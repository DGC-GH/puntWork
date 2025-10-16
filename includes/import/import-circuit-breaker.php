<?php
/**
 * Circuit Breaker Pattern for Import System
 * Automatic failure detection and recovery with different failure modes
 *
 * @package    Puntwork
 * @subpackage Import
 * @since      1.2.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Circuit breaker states
 */
class CircuitBreakerState {
    const CLOSED = 'closed';      // Normal operation
    const OPEN = 'open';          // Circuit is open due to failures
    const HALF_OPEN = 'half_open'; // Testing if service recovered
}

/**
 * Different types of failures that can trigger circuit breaker
 */
class FailureType {
    const TIMEOUT = 'timeout';
    const NETWORK = 'network';
    const DATABASE = 'database';
    const MEMORY = 'memory';
    const VALIDATION = 'validation';
    const PROCESSING = 'processing';
    const PERMISSION = 'permission';
}

/**
 * Circuit breaker for import operations
 * Implements automatic failure detection and recovery with different failure modes
 */
class ImportCircuitBreaker {

    private $state = CircuitBreakerState::CLOSED;
    private $failure_count = 0;
    private $last_failure_time = 0;
    private $next_attempt_time = 0;
    private $recovery_timeout = 300; // 5 minutes default
    private $failure_threshold = 5;  // 5 failures trigger open circuit
    private $config;

    /**
     * Initialize circuit breaker
     */
    public function __construct($config = []) {
        $this->config = wp_parse_args($config, [
            'recovery_timeout' => 300,    // 5 minutes
            'failure_threshold' => 5,     // 5 failures to open
            'success_threshold' => 2,     // 2 successes to close
            'half_open_timeout' => 60,    // 1 minute test period
        ]);

        $this->recovery_timeout = $this->config['recovery_timeout'];
        $this->failure_threshold = $this->config['failure_threshold'];

        // Load persisted state
        $this->load_state();
    }

    /**
     * Execute an operation with circuit breaker protection
     */
    public function execute($operation_name, $callable, $context = []) {
        // Check if circuit should transition to half-open
        if ($this->should_attempt_recovery()) {
            $this->state = CircuitBreakerState::HALF_OPEN;
            PuntWorkLogger::info("Circuit breaker entering HALF_OPEN state for test operation", PuntWorkLogger::CONTEXT_IMPORT, [
                'operation' => $operation_name,
                'context' => $context
            ]);
        }

        // If circuit is open, fail fast
        if ($this->state === CircuitBreakerState::OPEN) {
            PuntWorkLogger::warn("Circuit breaker OPEN - failing fast", PuntWorkLogger::CONTEXT_IMPORT, [
                'operation' => $operation_name,
                'failures' => $this->failure_count,
                'next_attempt' => date('Y-m-d H:i:s', $this->next_attempt_time)
            ]);

            throw new CircuitBreakerException("Circuit is open - operation blocked", [
                'operation' => $operation_name,
                'state' => $this->state,
                'next_attempt' => $this->next_attempt_time
            ]);
        }

        try {
            $start_time = microtime(true);
            $result = call_user_func($callable);
            $execution_time = microtime(true) - $start_time;

            // Record success
            $this->on_success($operation_name, $execution_time, $context);

            return $result;

        } catch (\Exception $e) {
            // Record failure
            $this->on_failure($operation_name, $e, $context);

            // Re-throw with circuit breaker context
            throw new CircuitBreakerException("Operation failed behind circuit breaker", [
                'original_exception' => $e->getMessage(),
                'operation' => $operation_name,
                'state' => $this->state,
                'failure_count' => $this->failure_count
            ], 0, $e);
        }
    }

    /**
     * Record a successful operation
     */
    private function on_success($operation_name, $execution_time, $context) {
        $old_state = $this->state;

        if ($this->state === CircuitBreakerState::HALF_OPEN) {
            // In half-open state, success transitions to closed
            $this->state = CircuitBreakerState::CLOSED;
            $this->failure_count = 0;

            PuntWorkLogger::info("Circuit breaker recovered - transitioned to CLOSED", PuntWorkLogger::CONTEXT_IMPORT, [
                'operation' => $operation_name,
                'execution_time' => $execution_time,
                'old_state' => $old_state,
                'context' => $context
            ]);

        } else {
            // In closed state, just log healthy operation
            if ($execution_time > 10) { // Log slow operations
                PuntWorkLogger::warn("Slow operation detected", PuntWorkLogger::CONTEXT_IMPORT, [
                    'operation' => $operation_name,
                    'execution_time' => $execution_time,
                    'context' => $context
                ]);
            }
        }

        // Persist state
        $this->save_state();

        // Update monitoring
        update_import_metrics(get_import_monitoring_id(), [
            'circuit_breaker_events' => [[
                'type' => 'success',
                'operation' => $operation_name,
                'execution_time' => $execution_time,
                'timestamp' => microtime(true)
            ]]
        ]);
    }

    /**
     * Record a failed operation
     */
    private function on_failure($operation_name, \Exception $e, $context) {
        $this->failure_count++;
        $this->last_failure_time = time();

        $error_details = $this->classify_error($e);

        // Update monitoring with failure
        update_import_metrics(get_import_monitoring_id(), [
            'circuit_breaker_events' => [[
                'type' => 'failure',
                'operation' => $operation_name,
                'failure_type' => $error_details['type'],
                'severity' => $error_details['severity'],
                'error_message' => $e->getMessage(),
                'timestamp' => microtime(true)
            ]]
        ]);

        $old_state = $this->state;

        // Check if we should open the circuit
        if ($this->should_open_circuit($error_details)) {
            $this->open_circuit();

            PuntWorkLogger::error("Circuit breaker opened due to repeated failures", PuntWorkLogger::CONTEXT_IMPORT, [
                'operation' => $operation_name,
                'failure_count' => $this->failure_count,
                'threshold' => $this->failure_threshold,
                'failure_type' => $error_details['type'],
                'severity' => $error_details['severity'],
                'old_state' => $old_state,
                'context' => $context
            ]);

            // Send alert for critical failures
            if ($error_details['severity'] === 'critical') {
                send_health_alert('Circuit breaker opened due to critical failure', [
                    'operation' => $operation_name,
                    'error_type' => $error_details['type'],
                    'error_message' => $e->getMessage(),
                    'context' => $context
                ]);
            }
        }
    }

    /**
     * Classify error type and severity
     */
    private function classify_error(\Exception $e) {
        $message = strtolower($e->getMessage());
        $code = $e->getCode();

        // Database errors
        if (strpos($message, 'wpdb') !== false ||
            strpos($message, 'database') !== false ||
            strpos($message, 'mysql') !== false ||
            strpos($message, 'sql') !== false) {
            return [
                'type' => FailureType::DATABASE,
                'severity' => 'high'
            ];
        }

        // Memory errors
        if (strpos($message, 'memory') !== false ||
            strpos($message, 'out of memory') !== false ||
            strpos($message, 'allowed memory') !== false) {
            return [
                'type' => FailureType::MEMORY,
                'severity' => 'critical'
            ];
        }

        // Timeout errors
        if (strpos($message, 'timeout') !== false ||
            strpos($message, 'max execution time') !== false ||
            $code === 504 || $code === 408) {
            return [
                'type' => FailureType::TIMEOUT,
                'severity' => 'high'
            ];
        }

        // Network/API errors
        if (strpos($message, 'connection') !== false ||
            strpos($message, 'network') !== false ||
            strpos($message, 'http') !== false ||
            strpos($message, 'curl') !== false ||
            ($code >= 400 && $code < 600)) {
            return [
                'type' => FailureType::NETWORK,
                'severity' => strpos($message, '403') !== false ? 'critical' : 'medium'
            ];
        }

        // Permission errors
        if (strpos($message, 'permission') !== false ||
            strpos($message, 'access denied') !== false ||
            strpos($message, 'unauthorized') !== false ||
            $code === 403 || $code === 401) {
            return [
                'type' => FailureType::PERMISSION,
                'severity' => 'critical'
            ];
        }

        // Validation errors
        if (strpos($message, 'validation') !== false ||
            strpos($message, 'invalid') !== false ||
            strpos($message, 'missing') !== false) {
            return [
                'type' => FailureType::VALIDATION,
                'severity' => 'low'
            ];
        }

        // Processing errors (default)
        return [
            'type' => FailureType::PROCESSING,
            'severity' => 'medium'
        ];
    }

    /**
     * Determine if circuit should be opened
     */
    private function should_open_circuit($error_details) {
        // Critical errors open circuit immediately
        if ($error_details['severity'] === 'critical') {
            return true;
        }

        // High severity errors need fewer failures
        if ($error_details['severity'] === 'high') {
            return $this->failure_count >= max(1, $this->failure_threshold / 2);
        }

        // Normal threshold for medium/low severity
        return $this->failure_count >= $this->failure_threshold;
    }

    /**
     * Open the circuit breaker
     */
    private function open_circuit() {
        $this->state = CircuitBreakerState::OPEN;
        $this->next_attempt_time = time() + $this->recovery_timeout;
        $this->save_state();
    }

    /**
     * Check if we should attempt recovery (transition to half-open)
     */
    private function should_attempt_recovery() {
        return $this->state === CircuitBreakerState::OPEN &&
               time() >= $this->next_attempt_time;
    }

    /**
     * Get current circuit breaker status
     */
    public function get_status() {
        return [
            'state' => $this->state,
            'failure_count' => $this->failure_count,
            'last_failure_time' => $this->last_failure_time,
            'next_attempt_time' => $this->next_attempt_time,
            'recovery_timeout' => $this->recovery_timeout,
            'failure_threshold' => $this->failure_threshold
        ];
    }

    /**
     * Manually reset circuit breaker
     */
    public function reset() {
        $this->state = CircuitBreakerState::CLOSED;
        $this->failure_count = 0;
        $this->last_failure_time = 0;
        $this->next_attempt_time = 0;

        $this->save_state();

        PuntWorkLogger::info("Circuit breaker manually reset", PuntWorkLogger::CONTEXT_IMPORT);
    }

    /**
     * Load persisted state from options
     */
    private function load_state() {
        $state_data = get_option('puntwork_circuit_breaker_state', []);

        if (!empty($state_data)) {
            $this->state = $state_data['state'] ?? CircuitBreakerState::CLOSED;
            $this->failure_count = $state_data['failure_count'] ?? 0;
            $this->last_failure_time = $state_data['last_failure_time'] ?? 0;
            $this->next_attempt_time = $state_data['next_attempt_time'] ?? 0;
            $this->recovery_timeout = $state_data['recovery_timeout'] ?? $this->recovery_timeout;
        }
    }

    /**
     * Save state to options
     */
    private function save_state() {
        $state_data = [
            'state' => $this->state,
            'failure_count' => $this->failure_count,
            'last_failure_time' => $this->last_failure_time,
            'next_attempt_time' => $this->next_attempt_time,
            'recovery_timeout' => $this->recovery_timeout,
            'last_updated' => time()
        ];

        update_option('puntwork_circuit_breaker_state', $state_data);
    }

    /**
     * Force circuit open (for testing/admin purposes)
     */
    public function force_open() {
        $this->open_circuit();
        PuntWorkLogger::warn("Circuit breaker force opened", PuntWorkLogger::CONTEXT_IMPORT);
    }
}

/**
 * Circuit breaker exception
 */
class CircuitBreakerException extends \Exception {
    private $context;

    public function __construct($message, $context = [], $code = 0, \Exception $previous = null) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    public function getContext() {
        return $this->context;
    }
}

/**
 * Get or create circuit breaker instance
 */
function get_import_circuit_breaker() {
    static $circuit_breaker = null;

    if ($circuit_breaker === null) {
        $config = get_import_config_value('circuit_breaker', []);
        $circuit_breaker = new ImportCircuitBreaker($config);
    }

    return $circuit_breaker;
}

/**
 * Execute operation with circuit breaker protection
 */
function execute_with_circuit_breaker($operation_name, $callable, $context = []) {
    $circuit_breaker = get_import_circuit_breaker();

    try {
        return $circuit_breaker->execute($operation_name, $callable, $context);
    } catch (CircuitBreakerException $e) {
        // Log circuit breaker specific details
        PuntWorkLogger::error("Circuit breaker blocked operation", PuntWorkLogger::CONTEXT_IMPORT, [
            'operation' => $operation_name,
            'circuit_state' => $e->getContext()['state'] ?? 'unknown',
            'original_error' => $e->getContext()['original_exception'] ?? 'none'
        ]);

        throw $e;
    }
}

/**
 * Get circuit breaker status
 */
function get_circuit_breaker_status() {
    $circuit_breaker = get_import_circuit_breaker();
    return $circuit_breaker->get_status();
}

/**
 * Reset circuit breaker
 */
function reset_circuit_breaker() {
    $circuit_breaker = get_import_circuit_breaker();
    $circuit_breaker->reset();
}

/**
 * Helper function to get current import monitoring ID
 */
function get_import_monitoring_id() {
    // Try to get from current monitoring data
    $monitoring_data = get_current_import_monitoring();
    if ($monitoring_data && isset($monitoring_data['import_id'])) {
        return $monitoring_data['import_id'];
    }

    // Fallback to new monitoring session
    return initialize_import_monitoring();
}

/**
 * Send health alert for circuit breaker issues
 */
function send_health_alert($message, $context = []) {
    $alert_data = [
        'type' => 'circuit_breaker',
        'message' => $message,
        'context' => $context,
        'timestamp' => microtime(true),
        'circuit_status' => get_circuit_breaker_status()
    ];

    PuntWorkLogger::error('Circuit breaker health alert', PuntWorkLogger::CONTEXT_IMPORT, $alert_data);

    // Store for admin notification
    $alerts = get_option('puntwork_health_alerts', []);
    array_unshift($alerts, $alert_data);
    $alerts = array_slice($alerts, 0, 50); // Keep last 50
    update_option('puntwork_health_alerts', $alerts);
}
