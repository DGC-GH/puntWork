<?php
/**
 * Recovery Strategies for Import System
 * Automatic recovery mechanisms for circuit breaker states and system failures
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
 * Recovery strategy types
 */
class RecoveryStrategy {
    const CIRCUIT_BREAKER_RESET = 'circuit_breaker_reset';
    const GRADUAL_LOAD_INCREASE = 'gradual_load_increase';
    const SERVICE_RESTART = 'service_restart';
    const DATA_RECOVERY = 'data_recovery';
    const MANUAL_INTERVENTION = 'manual_intervention';
}

/**
 * Recovery status
 */
class RecoveryStatus {
    const PENDING = 'pending';
    const IN_PROGRESS = 'in_progress';
    const COMPLETED = 'completed';
    const FAILED = 'failed';
    const CANCELLED = 'cancelled';
}

/**
 * Automated recovery system for import operations
 */
class ImportRecoveryManager {

    private $recovery_history = [];
    private $active_recoveries = [];
    private $circuit_breaker_recoveries = [];

    /**
     * Initialize recovery manager
     */
    public function __construct() {
        $this->load_recovery_state();
    }

    /**
     * Initiate recovery process for a specific failure type
     */
    public function initiate_recovery($failure_type, $context = []) {
        $recovery_id = uniqid('recovery_', true);
        $strategy = $this->determine_recovery_strategy($failure_type, $context);

        $recovery = [
            'id' => $recovery_id,
            'failure_type' => $failure_type,
            'strategy' => $strategy,
            'status' => RecoveryStatus::PENDING,
            'context' => $context,
            'created_at' => time(),
            'started_at' => 0,
            'completed_at' => 0,
            'attempts' => 0,
            'max_attempts' => 3,
            'last_error' => '',
            'progress' => [],
            'circuit_breaker_state' => get_circuit_breaker_status()
        ];

        $this->active_recoveries[$recovery_id] = $recovery;
        $this->save_recovery_state();

        // Dispatch recovery started event
        dispatch_import_event(ImportEventType::RECOVERY_ATTEMPTED, [
            'recovery_id' => $recovery_id,
            'failure_type' => $failure_type,
            'strategy' => $strategy,
            'context' => $context
        ]);

        PuntWorkLogger::info('Recovery initiated', PuntWorkLogger::CONTEXT_IMPORT, [
            'recovery_id' => $recovery_id,
            'failure_type' => $failure_type,
            'strategy' => $strategy
        ]);

        // Immediately attempt recovery if strategy allows
        if ($this->can_start_recovery_immediately($strategy, $failure_type)) {
            $this->execute_recovery($recovery_id);
        } else {
            // Schedule recovery
            $this->schedule_recovery($recovery_id, $strategy);
        }

        return $recovery_id;
    }

    /**
     * Execute recovery process
     */
    private function execute_recovery($recovery_id) {
        if (!isset($this->active_recoveries[$recovery_id])) {
            return false;
        }

        $recovery = &$this->active_recoveries[$recovery_id];
        $recovery['status'] = RecoveryStatus::IN_PROGRESS;
        $recovery['started_at'] = time();
        $recovery['attempts']++;

        $this->save_recovery_state();

        PuntWorkLogger::info('Recovery execution started', PuntWorkLogger::CONTEXT_IMPORT, [
            'recovery_id' => $recovery_id,
            'strategy' => $recovery['strategy'],
            'attempt' => $recovery['attempts']
        ]);

        try {
            $result = $this->execute_recovery_strategy($recovery);

            // Update progress
            $recovery['progress'][] = [
                'timestamp' => time(),
                'action' => 'strategy_executed',
                'result' => $result,
                'success' => $result['success'] ?? false
            ];

            if ($result['success']) {
                $this->complete_recovery($recovery_id, $result);
            } else {
                $this->handle_recovery_failure($recovery_id, $result['error'] ?? 'Unknown error');
            }

        } catch (\Exception $e) {
            $this->handle_recovery_failure($recovery_id, $e->getMessage());
        }

        return true;
    }

    /**
     * Execute specific recovery strategy
     */
    private function execute_recovery_strategy(&$recovery) {
        $strategy = $recovery['strategy'];
        $context = $recovery['context'];

        switch ($strategy) {
            case RecoveryStrategy::CIRCUIT_BREAKER_RESET:
                return $this->execute_circuit_breaker_reset($recovery);

            case RecoveryStrategy::GRADUAL_LOAD_INCREASE:
                return $this->execute_gradual_load_increase($recovery);

            case RecoveryStrategy::SERVICE_RESTART:
                return $this->execute_service_restart($recovery);

            case RecoveryStrategy::DATA_RECOVERY:
                return $this->execute_data_recovery($recovery);

            case RecoveryStrategy::MANUAL_INTERVENTION:
                return $this->execute_manual_intervention($recovery);

            default:
                throw new \Exception("Unknown recovery strategy: {$strategy}");
        }
    }

    /**
     * Execute circuit breaker reset strategy
     */
    private function execute_circuit_breaker_reset(&$recovery) {
        $cb_status = get_circuit_breaker_status();

        // Check if enough time has passed since circuit opened
        $time_since_open = time() - $cb_status['last_failure_time'];
        $min_wait_time = $cb_status['recovery_timeout'] / 2; // Half the recovery timeout

        if ($time_since_open < $min_wait_time) {
            return [
                'success' => false,
                'error' => "Insufficient time elapsed since circuit opened (waited: {$time_since_open}s, required: {$min_wait_time}s)"
            ];
        }

        // Reset circuit breaker
        reset_circuit_breaker();

        // Verify reset was successful
        $new_status = get_circuit_breaker_status();

        if ($new_status['state'] === CircuitBreakerState::CLOSED) {
            // Dispatch circuit closed event
            dispatch_import_event(ImportEventType::CIRCUIT_CLOSED, [
                'recovery_id' => $recovery['id'],
                'previous_failures' => $cb_status['failure_count'],
                'time_since_open' => $time_since_open
            ]);

            return [
                'success' => true,
                'action' => 'circuit_reset',
                'previous_state' => $cb_status['state'],
                'new_state' => $new_status['state']
            ];
        } else {
            return [
                'success' => false,
                'error' => "Circuit breaker reset failed - still in {$new_status['state']} state"
            ];
        }
    }

    /**
     * Execute gradual load increase strategy
     */
    private function execute_gradual_load_increase(&$recovery) {
        // Implement gradual load increase after circuit breaker reset
        $current_batch_size = get_batch_size();
        $increased_batch_size = min($current_batch_size * 1.5, get_import_config_value('batch.max_batch_size', 100));

        if ($increased_batch_size > $current_batch_size) {
            // Gradually increase batch size
            $intermediate_size = intval(($current_batch_size + $increased_batch_size) / 2);
            update_option('puntwork_batch_size', $intermediate_size);

            PuntWorkLogger::info('Gradual load increase applied', PuntWorkLogger::CONTEXT_IMPORT, [
                'old_batch_size' => $current_batch_size,
                'new_batch_size' => $intermediate_size,
                'target_batch_size' => $increased_batch_size
            ]);

            return [
                'success' => true,
                'action' => 'load_increase',
                'old_batch_size' => $current_batch_size,
                'new_batch_size' => $intermediate_size
            ];
        } else {
            return [
                'success' => false,
                'error' => 'Batch size already at maximum'
            ];
        }
    }

    /**
     * Execute service restart strategy
     */
    private function execute_service_restart(&$recovery) {
        // Clear import caches and transients
        $cleared_items = [];

        // Clear import transients
        delete_transient('import_cancel');
        delete_transient('puntwork_import_cancel');
        $cleared_items[] = 'import transients';

        // Clear import status
        delete_import_status();
        $cleared_items[] = 'import status';

        // Reset batch processing
        delete_batch_size();
        delete_time_per_job();
        delete_avg_time_per_job();
        delete_last_peak_memory();
        delete_consecutive_small_batches();
        delete_consecutive_batches();
        $cleared_items[] = 'batch processing data';

        // Clear WordPress cache
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
            $cleared_items[] = 'WordPress cache';
        }

        // Force cleanup of any stuck async jobs
        $queue = get_async_job_queue();
        if (method_exists($queue, 'cleanup_stuck_jobs')) {
            $stalled_cleaned = $queue->cleanup_stuck_jobs();
            if ($stalled_cleaned > 0) {
                $cleared_items[] = "{$stalled_cleaned} stuck async jobs";
            }
        }

        PuntWorkLogger::info('Service restart recovery completed', PuntWorkLogger::CONTEXT_IMPORT, [
            'cleared_items' => $cleared_items
        ]);

        return [
            'success' => true,
            'action' => 'service_restart',
            'cleared_items' => $cleared_items
        ];
    }

    /**
     * Execute data recovery strategy
     */
    private function execute_data_recovery(&$recovery) {
        $context = $recovery['context'];
        $import_id = $context['import_id'] ?? null;

        if (!$import_id) {
            return [
                'success' => false,
                'error' => 'No import ID provided for data recovery'
            ];
        }

        // Get last successful import state
        $last_successful = get_option('puntwork_import_history', []);
        if (empty($last_successful)) {
            return [
                'success' => false,
                'error' => 'No successful import history available for recovery'
            ];
        }

        $last_successful_run = $last_successful[0]; // Most recent

        // Restore baseline metrics
        update_option('puntwork_performance_baseline', [
            'avg_time_per_item' => $last_successful_run['processing_time_per_item'] ?? 1.0,
            'avg_memory_usage' => ($last_successful_run['peak_memory_mb'] ?? 100) * 1024 * 1024,
            'success_rate' => 1.0,
            'sample_size' => 1,
            'restored_from_history' => true,
            'timestamp' => time()
        ]);

        // Schedule a health check
        $queue = get_async_job_queue();
        $queue->add_job(AsyncOperationType::HEALTH_CHECK, [], 'high', 30); // 30 seconds

        return [
            'success' => true,
            'action' => 'data_recovery',
            'restored_baseline' => true,
            'last_successful_import' => $last_successful_run['timestamp'] ?? null
        ];
    }

    /**
     * Execute manual intervention strategy
     */
    private function execute_manual_intervention(&$recovery) {
        // This strategy requires admin notification and manual action
        $admin_email = get_option('admin_email');
        $subject = '[PuntWork] Manual Recovery Required';

        $context = $recovery['context'];
        $failure_type = $recovery['failure_type'];

        $message = "PuntWork Import System requires manual recovery intervention.\n\n";
        $message .= "Recovery ID: {$recovery['id']}\n";
        $message .= "Failure Type: {$failure_type}\n";
        $message .= "Created: " . date('Y-m-d H:i:s', $recovery['created_at']) . "\n";
        $message .= "Attempts: {$recovery['attempts']}\n\n";

        if (!empty($context)) {
            $message .= "Context:\n" . json_encode($context, JSON_PRETTY_PRINT) . "\n\n";
        }

        $message .= "Circuit Breaker Status:\n";
        $cb_status = get_circuit_breaker_status();
        $message .= "- State: {$cb_status['state']}\n";
        $message .= "- Failures: {$cb_status['failure_count']}\n";
        $message .= "- Last Failure: " . ($cb_status['last_failure_time'] ? date('Y-m-d H:i:s', $cb_status['last_failure_time']) : 'Never') . "\n\n";

        $message .= "Recommended Actions:\n";
        $message .= "1. Check server logs for additional error details\n";
        $message .= "2. Verify database connectivity and permissions\n";
        $message .= "3. Check feed URLs and accessibility\n";
        $message .= "4. Review server resource usage (CPU, memory, disk)\n";
        $message .= "5. Reset circuit breaker manually if safe to do so\n\n";

        $message .= "After taking corrective action, use the admin panel to:\n";
        $message .= "- Reset circuit breaker status\n";
        $message .= "- Clear import locks and transients\n";
        $message .= "- Manually resume failed imports\n";

        wp_mail($admin_email, $subject, $message);

        // Store manual intervention alert
        $alerts = get_option('puntwork_manual_intervention_alerts', []);
        $alerts[] = [
            'recovery_id' => $recovery['id'],
            'failure_type' => $failure_type,
            'timestamp' => time(),
            'context' => $context,
            'email_sent' => true
        ];
        update_option('puntwork_manual_intervention_alerts', $alerts);

        // Schedule follow-up check in 1 hour
        if (!wp_next_scheduled('puntwork_manual_recovery_followup_' . $recovery['id'])) {
            wp_schedule_single_event(time() + 3600, 'puntwork_manual_recovery_followup', [
                'recovery_id' => $recovery['id']
            ]);
        }

        return [
            'success' => true,
            'action' => 'manual_intervention_notified',
            'email_sent_to' => $admin_email
        ];
    }

    /**
     * Complete recovery successfully
     */
    private function complete_recovery($recovery_id, $result) {
        if (!isset($this->active_recoveries[$recovery_id])) {
            return;
        }

        $recovery = &$this->active_recoveries[$recovery_id];
        $recovery['status'] = RecoveryStatus::COMPLETED;
        $recovery['completed_at'] = time();
        $recovery['result'] = $result;

        // Move to history
        $this->recovery_history[] = $recovery;

        // Remove from active
        unset($this->active_recoveries[$recovery_id]);

        // Keep history limited (last 50 recoveries)
        if (count($this->recovery_history) > 50) {
            array_shift($this->recovery_history);
        }

        $this->save_recovery_state();

        PuntWorkLogger::info('Recovery completed successfully', PuntWorkLogger::CONTEXT_IMPORT, [
            'recovery_id' => $recovery_id,
            'strategy' => $recovery['strategy'],
            'duration' => time() - $recovery['started_at'],
            'result' => $result
        ]);

        // Dispatch recovery completed event
        dispatch_import_event(ImportEventType::RECOVERY_ATTEMPTED, [
            'recovery_id' => $recovery_id,
            'status' => 'completed',
            'strategy' => $recovery['strategy'],
            'result' => $result
        ]);
    }

    /**
     * Handle recovery failure
     */
    private function handle_recovery_failure($recovery_id, $error_message) {
        if (!isset($this->active_recoveries[$recovery_id])) {
            return;
        }

        $recovery = &$this->active_recoveries[$recovery_id];
        $recovery['last_error'] = $error_message;

        if ($recovery['attempts'] >= $recovery['max_attempts']) {
            // Max attempts reached - escalate to manual intervention
            $recovery['status'] = RecoveryStatus::FAILED;
            $recovery['strategy'] = RecoveryStrategy::MANUAL_INTERVENTION;

            PuntWorkLogger::error('Recovery failed, escalating to manual intervention', PuntWorkLogger::CONTEXT_IMPORT, [
                'recovery_id' => $recovery_id,
                'attempts' => $recovery['attempts'],
                'last_error' => $error_message
            ]);

            // Trigger manual intervention
            $this->execute_manual_intervention($recovery);

        } else {
            // Schedule retry
            $recovery['status'] = RecoveryStatus::PENDING;
            $delay = min(300, pow(2, $recovery['attempts'] - 1) * 60); // Exponential backoff
            $this->schedule_recovery($recovery_id, $recovery['strategy'], $delay);
        }

        $this->save_recovery_state();
    }

    /**
     * Determine appropriate recovery strategy for failure type
     */
    private function determine_recovery_strategy($failure_type, $context) {
        $failure_category = $failure_type;

        // Map failure types to recovery strategies
        $strategy_map = [
            FailureType::TIMEOUT => RecoveryStrategy::CIRCUIT_BREAKER_RESET,
            FailureType::NETWORK => RecoveryStrategy::SERVICE_RESTART,
            FailureType::DATABASE => RecoveryStrategy::SERVICE_RESTART,
            FailureType::MEMORY => RecoveryStrategy::GRADUAL_LOAD_INCREASE,
            FailureType::VALIDATION => RecoveryStrategy::DATA_RECOVERY,
            FailureType::PROCESSING => RecoveryStrategy::CIRCUIT_BREAKER_RESET,
            FailureType::PERMISSION => RecoveryStrategy::MANUAL_INTERVENTION
        ];

        // Check if circuit breaker is involved
        $cb_status = get_circuit_breaker_status();
        if ($cb_status['state'] === CircuitBreakerState::OPEN) {
            return RecoveryStrategy::CIRCUIT_BREAKER_RESET;
        }

        // Check severity from context
        $severity = $context['severity'] ?? 'medium';
        if ($severity === 'critical') {
            return RecoveryStrategy::MANUAL_INTERVENTION;
        }

        return $strategy_map[$failure_category] ?? RecoveryStrategy::SERVICE_RESTART;
    }

    /**
     * Check if recovery can start immediately
     */
    private function can_start_recovery_immediately($strategy, $failure_type) {
        // Circuit breaker resets can start immediately if enough time has passed
        if ($strategy === RecoveryStrategy::CIRCUIT_BREAKER_RESET) {
            $cb_status = get_circuit_breaker_status();
            $time_since_open = time() - $cb_status['last_failure_time'];
            return $time_since_open >= ($cb_status['recovery_timeout'] / 2);
        }

        // Service restarts can start immediately
        if ($strategy === RecoveryStrategy::SERVICE_RESTART) {
            return true;
        }

        // Other strategies need scheduling
        return false;
    }

    /**
     * Schedule recovery execution
     */
    private function schedule_recovery($recovery_id, $strategy, $delay = null) {
        if ($delay === null) {
            $delay = $this->get_default_delay_for_strategy($strategy);
        }

        if (!wp_next_scheduled('puntwork_execute_recovery')) {
            wp_schedule_single_event(time() + $delay, 'puntwork_execute_recovery', [
                'recovery_id' => $recovery_id
            ]);
        }

        PuntWorkLogger::info('Recovery scheduled', PuntWorkLogger::CONTEXT_IMPORT, [
            'recovery_id' => $recovery_id,
            'strategy' => $strategy,
            'delay_seconds' => $delay
        ]);
    }

    /**
     * Get default delay for recovery strategy
     */
    private function get_default_delay_for_strategy($strategy) {
        $delays = [
            RecoveryStrategy::CIRCUIT_BREAKER_RESET => 300,  // 5 minutes
            RecoveryStrategy::GRADUAL_LOAD_INCREASE => 600,  // 10 minutes
            RecoveryStrategy::SERVICE_RESTART => 60,          // 1 minute
            RecoveryStrategy::DATA_RECOVERY => 180,           // 3 minutes
            RecoveryStrategy::MANUAL_INTERVENTION => 0        // Immediate
        ];

        return $delays[$strategy] ?? 300;
    }

    /**
     * Load recovery state from persistence
     */
    private function load_recovery_state() {
        $state = get_option('puntwork_recovery_state', []);

        $this->active_recoveries = $state['active_recoveries'] ?? [];
        $this->recovery_history = $state['recovery_history'] ?? [];
        $this->circuit_breaker_recoveries = $state['circuit_breaker_recoveries'] ?? [];
    }

    /**
     * Save recovery state to persistence
     */
    private function save_recovery_state() {
        $state = [
            'active_recoveries' => $this->active_recoveries,
            'recovery_history' => array_slice($this->recovery_history, -50), // Keep last 50
            'circuit_breaker_recoveries' => $this->circuit_breaker_recoveries,
            'last_updated' => time()
        ];

        update_option('puntwork_recovery_state', $state);
    }

    /**
     * Get recovery status
     */
    public function get_status() {
        return [
            'active_recoveries' => count($this->active_recoveries),
            'total_history' => count($this->recovery_history),
            'last_recovery_attempt' => empty($this->recovery_history) ? null :
                $this->recovery_history[count($this->recovery_history) - 1]['created_at']
        ];
    }

    /**
     * Force cancel recovery
     */
    public function cancel_recovery($recovery_id) {
        if (isset($this->active_recoveries[$recovery_id])) {
            $recovery = &$this->active_recoveries[$recovery_id];
            $recovery['status'] = RecoveryStatus::CANCELLED;

            PuntWorkLogger::info('Recovery cancelled', PuntWorkLogger::CONTEXT_IMPORT, [
                'recovery_id' => $recovery_id,
                'strategy' => $recovery['strategy']
            ]);

            $this->save_recovery_state();
            return true;
        }

        return false;
    }
}

/**
 * Global recovery manager instance
 */
function get_recovery_manager() {
    static $manager = null;

    if ($manager === null) {
        $manager = new ImportRecoveryManager();
    }

    return $manager;
}

/**
 * Initiate recovery for system failure
 */
function initiate_system_recovery($failure_type, $context = []) {
    $manager = get_recovery_manager();
    return $manager->initiate_recovery($failure_type, $context);
}

/**
 * Execute scheduled recovery
 */
function execute_scheduled_recovery($recovery_id) {
    $manager = get_recovery_manager();
    return $manager->execute_recovery($recovery_id);
}

/**
 * Get recovery system status
 */
function get_recovery_status() {
    $manager = get_recovery_manager();
    return $manager->get_status();
}

/**
 * Cancel recovery process
 */
function cancel_recovery($recovery_id) {
    $manager = get_recovery_manager();
    return $manager->cancel_recovery($recovery_id);
}

/**
 * Enhanced circuit breaker reset with recovery tracking
 */
function reset_circuit_breaker_with_recovery_tracking() {
    $previous_status = get_circuit_breaker_status();
    reset_circuit_breaker();

    $new_status = get_circuit_breaker_status();

    // Track recovery success/failure
    $recovery_manager = get_recovery_manager();
    $context = [
        'circuit_breaker_reset' => true,
        'previous_state' => $previous_status['state'],
        'new_state' => $new_status['state'],
        'previous_failures' => $previous_status['failure_count']
    ];

    if ($new_status['state'] === CircuitBreakerState::CLOSED) {
        // Log successful recovery
        PuntWorkLogger::info('Circuit breaker recovery successful', PuntWorkLogger::CONTEXT_IMPORT, $context);
    } else {
        // Recovery failed, initiate full recovery process
        $recovery_id = $recovery_manager->initiate_recovery(FailureType::PROCESSING, $context);
        PuntWorkLogger::warn('Circuit breaker recovery failed, initiating full recovery', PuntWorkLogger::CONTEXT_IMPORT, [
            'recovery_id' => $recovery_id,
            'context' => $context
        ]);
    }
}

/**
 * WordPress action hooks
 */
add_action('puntwork_execute_recovery', function($recovery_id) {
    execute_scheduled_recovery($recovery_id['recovery_id']);
});

add_action('puntwork_manual_recovery_followup', function($recovery_id) {
    // Implement follow-up logic for manual recovery interventions
    $alerts = get_option('puntwork_manual_intervention_alerts', []);
    $alert = null;

    // Find the alert for this recovery
    foreach ($alerts as $a) {
        if ($a['recovery_id'] === $recovery_id['recovery_id']) {
            $alert = $a;
            break;
        }
    }

    if ($alert) {
        $admin_email = get_option('admin_email');
        $subject = '[PuntWork] Follow-up: Manual Recovery Required';

        $message = "This is a follow-up reminder for the manual recovery intervention required for PurtWork Import System.\n\n";
        $message .= "Recovery ID: {$alert['recovery_id']}\n";
        $message .= "Original Alert: " . date('Y-m-d H:i:s', $alert['timestamp']) . "\n\n";

        wp_mail($admin_email, $subject, $message);
    }
});

/**
 * Add recovery awareness to existing circuit breaker functions
 */
add_action('init', function() {
    // Hook into circuit breaker state changes via action instead of filter to avoid recursion
    add_action('circuit_breaker_state_changed', function($old_state, $new_state) {
        try {
            // If circuit was reset from open to closed, log recovery
            if (isset($old_state['state'], $new_state['state']) &&
                $old_state['state'] === CircuitBreakerState::OPEN &&
                $new_state['state'] === CircuitBreakerState::CLOSED) {

                dispatch_import_event(ImportEventType::CIRCUIT_CLOSED, [
                    'auto_recovery' => true,
                    'previous_failures' => $old_state['failure_count'] ?? 0,
                    'recovery_time' => time() - ($old_state['last_failure_time'] ?? time())
                ]);

                PuntWorkLogger::info('Circuit breaker automatically recovered to closed state', PuntWorkLogger::CONTEXT_IMPORT, [
                    'previous_failures' => $old_state['failure_count'] ?? 0,
                    'recovery_duration' => time() - ($old_state['last_failure_time'] ?? time())
                ]);
            }
        } catch (\Exception $e) {
            // Don't let recovery logging break the circuit breaker
            PuntWorkLogger::error('Error in circuit breaker recovery logging', PuntWorkLogger::CONTEXT_IMPORT, [
                'error' => $e->getMessage()
            ]);
        }
    });
});

/**
 * Helper function to trigger circuit breaker state change action
 * This should be called from the circuit breaker when state changes
 */
function notify_circuit_breaker_state_change($old_state, $new_state) {
    do_action('circuit_breaker_state_changed', $old_state, $new_state);
}
