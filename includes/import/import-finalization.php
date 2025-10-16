<?php
/**
 * Import finalization utilities
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

/**
 * Validate feed integrity before performing cleanup operations
 *
 * @param string $json_path Path to the feed file
 * @return array Validation result with 'valid' boolean and 'errors' array
 */
function validate_feed_integrity($json_path) {
    $validation_result = [
        'valid' => true,
        'errors' => [],
        'warnings' => [],
        'stats' => []
    ];

    PuntWorkLogger::info('Starting feed integrity validation', PuntWorkLogger::CONTEXT_BATCH, [
        'feed_path' => $json_path
    ]);

    // 1. Check if file exists and is readable
    if (!file_exists($json_path)) {
        $validation_result['valid'] = false;
        $validation_result['errors'][] = 'Feed file does not exist';
        return $validation_result;
    }

    if (!is_readable($json_path)) {
        $validation_result['valid'] = false;
        $validation_result['errors'][] = 'Feed file is not readable';
        return $validation_result;
    }

    // 2. Check file size and modification time
    $file_size = filesize($json_path);
    $file_modified = filemtime($json_path);
    $file_age_hours = (time() - $file_modified) / 3600;

    $validation_result['stats']['file_size_bytes'] = $file_size;
    $validation_result['stats']['file_modified'] = date('Y-m-d H:i:s', $file_modified);
    $validation_result['stats']['file_age_hours'] = $file_age_hours;

    // Warn if file is very old (more than 24 hours)
    if ($file_age_hours > 24) {
        $validation_result['warnings'][] = sprintf('Feed file is %.1f hours old - may be stale', $file_age_hours);
    }

    // Check if file is suspiciously small (less than 1KB)
    if ($file_size < 1024) {
        $validation_result['warnings'][] = 'Feed file is very small - may be incomplete';
    }

    // 3. Validate JSONL format and content
    $handle = fopen($json_path, 'r');
    if (!$handle) {
        $validation_result['valid'] = false;
        $validation_result['errors'][] = 'Cannot open feed file for reading';
        return $validation_result;
    }

    $line_count = 0;
    $valid_entries = 0;
    $invalid_entries = 0;
    $guids = [];
    $duplicate_guids = [];
    $missing_guids = 0;
    $sample_entries = [];

    // Sample first 100 lines for validation
    $max_sample_lines = 100;
    $sampled_lines = 0;

    while (($line = fgets($handle)) !== false && $sampled_lines < $max_sample_lines) {
        $line_count++;
        $line = trim($line);

        if (empty($line)) {
            continue; // Skip empty lines
        }

        $sampled_lines++;

        // Try to decode JSON
        $entry = json_decode($line, true);
        if ($entry === null) {
            $invalid_entries++;
            continue;
        }

        $valid_entries++;

        // Check for required GUID field
        if (!isset($entry['guid']) || empty($entry['guid'])) {
            $missing_guids++;
            continue;
        }

        // Check for duplicate GUIDs
        $guid = $entry['guid'];
        if (isset($guids[$guid])) {
            if (!isset($duplicate_guids[$guid])) {
                $duplicate_guids[$guid] = 1;
            }
            $duplicate_guids[$guid]++;
        } else {
            $guids[$guid] = true;
        }

        // Store sample entries for analysis
        if (count($sample_entries) < 3) {
            $sample_entries[] = [
                'guid' => $guid,
                'has_title' => isset($entry['title']) && !empty($entry['title']),
                'has_description' => isset($entry['description']) && !empty($entry['description']),
                'field_count' => count($entry)
            ];
        }
    }

    fclose($handle);

    $validation_result['stats']['total_lines'] = $line_count;
    $validation_result['stats']['sampled_lines'] = $sampled_lines;
    $validation_result['stats']['valid_entries'] = $valid_entries;
    $validation_result['stats']['invalid_entries'] = $invalid_entries;
    $validation_result['stats']['unique_guids'] = count($guids);
    $validation_result['stats']['duplicate_guids'] = count($duplicate_guids);
    $validation_result['stats']['missing_guids'] = $missing_guids;
    $validation_result['stats']['sample_entries'] = $sample_entries;

    // 4. Validation checks
    if ($valid_entries === 0) {
        $validation_result['valid'] = false;
        $validation_result['errors'][] = 'No valid JSON entries found in feed';
    }

    if ($missing_guids > 0) {
        $validation_result['valid'] = false;
        $validation_result['errors'][] = sprintf('%d entries missing required GUID field', $missing_guids);
    }

    if (count($duplicate_guids) > 0) {
        $validation_result['warnings'][] = sprintf('%d duplicate GUIDs found', count($duplicate_guids));
        // Don't fail validation for duplicates, just warn
    }

    // Check for reasonable number of entries (at least 10 for a valid feed)
    if ($valid_entries < 10) {
        $validation_result['warnings'][] = sprintf('Feed contains only %d valid entries - may be incomplete', $valid_entries);
    }

    // Check if invalid entries exceed 10% of total
    $invalid_percentage = $line_count > 0 ? ($invalid_entries / $line_count) * 100 : 0;
    if ($invalid_percentage > 10) {
        $validation_result['valid'] = false;
        $validation_result['errors'][] = sprintf('%.1f%% of feed entries are invalid JSON', $invalid_percentage);
    }

    // 5. Log validation results
    if ($validation_result['valid']) {
        PuntWorkLogger::info('Feed integrity validation passed', PuntWorkLogger::CONTEXT_BATCH, [
            'valid_entries' => $valid_entries,
            'unique_guids' => count($guids),
            'warnings_count' => count($validation_result['warnings']),
            'file_age_hours' => round($file_age_hours, 1)
        ]);
    } else {
        PuntWorkLogger::error('Feed integrity validation failed', PuntWorkLogger::CONTEXT_BATCH, [
            'errors' => $validation_result['errors'],
            'warnings' => $validation_result['warnings'],
            'stats' => $validation_result['stats']
        ]);
    }

    return $validation_result;
}

/**
 * Finalize batch import and update status.
 *
 * @param array $result Processing result.
 * @return array Final result.
 */
function finalize_batch_import($result) {
    if (is_wp_error($result) || !$result['success']) {
        return $result;
    }

    $status = get_import_status() ?: [
        'total' => $result['total'],
        'processed' => 0,
        'published' => 0,
        'updated' => 0,
        'skipped' => 0,
        'duplicates_drafted' => 0,
        'time_elapsed' => 0,
        'complete' => false,
        'batch_size' => $result['batch_size'],
        'inferred_languages' => 0,
        'inferred_benefits' => 0,
        'schema_generated' => 0,
        'start_time' => $result['start_time'],
        'last_update' => microtime(true),
        'logs' => [],
    ];

    // Ensure start_time is set properly
    if (!isset($status['start_time']) || $status['start_time'] == 0) {
        $status['start_time'] = $result['start_time'] ?? microtime(true);
    }

    $status['processed'] = $result['processed'] ?? 0;
    $status['published'] += $result['published'] ?? 0;
    $status['updated'] += $result['updated'] ?? 0;
    $status['skipped'] += $result['skipped'] ?? 0;
    $status['duplicates_drafted'] += $result['duplicates_drafted'] ?? 0;

    // Calculate total elapsed time from start to now
    $current_time = microtime(true);
    $total_elapsed = $current_time - $status['start_time'];
    $status['time_elapsed'] = $total_elapsed;

    $status['complete'] = $result['complete'] ?? false;
    $status['success'] = $result['success'] ?? false; // Set success status
    $status['error_message'] = $result['message'] ?? ''; // Set error message if any
    $status['batch_size'] = $result['batch_size'] ?? $status['batch_size'];
    $status['inferred_languages'] += $result['inferred_languages'] ?? 0;
    $status['inferred_benefits'] += $result['inferred_benefits'] ?? 0;
    $status['schema_generated'] += $result['schema_generated'] ?? 0;
    $status['last_update'] = microtime(true);

    set_import_status($status);

    // Log completed manual imports to history (only when in AJAX context and import is complete)
    if (($result['complete'] ?? false) && ($result['success'] ?? false) && function_exists('wp_doing_ajax') && wp_doing_ajax()) {
        $import_details = [
            'success' => $result['success'] ?? false,
            'duration' => $total_elapsed,
            'processed' => $result['processed'] ?? 0,
            'total' => $result['total'] ?? 0,
            'published' => $result['published'] ?? 0,
            'updated' => $result['updated'] ?? 0,
            'skipped' => $result['skipped'] ?? 0,
            'error_message' => $result['message'] ?? '',
            'timestamp' => microtime(true)
        ];

        // Import the function if not already available
        if (!function_exists(__NAMESPACE__ . '\\log_import_run')) {
            require_once __DIR__ . '/../scheduling/scheduling-history.php';
        }

        log_import_run($import_details, 'manual');

        PuntWorkLogger::info('Logged completed manual import to history', PuntWorkLogger::CONTEXT_BATCH, [
            'processed' => $result['processed'] ?? 0,
            'total' => $result['total'] ?? 0,
            'duration' => $total_elapsed
        ]);
    }

    return $result;
}

/**
 * Clean up import transients and temporary data.
 *
 * @return void
 */
function cleanup_import_data() {
    // Clean up transients
    delete_transient('import_cancel');

    // Clean up options that are no longer needed after successful import
    delete_import_progress();
    delete_processed_guids();
    delete_existing_guids();

    // Reset performance metrics for next import
    delete_time_per_job();
    delete_avg_time_per_job();
    delete_last_peak_memory();
    delete_batch_size();
    delete_consecutive_small_batches();
    delete_consecutive_batches();

    // Clean up batch timing data
    delete_last_batch_time();
    delete_last_batch_processed();
}

/**
 * Clean up old job posts that are no longer in the feed.
 * OPTIMIZED VERSION: Uses bulk operations to avoid database sync errors and improve performance
 *
 * @param float $import_start_time The timestamp when the import started.
 * @return array Array with deleted_count and logs.
 */
function cleanup_old_job_posts($import_start_time) {
    global $wpdb;

    $logs = []; // Initialize logs array

    PuntWorkLogger::info('Starting cleanup of old job posts based on current feed GUIDs', PuntWorkLogger::CONTEXT_BATCH, [
        'import_start_time' => date('Y-m-d H:i:s', (int)$import_start_time)
    ]);

    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Starting cleanup of old job posts';

    // Get all current GUIDs from the combined JSONL file with memory-safe chunked processing
    $json_path = PUNTWORK_PATH . 'feeds/combined-jobs.jsonl';
    $current_guids = [];

    if (!file_exists($json_path)) {
        PuntWorkLogger::error('Combined jobs file not found during cleanup - cannot proceed safely', PuntWorkLogger::CONTEXT_BATCH, [
            'json_path' => $json_path
        ]);
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ERROR: Combined jobs file not found - skipping cleanup to prevent unintended deletions';
        return [
            'deleted_count' => 0,
            'logs' => $logs
        ];
    }

    // FEED INTEGRITY VALIDATION: Ensure feed is valid before proceeding with cleanup
    PuntWorkLogger::info('Validating feed integrity before cleanup operations', PuntWorkLogger::CONTEXT_BATCH, [
        'feed_path' => $json_path
    ]);

    $feed_validation = validate_feed_integrity($json_path);

    if (!$feed_validation['valid']) {
        PuntWorkLogger::error('Feed integrity validation failed - cannot proceed with cleanup', PuntWorkLogger::CONTEXT_BATCH, [
            'validation_errors' => $feed_validation['errors'],
            'validation_warnings' => $feed_validation['warnings']
        ]);

        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ERROR: Feed integrity validation failed - skipping cleanup to prevent data loss';
        foreach ($feed_validation['errors'] as $error) {
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] VALIDATION ERROR: ' . $error;
        }
        foreach ($feed_validation['warnings'] as $warning) {
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] VALIDATION WARNING: ' . $warning;
        }

        return [
            'deleted_count' => 0,
            'logs' => $logs
        ];
    }

    // Log validation warnings even if validation passed
    if (!empty($feed_validation['warnings'])) {
        foreach ($feed_validation['warnings'] as $warning) {
            PuntWorkLogger::warn('Feed validation warning during cleanup', PuntWorkLogger::CONTEXT_BATCH, [
                'warning' => $warning,
                'feed_stats' => $feed_validation['stats']
            ]);
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] VALIDATION WARNING: ' . $warning;
        }
    }

    PuntWorkLogger::info('Feed integrity validation passed - proceeding with cleanup', PuntWorkLogger::CONTEXT_BATCH, [
        'valid_entries' => $feed_validation['stats']['valid_entries'] ?? 0,
        'unique_guids' => $feed_validation['stats']['unique_guids'] ?? 0
    ]);

    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Feed integrity validation passed - proceeding with cleanup';

    // MEMORY-SAFE: Load GUIDs in chunks to prevent memory exhaustion
    $guid_chunk_size = 1000;
    $guid_offset = 0;
    $total_guids_loaded = 0;

    PuntWorkLogger::info('Starting memory-safe GUID collection for finalization cleanup', PuntWorkLogger::CONTEXT_BATCH, [
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
        $total_guids_loaded += count($chunk_guids);

        // MEMORY CHECK: Prevent excessive memory usage during GUID collection
        $current_memory = memory_get_usage(true);
        $memory_ratio = $current_memory / get_memory_limit_bytes();

        if ($memory_ratio > 0.6) {
            PuntWorkLogger::warn('High memory usage during finalization GUID collection, reducing chunk size', PuntWorkLogger::CONTEXT_BATCH, [
                'guids_loaded' => $total_guids_loaded,
                'memory_ratio' => $memory_ratio,
                'chunk_size_reduced' => true
            ]);
            $guid_chunk_size = max(200, $guid_chunk_size / 2);
        }

        // Force cleanup between chunks
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }

    if (empty($current_guids)) {
        PuntWorkLogger::error('No valid GUIDs found in combined jobs file - cannot proceed safely', PuntWorkLogger::CONTEXT_BATCH, [
            'json_path' => $json_path
        ]);
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ERROR: No valid GUIDs found in feed file - skipping cleanup to prevent unintended deletions';
        return [
            'deleted_count' => 0,
            'logs' => $logs
        ];
    }

    PuntWorkLogger::info('Found current GUIDs in feed with memory-safe loading', PuntWorkLogger::CONTEXT_BATCH, [
        'guid_count' => count($current_guids),
        'total_guids_loaded' => $total_guids_loaded,
        'chunks_processed' => ceil($total_guids_loaded / 1000),
        'memory_usage_mb' => memory_get_usage(true) / (1024 * 1024),
        'sample_guids' => array_slice($current_guids, 0, 5)
    ]);

    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Found ' . count($current_guids) . ' current GUIDs in feed';

    // Get all published job GUIDs and compare against current feed GUIDs with memory-safe processing
    $published_jobs = [];
    $guid_chunks = array_chunk($current_guids, 2000); // Process in chunks of 2000 for SQL IN() clauses

    foreach ($guid_chunks as $chunk_index => $guid_chunk) {
        $placeholders = implode(',', array_fill(0, count($guid_chunk), '%s'));
        $chunk_jobs = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT p.ID, pm.meta_value as guid
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'guid'
            WHERE p.post_type = 'job'
            AND p.post_status = 'publish'
            AND pm.meta_value IN ({$placeholders})
        ", $guid_chunk));

        $published_jobs = array_merge($published_jobs, $chunk_jobs);

        PuntWorkLogger::debug('Published jobs chunk processed', PuntWorkLogger::CONTEXT_BATCH, [
            'chunk_index' => $chunk_index,
            'chunk_size' => count($guid_chunk),
            'jobs_found_in_chunk' => count($chunk_jobs),
            'total_jobs_found' => count($published_jobs),
            'memory_usage_mb' => memory_get_usage(true) / (1024 * 1024)
        ]);

        // Memory cleanup between chunks
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }

    $current_guids_set = array_flip($current_guids); // For fast lookup
    $old_post_ids = [];

    foreach ($published_jobs as $job) {
        if (!isset($current_guids_set[$job->guid])) {
            $old_post_ids[] = $job->ID;
        }
    }

    $total_old_posts = count($old_post_ids);

    PuntWorkLogger::info('Total old job posts to clean up in finalization', PuntWorkLogger::CONTEXT_BATCH, [
        'total_old_posts' => $total_old_posts,
        'current_feed_jobs' => count($current_guids),
        'published_jobs_found' => count($published_jobs),
        'memory_usage_mb' => memory_get_usage(true) / (1024 * 1024),
        'sample_old_post_ids' => array_slice($old_post_ids, 0, 5)
    ]);

    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Found ' . $total_old_posts . ' old published job posts to clean up';

    if ($total_old_posts === 0) {
        PuntWorkLogger::info('No old posts to clean up', PuntWorkLogger::CONTEXT_BATCH);
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] No old posts to clean up';
        return [
            'deleted_count' => 0,
            'logs' => $logs
        ];
    }

    // Update status to show cleanup progress starting
    $cleanup_start_status = get_import_status([]);
    $cleanup_start_status['cleanup_total'] = $total_old_posts;
    $cleanup_start_status['cleanup_processed'] = 0;
    $cleanup_start_status['last_update'] = microtime(true);
    set_import_status($cleanup_start_status);

    // Process deletions in batches with memory monitoring to avoid memory issues and timeouts
    $batch_size = 100; // Start with 100 posts at a time, will adjust based on memory usage
    $total_deleted = 0;
    $batches_processed = 0;
    $memory_warnings = 0;

    while (!empty($old_post_ids)) {
        $batches_processed++;

        // MEMORY CHECK: Monitor memory usage and adjust batch size dynamically
        $current_memory = memory_get_usage(true);
        $memory_ratio = $current_memory / get_memory_limit_bytes();

        // Reduce batch size if memory usage is high
        if ($memory_ratio > 0.7) {
            $old_batch_size = $batch_size;
            $batch_size = max(10, intval($batch_size * 0.5)); // Reduce to 50%, minimum 10

            PuntWorkLogger::warn('High memory usage during finalization cleanup, reducing batch size', PuntWorkLogger::CONTEXT_BATCH, [
                'memory_ratio' => $memory_ratio,
                'old_batch_size' => $old_batch_size,
                'new_batch_size' => $batch_size,
                'batches_processed' => $batches_processed,
                'memory_warnings' => ++$memory_warnings
            ]);

            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] WARNING: High memory usage (' . round($memory_ratio * 100, 1) . '%), reducing batch size to ' . $batch_size;
        }

        // Log memory status periodically or when high
        if ($memory_ratio > 0.85 || $batches_processed % 10 === 0) {
            PuntWorkLogger::info('Memory status during finalization cleanup', PuntWorkLogger::CONTEXT_BATCH, [
                'memory_ratio' => $memory_ratio,
                'batch_size' => $batch_size,
                'batches_processed' => $batches_processed,
                'remaining_posts' => count($old_post_ids),
                'memory_usage_mb' => $current_memory / (1024 * 1024)
            ]);
        }

        $batch_ids = array_splice($old_post_ids, 0, $batch_size);

        PuntWorkLogger::debug('Processing deletion batch with memory monitoring', PuntWorkLogger::CONTEXT_BATCH, [
            'batch' => $batches_processed,
            'batch_size' => count($batch_ids),
            'remaining' => count($old_post_ids),
            'memory_ratio' => $memory_ratio
        ]);

        // Delete posts in this batch
        $deleted_in_batch = 0;
        foreach ($batch_ids as $post_id) {
            // Verify post still exists and is published before deletion
            $post_status = $wpdb->get_var($wpdb->prepare(
                "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'job'",
                $post_id
            ));

            if ($post_status === 'publish') {
                $result = wp_delete_post($post_id, true); // true = force delete, skip trash
                if ($result) {
                    $deleted_in_batch++;
                    $total_deleted++;
                } else {
                    PuntWorkLogger::warn('Failed to delete post', PuntWorkLogger::CONTEXT_BATCH, [
                        'post_id' => $post_id
                    ]);
                }
            }

            // Free result set after each query to prevent sync errors
            if ($wpdb->result instanceof mysqli_result) {
                $wpdb->result->free();
            }
        }

        // Update progress every batch (every 100 deletions)
        $cleanup_progress_status = get_import_status([]);
        $cleanup_progress_status['cleanup_processed'] = $total_deleted;
        $cleanup_progress_status['last_update'] = microtime(true);
        if (!is_array($cleanup_progress_status['logs'] ?? null)) {
            $cleanup_progress_status['logs'] = [];
        }
        $cleanup_progress_status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Cleanup progress: ' . $total_deleted . '/' . $total_old_posts . ' old jobs deleted';
        set_import_status($cleanup_progress_status);

        PuntWorkLogger::debug('Batch deletion completed', PuntWorkLogger::CONTEXT_BATCH, [
            'batch' => $batches_processed,
            'deleted_in_batch' => $deleted_in_batch,
            'total_deleted' => $total_deleted,
            'remaining' => count($old_post_ids)
        ]);

        // Aggressive memory cleanup between batches to prevent memory exhaustion
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        // Flush WordPress object cache to free memory
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        // Force cleanup of any lingering references
        unset($batch_ids);

        // Small delay between batches to prevent overwhelming the server
        usleep(10000); // 0.01 seconds
    }

    // Final cleanup status update
    $final_cleanup_status = get_import_status([]);
    $final_cleanup_status['cleanup_processed'] = $total_deleted;
    $final_cleanup_status['last_update'] = microtime(true);
    set_import_status($final_cleanup_status);

    PuntWorkLogger::info('Cleanup of old published job posts completed', PuntWorkLogger::CONTEXT_BATCH, [
        'deleted_count' => $total_deleted,
        'current_feed_jobs' => count($current_guids),
        'batches_processed' => $batches_processed
    ]);

    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Cleanup completed: ' . $total_deleted . ' old published jobs deleted';

    return [
        'deleted_count' => $total_deleted,
        'logs' => $logs
    ];
}

/**
 * Smart cleanup system for expired jobs
 * Implements retention policies instead of auto-deletion
 */
function smart_cleanup_expired_jobs() {
    $config = get_import_config();
    $cleanup_config = $config['cleanup'];

    if ($cleanup_config['strategy'] === 'none') {
        PuntWorkLogger::info('Cleanup disabled by configuration', PuntWorkLogger::CONTEXT_IMPORT);
        return ['action' => 'disabled', 'deleted_count' => 0];
    }

    PuntWorkLogger::info('Starting smart cleanup of expired jobs', PuntWorkLogger::CONTEXT_IMPORT, [
        'strategy' => $cleanup_config['strategy'],
        'retention_days' => $cleanup_config['retention_days']
    ]);

    if ($cleanup_config['strategy'] === 'smart_retention') {
        return smart_retention_cleanup($cleanup_config);
    } elseif ($cleanup_config['strategy'] === 'auto_delete') {
        return legacy_cleanup_old_posts();
    }

    return ['action' => 'unknown_strategy', 'deleted_count' => 0];
}

/**
 * Smart retention cleanup - marks jobs as expired instead of deleting
 */
function smart_retention_cleanup($cleanup_config) {
    global $wpdb;

    $retention_days = $cleanup_config['retention_days'];
    $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$retention_days} days"));
    $batch_size = $cleanup_config['batch_size'];

    PuntWorkLogger::info('Executing smart retention cleanup', PuntWorkLogger::CONTEXT_IMPORT, [
        'retention_days' => $retention_days,
        'cutoff_date' => $cutoff_date,
        'batch_size' => $batch_size
    ]);

    // Find jobs older than retention period that are still published
    $expired_jobs = $wpdb->get_results($wpdb->prepare("
        SELECT p.ID, p.post_title,
               pm_last_import.meta_value as last_import,
               pm_guid.meta_value as guid
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm_last_import ON p.ID = pm_last_import.post_id AND pm_last_import.meta_key = '_last_import_update'
        LEFT JOIN {$wpdb->postmeta} pm_guid ON p.ID = pm_guid.post_id AND pm_guid.meta_key = 'guid'
        WHERE p.post_type = 'job'
        AND p.post_status = 'publish'
        AND (
            pm_last_import.meta_value IS NULL
            OR pm_last_import.meta_value < %s
        )
        LIMIT %d
    ", $cutoff_date, $batch_size));

    $processed_count = 0;
    $expired_count = 0;

    foreach ($expired_jobs as $job) {
        // Check if job is still in current feed before expiring
        $still_active = is_job_still_active($job->guid);

        if (!$still_active) {
            // Mark as expired instead of deleting
            expire_job_post($job->ID, $job->guid);
            $expired_count++;

            PuntWorkLogger::debug('Job marked as expired (smart retention)', PuntWorkLogger::CONTEXT_IMPORT, [
                'post_id' => $job->ID,
                'guid' => $job->guid,
                'last_import' => $job->last_import,
                'retention_days' => $retention_days
            ]);
        }

        $processed_count++;
    }

    // Clean up truly orphaned jobs (no GUID, very old) - safety cleanup
    $orphaned_cleanup = cleanup_orphaned_jobs($cleanup_config);

    $result = [
        'action' => 'smart_retention',
        'expired_count' => $expired_count,
        'processed_count' => $processed_count,
        'orphaned_cleaned' => $orphaned_cleanup['deleted_count'],
        'retention_days' => $retention_days
    ];

    PuntWorkLogger::info('Smart retention cleanup completed', PuntWorkLogger::CONTEXT_IMPORT, $result);

    return $result;
}

/**
 * Check if a job is still active in the current feed
 */
function is_job_still_active($guid) {
    static $current_guids = null;

    // Lazy load current GUIDs from feed
    if ($current_guids === null) {
        $current_guids = [];
        $json_path = PUNTWORK_PATH . 'feeds/combined-jobs.jsonl';

        if (file_exists($json_path) && ($handle = fopen($json_path, 'r'))) {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (!empty($line)) {
                    $item = json_decode($line, true);
                    if ($item && isset($item['guid'])) {
                        $current_guids[$item['guid']] = true;
                    }
                }
            }
            fclose($handle);
        }
    }

    return isset($current_guids[$guid]);
}

/**
 * Mark a job post as expired (status change, not deletion)
 */
function expire_job_post($post_id, $guid) {
    global $wpdb;

    // Change status to 'expired' or 'draft' instead of deleting
    $result = $wpdb->update(
        $wpdb->posts,
        [
            'post_status' => 'draft',
            'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', true)
        ],
        ['ID' => $post_id],
        ['%s', '%s', '%s'],
        ['%d']
    );

    if ($result !== false) {
        // Add metadata to track expiration
        update_post_meta($post_id, '_expired_at', current_time('mysql'));
        update_post_meta($post_id, '_expired_reason', 'smart_retention_policy');

        // Keep job searchable but marked as inactive
        update_post_meta($post_id, '_job_status', 'expired');

        return true;
    }

    return false;
}

/**
 * Clean up truly orphaned jobs (safeguard cleanup)
 */
function cleanup_orphaned_jobs($cleanup_config) {
    global $wpdb;

    // Only clean jobs that have no GUID and are very old (>180 days)
    $very_old_cutoff = date('Y-m-d H:i:s', strtotime('-180 days'));
    $max_cleanup_batch = min($cleanup_config['batch_size'], 50); // Limit to 50 for safety

    $orphaned_jobs = $wpdb->get_col($wpdb->prepare("
        SELECT p.ID
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm_guid ON p.ID = pm_guid.post_id AND pm_guid.meta_key = 'guid'
        LEFT JOIN {$wpdb->postmeta} pm_last_import ON p.ID = pm_last_import.post_id AND pm_last_import.meta_key = '_last_import_update'
        WHERE p.post_type = 'job'
        AND p.post_status IN ('publish', 'draft')
        AND pm_guid.meta_value IS NULL
        AND (
            pm_last_import.meta_value IS NULL
            OR pm_last_import.meta_value < %s
        )
        LIMIT %d
    ", $very_old_cutoff, $max_cleanup_batch));

    $deleted_count = 0;
    foreach ($orphaned_jobs as $post_id) {
        if (wp_delete_post($post_id, true)) { // Force delete
            $deleted_count++;
        }
    }

    PuntWorkLogger::info('Orphaned job cleanup completed', PuntWorkLogger::CONTEXT_IMPORT, [
        'deleted_count' => $deleted_count,
        'cutoff_date' => $very_old_cutoff,
        'batch_size' => $max_cleanup_batch
    ]);

    return ['deleted_count' => $deleted_count];
}

/**
 * Legacy cleanup - hard deletes old posts
 */
function legacy_cleanup_old_posts() {
    global $wpdb;

    $cutoff_date = date('Y-m-d H:i:s', strtotime('-30 days'));

    // Get old published jobs
    $old_jobs = $wpdb->get_col($wpdb->prepare("
        SELECT p.ID
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_last_import_update'
        WHERE p.post_type = 'job'
        AND p.post_status = 'publish'
        AND (pm.meta_value IS NULL OR pm.meta_value < %s)
        LIMIT 500
    ", $cutoff_date));

    $deleted_count = 0;
    foreach ($old_jobs as $post_id) {
        if (wp_delete_post($post_id, true)) {
            $deleted_count++;
        }
    }

    return [
        'action' => 'auto_delete',
        'deleted_count' => $deleted_count,
        'cutoff_date' => $cutoff_date
    ];
}

/**
 * Get cleanup statistics for reporting
 */
function get_cleanup_statistics() {
    global $wpdb;

    $stats = $wpdb->get_row("
        SELECT
            COUNT(CASE WHEN p.post_status = 'publish' THEN 1 END) as active_jobs,
            COUNT(CASE WHEN p.post_status = 'draft' AND pm_expired.meta_value IS NOT NULL THEN 1 END) as expired_jobs,
            COUNT(*) as total_jobs
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm_expired ON p.ID = pm_expired.post_id AND pm_expired.meta_key = '_expired_at'
        WHERE p.post_type = 'job'
    ", ARRAY_A);

    // Get age distribution
    $age_stats = $wpdb->get_results("
        SELECT
            CASE
                WHEN pm_last.meta_value > DATE_SUB(NOW(), INTERVAL 1 DAY) THEN '1_day'
                WHEN pm_last.meta_value > DATE_SUB(NOW(), INTERVAL 7 DAY) THEN '7_days'
                WHEN pm_last.meta_value > DATE_SUB(NOW(), INTERVAL 30 DAY) THEN '30_days'
                WHEN pm_last.meta_value > DATE_SUB(NOW(), INTERVAL 90 DAY) THEN '90_days'
                ELSE 'older'
            END as age_group,
            COUNT(*) as count
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm_last ON p.ID = pm_last.post_id AND pm_last.meta_key = '_last_import_update'
        WHERE p.post_type = 'job'
        AND p.post_status IN ('publish', 'draft')
        GROUP BY age_group
    ", ARRAY_A);

    $config = get_import_config();

    return [
        'current_stats' => $stats,
        'age_distribution' => $age_stats,
        'config' => $config['cleanup'],
        'next_cleanup_estimate' => estimate_next_cleanup()
    ];
}

/**
 * Estimate when next cleanup will run
 */
function estimate_next_cleanup() {
    global $wpdb;

    // Find old jobs that would be affected
    $retention_days = get_import_config_value('cleanup.retention_days', 90);
    $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$retention_days} days"));

    $expiring_soon = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*)
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_last_import_update'
        WHERE p.post_type = 'job'
        AND p.post_status = 'publish'
        AND pm.meta_value < DATE_SUB(%s, INTERVAL -7 DAY)
        AND pm.meta_value > %s
    ", $cutoff_date, $cutoff_date));

    return [
        'expiring_within_7_days' => $expiring_soon,
        'retention_days' => $retention_days,
        'cleanup_strategy' => get_import_config_value('cleanup.strategy', 'smart_retention')
    ];
}

/**
 * Clean up old job posts using direct SQL for maximum performance.
 * WARNING: This bypasses WordPress hooks and should only be used when wp_delete_post is too slow.
 * Use with extreme caution and thorough testing.
 *
 * @param array $post_ids Array of post IDs to delete
 * @return int Number of posts deleted
 */
function bulk_delete_job_posts_sql($post_ids) {
    global $wpdb;

    if (empty($post_ids)) {
        return 0;
    }

    $post_ids = array_map('intval', $post_ids);
    $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));

    // Begin transaction for data integrity
    $wpdb->query('START TRANSACTION');

    try {
        // Delete postmeta first (required before deleting posts)
        $wpdb->query($wpdb->prepare("
            DELETE FROM {$wpdb->postmeta}
            WHERE post_id IN ({$placeholders})
        ", $post_ids));

        // Delete term relationships
        $wpdb->query($wpdb->prepare("
            DELETE FROM {$wpdb->term_relationships}
            WHERE object_id IN ({$placeholders})
        ", $post_ids));

        // Delete posts
        $result = $wpdb->query($wpdb->prepare("
            DELETE FROM {$wpdb->posts}
            WHERE ID IN ({$placeholders}) AND post_type = 'job'
        ", $post_ids));

        // Commit transaction
        $wpdb->query('COMMIT');

        return $result;

    } catch (\Exception $e) {
        // Rollback on error
        $wpdb->query('ROLLBACK');
        PuntWorkLogger::error('Bulk delete failed, rolled back', PuntWorkLogger::CONTEXT_BATCH, [
            'error' => $e->getMessage(),
            'post_count' => count($post_ids)
        ]);
        return 0;
    }
}
