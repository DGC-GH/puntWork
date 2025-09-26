<?php
/**
 * Server-Sent Events (SSE) for real-time import progress updates
 *
 * @package    Puntwork
 * @subpackage API
 * @since      1.0.16
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Server-Sent Events handlers for real-time import progress
 */

/**
 * Register SSE endpoint for import progress
 */
add_action('rest_api_init', __NAMESPACE__ . '\\register_sse_import_progress_route');
function register_sse_import_progress_route() {
    register_rest_route('puntwork/v1', '/import-progress', [
        'methods' => 'GET',
        'callback' => __NAMESPACE__ . '\\handle_import_progress_sse',
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
 * Handle Server-Sent Events for import progress
 */
function handle_import_progress_sse($request) {
    $api_key = $request->get_param('api_key');

    // Verify API key
    if (empty($api_key)) {
        return new \WP_Error('missing_api_key', 'API key is required', ['status' => 401]);
    }

    $stored_key = get_option('puntwork_api_key');
    if (empty($stored_key) || !hash_equals($stored_key, $api_key)) {
        return new \WP_Error('invalid_api_key', 'Invalid API key', ['status' => 401]);
    }

    // Set headers for Server-Sent Events
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Cache-Control');

    // Disable output buffering
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Send initial connection event
    echo "event: connected\n";
    echo "data: " . json_encode(['status' => 'connected', 'timestamp' => time()]) . "\n\n";
    flush();

    $last_status = null;
    $last_update = 0;
    $client_disconnected = false;

    // Set up connection handling
    ignore_user_abort(false);
    set_time_limit(0);

    // Handle client disconnect
    register_shutdown_function(function() use (&$client_disconnected) {
        $client_disconnected = true;
    });

    // Main SSE loop
    while (!$client_disconnected && !connection_aborted()) {
        // Check if client is still connected
        if (connection_status() !== CONNECTION_NORMAL) {
            break;
        }

        try {
            // Get current import status
            $current_status = get_option('job_import_status', []);

            // Check for async import status if applicable
            $async_status = check_async_import_status();
            if ($async_status['active']) {
                $current_status = array_merge($current_status, $async_status['progress']);
                $current_status['async_active'] = true;
                $current_status['async_status'] = $async_status['status'];
            } else {
                $current_status['async_active'] = false;
            }

            // Calculate elapsed time
            if (isset($current_status['start_time']) && $current_status['start_time'] > 0) {
                $current_time = microtime(true);
                $current_status['time_elapsed'] = $current_time - $current_status['start_time'];
            }

            // Calculate completion status
            if (!isset($current_status['complete']) || !$current_status['complete']) {
                $current_status['complete'] = ($current_status['processed'] >= $current_status['total'] && $current_status['total'] > 0);
            }

            // Add additional status info
            $current_status['is_running'] = !$current_status['complete'];
            $current_status['last_run'] = get_option('puntwork_last_import_run');
            $current_status['next_scheduled'] = get_next_scheduled_time();

            // Only send update if status has changed or it's been more than 30 seconds
            $current_time = time();
            $status_changed = $last_status === null ||
                            json_encode($current_status) !== json_encode($last_status);
            $should_update = $status_changed || ($current_time - $last_update) > 30;

            if ($should_update) {
                $event_data = [
                    'timestamp' => $current_time,
                    'status' => $current_status
                ];

                echo "event: progress\n";
                echo "data: " . json_encode($event_data) . "\n\n";
                flush();

                $last_status = $current_status;
                $last_update = $current_time;

                PuntWorkLogger::debug('SSE progress update sent', PuntWorkLogger::CONTEXT_API, [
                    'processed' => $current_status['processed'] ?? 0,
                    'total' => $current_status['total'] ?? 0,
                    'complete' => $current_status['complete'] ?? false
                ]);
            }

            // If import is complete, send final update and close connection
            if (isset($current_status['complete']) && $current_status['complete']) {
                echo "event: complete\n";
                echo "data: " . json_encode([
                    'timestamp' => time(),
                    'status' => $current_status,
                    'message' => 'Import completed'
                ]) . "\n\n";
                flush();

                PuntWorkLogger::info('SSE connection closed - import completed', PuntWorkLogger::CONTEXT_API);
                break;
            }

        } catch (\Exception $e) {
            PuntWorkLogger::error('SSE error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_API);

            echo "event: error\n";
            echo "data: " . json_encode([
                'timestamp' => time(),
                'error' => $e->getMessage()
            ]) . "\n\n";
            flush();
            break;
        }

        // Wait before next check (balance between real-time updates and server load)
        sleep(2);
    }

    PuntWorkLogger::debug('SSE connection closed', PuntWorkLogger::CONTEXT_API);
    exit();
}

/**
 * Check async import status (helper function)
 */
function check_async_import_status() {
    // Check if Action Scheduler is available and has active jobs
    if (!function_exists('as_get_scheduled_actions')) {
        return ['active' => false];
    }

    $actions = as_get_scheduled_actions([
        'hook' => 'puntwork_async_import_batch',
        'status' => \ActionScheduler_Store::STATUS_RUNNING,
        'per_page' => 1
    ]);

    if (empty($actions)) {
        return ['active' => false];
    }

    $action = $actions[0];
    $args = $action->get_args();

    return [
        'active' => true,
        'status' => 'running',
        'progress' => [
            'async_job_id' => $action->get_id(),
            'current_batch' => $args['batch_index'] ?? 0,
            'total_batches' => $args['total_batches'] ?? 0,
            'batch_size' => $args['batch_size'] ?? 100
        ]
    ];
}

/**
 * Get next scheduled import time
 */
function get_next_scheduled_time() {
    if (!function_exists('wp_next_scheduled')) {
        return null;
    }

    $next = wp_next_scheduled('puntwork_scheduled_import');
    return $next ? $next : null;
}