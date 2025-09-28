<?php

/**
 * JSONL file combination utilities.
 *
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

function combine_jsonl_files($feeds, $output_dir, $total_items, &$logs)
{
    $debug_mode = defined('WP_DEBUG') && WP_DEBUG;

    if ($debug_mode) {
        error_log('[PUNTWORK] [JSONL-COMBINE-START] ===== COMBINE_JSONL_FILES START =====');
        error_log('[PUNTWORK] [JSONL-COMBINE-START] feeds count: ' . count($feeds));
        error_log('[PUNTWORK] [JSONL-COMBINE-START] total_items: ' . $total_items);
        error_log('[PUNTWORK] [JSONL-COMBINE-START] output_dir: ' . $output_dir);
        error_log('[PUNTWORK] [JSONL-COMBINE-START] Memory usage at start: ' . memory_get_usage(true) . ' bytes');
    }

    // Use advanced JSONL processor for better performance
    $combined_json_path = $output_dir . 'combined-jobs.jsonl';
    if ($debug_mode) {
        error_log('[PUNTWORK] [JSONL-COMBINE-DEBUG] Combined JSONL path: ' . $combined_json_path);
    }

    // Determine processing mode based on feed count and system capabilities
    $feed_files = [];
    foreach ($feeds as $feed_key => $url) {
        $feed_file = $output_dir . $feed_key . '.jsonl';
        $feed_files[] = $feed_file;
        if ($debug_mode) {
            error_log('[PUNTWORK] [JSONL-COMBINE-DEBUG] Feed file for ' . $feed_key . ': ' . $feed_file . ' (exists: ' . (file_exists($feed_file) ? 'yes' : 'no') . ')');
            if (file_exists($feed_file)) {
                $size = filesize($feed_file);
                error_log('[PUNTWORK] [JSONL-COMBINE-DEBUG]   - Size: ' . $size . ' bytes');
            }
        }
    }

    // Filter to existing files only
    $existing_feeds = array_filter($feed_files, 'file_exists');
    if ($debug_mode) {
        error_log('[PUNTWORK] [JSONL-COMBINE-DEBUG] Found ' . count($existing_feeds) . ' existing feed files out of ' . count($feed_files));
    }

    if (empty($existing_feeds)) {
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'No feed files found to combine';
        error_log('[PUNTWORK] [JSONL-COMBINE-ERROR] No feed files found to combine');
        if ($debug_mode) {
            error_log('[PUNTWORK] [JSONL-COMBINE-END] ===== COMBINE_JSONL_FILES END (NO FILES) =====');
        }

        return;
    }

    // Log details of existing files
    foreach ($existing_feeds as $feed_file) {
        $size = filesize($feed_file);
        $basename = basename($feed_file);
        if ($debug_mode) {
            error_log('[PUNTWORK] [JSONL-COMBINE-DEBUG] Will process: ' . $basename . ' (' . $size . ' bytes)');
            if ($size > 0) {
                // Check first line
                $handle = fopen($feed_file, 'r');
                if ($handle) {
                    $first_line = fgets($handle);
                    fclose($handle);
                    error_log('[PUNTWORK] [JSONL-COMBINE-DEBUG]   First line: ' . substr(trim($first_line), 0, 100) . (strlen($first_line) > 100 ? '...[truncated]' : ''));
                }
            }
        }
    }

    // Choose processing strategy
    $feed_count = count($existing_feeds);
    $use_parallel = $feed_count > 3 && function_exists('pcntl_fork');
    $use_progressive = file_exists($combined_json_path) && $feed_count < $feed_count; // Use progressive for small updates

    $processing_stats = [];

    if ($debug_mode) {
        error_log('[PUNTWORK] [JSONL-COMBINE-DEBUG] Processing strategy: feed_count=' . $feed_count . ', use_parallel=' . ($use_parallel ? 'true' : 'false') . ', use_progressive=' . ($use_progressive ? 'true' : 'false'));
    }

    if ($use_parallel) {
        if ($debug_mode) {
            error_log('[PUNTWORK] [JSONL-COMBINE-DEBUG] Using parallel processing');
        }
        $success = \Puntwork\Utilities\AdvancedJsonlProcessor::combineJsonlParallel($existing_feeds, $combined_json_path, $processing_stats);
        $method = 'parallel';
    } elseif ($use_progressive) {
        if ($debug_mode) {
            error_log('[PUNTWORK] [JSONL-COMBINE-DEBUG] Using progressive processing');
        }
        $success = \Puntwork\Utilities\AdvancedJsonlProcessor::combineJsonlProgressive($existing_feeds, $combined_json_path, $processing_stats);
        $method = 'progressive';
    } else {
        if ($debug_mode) {
            error_log('[PUNTWORK] [JSONL-COMBINE-DEBUG] Using streaming processing');
        }
        $success = \Puntwork\Utilities\AdvancedJsonlProcessor::combineJsonlStreaming($existing_feeds, $combined_json_path, $processing_stats);
        $method = 'streaming';
    }

    if ($debug_mode) {
        error_log('[PUNTWORK] [JSONL-COMBINE-DEBUG] Processing completed with success=' . ($success ? 'true' : 'false'));
    }

    if (!$success) {
        error_log('[PUNTWORK] [JSONL-COMBINE-ERROR] Advanced processing failed, falling back to original method');
        // Fallback to original method if advanced processing fails
        return combine_jsonl_files_fallback($feeds, $output_dir, $total_items, $logs);
    }

    // Log results
    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . sprintf(
        'Advanced JSONL combination (%s): %d files processed, %d unique items, %d duplicates removed in %.3f seconds',
        $method,
        $processing_stats['total_files'] ?? count($existing_feeds),
        $processing_stats['unique_items'] ?? 0,
        $processing_stats['duplicates_removed'] ?? 0,
        $processing_stats['processing_time'] ?? 0
    );

    if ($debug_mode) {
        error_log('[PUNTWORK] [JSONL-COMBINE-DEBUG] Results: files=' . ($processing_stats['total_files'] ?? count($existing_feeds)) . ', unique=' . ($processing_stats['unique_items'] ?? 0) . ', duplicates=' . ($processing_stats['duplicates_removed'] ?? 0) . ', time=' . ($processing_stats['processing_time'] ?? 0));
    }

    PuntWorkLogger::info('Advanced JSONL combination completed', PuntWorkLogger::CONTEXT_FEED, [
        'method' => $method,
        'files_processed' => count($existing_feeds),
        'unique_items' => $processing_stats['unique_items'] ?? 0,
        'duplicates_removed' => $processing_stats['duplicates_removed'] ?? 0,
        'processing_time' => $processing_stats['processing_time'] ?? 0,
        'memory_peak_mb' => $processing_stats['memory_peak_mb'] ?? 0,
    ]);

    // Check if combined file was created
    if (file_exists($combined_json_path)) {
        $combined_size = filesize($combined_json_path);
        if ($debug_mode) {
            error_log('[PUNTWORK] [JSONL-COMBINE-DEBUG] Combined file created: ' . $combined_size . ' bytes');
        }
    } else {
        error_log('[PUNTWORK] [JSONL-COMBINE-ERROR] Combined file was not created');
    }

    // Compress the final file
    gzip_file($combined_json_path, $combined_json_path . '.gz');
    PuntWorkLogger::info('GZIP compression completed', PuntWorkLogger::CONTEXT_FEED, [
        'gz_file' => $combined_json_path . '.gz',
    ]);
    if ($debug_mode) {
        error_log('[PUNTWORK] [JSONL-COMBINE-DEBUG] GZIP compression completed for ' . $combined_json_path);
    }

    // Optimize JSONL for batch processing synergy
    $optimization_stats = [];
    $optimization_success = \Puntwork\Utilities\JsonlOptimizer::optimizeForBatchProcessing(
        $combined_json_path,
        $combined_json_path . '.optimized',
        $optimization_stats
    );

    if ($optimization_success) {
        // Replace original with optimized version
        rename($combined_json_path . '.optimized', $combined_json_path);

        // Re-compress the optimized file
        gzip_file($combined_json_path, $combined_json_path . '.gz');

        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . sprintf(
            'JSONL optimization completed: %d strategies applied, %.3f seconds, %.1f MB memory',
            count($optimization_stats['strategies_applied']),
            $optimization_stats['optimization_time'],
            $optimization_stats['memory_peak_mb']
        );

        PuntWorkLogger::info('JSONL optimization completed', PuntWorkLogger::CONTEXT_FEED, [
            'strategies_applied' => $optimization_stats['strategies_applied'],
            'optimization_time' => $optimization_stats['optimization_time'],
            'memory_peak_mb' => $optimization_stats['memory_peak_mb'],
            'grouping_stats' => $optimization_stats['grouping_stats'],
        ]);
    } else {
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'JSONL optimization failed, using unoptimized version';
        if ($debug_mode) {
            error_log('[PUNTWORK] [JSONL-COMBINE-DEBUG] JSONL optimization failed, proceeding with unoptimized file');
        }
    }

    // Final check
    if (file_exists($combined_json_path)) {
        $final_size = filesize($combined_json_path);
        if ($debug_mode) {
            error_log('[PUNTWORK] [JSONL-COMBINE-DEBUG] Final combined file size: ' . $final_size . ' bytes');
            error_log('[PUNTWORK] [JSONL-COMBINE-END] ===== COMBINE_JSONL_FILES END =====');
        }
    }
}

/**
 * Fallback JSONL combination method (original implementation).
 */
function combine_jsonl_files_fallback($feeds, $output_dir, $total_items, &$logs)
{
    // Ensure output directory exists
    if (!wp_mkdir_p($output_dir) || !is_writable($output_dir)) {
        throw new \Exception('Feeds directory not writable');
    }

    $combined_json_path = $output_dir . 'combined-jobs.jsonl';
    $combined_gz_path = $combined_json_path . '.gz';
    $combined_handle = fopen($combined_json_path, 'w');
    if (!$combined_handle) {
        throw new \Exception('Cant open combined JSONL');
    }

    $seen_guids = [];
    $duplicate_count = 0;
    $unique_count = 0;

    PuntWorkLogger::info('Fallback JSONL file combination started', PuntWorkLogger::CONTEXT_FEED, [
        'feeds_count' => count($feeds),
        'output_dir' => $output_dir,
        'total_items' => $total_items,
    ]);
    error_log('[PUNTWORK] [JSONL-COMBINE] Fallback: Starting JSONL file combination, feeds count: ' . count($feeds) . ', output_dir: ' . $output_dir);

    foreach ($feeds as $feed_key => $url) {
        $feed_json_path = $output_dir . $feed_key . '.jsonl';
        PuntWorkLogger::debug("Processing feed file: {$feed_key}", PuntWorkLogger::CONTEXT_FEED, [
            'feed_file' => $feed_json_path,
            'exists' => file_exists($feed_json_path),
        ]);
        error_log('[PUNTWORK] [JSONL-COMBINE] Fallback: Processing feed: ' . $feed_key . ', file: ' . $feed_json_path . ', exists: ' . (file_exists($feed_json_path) ? 'yes' : 'no'));
        if (file_exists($feed_json_path)) {
            $feed_handle = fopen($feed_json_path, 'r');
            if ($feed_handle) {
                $feed_line_count = 0;
                while (($line = fgets($feed_handle)) !== false) {
                    $line = trim($line);
                    if (empty($line)) {
                        continue;
                    }

                    // Parse JSON to check GUID
                    $job_data = json_decode($line, true);
                    if ($job_data == null) {
                        // Invalid JSON, skip
                        PuntWorkLogger::debug('Skipping invalid JSON line in feed: ' . $feed_key, PuntWorkLogger::CONTEXT_FEED);

                        continue;
                    }

                    $guid = isset($job_data['guid']) ? trim($job_data['guid']) : '';
                    if (empty($guid)) {
                        // No GUID, include but log
                        fwrite($combined_handle, $line . "\n");
                        $unique_count++;

                        continue;
                    }

                    // Check for duplicates
                    if (isset($seen_guids[$guid])) {
                        $duplicate_count++;

                        continue; // Skip duplicate
                    }

                    // New unique job
                    $seen_guids[$guid] = true;
                    fwrite($combined_handle, $line . "\n");
                    $unique_count++;
                    $feed_line_count++;
                }
                fclose($feed_handle);
                PuntWorkLogger::debug("Feed processed: {$feed_key}", PuntWorkLogger::CONTEXT_FEED, [
                    'lines_added' => $feed_line_count,
                ]);
                error_log('[PUNTWORK] [JSONL-COMBINE] Fallback: Feed ' . $feed_key . ' processed, lines added: ' . $feed_line_count);
            } else {
                PuntWorkLogger::error("Could not open feed file: {$feed_json_path}", PuntWorkLogger::CONTEXT_FEED);
                error_log('[PUNTWORK] [JSONL-COMBINE] Fallback: Could not open feed file: ' . $feed_json_path);
            }
        } else {
            PuntWorkLogger::warn("Feed file not found: {$feed_json_path}", PuntWorkLogger::CONTEXT_FEED);
            error_log('[PUNTWORK] [JSONL-COMBINE] Fallback: Feed file not found: ' . $feed_json_path);
        }
    }

    fclose($combined_handle);
    @chmod($combined_json_path, 0644);

    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Combined JSONL ($unique_count unique items, $duplicate_count duplicates removed)";
    PuntWorkLogger::info('Fallback JSONL combination completed', PuntWorkLogger::CONTEXT_FEED, [
        'unique_count' => $unique_count,
        'duplicate_count' => $duplicate_count,
        'total_processed' => $unique_count + $duplicate_count,
    ]);
    error_log("Combined JSONL ($unique_count unique items, $duplicate_count duplicates removed)");
    error_log('[PUNTWORK] [JSONL-COMBINE] Fallback: JSONL combination completed, unique_count=' . $unique_count . ', duplicate_count=' . $duplicate_count);

    gzip_file($combined_json_path, $combined_gz_path);
    PuntWorkLogger::info('GZIP compression completed', PuntWorkLogger::CONTEXT_FEED, [
        'gz_file' => $combined_gz_path,
    ]);
    error_log('[PUNTWORK] [JSONL-COMBINE] Fallback: GZIP compression completed for ' . $combined_gz_path);
}
