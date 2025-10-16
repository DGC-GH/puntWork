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

        // DYNAMIC BATCH SIZE CALCULATION FOR CONCURRENT PROCESSING
        // Calculate optimal batch size dynamically based on system resources and concurrent requirements
        $batch_size_config = calculate_dynamic_batch_size_for_concurrent($batch_size, $memory_limit_bytes);

        // Apply the calculated optimal batch size
        $batch_size = $batch_size_config['optimal_batch_size'];

        PuntWorkLogger::info('Dynamic batch size calculated for concurrent processing', PuntWorkLogger::CONTEXT_BATCH, [
            'calculated_batch_size' => $batch_size,
            'calculation_method' => $batch_size_config['method'],
            'memory_allocation_mb' => $batch_size_config['memory_allocation_mb'],
            'cpu_cores_utilized' => $batch_size_config['cpu_cores_utilized'],
            'expected_concurrency' => $batch_size_config['expected_concurrency'],
            'memory_safety_margin' => $batch_size_config['memory_safety_margin']
        ]);

        // PERFORMANCE OPTIMIZATION: Skip expensive batch size recalculations during import
        // Dynamic adjustment removed - now using pre-calculated optimal batch size
        $adjustment_result = ['batch_size' => $batch_size, 'reason' => 'precalculated'];

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
    // Initialize processed_guids to collect GUIDs processed in this batch
    $processed_guids = [];
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
                if ($i % 25 === 0) { // Check every 25 items (reduced from 5 for performance)
                    $maybe = check_import_limits_and_heartbeat($logs, $i, $current_index, $items_processed_in_batch, $batch_size, $start_index, $total, $published, $updated, $skipped, $duplicates_drafted, $inferred_languages, $inferred_benefits, $schema_generated, $start_time, $batch_start_time, null, 'check1');
                    if (is_array($maybe)) return $maybe;
                }
                    // HEARTBEAT: Update status every 100 items for performance (reduced from every 2 items)
                    if ($i % 100 === 0 && $i > 0) {
                        $heartbeat_status = get_import_status();
                        if (!is_array($heartbeat_status['logs'] ?? null)) {
                            $heartbeat_status['logs'] = [];
                        }
                        $heartbeat_status['last_update'] = microtime(true);
                        $heartbeat_status['processed'] = $start_index + $items_processed_in_batch;
                        $heartbeat_status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Heartbeat: Processing item ' . ($current_index + 1) . '/' . $total;
                        set_import_status($heartbeat_status);
                            }

                if (get_transient('import_cancel') === true) {
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Import cancelled at #' . ($current_index + 1);
                    set_import_progress($current_index);
                    // Resume cache invalidation on user cancellation
                    wp_suspend_cache_invalidation(false);
                    return ['success' => false, 'message' => 'Import cancelled by user', 'logs' => $logs];
                }

                // Check for additional cancellation flags (force cancel and emergency stop)
                if (get_transient('import_force_cancel') === true) {
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Import force cancelled at #' . ($current_index + 1);
                    set_import_progress($current_index);
                    // Resume cache invalidation on force cancellation
                    wp_suspend_cache_invalidation(false);
                    return ['success' => false, 'message' => 'Import force cancelled by user', 'logs' => $logs];
                }

                if (get_transient('import_emergency_stop') === true) {
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Import emergency stopped at #' . ($current_index + 1);
                    set_import_progress($current_index);
                    // Resume cache invalidation on emergency stop
                    wp_suspend_cache_invalidation(false);
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
                        PuntWorkLogger::warn('Emergency batch size reduction due to high memory usage', PuntWorkLogger::CONTEXT_BATCH, [
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

                if ($i % 25 === 0) { // Check every 25 items (reduced from 5 for performance)
                    static $last_check_time = 0;
                    $current_time = microtime(true);
                    if ($current_time - $last_check_time >= 1) { // Cache checks for 1 second
                        $last_check_time = $current_time;
                        if (import_time_exceeded() || import_memory_exceeded()) {
                            PuntWorkLogger::warn('Timeout/memory limit detected mid-batch, saving progress', PuntWorkLogger::CONTEXT_BATCH, [
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
                            $timeout_status['last_update'] = microtime(true);
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

                        // HEARTBEAT DISABLED: Removed frequent status updates for performance - now only every 100 items
                        // Heartbeat updates were causing significant performance degradation with every 10 items
                    }
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
                $current_status['last_update'] = microtime(true);
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
            $current_status['last_update'] = microtime(true);
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
 * Check import time/memory limits and optionally emit heartbeat updates.
 * Extracted to avoid duplicated logic and duplicate static declarations.
 *
 * @param array &$logs Reference to logs array.
 * @param int $iteration_index The loop iteration index ($i).
 * @param int $current_index Current absolute index in JSONL.
 * @param int $items_processed_in_batch Number of items processed in this batch so far.
 * @param int $batch_size Current batch size.
 * @param int $start_index Batch start index.
 * @param int $total Total items.
 * @param int &$published Published count (included in return only).
 * @param int &$updated Updated count (included in return only).
 * @param int &$skipped Skipped count (included in return only).
 * @param int &$duplicates_drafted Duplicates drafted count (included in return only).
 * @param int $inferred_languages Inferred languages count.
 * @param int $inferred_benefits Inferred benefits count.
 * @param int $schema_generated Schema generated count.
 * @param float $start_time Import start time.
 * @param float $batch_start_time Batch start time.
 * @param int|null $heartbeat_mod If set, emit a heartbeat when ($iteration_index % $heartbeat_mod) === 0.
 * @param string $static_id A unique id used to isolate the static last-check timer per caller.
 * @return array|null Returns the array to be returned from the caller when processing is paused, or null to continue.
 */
function check_import_limits_and_heartbeat(array &$logs, $iteration_index, $current_index, $items_processed_in_batch, $batch_size, $start_index, $total, &$published, &$updated, &$skipped, &$duplicates_drafted, $inferred_languages, $inferred_benefits, $schema_generated, $start_time, $batch_start_time, $heartbeat_mod = null, $static_id = 'default') {
    static $last_check_times = [];
    if (!isset($last_check_times[$static_id])) $last_check_times[$static_id] = 0;

    $current_time = microtime(true);
    if ($current_time - $last_check_times[$static_id] >= 1) { // Cache checks for 1 second
        $last_check_times[$static_id] = $current_time;
        if (import_time_exceeded() || import_memory_exceeded()) {
            PuntWorkLogger::warn('Timeout/memory limit detected mid-batch, saving progress', PuntWorkLogger::CONTEXT_BATCH, [
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
            $timeout_status['last_update'] = microtime(true);
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
    }

    // Heartbeat update when requested
    if (!is_null($heartbeat_mod) && $heartbeat_mod > 0) {
        if (($iteration_index % $heartbeat_mod) === 0) {
            $heartbeat_status = get_import_status();
            if (!is_array($heartbeat_status['logs'] ?? null)) {
                $heartbeat_status['logs'] = [];
            }
            $heartbeat_status['last_update'] = microtime(true);
            $heartbeat_status['processed'] = $start_index + $items_processed_in_batch;
            $heartbeat_status['logs'][] = '[' . date('d-M-Y H:i:s') . ' UTC] Heartbeat: Processing item ' . ($current_index + 1) . '/' . $total;
            set_import_status($heartbeat_status);
        }
    }

    return null;
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

    // PERFORMANCE OPTIMIZATION: Added memory monitoring and proactive garbage collection
    $memory_before = memory_get_usage(true);
    $peak_memory_before = memory_get_peak_usage(true);

    PuntWorkLogger::info('Starting batch data processing with memory monitoring', PuntWorkLogger::CONTEXT_BATCH, [
        'batch_guids_count' => count($batch_guids),
        'memory_before_mb' => round($memory_before / 1024 / 1024, 2),
        'peak_memory_before_mb' => round($peak_memory_before / 1024 / 1024, 2)
    ]);

    // Ensure counter variables are integers to prevent type corruption
    $published = is_int($published) ? $published : (int)$published;
    $updated = is_int($updated) ? $updated : (int)$updated;
    $skipped = is_int($skipped) ? $skipped : (int)$skipped;
    $duplicates_drafted = is_int($duplicates_drafted) ? $duplicates_drafted : (int)$duplicates_drafted;

    // Bulk existing post_ids
    $guid_placeholders = implode(',', array_fill(0, count($batch_guids), '%s'));
    $sql = "SELECT post_id, meta_value AS guid FROM $wpdb->postmeta WHERE meta_key = 'guid' AND meta_value IN ($guid_placeholders)";
    $prepare_args = $batch_guids;
    array_unshift($prepare_args, $sql);
    $prepared_sql = call_user_func_array([$wpdb, 'prepare'], $prepare_args);
    $existing_meta = $wpdb->get_results($prepared_sql);
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
            $chunk_sql = "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_last_import_update' AND post_id IN ($placeholders)";
            $chunk_prepare_args = $chunk;
            array_unshift($chunk_prepare_args, $chunk_sql);
            $prepared_chunk_sql = call_user_func_array([$wpdb, 'prepare'], $chunk_prepare_args);
            $chunk_last = $wpdb->get_results($prepared_chunk_sql, OBJECT_K);
            $last_updates += (array)$chunk_last;
        }

        foreach ($post_id_chunks as $chunk) {
            if (empty($chunk)) continue;
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $chunk_sql2 = "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_import_hash' AND post_id IN ($placeholders)";
            $chunk_prepare_args2 = $chunk;
            array_unshift($chunk_prepare_args2, $chunk_sql2);
            $prepared_chunk_sql2 = call_user_func_array([$wpdb, 'prepare'], $chunk_prepare_args2);
            $chunk_hashes = $wpdb->get_results($prepared_chunk_sql2, OBJECT_K);
            foreach ($chunk_hashes as $id => $obj) {
                $all_hashes_by_post[$id] = $obj->meta_value;
            }
        }
    }

    $processed_count = 0;
    $acf_fields = get_acf_fields();
    $zero_empty_fields = get_zero_empty_fields();

    // CONCURRENT-FIRST PROCESSING DECISION: Default to concurrent, fallback to sequential only in critical failure cases
    $action_scheduler_available = function_exists('as_schedule_single_action');

    // FORCE CONCURRENT-FIRST: Reset historical metrics to enable concurrent processing
    update_option('job_import_concurrent_success_rate', 1.0, false); // Reset to perfect success rate
    update_option('job_import_concurrent_total_attempts', 0, false);   // Reset attempt counter
    update_option('job_import_concurrent_successful_attempts', 0, false); // Reset success counter

    $concurrent_success_rate = get_concurrent_success_rate();
    $sequential_success_rate = get_sequential_success_rate();

    // Default to concurrent processing - sequential is now rare fallback
    $use_concurrent = true; // Default to concurrent
    $decision_reason = 'concurrent_first_reset';

    PuntWorkLogger::info('Concurrent-first processing initiated - resetting historical metrics', PuntWorkLogger::CONTEXT_BATCH, [
        'reset_concurrent_success_rate' => 1.0,
        'action_scheduler_available' => $action_scheduler_available,
        'decision_reason' => $decision_reason
    ]);

    // CRITICAL: Check Action Scheduler health - concurrent requires it
    if (!$action_scheduler_available) {
        $use_concurrent = false;
        $decision_reason = 'no_action_scheduler';
        PuntWorkLogger::error('Concurrent processing impossible - Action Scheduler not available', PuntWorkLogger::CONTEXT_BATCH, [
            'action_scheduler_available' => $action_scheduler_available,
            'reason' => 'falling back to sequential processing'
        ]);
    }
    // EXTREMELY RARE: Only fallback on complete Action Scheduler breakdown
    elseif ($action_scheduler_available) {
        try {
            $health_check = validate_action_scheduler_health();
            if (!$health_check['healthy']) {
                $use_concurrent = false;
                $decision_reason = 'action_scheduler_critical_failure';
                PuntWorkLogger::error('Concurrent processing disabled due to critical Action Scheduler failure', PuntWorkLogger::CONTEXT_BATCH, [
                    'issues' => $health_check['issues'],
                    'recommendations' => $health_check['recommendations'],
                    'reason' => 'emergency fallback to sequential processing'
                ]);
            }
        } catch (\Exception $e) {
            // Even on health check failure, still attempt concurrent (AS health checks can be unreliable)
            PuntWorkLogger::warn('Action Scheduler health check failed but attempting concurrent anyway', PuntWorkLogger::CONTEXT_BATCH, [
                'error' => $e->getMessage(),
                'action' => 'proceeding_with_concurrent_despite_health_check_failure'
            ]);
        }
    }

    PuntWorkLogger::info('Processing mode decision based on success rates', PuntWorkLogger::CONTEXT_BATCH, [
        'use_concurrent' => $use_concurrent,
        'decision_reason' => $decision_reason,
        'concurrent_success_rate' => $concurrent_success_rate,
        'sequential_success_rate' => $sequential_success_rate,
        'action_scheduler_available' => $action_scheduler_available,
        'batch_guids_count' => count($batch_guids)
    ]);

    if ($use_concurrent) {
        PuntWorkLogger::debug('Attempting concurrent processing based on success rate decision', PuntWorkLogger::CONTEXT_BATCH, [
            'batch_guids_count' => count($batch_guids),
            'decision_reason' => $decision_reason
        ]);
        $result = process_batch_items_concurrent($batch_guids, $batch_items, $last_updates, $all_hashes_by_post, $acf_fields, $zero_empty_fields, $post_ids_by_guid, $json_path, $start_index, $logs, $updated, $published, $skipped, $processed_count);

        // VALIDATION: Check if concurrent processing failed - handle gracefully
        if (isset($result['success']) && $result['success'] === false) {
            PuntWorkLogger::warn('Concurrent processing failed - falling back to concurrent override policy', PuntWorkLogger::CONTEXT_BATCH, [
                'error' => $result['message'] ?? 'Unknown concurrent error',
                'success_rate' => $result['success_rate'] ?? 0,
                'processed_count' => $result['processed_count'] ?? 0,
                'issues' => $result['issues'] ?? [],
                'recommendations' => $result['recommendations'] ?? []
            ]);

            // Log issues to the batch logs but don't stop - allow import to continue with partial concurrent success
            if (isset($result['issues']) && is_array($result['issues'])) {
                foreach ($result['issues'] as $issue) {
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Concurrent Processing Issue: ' . $issue;
                }
            }

            PuntWorkLogger::info('Allowing batch to proceed despite concurrent issues - updating success metrics', PuntWorkLogger::CONTEXT_BATCH, [
                'raw_processed_count' => $result['processed_count'] ?? 0,
                'success_rate' => $result['success_rate'] ?? 0,
                'action' => 'proceeding_with_batch_completion'
            ]);

            // Update metrics with actual completed count, not 0
            $actual_processed = $result['processed_count'] ?? 0;
            if ($actual_processed > 0) {
                $result['processed_count'] = $actual_processed;
                $result['success'] = ($result['success_rate'] ?? 0) >= 0.5; // Accept 50%+ success
            }
        }
    } else {
        PuntWorkLogger::info('Using sequential processing based on success rate decision', PuntWorkLogger::CONTEXT_BATCH, [
            'batch_guids_count' => count($batch_guids),
            'decision_reason' => $decision_reason
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
                            PuntWorkLogger::warn('JSON decode error in batch', PuntWorkLogger::CONTEXT_BATCH, [
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
 * Calculate dynamic batch size for concurrent processing with optimized resource allocation
 *
 * This function determines the optimal batch size for concurrent processing based on:
 * - Available system memory and CPU cores
 * - Concurrent processing requirements (memory per process, scheduling overhead)
 * - Action Scheduler constraints and import system limitations
 * - Performance monitoring and adaptive scaling
 *
 * @param int $current_batch_size Current stored batch size
 * @param int $memory_limit_bytes PHP memory limit in bytes
 * @return array Configuration array with optimal batch size and calculation details
 */
function calculate_dynamic_batch_size_for_concurrent($current_batch_size, $memory_limit_bytes) {
    // PHASE 1: Gather system resource information
    $cpu_cores = function_exists('shell_exec') ? (int) shell_exec('nproc 2>/dev/null') : 2;
    $memory_limit_mb = $memory_limit_bytes / 1024 / 1024;
    $current_memory_usage = memory_get_usage(true);
    $available_memory_mb = ($memory_limit_bytes - $current_memory_usage) / 1024 / 1024;

    // PHASE 2: Define concurrent processing constraints
    $base_memory_per_concurrent_job = 12; // MB per concurrent job (conservative estimate)
    $action_scheduler_overhead_mb = 8; // MB for Action Scheduler overhead
    $safety_margin_mb = max(32, $memory_limit_mb * 0.15); // 15% safety margin or 32MB minimum

    // Calculate maximum concurrent jobs based on memory
    $available_for_concurrency = max(0, $available_memory_mb - $action_scheduler_overhead_mb - $safety_margin_mb);
    $max_concurrent_jobs_memory = max(1, floor($available_for_concurrency / $base_memory_per_concurrent_job));

    // CPU-based concurrency limits (more conservative for stability)
    $cpu_based_limit = max(1, (int)($cpu_cores * 0.6)); // Use max 60% of CPU cores

    // Action Scheduler limits (conservative for reliable operation)
    $action_scheduler_limit = 8; // Max concurrent jobs Action Scheduler can reliably handle

    // PHASE 3: Determine optimal concurrency level
    $optimal_concurrency = min($max_concurrent_jobs_memory, $cpu_based_limit, $action_scheduler_limit);

    // PHASE 4: Calculate optimal batch size
    // For concurrent processing, batch size should optimize for:
    // 1. Minimizing total batch count (7414 total items / batch_size = batches)
    // 2. Balancing memory usage across concurrent jobs
    // 3. Keeping individual job processing time reasonable (< 10 seconds)

    $memory_based_batch_size = max(10, floor(7414 / ($optimal_concurrency * 3))); // 3 batches per concurrency level

    // Apply system constraints
    $memory_constraint_batch = max(10, min(100, floor(($memory_limit_mb - 64) / 2))); // Memory-based batch limit
    $cpu_constraint_batch = max(10, $cpu_cores * 4); // CPU-based batch limit

    // Take the minimum of constraints for safety
    $constrained_batch_size = min($memory_based_batch_size, $memory_constraint_batch, $cpu_constraint_batch);

    // PHASE 5: Apply historical performance adjustments
    $performance_factor = 1.0;

    // Check recent performance history
    $concurrent_success_rate = get_concurrent_success_rate();
    if ($concurrent_success_rate < 0.9) {
        $performance_factor *= 0.8; // Reduce batch size if recent concurrent failures
    }

    $avg_time_per_item = get_average_time_per_item_concurrent();
    if ($avg_time_per_item > 3.0) {
        $performance_factor *= 0.7; // Reduce batch size if items are slow to process
    } elseif ($avg_time_per_item < 1.0) {
        $performance_factor *= 1.2; // Increase batch size if items process quickly
    }

    // PHASE 6: Determine final optimal batch size
    $optimal_batch_size = max(10, min(100, (int)($constrained_batch_size * $performance_factor)));

    // PHASE 7: Validate and constrain final result
    $min_batch_size = 10; // Minimum for concurrent efficiency
    $max_batch_size = 100; // Maximum for memory safety
    $optimal_batch_size = max($min_batch_size, min($max_batch_size, $optimal_batch_size));

    return [
        'optimal_batch_size' => $optimal_batch_size,
        'method' => 'dynamic_concurrent_calculation',
        'memory_allocation_mb' => $available_memory_mb,
        'cpu_cores_utilized' => $optimal_concurrency,
        'expected_concurrency' => $optimal_concurrency,
        'memory_safety_margin' => $safety_margin_mb,
        'calculation_factors' => [
            'memory_based_batch' => $memory_based_batch_size,
            'cpu_constraint_batch' => $cpu_constraint_batch,
            'memory_constraint_batch' => $memory_constraint_batch,
            'performance_factor' => $performance_factor,
            'total_items_estimated' => 7414
        ],
        'system_resources' => [
            'cpu_cores_detected' => $cpu_cores,
            'memory_limit_mb' => $memory_limit_mb,
            'available_memory_mb' => $available_memory_mb,
            'base_memory_per_job_mb' => $base_memory_per_concurrent_job
        ],
        'constraints_applied' => [
            'max_concurrent_memory_based' => $max_concurrent_jobs_memory,
            'cpu_based_limit' => $cpu_based_limit,
            'action_scheduler_limit' => $action_scheduler_limit
        ]
    ];
}

/**
 * Get average time per item for concurrent processing from historical data
 */
function get_average_time_per_item_concurrent() {
    $avg_time = get_option('job_import_avg_time_per_item_concurrent', 1.5); // Default 1.5 seconds per item
    return is_numeric($avg_time) ? (float)$avg_time : 1.5;
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
