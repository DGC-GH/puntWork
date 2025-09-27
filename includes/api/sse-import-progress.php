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
if (! defined('ABSPATH')) {
    exit;
}

// Explicitly load required utility classes for SSE context
require_once __DIR__ . '/../utilities/async-processing.php';
require_once __DIR__ . '/../scheduling/scheduling-core.php';

/**
 * Deep sanitize data for JSON serialization
 * Recursively removes non-serializable objects, resources, and invalid values
 *
 * @param mixed $data Data to sanitize
 * @return mixed Sanitized data
 */
function deep_sanitize_for_json($data) {
    if (is_object($data) || is_resource($data)) {
        error_log('[PUNTWORK] SSE: Removed object/resource from data');
        return null;
    }
    
    if (is_float($data) && (is_infinite($data) || is_nan($data))) {
        error_log('[PUNTWORK] SSE: Removed infinite/NaN float from data');
        return null;
    }
    
    if (is_array($data)) {
        $sanitized = [];
        foreach ($data as $key => $value) {
            // Skip keys that are objects or resources
            if (is_object($key) || is_resource($key)) {
                error_log('[PUNTWORK] SSE: Skipped object/resource key in array');
                continue;
            }
            
            // Convert object/resource keys to strings
            if (!is_string($key) && !is_int($key)) {
                $key = (string) $key;
            }
            
            $sanitized[$key] = deep_sanitize_for_json($value);
        }
        return $sanitized;
    }
    
    // For scalars and other types, return as-is
    return $data;
}

/**
 * Server-Sent Events handlers for real-time import progress
 */

/**
 * Register SSE endpoint for import progress
 */
add_action('rest_api_init', __NAMESPACE__ . '\\register_sse_import_progress_route');
function register_sse_import_progress_route()
{
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
function handle_import_progress_sse($request)
{
    try {
        error_log('[PUNTWORK] SSE: handle_import_progress_sse called');

        $api_key = $request->get_param('api_key');
        error_log('[PUNTWORK] SSE: API key from request: ' . (empty($api_key) ? 'empty' : 'provided'));

        // Verify API key
        if (empty($api_key)) {
            error_log('[PUNTWORK] SSE: Missing API key');
            return new \WP_Error('missing_api_key', 'API key is required', ['status' => 401]);
        }

        $stored_key = get_option('puntwork_api_key');
        error_log('[PUNTWORK] SSE: Stored API key exists: ' . (!empty($stored_key) ? 'yes' : 'no'));

        if (empty($stored_key) || !hash_equals($stored_key, $api_key)) {
            error_log('[PUNTWORK] SSE: Invalid API key provided');
            return new \WP_Error('invalid_api_key', 'Invalid API key', ['status' => 401]);
        }

        error_log('[PUNTWORK] SSE: API key verified, starting SSE connection');

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

        error_log('[PUNTWORK] SSE: Initial connection event sent');

        $last_status = null;
        $last_update = 0;
        $client_disconnected = false;

        // Set up connection handling
        ignore_user_abort(false);
        set_time_limit(0);

        // Handle client disconnect
        register_shutdown_function(function () use (&$client_disconnected) {
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
                error_log('[PUNTWORK] SSE: Raw current_status from get_option: ' . json_encode($current_status));

                // Ensure current_status is an array and sanitize it
                if (!is_array($current_status)) {
                    error_log('[PUNTWORK] SSE: current_status is not an array, resetting to empty array');
                    $current_status = [];
                }

                // Deep sanitize the status to ensure it's JSON serializable
                $current_status = deep_sanitize_for_json($current_status);
                error_log('[PUNTWORK] SSE: After deep sanitization: ' . json_encode($current_status));

                // Check for async import status if applicable
                $async_status = check_async_import_status();
                if ($async_status['active']) {
                    $async_progress = $async_status['progress'] ?? [];
                    // Deep sanitize async progress data
                    $async_progress = deep_sanitize_for_json($async_progress);
                    
                    $current_status = array_merge($current_status, $async_progress);
                    $current_status['async_active'] = true;
                    $current_status['async_status'] = $async_status['status'];
                } else {
                    $current_status['async_active'] = false;
                }

                error_log('[PUNTWORK] SSE: Final current_status: ' . json_encode($current_status));

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

                // Get next scheduled time and ensure it's serializable
                $next_scheduled = get_next_scheduled_time();
                $current_status['next_scheduled'] = is_array($next_scheduled) ?
                    ($next_scheduled['formatted'] ?? null) : $next_scheduled;

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

                    error_log('[PUNTWORK] SSE: Sending progress update, event_data keys: ' . implode(', ', array_keys($event_data)));
                    $json_data = json_encode($event_data);

                    if ($json_data === false) {
                        error_log('[PUNTWORK] SSE: JSON encoding failed: ' . json_last_error_msg());
                        error_log('[PUNTWORK] SSE: Event data that failed: ' . print_r($event_data, true));
                        // Send error event instead with sanitized data
                        $error_event_data = [
                            'timestamp' => $current_time,
                            'error' => 'Failed to encode status data: ' . json_last_error_msg(),
                            'status_summary' => [
                                'processed' => $current_status['processed'] ?? 0,
                                'total' => $current_status['total'] ?? 0,
                                'complete' => $current_status['complete'] ?? false
                            ]
                        ];
                        echo "event: error\n";
                        echo "data: " . json_encode($error_event_data) . "\n\n";
                        flush();
                        continue;
                    }

                    error_log('[PUNTWORK] SSE: JSON encoded data length: ' . strlen($json_data));
                    error_log('[PUNTWORK] SSE: JSON data preview: ' . substr($json_data, 0, 200) . '...');

                    echo "event: progress\n";
                    echo "data: " . $json_data . "\n\n";
                    flush();

                    $last_status = $current_status;
                    $last_update = $current_time;

                    error_log('[PUNTWORK] SSE: Progress update sent - processed: ' . ($current_status['processed'] ?? 0) . '/' . ($current_status['total'] ?? 0));
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

                    error_log('[PUNTWORK] SSE: Import completed, closing connection');
                    break;
                }
            } catch (\Exception $e) {
                error_log('[PUNTWORK] SSE: Error in main loop: ' . $e->getMessage());
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

        error_log('[PUNTWORK] SSE: Connection closed');
    } catch (\Throwable $e) {
        error_log('[PUNTWORK] SSE: Fatal error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        error_log('[PUNTWORK] SSE: Stack trace: ' . $e->getTraceAsString());
        echo "event: error\n";
        echo "data: " . json_encode([
            'timestamp' => time(),
            'error' => 'SSE initialization failed: ' . $e->getMessage()
        ]) . "\n\n";
        flush();
    }

    exit();
}
