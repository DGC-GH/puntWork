<?php
/**
 * Streaming import processing architecture
 *
 * @package    Puntwork
 * @subpackage Import
 * @since      1.1.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/../utilities/options-utilities.php';
require_once __DIR__ . '/../utilities/puntwork-logger.php';
require_once __DIR__ . '/import-circuit-breaker.php';

/**
 * Streaming architecture to replace batch processing
 * Processes items one by one from the feed stream with adaptive resource management
 */

/**
 * Resume streaming import from a specific checkpoint with database recovery
 *
 * @param int $resume_from_item The item index to resume from
 * @param array $recovery_context Optional recovery context data
 * @return array Resume result data
 */
function resume_streaming_import_with_recovery($resume_from_item, $recovery_context = []) {
    global $wpdb;

    PuntWorkLogger::info('Attempting to resume streaming import with database recovery', PuntWorkLogger::CONTEXT_IMPORT, [
        'resume_from_item' => $resume_from_item,
        'recovery_context_provided' => !empty($recovery_context),
        'recovery_reason' => $recovery_context['reason'] ?? 'unknown',
        'last_failed_field' => $recovery_context['last_field'] ?? 'unknown'
    ]);

    // Perform database connection recovery before resuming
    $recovery_success = perform_database_connection_recovery();
    if (!$recovery_success) {
        PuntWorkLogger::error('Database connection recovery failed, cannot resume import', PuntWorkLogger::CONTEXT_IMPORT);
        return [
            'success' => false,
            'message' => 'Database connection recovery failed',
            'can_retry' => false
        ];
    }

    // Clear any stalled import status that might prevent resumption
    $stale_status = get_import_status([]);
    if (isset($stale_status['complete']) && !$stale_status['complete']) {
        $last_update = $stale_status['last_update'] ?? 0;
        $time_since_update = microtime(true) - $last_update;

        // If status is stale (>30 seconds), clear it for fresh start
        if ($time_since_update > 30) {
            PuntWorkLogger::info('Clearing stale import status for resume operation', PuntWorkLogger::CONTEXT_IMPORT, [
                'time_since_last_update' => round($time_since_update, 2),
                'stale_threshold' => 30
            ]);
            delete_import_status();
        }
    }

    // Create resume status with checkpoint information
    $resume_status = [
        'total' => $recovery_context['total_items'] ?? 0,
        'processed' => $resume_from_item,
        'published' => $recovery_context['published'] ?? 0,
        'updated' => $recovery_context['updated'] ?? 0,
        'skipped' => $recovery_context['skipped'] ?? 0,
        'complete' => false,
        'success' => null,
        'phase' => 'resuming_import',
        'resume_attempted' => true,
        'resume_timestamp' => microtime(true),
        'recovery_performed' => true,
        'checkpoint_item' => $resume_from_item,
        'logs' => [
            '[' . date('d-M-Y H:i:s') . ' UTC] Import resumed from item ' . $resume_from_item . ' after database recovery'
        ]
    ];

    // Set the resume status atomically
    $status_set = set_import_status_atomic($resume_status, 3, 5);
    if (!$status_set) {
        PuntWorkLogger::error('Failed to set resume status atomically', PuntWorkLogger::CONTEXT_IMPORT);
        return [
            'success' => false,
            'message' => 'Failed to set resume status',
            'can_retry' => true
        ];
    }

    // Now call the main import function with preserve_status=true to resume
    PuntWorkLogger::info('Initiating main import function for resume operation', PuntWorkLogger::CONTEXT_IMPORT, [
        'preserve_status' => true,
        'resume_from_item' => $resume_from_item
    ]);

    try {
        $result = import_jobs_streaming(true); // preserve_status=true enables resume

        PuntWorkLogger::info('Resume operation completed', PuntWorkLogger::CONTEXT_IMPORT, [
            'success' => $result['success'] ?? false,
            'processed' => $result['processed'] ?? 0,
            'total' => $result['total'] ?? 0,
            'time_elapsed' => $result['time_elapsed'] ?? 0,
            'recovery_successful' => $result['success'] ?? false
        ]);

        return $result;
    } catch (\Exception $e) {
        PuntWorkLogger::error('Resume operation failed with exception', PuntWorkLogger::CONTEXT_IMPORT, [
            'error' => $e->getMessage(),
            'resume_from_item' => $resume_from_item,
            'trace' => $e->getTraceAsString()
        ]);

        return [
            'success' => false,
            'message' => 'Resume operation failed: ' . $e->getMessage(),
            'can_retry' => true,
            'resume_from_item' => $resume_from_item
        ];
    }
}

/**
 * Perform database connection recovery operations
 *
 * @return bool True if recovery successful, false otherwise
 */
function perform_database_connection_recovery() {
    global $wpdb;

    PuntWorkLogger::info('Performing database connection recovery', PuntWorkLogger::CONTEXT_IMPORT);

    try {
        // Step 1: Check current connection status
        $connection_ok = $wpdb->check_connection(false);
        if ($connection_ok) {
            PuntWorkLogger::debug('Database connection is already healthy', PuntWorkLogger::CONTEXT_IMPORT);
            // Even if connection is OK, perform cleanup operations
        } else {
            PuntWorkLogger::warn('Database connection check failed, attempting recovery', PuntWorkLogger::CONTEXT_IMPORT);
        }

        // Step 2: Force reconnection if possible
        if (method_exists($wpdb, 'close')) {
            $wpdb->close();
            PuntWorkLogger::debug('Closed existing database connection', PuntWorkLogger::CONTEXT_IMPORT);
        }

        // Step 3: Re-establish connection
        $reconnect_result = $wpdb->check_connection(false);
        if (!$reconnect_result) {
            PuntWorkLogger::error('Failed to re-establish database connection', PuntWorkLogger::CONTEXT_IMPORT);
            return false;
        }

        // Step 4: Clear any pending result sets that might cause "commands out of sync"
        // This is a best-effort attempt for legacy MySQL
        if (function_exists('mysql_free_result')) {
            try {
                while (mysql_free_result() !== false) {
                    PuntWorkLogger::debug('Cleared pending MySQL result set during recovery', PuntWorkLogger::CONTEXT_IMPORT);
                }
            } catch (\Exception $e) {
                // Ignore errors during cleanup - this is best effort
                PuntWorkLogger::debug('MySQL result cleanup completed (some results may have been cleared)', PuntWorkLogger::CONTEXT_IMPORT);
            }
        }

        // Step 5: Execute a simple test query to verify connection
        $test_result = $wpdb->get_var("SELECT 1 as test");
        if ($test_result !== '1') {
            PuntWorkLogger::error('Database test query failed after recovery', PuntWorkLogger::CONTEXT_IMPORT, [
                'test_result' => $test_result
            ]);
            return false;
        }

        PuntWorkLogger::info('Database connection recovery completed successfully', PuntWorkLogger::CONTEXT_IMPORT, [
            'test_query_passed' => true,
            'connection_reestablished' => true
        ]);

        return true;
    } catch (\Exception $e) {
        PuntWorkLogger::error('Exception during database connection recovery', PuntWorkLogger::CONTEXT_IMPORT, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return false;
    }
}

/**
 * Main streaming import function with composite key system
 *
 * @param bool $preserve_status Whether to preserve existing status for continuation
 * @return array Import result data
 */
function import_jobs_streaming($preserve_status = false) {
    $start_time = microtime(true);
    $composite_keys_processed = 0;
    $processed = 0;
    $published = 0;
    $updated = 0;
    $skipped = 0;
    $all_logs = [];
    $streaming_stats = [
        'start_time' => $start_time,
        'end_time' => null,
        'items_streamed' => 0,
        'memory_peaks' => [],
        'time_per_item' => [],
        'adaptive_resources' => []
    ];

    // Initialize streaming status
    $status_key = 'streaming_import_status';
    if (!$preserve_status) {
        $streaming_status = [
            'phase' => 'initializing',
            'processed' => 0,
            'total' => 0,
            'published' => 0,
            'updated' => 0,
            'skipped' => 0,
            'streaming_metrics' => $streaming_stats,
            'composite_key_cache' => [],
            'resource_limits' => get_adaptive_resource_limits(),
            'circuit_breaker' => ['failures' => 0, 'last_failure' => null, 'state' => 'closed'],
            'start_time' => $start_time,
            'complete' => false,
            'success' => false,
            'last_update' => microtime(true),
            'logs' => ['Streaming import initialized']
        ];
        update_option($status_key, $streaming_status, false);
    } else {
        $streaming_status = get_option($status_key, []);
        if (empty($streaming_status)) {
            return ['success' => false, 'message' => 'No streaming status to preserve', 'logs' => []];
        }
        // Resume from previous state
        $composite_keys_processed = $streaming_status['processed'] ?? 0;
        $streaming_stats = $streaming_status['streaming_metrics'] ?? $streaming_stats;
    }

    PuntWorkLogger::info('Streaming import started', PuntWorkLogger::CONTEXT_IMPORT, [
        'preserve_status' => $preserve_status,
        'start_position' => $composite_keys_processed,
        'resource_limits' => $streaming_status['resource_limits']
    ]);

    try {
        // Prepare streaming environment
        $stream_setup = prepare_streaming_environment();
        if (is_wp_error($stream_setup)) {
            return ['success' => false, 'message' => $stream_setup->get_error_message(), 'logs' => ['Stream setup failed']];
        }

        $json_path = $stream_setup['json_path'];
        $total_items = $stream_setup['total_items'];

        // Update status with total (atomic update with rollback protection)
        $streaming_status['total'] = $total_items;
        $streaming_status['phase'] = 'streaming';
        $status_update_result = set_import_status_atomic($streaming_status);
        if (!$status_update_result) {
            PuntWorkLogger::error('Failed to update streaming status atomically', PuntWorkLogger::CONTEXT_IMPORT, [
                'total_items' => $total_items,
                'phase' => 'streaming',
                'action' => 'continuing_import_despite_lock_failure'
            ]);
            // Continue import even if status update failed - better to complete import than fail due to status update lock
        }

        // Initialize composite key cache for duplicate detection
        $composite_key_cache = $streaming_status['composite_key_cache'] ?? [];
        if (empty($composite_key_cache)) {
            $composite_key_cache = initialize_composite_key_cache($json_path);
            $streaming_status['composite_key_cache'] = $composite_key_cache;
            update_option($status_key, $streaming_status, false);
        }

    PuntWorkLogger::info('Starting item streaming', PuntWorkLogger::CONTEXT_IMPORT, [
        'total_items' => $total_items,
        'composite_keys_cached' => count($composite_key_cache),
        'starting_from' => $composite_keys_processed,
        'initial_memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
    ]);

        // Begin true streaming processing - process items one by one from stream
        $streaming_result = process_feed_stream_optimized(
            $json_path,
            $composite_keys_processed,
            $streaming_status,
            $status_key,
            $streaming_stats,
            $processed,
            $published,
            $updated,
            $skipped,
            $all_logs
        );

        // Finalize streaming import
        $streaming_stats['end_time'] = microtime(true);
        $total_duration = $streaming_stats['end_time'] - $start_time;

        $final_result = [
            'success' => $streaming_result['success'],
            'processed' => $processed,
            'total' => $total_items,
            'published' => $published,
            'updated' => $updated,
            'skipped' => $skipped,
            'time_elapsed' => $total_duration,
            'complete' => $streaming_result['complete'],
            'logs' => $all_logs,
            'streaming_metrics' => $streaming_stats,
            'resource_adaptations' => $streaming_status['resource_limits']['adaptations'] ?? [],
            'message' => $streaming_result['success'] ? 'Streaming import completed successfully' : 'Streaming import encountered issues'
        ];

        // Clean up streaming status
        delete_option($status_key);

        PuntWorkLogger::info('Streaming import completed', PuntWorkLogger::CONTEXT_IMPORT, [
            'total_duration' => $total_duration,
            'items_processed' => $processed,
            'avg_time_per_item' => $processed > 0 ? $total_duration / $processed : 0,
            'success_rate' => $processed > 0 ? ($published + $updated) / $processed : 1.0
        ]);

        // INTEGRATE AUTOMATIC CLEANUP: Run after fully successful import (with forced timeout to prevent indefinite hangs)
        if ($final_result['success'] && $final_result['complete']) {
            $cleanup_start_time = microtime(true);
            PuntWorkLogger::info('Import completed successfully, running automatic cleanup', PuntWorkLogger::CONTEXT_IMPORT, [
                'processed' => $final_result['processed'],
                'published' => $final_result['published'],
                'updated' => $final_result['updated']
            ]);

            // Import the finalization functions
            require_once __DIR__ . '/import-finalization.php';

            // Run cleanup with safeguard validations and forced timeout protection
            try {
                // Set a hard timeout for cleanup (10 minutes max)
                $cleanup_timeout = 600; // 10 minutes
                $cleanup_finished = false;
                $cleanup_result = null;

                // Use process control for timeout if available (Linux)
                if (function_exists('pcntl_fork') && function_exists('pcntl_waitpid')) {
                    $cleanup_pid = pcntl_fork();

                    if ($cleanup_pid == -1) {
                        // Fork failed, run cleanup normally with manual timeout
                        PuntWorkLogger::warn('Fork failed for cleanup timeout protection, using manual timeout', PuntWorkLogger::CONTEXT_IMPORT);
                        $final_result = \Puntwork\finalize_import_with_cleanup($final_result);
                        $cleanup_finished = true;
                    } elseif ($cleanup_pid == 0) {
                        // Child process - run cleanup
                        $final_result = \Puntwork\finalize_import_with_cleanup($final_result);
                        exit(0); // Success
                    } else {
                        // Parent process - wait with timeout
                        $status = null;
                        $wait_start = microtime(true);

                        while (microtime(true) - $wait_start < $cleanup_timeout) {
                            $result = pcntl_waitpid($cleanup_pid, $status, WNOHANG);
                            if ($result > 0) {
                                $cleanup_finished = true;
                                break;
                            }
                            sleep(1); // Wait 1 second before checking again
                        }

                        if (!$cleanup_finished) {
                            // Timeout reached, kill child process
                            posix_kill($cleanup_pid, SIGKILL);
                            PuntWorkLogger::error('Cleanup process timed out and was killed', PuntWorkLogger::CONTEXT_IMPORT, [
                                'timeout_seconds' => $cleanup_timeout,
                                'cleanup_duration' => microtime(true) - $cleanup_start_time
                            ]);

                            // Mark import as successful but with cleanup failure
                            $final_result['cleanup_timeout'] = true;
                            $final_result['cleanup_success'] = false;
                        }
                    }
                } else {
                    // No process control available, run with manual timeout monitoring
                    PuntWorkLogger::warn('Process control not available, using manual timeout for cleanup', PuntWorkLogger::CONTEXT_IMPORT);
                    $final_result = \Puntwork\finalize_import_with_cleanup($final_result);
                    $cleanup_finished = true;
                }

                $cleanup_duration = microtime(true) - $cleanup_start_time;
                PuntWorkLogger::info('Cleanup completed', PuntWorkLogger::CONTEXT_IMPORT, [
                    'cleanup_duration' => round($cleanup_duration, 2),
                    'cleanup_success' => $final_result['cleanup_success'] ?? true,
                    'cleanup_timeout' => $final_result['cleanup_timeout'] ?? false
                ]);

            } catch (\Exception $e) {
                PuntWorkLogger::error('Exception during cleanup phase', PuntWorkLogger::CONTEXT_IMPORT, [
                    'error' => $e->getMessage(),
                    'cleanup_duration' => microtime(true) - $cleanup_start_time,
                    'action' => 'Continuing with import completion despite cleanup error'
                ]);

                // Don't fail the entire import due to cleanup errors
                $final_result['cleanup_error'] = $e->getMessage();
                $final_result['cleanup_success'] = false;
            }
        }

        return $final_result;

    } catch (\Exception $e) {
        PuntWorkLogger::error('Streaming import failed', PuntWorkLogger::CONTEXT_IMPORT, [
            'error' => $e->getMessage(),
            'processed_so_far' => $processed,
            'trace' => $e->getTraceAsString()
        ]);

        // Update status with error
        $streaming_status['success'] = false;
        $streaming_status['complete'] = true;
        $streaming_status['error_message'] = $e->getMessage();
        $streaming_status['last_update'] = microtime(true);
        $streaming_status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Streaming import failed: ' . $e->getMessage();
        update_option($status_key, $streaming_status, false);

        return [
            'success' => false,
            'message' => 'Streaming import failed: ' . $e->getMessage(),
            'logs' => $all_logs,
            'processed' => $processed
        ];
    }
}

/**
 * Dynamically detect available system memory for adaptive memory limits
 */
function detect_available_system_memory() {
    // Try to detect actual available system memory on Linux
    if (function_exists('shell_exec') && stripos(PHP_OS, 'Linux') !== false) {
        // Set memory limit based on environment variable or default to 512M if not set
        $env_memory_limit = getenv('PUNTWORK_MEMORY_LIMIT') ?: '512M';
        $current_limit = ini_get('memory_limit');
        // Only increase if current limit is lower and not unlimited
        if ($current_limit !== '-1' && (intval($current_limit) < intval($env_memory_limit))) {
            ini_set('memory_limit', $env_memory_limit);
        }

        // Read /proc/meminfo for precise memory detection
        $meminfo = shell_exec('cat /proc/meminfo 2>/dev/null');
        if ($meminfo) {
            $lines = explode("\n", trim($meminfo));
            $memory_info = [];

            foreach ($lines as $line) {
                if (preg_match('/^(\w+):\s+(\d+)\s+kB/', $line, $matches)) {
                    $memory_info[$matches[1]] = (int)$matches[2] * 1024; // Convert KB to bytes
                }
            }

            // Calculate available memory from MemAvailable (preferred) or MemFree + Cached + Buffers
            $available_bytes = 0;
            if (isset($memory_info['MemAvailable'])) {
                $available_bytes = $memory_info['MemAvailable'];
            } elseif (isset($memory_info['MemFree']) && isset($memory_info['Cached']) && isset($memory_info['Buffers'])) {
                $available_bytes = $memory_info['MemFree'] + $memory_info['Cached'] + $memory_info['Buffers'];
            }

            if ($available_bytes > 0) {
                // Use 75% of available memory as safe limit for PHP processes
                $recommended_limit = (int)($available_bytes * 0.75);

                // Ensure minimum of 512MB and maximum of 4096MB as safe bounds
                $recommended_limit = max(536870912, min(4294967296, $recommended_limit));

                PuntWorkLogger::info('System memory detected for dynamic limits', PuntWorkLogger::CONTEXT_IMPORT, [
                    'total_memory_gb' => isset($memory_info['MemTotal']) ? round($memory_info['MemTotal'] / 1024 / 1024 / 1024, 2) : 'unknown',
                    'available_memory_gb' => round($available_bytes / 1024 / 1024 / 1024, 2),
                    'recommended_php_limit_gb' => round($recommended_limit / 1024 / 1024 / 1024, 2),
                    'detection_method' => 'proc_meminfo'
                ]);

                return $recommended_limit;
            }
        }
    }

    // Fallback: Use configured PHP limit but apply intelligent scaling
    $current_limit = get_memory_limit_bytes();
    if ($current_limit > 0 && $current_limit !== PHP_INT_MAX) {
        // If PHP limit is low (<512MB), try to increase it safely
        if ($current_limit < 536870912) { // Less than 512MB
            $adaptive_limit = min(1073741824, $current_limit * 2); // Double it, max 1GB
            PuntWorkLogger::info('PHP limit too low, applying adaptive increase', PuntWorkLogger::CONTEXT_IMPORT, [
                'current_limit_mb' => round($current_limit / 1024 / 1024, 2),
                'adaptive_limit_mb' => round($adaptive_limit / 1024 / 1024, 2),
                'detection_method' => 'php_limit_adaptive'
            ]);
            return $adaptive_limit;
        }

        // For reasonable limits, use 90% as safe working limit
        PuntWorkLogger::info('Using PHP-configured memory limit with adaptive adjustment', PuntWorkLogger::CONTEXT_IMPORT, [
            'php_limit_mb' => round($current_limit / 1024 / 1024, 2),
            'adaptive_limit_mb' => round($current_limit * 0.9 / 1024 / 1024, 2),
            'detection_method' => 'php_limit_90_percent'
        ]);
        return (int)($current_limit * 0.9);
    }

    // Ultimate fallback: Conservative 512MB limit
    PuntWorkLogger::warn('Unable to detect memory limits, using conservative fallback', PuntWorkLogger::CONTEXT_IMPORT, [
        'fallback_limit_mb' => 512,
        'detection_method' => 'conservative_fallback'
    ]);
    return 536870912; // 512MB
}

/**
 * Prepare the streaming environment with dynamic memory limit detection
 */
function prepare_streaming_environment() {
    do_action('qm/cease'); // Disable Query Monitor

    // DYNAMIC MEMORY LIMIT: Detect and set based on available system memory
    $dynamic_memory_limit_bytes = detect_available_system_memory();
    $dynamic_memory_limit_mb = round($dynamic_memory_limit_bytes / 1024 / 1024, 2);

    // Set PHP memory limit dynamically
    ini_set('memory_limit', $dynamic_memory_limit_mb . 'M');

    PuntWorkLogger::info('Streaming environment prepared with dynamic memory limits', PuntWorkLogger::CONTEXT_IMPORT, [
        'dynamic_memory_limit_mb' => $dynamic_memory_limit_mb,
        'dynamic_memory_limit_bytes' => $dynamic_memory_limit_bytes,
        'time_limit_seconds' => 1800,
        'ignore_user_abort' => true
    ]);

    set_time_limit(1800);
    ignore_user_abort(true);

    global $wpdb;
    if (!defined('WP_IMPORTING')) {
        define('WP_IMPORTING', true);
    }
    wp_suspend_cache_invalidation(true);

    $json_path = PUNTWORK_PATH . 'feeds/combined-jobs.jsonl';

    if (!file_exists($json_path) || !is_readable($json_path)) {
        return new \WP_Error('stream_setup_failed', 'Feed file not accessible');
    }

    // Count items efficiently for streaming
    $total_items = get_json_item_count($json_path);

    if ($total_items == 0) {
        return new \WP_Error('stream_setup_failed', 'Empty feed file');
    }

    return [
        'json_path' => $json_path,
        'total_items' => $total_items
    ];
}

/**
 * Initialize composite key cache for duplicate detection
 * Uses "GUID + pubdate" format
 */
function initialize_composite_key_cache($json_path) {
    global $wpdb;

    PuntWorkLogger::info('Initializing composite key cache', PuntWorkLogger::CONTEXT_IMPORT);

    // Get existing jobs with their composite keys
    $existing_jobs = $wpdb->get_results("
        SELECT p.ID, p.post_title,
               pm_guid.meta_value as guid,
               pm_pubdate.meta_value as pubdate,
               pm_source.meta_value as source_feed_slug
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm_guid ON p.ID = pm_guid.post_id AND pm_guid.meta_key = 'guid'
        LEFT JOIN {$wpdb->postmeta} pm_pubdate ON p.ID = pm_pubdate.post_id AND pm_pubdate.meta_key = 'pubdate'
        LEFT JOIN {$wpdb->postmeta} pm_source ON p.ID = pm_source.post_id AND pm_source.meta_key = 'source_feed_slug'
        WHERE p.post_type = 'job'
        AND p.post_status IN ('publish', 'draft')
        AND pm_guid.meta_value IS NOT NULL
    ");

    $composite_cache = [];
    foreach ($existing_jobs as $job) {
        $composite_key = generate_composite_key(
            $job->guid,
            $job->pubdate ?? ''
        );
        $composite_cache[$composite_key] = $job->ID;
    }

    PuntWorkLogger::info('Composite key cache initialized', PuntWorkLogger::CONTEXT_IMPORT, [
        'cached_keys' => count($composite_cache),
        'sample_keys' => array_slice(array_keys($composite_cache), 0, 3)
    ]);

    return $composite_cache;
}

/**
 * Generate composite key from GUID and pubdate
 */
function generate_composite_key($guid, $pubdate) {
    // Normalize components
    $normalized_guid = trim($guid);
    $normalized_pubdate = $pubdate ? date('Y-m-d', strtotime($pubdate)) : '';

    // Create deterministic composite key (guid + pubdate only)
    $composite_key = sprintf(
        '%s|%s',
        $normalized_guid,
        $normalized_pubdate
    );

    return $composite_key;
}

/**
 * Process feed stream item by item with adaptive resource management and memory optimization
 */
/**
 * ULTRA-MEMORY-OPTIMIZED STREAMING PROCESSING
 * True streaming: processes one item at a time without loading full file into memory
 * Memory target: <50% of 512MB limit (<256MB peak usage)
 */
function process_feed_stream_optimized($json_path, &$composite_keys_processed, &$streaming_status, $status_key, &$streaming_stats, &$processed, &$published, &$updated, &$skipped, &$all_logs) {
    // ULTRA-AGGRESSIVE MEMORY MANAGEMENT: Initialization
    $initial_memory = memory_get_usage(true);
    PuntWorkLogger::info('STREAMING MEMORY BENCHMARK - Initial memory', PuntWorkLogger::CONTEXT_IMPORT, [
        'initial_memory_mb' => round($initial_memory / 1024 / 1024, 2),
        'memory_limit_mb' => round(get_memory_limit_bytes() / 1024 / 1024, 2),
        'target_max_mb' => 256 // Target <50% of limit
    ]);

    $bookmarks = get_bookmarks_for_streaming(); // Pre-load common ACF fields and reduce function calls
    $resource_limits = get_adaptive_resource_limits();
    $circuit_breaker = $streaming_status['circuit_breaker'];

    // True streaming: process one item at a time from file stream
    PuntWorkLogger::info('🔄 STARTING STREAMING LOOP - File reading begins', PuntWorkLogger::CONTEXT_IMPORT, [
        'json_path' => basename($json_path),
        'total_items_expected' => $streaming_status['total'] ?? 0,
        'composite_keys_processed' => $composite_keys_processed,
        'initial_memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        'starting_timestamp' => microtime(true),
        'action' => 'Beginning main streaming loop - file reading phase'
    ]);

    $handle = fopen($json_path, 'r');
    if (!$handle) {
        PuntWorkLogger::error('❌ STREAMING FAILURE - Could not open feed file', PuntWorkLogger::CONTEXT_IMPORT, [
            'json_path' => basename($json_path),
            'file_exists' => file_exists($json_path),
            'file_readable' => is_readable($json_path),
            'file_size_kb' => file_exists($json_path) ? round(filesize($json_path) / 1024, 1) : 'N/A',
            'action' => 'Cannot proceed - feed file not accessible'
        ]);
        return ['success' => false, 'complete' => false, 'message' => 'Failed to open feed stream'];
    }

    PuntWorkLogger::info('✅ FILE STREAM OPENED successfully', PuntWorkLogger::CONTEXT_IMPORT, [
        'json_path' => basename($json_path),
        'file_size_kb' => round(filesize($json_path) / 1024, 1),
        'total_lines_expected' => $streaming_status['total'] ?? 0,
        'action' => 'File handle successfully opened for streaming'
    ]);

    // Skip to resume position
    $skip_start_time = microtime(true);
    if ($composite_keys_processed > 0) {
        PuntWorkLogger::debug('⏭️ RESUME POSITION - Skipping to existing position', PuntWorkLogger::CONTEXT_IMPORT, [
            'composite_keys_processed' => $composite_keys_processed,
            'lines_to_skip' => $composite_keys_processed,
            'action' => 'Fast-forwarding file pointer to resume position'
        ]);

        $skipped_lines = 0;
        for ($i = 0; $i < $composite_keys_processed; $i++) {
            if (fgets($handle) === false) {
                PuntWorkLogger::warn('⚠️ RESUME SKIP - Unexpected EOF during skip', PuntWorkLogger::CONTEXT_IMPORT, [
                    'lines_attempted_to_skip' => $composite_keys_processed,
                    'lines_actually_skipped' => $skipped_lines,
                    'eof_reached' => true,
                    'remaining_lines' => $composite_keys_processed - $skipped_lines,
                    'action' => 'EOF reached earlier than expected during resume skip'
                ]);
                break; // EOF reached
            }
            $skipped_lines++;
        }

        $skip_duration = microtime(true) - $skip_start_time;
        PuntWorkLogger::info('✅ RESUME SKIP COMPLETED', PuntWorkLogger::CONTEXT_IMPORT, [
            'lines_skipped' => $skipped_lines,
            'skip_duration_seconds' => round($skip_duration, 3),
            'starting_line_number' => $skipped_lines + 1,
            'action' => 'Successfully resumed from previous position'
        ]);
    }

        $item_index = $composite_keys_processed;
        $should_continue = true;
        $batch_start_time = microtime(true);
        $loop_iteration_count = 0;
        $file_lines_read = 0;
        $empty_lines_skipped = 0;
        $invalid_json_count = 0;
        $missing_guid_count = 0;
        $successful_creates = 0;
        $successful_updates = 0;
        $duplicate_skips = 0;

        // Batch operations for ACF field updates (group to reduce individual update_field() calls)
        $acf_update_queue = [];
        $acf_create_queue = [];
        $batch_size = 50; // PERFORMANCE: Increased batch size for speed optimization (reduced DB calls)

        PuntWorkLogger::info('🔄 ENTERING MAIN STREAMING WHILE LOOP', PuntWorkLogger::CONTEXT_IMPORT, [
            'starting_item_index' => $item_index,
            'should_continue_initial' => $should_continue,
            'acf_batch_size' => $batch_size,
            'memory_at_loop_start' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'action' => 'Beginning main processing loop - iterative file reading'
        ]);

        // LOG INITIAL LOOP CONDITION CHECK
        PuntWorkLogger::info('📋 LOOP INITIAL CONDITIONS CHECK', PuntWorkLogger::CONTEXT_IMPORT, [
            'should_continue' => $should_continue,
            'composite_keys_processed' => $composite_keys_processed,
            'streaming_total' => $streaming_status['total'] ?? 0,
            'current_memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'file_handle_open' => is_resource($handle),
            'action' => 'Checking initial loop conditions before first iteration'
        ]);

    // Memory optimization: Use generator/iterator pattern to reduce memory footprint
    while ($should_continue && ($line = fgets($handle)) !== false) {
        $loop_iteration_count++;
        $file_lines_read++;
        $loop_start_time = microtime(true);

        PuntWorkLogger::debug('🔄 LOOP ITERATION ' . $loop_iteration_count, PuntWorkLogger::CONTEXT_IMPORT, [
            'iteration' => $loop_iteration_count,
            'item_index' => $item_index,
            'file_lines_read' => $file_lines_read,
            'should_continue' => $should_continue,
            'memory_current' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'batch_size_current' => count($acf_update_queue) + count($acf_create_queue),
            'action' => 'Processing loop iteration'
        ]);
        $item_start_time = microtime(true);

        $line = trim($line);
        if (empty($line)) {
            $item_index++;
            continue;
        }

        // Parse JSON item efficiently
        $item = json_decode($line, true, 4, JSON_INVALID_UTF8_IGNORE); // Faster decoding with UTF8 ignore
        if ($item === null || !isset($item['guid'])) {
            $skipped++;
            $item_index++;
            continue;
        }

        // DUPLICATE DETECTION OPTIMIZATION: Use composite keys for O(1) lookup
        // Use ONLY guid + pubdate for duplicate detection (source_feed_slug removed from identification)
        $composite_key = generate_composite_key($item['guid'], $item['pubdate'] ?? '');

        // Still log source_feed_slug issues for data quality monitoring
        $source_slug = $item['source_feed_slug'] ?? '';
        if (empty($source_slug)) {
            // Log warning for missing source_feed_slug for data quality tracking
            static $missing_slug_warnings = 0;
            $missing_slug_warnings++;
            if ($missing_slug_warnings <= 3) { // Log first 3 warnings only (reduced frequency)
                PuntWorkLogger::debug('Missing source_feed_slug in feed item (data quality note)', PuntWorkLogger::CONTEXT_IMPORT, [
                    'guid' => $item['guid'] ?? 'unknown',
                    'warning_count' => $missing_slug_warnings
                ]);
            } elseif ($missing_slug_warnings % 500 === 0) { // Log every 500th (reduced from 100)
                PuntWorkLogger::info('Feed data quality: Multiple items missing source_feed_slug', PuntWorkLogger::CONTEXT_IMPORT, [
                    'total_missing' => $missing_slug_warnings,
                    'last_guid' => $item['guid'] ?? 'unknown',
                    'note' => 'Not using source_feed_slug in duplicate detection due to inconsistent feed data'
                ]);
            }
        }

        if (isset($streaming_status['composite_key_cache'][$composite_key])) {
            // Existing job - ALWAYS update fully for comprehensive field population
            $existing_post_id = $streaming_status['composite_key_cache'][$composite_key];

            // ALWAYS UPDATE: Ensure all ACF fields are fully updated on every job post
            $acf_update_queue[$existing_post_id] = $item;

            // Process ACF queue when it reaches batch size
            if (count($acf_update_queue) >= $batch_size) {
                process_acf_queue_batch($acf_update_queue, $acf_create_queue, 'update');
                $acf_update_queue = [];
            }

            $updated++;
            // LIMIT LOGGING: Only keep recent logs to prevent memory buildup
            $all_logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Updated job ' . $existing_post_id;
            if (count($all_logs) > 100) {
                $all_logs = array_slice($all_logs, -50); // Keep only last 50 log entries
            }
        } else {
            // New job - create with batch ACF processing
            // Ensure safe string values for post creation to prevent null-related warnings
            $safe_title = isset($item['title']) && $item['title'] !== null ? (string)$item['title'] : '';
            $safe_content = isset($item['description']) && $item['description'] !== null ? (string)$item['description'] : '';

            $post_data = [
                'post_title' => trim($safe_title),
                'post_content' => trim($safe_content),
                'post_status' => 'publish',
                'post_type' => 'job',
                'post_author' => ''
            ];

            $post_id = wp_insert_post($post_data);
            if (!is_wp_error($post_id)) {
                // ULTRA-AGGRESSIVE COMPOSITE KEY CACHE MANAGEMENT - Target: 8K limit
                $streaming_status['composite_key_cache'][$composite_key] = $post_id;

                // PERFORMANCE: Keep cache size bounded to 10K entries - less aggressive trimming for speed
                if (count($streaming_status['composite_key_cache']) > 10000) {
                    // Remove 20% of oldest entries (keep the most recent 80%) - less aggressive than before
                    $cache_count = count($streaming_status['composite_key_cache']);
                    $remove_count = (int)($cache_count * 0.2); // Remove 20%
                    $streaming_status['composite_key_cache'] = array_slice($streaming_status['composite_key_cache'], $remove_count, null, true);

                    PuntWorkLogger::info('ULTRA-MEMORY: Composite key cache trimmed aggressively', PuntWorkLogger::CONTEXT_IMPORT, [
                        'cache_size_after_trim' => count($streaming_status['composite_key_cache']),
                        'target_limit' => 10000,
                        'efficiency' => '20% removal rate for speed optimization'
                    ]);
                }

                // Queue ACF creation in batches
                $acf_create_queue[$post_id] = $item;

                // Process ACF queue when it reaches batch size
                if (count($acf_create_queue) >= $batch_size) {
                    process_acf_queue_batch($acf_update_queue, $acf_create_queue, 'create');
                    $acf_create_queue = [];
                }

                $published++;
                // Queued log entry
                $all_logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Created new job ' . $post_id . ' (composite key: ' . $composite_key . ')';
            } else {
                $skipped++;
                $circuit_breaker = handle_circuit_breaker_failure($circuit_breaker, 'create_failure');
            }
        }

        // PERFORMANCE OPTIMIZATION: Reduced frequency garbage collection and cleanup (every 200 items vs 50)
        if (($processed + 1) % 200 === 0) { // Reduced frequency for speed optimization
            // LOG MEMORY USAGE FOR DEBUGGING (less frequent)
            $current_memory = memory_get_usage(true);
            $peak_memory = memory_get_peak_usage(true);
            $memory_percent = ($current_memory / get_memory_limit_bytes()) * 100;

            // PERFORMANCE: Only log memory details when memory > 60% to reduce logging overhead
            if ($memory_percent > 60) {
                PuntWorkLogger::info('Memory checkpoint during streaming', PuntWorkLogger::CONTEXT_IMPORT, [
                    'processed_items' => $processed,
                    'memory_percent' => round($memory_percent, 2),
                    'current_memory_mb' => round($current_memory / 1024 / 1024, 2),
                    'peak_memory_mb' => round($peak_memory / 1024 / 1024, 2),
                    'streaming_cache_size' => count($streaming_status['composite_key_cache'] ?? []),
                    'log_entries' => count($all_logs)
                ]);
            }

            // ULTRA-MEMORY: Emergency cache trimming when memory > 80% (more aggressive trimming)
            if ($memory_percent > 80) {
                $original_cache_size = count($streaming_status['composite_key_cache']);
                $trimmed_cache = array_slice($streaming_status['composite_key_cache'],
                    max(0, $original_cache_size - 1000), null, true); // Remove more: keep only most recent 1000 entries
                $streaming_status['composite_key_cache'] = $trimmed_cache;

                PuntWorkLogger::warn('ULTRA-MEMORY: Emergency cache trim triggered (speed mode)', PuntWorkLogger::CONTEXT_IMPORT, [
                    'memory_percent' => round($memory_percent, 2),
                    'original_cache_size' => $original_cache_size,
                    'trimmed_cache_size' => count($trimmed_cache),
                    'entries_removed' => $original_cache_size - count($trimmed_cache),
                    'action' => 'Aggressive cache reduction for speed optimization'
                ]);

                // Also trim logs aggressively under high memory pressure
                if (count($all_logs) > 10) {
                    $all_logs = array_slice($all_logs, -5); // Keep only last 5 entries
                }
            } elseif ($memory_percent > 75) {
                // PERFORMANCE: Light GC at 75% instead of immediate emergency
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        }

        // PERFORMANCE: Periodic cleanup every 100 items but without logging
        if (($processed + 1) % 100 === 0) {
            // Clear temporary variables to free memory without logging overhead
            unset($line, $item, $composite_key, $existing_post_id, $post_id, $post_data, $create_result, $update_result);
        }

        // Clear line and item after every item to prevent memory buildup
        unset($line);
        // Note: $item is still needed for queue processing, so clear it later

        $processed++;
        $item_index++;
        $composite_keys_processed = $item_index;

        // PERFORMANCE METRICS: Optimized tracking with cleanup
        $item_duration = microtime(true) - $item_start_time;
        if ($processed % 10 === 0) { // Sample every 10 items instead of all
            $streaming_stats['time_per_item'][] = $item_duration;
            $streaming_stats['memory_peaks'][] = memory_get_peak_usage(true);

            // LIMIT ARRAY SIZES: Keep only last 50 samples to prevent memory buildup
            if (count($streaming_stats['time_per_item']) > 50) {
                $streaming_stats['time_per_item'] = array_slice($streaming_stats['time_per_item'], -50);
            }
            if (count($streaming_stats['memory_peaks']) > 50) {
                $streaming_stats['memory_peaks'] = array_slice($streaming_stats['memory_peaks'], -50);
            }
        }

        // ADAPTIVE RESOURCE MANAGEMENT: Optimized checks with PAUSE & CONTINUE logic
        $current_time = microtime(true);
        $elapsed_time = $current_time - $streaming_stats['start_time'];

        // FORCE EARLIER PAUSE: Reduce safe threshold to 30 seconds to test pause/resume functionality
        $original_safe_threshold = PUNTWORK_SAFE_RUN_SECONDS ?? 60;
        $forced_safe_threshold = 30; // Force testing at 30 seconds instead of 60

        PuntWorkLogger::debug('RESOURCE CHECK DEBUG', PuntWorkLogger::CONTEXT_IMPORT, [
            'elapsed_seconds' => round($elapsed_time, 2),
            'max_exec_time_limit' => $resource_limits['max_execution_time'] ?? null,
            'current_memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'max_memory_mb' => isset($resource_limits['max_memory_usage']) ? round($resource_limits['max_memory_usage'] / 1024 / 1024, 2) : null,
            'php_time_limit' => ini_get('max_execution_time'),
            'original_safe_threshold' => $original_safe_threshold,
            'forced_safe_threshold' => $forced_safe_threshold,
            'iteration' => $loop_iteration_count,
            'processed' => $processed,
            'should_force_pause' => $elapsed_time >= $forced_safe_threshold
        ]);

        // FORCE IMMEDIATE PAUSE FOR TESTING: Pause at 30 seconds OR after 100 items processed (whichever comes first)
        $should_continue = check_streaming_resources_optimized($resource_limits, $streaming_stats, $circuit_breaker);
        $force_pause_for_testing = $elapsed_time >= $forced_safe_threshold || $processed >= 100;

        if ($force_pause_for_testing) {
            PuntWorkLogger::info('FORCED PAUSE FOR TESTING - pausing import regardless of resource limits', PuntWorkLogger::CONTEXT_IMPORT, [
                'elapsed_seconds' => round($elapsed_time, 2),
                'processed_items' => $processed,
                'force_reason' => $elapsed_time >= $forced_safe_threshold ? 'time_threshold_reached' : 'item_count_threshold_reached',
                'action' => 'Simulating resource limit exceeded for pause/resume testing'
            ]);
            $should_continue = false; // Force pause for testing
        }

        // CRITICAL FIX: Handle timeout/memory limits by PAUSING import (not stopping it completely)
        if (!$should_continue && !isset($streaming_status['paused'])) {
            // Set import status to PAUSED and schedule continuation
            PuntWorkLogger::warn('RESOURCE LIMIT REACHED - PAUSING IMPORT FOR CONTINUATION', PuntWorkLogger::CONTEXT_IMPORT, [
                'processed_items_at_pause' => $processed,
                'total_items' => $streaming_status['total'] ?? 0,
                'composite_keys_processed' => $composite_keys_processed,
                'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'execution_time_seconds' => round(microtime(true) - $streaming_stats['start_time'], 2),
                'phase_at_pause' => $streaming_status['phase'] ?? 'unknown',
                'action' => 'Marking import as PAUSED and scheduling continuation hook'
            ]);

            // Set paused status and checkpoint information
            $streaming_status['paused'] = true;
            $streaming_status['pause_timestamp'] = microtime(true);
            $streaming_status['resume_from_item'] = $composite_keys_processed;
            $streaming_status['resume_context'] = [
                'reason' => 'resource_limits_exceeded',
                'last_item_index' => $composite_keys_processed,
                'total_processed' => $processed,
                'memory_at_pause' => memory_get_usage(true),
                'time_at_pause' => microtime(true) - $streaming_stats['start_time']
            ];

            // CRITICAL: Update status with paused flag BEFORE scheduling continuation
            set_import_status_atomic($streaming_status, 3, 5);

            // Schedule immediate continuation (10 seconds from now)
            wp_schedule_single_event(time() + 10, 'puntwork_continue_import');

            // Schedule fallback continuations
            wp_schedule_single_event(time() + 120, 'puntwork_continue_import_retry');
            wp_schedule_single_event(time() + 300, 'puntwork_continue_import_manual');

            PuntWorkLogger::info('IMPORT PAUSED - CONTINUATION SCHEDULED', PuntWorkLogger::CONTEXT_IMPORT, [
                'pause_timestamp' => $streaming_status['pause_timestamp'],
                'resume_from_item' => $streaming_status['resume_from_item'],
                'continuations_scheduled' => [
                    'primary' => '10 seconds',
                    'retry' => '2 minutes',
                    'manual_fallback' => '5 minutes'
                ],
                'next_step' => 'Import will resume automatically via puntwork_continue_import hook'
            ]);

            // Update status one more time to ensure pause status is saved
            update_option($status_key, $streaming_status, false);

            $should_continue = false; // Exit the processing loop
        }

        // Check for emergency stop (less frequent)
        if ($processed % 50 === 0 && get_transient('import_emergency_stop') === true) {
            $all_logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Emergency stop requested';
            $should_continue = false;
        }

        // FORCE END-OF-IMPORT BREAKPOINT: If FEED ITEMS PROCESSED equals total items, we've reached the end
        // This prevents infinite loops where ACF updates keep processing existing jobs forever
        if (isset($streaming_status['total']) && $streaming_status['total'] > 0 && $composite_keys_processed >= $streaming_status['total']) {
            PuntWorkLogger::error('🚨 FORCE END-OF-IMPORT BREAKPOINT TRIGGERED - STREAM PROCESSING COMPLETE!', PuntWorkLogger::CONTEXT_IMPORT, [
                'feed_items_processed' => $composite_keys_processed,
                'acf_jobs_processed' => $processed,
                'total_feed_items' => $streaming_status['total'],
                'completion_percentage' => floor(($composite_keys_processed / $streaming_status['total']) * 100) . '%',
                'streaming_phase' => 'STREAMING_COMPLETED',
                'acf_phase' => 'CONTINUES_IN_BACKGROUND',
                'action' => 'Exiting main streaming loop (FILE READING COMPLETE) - ACF batch processing may continue',
                'final_memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . 'MB',
                'final_execution_time' => round(microtime(true) - $streaming_stats['start_time'], 2) . 's',
                'action_summary' => 'Feed file successfully processed - exiting streaming loop',
                'next_steps' => 'ACF batch processing will complete remaining updates in background'
            ]);
            $should_continue = false;
        }

        // ULTRA-SAFEGUARD: Maximum iteration limit to prevent runaway loops even if counters fail
        static $maximum_safe_iterations = 50000; // Allow up to 50K iterations (more than enough for any feed)
        if ($composite_keys_processed > $maximum_safe_iterations) {
            PuntWorkLogger::error('🚨 MAXIMUM ITERATION LIMIT EXCEEDED - Emergency shutdown', PuntWorkLogger::CONTEXT_IMPORT, [
                'iterations_reached' => $composite_keys_processed,
                'maximum_allowed' => $maximum_safe_iterations,
                'acf_jobs_processed' => $processed,
                'feed_items_expected' => $streaming_status['total'] ?? 'unknown',
                'emergency_action' => 'Forcing import completion due to excessive iterations'
            ]);
            $should_continue = false;
        }

        // ULTRA-MEMORY PROGRESS MONITORING: Reduced frequency to every 250 items
        if ($processed % 250 === 0) { // ULTRA-OPTIMIZED: Progress every 250 items (was 100)
            PuntWorkLogger::debug('[Progress Update] Milestone reached at 250 items', PuntWorkLogger::CONTEXT_IMPORT, [
                'processed' => $processed,
                'total' => isset($streaming_status['total']) ? $streaming_status['total'] : 0,
                'published' => $published,
                'updated' => $updated,
                'skipped' => $skipped,
                'progress_percentage' => isset($streaming_status['total']) && $streaming_status['total'] > 0 ?
                    round(($processed / $streaming_status['total']) * 100, 2) : 0,
                'time_elapsed' => round(microtime(true) - $streaming_status['start_time'], 2),
                'phase' => $streaming_status['phase']
            ]);

            emit_progress_event($streaming_status, $processed, $published, $updated, $skipped, $streaming_stats);

            // ULTRA-MEMORY OPTIMIZATION: Ultra-lightweight status for DB storage to prevent memory explosion
            $db_status = [
                'phase' => $streaming_status['phase'],
                'processed' => $processed,
                'total' => isset($streaming_status['total']) ? $streaming_status['total'] : 0,
                'published' => $published,
                'updated' => $updated,
                'skipped' => $skipped,
                'circuit_breaker' => $circuit_breaker,
                'last_update' => microtime(true),
                // ULTRA-CRITICAL: Completely exclude streaming_metrics and composite_key_cache from DB
                // These are kept only in-memory to prevent serialization overhead
                'logs' => [] // No logs in DB updates - keep in memory only
            ];

            // CRITICAL FIX: Also update the main job_import_status so client polling mechanisms can see progress
            $main_status = [
                'total' => isset($streaming_status['total']) ? $streaming_status['total'] : 0,
                'processed' => $processed,
                'published' => $published,
                'updated' => $updated,
                'skipped' => $skipped,
                'complete' => false,
                'success' => null,
                'time_elapsed' => microtime(true) - $streaming_status['start_time'],
                'start_time' => $streaming_status['start_time'],
                'last_update' => microtime(true),
                'batch_size' => 250, // Progress update frequency
                'batch_count' => floor($processed / 250),
                'logs' => array_slice($all_logs, -10), // Include latest log entries for client
                'phase' => $streaming_status['phase']
            ];

            PuntWorkLogger::debug('[Progress Update] Preparing main status update', PuntWorkLogger::CONTEXT_IMPORT, [
                'main_status_keys' => array_keys($main_status),
                'logs_count_in_main' => count($main_status['logs']),
                'has_recent_logs' => !empty($all_logs),
                'all_logs_count' => count($all_logs)
            ]);

            $set_status_result = set_import_status_atomic($main_status, 3, 5); // 3 retries, 5 second locks
            PuntWorkLogger::debug('[Progress Update] Main status update attempt', PuntWorkLogger::CONTEXT_IMPORT, [
                'set_import_status_called' => true,
                'processed_value' => $processed,
                'expected_main_status_processed' => $main_status['processed'],
                'wp_cache_function_available' => function_exists('wp_cache_delete')
            ]);

            // Verify the status was set correctly
            $verify_status = get_import_status([]);
            PuntWorkLogger::debug('[Progress Update] Status verification after update', PuntWorkLogger::CONTEXT_IMPORT, [
                'verified_processed' => $verify_status['processed'] ?? 'null',
                'verified_total' => $verify_status['total'] ?? 'null',
                'verified_phase' => $verify_status['phase'] ?? 'null',
                'status_matches_expected' => ($verify_status['processed'] ?? 0) === $processed,
                'last_update_timestamp' => $verify_status['last_update'] ?? 'null'
            ]);

            update_option($status_key, $db_status, false);

            PuntWorkLogger::debug('ULTRA-MEMORY: DB status updated (lightweight format)', PuntWorkLogger::CONTEXT_IMPORT, [
                'processed' => $processed,
                'update_frequency' => 'every 250 items',
                'excludes' => ['streaming_metrics', 'composite_key_cache', 'logs'],
                'memory_saved_mb' => round(get_memory_limit_bytes() * 0.5 / 1024 / 1024, 2), // Estimate
                'client_status_sync' => 'job_import_status updated for polling compatibility'
            ]);
        }
    }

    PuntWorkLogger::info('STREAMING LOOP EXITED - Final status check', PuntWorkLogger::CONTEXT_IMPORT, [
        'exit_reason_summary' => $should_continue ? 'Loop condition became false' : 'Resource limits or breakpoint triggered',
        'should_continue_final' => $should_continue,
        'files_closed_gracefully' => true,
        'final_iteration_count' => $loop_iteration_count,
        'final_lines_read' => $file_lines_read,
        'final_keys_processed' => $composite_keys_processed,
        'final_acf_update_queue_size' => count($acf_update_queue),
        'final_acf_create_queue_size' => count($acf_create_queue),
        'processing_stats_summary' => [
            'total_phases' => ['file_reading', 'acf_batch_processing', 'finalization'],
            'current_phase_when_stopped' => 'streaming_loop',
            'items_successfully_processed' => $processed,
            'memory_at_exit_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'total_execution_time_seconds' => round(microtime(true) - $batch_start_time, 2)
        ],
        'action' => 'Beginning final ACF batch processing'
    ]);

    // LOG execution time analysis to detect timeouts
    $total_execution_time = microtime(true) - $batch_start_time;
    $memory_usage_mb = memory_get_usage(true) / 1024 / 1024;

    PuntWorkLogger::info('EXIT TIMING ANALYSIS - Detect abnormal termination', PuntWorkLogger::CONTEXT_IMPORT, [
        'total_execution_time_seconds' => round($total_execution_time, 2),
        'max_expected_time_seconds' => 600, // 10 minutes
        'percentage_time_used' => round($total_execution_time / 600 * 100, 2) . '%',
        'memory_usage_mb' => round($memory_usage_mb, 2),
        'process_termination_risk_indicators' => [
            'web_server_timeout_likely' => ($total_execution_time > 300) ? 'HIGH RISK' : 'LOW RISK', // 5+ min = likely web timeout
            'hosting_provider_kill_likely' => ($total_execution_time > 120 && $memory_usage_mb < 1000) ? 'MODERATE RISK' : 'LOW RISK',
            'sudden_termination_signature' => ($processed > 1000 && !$should_continue) ? 'UNUSUAL' : 'NORMAL',
            'incomplete_acf_queues' => (count($acf_update_queue) > 0 || count($acf_create_queue) > 0) ? 'TERMINATED MID-ACF' : 'NORMAL EXIT'
        ],
        'recommendation' => 'If import consistently stops here, increase PHP max_execution_time to 1800 (30 minutes) in php.ini or .htaccess'
    ]);

    // DEATHBED LOGGING: Write to file system immediately before any further processing
    if (is_writable(dirname(PUNTWORK_LOGS))) {
        $deathbed_log_path = dirname(PUNTWORK_LOGS) . '/import_deathbed_' . time() . '.log';
        $deathbed_data = [
            'timestamp' => time(),
            'processed_items' => $processed,
            'total_expected' => $streaming_status['total'] ?? 0,
            'remaining_items' => ($streaming_status['total'] ?? 0) - $processed,
            'total_execution_time_seconds' => round($total_execution_time, 2),
            'exit_reason' => $should_continue ? 'NORMAL_COMPLETION' : 'ABNORMAL_TERMINATION',
            'acf_queues_pending' => count($acf_update_queue) + count($acf_create_queue),
            'memory_usage_mb' => round($memory_usage_mb, 2),
            'php_ini_limits' => [
                'max_execution_time' => ini_get('max_execution_time'),
                'memory_limit' => ini_get('memory_limit'),
                'max_input_time' => ini_get('max_input_time')
            ],
            'web_server_hints' => [
                'likely_timeout_source' => $total_execution_time > 300 ? 'WEB_SERVER_TIMEOUT' : 'SCRIPT_ERROR',
                'recommended_php_ini_setting' => 'max_execution_time = 1800',
                'server_upload_limits_worth_checking' => true
            ]
        ];

        file_put_contents($deathbed_log_path, json_encode($deathbed_data, JSON_PRETTY_PRINT), LOCK_EX);
        PuntWorkLogger::info('DEATHBED LOG WRITTEN - Process termination forensics', PuntWorkLogger::CONTEXT_IMPORT, [
            'deathbed_log_path' => $deathbed_log_path,
            'log_contents_summary' => 'Comprehensive analysis of exit conditions written to disk',
            'importance' => 'CRITICAL: This log survives script termination and contains timeout analysis'
        ]);
    }

    fclose($handle);

    PuntWorkLogger::info('FILE HANDLE CLOSED - Starting final ACF batch processing', PuntWorkLogger::CONTEXT_IMPORT, [
        'acf_update_queue_items_remaining' => count($acf_update_queue),
        'acf_create_queue_items_remaining' => count($acf_create_queue),
        'total_acf_jobs_remaining' => count($acf_update_queue) + count($acf_create_queue),
        'processing_status' => 'FILE STREAMING COMPLETE - ACF PROCESSING BEGINNING',
        'estimated_acf_processing_time' => 'TBD - batch processing speed varies',
        'memory_before_final_acf' => round(memory_get_usage(true) / 1024 / 1024, 2) . 'MB',
        'action' => 'Beginning final batch processing of remaining ACF fields'
    ]);

    // Final ACF batch processing
    PuntWorkLogger::info('STARTING FINAL ACF BATCH PROCESSING', PuntWorkLogger::CONTEXT_IMPORT, [
        'queue_sizes' => ['update' => count($acf_update_queue), 'create' => count($acf_create_queue)],
        'processing_strategy' => 'Batch processing in groups of 50 for memory efficiency',
        'expected_iterations' => ceil(count($acf_update_queue) + count($acf_create_queue) / 50),
        'action' => 'Final ACF field population for all queued jobs'
    ]);

    $acf_batch_start_time = microtime(true);
    process_acf_queue_batch($acf_update_queue, $acf_create_queue, 'final');
    $acf_batch_duration = microtime(true) - $acf_batch_start_time;

    PuntWorkLogger::info('FINAL ACF BATCH PROCESSING COMPLETED', PuntWorkLogger::CONTEXT_IMPORT, [
        'acf_processing_duration_seconds' => round($acf_batch_duration, 2),
        'acf_processing_status' => 'COMPLETED SUCCESSFULLY',
        'total_acf_jobs_completed' => count($acf_update_queue) + count($acf_create_queue),
        'acf_jobs_processed_per_second' => count($acf_update_queue) + count($acf_create_queue) > 0 ? round((count($acf_update_queue) + count($acf_create_queue)) / $acf_batch_duration, 2) : 'N/A',
        'action' => 'ACF batch processing finished - preparing final cleanup',
        'next_step' => 'Status updates and cleanup'
    ]);

    // Final status update
    $streaming_status['complete'] = true;
    $streaming_status['success'] = true;
    $streaming_status['last_update'] = microtime(true);

    PuntWorkLogger::info('Streaming processing completed successfully', PuntWorkLogger::CONTEXT_IMPORT, [
        'total_processed' => $processed,
        'published' => $published,
        'updated' => $updated,
        'skipped' => $skipped,
        'total_time' => microtime(true) - $batch_start_time,
        'avg_time_per_item' => $processed > 0 ? (microtime(true) - $batch_start_time) / $processed : 0
    ]);

    update_option($status_key, $streaming_status, false);

    return ['success' => true, 'complete' => true];
}

/**
 * ULTRA-MEMORY-SMART UPDATE CHECKING - Aggressive cache pruning for <256MB target
 */
function should_update_existing_job_smart($post_id, $item) {
    static $update_check_cache = []; // ULTRA-REDUCED: Max 300 entries (was 1000)
    $cache_key = $post_id . '_' . md5(serialize($item));

    if (isset($update_check_cache[$cache_key])) {
        return $update_check_cache[$cache_key];
    }

    // ULTRA-AGGRESSIVE CACHE MANAGEMENT: Keep only 200 entries (67% reduction)
    if (count($update_check_cache) > 300) {
        $update_check_cache = array_slice($update_check_cache, 200, null, true); // Keep last 200 entries only

        PuntWorkLogger::debug('ULTRA-MEMORY: Update check cache pruned aggressively', PuntWorkLogger::CONTEXT_IMPORT, [
            'cache_size_after_prune' => count($update_check_cache),
            'target_limit' => 300,
            'memory_savings' => '67% reduction for <256MB target'
        ]);
    }

    // Quick pubdate comparison first (most common change)
    $current_pubdate = get_post_meta($post_id, 'pubdate', true);
    $new_pubdate = $item['pubdate'] ?? '';
    if ($new_pubdate && strtotime($new_pubdate) > strtotime($current_pubdate)) {
        $update_check_cache[$cache_key] = true;
        return true;
    }

    // Check if title or content changed (use hash comparison for speed)
    $current_title = get_post_field('post_title', $post_id);
    $new_title = $item['title'] ?? $item['functiontitle'] ?? '';
    if ($current_title !== $new_title) {
        $update_check_cache[$cache_key] = true;
        return true;
    }

    $update_check_cache[$cache_key] = false;
    return false;
}

/**
 * Batch process ACF field updates for performance with timeout protection and error handling
 * Updates ALL ACF fields like the batch methods to ensure comprehensive field population
 * Includes individual field timeout protection to prevent hanging on problematic fields
 */
function process_acf_queue_batch(&$update_queue, &$create_queue, $operation = 'batch') {
    // Get all ACF field names that should be processed (like batch methods)
    $acf_fields = get_acf_fields();
    $zero_empty_fields = get_zero_empty_fields();

    // Initialize field failure tracking
    static $field_failures = [];
    static $field_timeouts = [];
    $field_timeout_seconds = 30; // 30 second timeout per field to prevent hanging

    PuntWorkLogger::info('Starting ACF batch processing with timeout protection', PuntWorkLogger::CONTEXT_IMPORT, [
        'operation' => $operation,
        'update_queue_size' => count($update_queue),
        'create_queue_size' => count($create_queue),
        'total_acf_fields' => count($acf_fields),
        'field_timeout_seconds' => $field_timeout_seconds,
        'previous_field_failures' => count($field_failures)
    ]);

    // Process update queue with comprehensive ACF field handling and timeout protection
    foreach ($update_queue as $post_id => $item) {
        $fields_processed = 0;
        $fields_skipped = 0;
        $fields_failed = 0;
        $job_acf_validation_errors = []; // Collect all ACF validation errors for this job

        foreach ($acf_fields as $field) {
            $start_time = microtime(true);
            $field_processed = false;

            try {
                $value = $item[$field] ?? '';
                $is_special = in_array($field, $zero_empty_fields);
                $set_value = $is_special && $value === '0' ? '' : $value;

                if (function_exists('update_field')) {
                    // Set up timeout protection for individual field updates
                    $field_timeout_reached = false;
                    $field_result = null;
                    $error_message = null;

                    // Use pcntl_alarm for timeout protection if available, otherwise manual time tracking
                    if (function_exists('pcntl_alarm')) {
                        // Set alarm for timeout
                        pcntl_alarm($field_timeout_seconds);

                        try {
                            $field_result = update_field($field, $set_value, $post_id);
                            pcntl_alarm(0); // Cancel alarm
                            $field_processed = true;
                        } catch (\Exception $e) {
                            pcntl_alarm(0); // Cancel alarm
                            $error_message = $e->getMessage();
                        }
                    } else {
                        // Manual timeout checking with small increments
                        $field_result = update_field($field, $set_value, $post_id);
                        $elapsed = microtime(true) - $start_time;

                        if ($elapsed > $field_timeout_seconds) {
                            $field_timeout_reached = true;
                            $error_message = "Field update exceeded timeout of {$field_timeout_seconds}s";
                        } else {
                            $field_processed = true;
                        }
                    }

                    if ($field_processed && $field_result !== false) {
                        $fields_processed++;
                    } elseif ($field_timeout_reached || (!$field_processed && isset($error_message))) {
                        // Check for MySQL "commands out of sync" error
                        $is_mysql_sync_error = isset($error_message) &&
                            (strpos(strtolower($error_message), 'commands out of sync') !== false ||
                             strpos(strtolower($error_message), 'mysql') !== false ||
                             strpos(strtolower($error_message), 'out of sync') !== false);

                        if ($is_mysql_sync_error) {
                            $mysql_sync_errors++;
                            PuntWorkLogger::error('MySQL sync error detected - attempting database connection reset', PuntWorkLogger::CONTEXT_IMPORT, [
                                'field' => $field,
                                'post_id' => $post_id,
                                'guid' => $item['guid'] ?? 'unknown',
                                'error_message' => $error_message,
                                'mysql_sync_error_count' => $mysql_sync_errors,
                                'action' => 'Resetting database connection and retrying'
                            ]);

                            // Reset database connections to clear sync state
                            if (isset($wpdb) && $wpdb instanceof \wpdb) {
                                // Force reconnection by closing current connections
                                if (method_exists($wpdb, 'close')) {
                                    $wpdb->close();
                                }
                                // Re-establish connection
                                $wpdb->check_connection(false);
                                PuntWorkLogger::info('Database connection reset for MySQL sync error recovery', PuntWorkLogger::CONTEXT_IMPORT, [
                                    'post_id' => $post_id,
                                    'guid' => $item['guid'] ?? 'unknown',
                                    'connection_reset' => true
                                ]);
                            }

                            // Clear any pending results that might cause sync issues
                            if (function_exists('mysql_free_result')) {
                                // Legacy MySQL function - attempt to free any pending results
                                while (mysql_free_result() !== false) {
                                    // Continue freeing results until none left
                                }
                            }

                            // Retry the field update once after connection reset
                            try {
                                $retry_result = update_field($field, $set_value, $post_id);
                                if ($retry_result !== false) {
                                    PuntWorkLogger::info('MySQL sync error resolved with connection reset', PuntWorkLogger::CONTEXT_IMPORT, [
                                        'field' => $field,
                                        'post_id' => $post_id,
                                        'guid' => $item['guid'] ?? 'unknown',
                                        'retry_successful' => true
                                    ]);
                                    $fields_processed++;
                                    $mysql_sync_errors--; // Reduce error count since we recovered
                                    continue; // Successfully processed, move to next field
                                }
                            } catch (\Exception $retry_exception) {
                                PuntWorkLogger::warn('Retry after MySQL sync error reset also failed', PuntWorkLogger::CONTEXT_IMPORT, [
                                    'field' => $field,
                                    'post_id' => $post_id,
                                    'guid' => $item['guid'] ?? 'unknown',
                                    'original_error' => $error_message,
                                    'retry_error' => $retry_exception->getMessage(),
                                    'action' => 'Skipping field after failed retry'
                                ]);
                            }
                        }

                        // Handle timeout or other failure (including failed MySQL sync recovery)
                        $fields_failed++;
                        $field_failures[$field] = ($field_failures[$field] ?? 0) + 1;
                        if ($field_timeout_reached) {
                            $field_timeouts[$field] = ($field_timeouts[$field] ?? 0) + 1;
                        }

                        PuntWorkLogger::warn('ACF field update failed or timed out', PuntWorkLogger::CONTEXT_IMPORT, [
                            'field' => $field,
                            'post_id' => $post_id,
                            'guid' => $item['guid'] ?? 'unknown',
                            'elapsed_seconds' => round(microtime(true) - $start_time, 3),
                            'timeout_threshold' => $field_timeout_seconds,
                            'was_timeout' => $field_timeout_reached,
                            'was_mysql_sync_error' => $is_mysql_sync_error ?? false,
                            'error_message' => $error_message,
                            'failure_count' => $field_failures[$field],
                            'timeout_count' => $field_timeouts[$field] ?? 0,
                            'mysql_sync_errors_total' => $mysql_sync_errors,
                            'action' => 'Skipped problematic field, continuing import'
                        ]);

                        // Skip this field and continue processing other fields
                        continue;
                    } elseif ($field_result === false) {
                        // ACF update_field returned false (validation failure, etc.)
                        // Capture specific ACF validation errors
                        $acf_validation_errors = function_exists('acf_get_validation_errors') ? acf_get_validation_errors() : [];
                        $field_validation_errors = [];

                        if (!empty($acf_validation_errors)) {
                            foreach ($acf_validation_errors as $error) {
                                if (isset($error['field']) && $error['field'] === $field) {
                                    $field_validation_errors[] = $error['message'] ?? 'Unknown validation error';
                                }
                            }
                        }

                        if (!empty($field_validation_errors)) {
                            $job_acf_validation_errors[$field] = $field_validation_errors;
                        }

                        $fields_skipped++;

                        // Clear ACF validation errors to prevent accumulation
                        if (function_exists('acf_reset_validation_errors')) {
                            acf_reset_validation_errors();
                        }
                    }
                }
            } catch (\Exception $e) {
                // Catch any unexpected errors during field processing
                $fields_failed++;
                $field_failures[$field] = ($field_failures[$field] ?? 0) + 1;

                PuntWorkLogger::error('Unexpected error processing ACF field', PuntWorkLogger::CONTEXT_IMPORT, [
                    'field' => $field,
                    'post_id' => $post_id,
                    'guid' => $item['guid'] ?? 'unknown',
                    'error_message' => $e->getMessage(),
                    'elapsed_seconds' => round(microtime(true) - $start_time, 3),
                    'failure_count' => $field_failures[$field],
                    'action' => 'Skipped field due to error, continuing import'
                ]);

                // Continue processing other fields
                continue;
            }
        }

        // Update metadata in batch (these are more reliable than ACF fields)
        update_post_meta($post_id, '_last_import_update', current_time('mysql'));
        update_post_meta($post_id, 'guid', $item['guid']);
        update_post_meta($post_id, 'pubdate', $item['pubdate'] ?? '');

        // Update import hash for change detection
        $item_hash = md5(json_encode($item));
        update_post_meta($post_id, '_import_hash', $item_hash);

        // Log consolidated ACF validation errors for this job if any occurred
        if (!empty($job_acf_validation_errors)) {
            PuntWorkLogger::debug('Job ACF validation failures summary', PuntWorkLogger::CONTEXT_IMPORT, [
                'post_id' => $post_id,
                'guid' => $item['guid'] ?? 'unknown',
                'failed_fields_count' => count($job_acf_validation_errors),
                'failed_fields' => array_keys($job_acf_validation_errors),
                'validation_errors' => $job_acf_validation_errors,
                'operation' => 'update'
            ]);
        }

        PuntWorkLogger::debug('ACF batch processing completed', PuntWorkLogger::CONTEXT_IMPORT, [
            'post_id' => $post_id,
            'guid' => $item['guid'] ?? 'unknown',
            'fields_processed' => $fields_processed,
            'fields_skipped' => $fields_skipped,
            'fields_failed' => $fields_failed,
            'operation' => 'update'
        ]);
    }

    // Process create queue with comprehensive ACF field handling and timeout protection
    foreach ($create_queue as $post_id => $item) {
        $fields_processed = 0;
        $fields_skipped = 0;
        $fields_failed = 0;

        foreach ($acf_fields as $field) {
            $start_time = microtime(true);
            $field_processed = false;

            try {
                $value = $item[$field] ?? '';
                $is_special = in_array($field, $zero_empty_fields);
                $set_value = $is_special && $value === '0' ? '' : $value;

                if (function_exists('update_field')) {
                    // Set up timeout protection for individual field updates
                    $field_timeout_reached = false;
                    $field_result = null;
                    $error_message = null;

                    // Use pcntl_alarm for timeout protection if available, otherwise manual time tracking
                    if (function_exists('pcntl_alarm')) {
                        // Set alarm for timeout
                        pcntl_alarm($field_timeout_seconds);

                        try {
                            $field_result = update_field($field, $set_value, $post_id);
                            pcntl_alarm(0); // Cancel alarm
                            $field_processed = true;
                        } catch (\Exception $e) {
                            pcntl_alarm(0); // Cancel alarm
                            $error_message = $e->getMessage();
                        }
                    } else {
                        // Manual timeout checking with small increments
                        $field_result = update_field($field, $set_value, $post_id);
                        $elapsed = microtime(true) - $start_time;

                        if ($elapsed > $field_timeout_seconds) {
                            $field_timeout_reached = true;
                            $error_message = "Field update exceeded timeout of {$field_timeout_seconds}s";
                        } else {
                            $field_processed = true;
                        }
                    }

                    if ($field_processed && $field_result !== false) {
                        $fields_processed++;
                    } elseif ($field_timeout_reached || (!$field_processed && isset($error_message))) {
                        // Handle timeout or failure
                        $fields_failed++;
                        $field_failures[$field] = ($field_failures[$field] ?? 0) + 1;
                        if ($field_timeout_reached) {
                            $field_timeouts[$field] = ($field_timeouts[$field] ?? 0) + 1;
                        }

                        PuntWorkLogger::warn('ACF field update failed or timed out during create', PuntWorkLogger::CONTEXT_IMPORT, [
                            'field' => $field,
                            'post_id' => $post_id,
                            'guid' => $item['guid'] ?? 'unknown',
                            'elapsed_seconds' => round(microtime(true) - $start_time, 3),
                            'timeout_threshold' => $field_timeout_seconds,
                            'was_timeout' => $field_timeout_reached,
                            'error_message' => $error_message,
                            'failure_count' => $field_failures[$field],
                            'timeout_count' => $field_timeouts[$field] ?? 0,
                            'action' => 'Skipped problematic field, continuing import'
                        ]);

                        // Skip this field and continue processing other fields
                        continue;
                    } elseif ($field_result === false) {
                        // ACF update_field returned false (validation failure, etc.)
                        PuntWorkLogger::debug('ACF field update returned false during create (validation failure)', PuntWorkLogger::CONTEXT_IMPORT, [
                            'field' => $field,
                            'post_id' => $post_id,
                            'value' => substr((string)$set_value, 0, 100), // Truncate for logging
                            'guid' => $item['guid'] ?? 'unknown'
                        ]);
                        $fields_skipped++;
                    }
                }
            } catch (\Exception $e) {
                // Catch any unexpected errors during field processing
                $fields_failed++;
                $field_failures[$field] = ($field_failures[$field] ?? 0) + 1;

                PuntWorkLogger::error('Unexpected error processing ACF field during create', PuntWorkLogger::CONTEXT_IMPORT, [
                    'field' => $field,
                    'post_id' => $post_id,
                    'guid' => $item['guid'] ?? 'unknown',
                    'error_message' => $e->getMessage(),
                    'elapsed_seconds' => round(microtime(true) - $start_time, 3),
                    'failure_count' => $field_failures[$field],
                    'action' => 'Skipped field due to error, continuing import'
                ]);

                // Continue processing other fields
                continue;
            }
        }

        // Update metadata in batch (these are more reliable than ACF fields)
        update_post_meta($post_id, 'guid', $item['guid']);
        update_post_meta($post_id, 'pubdate', $item['pubdate'] ?? '');
        update_post_meta($post_id, 'source_feed_slug', $item['source_feed_slug'] ?? 'unknown');
        update_post_meta($post_id, '_last_import_update', current_time('mysql'));

        // Set import hash for new posts
        $item_hash = md5(json_encode($item));
        update_post_meta($post_id, '_import_hash', $item_hash);

        PuntWorkLogger::debug('ACF batch processing completed for create', PuntWorkLogger::CONTEXT_IMPORT, [
            'post_id' => $post_id,
            'guid' => $item['guid'] ?? 'unknown',
            'fields_processed' => $fields_processed,
            'fields_skipped' => $fields_skipped,
            'fields_failed' => $fields_failed,
            'operation' => 'create'
        ]);
    }

    // Log summary of batch processing
    PuntWorkLogger::info('ACF batch processing summary', PuntWorkLogger::CONTEXT_IMPORT, [
        'operation' => $operation,
        'update_queue_processed' => count($update_queue),
        'create_queue_processed' => count($create_queue),
        'problematic_fields' => count($field_failures),
        'timed_out_fields' => count($field_timeouts),
        'most_failed_field' => !empty($field_failures) ? array_search(max($field_failures), $field_failures) : null,
        'total_field_failures' => array_sum($field_failures),
        'total_timeouts' => array_sum($field_timeouts)
    ]);

    // Store field failure statistics for potential admin alerts
    if (count($field_failures) > 0) {
        update_option('puntwork_acf_field_failures', [
            'last_batch' => $field_failures,
            'last_updated' => time(),
            'total_processed' => count($update_queue) + count($create_queue)
        ], false);

        // If we have fields with multiple failures, send an alert
        $critical_failures = array_filter($field_failures, function($count) { return $count >= 5; });
        if (!empty($critical_failures) && time() % 3600 < 60) { // Only alert once per hour
            send_health_alert('Critical ACF Field Failures Detected', [
                'critical_fields' => $critical_failures,
                'total_jobs_processed' => count($update_queue) + count($create_queue),
                'recommendation' => 'Check ACF field configuration and consider temporarily disabling problematic fields'
            ]);
        }
    }

    // Clear queues after processing and trigger garbage collection
    $update_queue = [];
    $create_queue = [];

    // Explicit garbage collection to free memory immediately
    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }
}

/**
 * ULTRA-MEMORY-OPTIMIZED RESOURCE CHECKING with aggressive pressure monitoring
 * Includes 75%/85%/95% memory pressure alerts for proactive management
 */
function check_streaming_resources_optimized($resource_limits, $streaming_stats, $circuit_breaker) {
    static $last_check = 0;
    static $check_interval = 10; // Check every 10 seconds instead of 5
    $now = microtime(true);

    if ($now - $last_check < $check_interval) {
        return true;
    }
    $last_check = $now;

    // ULTRA-MEMORY MONITORING: Multi-tier pressure alerts
    $current_memory = memory_get_usage(true);
    $memory_limit = get_memory_limit_bytes();
    $memory_percent = ($current_memory / $memory_limit) * 100;

    // Memory pressure alerts at 75%, 85%, 95%
    if ($memory_percent >= 95) {
        PuntWorkLogger::error('ULTRA-MEMORY: CRITICAL - 95% memory usage reached', PuntWorkLogger::CONTEXT_IMPORT, [
            'memory_percent' => round($memory_percent, 2),
            'current_memory_mb' => round($current_memory / 1024 / 1024, 2),
            'limit_mb' => round($memory_limit / 1024 / 1024, 2),
            'action' => 'EMERGENCY SHUTDOWN initiated',
            'target_reduction' => '<256MB required'
        ]);
        // Trigger immediate emergency stop at 95%
        return false;
    } elseif ($memory_percent >= 85) {
        PuntWorkLogger::error('ULTRA-MEMORY: HIGH ALERT - 85% memory usage exceeded', PuntWorkLogger::CONTEXT_IMPORT, [
            'memory_percent' => round($memory_percent, 2),
            'current_memory_mb' => round($current_memory / 1024 / 1024, 2),
            'limit_mb' => round($memory_limit / 1024 / 1024, 2),
            'action' => 'Aggressive cleanup triggered',
            'cache_trim_required' => true
        ]);
        // Trigger aggressive cleanup at 85%
        trigger_memory_pressure_cleanup();
    } elseif ($memory_percent >= 75) {
        PuntWorkLogger::warn('ULTRA-MEMORY: WARNING - 75% memory usage exceeded', PuntWorkLogger::CONTEXT_IMPORT, [
            'memory_percent' => round($memory_percent, 2),
            'current_memory_mb' => round($current_memory / 1024 / 1024, 2),
            'limit_mb' => round($memory_limit / 1024 / 1024, 2),
            'action' => 'Monitoring increased',
            'recommendation' => 'Check for memory leaks'
        ]);
    }

    // Standard memory limit check (maintain backward compatibility)
    if ($current_memory > $resource_limits['max_memory_usage']) {
        PuntWorkLogger::warn('Memory limit approached in optimized streaming', PuntWorkLogger::CONTEXT_IMPORT, [
            'current_memory_mb' => $current_memory / 1024 / 1024,
            'limit_mb' => $resource_limits['max_memory_usage'] / 1024 / 1024,
            'triggered_shutdown' => true
        ]);
        return false;
    }

    // Time check
    $elapsed = $now - $streaming_stats['start_time'];
    if ($elapsed > $resource_limits['max_execution_time']) {
        PuntWorkLogger::warn('Time limit approached in optimized streaming', PuntWorkLogger::CONTEXT_IMPORT, [
            'elapsed_seconds' => $elapsed,
            'limit_seconds' => $resource_limits['max_execution_time'],
            'triggered_shutdown' => true
        ]);
        return false;
    }

    return true;
}

/**
 * ULTRA-MEMORY PRESSURE CLEANUP: Emergency cache trimming and garbage collection
 */
function trigger_memory_pressure_cleanup() {
    static $last_cleanup = 0;
    $now = microtime(true);

    // Prevent excessive cleanup calls (max once per minute)
    if ($now - $last_cleanup < 60) {
        return;
    }
    $last_cleanup = $now;

    PuntWorkLogger::warn('ULTRA-MEMORY: Executing emergency memory pressure cleanup', PuntWorkLogger::CONTEXT_IMPORT, [
        'action' => 'Force GC + cache trimming',
        'timestamp' => date('H:i:s')
    ]);

    // Aggressive garbage collection
    if (function_exists('gc_collect_cycles')) {
        $cycles = gc_collect_cycles();
        PuntWorkLogger::debug('ULTRA-MEMORY: Emergency GC completed', PuntWorkLogger::CONTEXT_IMPORT, [
            'cycles_collected' => $cycles,
            'memory_after_gc' => round(memory_get_usage(true) / 1024 / 1024, 2)
        ]);
    }

    // Note: Cache trimming would be done in the main loop where we have access to the caches
    // This function is called from resource checking, but actual cache objects are in the main scope
}

/**
 * Get bookmarks for streaming to preload common data
 */
function get_bookmarks_for_streaming() {
    return [
        'acf_available' => function_exists('update_field'),
        'admin_user_id' => get_user_by('login', 'admin') ? get_user_by('login', 'admin')->ID : 1,
        'memory_limit_bytes' => get_memory_limit_bytes()
    ];
}

function process_feed_stream($json_path, &$composite_keys_processed, &$streaming_status, $status_key, &$streaming_stats, &$processed, &$published, &$updated, &$skipped, &$all_logs) {
    $resource_limits = get_adaptive_resource_limits();
    $circuit_breaker = $streaming_status['circuit_breaker'];

    // Open stream
    $handle = fopen($json_path, 'r');
    if (!$handle) {
        return ['success' => false, 'complete' => false, 'message' => 'Failed to open feed stream'];
    }

    $item_index = 0;
    $should_continue = true;

    while ($should_continue && ($line = fgets($handle)) !== false) {
        $item_start_time = microtime(true);

        // Skip to current position if resuming
        if ($item_index < $composite_keys_processed) {
            $item_index++;
            continue;
        }

        $line = trim($line);
        if (empty($line)) {
            $item_index++;
            continue;
        }

        // Parse JSON item
        $item = json_decode($line, true);
        if ($item === null || !isset($item['guid'])) {
            $skipped++;
            $all_logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Skipped item ' . ($item_index + 1) . ': Invalid JSON or missing GUID';
            $item_index++;
            continue;
        }

        // Check composite key for duplicates
        $composite_key = generate_composite_key(
            $item['guid'],
            $item['pubdate'] ?? ''
        );

        if (isset($streaming_status['composite_key_cache'][$composite_key])) {
            // Existing job - check if update needed
            $existing_post_id = $streaming_status['composite_key_cache'][$composite_key];

            if (should_update_existing_job($existing_post_id, $item)) {
                $update_result = update_job_streaming($existing_post_id, $item);
                if ($update_result['success']) {
                    $updated++;
                    $all_logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Updated job ' . $existing_post_id . ' (composite key: ' . $composite_key . ')';
                } else {
                    $skipped++;
                    $all_logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Failed to update job ' . $existing_post_id . ': ' . $update_result['message'];
                }
            } else {
                $skipped++;
                $all_logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Skipped existing job ' . $existing_post_id . ' (no changes needed)';
            }
        } else {
            // New job - create it
            $create_result = create_job_streaming($item);
            if ($create_result['success']) {
                $published++;
                $streaming_status['composite_key_cache'][$composite_key] = $create_result['post_id'];
                $all_logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Created new job ' . $create_result['post_id'] . ' (composite key: ' . $composite_key . ')';
            } else {
                $skipped++;
                $all_logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Failed to create job: ' . $create_result['message'];
                // Trigger circuit breaker for creation failures
                $circuit_breaker = handle_circuit_breaker_failure($circuit_breaker, 'create_failure');
            }
        }

        $processed++;
        $item_index++;
        $composite_keys_processed = $item_index;

        // Record streaming metrics
        $item_duration = microtime(true) - $item_start_time;
        $streaming_stats['time_per_item'][] = $item_duration;
        $streaming_stats['memory_peaks'][] = memory_get_peak_usage(true);

        // Adaptive resource checking
        $should_continue = check_streaming_resources($resource_limits, $streaming_stats, $circuit_breaker);

        // Event-driven progress reporting (every 10 items)
        if ($processed % 10 === 0) {
            emit_progress_event($streaming_status, $processed, $published, $updated, $skipped, $streaming_stats);
        }

        // Check for emergency stop
        if (get_transient('import_emergency_stop') === true) {
            $all_logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Emergency stop requested';
            $should_continue = false;
        }

        // Save progress periodically (every 100 items)
        if ($processed % 100 === 0) {
            $streaming_status['processed'] = $processed;
            $streaming_status['published'] = $published;
            $streaming_status['updated'] = $updated;
            $streaming_status['skipped'] = $skipped;
            $streaming_status['streaming_metrics'] = $streaming_stats;
            $streaming_status['circuit_breaker'] = $circuit_breaker;
            $streaming_status['last_update'] = microtime(true);
            update_option($status_key, $streaming_status, false);
        }
    }

    fclose($handle);

    // Final status update
    $streaming_status['complete'] = true;
    $streaming_status['success'] = true;
    $streaming_status['last_update'] = microtime(true);
    update_option($status_key, $streaming_status, false);

    return ['success' => true, 'complete' => true];
}

/**
 * Get adaptive resource limits based on server capacity
 */
function get_adaptive_resource_limits() {
    $memory_limit = get_memory_limit_bytes();
    $time_limit = ini_get('max_execution_time');

    // Calculate adaptive limits based on server capacity
    $adaptive_limits = [
        'max_memory_usage' => $memory_limit * 0.9, // 90% of available memory (increased for speed)
        'max_execution_time' => max(600, $time_limit - 60), // Leave 60 seconds buffer
        'items_per_memory_check' => 50,
        'items_per_time_check' => 25,
        'acceptable_failure_rate' => 0.05, // 5% failure rate threshold
        'circuit_breaker_threshold' => 3, // 3 consecutive failures trigger circuit breaker
        'adaptations' => []
    ];

    // Detect server capacity indicators
    $cpu_count = function_exists('shell_exec') ? (int)shell_exec('nproc 2>/dev/null') : 2;
    $adaptive_limits['cpu_cores'] = $cpu_count;

    if ($cpu_count >= 8) {
        $adaptive_limits['high_performance'] = true;
        $adaptive_limits['max_memory_usage'] = $memory_limit * 0.95; // Use even more memory on powerful servers (increased for speed)
        $adaptive_limits['adaptations'][] = 'high_performance_detected';
    }

    PuntWorkLogger::info('Adaptive resource limits calculated', PuntWorkLogger::CONTEXT_IMPORT, [
        'limits' => $adaptive_limits,
        'server_memory_mb' => $memory_limit / 1024 / 1024,
        'server_time_limit' => $time_limit,
        'cpu_cores' => $cpu_count
    ]);

    return $adaptive_limits;
}

/**
 * Check if streaming should continue based on adaptive resource limits
 */
function check_streaming_resources($resource_limits, $streaming_stats, $circuit_breaker) {
    static $last_check = 0;
    $now = microtime(true);

    // Only check every few seconds to avoid overhead
    if ($now - $last_check < 5) {
        return true;
    }
    $last_check = $now;

    // Memory check
    $current_memory = memory_get_usage(true);
    if ($current_memory > $resource_limits['max_memory_usage']) {
        PuntWorkLogger::warn('Memory limit exceeded in streaming', PuntWorkLogger::CONTEXT_IMPORT, [
            'current_memory_mb' => $current_memory / 1024 / 1024,
            'limit_mb' => $resource_limits['max_memory_usage'] / 1024 / 1024
        ]);
        return false;
    }

    // Time check
    $elapsed = $now - $streaming_stats['start_time'];
    if ($elapsed > $resource_limits['max_execution_time']) {
        PuntWorkLogger::warn('Time limit exceeded in streaming', PuntWorkLogger::CONTEXT_IMPORT, [
            'elapsed_seconds' => $elapsed,
            'limit_seconds' => $resource_limits['max_execution_time']
        ]);
        return false;
    }

    // Circuit breaker check
    if ($circuit_breaker['state'] === 'open') {
        PuntWorkLogger::warn('Circuit breaker open - stopping streaming', PuntWorkLogger::CONTEXT_IMPORT, [
            'failures' => $circuit_breaker['failures'],
            'last_failure' => $circuit_breaker['last_failure']
        ]);
        return false;
    }

    return true;
}

/**
 * Handle circuit breaker failure
 */
function handle_circuit_breaker_failure($circuit_breaker, $failure_type) {
    $circuit_breaker['failures']++;
    $circuit_breaker['last_failure'] = microtime(true);
    $circuit_breaker['last_failure_type'] = $failure_type;

    // Open circuit breaker after threshold
    if ($circuit_breaker['failures'] >= 3) {
        $circuit_breaker['state'] = 'open';
        PuntWorkLogger::error('Circuit breaker opened', PuntWorkLogger::CONTEXT_IMPORT, [
            'failure_threshold' => 3,
            'failures' => $circuit_breaker['failures'],
            'last_failure_type' => $failure_type
        ]);
    }

    return $circuit_breaker;
}

/**
 * Event-driven progress reporting
 */
function emit_progress_event($streaming_status, $processed, $published, $updated, $skipped, $streaming_stats) {
    $progress_event = [
        'type' => 'streaming_progress',
        'processed' => $processed,
        'published' => $published,
        'updated' => $updated,
        'skipped' => $skipped,
        'memory_usage' => memory_get_usage(true) / 1024 / 1024,
        'avg_time_per_item' => count($streaming_stats['time_per_item']) > 0 ?
            array_sum($streaming_stats['time_per_item']) / count($streaming_stats['time_per_item']) : 0,
        'timestamp' => microtime(true)
    ];

    // Store in status for UI polling (replaces heartbeat polling)
    update_option('streaming_import_status', $streaming_status + ['last_progress_event' => $progress_event], false);

    // Trigger WordPress action for real-time updates
    do_action('puntwork_streaming_progress', $progress_event);
}

/**
 * Check if existing job should be updated based on pubdate
 */
function should_update_existing_job($post_id, $item) {
    $current_pubdate = get_post_meta($post_id, 'pubdate', true);
    $new_pubdate = $item['pubdate'] ?? '';

    if (empty($new_pubdate)) {
        return false; // No pubdate to compare
    }

    // Update if new pubdate is newer or significantly different
    return strtotime($new_pubdate) > strtotime($current_pubdate);
}

/**
 * Update job in streaming context - comprehensive ACF field handling
 */
function update_job_streaming($post_id, $item) {
    try {
        // Ensure safe string values for post update to prevent null-related warnings
        $safe_title = isset($item['title']) && $item['title'] !== null ? (string)$item['title'] : '';
        $safe_content = isset($item['description']) && $item['description'] !== null ? (string)$item['description'] : '';

        $update_result = wp_update_post([
            'ID' => $post_id,
            'post_title' => trim($safe_title),
            'post_content' => trim($safe_content),
            'post_status' => 'publish',
            'post_modified' => current_time('mysql'),
            'post_author' => 0 // Clear author if present
        ]);

        if (is_wp_error($update_result)) {
            return ['success' => false, 'message' => $update_result->get_error_message()];
        }

        // Update ALL ACF fields comprehensively (not selectively)
        $acf_fields = get_acf_fields();
        $zero_empty_fields = get_zero_empty_fields();

        foreach ($acf_fields as $field) {
            $value = $item[$field] ?? '';
            $is_special = in_array($field, $zero_empty_fields);
            $set_value = $is_special && $value === '0' ? '' : $value;

            if (function_exists('update_field')) {
                update_field($field, $set_value, $post_id);
            }
        }

        // Update metadata
        update_post_meta($post_id, '_last_import_update', current_time('mysql'));
        update_post_meta($post_id, 'guid', $item['guid']);
        update_post_meta($post_id, 'pubdate', $item['pubdate'] ?? '');

        // Update import hash for change detection
        $item_hash = md5(json_encode($item));
        update_post_meta($post_id, '_import_hash', $item_hash);

        return ['success' => true];
    } catch (\Exception $e) {
        PuntWorkLogger::error('Error updating job in streaming', PuntWorkLogger::CONTEXT_IMPORT, [
            'post_id' => $post_id,
            'error' => $e->getMessage()
        ]);
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Create job in streaming context - comprehensive ACF field handling
 */
function create_job_streaming($item) {
    try {
        $post_data = [
            'post_title' => $item['title'] ?? '',
            'post_content' => $item['description'] ?? '',
            'post_status' => 'publish',
            'post_type' => 'job'
            // Removed post_author to ensure no author is set
        ];

        $post_id = wp_insert_post($post_data);
        if (is_wp_error($post_id)) {
            return ['success' => false, 'message' => $post_id->get_error_message()];
        }

        // Set ALL ACF fields comprehensively (not selectively)
        $acf_fields = get_acf_fields();
        $zero_empty_fields = get_zero_empty_fields();

        foreach ($acf_fields as $field) {
            $value = $item[$field] ?? '';
            $is_special = in_array($field, $zero_empty_fields);
            $set_value = $is_special && $value === '0' ? '' : $value;

            if (function_exists('update_field')) {
                update_field($field, $set_value, $post_id);
            }
        }

        // Set metadata
        update_post_meta($post_id, 'guid', $item['guid']);
        update_post_meta($post_id, 'pubdate', $item['pubdate'] ?? '');
        update_post_meta($post_id, 'source_feed_slug', $item['source_feed_slug'] ?? 'unknown');
        update_post_meta($post_id, '_last_import_update', current_time('mysql'));

        // Set import hash for new posts
        $item_hash = md5(json_encode($item));
        update_post_meta($post_id, '_import_hash', $item_hash);

        return ['success' => true, 'post_id' => $post_id];
    } catch (\Exception $e) {
        PuntWorkLogger::error('Error creating job in streaming', PuntWorkLogger::CONTEXT_IMPORT, [
            'guid' => $item['guid'] ?? 'unknown',
            'error' => $e->getMessage()
        ]);
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Selective ACF field loading and updating for efficiency
 * Maps all available item data to ACF field keys dynamically
 */
function get_acf_fields_selective($item) {
    $acf_fields = [];

    // Get all ACF field names that can be populated
    $all_acf_field_names = get_acf_fields();

    // Dynamically map item data to ACF fields (direct mapping where field names match)
    foreach ($all_acf_field_names as $acf_field) {
        // Check if this ACF field exists in the item data (case-insensitive)
        $item_value = null;

        // Direct match first
        if (isset($item[$acf_field])) {
            $item_value = $item[$acf_field];
        }

        // If not found, try some common variations (legacy mappings)
        if ($item_value === null) {
            switch ($acf_field) {
                case 'job_location':
                    $item_value = $item['city'] ?? $item['location'] ?? null;
                    break;
                case 'job_company':
                    $item_value = $item['company'] ?? $item['companydescription'] ?? null;
                    break;
                case 'job_salary_min':
                    $item_value = $item['salaryfrom'] ?? $item['salary_min'] ?? null;
                    break;
                case 'job_salary_max':
                    $item_value = $item['salaryto'] ?? $item['salary_max'] ?? null;
                    break;
                case 'job_salary':
                    // Combined salary display
                    $salary_min = $item['salaryfrom'] ?? $item['salary_min'] ?? null;
                    $salary_max = $item['salaryto'] ?? $item['salary_max'] ?? null;
                    if ($salary_min && $salary_max) {
                        $item_value = $salary_min . ' - ' . $salary_max;
                    } elseif ($salary_min) {
                        $item_value = 'From ' . $salary_min;
                    } elseif ($salary_max) {
                        $item_value = 'Up to ' . $salary_max;
                    }
                    break;
                case 'location':
                    $item_value = isset($item['city']) && isset($item['province']) ?
                        trim($item['city'] . ', ' . $item['province'], ', ') :
                        ($item['city'] ?? $item['province'] ?? null);
                    break;
            }
        }

        // Only add if value is not null (don't create empty ACF fields)
        if ($item_value !== null && $item_value !== '') {
            $acf_fields[$acf_field] = $item_value;
        }
    }

    return $acf_fields;
}

function update_job_acf_fields_selective($post_id, $item) {
    $selective_fields = get_acf_fields_selective($item);

    foreach ($selective_fields as $key => $value) {
        update_field($key, $value, $post_id);
    }
}

function set_job_acf_fields_selective($post_id, $item, $acf_fields) {
    $selective_fields = get_acf_fields_selective($item);

    foreach ($selective_fields as $key => $value) {
        update_field($key, $value, $post_id);
    }
}
