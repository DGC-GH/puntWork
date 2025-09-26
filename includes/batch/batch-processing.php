<?php
declare(strict_types=1);
/**
 * Batch processing utilities
 *
 * @package    Puntwork
 * @subpackage Batch
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Iterator for streaming JSONL file items to reduce memory usage.
 */
class JsonlIterator implements \Iterator {
    private string $filePath;
    private int $startIndex;
    private int $batchSize;
    private $handle;
    private int $currentIndex = 0;
    private int $loadedCount = 0;
    private $currentItem = null;
    private int $key = 0;

    public function __construct(string $filePath, int $startIndex, int $batchSize) {
        $this->filePath = $filePath;
        $this->startIndex = $startIndex;
        $this->batchSize = $batchSize;
    }

    public function rewind(): void {
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

    private function skipToStart(): void {
        while ($this->currentIndex < $this->startIndex && ($line = fgets($this->handle)) !== false) {
            $this->currentIndex++;
        }
    }

    #[\ReturnTypeWillChange]
    public function current() {
        return $this->currentItem;
    }

    public function key(): int {
        return $this->key;
    }

    public function next(): void {
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

    public function valid(): bool {
        return $this->currentItem !== null && $this->loadedCount <= $this->batchSize;
    }

    public function __destruct() {
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
 * @param array $setup Setup data from prepare_import_setup.
 * @return array Processing results.
 */
function process_batch_items_logic(array $setup): array {
    extract($setup);

    $batch_start_time = microtime(true); // Record start time for this batch

    // Validate and adjust batch size based on performance metrics
    $batch_size_info = validate_and_adjust_batch_size($setup);
    $batch_size = $batch_size_info['batch_size'];
    $logs = $batch_size_info['logs'];

    // Re-align start_index with new batch_size to avoid skips
    // Removed to prevent stuck imports when batch_size changes

    $end_index = min($start_index + $batch_size, $total);
    $published = 0;
    $updated = 0;
    $skipped = 0;
    $duplicates_drafted = 0;
    $inferred_languages = 0;
    $inferred_benefits = 0;
    $schema_generated = 0;

    try {
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Starting batch from $start_index to $end_index (size $batch_size)";

        // Load and prepare batch items from JSONL
        $batch_load_info = load_and_prepare_batch_items($json_path, $start_index, $batch_size, $batch_size_info['threshold'], $logs);
        $batch_items = $batch_load_info['batch_items'];
        $batch_guids = $batch_load_info['batch_guids'];

        if ($batch_load_info['cancelled']) {
            update_option('job_import_progress', $end_index, false);
            update_option('job_import_processed_guids', $processed_guids, false);
            $time_elapsed = microtime(true) - $start_time;
            $batch_time = microtime(true) - $batch_start_time; // Calculate actual batch processing time

            // Update import status for UI polling
            $current_status = get_option('job_import_status', []);
            $current_status['total'] = $total;
            $current_status['processed'] = $end_index;
            $current_status['published'] = $current_status['published'] ?? 0;
            $current_status['updated'] = $current_status['updated'] ?? 0;
            $current_status['skipped'] = ($current_status['skipped'] ?? 0) + $skipped;
            $current_status['duplicates_drafted'] = $current_status['duplicates_drafted'] ?? 0;
            $current_status['time_elapsed'] = $time_elapsed;
            $current_status['complete'] = ($end_index >= $total);
            $current_status['success'] = true;
            $current_status['error_message'] = '';
            $current_status['batch_size'] = $batch_size;
            $current_status['inferred_languages'] = ($current_status['inferred_languages'] ?? 0) + $inferred_languages;
            $current_status['inferred_benefits'] = ($current_status['inferred_benefits'] ?? 0) + $inferred_benefits;
            $current_status['schema_generated'] = ($current_status['schema_generated'] ?? 0) + $schema_generated;
            $current_status['start_time'] = $start_time;
            $current_status['end_time'] = $current_status['complete'] ? microtime(true) : null;
            $current_status['last_update'] = time();
            $current_status['logs'] = array_slice($logs, -50);
            update_option('job_import_status', $current_status, false);

            return [
                'success' => true,
                'processed' => $end_index,
                'total' => $total,
                'published' => $published,
                'updated' => $updated,
                'skipped' => $skipped,
                'duplicates_drafted' => $duplicates_drafted,
                'time_elapsed' => $time_elapsed,
                'complete' => ($end_index >= $total),
                'logs' => $logs,
                'batch_size' => $batch_size,
                'inferred_languages' => $inferred_languages,
                'inferred_benefits' => $inferred_benefits,
                'schema_generated' => $schema_generated,
                'batch_time' => $batch_time,
                'batch_processed' => 0,
                'message' => '' // No error message for success
            ];
        }

        // Process batch items
        $result = process_batch_data($batch_guids, $batch_items, $logs, $published, $updated, $skipped, $duplicates_drafted);

        unset($batch_items, $batch_guids);

        update_option('job_import_progress', $end_index, false);
        update_option('job_import_processed_guids', $processed_guids, false);
        $time_elapsed = microtime(true) - $start_time;
        $batch_time = microtime(true) - $batch_start_time; // Calculate actual batch processing time
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Batch complete: Processed {$result['processed_count']} items (published: $published, updated: $updated, skipped: $skipped, duplicates: $duplicates_drafted)";

        // Update performance metrics with batch time, not total time
        update_batch_metrics($batch_time, $result['processed_count'], $batch_size);

        // Store batch timing data for status retrieval
        update_option('job_import_last_batch_time', $batch_time, false);
        update_option('job_import_last_batch_processed', $result['processed_count'], false);

        // Update import status for UI polling
        $current_status = get_option('job_import_status', []);
        $current_status['total'] = $total;
        $current_status['processed'] = $end_index;
        $current_status['published'] = ($current_status['published'] ?? 0) + $published;
        $current_status['updated'] = ($current_status['updated'] ?? 0) + $updated;
        $current_status['skipped'] = ($current_status['skipped'] ?? 0) + $skipped;
        $current_status['duplicates_drafted'] = ($current_status['duplicates_drafted'] ?? 0) + $duplicates_drafted;
        $current_status['time_elapsed'] = $time_elapsed;
        $current_status['complete'] = ($end_index >= $total);
        $current_status['success'] = true;
        $current_status['error_message'] = '';
        $current_status['batch_size'] = $batch_size;
        $current_status['inferred_languages'] = ($current_status['inferred_languages'] ?? 0) + $inferred_languages;
        $current_status['inferred_benefits'] = ($current_status['inferred_benefits'] ?? 0) + $inferred_benefits;
        $current_status['schema_generated'] = ($current_status['schema_generated'] ?? 0) + $schema_generated;
        $current_status['start_time'] = $start_time;
        $current_status['end_time'] = $current_status['complete'] ? microtime(true) : null;
        $current_status['last_update'] = time();
        $current_status['logs'] = array_slice($logs, -50); // Keep last 50 log entries
        update_option('job_import_status', $current_status, false);

        return [
            'success' => true,
            'processed' => $end_index,
            'total' => $total,
            'published' => $published,
            'updated' => $updated,
            'skipped' => $skipped,
            'duplicates_drafted' => $duplicates_drafted,
            'time_elapsed' => $time_elapsed,
            'complete' => ($end_index >= $total),
            'logs' => $logs,
            'batch_size' => $batch_size,
            'inferred_languages' => $inferred_languages,
            'inferred_benefits' => $inferred_benefits,
            'schema_generated' => $schema_generated,
            'batch_time' => $batch_time,  // Time for this specific batch
            'batch_processed' => $result['processed_count'],  // Items processed in this batch
            'start_time' => $start_time,
            'message' => '' // No error message for success
        ];
    } catch (\Exception $e) {
        $error_msg = 'Batch import error: ' . $e->getMessage();
        error_log($error_msg);
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . $error_msg;
        return ['success' => false, 'message' => 'Batch failed: ' . $e->getMessage(), 'logs' => $logs];
    }
}

/**
 * Validate and adjust batch size based on performance metrics.
 *
 * @param array $setup Setup data.
 * @return array Adjusted setup with batch_size and logs.
 */
function validate_and_adjust_batch_size(array $setup): array {
    extract($setup);

    $memory_limit_bytes = get_memory_limit_bytes();
    $threshold = 0.6 * $memory_limit_bytes;
    $batch_size = get_option('job_import_batch_size') ?: 100;
    $old_batch_size = $batch_size;
    $prev_time_per_item = get_option('job_import_time_per_job', 0);
    $avg_time_per_item = get_option('job_import_avg_time_per_job', $prev_time_per_item);
    $last_peak_memory = get_option('job_import_last_peak_memory', $memory_limit_bytes);
    $last_memory_ratio = $last_peak_memory / $memory_limit_bytes;

    $current_batch_time = get_option('job_import_last_batch_time', 0);
    $previous_batch_time = get_option('job_import_previous_batch_time', 0);

    $batch_size = adjust_batch_size($batch_size, $memory_limit_bytes, $last_memory_ratio, $current_batch_time, $previous_batch_time);
    $adjustment_result = $batch_size; // Assuming adjust_batch_size returns array
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
 * @param array $setup Original setup.
 * @param int $batch_size Adjusted batch size.
 * @return array Prepared variables.
 */
function prepare_batch_processing(array $setup, int $batch_size): array {
    extract($setup);
    $end_index = min($start_index + $batch_size, $total);

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
 * @param string $json_path Path to JSONL file.
 * @param int $start_index Start index.
 * @param int $batch_size Batch size.
 * @param int $threshold Memory threshold.
 * @param array &$logs Logs array.
 * @return array Prepared batch data.
 */
function load_and_prepare_batch_items(string $json_path, int $start_index, int $batch_size, float $threshold, array &$logs): array {
    $batch_json_items = load_json_batch($json_path, $start_index, $batch_size);
    $batch_items = [];
    $batch_guids = [];
    $loaded_count = count($batch_json_items);

    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Loaded $loaded_count items from JSONL (batch size: $batch_size)";

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
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Skipped #' . ($current_index + 1) . ': Empty GUID';
            continue;
        }

        $batch_guids[] = $guid;
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Processing #' . ($current_index + 1) . ' GUID: ' . $guid;
        $batch_items[$guid] = ['item' => $item, 'index' => $current_index];

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

    $valid_items_count = count($batch_guids);
    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Prepared $valid_items_count valid items for processing (skipped " . ($loaded_count - $valid_items_count) . " items)";

    return [
        'batch_items' => $batch_items,
        'batch_guids' => $batch_guids,
        'cancelled' => false
    ];
}

/**
 * Process batch data including duplicates and item processing.
 *
 * @param array $batch_guids Array of GUIDs in batch.
 * @param array $batch_items Array of batch items.
 * @param array &$logs Reference to logs array.
 * @param int &$published Reference to published count.
 * @param int &$updated Reference to updated count.
 * @param int &$skipped Reference to skipped count.
 * @param int &$duplicates_drafted Reference to duplicates drafted count.
 * @return array Processing result.
 */
function process_batch_data(array $batch_guids, array $batch_items, array &$logs, int &$published, int &$updated, int &$skipped, int &$duplicates_drafted): array {
    global $wpdb;

    // Bulk existing post_ids
    $guid_placeholders = implode(',', array_fill(0, count($batch_guids), '%s'));
    $existing_meta = $wpdb->get_results($wpdb->prepare(
        "SELECT post_id, meta_value AS guid FROM $wpdb->postmeta WHERE meta_key = 'guid' AND meta_value IN ($guid_placeholders)",
        $batch_guids
    ));
    $existing_by_guid = [];
    foreach ($existing_meta as $row) {
        $existing_by_guid[$row->guid][] = $row->post_id;
    }

    $post_ids_by_guid = [];
    handle_duplicates($batch_guids, $existing_by_guid, $logs, $duplicates_drafted, $post_ids_by_guid);

    // Bulk fetch for all existing in batch
    $post_ids = array_values($post_ids_by_guid);
    $max_chunk_size = 50;
    $post_id_chunks = array_chunk($post_ids, $max_chunk_size);
    $last_updates = [];
    $all_hashes_by_post = [];

    if (!empty($post_ids)) {
        foreach ($post_id_chunks as $chunk) {
            if (empty($chunk)) continue;
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $chunk_last = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_last_import_update' AND post_id IN ($placeholders)",
                $chunk
            ), OBJECT_K);
            $last_updates += (array)$chunk_last;
        }

        foreach ($post_id_chunks as $chunk) {
            if (empty($chunk)) continue;
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $chunk_hashes = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_import_hash' AND post_id IN ($placeholders)",
                $chunk
            ), OBJECT_K);
            foreach ($chunk_hashes as $id => $obj) {
                $all_hashes_by_post[$id] = $obj->meta_value;
            }
        }
    }

    $processed_count = 0;
    $acf_fields = get_acf_fields();
    $zero_empty_fields = get_zero_empty_fields();

    process_batch_items($batch_guids, $batch_items, $last_updates, $all_hashes_by_post, $acf_fields, $zero_empty_fields, $post_ids_by_guid, $logs, $updated, $published, $skipped, $processed_count);

    return ['processed_count' => $processed_count];
}

/**
 * Load a batch of items from JSONL file.
 *
 * @param string $json_path Path to JSONL file.
 * @param int $start_index Starting index.
 * @param int $batch_size Batch size.
 * @return array Array of JSON items.
 */
function load_json_batch($json_path, $start_index, $batch_size) {
    $items = [];
    $count = 0;
    $current_index = 0;

    if (($handle = fopen($json_path, "r")) !== false) {
        while (($line = fgets($handle)) !== false) {
            if ($current_index >= $start_index && $count < $batch_size) {
                $line = trim($line);
                if (!empty($line)) {
                    $item = json_decode($line, true);
                    if ($item !== null) {
                        $items[] = $item;
                        $count++;
                    }
                }
            } elseif ($current_index >= $start_index + $batch_size) {
                break;
            }
            $current_index++;
        }
        fclose($handle);
    }

    return $items;
}