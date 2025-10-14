<?php
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

// Include retry utility
require_once plugin_dir_path(__FILE__) . '../utilities/retry-utility.php';

// Include options utilities for centralized option management
require_once __DIR__ . '/../utilities/options-utilities.php';

// Include import batch utilities for timeout functions
require_once __DIR__ . '/../import/import-batch.php';

// Include concurrent processing utilities
require_once __DIR__ . '/../import/process-batch-items-concurrent.php';

// Include sequential processing utilities as fallback
require_once __DIR__ . '/../import/process-batch-items.php';

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
function process_batch_items_logic($setup) {
    $batch_start_time = microtime(true);

    try {
        extract($setup);

        PuntWorkLogger::info('Starting individual item processing', PuntWorkLogger::CONTEXT_BATCH, [
            'start_index' => $start_index ?? 'unknown',
            'total' => $total ?? 'unknown',
            'batch_start_time' => $batch_start_time
        ]);

        $memory_limit_bytes = get_memory_limit_bytes();
        $threshold = 0.6 * $memory_limit_bytes;
        $batch_size = get_batch_size();
        $old_batch_size = $batch_size;
        $prev_time_per_item = get_time_per_job();
        $avg_time_per_item = get_avg_time_per_job();
        $last_peak_memory = get_last_peak_memory();
        $last_memory_ratio = $last_peak_memory / $memory_limit_bytes;

        // For concurrent processing, allow larger batches but cap to prevent overwhelming Action Scheduler
        $cpu_cores = function_exists('shell_exec') ? (int) shell_exec('nproc 2>/dev/null') : 2;
        $max_concurrent_batch_size = min(100, $cpu_cores * 10); // Cap at 100 or 10x CPU cores, whichever smaller
        $batch_size = min($batch_size, $max_concurrent_batch_size);

        // Get current and previous batch times for dynamic adjustment
        $current_batch_time = get_last_batch_time();
        $previous_batch_time = get_previous_batch_time();

        try {
            $adjustment_result = adjust_batch_size_concurrent($batch_size, $memory_limit_bytes, $last_memory_ratio, $current_batch_time, $previous_batch_time, $cpu_cores);
            $batch_size = $adjustment_result['batch_size'];
            // Ensure batch size doesn't exceed concurrent cap
            $batch_size = min($batch_size, $max_concurrent_batch_size);
        } catch (\Exception $e) {
            PuntWorkLogger::error('Failed to adjust batch size, using default', PuntWorkLogger::CONTEXT_BATCH, [
                'error' => $e->getMessage(),
                'original_batch_size' => $old_batch_size
            ]);
            $batch_size = $old_batch_size; // Fallback to original size
            $adjustment_result = ['batch_size' => $batch_size, 'reason' => 'error fallback'];
        }

        // Only update and log if changed
        if ($batch_size != $old_batch_size) {
            try {
                retry_option_operation(function() use ($batch_size) {
                    return set_batch_size($batch_size);
                }, [], [
                    'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                    'operation' => 'update_batch_size'
                ]);

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
                $logs = [];
                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Batch size adjusted to ' . $batch_size . ' due to ' . $reason;
                if (!empty($adjustment_result['reason'])) {
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Reason: ' . $adjustment_result['reason'];
                }
            } catch (\Exception $e) {
                PuntWorkLogger::error('Failed to update batch size option', PuntWorkLogger::CONTEXT_BATCH, [
                    'error' => $e->getMessage(),
                    'batch_size' => $batch_size
                ]);
                $logs = [];
            }
        } else {
            $logs = [];
        }

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

        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Starting individual processing from $start_index to $end_index (size $batch_size)";

        // Load batch from JSONL
        try {
            $batch_json_items = load_json_batch($json_path, $start_index, $batch_size);
            $batch_items = [];
            $batch_guids = [];
            $loaded_count = count($batch_json_items);

            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Loaded $loaded_count items from JSONL (batch size: $batch_size)";
        } catch (\Exception $e) {
            PuntWorkLogger::error('Failed to load JSONL batch', PuntWorkLogger::CONTEXT_BATCH, [
                'error' => $e->getMessage(),
                'json_path' => $json_path ?? 'unknown',
                'start_index' => $start_index ?? 'unknown',
                'batch_size' => $batch_size
            ]);
            return [
                'success' => false,
                'message' => 'Failed to load batch data: ' . $e->getMessage(),
                'logs' => $logs
            ];
        }

        // Progress update interval - update every 50 items or 10% of batch, whichever is smaller
        $progress_update_interval = min(50, max(10, floor($batch_size / 10)));
        $next_progress_update = $start_index + $progress_update_interval;
        $items_processed_in_batch = 0;

        // Store original count to avoid issues with unset() modifying the array during iteration
        $original_batch_count = count($batch_json_items);

        for ($i = 0; $i < $original_batch_count; $i++) {
            try {
                $current_index = $start_index + $i;

                // CRITICAL: Check for timeouts during batch processing to prevent server kills
                if ($i % 5 === 0) { // Check every 5 items (more aggressive)
                    if (import_time_exceeded() || import_memory_exceeded()) {
                        PuntWorkLogger::warning('Timeout/memory limit detected mid-batch, saving progress', PuntWorkLogger::CONTEXT_BATCH, [
                            'current_index' => $current_index,
                            'items_processed_in_batch' => $items_processed_in_batch,
                            'batch_size' => $batch_size,
                            'time_exceeded' => import_time_exceeded(),
                            'memory_exceeded' => import_memory_exceeded()
                        ]);

                        // Save partial progress before timeout
                        $partial_processed = $start_index + $items_processed_in_batch;
                        retry_option_operation(function() use ($partial_processed) {
                            return set_import_progress($partial_processed);
                        }, [], [
                            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                            'operation' => 'save_partial_progress_timeout'
                        ]);

                        // Update status to show partial completion
                        $timeout_status = get_import_status();
                        if (!is_array($timeout_status['logs'] ?? null)) {
                            $timeout_status['logs'] = [];
                        }
                        $timeout_status['processed'] = $partial_processed;
                        $timeout_status['last_update'] = time();
                        $timeout_status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Processing paused mid-batch due to time/memory limits at item ' . ($current_index + 1);
                        set_import_status($timeout_status);

                        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Processing paused mid-batch due to limits - processed ' . $items_processed_in_batch . '/' . $batch_size . ' items in this batch';

                        return [
                            'success' => true,
                            'processed' => $partial_processed,
                            'total' => $total,
                            'published' => $published,
                            'updated' => $updated,
                            'skipped' => $skipped,
                            'duplicates_drafted' => $duplicates_drafted,
                            'time_elapsed' => microtime(true) - $start_time,
                            'complete' => false,
                            'paused' => true,
                            'pause_reason' => 'mid_batch_timeout',
                            'logs' => $logs,
                            'batch_size' => $batch_size,
                            'inferred_languages' => $inferred_languages,
                            'inferred_benefits' => $inferred_benefits,
                            'schema_generated' => $schema_generated,
                            'batch_time' => microtime(true) - $batch_start_time,
                            'batch_processed' => $items_processed_in_batch,
                            'message' => 'Processing paused mid-batch due to time/memory limits'
                        ];
                    }

                    // HEARTBEAT: Update status every 5 items to prevent server timeout detection
                    if ($i % 5 === 0) {
                        $heartbeat_status = get_import_status();
                        if (!is_array($heartbeat_status['logs'] ?? null)) {
                            $heartbeat_status['logs'] = [];
                        }
                        $heartbeat_status['last_update'] = time();
                        $heartbeat_status['processed'] = $start_index + $items_processed_in_batch;
                        $heartbeat_status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Heartbeat: Processing item ' . ($current_index + 1) . '/' . $total;
                        set_import_status($heartbeat_status);
                    }
                }

                if (get_transient('import_cancel') === true) {
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Import cancelled at #' . ($current_index + 1);
                    set_import_progress($current_index);
                    // Resume cache invalidation on user cancellation
                    wp_resume_cache_invalidation();
                    return ['success' => false, 'message' => 'Import cancelled by user', 'logs' => $logs];
                }

                // Check for additional cancellation flags (force cancel and emergency stop)
                if (get_transient('import_force_cancel') === true) {
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Import force cancelled at #' . ($current_index + 1);
                    set_import_progress($current_index);
                    // Resume cache invalidation on force cancellation
                    wp_resume_cache_invalidation();
                    return ['success' => false, 'message' => 'Import force cancelled by user', 'logs' => $logs];
                }

                if (get_transient('import_emergency_stop') === true) {
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Import emergency stopped at #' . ($current_index + 1);
                    set_import_progress($current_index);
                    // Resume cache invalidation on emergency stop
                    wp_resume_cache_invalidation();
                    return ['success' => false, 'message' => 'Import emergency stopped', 'logs' => $logs];
                }

                $item = $batch_json_items[$i];
                if (!isset($item)) {
                    // Item was already processed or unset
                    continue;
                }
                $guid = $item['guid'] ?? '';

                if (empty($guid)) {
                    $skipped++;
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Skipped #' . ($current_index + 1) . ': Empty GUID';
                    continue;
                }

                $processed_guids[] = $guid;
                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Processing #' . ($current_index + 1) . ' GUID: ' . $guid;
                $batch_items[$guid] = ['item' => $item, 'index' => $current_index];
                $batch_guids[] = $guid;

                if (!empty($item['job_languages'])) $inferred_languages++;
                $benefit_count = (!empty($item['job_car']) ? 1 : 0) + (!empty($item['job_remote']) ? 1 : 0) + (!empty($item['job_meal_vouchers']) ? 1 : 0) + (!empty($item['job_flex_hours']) ? 1 : 0);
                $inferred_benefits += $benefit_count;
                if (!empty($item['job_posting']) || !empty($item['job_ecommerce'])) $schema_generated++;

                // Monitor memory usage during processing and adjust batch size if needed
                $current_memory = memory_get_usage(true);
                $current_memory_ratio = $current_memory / $memory_limit_bytes;

                // Emergency batch size reduction if memory usage is critically high
                if ($current_memory_ratio > 0.9) {
                    $emergency_batch_size = max(1, floor($batch_size * 0.5));
                    if ($emergency_batch_size < $batch_size) {
                        PuntWorkLogger::warning('Emergency batch size reduction due to high memory usage', PuntWorkLogger::CONTEXT_BATCH, [
                            'current_memory_ratio' => $current_memory_ratio,
                            'original_batch_size' => $batch_size,
                            'emergency_batch_size' => $emergency_batch_size
                        ]);
                        $batch_size = $emergency_batch_size;
                        // Update the stored batch size for future batches
                        set_batch_size($batch_size);
                    }
                }

                $items_processed_in_batch++;

                if ($i % 5 === 0) {
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
                unset($batch_json_items[$i]);
            } catch (\Exception $e) {
                PuntWorkLogger::error('Error processing batch item', PuntWorkLogger::CONTEXT_BATCH, [
                    'error' => $e->getMessage(),
                    'item_index' => $i,
                    'current_index' => $start_index + $i
                ]);
                $skipped++;
                $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Error processing item #' . ($start_index + $i + 1) . ': ' . $e->getMessage();
                continue;
            }
        }
        unset($batch_json_items);

        $valid_items_count = count($batch_guids);
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Prepared $valid_items_count valid items for individual processing (skipped " . ($loaded_count - $valid_items_count) . " items)";

        if (empty($batch_guids)) {
            try {
                retry_option_operation(function() use ($end_index) {
                    return set_import_progress($end_index);
                }, [], [
                    'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                    'operation' => 'update_progress_empty_batch'
                ]);

                retry_option_operation(function() use ($processed_guids) {
                    return set_processed_guids($processed_guids);
                }, [], [
                    'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                    'operation' => 'update_processed_guids_empty_batch'
                ]);

                $time_elapsed = microtime(true) - $start_time;
                $batch_time = microtime(true) - $batch_start_time;

                // Update import status for UI polling
                $current_status = get_import_status();
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

                retry_option_operation(function() use ($current_status) {
                    return set_import_status($current_status);
                }, [], [
                    'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                    'operation' => 'update_import_status_empty_batch'
                ]);

            } catch (\Exception $e) {
                PuntWorkLogger::error('Failed to update import status for empty batch', PuntWorkLogger::CONTEXT_BATCH, [
                    'error' => $e->getMessage(),
                    'end_index' => $end_index,
                    'total' => $total
                ]);
            }

            return [
                'success' => true,
                'processed' => $end_index,
                'total' => $total,
                'published' => $published,
                'updated' => $updated,
                'skipped' => $skipped,
                'duplicates_drafted' => $duplicates_drafted,
                'time_elapsed' => microtime(true) - $start_time,
                'complete' => ($end_index >= $total),
                'logs' => $logs,
                'batch_size' => $batch_size,
                'inferred_languages' => $inferred_languages,
                'inferred_benefits' => $inferred_benefits,
                'schema_generated' => $schema_generated,
                'batch_time' => microtime(true) - $batch_start_time,
                'batch_processed' => 0,
                'message' => '' // No error message for success
            ];
        }

        // Process batch items
        try {
            $result = process_batch_data($batch_guids, $batch_items, $json_path, $start_index, $logs, $published, $updated, $skipped, $duplicates_drafted);
        } catch (\Exception $e) {
            PuntWorkLogger::error('Failed to process batch data', PuntWorkLogger::CONTEXT_BATCH, [
                'error' => $e->getMessage(),
                'batch_guids_count' => count($batch_guids),
                'batch_items_count' => count($batch_items)
            ]);
            return [
                'success' => false,
                'message' => 'Failed to process batch data: ' . $e->getMessage(),
                'logs' => $logs
            ];
        }

        unset($batch_items, $batch_guids);

        try {
            retry_option_operation(function() use ($end_index) {
                return set_import_progress($end_index);
            }, [], [
                'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                'operation' => 'update_progress_final'
            ]);

            retry_option_operation(function() use ($processed_guids) {
                return set_processed_guids($processed_guids);
            }, [], [
                'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                'operation' => 'update_processed_guids_final'
            ]);

            $time_elapsed = microtime(true) - $start_time;
            $batch_time = microtime(true) - $batch_start_time;
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Individual processing complete: Processed {$result['processed_count']} items (published: $published, updated: $updated, skipped: $skipped, duplicates: $duplicates_drafted)";

            // Update performance metrics with concurrent processing data
            $avg_time_per_item = $result['avg_time_per_item'] ?? 0;
            $concurrency_used = $result['concurrency_used'] ?? 1;
            update_batch_metrics_concurrent($batch_time, $result['processed_count'], $batch_size, $avg_time_per_item, $concurrency_used);

            // Store batch timing data for status retrieval
            retry_option_operation(function() use ($batch_time) {
                return set_last_batch_time($batch_time);
            }, [], [
                'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                'operation' => 'update_last_batch_time'
            ]);

            retry_option_operation(function() use ($result) {
                return set_last_batch_processed($result['processed_count']);
            }, [], [
                'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                'operation' => 'update_last_batch_processed'
            ]);

            // Update import status for UI polling
            $current_status = get_import_status();
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

            retry_option_operation(function() use ($current_status) {
                return set_import_status($current_status);
            }, [], [
                'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                'operation' => 'update_import_status_final'
            ]);

        } catch (\Exception $e) {
            PuntWorkLogger::error('Failed to update import status after batch processing', PuntWorkLogger::CONTEXT_BATCH, [
                'error' => $e->getMessage(),
                'end_index' => $end_index,
                'total' => $total,
                'processed_count' => $result['processed_count'] ?? 0
            ]);
            // Continue with return even if status update fails
        }

        PuntWorkLogger::info('Individual item processing completed successfully', PuntWorkLogger::CONTEXT_BATCH, [
            'processed_count' => $result['processed_count'] ?? 0,
            'published' => $published,
            'updated' => $updated,
            'skipped' => $skipped,
            'duplicates_drafted' => $duplicates_drafted,
            'batch_time' => $batch_time,
            'time_elapsed' => $time_elapsed
        ]);

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
            'batch_processed' => $result['processed_count'],
            'start_time' => $start_time,
            'message' => '' // No error message for success
        ];
    } catch (\Exception $e) {
        $error_msg = 'Batch import error: ' . $e->getMessage();
        error_log($error_msg);

        PuntWorkLogger::error('Critical batch processing error', PuntWorkLogger::CONTEXT_BATCH, [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);

        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . $error_msg;
        return [
            'success' => false,
            'message' => 'Batch failed: ' . $e->getMessage(),
            'logs' => $logs
        ];
    }
}

/**
 * Process batch data including duplicates and item processing.
 *
 * @param array $batch_guids Array of GUIDs in batch.
 * @param array $batch_items Array of batch items.
 * @param string $json_path Path to JSONL file.
 * @param int $start_index Starting index in JSONL file.
 * @param array &$logs Reference to logs array.
 * @param int &$published Reference to published count.
 * @param int &$updated Reference to updated count.
 * @param int &$skipped Reference to skipped count.
 * @param int &$duplicates_drafted Reference to duplicates drafted count.
 * @return array Processing result.
 */
function process_batch_data($batch_guids, $batch_items, $json_path, $start_index, &$logs, &$published, &$updated, &$skipped, &$duplicates_drafted) {
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

    // Check if Action Scheduler is available for concurrent processing
    if (function_exists('as_schedule_single_action')) {
        PuntWorkLogger::debug('Using concurrent processing with Action Scheduler', PuntWorkLogger::CONTEXT_BATCH, [
            'batch_guids_count' => count($batch_guids)
        ]);
        $result = process_batch_items_concurrent($batch_guids, $batch_items, $last_updates, $all_hashes_by_post, $acf_fields, $zero_empty_fields, $post_ids_by_guid, $json_path, $start_index, $logs, $updated, $published, $skipped, $processed_count);
    } else {
        PuntWorkLogger::warning('Action Scheduler not available, falling back to sequential processing', PuntWorkLogger::CONTEXT_BATCH, [
            'batch_guids_count' => count($batch_guids)
        ]);
        $result = process_batch_items($batch_guids, $batch_items, $last_updates, $all_hashes_by_post, $acf_fields, $zero_empty_fields, $post_ids_by_guid, $json_path, $start_index, $logs, $updated, $published, $skipped, $processed_count);
    }

    return $result;
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
    try {
        PuntWorkLogger::debug('Loading JSONL batch', PuntWorkLogger::CONTEXT_BATCH, [
            'json_path' => $json_path,
            'start_index' => $start_index,
            'batch_size' => $batch_size
        ]);

        $items = [];
        $count = 0;
        $current_index = 0;

        // Validate file exists and is readable
        if (!file_exists($json_path)) {
            throw new \Exception("JSONL file does not exist: $json_path");
        }

        if (!is_readable($json_path)) {
            throw new \Exception("JSONL file is not readable: $json_path");
        }

        // Check file size
        $file_size = filesize($json_path);
        if ($file_size === 0) {
            throw new \Exception("JSONL file is empty: $json_path");
        }

        PuntWorkLogger::debug('JSONL file validation passed', PuntWorkLogger::CONTEXT_BATCH, [
            'json_path' => $json_path,
            'file_size' => $file_size,
            'start_index' => $start_index,
            'batch_size' => $batch_size
        ]);

        $handle = retry_file_operation(function() use ($json_path) {
            return fopen($json_path, "r");
        }, [$json_path], [
            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
            'operation' => 'open_json_file',
            'file_path' => $json_path
        ]);

        if ($handle === false) {
            throw new \Exception("Failed to open JSONL file: $json_path");
        }

        try {
            while (($line = fgets($handle)) !== false) {
                if ($current_index >= $start_index && $count < $batch_size) {
                    $line = trim($line);
                    if (!empty($line)) {
                        $item = json_decode($line, true);
                        if ($item === null && json_last_error() !== JSON_ERROR_NONE) {
                            PuntWorkLogger::warning('JSON decode error in batch', PuntWorkLogger::CONTEXT_BATCH, [
                                'line_number' => $current_index + 1,
                                'json_error' => json_last_error_msg(),
                                'line_preview' => substr($line, 0, 100)
                            ]);
                            // Skip malformed JSON lines but continue processing
                            $current_index++;
                            continue;
                        }

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
        } catch (\Exception $e) {
            PuntWorkLogger::error('Error reading JSONL file', PuntWorkLogger::CONTEXT_BATCH, [
                'error' => $e->getMessage(),
                'json_path' => $json_path,
                'current_index' => $current_index,
                'items_loaded' => count($items)
            ]);
            // Continue to close file handle
        }

        fclose($handle);

        PuntWorkLogger::debug('JSONL batch loaded successfully', PuntWorkLogger::CONTEXT_BATCH, [
            'items_loaded' => count($items),
            'total_lines_processed' => $current_index
        ]);

        return $items;

    } catch (\Exception $e) {
        PuntWorkLogger::error('Failed to load JSONL batch', PuntWorkLogger::CONTEXT_BATCH, [
            'error' => $e->getMessage(),
            'json_path' => $json_path,
            'start_index' => $start_index,
            'batch_size' => $batch_size,
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        throw $e; // Re-throw to let calling function handle it
    }
}

/**
 * Adjust batch size dynamically for concurrent processing.
 * Since items run concurrently, batch time is much shorter, so we use more conservative adjustments.
 *
 * @param int $current_batch_size Current batch size.
 * @param int $memory_limit_bytes Memory limit in bytes.
 * @param float $last_memory_ratio Last memory usage ratio.
 * @param float $current_batch_time Current batch time.
 * @param float $previous_batch_time Previous batch time.
 * @param int $cpu_cores Number of CPU cores.
 * @return array Adjustment result with new batch_size and reason.
 */
function adjust_batch_size_concurrent($current_batch_size, $memory_limit_bytes, $last_memory_ratio, $current_batch_time, $previous_batch_time, $cpu_cores) {
    $new_batch_size = $current_batch_size;
    $reason = 'no_change';

    // Base adjustments on concurrent processing characteristics
    $max_batch_size = min(100, $cpu_cores * 10); // Cap at 100 or 10x CPU cores

    // Memory-based adjustment (more conservative for concurrent)
    if ($last_memory_ratio > 0.8) {
        $new_batch_size = max(5, floor($current_batch_size * 0.7));
        $reason = 'high_memory_usage_concurrent';
    } elseif ($last_memory_ratio < 0.4) {
        $new_batch_size = min($max_batch_size, ceil($current_batch_size * 1.2));
        $reason = 'low_memory_usage_concurrent';
    }

    // Time-based adjustment (batch time is shorter in concurrent mode)
    if ($current_batch_time > 0 && $previous_batch_time > 0) {
        $time_ratio = $current_batch_time / $previous_batch_time;
        if ($time_ratio > 1.5) {
            // Slower than previous - reduce batch size conservatively
            $new_batch_size = max(5, floor($current_batch_size * 0.8));
            $reason = 'slower_batch_time_concurrent';
        } elseif ($time_ratio < 0.7) {
            // Faster than previous - increase batch size moderately
            $new_batch_size = min($max_batch_size, ceil($current_batch_size * 1.3));
            $reason = 'faster_batch_time_concurrent';
        }
    }

    // Consecutive batch logic (more aggressive ramp-up for concurrent)
    $consecutive_batches = get_consecutive_batches();
    if ($consecutive_batches > 5 && $new_batch_size < $max_batch_size) {
        $new_batch_size = min($max_batch_size, ceil($new_batch_size * 1.1));
        $reason = 'consecutive_batches_ramp_up_concurrent';
    }

    // Ensure minimum and maximum bounds
    $new_batch_size = max(5, min($max_batch_size, $new_batch_size));

    return [
        'batch_size' => $new_batch_size,
        'reason' => $reason,
        'max_allowed' => $max_batch_size,
        'cpu_cores' => $cpu_cores
    ];
}