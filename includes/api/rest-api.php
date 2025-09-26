<?php
/**
 * REST API endpoints for remote import triggering
 *
 * @package    Puntwork
 * @subpackage API
 * @since      1.0.7
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST API handlers for remote import triggering
 */

/**
 * Register REST API routes
 */
add_action('rest_api_init', __NAMESPACE__ . '\\register_import_api_routes');
function register_import_api_routes() {
    register_rest_route('puntwork/v1', '/trigger-import', [
        'methods' => 'POST',
        'callback' => __NAMESPACE__ . '\\handle_trigger_import',
        'permission_callback' => __NAMESPACE__ . '\\verify_api_key',
        'args' => [
            'api_key' => [
                'required' => true,
                'type' => 'string',
                'description' => 'API key for authentication',
            ],
            'force' => [
                'required' => false,
                'type' => 'boolean',
                'default' => false,
                'description' => 'Force import even if one is already running',
            ],
            'test_mode' => [
                'required' => false,
                'type' => 'boolean',
                'default' => false,
                'description' => 'Run in test mode (no actual posts created)',
            ],
        ],
    ]);

    register_rest_route('puntwork/v1', '/import-status', [
        'methods' => 'GET',
        'callback' => __NAMESPACE__ . '\\handle_get_import_status',
        'permission_callback' => __NAMESPACE__ . '\\verify_api_key',
        'args' => [
            'api_key' => [
                'required' => true,
                'type' => 'string',
                'description' => 'API key for authentication',
            ],
        ],
    ]);
}

/**
 * Verify API key for authentication
 */
function verify_api_key($request) {
    $api_key = $request->get_param('api_key');

    if (empty($api_key)) {
        return new \WP_Error('missing_api_key', 'API key is required', ['status' => 401]);
    }

    $stored_key = get_option('puntwork_api_key');

    if (empty($stored_key)) {
        return new \WP_Error('api_not_configured', 'API key not configured', ['status' => 403]);
    }

    if (!hash_equals($stored_key, $api_key)) {
        return new \WP_Error('invalid_api_key', 'Invalid API key', ['status' => 401]);
    }

    return true;
}

/**
 * Handle trigger import request
 */
function handle_trigger_import($request) {
    $force = $request->get_param('force');
    $test_mode = $request->get_param('test_mode');

    PuntWorkLogger::info('Remote import trigger requested', PuntWorkLogger::CONTEXT_API, [
        'force' => $force,
        'test_mode' => $test_mode,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);

    // Check if import is already running
    $import_status = get_option('job_import_status', []);
    $is_running = isset($import_status['complete']) && !$import_status['complete'] && !isset($import_status['paused']);

    if ($is_running && !$force) {
        PuntWorkLogger::info('Import already running, skipping trigger', PuntWorkLogger::CONTEXT_API);
        return new \WP_REST_Response([
            'success' => false,
            'message' => 'Import already running. Use force=true to override.',
            'status' => 'running',
            'current_progress' => $import_status
        ], 409);
    }

    // If forcing, cancel current import
    if ($is_running && $force) {
        set_transient('import_cancel', true, 3600);
        delete_option('job_import_status');
        delete_option('job_import_progress');
        delete_option('job_import_processed_guids');
        delete_option('job_import_last_batch_time');
        delete_option('job_import_last_batch_processed');
        delete_option('job_import_batch_size');
        delete_option('job_import_consecutive_small_batches');
        PuntWorkLogger::info('Cancelled existing import for forced trigger', PuntWorkLogger::CONTEXT_API);
        sleep(2); // Brief pause to allow cleanup
    }

    try {
        // Set test mode if requested
        if ($test_mode) {
            update_option('puntwork_test_mode', true);
        }

        // Get total items count for proper status initialization
        $json_path = ABSPATH . 'feeds/combined-jobs.jsonl';
        $total_items = 0;
        if (file_exists($json_path)) {
            $total_items = get_json_item_count($json_path);
        }

        // Initialize import status for immediate API response
        $initial_status = [
            'total' => $total_items, // Set correct total from the start
            'processed' => 0,
            'published' => 0,
            'updated' => 0,
            'skipped' => 0,
            'duplicates_drafted' => 0,
            'time_elapsed' => 0,
            'success' => false,
            'error_message' => '',
            'batch_size' => get_option('job_import_batch_size') ?: 100,
            'inferred_languages' => 0,
            'inferred_benefits' => 0,
            'schema_generated' => 0,
            'start_time' => microtime(true),
            'end_time' => null,
            'last_update' => time(),
            'logs' => ['API import started - preparing feeds...'],
            'trigger_type' => 'api',
            'test_mode' => $test_mode
        ];
        update_option('job_import_status', $initial_status, false);

        // Reset import progress for fresh API-triggered import
        update_option('job_import_progress', 0, false);
        update_option('job_import_processed_guids', [], false);
        delete_option('job_import_last_batch_time');
        delete_option('job_import_last_batch_processed');
        delete_option('job_import_batch_size');
        delete_option('job_import_consecutive_small_batches');

        // Clear any previous cancellation before starting
        delete_transient('import_cancel');

        // Schedule the import to run asynchronously
        if (false && function_exists('as_schedule_single_action')) { // Temporarily force sync for testing
            // Use Action Scheduler if available
            as_schedule_single_action(time(), 'puntwork_scheduled_import_async');
        } elseif (false && function_exists('wp_schedule_single_event')) { // Temporarily force sync for testing
            // Fallback: Use WordPress cron for near-immediate execution
            wp_schedule_single_event(time() + 1, 'puntwork_scheduled_import_async');
        } else {
            // Force synchronous execution for testing polling mechanism
            error_log('[PUNTWORK] API: Forcing synchronous execution');
            if (!function_exists('run_scheduled_import')) {
                error_log('[PUNTWORK] API: run_scheduled_import function not found');
                return new \WP_REST_Response([
                    'success' => false,
                    'message' => 'Import function not found',
                    'async' => false
                ], 500);
            }
            $result = run_scheduled_import($test_mode, 'api');

            // Clear test mode
            if ($test_mode) {
                delete_option('puntwork_test_mode');
            }

            // Add debug information
            $debug_info = [
                'jsonl_path' => ABSPATH . 'feeds/combined-jobs.jsonl',
                'jsonl_exists' => file_exists(ABSPATH . 'feeds/combined-jobs.jsonl'),
                'jsonl_size' => file_exists(ABSPATH . 'feeds/combined-jobs.jsonl') ? filesize(ABSPATH . 'feeds/combined-jobs.jsonl') : 0,
                'jsonl_readable' => is_readable(ABSPATH . 'feeds/combined-jobs.jsonl'),
                'feeds_count' => count(\Puntwork\get_feeds()),
                'wp_abspath' => ABSPATH,
            ];

            if ($result['success']) {
                PuntWorkLogger::info('Remote import trigger completed successfully (sync)', PuntWorkLogger::CONTEXT_API, [
                    'processed' => $result['processed'] ?? 0,
                    'total' => $result['total'] ?? 0
                ]);

                return new \WP_REST_Response([
                    'success' => true,
                    'message' => 'Import completed successfully',
                    'data' => $result,
                    'debug' => $debug_info,
                    'async' => false
                ], 200);
            } else {
                $error_msg = $result['message'] ?? 'Unknown error occurred';
                PuntWorkLogger::error('Remote import trigger failed (sync)', PuntWorkLogger::CONTEXT_API, [
                    'error' => $error_msg
                ]);

                return new \WP_REST_Response([
                    'success' => false,
                    'message' => $error_msg,
                    'data' => $result,
                    'debug' => $debug_info,
                    'async' => false
                ], 500);
            }
        }

        // Clear test mode (will be set again by async function if needed)
        if ($test_mode) {
            delete_option('puntwork_test_mode');
        }

        PuntWorkLogger::info('Remote import trigger initiated asynchronously', PuntWorkLogger::CONTEXT_API);

        return new \WP_REST_Response([
            'success' => true,
            'message' => 'Import triggered successfully',
            'async' => true
        ], 200);

    } catch (\Exception $e) {
        PuntWorkLogger::error('Remote import trigger exception', PuntWorkLogger::CONTEXT_API, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return new \WP_REST_Response([
            'success' => false,
            'message' => 'Import failed: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Handle get import status request
 */
function handle_get_import_status($request) {
    $progress = get_option('job_import_status') ?: [
        'total' => 0,
        'processed' => 0,
        'published' => 0,
        'updated' => 0,
        'skipped' => 0,
        'duplicates_drafted' => 0,
        'time_elapsed' => 0,
        'complete' => true,
        'success' => false,
        'error_message' => '',
        'batch_size' => 100,
        'inferred_languages' => 0,
        'inferred_benefits' => 0,
        'schema_generated' => 0,
        'start_time' => microtime(true),
        'end_time' => null,
        'last_update' => time(),
        'logs' => [],
    ];

    // Calculate elapsed time
    if (isset($progress['start_time']) && $progress['start_time'] > 0) {
        $current_time = microtime(true);
        $progress['time_elapsed'] = $current_time - $progress['start_time'];
    }

    // Calculate completion status
    if (!isset($progress['complete']) || !$progress['complete']) {
        $progress['complete'] = ($progress['processed'] >= $progress['total'] && $progress['total'] > 0);
    }

    // Add additional status info
    $progress['is_running'] = !$progress['complete'];
    $progress['last_run'] = get_option('puntwork_last_import_run');
    $progress['next_scheduled'] = get_next_scheduled_time();

    PuntWorkLogger::debug('Import status requested via API', PuntWorkLogger::CONTEXT_API);

    return new \WP_REST_Response([
        'success' => true,
        'status' => $progress
    ], 200);
}

/**
 * Generate a new API key
 */
function generate_api_key() {
    return wp_generate_password(32, false);
}

/**
 * Get or create API key
 */
function get_or_create_api_key() {
    $existing_key = get_option('puntwork_api_key');

    if (!$existing_key) {
        $new_key = generate_api_key();
        update_option('puntwork_api_key', $new_key);
        return $new_key;
    }

    return $existing_key;
}

/**
 * Get the total count of items in JSONL file.
 *
 * @param string $json_path Path to JSONL file.
 * @return int Total item count.
 */
function get_json_item_count($json_path) {
    $count = 0;
    if (($handle = fopen($json_path, "r")) !== false) {
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (!empty($line)) {
                $item = json_decode($line, true);
                if ($item !== null) {
                    $count++;
                }
            }
        }
        fclose($handle);
    }
    return $count;
}