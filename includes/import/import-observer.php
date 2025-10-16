<?php
/**
 * Observer Pattern for Import System Events
 * Enables reactive programming and decoupled event handling
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
 * Import event types
 */
class ImportEventType {
    // Import lifecycle events
    const IMPORT_STARTED = 'import_started';
    const IMPORT_COMPLETED = 'import_completed';
    const IMPORT_FAILED = 'import_failed';
    const IMPORT_CANCELLED = 'import_cancelled';

    // Batch processing events
    const BATCH_STARTED = 'batch_started';
    const BATCH_COMPLETED = 'batch_completed';
    const BATCH_FAILED = 'batch_failed';

    // Item processing events
    const ITEM_PROCESSED = 'item_processed';
    const ITEM_SKIPPED = 'item_skipped';
    const ITEM_FAILED = 'item_failed';

    // Circuit breaker events
    const CIRCUIT_OPENED = 'circuit_opened';
    const CIRCUIT_CLOSED = 'circuit_closed';
    const CIRCUIT_HALF_OPEN = 'circuit_half_open';

    // Health and monitoring events
    const HEALTH_ALERT = 'health_alert';
    const PERFORMANCE_DEGRADED = 'performance_degraded';
    const RECOVERY_ATTEMPTED = 'recovery_attempted';

    // System maintenance events
    const CLEANUP_STARTED = 'cleanup_started';
    const CLEANUP_COMPLETED = 'cleanup_completed';
    const MAINTENANCE_COMPLETED = 'maintenance_completed';
}

/**
 * Event priority levels
 */
class EventPriority {
    const LOW = 10;
    const NORMAL = 50;
    const HIGH = 100;
    const CRITICAL = 200;
}

/**
 * Base observer interface
 */
interface ImportObserverInterface {
    /**
     * Handle an import event
     *
     * @param ImportEvent $event The event object
     */
    public function handle_event(ImportEvent $event);
}

/**
 * Import event class
 */
class ImportEvent {

    private $type;
    private $data;
    private $timestamp;
    private $source;
    private $context;

    public function __construct($type, $data = [], $context = []) {
        $this->type = $type;
        $this->data = $data;
        $this->timestamp = microtime(true);
        $this->source = $this->detect_source();
        $this->context = $context;
    }

    /**
     * Get event type
     */
    public function get_type() {
        return $this->type;
    }

    /**
     * Get event data
     */
    public function get_data() {
        return $this->data;
    }

    /**
     * Get event timestamp
     */
    public function get_timestamp() {
        return $this->timestamp;
    }

    /**
     * Get event source
     */
    public function get_source() {
        return $this->source;
    }

    /**
     * Get event context
     */
    public function get_context() {
        return $this->context;
    }

    /**
     * Detect the source of the event
     */
    private function detect_source() {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);

        foreach ($backtrace as $frame) {
            if (isset($frame['file']) && strpos($frame['file'], 'includes/import/') !== false) {
                $filename = basename($frame['file'], '.php');
                if ($filename !== 'import-observer') {
                    return $filename;
                }
            }
        }

        return 'unknown';
    }

    /**
     * Check if event is of specific type
     */
    public function is_type($type) {
        return $this->type === $type;
    }

    /**
     * Check if event is in a list of types
     */
    public function is_type_in(array $types) {
        return in_array($this->type, $types);
    }
}

/**
 * Import event dispatcher
 */
class ImportEventDispatcher {

    private static $instance = null;
    private $observers = [];
    private $async_events = [];
    private $event_history = [];

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Add an observer
     *
     * @param ImportObserverInterface $observer The observer to add
     * @param int $priority Priority for event handling order
     */
    public function add_observer(ImportObserverInterface $observer, $priority = EventPriority::NORMAL) {
        if (!isset($this->observers[$priority])) {
            $this->observers[$priority] = [];
        }

        $this->observers[$priority][] = $observer;

        // Sort observers by priority (higher numbers first)
        krsort($this->observers);
    }

    /**
     * Remove an observer
     *
     * @param ImportObserverInterface $observer The observer to remove
     */
    public function remove_observer(ImportObserverInterface $observer) {
        foreach ($this->observers as $priority => $priority_observers) {
            foreach ($priority_observers as $key => $priority_observer) {
                if ($priority_observer === $observer) {
                    unset($this->observers[$priority][$key]);
                    if (empty($this->observers[$priority])) {
                        unset($this->observers[$priority]);
                    }
                    return;
                }
            }
        }
    }

    /**
     * Dispatch an event to all observers
     *
     * @param ImportEvent $event The event to dispatch
     * @param bool $async Whether to handle asynchronously
     */
    public function dispatch(ImportEvent $event, $async = false) {
        // Add to event history
        $this->add_to_history($event);

        if ($async) {
            $this->queue_async_event($event);
            return;
        }

        $this->notify_observers($event);
    }

    /**
     * Dispatch an event immediately (synchronous)
     *
     * @param string $type Event type
     * @param array $data Event data
     * @param array $context Event context
     */
    public function dispatch_immediate($type, $data = [], $context = []) {
        $event = new ImportEvent($type, $data, $context);
        $this->dispatch($event, false);
    }

    /**
     * Queue an event for async processing
     *
     * @param ImportEvent $event The event to queue
     */
    public function dispatch_async($type, $data = [], $context = []) {
        $event = new ImportEvent($type, $data, $context);
        $this->dispatch($event, true);
    }

    /**
     * Notify all observers of an event
     */
    private function notify_observers(ImportEvent $event) {
        foreach ($this->observers as $priority => $priority_observers) {
            foreach ($priority_observers as $observer) {
                try {
                    $observer->handle_event($event);
                } catch (\Exception $e) {
                    PuntWorkLogger::error('Observer failed to handle event', PuntWorkLogger::CONTEXT_IMPORT, [
                        'event_type' => $event->get_type(),
                        'observer_class' => get_class($observer),
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    /**
     * Queue event for async processing
     */
    private function queue_async_event(ImportEvent $event) {
        $this->async_events[] = [
            'type' => $event->get_type(),
            'data' => $event->get_data(),
            'context' => $event->get_context(),
            'timestamp' => $event->get_timestamp(),
            'source' => $event->get_source()
        ];

        // Trigger async processing
        if (!wp_next_scheduled('puntwork_process_async_events')) {
            wp_schedule_single_event(time() + 1, 'puntwork_process_async_events');
        }
    }

    /**
     * Process queued async events
     */
    public function process_async_events() {
        if (empty($this->async_events)) {
            return;
        }

        $events = $this->async_events;
        $this->async_events = [];

        foreach ($events as $event_data) {
            $event = new ImportEvent(
                $event_data['type'],
                $event_data['data'],
                $event_data['context'] + [
                    'async_processed_at' => microtime(true),
                    'original_timestamp' => $event_data['timestamp']
                ]
            );

            // Override the source since it was stored
            $event_source = $event_data['source'];

            $this->notify_observers($event);
        }

        PuntWorkLogger::debug('Processed async events', PuntWorkLogger::CONTEXT_IMPORT, [
            'event_count' => count($events)
        ]);
    }

    /**
     * Add event to history
     */
    private function add_to_history(ImportEvent $event) {
        $this->event_history[] = [
            'type' => $event->get_type(),
            'timestamp' => $event->get_timestamp(),
            'source' => $event->get_source(),
            'data_size' => strlen(json_encode($event->get_data()))
        ];

        // Keep last 1000 events
        if (count($this->event_history) > 1000) {
            array_shift($this->event_history);
        }
    }

    /**
     * Get event history
     */
    public function get_event_history($limit = 100) {
        $history = array_slice(array_reverse($this->event_history), 0, $limit);
        return $history;
    }

    /**
     * Clear event history
     */
    public function clear_event_history() {
        $this->event_history = [];
    }

    /**
     * Get queued async events count
     */
    public function get_async_queue_count() {
        return count($this->async_events);
    }
}

/**
 * Built-in observers for common import functionality
 */

/**
 * Health monitoring observer
 */
class HealthMonitoringObserver implements ImportObserverInterface {

    public function handle_event(ImportEvent $event) {
        $dispatcher = ImportEventDispatcher::get_instance();

        switch ($event->get_type()) {
            case ImportEventType::CIRCUIT_OPENED:
                PuntWorkLogger::error('Circuit breaker opened - dispatching health alert', PuntWorkLogger::CONTEXT_IMPORT, [
                    'reason' => $event->get_data()['reason'] ?? 'unknown'
                ]);

                $dispatcher->dispatch_async(ImportEventType::HEALTH_ALERT, [
                    'alert_type' => 'circuit_breaker',
                    'severity' => 'critical',
                    'message' => 'Import circuit breaker has opened',
                    'data' => $event->get_data()
                ]);
                break;

            case ImportEventType::PERFORMANCE_DEGRADED:
                $dispatcher->dispatch_async(ImportEventType::HEALTH_ALERT, [
                    'alert_type' => 'performance',
                    'severity' => 'warning',
                    'message' => 'Import performance degradation detected',
                    'data' => $event->get_data()
                ]);
                break;

            case ImportEventType::IMPORT_FAILED:
                $dispatcher->dispatch_async(ImportEventType::HEALTH_ALERT, [
                    'alert_type' => 'import_failure',
                    'severity' => 'high',
                    'message' => 'Import operation failed',
                    'data' => $event->get_data()
                ]);
                break;
        }
    }
}

/**
 * Performance tracking observer
 */
class PerformanceTrackingObserver implements ImportObserverInterface {

    private $performance_data = [];

    public function handle_event(ImportEvent $event) {
        switch ($event->get_type()) {
            case ImportEventType::IMPORT_STARTED:
                $this->performance_data[$event->get_data()['import_id']] = [
                    'start_time' => $event->get_timestamp(),
                    'items_processed' => 0,
                    'batches_completed' => 0
                ];
                break;

            case ImportEventType::BATCH_COMPLETED:
                $import_id = $event->get_context()['import_id'] ?? null;
                if ($import_id && isset($this->performance_data[$import_id])) {
                    $this->performance_data[$import_id]['batches_completed']++;
                    $this->performance_data[$import_id]['items_processed'] += $event->get_data()['items_count'] ?? 0;
                }
                break;

            case ImportEventType::IMPORT_COMPLETED:
                $import_id = $event->get_data()['import_id'];
                if (isset($this->performance_data[$import_id])) {
                    $data = $this->performance_data[$import_id];
                    $duration = $event->get_timestamp() - $data['start_time'];

                    update_import_metrics($import_id, [
                        'performance_tracking' => [[
                            'duration' => $duration,
                            'items_processed' => $data['items_processed'],
                            'batches_completed' => $data['batches_completed'],
                            'items_per_second' => $duration > 0 ? $data['items_processed'] / $duration : 0
                        ]]
                    ]);

                    unset($this->performance_data[$import_id]);
                }
                break;

            case ImportEventType::IMPORT_FAILED:
                $import_id = $event->get_data()['import_id'];
                if (isset($this->performance_data[$import_id])) {
                    unset($this->performance_data[$import_id]);
                }
                break;
        }
    }
}

/**
 * Logging observer - converts events to structured logs
 */
class LoggingObserver implements ImportObserverInterface {

    public function handle_event(ImportEvent $event) {
        $log_level = $this->get_log_level_for_event($event->get_type());
        $message = $this->format_event_message($event);
        $context = [
            'event_source' => $event->get_source(),
            'event_context' => $event->get_context()
        ] + $event->get_data();

        switch ($log_level) {
            case 'error':
                PuntWorkLogger::error($message, PuntWorkLogger::CONTEXT_IMPORT, $context);
                break;
            case 'warn':
                PuntWorkLogger::warn($message, PuntWorkLogger::CONTEXT_IMPORT, $context);
                break;
            case 'info':
            default:
                PuntWorkLogger::info($message, PuntWorkLogger::CONTEXT_IMPORT, $context);
                break;
        }
    }

    private function get_log_level_for_event($event_type) {
        $error_events = [
            ImportEventType::IMPORT_FAILED,
            ImportEventType::BATCH_FAILED,
            ImportEventType::ITEM_FAILED,
            ImportEventType::CIRCUIT_OPENED,
            ImportEventType::HEALTH_ALERT
        ];

        $warn_events = [
            ImportEventType::PERFORMANCE_DEGRADED,
            ImportEventType::RECOVERY_ATTEMPTED
        ];

        if (in_array($event_type, $error_events)) {
            return 'error';
        }

        if (in_array($event_type, $warn_events)) {
            return 'warn';
        }

        return 'info';
    }

    private function format_event_message(ImportEvent $event) {
        $type = $event->get_type();
        $data = $event->get_data();

        switch ($type) {
            case ImportEventType::IMPORT_STARTED:
                return 'Import operation started';
            case ImportEventType::IMPORT_COMPLETED:
                return 'Import operation completed successfully';
            case ImportEventType::IMPORT_FAILED:
                return 'Import operation failed: ' . ($data['error'] ?? 'unknown error');
            case ImportEventType::BATCH_STARTED:
                return 'Batch processing started';
            case ImportEventType::BATCH_COMPLETED:
                return 'Batch processing completed';
            case ImportEventType::CIRCUIT_OPENED:
                return 'Circuit breaker opened';
            case ImportEventType::CIRCUIT_CLOSED:
                return 'Circuit breaker closed';
            case ImportEventType::HEALTH_ALERT:
                return 'Health alert triggered: ' . ($data['message'] ?? 'unknown issue');
            default:
                return ucfirst(str_replace('_', ' ', $type));
        }
    }
}

/**
 * Recovery strategy observer
 */
class RecoveryObserver implements ImportObserverInterface {

    public function handle_event(ImportEvent $event) {
        $dispatcher = ImportEventDispatcher::get_instance();

        switch ($event->get_type()) {
            case ImportEventType::IMPORT_FAILED:
                // Trigger recovery attempt event
                $dispatcher->dispatch_async(ImportEventType::RECOVERY_ATTEMPTED, [
                    'failure_type' => 'import',
                    'original_event' => $event->get_data(),
                    'recovery_strategy' => 'circuit_breaker_reset'
                ]);

                // Schedule async recovery
                $this->schedule_recovery_check(300); // 5 minutes
                break;

            case ImportEventType::CIRCUIT_OPENED:
                // Schedule circuit breaker reset check
                $this->schedule_recovery_check(600); // 10 minutes
                break;

            case ImportEventType::HEALTH_ALERT:
                if ($event->get_data()['severity'] === 'critical') {
                    // For critical alerts, check if we need to escalate
                    $this->handle_critical_alert($event);
                }
                break;
        }
    }

    private function schedule_recovery_check($delay_seconds) {
        if (!wp_next_scheduled('puntwork_recovery_check')) {
            wp_schedule_single_event(time() + $delay_seconds, 'puntwork_recovery_check');
        }
    }

    private function handle_critical_alert(ImportEvent $event) {
        $alert_type = $event->get_data()['alert_type'] ?? 'unknown';

        // Different escalation strategies based on alert type
        switch ($alert_type) {
            case 'circuit_breaker':
                // Immediate escalation for circuit breaker issues
                PuntWorkLogger::error('Critical alert escalation: Circuit breaker issue detected', PuntWorkLogger::CONTEXT_IMPORT, [
                    'alert_data' => $event->get_data()
                ]);
                break;

            case 'import_failure':
                // Queue maintenance tasks
                force_async_cleanup('transient_cleanup');
                break;

            default:
                PuntWorkLogger::warn('Critical alert received but no specific escalation defined', PuntWorkLogger::CONTEXT_IMPORT, [
                    'alert_type' => $alert_type
                ]);
                break;
        }
    }
}

/**
 * Global event dispatcher functions
 */

/**
 * Get the global event dispatcher
 */
function get_import_event_dispatcher() {
    return ImportEventDispatcher::get_instance();
}

/**
 * Dispatch an import event
 */
function dispatch_import_event($type, $data = [], $context = [], $async = false) {
    $dispatcher = get_import_event_dispatcher();

    if ($async) {
        $dispatcher->dispatch_async($type, $data, $context);
    } else {
        $dispatcher->dispatch_immediate($type, $data, $context);
    }
}

/**
 * Add an observer to the event system
 */
function add_import_observer(ImportObserverInterface $observer, $priority = EventPriority::NORMAL) {
    $dispatcher = get_import_event_dispatcher();
    $dispatcher->add_observer($observer, $priority);
}

/**
 * Initialize default observers
 */
function initialize_import_observers() {
    static $initialized = false;

    if ($initialized) {
        return;
    }

    $dispatcher = get_import_event_dispatcher();

    // Add built-in observers with different priorities
    $dispatcher->add_observer(new LoggingObserver(), EventPriority::HIGH);
    $dispatcher->add_observer(new PerformanceTrackingObserver(), EventPriority::NORMAL);
    $dispatcher->add_observer(new HealthMonitoringObserver(), EventPriority::HIGH);
    $dispatcher->add_observer(new RecoveryObserver(), EventPriority::CRITICAL);

    $initialized = true;

    PuntWorkLogger::info('Import observers initialized', PuntWorkLogger::CONTEXT_IMPORT, [
        'observer_count' => 4
    ]);
}

/**
 * WordPress action hooks
 */
add_action('puntwork_process_async_events', function() {
    $dispatcher = get_import_event_dispatcher();
    $dispatcher->process_async_events();
});

add_action('puntwork_recovery_check', function() {
    // Implement recovery check logic
    $cb_status = get_circuit_breaker_status();

    if ($cb_status['state'] === 'open') {
        // Attempt to close circuit breaker for testing
        reset_circuit_breaker();

        PuntWorkLogger::info('Recovery check: Attempted to reset circuit breaker', PuntWorkLogger::CONTEXT_IMPORT, [
            'previous_state' => $cb_status['state'],
            'failure_count' => $cb_status['failure_count']
        ]);

        // Dispatch recovery event
        dispatch_import_event(ImportEventType::RECOVERY_ATTEMPTED, [
            'recovery_type' => 'circuit_breaker_reset',
            'previous_state' => $cb_status['state']
        ]);
    }
});

/**
 * Helper functions for common event dispatching
 */

/**
 * Dispatch batch processing event
 */
function dispatch_batch_event($event_type, $batch_data, $import_context = []) {
    dispatch_import_event($event_type, $batch_data, $import_context, false);
}

/**
 * Dispatch import lifecycle event
 */
function dispatch_import_lifecycle_event($event_type, $import_data) {
    // These are typically synchronous for immediate response
    dispatch_import_event($event_type, $import_data, [], false);
}

/**
 * Dispatch health alert event
 */
function dispatch_health_alert($alert_type, $severity, $message, $data = []) {
    dispatch_import_event(ImportEventType::HEALTH_ALERT, [
        'alert_type' => $alert_type,
        'severity' => $severity,
        'message' => $message
    ] + $data, [], true); // Async
}

/**
 * Get event statistics
 */
function get_import_event_statistics() {
    $dispatcher = get_import_event_dispatcher();
    $history = $dispatcher->get_event_history();

    $stats = [
        'total_events' => count($history),
        'async_queue_count' => $dispatcher->get_async_queue_count(),
        'events_by_type' => []
    ];

    foreach ($history as $event) {
        $type = $event['type'];
        if (!isset($stats['events_by_type'][$type])) {
            $stats['events_by_type'][$type] = 0;
        }
        $stats['events_by_type'][$type]++;
    }

    return $stats;
}
