<?php

/**
 * Batch processing core functions
 *
 * @package    Puntwork
 * @subpackage Batch
 * @since      1.0.0
 */

declare(strict_types=1);

// Prevent direct access
if (! defined('ABSPATH') ) {
    exit;
}

/**
 * Process batch items and handle imports.
 *
 * @param  array $setup Setup data from prepare_import_setup.
 * @return array Processing results.
 */
function process_batch_items_logic( array $setup ): array
{
    error_log('=== PUNTWORK BATCH DEBUG: process_batch_items_logic STARTED ===');
    error_log(
        '[PUNTWORK] process_batch_items_logic called with setup: ' . json_encode(
            array(
                'start_index'    => $setup['start_index'] ?? 'not set',
                'total'          => $setup['total'] ?? 'not set',
                'json_path'      => isset($setup['json_path']) ? basename($setup['json_path']) : 'not set',
                'json_path_full' => $setup['json_path'] ?? 'not set',
            )
        )
    );

    // Check if json_path exists and is readable
    if (isset($setup['json_path']) ) {
        error_log('[PUNTWORK] JSON file check: exists=' . ( file_exists($setup['json_path']) ? 'yes' : 'no' ) . ', readable=' . ( is_readable($setup['json_path']) ? 'yes' : 'no' ) . ', size=' . ( file_exists($setup['json_path']) ? filesize($setup['json_path']) : 'N/A' ));
    }

    // Start tracing span for batch processing (only if available)
    $span = null;
    if (class_exists('\Puntwork\PuntworkTracing') ) {
        $span = \Puntwork\PuntworkTracing::startActiveSpan(
            'process_batch_items_logic',
            array(
            'batch.start_index' => $setup['start_index'] ?? 0,
            'batch.total'       => $setup['total'] ?? 0,
            'batch.json_path'   => $setup['json_path'] ?? '',
            )
        );
    }

    try {
        error_log('[PUNTWORK] [BATCH-DEBUG] Starting performance monitoring');
        // Start performance monitoring
        $perf_id = start_performance_monitoring('batch_import');
        error_log('[PUNTWORK] [BATCH-DEBUG] Performance monitoring started with ID: ' . $perf_id);

        // Increase memory limit for batch processing
        $original_memory_limit = ini_get('memory_limit');
        ini_set('memory_limit', '1024M');
        error_log('[PUNTWORK] [BATCH-DEBUG] Memory limit increased to 1024M');

        // Clear analytics cache to prevent memory accumulation during import
        \Puntwork\Utilities\CacheManager::clearGroup(\Puntwork\Utilities\CacheManager::GROUP_ANALYTICS);
        error_log('[PUNTWORK] [BATCH-DEBUG] Analytics cache cleared');

        // Start database performance monitoring
        start_db_performance_monitoring();
        error_log('[PUNTWORK] [BATCH-DEBUG] Database performance monitoring started');

        // Optimize memory for large batch
        optimize_memory_for_batch();
        error_log('[PUNTWORK] [BATCH-DEBUG] Memory optimization completed');

        // Reset memory manager
        reset_memory_manager();
        error_log('[PUNTWORK] [BATCH-DEBUG] Memory manager reset completed');

        extract($setup);

        $batch_start_time = microtime(true); // Record start time for this batch

        error_log('[PUNTWORK] [BATCH-DEBUG] Calling validate_and_adjust_batch_size');
        // Validate and adjust batch size based on performance metrics
        $batch_size_info = validate_and_adjust_batch_size($setup);
        $batch_size      = $batch_size_info['batch_size'];
        $logs            = $batch_size_info['logs'];
        error_log('[PUNTWORK] [BATCH-DEBUG] validate_and_adjust_batch_size completed, batch_size=' . $batch_size);

        // Re-align start_index with new batch_size to avoid skips
        // Removed to prevent stuck imports when batch_size changes

        $end_index          = min($setup['start_index'] + $batch_size, $setup['total']);
        $published          = 0;
        $updated            = 0;
        $skipped            = 0;
        $duplicates_drafted = 0;
        $inferred_languages = 0;
        $inferred_benefits  = 0;
        $schema_generated   = 0;

        try {
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Starting batch from {$setup['start_index']} to $end_index (size $batch_size)";

            // Checkpoint: Batch setup complete
            checkpoint_performance(
                $perf_id,
                'batch_setup',
                array(
                'batch_size'  => $batch_size,
                'start_index' => $setup['start_index'],
                'end_index'   => $end_index,
                )
            );

            error_log('[PUNTWORK] [BATCH-DEBUG] Calling load_and_prepare_batch_items');
            // Load and prepare batch items from JSONL
            $batch_load_info = load_and_prepare_batch_items($json_path, $setup['start_index'], $batch_size, $batch_size_info['threshold'], $logs);
            $batch_items     = $batch_load_info['batch_items'];
            $batch_guids     = $batch_load_info['batch_guids'];
            error_log('[PUNTWORK] [BATCH-DEBUG] load_and_prepare_batch_items completed, loaded ' . count($batch_guids) . ' GUIDs');

            // Checkpoint: Batch items loaded
            checkpoint_performance(
                $perf_id,
                'batch_loaded',
                array(
                'items_loaded' => count($batch_guids),
                'memory_usage' => memory_get_usage(true),
                )
            );

            if ($batch_load_info['cancelled'] ) {
                  error_log('[PUNTWORK] [BATCH-DEBUG] Batch was cancelled, returning early');
                  update_option('job_import_progress', $end_index, false);
                  update_option('job_import_processed_guids', $processed_guids, false);
                  $time_elapsed = microtime(true) - $setup['start_time'];
                  $batch_time   = microtime(true) - $batch_start_time; // Calculate actual batch processing time

                  // End performance monitoring
                  $perf_data = end_performance_monitoring($perf_id);

                  // Update import status for UI polling
                  $current_status                       = get_option('job_import_status', array());
                  $current_status['total']              = $setup['total'];
                  $current_status['processed']          = $end_index;
                  $current_status['published']          = $current_status['published'] ?? 0;
                  $current_status['updated']            = $current_status['updated'] ?? 0;
                  $current_status['skipped']            = ( $current_status['skipped'] ?? 0 ) + $skipped;
                  $current_status['duplicates_drafted'] = $current_status['duplicates_drafted'] ?? 0;
                  $current_status['time_elapsed']       = $time_elapsed;
                  $current_status['complete']           = ( $end_index >= $setup['total'] );
                  $current_status['success']            = true;
                  $current_status['error_message']      = '';
                  $current_status['batch_size']         = $batch_size;
                  $current_status['inferred_languages'] = ( $current_status['inferred_languages'] ?? 0 ) + $inferred_languages;
                  $current_status['inferred_benefits'] = ( $current_status['inferred_benefits'] ?? 0 ) + $inferred_benefits;
                  $current_status['schema_generated']   = ( $current_status['schema_generated'] ?? 0 ) + $schema_generated;
                  $current_status['start_time']         = $setup['start_time'];
                  $current_status['end_time']           = $current_status['complete'] ? microtime(true) : null;
                  $current_status['last_update']        = time();
                  $current_status['logs']               = array_slice($logs, -50);
                  update_option('job_import_status', $current_status, false);

                if ($span ) {
                    $span->setAttribute('batch.cancelled', true);
                    $span->end();
                }

                // Restore memory limit
                ini_set('memory_limit', $original_memory_limit);

                  return array(
                   'success'            => true,
                   'processed'          => $end_index,
                   'total'              => $setup['total'],
                   'published'          => $published,
                   'updated'            => $updated,
                   'skipped'            => $skipped,
                   'duplicates_drafted' => $duplicates_drafted,
                   'time_elapsed'       => $time_elapsed,
                   'complete'           => ( $end_index >= $setup['total'] ),
                   'logs'               => $logs,
                   'batch_size'         => $batch_size,
                   'inferred_languages' => $inferred_languages,
                   'inferred_benefits'  => $inferred_benefits,
                   'schema_generated'   => $schema_generated,
                   'batch_time'         => $batch_time,
                   'batch_processed'    => 0,
                   'performance'        => $perf_data,
                   'message'            => '', // No error message for success
                  );
            }

            error_log('[PUNTWORK] [BATCH-DEBUG] Calling process_batch_data');
            // Process batch items
            $result = process_batch_data($batch_guids, $batch_items, $logs, $published, $updated, $skipped, $duplicates_drafted);
            error_log('[PUNTWORK] [BATCH-DEBUG] process_batch_data completed, processed_count=' . $result['processed_count']);

            // Checkpoint: Batch processing complete
            checkpoint_performance(
                $perf_id,
                'batch_processed',
                array(
                'items_processed'    => $result['processed_count'],
                'published'          => $published,
                'updated'            => $updated,
                'skipped'            => $skipped,
                'duplicates_drafted' => $duplicates_drafted,
                )
            );

            unset($batch_items, $batch_guids);

            update_option('job_import_progress', $end_index, false);
            update_option('job_import_processed_guids', $processed_guids, false);
            $time_elapsed = microtime(true) - $setup['start_time'];
            $batch_time   = microtime(true) - $batch_start_time; // Calculate actual batch processing time
            $logs[]       = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Batch complete: Processed {$result['processed_count']} items (published: $published, updated: $updated, skipped: $skipped, duplicates: $duplicates_drafted)";

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
            $current_status                       = get_option('job_import_status', array());
            $current_status['total']              = $setup['total'];
            $current_status['processed']          = $end_index;
            $current_status['published']          = ( $current_status['published'] ?? 0 ) + $published;
            $current_status['updated']            = ( $current_status['updated'] ?? 0 ) + $updated;
            $current_status['skipped']            = ( $current_status['skipped'] ?? 0 ) + $skipped;
            $current_status['duplicates_drafted'] = ( $current_status['duplicates_drafted'] ?? 0 ) + $duplicates_drafted;
            $current_status['time_elapsed']       = $time_elapsed;
            $current_status['complete']           = ( $end_index >= $setup['total'] );
            $current_status['success']            = true;
            $current_status['error_message']      = '';
            $current_status['batch_size']         = $batch_size;
            $current_status['inferred_languages'] = ( $current_status['inferred_languages'] ?? 0 ) + $inferred_languages;
            $current_status['inferred_benefits'] = ( $current_status['inferred_benefits'] ?? 0 ) + $inferred_benefits;
            $current_status['schema_generated']   = ( $current_status['schema_generated'] ?? 0 ) + $schema_generated;
            $current_status['start_time']         = $setup['start_time'];
            $current_status['end_time']           = $current_status['complete'] ? microtime(true) : null;
            $current_status['last_update']        = time();
            $current_status['logs']               = array_slice($logs, -50); // Keep last 50 log entries
            update_option('job_import_status', $current_status, false);

            // Schedule async analytics update for better performance
            $analytics_data = array(
            'import_id'          => wp_generate_uuid4(),
            'start_time'         => $setup['start_time'],
            'end_time'           => microtime(true),
            'batch_time'         => $batch_time,
            'total'              => $setup['total'],
            'processed'          => $result['processed_count'],
            'published'          => $published,
            'updated'            => $updated,
            'skipped'            => $skipped,
            'duplicates_drafted' => $duplicates_drafted,
            'performance'        => $perf_data,
            'message'            => '',
            );
            schedule_async_analytics_update($analytics_data);

            error_log('[PUNTWORK] [BATCH-DEBUG] process_batch_items_logic completed successfully');

            // Restore original memory limit
            ini_set('memory_limit', $original_memory_limit);
            error_log('[PUNTWORK] [BATCH-DEBUG] Memory limit restored to ' . $original_memory_limit);

            return array(
            'success'            => true,
            'processed'          => $end_index,
            'total'              => $setup['total'],
            'published'          => $published,
            'updated'            => $updated,
            'skipped'            => $skipped,
            'duplicates_drafted' => $duplicates_drafted,
            'time_elapsed'       => $time_elapsed,
            'complete'           => ( $end_index >= $setup['total'] ),
            'logs'               => $logs,
            'batch_size'         => $batch_size,
            'inferred_languages' => $inferred_languages,
            'inferred_benefits'  => $inferred_benefits,
            'schema_generated'   => $schema_generated,
            'batch_time'         => $batch_time,  // Time for this specific batch
            'batch_processed'    => $result['processed_count'],  // Items processed in this batch
            'start_time'         => $setup['start_time'],
            'performance'        => $perf_data,
            'message'            => '', // No error message for success
            );
        } catch ( \Exception $e ) {
            // End performance monitoring on error
            $perf_data = end_performance_monitoring($perf_id);

            $error_msg = 'Batch import error: ' . $e->getMessage();
            error_log($error_msg);
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . $error_msg;

            if ($span ) {
                $span->recordException($e);
                $span->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR, $e->getMessage());
                $span->end();
            }

            // Restore memory limit
            ini_set('memory_limit', $original_memory_limit);

            return array(
            'success'     => false,
            'message'     => 'Batch failed: ' . $e->getMessage(),
            'logs'        => $logs,
            'performance' => $perf_data,
            );
        }
    } catch ( \Exception $e ) {
        // Handle outer try exceptions (setup/initialization errors)
        if ($span ) {
            $span->recordException($e);
            $span->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR, $e->getMessage());
            $span->end();
        }

        // Restore memory limit
        ini_set('memory_limit', $original_memory_limit ?? '512M');

        return array(
        'success'     => false,
        'message'     => 'Batch setup failed: ' . $e->getMessage(),
        'logs'        => array(),
        'performance' => null,
        );
    }
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
function process_batch_data( array $batch_guids, array $batch_items, array &$logs, int &$published, int &$updated, int &$skipped, int &$duplicates_drafted ): array
{
    error_log('[PUNTWORK] process_batch_data called with ' . count($batch_guids) . ' GUIDs');

    if (empty($batch_guids) ) {
        error_log('[PUNTWORK] ERROR: process_batch_data called with empty batch_guids! This means load_and_prepare_batch_items failed to load valid items.');
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'ERROR: No GUIDs to process in this batch';
        return array( 'processed_count' => 0 );
    }

    global $wpdb;

    try {
        error_log('[PUNTWORK] [BATCH-DEBUG] About to call get_posts_by_guids_with_status');
        // Use optimized function to get posts by GUIDs with status
        $existing_by_guid = get_posts_by_guids_with_status($batch_guids);
        error_log('[PUNTWORK] [BATCH-DEBUG] get_posts_by_guids_with_status completed, found ' . count($existing_by_guid) . ' existing GUIDs');
    } catch ( \Exception $e ) {
        error_log('[PUNTWORK] Error getting existing posts: ' . $e->getMessage());
        throw $e;
    }

    $post_ids_by_guid = array();

    error_log('[PUNTWORK] [BATCH-DEBUG] About to call handle_batch_duplicates');
    // Handle duplicates
    handle_batch_duplicates($batch_guids, $existing_by_guid, $logs, $duplicates_drafted, $post_ids_by_guid);
    error_log('[PUNTWORK] [BATCH-DEBUG] handle_batch_duplicates completed, post_ids_by_guid has ' . count($post_ids_by_guid) . ' entries');

    // Clear cache to prevent memory accumulation
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
    \Puntwork\Utilities\CacheManager::clearGroup(\Puntwork\Utilities\CacheManager::GROUP_ANALYTICS);
    error_log('[PUNTWORK] [BATCH-DEBUG] Cache cleared after duplicates');

    error_log('[PUNTWORK] [BATCH-DEBUG] About to call prepare_batch_metadata');
    // Prepare batch metadata
    $batch_metadata = prepare_batch_metadata($post_ids_by_guid);
    error_log('[PUNTWORK] [BATCH-DEBUG] prepare_batch_metadata completed');

    // Clear cache again after metadata preparation
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
    \Puntwork\Utilities\CacheManager::clearGroup(\Puntwork\Utilities\CacheManager::GROUP_ANALYTICS);
    error_log('[PUNTWORK] [BATCH-DEBUG] Cache cleared after metadata');

    error_log('[PUNTWORK] [BATCH-DEBUG] About to call process_batch_items_with_metadata');
    // Process items
    $processed_count = process_batch_items_with_metadata($batch_guids, $batch_items, $batch_metadata, $post_ids_by_guid, $logs, $updated, $published, $skipped);
    error_log('[PUNTWORK] [BATCH-DEBUG] process_batch_items_with_metadata completed, processed_count=' . $processed_count);

    return array( 'processed_count' => $processed_count );
}

/**
 * Process batch items with prepared metadata.
 */
function process_batch_items_with_metadata( array $batch_guids, array $batch_items, array $batch_metadata, array $post_ids_by_guid, array &$logs, int &$updated, int &$published, int &$skipped ): int
{
    $processed_count   = 0;
    $acf_fields        = get_acf_fields();
    $zero_empty_fields = get_zero_empty_fields();

    process_batch_items($batch_guids, $batch_items, $batch_metadata['last_updates'], $batch_metadata['hashes_by_post'], $acf_fields, $zero_empty_fields, $post_ids_by_guid, $logs, $updated, $published, $skipped, $processed_count);

    return $processed_count;
}
