<?php
/**
 * Import setup and initialization
 *
 * @package    Puntwork
 * @subpackage Import
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/../utilities/options-utilities.php';

/**
 * Import setup and validation
 * Handles preparation and prerequisite validation for job imports
 */

/**
 * Prepare import setup and validate prerequisites.
 *
 * @param int $batch_start Starting index for batch.
 * @return array|WP_Error Setup data or error.
 */
function prepare_import_setup($batch_start = 0) {
    PuntWorkLogger::info('Import setup preparation started', PuntWorkLogger::CONTEXT_IMPORT, [
        'batch_start' => $batch_start,
        'memory_limit' => ini_get('memory_limit'),
        'time_limit' => ini_get('max_execution_time'),
        'timestamp' => microtime(true)
    ]);

    do_action('qm/cease'); // Disable Query Monitor data collection to reduce memory usage
    ini_set('memory_limit', '512M');
    set_time_limit(1800);
    ignore_user_abort(true);

    global $wpdb;
    $acf_fields = get_acf_fields();
    $zero_empty_fields = get_zero_empty_fields();

    if (!defined('WP_IMPORTING')) {
        define('WP_IMPORTING', true);
    }
    wp_suspend_cache_invalidation(true);
    remove_action('post_updated', 'wp_save_post_revision');

    // Check if there's an existing import in progress and use its start time
    $existing_status = get_import_status();
    if ($existing_status && isset($existing_status['start_time']) && $existing_status['start_time'] > 0) {
        $start_time = $existing_status['start_time'];
        PuntWorkLogger::info('Using existing import start time', PuntWorkLogger::CONTEXT_IMPORT, [
            'existing_start_time' => $start_time,
            'import_status' => $existing_status
        ]);
    } else {
        $start_time = microtime(true);
        PuntWorkLogger::info('Starting new import with start time: ' . $start_time, PuntWorkLogger::CONTEXT_BATCH);
    }

    $json_path = PUNTWORK_PATH . 'feeds/combined-jobs.jsonl';
    PuntWorkLogger::info('JSONL feed path configured', PuntWorkLogger::CONTEXT_IMPORT, [
        'json_path' => $json_path,
        'file_exists' => file_exists($json_path),
        'file_readable' => is_readable($json_path),
        'file_size' => file_exists($json_path) ? filesize($json_path) : 0
    ]);

    if (!file_exists($json_path)) {
        PuntWorkLogger::error('JSONL feed file not found', PuntWorkLogger::CONTEXT_IMPORT, [
            'json_path' => $json_path,
            'expected_location' => PUNTWORK_PATH . 'feeds/',
            'error_type' => 'file_not_found'
        ]);
        return ['success' => false, 'message' => 'JSONL file not found', 'logs' => ['JSONL file not found']];
    }

    if (!is_readable($json_path)) {
        PuntWorkLogger::error('JSONL feed file not readable', PuntWorkLogger::CONTEXT_IMPORT, [
            'json_path' => $json_path,
            'file_permissions' => fileperms($json_path),
            'error_type' => 'file_not_readable'
        ]);
        return ['success' => false, 'message' => 'JSONL file not readable', 'logs' => ['JSONL file not readable']];
    }

    // Check if total count is already cached in import status
    $existing_status = get_import_status();
    if ($existing_status && isset($existing_status['total']) && $existing_status['total'] > 0) {
        $total = $existing_status['total'];
        PuntWorkLogger::info('Using cached total item count', PuntWorkLogger::CONTEXT_IMPORT, [
            'cached_total' => $total,
            'cache_source' => 'import_status'
        ]);
    } else {
        $total = get_json_item_count($json_path);
    }

    if ($total == 0) {
        PuntWorkLogger::error('JSONL feed file is empty or contains no valid items', PuntWorkLogger::CONTEXT_IMPORT, [
            'json_path' => $json_path,
            'file_size' => filesize($json_path),
            'error_type' => 'empty_feed_file'
        ]);
        return ['success' => false, 'message' => 'JSONL file is empty or contains no valid items', 'logs' => ['JSONL file is empty or contains no valid items']];
    }

    // Cache existing job GUIDs if not already cached
    if (false === get_existing_guids()) {
        PuntWorkLogger::info('Starting GUID cache query for existing jobs', PuntWorkLogger::CONTEXT_IMPORT, [
            'cache_status' => 'not_cached',
            'query_type' => 'existing_job_guids'
        ]);
        try {
            $start_guid_query = microtime(true);
            $all_jobs = $wpdb->get_results("SELECT p.ID, pm.meta_value AS guid FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID = pm.post_id WHERE p.post_type = 'job' AND pm.meta_key = 'guid'");
            $guid_query_time = microtime(true) - $start_guid_query;
            PuntWorkLogger::info('GUID cache query completed', PuntWorkLogger::CONTEXT_IMPORT, [
                'query_duration_seconds' => round($guid_query_time, 3),
                'jobs_found' => count($all_jobs),
                'query_success' => true
            ]);

            // Only cache if not too many jobs (to avoid memory issues)
            if (count($all_jobs) > 10000) {
                PuntWorkLogger::warn('Skipping GUID cache due to excessive job count', PuntWorkLogger::CONTEXT_IMPORT, [
                    'job_count' => count($all_jobs),
                    'threshold' => 10000,
                    'reason' => 'memory_optimization',
                    'action' => 'set_empty_cache'
                ]);
                set_existing_guids([]); // Set empty array to avoid re-querying
            } else {
                set_existing_guids($all_jobs);
                PuntWorkLogger::info('GUID cache stored successfully', PuntWorkLogger::CONTEXT_IMPORT, [
                    'cached_job_count' => count($all_jobs),
                    'cache_memory_usage' => 'optimized'
                ]);
            }
    } catch (\Exception $e) {
            PuntWorkLogger::error('GUID cache query failed', PuntWorkLogger::CONTEXT_IMPORT, [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'fallback_action' => 'continue_without_cache'
            ]);
            set_existing_guids([]); // Set empty to avoid re-querying
        }
    } else {
        PuntWorkLogger::info('GUID cache already exists, skipping query', PuntWorkLogger::CONTEXT_IMPORT, [
            'cache_status' => 'already_cached',
            'action' => 'skip_query'
        ]);
    }

    $processed_guids = get_processed_guids();
    $start_index = max(get_import_progress(), $batch_start);

    // For fresh starts (batch_start = 0), reset the status and create new start time
    if ($batch_start === 0) {
        $start_index = 0;
        // Clear processed GUIDs for fresh start
        $processed_guids = [];
        
        // Check if status is already properly initialized (from run_job_import_batch_ajax)
        $existing_status = get_import_status();
        $needs_reinit = true;
        
        if ($existing_status && 
            isset($existing_status['total']) && $existing_status['total'] === $total &&
            isset($existing_status['processed']) && $existing_status['processed'] === 0 &&
            isset($existing_status['complete']) && $existing_status['complete'] === false) {
            // Status is already properly initialized, don't clear it
            $needs_reinit = false;
            PuntWorkLogger::info('Using pre-initialized import status', PuntWorkLogger::CONTEXT_BATCH, [
                'total' => $existing_status['total'],
                'start_time' => $existing_status['start_time']
            ]);
        }
        
        if ($needs_reinit) {
            // Clear existing status for fresh start
            delete_option('job_import_status');
            $start_time = microtime(true);
            PuntWorkLogger::info('Fresh import start - resetting status and progress to 0', PuntWorkLogger::CONTEXT_BATCH);

            // Initialize status for manual import
            $initial_status = initialize_import_status($total, 'Manual import started - preparing to process items...', $start_time);
            set_import_status($initial_status);
        }
    }

    if ($start_index >= $total) {
        return [
            'success' => true,
            'processed' => $total,
            'total' => $total,
            'published' => 0,
            'updated' => 0,
            'skipped' => 0,
            'duplicates_drafted' => 0,
            'time_elapsed' => 0,
            'complete' => true,
            'logs' => ['Start index beyond total items'],
            'batch_size' => 0,
            'inferred_languages' => 0,
            'inferred_benefits' => 0,
            'schema_generated' => 0,
            'batch_time' => 0,
            'batch_processed' => 0
        ];
    }

    return [
        'acf_fields' => $acf_fields,
        'zero_empty_fields' => $zero_empty_fields,
        'start_time' => $start_time,
        'json_path' => $json_path,
        'total' => $total,
        'processed_guids' => $processed_guids,
        'start_index' => $start_index
    ];
}

/**
 * Get the total count of items in JSONL file.
 *
 * @param string $json_path Path to JSONL file.
 * @return int Total item count.
 */
function get_json_item_count($json_path) {
    PuntWorkLogger::info('Starting JSONL item count operation', PuntWorkLogger::CONTEXT_IMPORT, [
        'json_path' => $json_path,
        'operation' => 'count_items',
        'timeout_limit_seconds' => 30
    ]);

    $count = 0;
    $start_time = microtime(true);
    $max_time = 30; // 30 second timeout

    if (($handle = fopen($json_path, "r")) !== false) {
        PuntWorkLogger::debug('JSONL file opened successfully for counting', PuntWorkLogger::CONTEXT_IMPORT, [
            'file_handle' => 'opened',
            'file_path' => $json_path
        ]);

        // Update import status to show counting is in progress
        $current_status = get_import_status();
        if ($current_status && isset($current_status['total']) && $current_status['total'] == 0) {
            $current_status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Counting items in feed file...';
            set_import_status($current_status);
        }

        while (($line = fgets($handle)) !== false) {
            // Check for timeout
            if (microtime(true) - $start_time > $max_time) {
                PuntWorkLogger::warn('JSONL count operation timed out', PuntWorkLogger::CONTEXT_IMPORT, [
                    'elapsed_seconds' => round(microtime(true) - $start_time, 1),
                    'timeout_limit' => $max_time,
                    'items_counted_so_far' => $count,
                    'reason' => 'timeout_protection'
                ]);
                break;
            }

            $line = trim($line);
            if (!empty($line)) {
                $item = json_decode($line, true);
                if ($item !== null) {
                    $count++;
                }
            }

            // Update status every 1000 items to show progress
                if ($count % 1000 === 0 && $count > 0) {
                    $current_status = get_import_status();
                    if ($current_status) {
                        $current_status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Counted ' . number_format($count) . ' items so far...';
                        $current_status['last_update'] = microtime(true);
                        set_import_status($current_status);
                    }                PuntWorkLogger::debug('JSONL counting progress milestone', PuntWorkLogger::CONTEXT_IMPORT, [
                    'items_counted' => $count,
                    'elapsed_seconds' => round(microtime(true) - $start_time, 1),
                    'items_per_second' => round($count / (microtime(true) - $start_time), 1)
                ]);
            }
        }
        fclose($handle);

        // Final status update with total count
        $current_status = get_import_status();
        if ($current_status) {
            $current_status['total'] = $count;
            $current_status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Found ' . number_format($count) . ' total items to import';
            $current_status['last_update'] = microtime(true);
            set_import_status($current_status);
        }

        PuntWorkLogger::info('JSONL count operation completed', PuntWorkLogger::CONTEXT_IMPORT, [
            'total_items' => $count,
            'elapsed_seconds' => round(microtime(true) - $start_time, 1),
            'items_per_second' => round($count / (microtime(true) - $start_time), 1),
            'operation_success' => true
        ]);
    } else {
        PuntWorkLogger::error('Failed to open JSONL file for counting', PuntWorkLogger::CONTEXT_IMPORT, [
            'json_path' => $json_path,
            'error_type' => 'file_open_failed',
            'file_exists' => file_exists($json_path),
            'file_readable' => is_readable($json_path)
        ]);
    }
    return $count;
}