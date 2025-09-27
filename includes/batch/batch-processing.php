<?php

/**
 * Batch processing utilities
 *
 * @package    Puntwork
 * @subpackage Batch
 * @since      1.0.0
 */

declare(strict_types=1);

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

use Puntwork\Utilities\CacheManager;
use Puntwork\Utilities\EnhancedCacheManager;

/**
 * Batch processing logic
 * Handles the core batch processing operations for job imports
 */

// Include database optimization utilities
require_once __DIR__ . '/../utilities/database-optimization.php';

// Include duplicate handling utilities
require_once __DIR__ . '/../utilities/handle-duplicates.php';

// Include performance monitoring utilities
require_once __DIR__ . '/../utilities/performance-functions.php';

// Include batch size management utilities
require_once __DIR__ . '/batch-size-management.php';

// Include async processing utilities
require_once __DIR__ . '/../utilities/async-processing.php';

// Include tracing utilities
require_once __DIR__ . '/../utilities/PuntworkTracing.php';

// Include job deduplicator utilities
require_once __DIR__ . '/../utilities/JobDeduplicator.php';

// Include advanced memory manager utilities
require_once __DIR__ . '/../utilities/AdvancedMemoryManager.php';

// Include base memory manager utilities
require_once __DIR__ . '/../utilities/MemoryManager.php';

// Include utility helpers
require_once __DIR__ . '/../utilities/utility-helpers.php';
class JsonlIterator implements \Iterator
{
    private string $filePath;
    private int $startIndex;
    private int $batchSize;
    private $handle;
    private int $currentIndex = 0;
    private int $loadedCount = 0;
    private $currentItem = null;
    private int $key = 0;

    public function __construct(string $filePath, int $startIndex, int $batchSize)
    {
        $this->filePath = $filePath;
        $this->startIndex = $startIndex;
        $this->batchSize = $batchSize;
    }

    public function rewind(): void
    {
        if ($this->handle) {
            fclose($this->handle);
        }
        $this->handle = fopen($this->filePath, 'r');
        $this->currentIndex = 0;
        $this->loadedCount = 0;
        $this->key = 0;
        $this->currentItem = null;
        $this->skipToStart();
    }

    private function skipToStart(): void
    {
        while ($this->currentIndex < $this->startIndex && ($line = fgets($this->handle)) !== false) {
            $this->currentIndex++;
        }
    }

    #[\ReturnTypeWillChange]
    public function current()
    {
        return $this->currentItem;
    }

    public function key(): int
    {
        return $this->key;
    }

    public function next(): void
    {
        $this->key++;
        $this->currentItem = null;

        if ($this->loadedCount >= $this->batchSize) {
            return;
        }

        while (($line = fgets($this->handle)) !== false) {
            $this->currentIndex++;
            $line = trim($line);
            if (!empty($line)) {
                $item = json_decode($line, true);
                if ($item !== null) {
                    $this->currentItem = $item;
                    $this->loadedCount++;
                    return;
                }
            }
        }
    }

    public function valid(): bool
    {
        return $this->currentItem !== null && $this->loadedCount <= $this->batchSize;
    }

    public function __destruct()
    {
        if ($this->handle) {
            fclose($this->handle);
        }
    }
}

/**
 * Batch processing logic
 * Handles the core batch processing operations for job imports
 */

/**
 * Process batch items and handle imports.
 *
 * @param  array $setup Setup data from prepare_import_setup.
 * @return array Processing results.
 */
function process_batch_items_logic(array $setup): array
{
    error_log('=== PUNTWORK BATCH DEBUG: process_batch_items_logic STARTED ===');
    error_log(
        '[PUNTWORK] process_batch_items_logic called with setup: ' . json_encode(
            [
            'start_index' => $setup['start_index'] ?? 'not set',
            'total' => $setup['total'] ?? 'not set',
            'json_path' => isset($setup['json_path']) ? basename($setup['json_path']) : 'not set'
            ]
        )
    );

    // Start tracing span for batch processing (only if available)
    $span = null;
    if (class_exists('\Puntwork\PuntworkTracing')) {
        $span = \Puntwork\PuntworkTracing::startActiveSpan(
            'process_batch_items_logic', [
            'batch.start_index' => $setup['start_index'] ?? 0,
            'batch.total' => $setup['total'] ?? 0,
            'batch.json_path' => $setup['json_path'] ?? ''
            ]
        );
    }

    try {
        error_log('[PUNTWORK] Starting performance monitoring');
        // Start performance monitoring
        $perf_id = start_performance_monitoring('batch_import');
        error_log('[PUNTWORK] Performance monitoring started with ID: ' . $perf_id);

        // Start database performance monitoring
        start_db_performance_monitoring();
        error_log('[PUNTWORK] Database performance monitoring started');

        // Optimize memory for large batch
        optimize_memory_for_batch();
        error_log('[PUNTWORK] Memory optimization completed');

        // Reset memory manager
        reset_memory_manager();
        error_log('[PUNTWORK] Memory manager reset completed');

        extract($setup);

        $batch_start_time = microtime(true); // Record start time for this batch

        // Validate and adjust batch size based on performance metrics
        $batch_size_info = validate_and_adjust_batch_size($setup);
        $batch_size = $batch_size_info['batch_size'];
        $logs = $batch_size_info['logs'];

        // Re-align start_index with new batch_size to avoid skips
        // Removed to prevent stuck imports when batch_size changes

        $end_index = min($setup['start_index'] + $batch_size, $setup['total']);
        $published = 0;
        $updated = 0;
        $skipped = 0;
        $duplicates_drafted = 0;
        $inferred_languages = 0;
        $inferred_benefits = 0;
        $schema_generated = 0;

        try {
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Starting batch from {$setup['start_index']} to $end_index (size $batch_size)";

            // Checkpoint: Batch setup complete
            checkpoint_performance(
                $perf_id, 'batch_setup', [
                'batch_size' => $batch_size,
                'start_index' => $setup['start_index'],
                'end_index' => $end_index
                ]
            );

            // Load and prepare batch items from JSONL
            $batch_load_info = load_and_prepare_batch_items($json_path, $setup['start_index'], $batch_size, $batch_size_info['threshold'], $logs);
            $batch_items = $batch_load_info['batch_items'];
            $batch_guids = $batch_load_info['batch_guids'];

            // Checkpoint: Batch items loaded
            checkpoint_performance(
                $perf_id, 'batch_loaded', [
                'items_loaded' => count($batch_guids),
                'memory_usage' => memory_get_usage(true)
                ]
            );

            if ($batch_load_info['cancelled']) {
                update_option('job_import_progress', $end_index, false);
                update_option('job_import_processed_guids', $processed_guids, false);
                $time_elapsed = microtime(true) - $setup['start_time'];
                $batch_time = microtime(true) - $batch_start_time; // Calculate actual batch processing time

                // End performance monitoring
                $perf_data = end_performance_monitoring($perf_id);

                // Update import status for UI polling
                $current_status = get_option('job_import_status', []);
                $current_status['total'] = $setup['total'];
                $current_status['processed'] = $end_index;
                $current_status['published'] = $current_status['published'] ?? 0;
                $current_status['updated'] = $current_status['updated'] ?? 0;
                $current_status['skipped'] = ($current_status['skipped'] ?? 0) + $skipped;
                $current_status['duplicates_drafted'] = $current_status['duplicates_drafted'] ?? 0;
                $current_status['time_elapsed'] = $time_elapsed;
                $current_status['complete'] = ($end_index >= $setup['total']);
                $current_status['success'] = true;
                $current_status['error_message'] = '';
                $current_status['batch_size'] = $batch_size;
                $current_status['inferred_languages'] = ($current_status['inferred_languages'] ?? 0) + $inferred_languages;
                $current_status['inferred_benefits'] = ($current_status['inferred_benefits'] ?? 0) + $inferred_benefits;
                $current_status['schema_generated'] = ($current_status['schema_generated'] ?? 0) + $schema_generated;
                $current_status['start_time'] = $setup['start_time'];
                $current_status['end_time'] = $current_status['complete'] ? microtime(true) : null;
                $current_status['last_update'] = time();
                $current_status['logs'] = array_slice($logs, -50);
                update_option('job_import_status', $current_status, false);

                if ($span) {
                    $span->setAttribute('batch.cancelled', true);
                    $span->end();
                }

                return [
                    'success' => true,
                    'processed' => $end_index,
                    'total' => $setup['total'],
                    'published' => $published,
                    'updated' => $updated,
                    'skipped' => $skipped,
                    'duplicates_drafted' => $duplicates_drafted,
                    'time_elapsed' => $time_elapsed,
                    'complete' => ($end_index >= $setup['total']),
                    'logs' => $logs,
                    'batch_size' => $batch_size,
                    'inferred_languages' => $inferred_languages,
                    'inferred_benefits' => $inferred_benefits,
                    'schema_generated' => $schema_generated,
                    'batch_time' => $batch_time,
                    'batch_processed' => 0,
                    'performance' => $perf_data,
                    'message' => '' // No error message for success
                ];
            }

            // Process batch items
            $result = process_batch_data($batch_guids, $batch_items, $logs, $published, $updated, $skipped, $duplicates_drafted);

            // Checkpoint: Batch processing complete
            checkpoint_performance(
                $perf_id, 'batch_processed', [
                'items_processed' => $result['processed_count'],
                'published' => $published,
                'updated' => $updated,
                'skipped' => $skipped,
                'duplicates_drafted' => $duplicates_drafted
                ]
            );

            unset($batch_items, $batch_guids);

            update_option('job_import_progress', $end_index, false);
            update_option('job_import_processed_guids', $processed_guids, false);
            $time_elapsed = microtime(true) - $setup['start_time'];
            $batch_time = microtime(true) - $batch_start_time; // Calculate actual batch processing time
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Batch complete: Processed {$result['processed_count']} items (published: $published, updated: $updated, skipped: $skipped, duplicates: $duplicates_drafted)";

            // Update performance metrics with batch time, not total time
            update_batch_metrics($batch_time, $result['processed_count'], $batch_size);

            // Store batch timing data for status retrieval
            update_option('job_import_last_batch_time', $batch_time, false);
            update_option('job_import_last_batch_processed', $result['processed_count'], false);

            // End performance monitoring
            $perf_data = end_performance_monitoring($perf_id);

            // End database performance monitoring
            $db_perf_data = end_db_performance_monitoring();

            // Include DB performance in main performance data
            $perf_data['database'] = $db_perf_data;

            // Update import status for UI polling
            $current_status = get_option('job_import_status', []);
            $current_status['total'] = $setup['total'];
            $current_status['processed'] = $end_index;
            $current_status['published'] = ($current_status['published'] ?? 0) + $published;
            $current_status['updated'] = ($current_status['updated'] ?? 0) + $updated;
            $current_status['skipped'] = ($current_status['skipped'] ?? 0) + $skipped;
            $current_status['duplicates_drafted'] = ($current_status['duplicates_drafted'] ?? 0) + $duplicates_drafted;
            $current_status['time_elapsed'] = $time_elapsed;
            $current_status['complete'] = ($end_index >= $setup['total']);
            $current_status['success'] = true;
            $current_status['error_message'] = '';
            $current_status['batch_size'] = $batch_size;
            $current_status['inferred_languages'] = ($current_status['inferred_languages'] ?? 0) + $inferred_languages;
            $current_status['inferred_benefits'] = ($current_status['inferred_benefits'] ?? 0) + $inferred_benefits;
            $current_status['schema_generated'] = ($current_status['schema_generated'] ?? 0) + $schema_generated;
            $current_status['start_time'] = $setup['start_time'];
            $current_status['end_time'] = $current_status['complete'] ? microtime(true) : null;
            $current_status['last_update'] = time();
            $current_status['logs'] = array_slice($logs, -50); // Keep last 50 log entries
            update_option('job_import_status', $current_status, false);

            // Schedule async analytics update for better performance
            $analytics_data = [
                'import_id' => wp_generate_uuid4(),
                'start_time' => $setup['start_time'],
                'end_time' => microtime(true),
                'batch_time' => $batch_time,
                'total' => $setup['total'],
                'processed' => $result['processed_count'],
                'published' => $published,
                'updated' => $updated,
                'skipped' => $skipped,
                'duplicates_drafted' => $duplicates_drafted,
                'performance' => $perf_data,
                'message' => ''
            ];
            schedule_async_analytics_update($analytics_data);

            return [
                'success' => true,
                'processed' => $end_index,
                'total' => $setup['total'],
                'published' => $published,
                'updated' => $updated,
                'skipped' => $skipped,
                'duplicates_drafted' => $duplicates_drafted,
                'time_elapsed' => $time_elapsed,
                'complete' => ($end_index >= $setup['total']),
                'logs' => $logs,
                'batch_size' => $batch_size,
                'inferred_languages' => $inferred_languages,
                'inferred_benefits' => $inferred_benefits,
                'schema_generated' => $schema_generated,
                'batch_time' => $batch_time,  // Time for this specific batch
                'batch_processed' => $result['processed_count'],  // Items processed in this batch
                'start_time' => $setup['start_time'],
                'performance' => $perf_data,
                'message' => '' // No error message for success
            ];
        } catch (\Exception $e) {
            // End performance monitoring on error
            $perf_data = end_performance_monitoring($perf_id);

            $error_msg = 'Batch import error: ' . $e->getMessage();
            error_log($error_msg);
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . $error_msg;

            if ($span) {
                $span->recordException($e);
                $span->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR, $e->getMessage());
                $span->end();
            }

            return [
            'success' => false,
            'message' => 'Batch failed: ' . $e->getMessage(),
            'logs' => $logs,
            'performance' => $perf_data
            ];
        }
    } catch (\Exception $e) {
        // Handle outer try exceptions (setup/initialization errors)
        if ($span) {
            $span->recordException($e);
            $span->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR, $e->getMessage());
            $span->end();
        }

        return [
        'success' => false,
        'message' => 'Batch setup failed: ' . $e->getMessage(),
        'logs' => [],
        'performance' => null
        ];
    }
}

/**
 * Validate and adjust batch size based on performance metrics.
 *
 * @param  array $setup Setup data.
 * @return array Adjusted setup with batch_size and logs.
 */
function validate_and_adjust_batch_size(array $setup): array
{
    $memory_limit_bytes = get_memory_limit_bytes();
    $threshold = 0.6 * $memory_limit_bytes;
    $batch_size = get_option('job_import_batch_size') ?: 100;
    
    // Ensure batch_size is at least 1
    $batch_size = max(1, (int)$batch_size);
    
    $old_batch_size = $batch_size;
    $prev_time_per_item = get_option('job_import_time_per_job', 0);
    $avg_time_per_item = get_option('job_import_avg_time_per_job', $prev_time_per_item);
    $last_peak_memory = get_option('job_import_last_peak_memory', $memory_limit_bytes);
    $last_memory_ratio = $last_peak_memory / $memory_limit_bytes;

    $current_batch_time = get_option('job_import_last_batch_time', 0);
    $previous_batch_time = get_option('job_import_previous_batch_time', 0);

    $adjustment_result = adjust_batch_size($batch_size, $memory_limit_bytes, $last_memory_ratio, $current_batch_time, $previous_batch_time);
    $batch_size = $adjustment_result['batch_size'];

    $logs = [];
    if ($batch_size != $old_batch_size) {
        update_option('job_import_batch_size', $batch_size, false);
        $reason = '';
        if ($last_memory_ratio > 0.85) {
            $reason = 'high previous memory';
        } elseif ($last_memory_ratio < 0.5) {
            $reason = 'low previous memory and low avg time';
        } elseif ($current_batch_time > $previous_batch_time) {
            $reason = 'current batch slower than previous';
        } elseif ($current_batch_time < $previous_batch_time) {
            $reason = 'current batch faster than previous';
        }
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Batch size adjusted to ' . $batch_size . ' due to ' . $reason;
        if (!empty($adjustment_result['reason'])) {
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Reason: ' . $adjustment_result['reason'];
        }
    }

    return [
        'batch_size' => $batch_size,
        'threshold' => $threshold,
        'logs' => $logs
    ];
}

/**
 * Prepare batch processing variables.
 *
 * @param  array $setup      Original setup.
 * @param  int   $batch_size Adjusted batch size.
 * @return array Prepared variables.
 */
function prepare_batch_processing(array $setup, int $batch_size): array
{
    $end_index = min($setup['start_index'] + $batch_size, $setup['total']);

    return [
        'end_index' => $end_index,
        'published' => 0,
        'updated' => 0,
        'skipped' => 0,
        'duplicates_drafted' => 0,
        'inferred_languages' => 0,
        'inferred_benefits' => 0,
        'schema_generated' => 0
    ];
}

/**
 * Load and prepare batch items from JSONL.
 *
 * @param  string $json_path   Path to JSONL file.
 * @param  int    $start_index Start index.
 * @param  int    $batch_size  Batch size.
 * @param  int    $threshold   Memory threshold.
 * @param  array  &$logs       Logs array.
 * @return array Prepared batch data.
 */
function load_and_prepare_batch_items(string $json_path, int $start_index, int $batch_size, float $threshold, array &$logs): array
{
    error_log(
        '[PUNTWORK] load_and_prepare_batch_items called with: ' . json_encode(
            [
            'json_path' => basename($json_path),
            'start_index' => $start_index,
            'batch_size' => $batch_size,
            'file_exists' => file_exists($json_path)
            ]
        )
    );

    $batch_json_items = load_json_batch($json_path, $start_index, $batch_size);
    error_log('[PUNTWORK] Loaded ' . count($batch_json_items) . ' items from JSONL');

    $batch_items = [];
    $batch_guids = [];
    $loaded_count = count($batch_json_items);

    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Loaded $loaded_count items from JSONL (batch size: $batch_size)";

    if ($loaded_count === 0) {
        error_log('[PUNTWORK] No items loaded from JSONL - file may be empty or corrupted');
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'WARNING: No items loaded from JSONL file - check file integrity';
        return [
            'batch_items' => $batch_items,
            'batch_guids' => $batch_guids,
            'cancelled' => false
        ];
    }

    $valid_items = 0;
    $skipped_items = 0;
    $missing_guids = 0;

    for ($i = 0; $i < count($batch_json_items); $i++) {
        $current_index = $start_index + $i;

        if (get_transient('import_cancel') === true) {
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Import cancelled at #' . ($current_index + 1);
            update_option('job_import_progress', $current_index, false);
            return ['cancelled' => true, 'logs' => $logs];
        }

        $item = $batch_json_items[$i];
        $guid = $item['guid'] ?? '';

        if (empty($guid)) {
            $missing_guids++;
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Skipped #' . ($current_index + 1) . ': Empty GUID - Item keys: ' . implode(', ', array_keys($item));
            continue;
        }

        $batch_guids[] = $guid;
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Processing #' . ($current_index + 1) . ' GUID: ' . $guid;
        $batch_items[$guid] = ['item' => $item, 'index' => $current_index];
        $valid_items++;

        // Enhanced memory management
        $memory_status = check_batch_memory_usage($current_index, $threshold * 0.8); // More aggressive threshold
        if (!empty($memory_status['actions_taken'])) {
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Memory management: ' . implode(', ', $memory_status['actions_taken']);
        }

        // Memory check
        if (memory_get_usage(true) > $threshold) {
            $batch_size = max(1, (int)($batch_size * 0.8));
            update_option('job_import_batch_size', $batch_size, false);
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Memory high, reduced batch to ' . $batch_size;
        }

        if ($i % 5 === 0) {
            ob_flush();
            flush();
        }
        unset($batch_json_items[$i]);
    }
    unset($batch_json_items);

    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Prepared $valid_items valid items for processing (skipped $skipped_items items, $missing_guids missing GUIDs)";

    error_log('[PUNTWORK] Prepared ' . $valid_items . ' valid items for processing (skipped: ' . $skipped_items . ', missing GUIDs: ' . $missing_guids . ')');
    return [
        'batch_items' => $batch_items,
        'batch_guids' => $batch_guids,
        'cancelled' => false
    ];
}

/**
 * Process batch data including duplicates and item processing.
 *
 * @param  array $batch_guids         Array of GUIDs in batch.
 * @param  array $batch_items         Array of batch items.
 * @param  array &$logs               Reference to logs array.
 * @param  int   &$published          Reference to published count.
 * @param  int   &$updated            Reference to updated count.
 * @param  int   &$skipped            Reference to skipped count.
 * @param  int   &$duplicates_drafted Reference to duplicates drafted count.
 * @return array Processing result.
 */
function process_batch_data(array $batch_guids, array $batch_items, array &$logs, int &$published, int &$updated, int &$skipped, int &$duplicates_drafted): array
{
    error_log('[PUNTWORK] process_batch_data called with ' . count($batch_guids) . ' GUIDs');
    
    if (empty($batch_guids)) {
        error_log('[PUNTWORK] ERROR: process_batch_data called with empty batch_guids!');
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'ERROR: No GUIDs to process in this batch';
        return ['processed_count' => 0];
    }

    global $wpdb;

    try {
        // Use optimized function to get posts by GUIDs with status
        $existing_by_guid = get_posts_by_guids_with_status($batch_guids);
        error_log('[PUNTWORK] Got existing posts by GUID: ' . count($existing_by_guid));
    } catch (\Exception $e) {
        error_log('[PUNTWORK] Error getting existing posts: ' . $e->getMessage());
        throw $e;
    }

    $post_ids_by_guid = [];

    // Handle duplicates
    handle_batch_duplicates($batch_guids, $existing_by_guid, $logs, $duplicates_drafted, $post_ids_by_guid);
    error_log('[PUNTWORK] Handled duplicates');

    // Prepare batch metadata
    $batch_metadata = prepare_batch_metadata($post_ids_by_guid);
    error_log('[PUNTWORK] Prepared batch metadata');

    // Process items
    $processed_count = process_batch_items_with_metadata($batch_guids, $batch_items, $batch_metadata, $post_ids_by_guid, $logs, $updated, $published, $skipped);
    error_log('[PUNTWORK] Processed batch items, count=' . $processed_count);

    return ['processed_count' => $processed_count];
}

/**
 * Handle duplicates for the batch.
 */
function handle_batch_duplicates(array $batch_guids, array $existing_by_guid, array &$logs, int &$duplicates_drafted, array &$post_ids_by_guid): void
{
    // Use advanced deduplication if available and enabled
    if (class_exists('Puntwork\\JobDeduplicator') && apply_filters('puntwork_use_advanced_deduplication', true)) {
        \Puntwork\JobDeduplicator::handleDuplicatesAdvanced($batch_guids, $existing_by_guid, $logs, $duplicates_drafted, $post_ids_by_guid);
    } else {
        // Fallback to original deduplication logic
        handle_duplicates($batch_guids, $existing_by_guid, $logs, $duplicates_drafted, $post_ids_by_guid);
    }
}

/**
 * Prepare metadata for batch processing.
 */
function prepare_batch_metadata(array $post_ids_by_guid): array
{
    global $wpdb;

    $post_ids = array_values($post_ids_by_guid);
    if (empty($post_ids)) {
        return ['last_updates' => [], 'hashes_by_post' => []];
    }

    $max_chunk_size = 50;
    $post_id_chunks = array_chunk($post_ids, $max_chunk_size);

    // Get last updates with caching
    $last_updates = get_cached_last_updates($post_ids, $post_id_chunks);

    // Get import hashes with caching
    $hashes_by_post = get_cached_import_hashes($post_ids, $post_id_chunks);

    return [
        'last_updates' => $last_updates,
        'hashes_by_post' => $hashes_by_post
    ];
}

/**
 * Get cached last updates for posts.
 */
function get_cached_last_updates(array $post_ids, array $post_id_chunks): array
{
    global $wpdb;

    sort($post_ids);
    $cache_key = 'batch_last_updates_' . md5(implode(',', $post_ids));
    $cached = \Puntwork\Utilities\CacheManager::get($cache_key, \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS);

    if ($cached !== false) {
        return $cached;
    }

    $last_updates = [];
    foreach ($post_id_chunks as $chunk) {
        if (empty($chunk)) {
            continue;
        }
        $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
        $chunk_last = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_last_import_update' AND post_id IN ($placeholders)",
                $chunk
            ), OBJECT_K
        );
        $last_updates += (array)$chunk_last;
    }

    // Cache for 5 minutes during import processing
    \Puntwork\Utilities\CacheManager::set($cache_key, $last_updates, \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS, 5 * MINUTE_IN_SECONDS);
    return $last_updates;
}

/**
 * Get cached import hashes for posts.
 */
function get_cached_import_hashes(array $post_ids, array $post_id_chunks): array
{
    global $wpdb;

    $cache_key = 'batch_import_hashes_' . md5(implode(',', $post_ids));
    $cached = \Puntwork\Utilities\CacheManager::get($cache_key, \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS);

    if ($cached !== false) {
        return $cached;
    }

    $hashes_by_post = [];
    foreach ($post_id_chunks as $chunk) {
        if (empty($chunk)) {
            continue;
        }
        $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
        $chunk_hashes = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_import_hash' AND post_id IN ($placeholders)",
                $chunk
            ), OBJECT_K
        );
        foreach ($chunk_hashes as $id => $obj) {
            $hashes_by_post[$id] = $obj->meta_value;
        }
    }

    // Cache for 5 minutes during import processing
    \Puntwork\Utilities\CacheManager::set($cache_key, $hashes_by_post, \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS, 5 * MINUTE_IN_SECONDS);
    return $hashes_by_post;
}

/**
 * Process batch items with prepared metadata.
 */
function process_batch_items_with_metadata(array $batch_guids, array $batch_items, array $batch_metadata, array $post_ids_by_guid, array &$logs, int &$updated, int &$published, int &$skipped): int
{
    $processed_count = 0;
    $acf_fields = get_acf_fields();
    $zero_empty_fields = get_zero_empty_fields();

    process_batch_items($batch_guids, $batch_items, $batch_metadata['last_updates'], $batch_metadata['hashes_by_post'], $acf_fields, $zero_empty_fields, $post_ids_by_guid, $logs, $updated, $published, $skipped, $processed_count);

    return $processed_count;
}

/**
 * Load a batch of items from JSONL file with improved performance.
 *
 * @param  string $json_path   Path to JSONL file.
 * @param  int    $start_index Starting index.
 * @param  int    $batch_size  Batch size.
 * @return array Array of JSON items.
 */
function load_json_batch($json_path, $start_index, $batch_size)
{
    // Ensure batch_size is at least 1
    $batch_size = max(1, (int)$batch_size);
    
    error_log('[PUNTWORK] load_json_batch called with: path=' . basename($json_path) . ', start_index=' . $start_index . ', batch_size=' . $batch_size);
    error_log('[PUNTWORK] load_json_batch: file exists: ' . (file_exists($json_path) ? 'yes' : 'no'));
    
    if (file_exists($json_path)) {
        error_log('[PUNTWORK] load_json_batch: file size: ' . filesize($json_path) . ' bytes');
    }

    $items = [];
    $count = 0;
    $current_index = 0;
    $lines_read = 0;
    $empty_lines = 0;
    $invalid_json = 0;

    if (($handle = fopen($json_path, "r")) !== false) {
        error_log('[PUNTWORK] load_json_batch: opened file successfully, ftell=' . ftell($handle));
        
        // Skip to start_index efficiently
        error_log('[PUNTWORK] load_json_batch: starting skip loop, current_index=' . $current_index . ', start_index=' . $start_index);
        while ($current_index < $start_index && ($line = fgets($handle)) !== false) {
            $current_index++;
            error_log('[PUNTWORK] load_json_batch: skipped line ' . $current_index . ', ftell=' . ftell($handle));
        }
        
        error_log('[PUNTWORK] load_json_batch: finished skip loop, current_index=' . $current_index . ', ftell=' . ftell($handle));

        // Read batch_size items
        error_log('[PUNTWORK] load_json_batch: starting read loop, count=' . $count . ', batch_size=' . $batch_size . ', ftell=' . ftell($handle));
        while ($count < $batch_size && ($line = fgets($handle)) !== false) {
            $lines_read++;
            error_log('[PUNTWORK] load_json_batch: read line ' . $lines_read . ', length=' . strlen($line) . ', ftell=' . ftell($handle));
            $line = trim($line);
            if (!empty($line)) {
                error_log('[PUNTWORK] load_json_batch: line not empty, attempting JSON decode');
                $item = json_decode($line, true);
                if ($item !== null) {
                    $items[] = $item;
                    $count++;
                    error_log('[PUNTWORK] load_json_batch: Successfully decoded item ' . $count . ' with GUID: ' . ($item['guid'] ?? 'MISSING'));
                } else {
                    $invalid_json++;
                    error_log('[PUNTWORK] load_json_batch: Failed to decode JSON at line ' . ($current_index + $lines_read) . ': ' . json_last_error_msg() . ' - Line content: ' . substr($line, 0, 100));
                }
            } else {
                $empty_lines++;
                error_log('[PUNTWORK] load_json_batch: empty line skipped');
            }
            $current_index++;
        }
        
        error_log('[PUNTWORK] load_json_batch: finished read loop, lines_read=' . $lines_read . ', empty=' . $empty_lines . ', invalid JSON=' . $invalid_json . ', valid items=' . $count . ', ftell=' . ftell($handle));
        fclose($handle);
    } else {
        error_log('[PUNTWORK] load_json_batch: failed to open file');
    }

    error_log('[PUNTWORK] load_json_batch: returning ' . count($items) . ' items');
    return $items;
}

/**
 * Enhanced batch processing with advanced caching and memory management
 */
function process_batch_enhanced(array $batch_guids, array $batch_items, array &$logs, int &$published, int &$updated, int &$skipped, int &$duplicates_drafted): array
{
    $start_time = microtime(true);

    // Initialize enhanced monitoring
    $monitor_id = start_performance_monitoring('enhanced_batch_processing');

    try {
        // Warm up caches for better performance
        \Puntwork\Utilities\EnhancedCacheManager::warmCommonCaches();

        // Get existing posts with enhanced caching
        $existing_by_guid = get_posts_by_guids_with_status_enhanced($batch_guids);

        $post_ids_by_guid = [];

        // Handle duplicates with circuit breaker protection
        handle_batch_duplicates_enhanced($batch_guids, $existing_by_guid, $logs, $duplicates_drafted, $post_ids_by_guid);

        // Prepare batch metadata with advanced caching
        $batch_metadata = prepare_batch_metadata_enhanced($post_ids_by_guid);

        // Process items with memory management
        $processed_count = process_batch_items_with_memory_management($batch_guids, $batch_items, $batch_metadata, $post_ids_by_guid, $logs, $updated, $published, $skipped);

        $processing_time = microtime(true) - $start_time;

        checkpoint_performance(
            $monitor_id, 'batch_completed', [
            'processed_count' => $processed_count,
            'processing_time' => $processing_time,
            'memory_peak' => memory_get_peak_usage(true)
            ]
        );

        end_performance_monitoring($monitor_id);

        return [
            'processed_count' => $processed_count,
            'processing_time' => $processing_time
        ];
    } catch (\Exception $e) {
        end_performance_monitoring($monitor_id);
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Enhanced batch processing failed: ' . $e->getMessage();
        throw $e;
    }
}

/**
 * Get posts by GUIDs with enhanced caching
 */
function get_posts_by_guids_with_status_enhanced(array $guids): array
{
    if (empty($guids)) {
        return [];
    }

    // Use enhanced caching with batch operations
    $cache_key = 'posts_by_guid_' . md5(implode(',', $guids));
    $cached = \Puntwork\Utilities\EnhancedCacheManager::getWithWarmup(
        $cache_key,
        \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS,
        function () use ($guids) {
            return get_posts_by_guids_with_status($guids);
        },
        10 * MINUTE_IN_SECONDS
    );

    return $cached;
}

/**
 * Handle duplicates with circuit breaker protection
 */
function handle_batch_duplicates_enhanced(array $batch_guids, array $existing_by_guid, array &$logs, int &$duplicates_drafted, array &$post_ids_by_guid): void
{
    // Check circuit breaker for duplicate processing
    if (!can_process_feed('duplicate_processing')) {
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Duplicate processing circuit breaker open, skipping advanced deduplication';
        handle_duplicates($batch_guids, $existing_by_guid, $logs, $duplicates_drafted, $post_ids_by_guid);
        return;
    }

    try {
        handle_batch_duplicates($batch_guids, $existing_by_guid, $logs, $duplicates_drafted, $post_ids_by_guid);
        record_feed_success('duplicate_processing');
    } catch (\Exception $e) {
        record_feed_failure('duplicate_processing');
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Advanced deduplication failed, falling back to basic: ' . $e->getMessage();
        handle_duplicates($batch_guids, $existing_by_guid, $logs, $duplicates_drafted, $post_ids_by_guid);
    }
}

/**
 * Prepare batch metadata with advanced caching strategies
 */
function prepare_batch_metadata_enhanced(array $post_ids_by_guid): array
{
    global $wpdb;

    $post_ids = array_values($post_ids_by_guid);
    if (empty($post_ids)) {
        return ['last_updates' => [], 'hashes_by_post' => []];
    }

    // Use larger chunks for better performance
    $max_chunk_size = 100; // Increased from 50
    $post_id_chunks = array_chunk($post_ids, $max_chunk_size);

    // Get last updates with enhanced caching
    $last_updates = get_cached_last_updates_enhanced($post_ids, $post_id_chunks);

    // Get import hashes with enhanced caching
    $hashes_by_post = get_cached_import_hashes_enhanced($post_ids, $post_id_chunks);

    return [
        'last_updates' => $last_updates,
        'hashes_by_post' => $hashes_by_post
    ];
}

/**
 * Enhanced cached last updates with batch operations
 */
function get_cached_last_updates_enhanced(array $post_ids, array $post_id_chunks): array
{
    sort($post_ids);
    $cache_key = 'batch_last_updates_enhanced_' . md5(implode(',', $post_ids));

    $cached = \Puntwork\Utilities\EnhancedCacheManager::get($cache_key, \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS);
    if ($cached !== false) {
        return $cached;
    }

    $last_updates = [];
    foreach ($post_id_chunks as $chunk) {
        if (empty($chunk)) {
            continue;
        }
        $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
        $chunk_last = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_last_import_update' AND post_id IN ($placeholders)",
                $chunk
            ), OBJECT_K
        );
        $last_updates += (array)$chunk_last;
    }

    // Cache for longer period with compression for large datasets
    if (count($last_updates) > 1000) {
        \Puntwork\Utilities\EnhancedCacheManager::setCompressed($cache_key, $last_updates, \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS, 10 * MINUTE_IN_SECONDS);
    } else {
        \Puntwork\Utilities\EnhancedCacheManager::set($cache_key, $last_updates, \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS, 10 * MINUTE_IN_SECONDS);
    }

    return $last_updates;
}

/**
 * Enhanced cached import hashes with batch operations
 */
function get_cached_import_hashes_enhanced(array $post_ids, array $post_id_chunks): array
{
    $cache_key = 'batch_import_hashes_enhanced_' . md5(implode(',', $post_ids));

    $cached = \Puntwork\Utilities\EnhancedCacheManager::get($cache_key, \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS);
    if ($cached !== false) {
        return $cached;
    }

    $hashes_by_post = [];
    foreach ($post_id_chunks as $chunk) {
        if (empty($chunk)) {
            continue;
        }
        $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
        $chunk_hashes = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_import_hash' AND post_id IN ($placeholders)",
                $chunk
            ), OBJECT_K
        );
        foreach ($chunk_hashes as $id => $obj) {
            $hashes_by_post[$id] = $obj->meta_value;
        }
    }

    // Cache for longer period with compression for large datasets
    if (count($hashes_by_post) > 1000) {
        \Puntwork\Utilities\EnhancedCacheManager::setCompressed($cache_key, $hashes_by_post, \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS, 10 * MINUTE_IN_SECONDS);
    } else {
        \Puntwork\Utilities\EnhancedCacheManager::set($cache_key, $hashes_by_post, \Puntwork\Utilities\CacheManager::GROUP_ANALYTICS, 10 * MINUTE_IN_SECONDS);
    }

    return $hashes_by_post;
}

/**
 * Process batch items with advanced memory management
 */
function process_batch_items_with_memory_management(array $batch_guids, array $batch_items, array $batch_metadata, array $post_ids_by_guid, array &$logs, int &$updated, int &$published, int &$skipped): int
{
    $processed_count = 0;
    $batch_size = count($batch_guids);

    // Predict memory usage and adjust batch size if needed
    $memory_prediction = \Puntwork\Utilities\AdvancedMemoryManager::predictMemoryUsage($batch_size);

    if ($memory_prediction['will_exceed_limit']) {
        $recommended_size = $memory_prediction['recommended_batch_size'];
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Predicted memory exceedance, adjusting batch size from {$batch_size} to {$recommended_size}";

        // Process in smaller chunks
        $chunks = array_chunk($batch_guids, $recommended_size, true);
        $total_processed = 0;

        foreach ($chunks as $chunk_guids) {
            $chunk_items = array_intersect_key($batch_items, array_flip($chunk_guids));
            $chunk_post_ids = array_intersect_key($post_ids_by_guid, array_flip($chunk_guids));

            $chunk_processed = process_batch_chunk($chunk_guids, $chunk_items, $batch_metadata, $chunk_post_ids, $logs, $updated, $published, $skipped);
            $total_processed += $chunk_processed;

            // Memory cleanup between chunks
            \Puntwork\Utilities\AdvancedMemoryManager::checkAndCleanup();
        }

        return $total_processed;
    }

    // Process normally with memory monitoring
    return process_batch_chunk($batch_guids, $batch_items, $batch_metadata, $post_ids_by_guid, $logs, $updated, $published, $skipped);
}

/**
 * Process a chunk of batch items
 */
function process_batch_chunk(array $batch_guids, array $batch_items, array $batch_metadata, array $post_ids_by_guid, array &$logs, int &$updated, int &$published, int &$skipped): int
{
    $processed_count = 0;
    $acf_fields = get_acf_fields();
    $zero_empty_fields = get_zero_empty_fields();

    process_batch_items($batch_guids, $batch_items, $batch_metadata['last_updates'], $batch_metadata['hashes_by_post'], $acf_fields, $zero_empty_fields, $post_ids_by_guid, $logs, $updated, $published, $skipped, $processed_count);

    return $processed_count;
}
