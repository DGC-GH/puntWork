<?php
/**
 * AJAX handlers for import control operations
 * Handles batch processing, cancellation, and status retrieval
 *
 * @package    Puntwork
 * @subpackage AJAX
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/../utilities/ajax-utilities.php';
require_once __DIR__ . '/../utilities/file-utilities.php';
require_once __DIR__ . '/../utilities/options-utilities.php';
require_once __DIR__ . '/../batch/batch-size-management.php';
require_once __DIR__ . '/../import/import-finalization.php';
require_once __DIR__ . '/../scheduling/scheduling-history.php';

/**
 * AJAX handlers for import control operations
 * Handles batch processing, cancellation, and status retrieval
 */

add_action('wp_ajax_run_job_import_batch', __NAMESPACE__ . '\\run_job_import_batch_ajax');
function run_job_import_batch_ajax() {
    if (!validate_ajax_request('run_job_import_batch')) {
        return;
    }

    // For manual imports, use the same async approach as scheduled imports
    // This unifies the import process - both manual and scheduled use the same backend logic

    // Check if an import is already running
    $import_status = get_import_status([]);
    if (isset($import_status['complete']) && !$import_status['complete']) {
        // Calculate actual time elapsed
        $time_elapsed = 0;
        if (isset($import_status['start_time']) && $import_status['start_time'] > 0) {
            $time_elapsed = microtime(true) - $import_status['start_time'];
        } elseif (isset($import_status['time_elapsed'])) {
            $time_elapsed = $import_status['time_elapsed'];
        }

        // Check for stuck imports and clear them automatically
        $current_time = microtime(true);
        $last_update = isset($import_status['last_update']) ? $import_status['last_update'] : 0;
        $time_since_last_update = $current_time - $last_update;

        // Detect stuck imports with multiple criteria:
        // 1. No progress for 5+ minutes (300 seconds)
        // 2. Import running for more than 2 hours without completion (7200 seconds)
        // 3. No status update for 10+ minutes (600 seconds)
        $is_stuck = false;
        $stuck_reason = '';

        if ($import_status['processed'] == 0 && $time_elapsed > 300) {
            $is_stuck = true;
            $stuck_reason = 'no progress for 5+ minutes';
        } elseif ($time_elapsed > 7200) { // 2 hours
            $is_stuck = true;
            $stuck_reason = 'running for more than 2 hours';
        } elseif ($time_since_last_update > 600) { // 10 minutes since last update
            $is_stuck = true;
            $stuck_reason = 'no status update for 10+ minutes';
        }

        if ($is_stuck) {
            PuntWorkLogger::info('Detected stuck import in batch start, clearing status', PuntWorkLogger::CONTEXT_BATCH, [
                'processed' => $import_status['processed'],
                'total' => $import_status['total'],
                'time_elapsed' => $time_elapsed,
                'time_since_last_update' => $time_since_last_update,
                'reason' => $stuck_reason,
                'start_time' => isset($import_status['start_time']) ? date('Y-m-d H:i:s', (int)$import_status['start_time']) : 'unknown',
                'last_update' => isset($import_status['last_update']) ? date('Y-m-d H:i:s', (int)$import_status['last_update']) : 'unknown',
                'batch_size' => $import_status['batch_size'] ?? 'unknown',
                'complete' => $import_status['complete'] ?? false
            ]);
            delete_option('job_import_status');
            delete_option('job_import_progress');
            delete_option('job_import_processed_guids');
            delete_option('job_import_last_batch_time');
            delete_option('job_import_last_batch_processed');
            delete_option('job_import_batch_size');
            delete_option('job_import_consecutive_small_batches');
            delete_transient('import_cancel');

            // Clear the status so we can proceed
            $import_status = [];
        } else {
            send_ajax_error('run_job_import_batch', 'An import is already running');
            return;
        }
    }

    try {
        // Initialize import status for immediate UI feedback
        $initial_status = initialize_import_status(0, 'Manual import started - preparing feeds...');
        set_import_status($initial_status);
        PuntWorkLogger::info('Manual import initialization completed', PuntWorkLogger::CONTEXT_BATCH, [
            'import_type' => 'manual',
            'status_total' => $initial_status['total'],
            'status_complete' => $initial_status['complete'],
            'batch_size' => $initial_status['batch_size'],
            'start_time' => date('Y-m-d H:i:s', (int)$initial_status['start_time']),
            'last_update' => date('Y-m-d H:i:s', (int)$initial_status['last_update'])
        ]);

        // Clear any previous cancellation before starting
        delete_transient('import_cancel');
        PuntWorkLogger::debug('Import cancellation transient cleared for manual import', PuntWorkLogger::CONTEXT_BATCH);

        // Schedule the import to run asynchronously (same as scheduled imports)
        if (function_exists('as_enqueue_async_action')) {
            // Use Action Scheduler async action for immediate execution
            as_enqueue_async_action('puntwork_manual_import_async');
            PuntWorkLogger::info('Manual import scheduled via Action Scheduler (async)', PuntWorkLogger::CONTEXT_BATCH, [
                'scheduler' => 'action_scheduler',
                'action' => 'puntwork_manual_import_async',
                'execution_type' => 'immediate_async'
            ]);
        } elseif (function_exists('as_schedule_single_action')) {
            // Use Action Scheduler if available
            as_schedule_single_action(time(), 'puntwork_manual_import_async');
            PuntWorkLogger::info('Manual import scheduled via Action Scheduler (single)', PuntWorkLogger::CONTEXT_BATCH, [
                'scheduler' => 'action_scheduler',
                'action' => 'puntwork_manual_import_async',
                'scheduled_time' => date('Y-m-d H:i:s', time()),
                'execution_type' => 'scheduled_single'
            ]);
        } elseif (function_exists('wp_schedule_single_event')) {
            // Fallback: Use WordPress cron for near-immediate execution
            wp_schedule_single_event(time() + 1, 'puntwork_manual_import_async');
            PuntWorkLogger::warn('Manual import scheduled via WordPress cron (fallback)', PuntWorkLogger::CONTEXT_BATCH, [
                'scheduler' => 'wordpress_cron',
                'action' => 'puntwork_manual_import_async',
                'scheduled_time' => date('Y-m-d H:i:s', time() + 1),
                'delay_seconds' => 1,
                'reason' => 'action_scheduler_not_available'
            ]);
        } else {
            // Final fallback: Run synchronously (not ideal for UI but maintains functionality)
            PuntWorkLogger::warn('Manual import running synchronously (final fallback)', PuntWorkLogger::CONTEXT_BATCH, [
                'execution_type' => 'synchronous',
                'reason' => 'no_async_scheduling_available'
            ]);
            $result = run_manual_import();

            if ($result['success']) {
                PuntWorkLogger::info('Synchronous manual import completed successfully', PuntWorkLogger::CONTEXT_BATCH, [
                    'processed' => $result['processed'] ?? 0,
                    'total' => $result['total'] ?? 0,
                    'published' => $result['published'] ?? 0,
                    'updated' => $result['updated'] ?? 0,
                    'skipped' => $result['skipped'] ?? 0,
                    'time_elapsed' => $result['time_elapsed'] ?? 0,
                    'execution_type' => 'synchronous'
                ]);
                send_ajax_success('run_job_import_batch', [
                    'message' => 'Import completed successfully',
                    'result' => $result,
                    'async' => false
                ], [
                    'message' => 'Import completed successfully',
                    'success' => $result['success'] ?? null,
                    'processed' => $result['processed'] ?? null,
                    'total' => $result['total'] ?? null,
                    'time_elapsed' => $result['time_elapsed'] ?? null,
                    'async' => false
                ]);
            } else {
                PuntWorkLogger::error('Synchronous manual import failed', PuntWorkLogger::CONTEXT_BATCH, [
                    'error_message' => $result['message'] ?? 'Unknown error',
                    'execution_type' => 'synchronous'
                ]);
                // Reset import status on failure so future attempts can start
                delete_import_status();
                PuntWorkLogger::info('Import status reset after synchronous import failure', PuntWorkLogger::CONTEXT_BATCH);
                send_ajax_error('run_job_import_batch', 'Import failed: ' . ($result['message'] ?? 'Unknown error'));
            }
            return;
        }

        // Return success immediately so UI can start polling
        PuntWorkLogger::info('Manual import initiation completed successfully', PuntWorkLogger::CONTEXT_BATCH, [
            'async' => true,
            'import_type' => 'manual',
            'ui_polling_enabled' => true
        ]);
        send_ajax_success('run_job_import_batch', [
            'message' => 'Import started successfully',
            'async' => true
        ], [
            'message' => 'Import started successfully',
            'async' => true,
            'import_type' => 'manual'
        ]);

    } catch (\Exception $e) {
        PuntWorkLogger::error('Manual import AJAX initiation failed', PuntWorkLogger::CONTEXT_AJAX, [
            'error_message' => $e->getMessage(),
            'error_file' => $e->getFile(),
            'error_line' => $e->getLine(),
            'import_type' => 'manual'
        ]);
        send_ajax_error('run_job_import_batch', 'Failed to start import: ' . $e->getMessage());
    }
}
add_action('wp_ajax_cancel_job_import', __NAMESPACE__ . '\\cancel_job_import_ajax');
function cancel_job_import_ajax() {
    if (!validate_ajax_request('cancel_job_import')) {
        return;
    }

    $before_cancel = get_import_status([]);
    set_transient('import_cancel', true, 3600);

    // POISON PILL: Aggressively cancel all import-related processes
    $cancelled_count = cancel_all_import_processes();

    // Also clear the import status to reset the UI
    delete_option('job_import_status');
    delete_option('job_import_batch_size');

    PuntWorkLogger::info('Import cancellation initiated - POISON PILL deployed', PuntWorkLogger::CONTEXT_BATCH, [
        'import_status_before_cancel' => [
            'processed' => $before_cancel['processed'] ?? 0,
            'total' => $before_cancel['total'] ?? 0,
            'complete' => $before_cancel['complete'] ?? false,
            'time_elapsed' => $before_cancel['time_elapsed'] ?? 0
        ],
        'transient_set' => true,
        'transient_expiry' => 3600,
        'options_cleared' => ['job_import_status', 'job_import_batch_size'],
        'poison_pill_processes_cancelled' => $cancelled_count,
        'cancellation_method' => 'aggressive_poison_pill'
    ]);

    send_ajax_success('cancel_job_import', [
        'cancelled_processes' => $cancelled_count,
        'method' => 'poison_pill'
    ]);
}

add_action('wp_ajax_clear_import_cancel', __NAMESPACE__ . '\\clear_import_cancel_ajax');
function clear_import_cancel_ajax() {
    if (!validate_ajax_request('clear_import_cancel')) {
        return;
    }

    $transient_existed = get_transient('import_cancel') !== false;
    $force_cancel_existed = get_transient('import_force_cancel') !== false;
    $emergency_stop_existed = get_transient('import_emergency_stop') !== false;

    // Clear all cancellation flags
    delete_transient('import_cancel');
    delete_transient('import_force_cancel');
    delete_transient('import_emergency_stop');

    PuntWorkLogger::info('Import cancellation flags cleared', PuntWorkLogger::CONTEXT_BATCH, [
        'import_cancel_existed' => $transient_existed,
        'import_force_cancel_existed' => $force_cancel_existed,
        'import_emergency_stop_existed' => $emergency_stop_existed,
        'action' => 'clear_cancellation_flags'
    ]);

    send_ajax_success('clear_import_cancel', []);
}

add_action('wp_ajax_reset_job_import', __NAMESPACE__ . '\\reset_job_import_ajax');
function reset_job_import_ajax() {
    if (!validate_ajax_request('reset_job_import')) {
        return;
    }

    $before_reset = get_import_status([]);

    // POISON PILL: Aggressively cancel all import-related processes before reset
    $cancelled_count = cancel_all_import_processes();

    // Clear all import-related data
    $cleared_options = [
        'job_import_status',
        'job_import_progress',
        'job_import_processed_guids',
        'job_import_last_batch_time',
        'job_import_last_batch_processed',
        'job_import_batch_size',
        'job_import_consecutive_small_batches',
        'job_import_consecutive_batches'
    ];

    foreach ($cleared_options as $option) {
        delete_option($option);
    }

    // Clear all cancellation transients
    delete_transient('import_cancel');
    delete_transient('import_force_cancel');
    delete_transient('import_emergency_stop');

    PuntWorkLogger::info('Import system completely reset', PuntWorkLogger::CONTEXT_BATCH, [
        'import_status_before_reset' => [
            'processed' => $before_reset['processed'] ?? 0,
            'total' => $before_reset['total'] ?? 0,
            'complete' => $before_reset['complete'] ?? false,
            'time_elapsed' => $before_reset['time_elapsed'] ?? 0
        ],
        'options_cleared' => $cleared_options,
        'transients_cleared' => ['import_cancel', 'import_force_cancel', 'import_emergency_stop'],
        'processes_cancelled' => $cancelled_count,
        'reset_type' => 'complete_system_reset_with_cancellation'
    ]);

    send_ajax_success('reset_job_import', []);
}

add_action('wp_ajax_get_job_import_status', __NAMESPACE__ . '\\get_job_import_status_ajax');

/**
 * Check for active scheduled import jobs
 */
function check_active_scheduled_imports() {
    $active_imports = [];

    PuntWorkLogger::debug('Checking for active scheduled imports', PuntWorkLogger::CONTEXT_AJAX);

    // Check for Action Scheduler jobs
    if (function_exists('as_get_scheduled_actions')) {
        PuntWorkLogger::debug('Action Scheduler available, checking for scheduled import jobs', PuntWorkLogger::CONTEXT_AJAX);

        // Check for pending scheduled import actions
        try {
            $scheduled_actions = as_get_scheduled_actions([
                'hook' => 'puntwork_scheduled_import',
                'status' => \ActionScheduler_Store::STATUS_PENDING
            ]);
            PuntWorkLogger::debug('Found pending scheduled import actions', PuntWorkLogger::CONTEXT_AJAX, [
                'count' => count($scheduled_actions),
                'actions' => array_map(function($action) {
                    return [
                        'id' => $action->get_schedule()->get_date()->getTimestamp(),
                        'next_run' => $action->get_schedule()->get_date()->format('Y-m-d H:i:s'),
                        'args' => $action->get_args()
                    ];
                }, $scheduled_actions)
            ]);
            if (!empty($scheduled_actions)) {
                $active_imports['scheduled_pending'] = count($scheduled_actions);
            }
        } catch (\Exception $e) {
            PuntWorkLogger::error('Error checking pending scheduled import actions', PuntWorkLogger::CONTEXT_AJAX, [
                'error' => $e->getMessage()
            ]);
        }

        // Check for running scheduled import actions
        try {
            $running_actions = as_get_scheduled_actions([
                'hook' => 'puntwork_scheduled_import',
                'status' => \ActionScheduler_Store::STATUS_RUNNING
            ]);
            PuntWorkLogger::debug('Found running scheduled import actions', PuntWorkLogger::CONTEXT_AJAX, [
                'count' => count($running_actions),
                'actions' => array_map(function($action) {
                    return [
                        'id' => $action->get_id(),
                        'started' => $action->get_schedule()->get_date()->format('Y-m-d H:i:s'),
                        'args' => $action->get_args()
                    ];
                }, $running_actions)
            ]);
            if (!empty($running_actions)) {
                $active_imports['scheduled_running'] = count($running_actions);
            }
        } catch (\Exception $e) {
            PuntWorkLogger::error('Error checking running scheduled import actions', PuntWorkLogger::CONTEXT_AJAX, [
                'error' => $e->getMessage()
            ]);
        }

        // Check for continuation actions
        try {
            $continuation_actions = as_get_scheduled_actions([
                'hook' => 'puntwork_continue_import',
                'status' => [\ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING]
            ]);
            PuntWorkLogger::debug('Found continuation import actions', PuntWorkLogger::CONTEXT_AJAX, [
                'count' => count($continuation_actions),
                'actions' => array_map(function($action) {
                    return [
                        'id' => $action->get_id(),
                        'status' => \ActionScheduler::store()->get_status($action->get_id()),
                        'scheduled' => $action->get_schedule()->get_date()->format('Y-m-d H:i:s'),
                        'args' => $action->get_args()
                    ];
                }, $continuation_actions)
            ]);
            if (!empty($continuation_actions)) {
                $active_imports['continuation_jobs'] = count($continuation_actions);
            }
        } catch (\Exception $e) {
            PuntWorkLogger::error('Error checking continuation import actions', PuntWorkLogger::CONTEXT_AJAX, [
                'error' => $e->getMessage()
            ]);
        }

        // Check for async actions
        try {
            $async_actions = as_get_scheduled_actions([
                'hook' => 'puntwork_scheduled_import_async',
                'status' => [\ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING]
            ]);
            PuntWorkLogger::debug('Found async scheduled import actions', PuntWorkLogger::CONTEXT_AJAX, [
                'count' => count($async_actions),
                'actions' => array_map(function($action) {
                    return [
                        'id' => $action->get_id(),
                        'status' => \ActionScheduler::store()->get_status($action->get_id()),
                        'scheduled' => $action->get_schedule()->get_date()->format('Y-m-d H:i:s'),
                        'args' => $action->get_args()
                    ];
                }, $async_actions)
            ]);
            if (!empty($async_actions)) {
                $active_imports['async_scheduled'] = count($async_actions);
            }
        } catch (\Exception $e) {
            PuntWorkLogger::error('Error checking async scheduled import actions', PuntWorkLogger::CONTEXT_AJAX, [
                'error' => $e->getMessage()
            ]);
        }
    } else {
        PuntWorkLogger::warn('Action Scheduler not available for checking scheduled imports', PuntWorkLogger::CONTEXT_AJAX);
    }

    // Check for WordPress cron for scheduled imports
    $next_scheduled = wp_next_scheduled('puntwork_scheduled_import');
    if ($next_scheduled) {
        PuntWorkLogger::debug('Found WordPress cron scheduled import', PuntWorkLogger::CONTEXT_AJAX, [
            'next_run_timestamp' => $next_scheduled,
            'next_run_formatted' => date('Y-m-d H:i:s', (int)$next_scheduled)
        ]);
        $active_imports['wp_cron_scheduled'] = $next_scheduled;
    } else {
        PuntWorkLogger::debug('No WordPress cron scheduled import found', PuntWorkLogger::CONTEXT_AJAX);
    }

    // Check for WordPress cron for manual imports
    $next_manual = wp_next_scheduled('puntwork_manual_import');
    if ($next_manual) {
        PuntWorkLogger::debug('Found WordPress cron manual import', PuntWorkLogger::CONTEXT_AJAX, [
            'next_run_timestamp' => $next_manual,
            'next_run_formatted' => date('Y-m-d H:i:s', (int)$next_manual)
        ]);
        $active_imports['wp_cron_manual'] = $next_manual;
    } else {
        PuntWorkLogger::debug('No WordPress cron manual import found', PuntWorkLogger::CONTEXT_AJAX);
    }

    // Check if import is currently running (from status)
    $import_status = get_import_status([]);
    $is_complete = $import_status['complete'] ?? false;
    $is_running = !$is_complete &&
                  (isset($import_status['start_time']) || ($import_status['processed'] ?? 0) > 0);

    PuntWorkLogger::debug('Current import status check', PuntWorkLogger::CONTEXT_AJAX, [
        'is_running' => $is_running,
        'complete' => $is_complete,
        'start_time' => $import_status['start_time'] ?? 'undefined',
        'processed' => $import_status['processed'] ?? 0,
        'total' => $import_status['total'] ?? 0,
        'detection_reason' => isset($import_status['start_time']) ? 'start_time_present' : (($import_status['processed'] ?? 0) > 0 ? 'active_processing' : 'none')
    ]);

    if ($is_running) {
        $active_imports['import_running'] = true;
        $active_imports['running_details'] = [
            'processed' => $import_status['processed'] ?? 0,
            'total' => $import_status['total'] ?? 0,
            'start_time' => $import_status['start_time'] ?? null
        ];
        PuntWorkLogger::info('Import detected as running', PuntWorkLogger::CONTEXT_AJAX, [
            'processed' => $import_status['processed'] ?? 0,
            'total' => $import_status['total'] ?? 0,
            'start_time' => $import_status['start_time'] ?? null,
            'is_counting_phase' => ($import_status['total'] ?? 0) === 0 && ($import_status['processed'] ?? 0) > 0
        ]);
    }

    $has_active_imports = !empty($active_imports);
    PuntWorkLogger::info('Active scheduled imports check completed', PuntWorkLogger::CONTEXT_AJAX, [
        'active_imports' => $active_imports,
        'has_active_imports' => $has_active_imports
    ]);

    return $active_imports;
}

add_action('wp_ajax_get_active_scheduled_imports', __NAMESPACE__ . '\\get_active_scheduled_imports_ajax');
function get_active_scheduled_imports_ajax() {
    if (!validate_ajax_request('get_active_scheduled_imports')) {
        return;
    }

    try {
        $active_imports = check_active_scheduled_imports();

        // Check scheduling settings
        $schedule_enabled = get_option('puntwork_import_schedule', ['enabled' => false]);
        $schedule_enabled = $schedule_enabled['enabled'] ?? false;
        $schedule_frequency = get_option('puntwork_import_schedule', ['frequency' => 'daily']);
        $schedule_frequency = $schedule_frequency['frequency'] ?? 'daily';

        PuntWorkLogger::info('Scheduling settings checked', PuntWorkLogger::CONTEXT_AJAX, [
            'schedule_enabled' => $schedule_enabled,
            'schedule_frequency' => $schedule_frequency,
            'active_imports_count' => count($active_imports)
        ]);

        $response = [
            'schedule_enabled' => $schedule_enabled,
            'schedule_frequency' => $schedule_frequency,
            'active_imports' => $active_imports,
            'has_active_imports' => !empty($active_imports),
            'last_checked' => microtime(true)
        ];

        wp_send_json_success($response);

    } catch (\Exception $e) {
        PuntWorkLogger::error('Error checking active scheduled imports', PuntWorkLogger::CONTEXT_AJAX, [
            'error_message' => $e->getMessage(),
            'error_file' => $e->getFile(),
            'error_line' => $e->getLine()
        ]);
        wp_send_json_error(['message' => 'Error checking scheduled imports status']);
    }
}

/**
 * Calculate estimated time remaining for import completion
 *
 * @param array $status The current import status array
 * @return float Estimated time remaining in seconds.
 */
function calculate_estimated_time_remaining($status) {
    // Ensure we have required data with safe defaults
    $is_complete = $status['complete'] ?? false;
    $processed = $status['processed'] ?? 0;
    $total = $status['total'] ?? 0;
    $job_importing_time_elapsed = $status['job_importing_time_elapsed'] ?? 0;

    if ($is_complete || $processed <= 0 || $total <= 0 || $job_importing_time_elapsed <= 0) {
        return 0;
    }

    $items_remaining = $total - $processed;
    if ($items_remaining <= 0) {
        return 0;
    }

    $time_per_item = $job_importing_time_elapsed / $processed;
    $estimated_seconds = $items_remaining * $time_per_item;

    // Ensure we don't return negative or infinite values
    if (!is_finite($estimated_seconds) || $estimated_seconds < 0) {
        return 0;
    }

    // Cap at a reasonable maximum (24 hours)
    return min($estimated_seconds, 86400);
}

function get_job_import_status_ajax() {
    try {
        // Get status first to determine if we should log
        $progress = get_import_status();

        // If no import status exists, return a minimal default status without triggering expensive calculations
        if (!$progress || (is_array($progress) && empty($progress))) {
            $progress = [
                'total' => 0,
                'processed' => 0,
                'published' => 0,
                'updated' => 0,
                'skipped' => 0,
                'duplicates_drafted' => 0,
                'time_elapsed' => 0,
                'complete' => false,
                'success' => false,
                'error_message' => '',
                'batch_size' => DEFAULT_BATCH_SIZE, // Use constant instead of calling get_batch_size()
                'inferred_languages' => 0,
                'inferred_benefits' => 0,
                'schema_generated' => 0,
                'start_time' => null,
                'end_time' => null,
                'last_update' => microtime(true),
                'logs' => []
            ];
        }

        // Validate that progress is an array
        if (!is_array($progress)) {
            PuntWorkLogger::error('Import status is not an array', PuntWorkLogger::CONTEXT_AJAX, [
                'status_type' => gettype($progress),
                'status_value' => is_scalar($progress) ? $progress : 'non-scalar'
            ]);
            wp_send_json_error(['message' => 'Import status data is corrupted']);
            return;
        }

        // Debug log the status data (only in debug mode to avoid spam)
        // NOTE: To enable polling debug logs, define PUNTWORK_DEBUG_POLLING as true in wp-config.php
        if (defined('WP_DEBUG') && WP_DEBUG && defined('PUNTWORK_DEBUG_POLLING') && PUNTWORK_DEBUG_POLLING) {
            PuntWorkLogger::debug('Import status retrieved', PuntWorkLogger::CONTEXT_AJAX, [
                'total' => $progress['total'] ?? 0,
                'processed' => $progress['processed'] ?? 0,
                'complete' => $progress['complete'] ?? false,
                'has_start_time' => isset($progress['start_time']),
                'start_time' => $progress['start_time'] ?? null,
                'logs_count' => count($progress['logs'] ?? []),
                'is_counting_phase' => (($progress['total'] ?? 0) == 0 && isset($progress['start_time']) && $progress['start_time'] > 0)
            ]);
        }

        $total = $progress['total'] ?? 0;
        $processed = $progress['processed'] ?? 0;
        $complete = $progress['complete'] ?? false;
        $should_log = $processed > 0 || $complete === true;

        // Validate request (conditionally log based on import state)
        if (!validate_ajax_request('get_job_import_status', false)) {
            return;
        }

        // Only log debug when import has meaningful progress to reduce log spam
        // NOTE: To enable polling debug logs, define PUNTWORK_DEBUG_POLLING as true in wp-config.php
        if ($should_log && defined('PUNTWORK_DEBUG_POLLING') && PUNTWORK_DEBUG_POLLING) {
            PuntWorkLogger::debug('Import status retrieved with active progress', PuntWorkLogger::CONTEXT_BATCH, [
                'total' => $total,
                'processed' => $processed,
                'complete' => $complete,
                'progress_percentage' => $total > 0 ? round(($processed / $total) * 100, 2) : 0,
                'has_meaningful_progress' => $should_log
            ]);
        }

        // Check for stuck or stale imports and clear them
        if (isset($progress['complete']) && !$progress['complete'] && isset($progress['total']) && $progress['total'] > 0) {
            $current_time = microtime(true);
            $time_elapsed = 0;
            $last_update = isset($progress['last_update']) ? $progress['last_update'] : 0;
            $time_since_last_update = $current_time - $last_update;

            if (isset($progress['start_time']) && $progress['start_time'] > 0) {
                $time_elapsed = microtime(true) - $progress['start_time'];
            } elseif (isset($progress['time_elapsed'])) {
                $time_elapsed = $progress['time_elapsed'];
            }

            // Detect stuck imports with multiple criteria:
            // 1. No progress for 5+ minutes (300 seconds) - but be more lenient if we're still counting items
            // 2. Import running for more than 2 hours without completion (7200 seconds)
            // 3. No status update for 10+ minutes (600 seconds)
            $is_stuck = false;
            $stuck_reason = '';

            // Check if we're in the counting phase (total = 0 but import has started)
            $is_counting_phase = ($progress['total'] == 0 && isset($progress['start_time']) && $progress['start_time'] > 0);

            if ($progress['processed'] == 0 && $time_elapsed > 300 && !$is_counting_phase) {
                $is_stuck = true;
                $stuck_reason = 'no progress for 5+ minutes';
            } elseif ($time_elapsed > 7200) { // 2 hours
                $is_stuck = true;
                $stuck_reason = 'running for more than 2 hours';
            } elseif ($time_since_last_update > 600) { // 10 minutes since last update
                $is_stuck = true;
                $stuck_reason = 'no status update for 10+ minutes';
            }

            if ($is_stuck) {
                PuntWorkLogger::warn('Stuck import detected and cleared during status check', PuntWorkLogger::CONTEXT_BATCH, [
                    'processed' => $progress['processed'] ?? 0,
                    'total' => $progress['total'] ?? 0,
                    'time_elapsed' => round($time_elapsed, 2),
                    'time_since_last_update' => $time_since_last_update,
                    'reason' => $stuck_reason,
                    'start_time' => isset($progress['start_time']) ? date('Y-m-d H:i:s', (int)$progress['start_time']) : 'unknown',
                    'last_update' => isset($progress['last_update']) ? date('Y-m-d H:i:s', (int)$progress['last_update']) : 'unknown',
                    'current_time' => date('Y-m-d H:i:s', (int)$current_time),
                    'action' => 'cleared_stuck_import'
                ]);
                delete_option('job_import_status');
                delete_option('job_import_progress');
                delete_option('job_import_processed_guids');
                delete_option('job_import_last_batch_time');
                delete_option('job_import_last_batch_processed');
                delete_option('job_import_batch_size');
                delete_option('job_import_consecutive_small_batches');
                delete_transient('import_cancel');
                delete_transient('import_force_cancel');
                delete_transient('import_emergency_stop');

                // Return fresh status
                $progress = initialize_import_status(0, '', null);
            }
        }

        // Add resume_progress for JavaScript
        $progress['resume_progress'] = get_import_progress();

        // Track job importing start time - only initialize if import is not complete and hasn't started yet
        $is_import_complete = ($progress['complete'] ?? false) === true;
        $has_job_start_time = isset($progress['job_import_start_time']) && $progress['job_import_start_time'] > 0;

        if (($progress['total'] ?? 0) > 1 && !$has_job_start_time && !$is_import_complete) {
            $progress['job_import_start_time'] = microtime(true);
            set_import_status($progress);
            PuntWorkLogger::debug('Job import start time initialized', PuntWorkLogger::CONTEXT_BATCH, [
                'job_import_start_time' => date('Y-m-d H:i:s', (int)$progress['job_import_start_time']),
                'total_jobs' => $progress['total'],
                'is_import_complete' => $is_import_complete,
                'has_job_start_time' => $has_job_start_time
            ]);
        }

        // Calculate job importing elapsed time with safe defaults
        $job_import_start_time = $progress['job_import_start_time'] ?? null;
        $time_elapsed = $progress['time_elapsed'] ?? 0;
        $progress['job_importing_time_elapsed'] = $job_import_start_time ? microtime(true) - $job_import_start_time : $time_elapsed;

        // Add batch timing data for accurate time calculations
        $progress['batch_time'] = get_last_batch_time();
        $progress['batch_processed'] = get_last_batch_processed();

        // Add estimated time remaining calculation from PHP with error handling
        try {
            $progress['estimated_time_remaining'] = calculate_estimated_time_remaining($progress);
        } catch (\Exception $e) {
            PuntWorkLogger::error('Error calculating estimated time remaining', PuntWorkLogger::CONTEXT_BATCH, [
                'error' => $e->getMessage(),
                'progress_data' => [
                    'total' => $progress['total'] ?? 0,
                    'processed' => $progress['processed'] ?? 0,
                    'time_elapsed' => $progress['time_elapsed'] ?? 0
                ]
            ]);
            $progress['estimated_time_remaining'] = 0;
        }

        // Add a last_modified timestamp for client-side caching (use microtime for better precision)
        $progress['last_modified'] = microtime(true);

        // Only log AJAX response when import has meaningful progress to reduce log spam
        if ($total > 0 || $processed > 0 || $complete === true) {
            // Create highly condensed log data to prevent extremely long log lines
            $sanitized_log_data = [
                'total' => $progress['total'] ?? 0,
                'processed' => $progress['processed'] ?? 0,
                'published' => $progress['published'] ?? 0,
                'updated' => $progress['updated'] ?? 0,
                'skipped' => $progress['skipped'] ?? 0,
                'duplicates_drafted' => $progress['duplicates_drafted'] ?? 0,
                'complete' => $progress['complete'] ?? false,
                'time_elapsed' => round($progress['time_elapsed'] ?? 0, 2),
                'batch_count' => $progress['batch_count'] ?? 0,
                'logs_count' => count($progress['logs'] ?? []),
                'last_log_entry' => (!empty($progress['logs'])) ? end($progress['logs']) : null
            ];

            // Create a smaller response for the frontend to avoid JSON encoding issues with large data
            $response_data = [
                'total' => $progress['total'] ?? 0,
                'processed' => $progress['processed'] ?? 0,
                'published' => $progress['published'] ?? 0,
                'updated' => $progress['updated'] ?? 0,
                'skipped' => $progress['skipped'] ?? 0,
                'duplicates_drafted' => $progress['duplicates_drafted'] ?? 0,
                'complete' => $progress['complete'] ?? false,
                'time_elapsed' => $progress['time_elapsed'] ?? 0,
                'job_importing_time_elapsed' => $progress['job_importing_time_elapsed'] ?? 0,
                'estimated_time_remaining' => $progress['estimated_time_remaining'] ?? 0,
                'batch_count' => $progress['batch_count'] ?? 0,
                'last_modified' => $progress['last_modified'] ?? microtime(true),
                'progress_percentage' => $total > 0 ? round(($processed / $total) * 100, 2) : 0,
                'logs' => array_slice($progress['logs'] ?? [], -10) // Only send last 10 logs to frontend
            ];

            send_ajax_success('get_job_import_status', $response_data, $sanitized_log_data);
        } else {
            // For initial polling or counting phase, still send response but with minimal logging
            // Check if we're in counting phase (import started but total still 0)
            $is_counting_phase = isset($progress['start_time']) && $progress['start_time'] > 0 && $progress['total'] == 0;
            if ($is_counting_phase && defined('WP_DEBUG') && WP_DEBUG && defined('PUNTWORK_DEBUG_POLLING') && PUNTWORK_DEBUG_POLLING) {
                PuntWorkLogger::debug('Import status requested during counting phase', PuntWorkLogger::CONTEXT_AJAX, [
                    'start_time' => $progress['start_time'] ?? null,
                    'time_elapsed' => $progress['job_importing_time_elapsed'] ?? $progress['time_elapsed'] ?? 0,
                    'logs_count' => count($progress['logs'] ?? [])
                ]);
            }
            wp_send_json_success($progress);
        }

    } catch (\Exception $e) {
        PuntWorkLogger::error('Fatal error in import status retrieval', PuntWorkLogger::CONTEXT_AJAX, [
            'error_message' => $e->getMessage(),
            'error_file' => $e->getFile(),
            'error_line' => $e->getLine(),
            'stack_trace' => $e->getTraceAsString()
        ]);
        wp_send_json_error(['message' => 'Internal server error occurred while retrieving import status']);
    }
}

add_action('wp_ajax_cleanup_trashed_jobs', __NAMESPACE__ . '\\cleanup_trashed_jobs_ajax');
function cleanup_trashed_jobs_ajax() {
    if (!validate_ajax_request('cleanup_trashed_jobs')) {
        return;
    }

    global $wpdb;

    // Get batch parameters
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 50;
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $is_continue = isset($_POST['is_continue']) && $_POST['is_continue'] === 'true';

    // Initialize progress tracking for first batch
    if (!$is_continue) {
        // MEMORY-SAFE: Get total count without loading all data
        $total_count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_type = 'job' AND post_status = 'trash'
        "));

        set_cleanup_trashed_progress([
            'total_processed' => 0,
            'total_deleted' => 0,
            'total_jobs' => $total_count,
            'current_offset' => 0,
            'complete' => false,
            'start_time' => microtime(true),
            'logs' => [],
            'memory_peak_start' => memory_get_peak_usage(true)
        ]);
    }

    $progress = get_cleanup_trashed_progress();

    // MEMORY CHECK: Adjust batch size based on available memory
    $memory_limit = get_memory_limit_bytes();
    $current_memory = memory_get_usage(true);
    $available_memory = $memory_limit - $current_memory;
    $memory_ratio = $current_memory / $memory_limit;

    // Reduce batch size if memory usage is high
    if ($memory_ratio > 0.7) {
        $batch_size = max(10, $batch_size / 2);
        PuntWorkLogger::warning('Reducing batch size due to high memory usage in trashed cleanup', PuntWorkLogger::CONTEXT_BATCH, [
            'original_batch_size' => isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 50,
            'adjusted_batch_size' => $batch_size,
            'memory_ratio' => $memory_ratio,
            'available_memory_mb' => $available_memory / (1024 * 1024)
        ]);
    }

    try {
        // MEMORY-SAFE: Get batch of trashed jobs to process with memory monitoring
        $trashed_posts = $wpdb->get_results($wpdb->prepare("
            SELECT ID, post_title
            FROM {$wpdb->posts}
            WHERE post_type = 'job' AND post_status = 'trash'
            ORDER BY ID
            LIMIT %d OFFSET %d
        ", $batch_size, $offset));

        if (empty($trashed_posts)) {
            // No more jobs to process
            $progress['complete'] = true;
            $progress['end_time'] = microtime(true);
            $progress['time_elapsed'] = $progress['end_time'] - $progress['start_time'];
            $progress['memory_peak_end'] = memory_get_peak_usage(true);
            $progress['memory_peak_mb'] = $progress['memory_peak_end'] / (1024 * 1024);
            set_cleanup_trashed_progress($progress);

            $message = "Cleanup completed: Processed {$progress['total_processed']} jobs, deleted {$progress['total_deleted']} trashed jobs";
            PuntWorkLogger::info('Cleanup of trashed jobs completed', PuntWorkLogger::CONTEXT_BATCH, [
                'total_processed' => $progress['total_processed'],
                'total_deleted' => $progress['total_deleted'],
                'memory_peak_mb' => $progress['memory_peak_mb'],
                'time_elapsed' => $progress['time_elapsed']
            ]);

            wp_send_json_success([
                'message' => $message,
                'complete' => true,
                'total_processed' => $progress['total_processed'],
                'total_deleted' => $progress['total_deleted'],
                'time_elapsed' => $progress['time_elapsed'],
                'logs' => array_slice($progress['logs'], -50)
            ]);
        }

        // Process this batch with memory monitoring
        $deleted_count = 0;
        $logs = $progress['logs'];
        $batch_memory_start = memory_get_usage(true);

        foreach ($trashed_posts as $post) {
            // MEMORY CHECK: Monitor memory usage during processing
            $current_memory = memory_get_usage(true);
            $current_ratio = $current_memory / $memory_limit;

            if ($current_ratio > 0.85) {
                PuntWorkLogger::warning('High memory usage detected during trashed cleanup processing', PuntWorkLogger::CONTEXT_BATCH, [
                    'current_memory_ratio' => $current_ratio,
                    'post_id' => $post->ID,
                    'processed_in_batch' => $deleted_count
                ]);

                // Force garbage collection and continue
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }

            $result = wp_delete_post($post->ID, true); // true = force delete, skip trash
            if ($result) {
                $deleted_count++;
                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Permanently deleted trashed job: ' . $post->post_title . ' (ID: ' . $post->ID . ')';
            } else {
                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Failed to delete trashed job: ' . $post->post_title . ' (ID: ' . $post->ID . ')';
            }

            // MEMORY CLEANUP: Aggressive cleanup every 5 deletions
            if ($deleted_count % 5 === 0) {
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
                // Clear any cached WordPress object caches that might be holding memory
                if (function_exists('wp_cache_flush')) {
                    wp_cache_flush();
                }
            }
        }

        $batch_memory_end = memory_get_usage(true);
        $batch_memory_used = $batch_memory_end - $batch_memory_start;

        // Update progress
        $progress['total_processed'] += count($trashed_posts);
        $progress['total_deleted'] += $deleted_count;
        $progress['current_offset'] = $offset + $batch_size;
        $progress['logs'] = $logs;
        set_cleanup_trashed_progress($progress);

        // Calculate progress percentage
        $progress_percentage = $progress['total_jobs'] > 0 ? round(($progress['total_processed'] / $progress['total_jobs']) * 100, 1) : 0;

        wp_send_json_success([
            'message' => "Batch processed: {$progress['total_processed']}/{$progress['total_jobs']} jobs ({$progress_percentage}%), deleted {$deleted_count} trashed jobs this batch",
            'complete' => false,
            'next_offset' => $progress['current_offset'],
            'batch_size' => $batch_size,
            'total_processed' => $progress['total_processed'],
            'total_deleted' => $progress['total_deleted'],
            'progress_percentage' => $progress_percentage,
            'logs' => array_slice($logs, -20) // Return last 20 log entries for this batch
        ]);

    } catch (\Exception $e) {
        PuntWorkLogger::error('Cleanup of trashed jobs failed', PuntWorkLogger::CONTEXT_BATCH, [
            'error' => $e->getMessage(),
            'memory_usage_mb' => memory_get_usage(true) / (1024 * 1024),
            'memory_limit_mb' => $memory_limit / (1024 * 1024)
        ]);
        wp_send_json_error(['message' => 'Cleanup failed: ' . $e->getMessage()]);
    }
}

add_action('wp_ajax_cleanup_drafted_jobs', __NAMESPACE__ . '\\cleanup_drafted_jobs_ajax');
function cleanup_drafted_jobs_ajax() {
    if (!validate_ajax_request('cleanup_drafted_jobs')) {
        return;
    }

    global $wpdb;

    // Get batch parameters
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 50;
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $is_continue = isset($_POST['is_continue']) && $_POST['is_continue'] === 'true';

    // Initialize progress tracking for first batch
    if (!$is_continue) {
        // MEMORY-SAFE: Get draft job IDs in chunks to avoid loading all into memory
        $draft_job_ids = [];
        $offset_temp = 0;
        $chunk_size = 1000; // Load GUIDs in chunks of 1000

        while (true) {
            $chunk = $wpdb->get_col($wpdb->prepare("
                SELECT ID FROM {$wpdb->posts}
                WHERE post_type = 'job' AND post_status = 'draft'
                ORDER BY ID DESC
                LIMIT %d OFFSET %d
            ", $chunk_size, $offset_temp));

            if (empty($chunk)) {
                break;
            }

            $draft_job_ids = array_merge($draft_job_ids, $chunk);
            $offset_temp += $chunk_size;

            // MEMORY CHECK: Prevent excessive memory usage during initialization
            $current_memory = memory_get_usage(true);
            $memory_ratio = $current_memory / get_memory_limit_bytes();

            if ($memory_ratio > 0.6) {
                PuntWorkLogger::warning('High memory usage during draft job ID collection, reducing chunk size', PuntWorkLogger::CONTEXT_BATCH, [
                    'collected_count' => count($draft_job_ids),
                    'memory_ratio' => $memory_ratio,
                    'chunk_size_reduced' => true
                ]);
                $chunk_size = max(100, $chunk_size / 2);
            }

            // Force cleanup between chunks
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        $total_count = count($draft_job_ids);

        PuntWorkLogger::info('Draft cleanup initialized with memory-safe ID collection', PuntWorkLogger::CONTEXT_BATCH, [
            'total_draft_jobs_found' => $total_count,
            'memory_usage_mb' => memory_get_usage(true) / (1024 * 1024),
            'chunks_loaded' => ceil($total_count / 1000)
        ]);

        set_cleanup_drafted_progress([
            'total_processed' => 0,
            'total_deleted' => 0,
            'total_jobs' => $total_count,
            'draft_job_ids' => $draft_job_ids,
            'current_index' => 0,
            'complete' => false,
            'start_time' => microtime(true),
            'logs' => [],
            'memory_peak_start' => memory_get_peak_usage(true)
        ]);
    }

    $progress = get_cleanup_drafted_progress();

    // MEMORY CHECK: Adjust batch size based on available memory
    $memory_limit = get_memory_limit_bytes();
    $current_memory = memory_get_usage(true);
    $available_memory = $memory_limit - $current_memory;
    $memory_ratio = $current_memory / $memory_limit;

    // Reduce batch size if memory usage is high
    if ($memory_ratio > 0.7) {
        $batch_size = max(5, $batch_size / 2);
        PuntWorkLogger::warning('Reducing batch size due to high memory usage in drafted cleanup', PuntWorkLogger::CONTEXT_BATCH, [
            'original_batch_size' => isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 50,
            'adjusted_batch_size' => $batch_size,
            'memory_ratio' => $memory_ratio,
            'available_memory_mb' => $available_memory / (1024 * 1024)
        ]);
    }

    // If already completed, return completion response
    if ($progress['complete']) {
        $message = "Cleanup completed: Processed {$progress['total_processed']} jobs, deleted {$progress['total_deleted']} drafted jobs";
        
        wp_send_json_success([
            'message' => $message,
            'complete' => false,
            'status' => 'completed',
            'processed' => $progress['total_processed'],
            'deleted' => $progress['total_deleted'],
            'total' => $progress['total_jobs'],
            'offset' => $progress['current_index'],
            'progress_percentage' => 100,
            'time_elapsed' => $progress['time_elapsed'] ?? 0,
            'logs' => array_slice($progress['logs'], -50)
        ]);
        return;
    }

    $draft_job_ids = $progress['draft_job_ids'] ?? [];
    $current_index = $progress['current_index'] ?? 0;

    try {
        // Process batch of drafted jobs from collected IDs
        $batch_posts = array_slice($draft_job_ids, $current_index, $batch_size);

        if (empty($batch_posts)) {
            // No more jobs to process
            $progress['complete'] = true;
            $progress['end_time'] = microtime(true);
            $progress['time_elapsed'] = $progress['end_time'] - $progress['start_time'];
            set_cleanup_drafted_progress($progress);

            $message = "Cleanup completed: Processed {$progress['total_processed']} jobs, deleted {$progress['total_deleted']} drafted jobs";

            // Verify final count
            $final_count = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$wpdb->posts}
                WHERE post_type = 'job' AND post_status = 'draft'
            "));

            PuntWorkLogger::info('Cleanup of drafted jobs completed', PuntWorkLogger::CONTEXT_BATCH, [
                'total_processed' => $progress['total_processed'],
                'total_deleted' => $progress['total_deleted'],
                'final_draft_count' => $final_count,
                'expected_remaining' => $progress['total_jobs'] - $progress['total_deleted']
            ]);

            wp_send_json_success([
                'message' => $message,
                'complete' => false,
                'status' => 'completed',
                'processed' => $progress['total_processed'],
                'deleted' => $progress['total_deleted'],
                'total' => $progress['total_jobs'],
                'offset' => $progress['current_index'],
                'progress_percentage' => 100,
                'time_elapsed' => $progress['time_elapsed'],
                'logs' => array_slice($progress['logs'], -50)
            ]);
        }

        // Get post details for this batch
        $placeholders = implode(',', array_fill(0, count($batch_posts), '%d'));
        $posts_details = $wpdb->get_results($wpdb->prepare("
            SELECT ID, post_title FROM {$wpdb->posts}
            WHERE ID IN ({$placeholders})
        ", $batch_posts));

        // Process this batch with memory monitoring
        $deleted_count = 0;
        $logs = $progress['logs'];
        $batch_memory_start = memory_get_usage(true);

        foreach ($posts_details as $post) {
            // MEMORY CHECK: Monitor memory usage during processing
            $current_memory = memory_get_usage(true);
            $current_ratio = $current_memory / $memory_limit;

            if ($current_ratio > 0.85) {
                PuntWorkLogger::warning('High memory usage detected during drafted cleanup processing', PuntWorkLogger::CONTEXT_BATCH, [
                    'current_memory_ratio' => $current_ratio,
                    'post_id' => $post->ID,
                    'processed_in_batch' => $deleted_count
                ]);

                // Force garbage collection and continue
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }

            // Check if post still exists before deletion
            $post_exists_before = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $post->ID));

            // Check if post is locked
            $locked = wp_check_post_lock($post->ID);
            if ($locked) {
                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Skipped locked draft job: ' . $post->post_title . ' (ID: ' . $post->ID . ', locked by user: ' . $locked . ')';
                continue;
            }

            $result = wp_delete_post($post->ID, true); // true = force delete, skip trash

            // Verify deletion
            $post_exists_after = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $post->ID));

            if ($result && !$post_exists_after) {
                $deleted_count++;
                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Successfully deleted draft job: ' . $post->post_title . ' (ID: ' . $post->ID . ')';
            } else {
                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Failed to delete draft job: ' . $post->post_title . ' (ID: ' . $post->ID . ') - wp_delete_post returned: ' . ($result ? 'true' : 'false') . ', still exists: ' . ($post_exists_after ? 'yes' : 'no');
                PuntWorkLogger::error('Post deletion failed', PuntWorkLogger::CONTEXT_BATCH, [
                    'post_id' => $post->ID,
                    'post_title' => $post->post_title,
                    'wp_delete_result' => $result ? 'true' : 'false',
                    'existed_before' => $post_exists_before ? 'yes' : 'no',
                    'exists_after' => $post_exists_after ? 'yes' : 'no'
                ]);
            }

            // MEMORY CLEANUP: Aggressive cleanup every 3 deletions
            if ($deleted_count % 3 === 0) {
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
                // Clear any cached WordPress object caches that might be holding memory
                if (function_exists('wp_cache_flush')) {
                    wp_cache_flush();
                }
            }
        }

        $batch_memory_end = memory_get_usage(true);
        $batch_memory_used = $batch_memory_end - $batch_memory_start;

        // Update progress
        $progress['total_processed'] += count($posts_details);
        $progress['total_deleted'] += $deleted_count;
        $progress['current_index'] = $current_index + $batch_size;
        $progress['logs'] = $logs;
        set_cleanup_drafted_progress($progress);

        // Calculate progress percentage
        $progress_percentage = $progress['total_jobs'] > 0 ? round(($progress['total_processed'] / $progress['total_jobs']) * 100, 1) : 0;

        PuntWorkLogger::debug('Sending batch progress update', PuntWorkLogger::CONTEXT_BATCH, [
            'processed' => $progress['total_processed'],
            'total' => $progress['total_jobs'],
            'deleted' => $progress['total_deleted'],
            'offset' => $progress['current_index'],
            'progress_percentage' => $progress_percentage,
            'batch_size' => $batch_size
        ]);

        wp_send_json_success([
            'message' => "Batch processed: {$progress['total_processed']}/{$progress['total_jobs']} jobs ({$progress_percentage}%), deleted {$deleted_count} drafted jobs this batch",
            'complete' => false,
            'offset' => $progress['current_index'], // Changed from next_offset for frontend compatibility
            'batch_size' => $batch_size,
            'processed' => $progress['total_processed'],
            'deleted' => $progress['total_deleted'],
            'total' => $progress['total_jobs'],
            'progress_percentage' => $progress_percentage,
            'logs' => array_slice($logs, -20) // Return last 20 log entries for this batch
        ]);

    } catch (\Exception $e) {
        PuntWorkLogger::error('Cleanup of drafted jobs failed', PuntWorkLogger::CONTEXT_BATCH, [
            'error' => $e->getMessage()
        ]);
        wp_send_json_error(['message' => 'Cleanup failed: ' . $e->getMessage()]);
    }
}

add_action('wp_ajax_cleanup_old_published_jobs', __NAMESPACE__ . '\\cleanup_old_published_jobs_ajax');
function cleanup_old_published_jobs_ajax() {
    if (!validate_ajax_request('cleanup_old_published_jobs')) {
        return;
    }

    global $wpdb;

    // Get batch parameters
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 50;
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $is_continue = isset($_POST['is_continue']) && $_POST['is_continue'] === 'true';

    // Initialize progress tracking for first batch
    if (!$is_continue) {
        // Check if combined-jobs.jsonl exists
        $json_path = PUNTWORK_PATH . 'feeds/combined-jobs.jsonl';
        if (!file_exists($json_path)) {
            wp_send_json_error(['message' => 'No current feed data found. Please run an import first to generate feed data.']);
        }

        // MEMORY-SAFE: Get current GUIDs in chunks to avoid loading all into memory
        $current_guids = [];
        $guid_chunk_size = 500; // Process GUIDs in chunks of 500
        $guid_offset = 0;

        PuntWorkLogger::info('Starting memory-safe GUID collection for old published cleanup', PuntWorkLogger::CONTEXT_BATCH, [
            'json_path' => $json_path,
            'chunk_size' => $guid_chunk_size
        ]);

        while (true) {
            $chunk_guids = [];
            if (($handle = fopen($json_path, "r")) !== false) {
                $current_index = 0;
                $guids_in_chunk = 0;

                while (($line = fgets($handle)) !== false) {
                    if ($current_index >= $guid_offset && $guids_in_chunk < $guid_chunk_size) {
                        $line = trim($line);
                        if (!empty($line)) {
                            $item = json_decode($line, true);
                            if ($item !== null && isset($item['guid'])) {
                                $chunk_guids[] = $item['guid'];
                                $guids_in_chunk++;
                            }
                        }
                    } elseif ($guids_in_chunk >= $guid_chunk_size) {
                        break;
                    }
                    $current_index++;
                }
                fclose($handle);
            }

            if (empty($chunk_guids)) {
                break;
            }

            $current_guids = array_merge($current_guids, $chunk_guids);
            $guid_offset += $guid_chunk_size;

            // MEMORY CHECK: Prevent excessive memory usage during GUID collection
            $current_memory = memory_get_usage(true);
            $memory_ratio = $current_memory / get_memory_limit_bytes();

            if ($memory_ratio > 0.6) {
                PuntWorkLogger::warning('High memory usage during GUID collection, reducing chunk size', PuntWorkLogger::CONTEXT_BATCH, [
                    'collected_guids' => count($current_guids),
                    'memory_ratio' => $memory_ratio,
                    'chunk_size_reduced' => true
                ]);
                $guid_chunk_size = max(100, $guid_chunk_size / 2);
            }

            // Force cleanup between chunks
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        if (empty($current_guids)) {
            wp_send_json_error(['message' => 'No valid job data found in current feeds.']);
        }

        // MEMORY-SAFE: Get total count using chunked GUID processing
        $total_count = 0;
        $guid_chunks = array_chunk($current_guids, 1000); // Process in chunks of 1000 for SQL IN() clauses

        foreach ($guid_chunks as $guid_chunk) {
            $placeholders = implode(',', array_fill(0, count($guid_chunk), '%s'));
            $chunk_count = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*)
                FROM {$wpdb->posts} p
                JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'guid'
                WHERE p.post_type = 'job'
                AND p.post_status = 'publish'
                AND pm.meta_value NOT IN ({$placeholders})
            ", $guid_chunk));

            $total_count += $chunk_count;

            // Memory cleanup between chunks
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        PuntWorkLogger::info('Old published cleanup initialized with memory-safe processing', PuntWorkLogger::CONTEXT_BATCH, [
            'current_guids_count' => count($current_guids),
            'total_old_jobs_found' => $total_count,
            'guid_chunks_processed' => count($guid_chunks),
            'memory_usage_mb' => memory_get_usage(true) / (1024 * 1024)
        ]);

        // Store GUIDs in option for batch processing (in chunks to save memory)
        $guid_chunks_stored = [];
        foreach (array_chunk($current_guids, 2000) as $i => $chunk) {
            $guid_chunks_stored["chunk_$i"] = $chunk;
        }

        set_cleanup_guids($guid_chunks_stored);
        set_cleanup_old_published_progress([
            'total_processed' => 0,
            'total_deleted' => 0,
            'total_jobs' => $total_count,
            'current_offset' => 0,
            'complete' => false,
            'start_time' => microtime(true),
            'logs' => [],
            'memory_peak_start' => memory_get_peak_usage(true)
        ]);
    }

    $progress = get_cleanup_old_published_progress();

    // MEMORY CHECK: Adjust batch size based on available memory
    $memory_limit = get_memory_limit_bytes();
    $current_memory = memory_get_usage(true);
    $available_memory = $memory_limit - $current_memory;
    $memory_ratio = $current_memory / $memory_limit;

    // Reduce batch size if memory usage is high
    if ($memory_ratio > 0.7) {
        $batch_size = max(5, $batch_size / 2);
        PuntWorkLogger::warning('Reducing batch size due to high memory usage in old published cleanup', PuntWorkLogger::CONTEXT_BATCH, [
            'original_batch_size' => isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 50,
            'adjusted_batch_size' => $batch_size,
            'memory_ratio' => $memory_ratio,
            'available_memory_mb' => $available_memory / (1024 * 1024)
        ]);
    }

    // If already completed, return completion response
    if ($progress['complete']) {
        $message = "Cleanup completed: Processed {$progress['total_processed']} jobs, deleted {$progress['total_deleted']} old published jobs";
        
        wp_send_json_success([
            'message' => $message,
            'complete' => false,
            'status' => 'completed',
            'processed' => $progress['total_processed'],
            'deleted' => $progress['total_deleted'],
            'total' => $progress['total_jobs'],
            'offset' => $progress['current_offset'],
            'progress_percentage' => 100,
            'time_elapsed' => $progress['time_elapsed'] ?? 0,
            'logs' => array_slice($progress['logs'], -50)
        ]);
        return;
    }

    $current_guids_chunked = get_cleanup_guids();

    try {
        // MEMORY-SAFE: Get batch of old published jobs using chunked GUID processing
        $old_published_posts = [];

        // Process GUIDs in chunks to avoid memory issues with large IN() clauses
        foreach ($current_guids_chunked as $chunk_key => $guid_chunk) {
            if (empty($guid_chunk)) continue;

            $placeholders = implode(',', array_fill(0, count($guid_chunk), '%s'));
            $chunk_posts = $wpdb->get_results($wpdb->prepare("
                SELECT p.ID, p.post_title, pm.meta_value as guid
                FROM {$wpdb->posts} p
                JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'guid'
                WHERE p.post_type = 'job'
                AND p.post_status = 'publish'
                AND pm.meta_value NOT IN ({$placeholders})
                ORDER BY p.ID
                LIMIT %d OFFSET %d
            ", array_merge($guid_chunk, [$batch_size, $offset])));

            $old_published_posts = array_merge($old_published_posts, $chunk_posts);

            // Limit total results to batch_size to prevent memory issues
            if (count($old_published_posts) >= $batch_size) {
                $old_published_posts = array_slice($old_published_posts, 0, $batch_size);
                break;
            }

            // Memory cleanup between chunks
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        if (empty($old_published_posts)) {
            // No more jobs to process
            $progress['complete'] = true;
            $progress['end_time'] = microtime(true);
            $progress['time_elapsed'] = $progress['end_time'] - $progress['start_time'];
            set_cleanup_old_published_progress($progress);

            // Clean up temporary options
            delete_option('job_cleanup_guids');

            $message = "Cleanup completed: Processed {$progress['total_processed']} jobs, deleted {$progress['total_deleted']} old published jobs";
            PuntWorkLogger::info('Cleanup of old published jobs completed', PuntWorkLogger::CONTEXT_BATCH, [
                'total_processed' => $progress['total_processed'],
                'total_deleted' => $progress['total_deleted'],
                'current_feed_jobs' => count($current_guids)
            ]);

            wp_send_json_success([
                'message' => $message,
                'complete' => false,
                'status' => 'completed',
                'processed' => $progress['total_processed'],
                'deleted' => $progress['total_deleted'],
                'total' => $progress['total_jobs'],
                'offset' => $progress['current_offset'],
                'progress_percentage' => 100,
                'time_elapsed' => $progress['time_elapsed'],
                'logs' => array_slice($progress['logs'], -50)
            ]);
        }

        // Process this batch with memory monitoring
        $deleted_count = 0;
        $logs = $progress['logs'];
        $batch_memory_start = memory_get_usage(true);

        foreach ($old_published_posts as $post) {
            // MEMORY CHECK: Monitor memory usage during processing
            $current_memory = memory_get_usage(true);
            $current_ratio = $current_memory / $memory_limit;

            if ($current_ratio > 0.85) {
                PuntWorkLogger::warning('High memory usage detected during old published cleanup processing', PuntWorkLogger::CONTEXT_BATCH, [
                    'current_memory_ratio' => $current_ratio,
                    'post_id' => $post->ID,
                    'processed_in_batch' => $deleted_count
                ]);

                // Force garbage collection and continue
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }

            $result = wp_delete_post($post->ID, true); // true = force delete, skip trash
            if ($result) {
                $deleted_count++;
                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Permanently deleted old published job: ' . $post->post_title . ' (ID: ' . $post->ID . ', GUID: ' . $post->guid . ')';
            } else {
                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Failed to delete old published job: ' . $post->post_title . ' (ID: ' . $post->ID . ', GUID: ' . $post->guid . ')';
            }

            // MEMORY CLEANUP: Aggressive cleanup every 3 deletions
            if ($deleted_count % 3 === 0) {
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
                // Clear any cached WordPress object caches that might be holding memory
                if (function_exists('wp_cache_flush')) {
                    wp_cache_flush();
                }
            }
        }

        $batch_memory_end = memory_get_usage(true);
        $batch_memory_used = $batch_memory_end - $batch_memory_start;

        // Update progress
        $progress['total_processed'] += count($old_published_posts);
        $progress['total_deleted'] += $deleted_count;
        $progress['current_offset'] = $offset + $batch_size;
        $progress['logs'] = $logs;
        set_cleanup_old_published_progress($progress);

        // Calculate progress percentage
        $progress_percentage = $progress['total_jobs'] > 0 ? round(($progress['total_processed'] / $progress['total_jobs']) * 100, 1) : 0;

        PuntWorkLogger::debug('Sending batch progress update for old published jobs', PuntWorkLogger::CONTEXT_BATCH, [
            'processed' => $progress['total_processed'],
            'total' => $progress['total_jobs'],
            'deleted' => $progress['total_deleted'],
            'offset' => $progress['current_offset'],
            'progress_percentage' => $progress_percentage,
            'batch_size' => $batch_size
        ]);

        wp_send_json_success([
            'message' => "Batch processed: {$progress['total_processed']}/{$progress['total_jobs']} jobs ({$progress_percentage}%), deleted {$deleted_count} old published jobs this batch",
            'complete' => false,
            'offset' => $progress['current_offset'],
            'batch_size' => $batch_size,
            'processed' => $progress['total_processed'],
            'deleted' => $progress['total_deleted'],
            'total' => $progress['total_jobs'],
            'progress_percentage' => $progress_percentage,
            'logs' => array_slice($logs, -20) // Return last 20 log entries for this batch
        ]);

    } catch (\Exception $e) {
        PuntWorkLogger::error('Cleanup of old published jobs failed', PuntWorkLogger::CONTEXT_BATCH, [
            'error' => $e->getMessage()
        ]);
        wp_send_json_error(['message' => 'Cleanup failed: ' . $e->getMessage()]);
    }
}

add_action('wp_ajax_manually_resume_stuck_import', __NAMESPACE__ . '\\manually_resume_stuck_import_ajax');
function manually_resume_stuck_import_ajax() {
    if (!validate_ajax_request('manually_resume_stuck_import')) {
        return;
    }

    try {
        PuntWorkLogger::info('Manual stuck import resume initiated via AJAX', PuntWorkLogger::CONTEXT_AJAX);

        // Call the manual resume function
        $result = manually_resume_stuck_import();

        if ($result['success']) {
            PuntWorkLogger::info('Manual stuck import resume completed successfully', PuntWorkLogger::CONTEXT_AJAX, [
                'result' => $result
            ]);
            send_ajax_success('manually_resume_stuck_import', [
                'message' => $result['message'],
                'result' => $result['result'] ?? null
            ]);
        } else {
            PuntWorkLogger::error('Manual stuck import resume failed', PuntWorkLogger::CONTEXT_AJAX, [
                'error_message' => $result['message'],
                'result' => $result
            ]);
            send_ajax_error('manually_resume_stuck_import', $result['message']);
        }

    } catch (\Exception $e) {
        PuntWorkLogger::error('Manual stuck import resume AJAX failed', PuntWorkLogger::CONTEXT_AJAX, [
            'error_message' => $e->getMessage(),
            'error_file' => $e->getFile(),
            'error_line' => $e->getLine()
        ]);
        send_ajax_error('manually_resume_stuck_import', 'Failed to resume stuck import: ' . $e->getMessage());
    }
}

add_action('wp_ajax_continue_paused_import_ajax', __NAMESPACE__ . '\\continue_paused_import_ajax');
function continue_paused_import_ajax() {
    if (!validate_ajax_request('continue_paused_import_ajax')) {
        return;
    }

    try {
        PuntWorkLogger::info('AJAX continuation of paused import initiated', PuntWorkLogger::CONTEXT_AJAX);

        // Check current status
        $status = get_import_status([]);
        if (isset($status['complete']) && $status['complete']) {
            PuntWorkLogger::info('AJAX continuation skipped - import already completed', PuntWorkLogger::CONTEXT_AJAX);
            send_ajax_success('continue_paused_import_ajax', [
                'message' => 'Import already completed',
                'already_complete' => true
            ]);
            return;
        }

        if (!isset($status['paused']) || !$status['paused']) {
            PuntWorkLogger::info('AJAX continuation skipped - import not paused', PuntWorkLogger::CONTEXT_AJAX);
            send_ajax_success('continue_paused_import_ajax', [
                'message' => 'Import not paused',
                'not_paused' => true
            ]);
            return;
        }

        // Check for cancellation
        if (get_transient('import_cancel') === true || get_transient('import_force_cancel') === true || get_transient('import_emergency_stop') === true) {
            $cancel_type = get_transient('import_emergency_stop') === true ? 'emergency stopped' :
                          (get_transient('import_force_cancel') === true ? 'force cancelled' : 'cancelled');
            PuntWorkLogger::info('AJAX continuation cancelled', PuntWorkLogger::CONTEXT_AJAX, [
                'reason' => 'import_cancel_transient_set',
                'cancel_type' => $cancel_type
            ]);
            send_ajax_error('continue_paused_import_ajax', 'Import was ' . $cancel_type . ' by user');
            return;
        }

        // Update continuation attempt tracking
        $status['continuation_attempts'] = ($status['continuation_attempts'] ?? 0) + 1;
        $status['last_continuation_attempt'] = microtime(true);
        $status['continuation_method'] = 'ajax_fallback';
        set_import_status($status);

        PuntWorkLogger::info('AJAX continuation attempt initiated', PuntWorkLogger::CONTEXT_AJAX, [
            'attempt_number' => $status['continuation_attempts'],
            'pause_time' => $status['pause_time'] ?? null,
            'time_since_pause' => $status['pause_time'] ? (microtime(true) - $status['pause_time']) : null,
            'method' => 'ajax_fallback'
        ]);

        // Reset pause status
        $status['paused'] = false;
        unset($status['pause_reason']);
        if (!is_array($status['logs'] ?? null)) {
            $status['logs'] = [];
        }
        $status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Resuming paused import (AJAX fallback continuation)';
        set_import_status($status);

        // Continue the import
        $result = import_all_jobs_from_json(true); // preserve status for continuation

        if ($result['success']) {
            PuntWorkLogger::info('AJAX continuation completed successfully', PuntWorkLogger::CONTEXT_AJAX, [
                'processed' => $result['processed'] ?? 0,
                'total' => $result['total'] ?? 0,
                'time_elapsed' => $result['time_elapsed'] ?? 0,
                'attempts_used' => $status['continuation_attempts'] ?? 1
            ]);
            send_ajax_success('continue_paused_import_ajax', [
                'message' => 'Import resumed and completed successfully',
                'result' => $result
            ]);
        } else {
            PuntWorkLogger::error('AJAX continuation failed', PuntWorkLogger::CONTEXT_AJAX, [
                'error' => $result['message'] ?? 'Unknown error',
                'processed' => $result['processed'] ?? 0,
                'total' => $result['total'] ?? 0,
                'attempts_used' => $status['continuation_attempts'] ?? 1
            ]);
            send_ajax_error('continue_paused_import_ajax', 'Import continuation failed: ' . ($result['message'] ?? 'Unknown error'));
        }

    } catch (\Exception $e) {
        PuntWorkLogger::error('AJAX continuation failed with exception', PuntWorkLogger::CONTEXT_AJAX, [
            'error_message' => $e->getMessage(),
            'error_file' => $e->getFile(),
            'error_line' => $e->getLine()
        ]);
        send_ajax_error('continue_paused_import_ajax', 'Failed to continue import: ' . $e->getMessage());
    }
}

/**
 * WordPress Heartbeat handler for real-time import status updates
 * Responds to heartbeat requests with current import status data
 */
add_action('heartbeat_received', __NAMESPACE__ . '\\puntwork_heartbeat_handler', 10, 2);
function puntwork_heartbeat_handler($response, $data) {
    try {
        // Check if client requested import status updates
        if (isset($data['puntwork_import_status'])) {
            $response['puntwork_import_update'] = [
                'status' => get_import_status(),
                'is_active' => false, // Will be set below
                'timestamp' => microtime(true)
            ];

            // Determine if import is active
            $status = $response['puntwork_import_update']['status'];
            $is_complete = $status['complete'] ?? false;
            $is_running = !$is_complete &&
                         (isset($status['start_time']) || ($status['processed'] ?? 0) > 0);

            $response['puntwork_import_update']['is_active'] = $is_running;

            // Only log when there's meaningful activity to avoid spam
            if ($is_running || $is_complete) {
                PuntWorkLogger::debug('Heartbeat import status update sent', PuntWorkLogger::CONTEXT_AJAX, [
                    'processed' => $status['processed'] ?? 0,
                    'total' => $status['total'] ?? 0,
                    'complete' => $is_complete,
                    'is_active' => $is_running,
                    'timestamp' => $response['puntwork_import_update']['timestamp']
                ]);
            }
        }

        // Handle scheduled imports status if requested
        if (isset($data['puntwork_scheduled_imports'])) {
            $active_imports = check_active_scheduled_imports();
            $schedule_enabled = get_option('puntwork_import_schedule', ['enabled' => false]);
            $schedule_enabled = $schedule_enabled['enabled'] ?? false;
            $schedule_frequency = get_option('puntwork_import_schedule', ['frequency' => 'daily']);
            $schedule_frequency = $schedule_frequency['frequency'] ?? 'daily';

            $response['puntwork_scheduled_imports'] = [
                'data' => [
                    'schedule_enabled' => $schedule_enabled,
                    'schedule_frequency' => $schedule_frequency,
                    'active_imports' => $active_imports,
                    'has_active_imports' => !empty($active_imports)
                ],
                'has_changes' => true, // Always send for now, could be optimized with change detection
                'timestamp' => microtime(true)
            ];
        }

    } catch (\Exception $e) {
        PuntWorkLogger::error('Heartbeat handler error', PuntWorkLogger::CONTEXT_AJAX, [
            'error_message' => $e->getMessage(),
            'error_file' => $e->getFile(),
            'error_line' => $e->getLine()
        ]);

        // Send error response in heartbeat
        $response['puntwork_heartbeat_error'] = [
            'message' => 'Heartbeat processing failed',
            'timestamp' => microtime(true)
        ];
    }

    return $response;
}

/**
 * POISON PILL: Aggressively cancel all import-related background processes
 * This function implements a comprehensive cancellation system that interrupts
 * running imports at multiple levels for immediate termination.
 *
 * @return int Number of processes cancelled
 */
function cancel_all_import_processes() {
    $cancelled_count = 0;

    PuntWorkLogger::info('Deploying POISON PILL - Aggressive import cancellation initiated', PuntWorkLogger::CONTEXT_BATCH, [
        'timestamp' => microtime(true),
        'method' => 'comprehensive_cancellation'
    ]);

    try {
        // 1. CANCEL WORDPRESS CRON JOBS
        // Clear any scheduled import continuations
        $cron_cancelled = 0;
        if (wp_next_scheduled('puntwork_continue_import')) {
            wp_clear_scheduled_hook('puntwork_continue_import');
            $cron_cancelled++;
            PuntWorkLogger::info('WordPress cron continuation job cancelled', PuntWorkLogger::CONTEXT_BATCH);
        }

        if (wp_next_scheduled('puntwork_manual_import_async')) {
            wp_clear_scheduled_hook('puntwork_manual_import_async');
            $cron_cancelled++;
            PuntWorkLogger::info('WordPress cron manual async job cancelled', PuntWorkLogger::CONTEXT_BATCH);
        }

        if (wp_next_scheduled('puntwork_scheduled_import')) {
            wp_clear_scheduled_hook('puntwork_scheduled_import');
            $cron_cancelled++;
            PuntWorkLogger::info('WordPress cron scheduled import job cancelled', PuntWorkLogger::CONTEXT_BATCH);
        }

        $cancelled_count += $cron_cancelled;

        // 2. CANCEL ACTION SCHEDULER JOBS (if available)
        $as_cancelled = 0;
        if (function_exists('as_unschedule_all_actions')) {
            // Cancel all import-related Action Scheduler jobs
            $import_hooks = [
                'puntwork_process_single_item',
                'puntwork_manual_import_async',
                'puntwork_scheduled_import_async',
                'puntwork_continue_import'
            ];

            foreach ($import_hooks as $hook) {
                try {
                    $unscheduled = as_unschedule_all_actions($hook);
                    if ($unscheduled > 0) {
                        $as_cancelled += $unscheduled;
                        PuntWorkLogger::info('Action Scheduler jobs cancelled', PuntWorkLogger::CONTEXT_BATCH, [
                            'hook' => $hook,
                            'jobs_cancelled' => $unscheduled
                        ]);
                    }
                } catch (\Exception $e) {
                    PuntWorkLogger::warning('Failed to cancel Action Scheduler jobs for hook', PuntWorkLogger::CONTEXT_BATCH, [
                        'hook' => $hook,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        } else {
            PuntWorkLogger::debug('Action Scheduler not available for job cancellation', PuntWorkLogger::CONTEXT_BATCH);
        }

        $cancelled_count += $as_cancelled;

        // 3. CANCEL RUNNING PROCESSES VIA DATABASE SIGNALS
        // Set multiple cancellation flags for maximum coverage
        $db_flags_set = 0;
        $cancellation_flags = [
            'import_cancel' => true,           // Main cancellation flag
            'import_force_cancel' => true,     // Force cancellation flag
            'import_emergency_stop' => true,   // Emergency stop flag
        ];

        foreach ($cancellation_flags as $flag => $value) {
            set_transient($flag, $value, 3600); // 1 hour expiry
            $db_flags_set++;
        }

        // 4. CLEAR IMPORT PROGRESS AND FORCE RESET
        $options_cleared = 0;
        $import_options = [
            'job_import_progress',
            'job_import_processed_guids',
            'job_import_last_batch_time',
            'job_import_last_batch_processed',
            'job_import_batch_size',
            'job_import_consecutive_small_batches',
            'job_import_consecutive_batches',
            'job_import_start_time',
            'puntwork_last_import_run',
            'puntwork_last_import_details'
        ];

        foreach ($import_options as $option) {
            if (delete_option($option)) {
                $options_cleared++;
            }
        }

        // 5. SEND KILL SIGNAL TO RUNNING PROCESSES (if possible)
        // This is a more aggressive approach for processes that might be stuck
        $kill_signals_sent = 0;
        if (function_exists('posix_kill') && function_exists('getmypid')) {
            // Try to find and kill any child processes (limited effectiveness in web context)
            PuntWorkLogger::debug('POSIX signals available but limited in web context', PuntWorkLogger::CONTEXT_BATCH);
        }

        // 5.5. FORCE IMMEDIATE STATUS RESET (NUCLEAR OPTION)
        // This completely wipes the import state to force any running process to stop
        $nuclear_reset = [
            'job_import_status' => [],
            'job_import_progress' => 0,
            'job_import_processed_guids' => [],
            'job_import_last_batch_time' => 0,
            'job_import_last_batch_processed' => 0,
            'job_import_batch_size' => get_batch_size(), // Keep batch size
            'job_import_consecutive_small_batches' => 0,
            'job_import_consecutive_batches' => 0,
            'job_import_start_time' => 0,
        ];

        foreach ($nuclear_reset as $option => $value) {
            update_option($option, $value, false); // Force immediate update
        }

        PuntWorkLogger::info('NUCLEAR RESET executed - Import state completely wiped', PuntWorkLogger::CONTEXT_BATCH, [
            'nuclear_options_reset' => array_keys($nuclear_reset),
            'method' => 'complete_state_wipe'
        ]);

        // 6. FORCE GARBAGE COLLECTION AND MEMORY CLEANUP
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
            PuntWorkLogger::debug('Garbage collection forced during cancellation', PuntWorkLogger::CONTEXT_BATCH);
        }

        // 7. LOG COMPREHENSIVE CANCELLATION REPORT
        PuntWorkLogger::info('POISON PILL DEPLOYMENT COMPLETE - Import processes terminated', PuntWorkLogger::CONTEXT_BATCH, [
            'cron_jobs_cancelled' => $cron_cancelled,
            'action_scheduler_jobs_cancelled' => $as_cancelled,
            'database_flags_set' => $db_flags_set,
            'options_cleared' => $options_cleared,
            'kill_signals_sent' => $kill_signals_sent,
            'total_processes_cancelled' => $cancelled_count,
            'cancellation_timestamp' => microtime(true),
        ]);

    } catch (\Exception $e) {
        PuntWorkLogger::error('CRITICAL ERROR during import cancellation', PuntWorkLogger::CONTEXT_BATCH, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'method' => 'cancel_all_import_processes'
        ]);
        throw $e; // Re-throw to let caller handle
    }
}