<?php

/**
 * Advanced JSONL Processing System for Combined Feed Operations.
 *
 * @since      1.0.9
 */

namespace Puntwork\Utilities;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Advanced JSONL Processing System.
 *
 * Optimized for combining, processing, and managing large JSONL feed files
 * with streaming, parallel processing, and intelligent deduplication.
 */
class AdvancedJsonlProcessor
{
    /**
     * Processing configuration.
     */
    private static array $config = [
        'chunk_size' => 1000,
        'memory_buffer_mb' => 50,
        'max_parallel_feeds' => 5,
        'compression_level' => 6,
        'deduplication_cache_ttl' => 3600, // 1 hour
        'progress_reporting_interval' => 1000,
    ];

    /**
     * Stream-based JSONL file combination with advanced optimizations.
     *
     * @param array  $feed_files Array of feed file paths to combine
     * @param string $output_path Output combined JSONL file path
     * @param array  &$stats Processing statistics
     * @return bool Success status
     */
    public static function combineJsonlStreaming(array $feed_files, string $output_path, array &$stats = []): bool
    {
        $start_time = microtime(true);
        $stats = [
            'total_files' => count($feed_files),
            'total_lines_processed' => 0,
            'unique_items' => 0,
            'duplicates_removed' => 0,
            'invalid_lines' => 0,
            'processing_time' => 0,
            'memory_peak_mb' => 0,
            'files_processed' => [],
        ];

        try {
            // Initialize deduplication cache
            $dedup_cache = self::initializeDeduplicationCache();

            // Open output file
            $output_handle = fopen($output_path, 'w');
            if (!$output_handle) {
                throw new \Exception("Cannot open output file: $output_path");
            }

            // Process feeds in optimized order (largest first for better memory usage)
            $ordered_feeds = self::orderFeedsBySize($feed_files);

            foreach ($ordered_feeds as $feed_path) {
                $feed_stats = self::processFeedStreaming($feed_path, $output_handle, $dedup_cache);
                $stats['files_processed'][] = [
                    'file' => basename($feed_path),
                    'lines_processed' => $feed_stats['lines_processed'],
                    'unique_added' => $feed_stats['unique_added'],
                    'duplicates' => $feed_stats['duplicates'],
                    'invalid' => $feed_stats['invalid'],
                ];

                $stats['total_lines_processed'] += $feed_stats['lines_processed'];
                $stats['unique_items'] += $feed_stats['unique_added'];
                $stats['duplicates_removed'] += $feed_stats['duplicates'];
                $stats['invalid_lines'] += $feed_stats['invalid'];

                // Memory management checkpoint
                $current_memory = memory_get_peak_usage(true) / 1024 / 1024;
                $stats['memory_peak_mb'] = max($stats['memory_peak_mb'], $current_memory);

                if ($current_memory > self::$config['memory_buffer_mb']) {
                    self::performMemoryCleanup();
                }

                // Progress reporting
                if ($stats['total_lines_processed'] % self::$config['progress_reporting_interval'] === 0) {
                    error_log(
                        sprintf(
                            '[PUNTWORK] [JSONL-COMBINE] Progress: %d lines processed, %d unique, %d duplicates',
                            $stats['total_lines_processed'],
                            $stats['unique_items'],
                            $stats['duplicates_removed']
                        )
                    );
                }
            }

            fclose($output_handle);

            // Compress the output file
            self::compressOutputFile($output_path);

            $stats['processing_time'] = microtime(true) - $start_time;

            error_log(
                sprintf(
                    '[PUNTWORK] [JSONL-COMBINE] Streaming combination completed: %d files, %d lines, %d unique items, %d duplicates removed in %.3f seconds',
                    $stats['total_files'],
                    $stats['total_lines_processed'],
                    $stats['unique_items'],
                    $stats['duplicates_removed'],
                    $stats['processing_time']
                )
            );

            return true;
        } catch (\Exception $e) {
            error_log('[PUNTWORK] [JSONL-COMBINE] Streaming combination failed: ' . $e->getMessage());
            if (isset($output_handle) && $output_handle) {
                fclose($output_handle);
            }

            return false;
        }
    }

    /**
     * Process a single feed file using streaming approach.
     */
    private static function processFeedStreaming(string $feed_path, $output_handle, &$dedup_cache): array
    {
        $stats = [
            'lines_processed' => 0,
            'unique_added' => 0,
            'duplicates' => 0,
            'invalid' => 0,
        ];

        if (!file_exists($feed_path)) {
            error_log("[PUNTWORK] [JSONL-COMBINE] Feed file not found: $feed_path");

            return $stats;
        }

        $handle = fopen($feed_path, 'r');
        if (!$handle) {
            error_log("[PUNTWORK] [JSONL-COMBINE] Cannot open feed file: $feed_path");

            return $stats;
        }

        $buffer = [];
        $buffer_size = 0;
        $max_buffer_size = self::$config['chunk_size'];

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            $stats['lines_processed']++;

            if (empty($line)) {
                continue;
            }

            // Parse JSON
            $item = json_decode($line, true);
            if ($item === null) {
                $stats['invalid']++;

                continue;
            }

            // Check for GUID
            $guid = $item['guid'] ?? '';
            if (empty($guid)) {
                // No GUID - include but mark as potentially duplicate-prone
                $buffer[] = $line;
                $buffer_size++;
            } else {
                // Check deduplication cache
                $item_hash = self::calculateItemHash($item);
                if (isset($dedup_cache[$guid])) {
                    // Check if this is actually a duplicate or just same GUID with different content
                    if ($dedup_cache[$guid] === $item_hash) {
                        $stats['duplicates']++;

                        continue;
                    } else {
                        // Same GUID but different content - this might be an update
                        // For now, treat as duplicate to be safe
                        $stats['duplicates']++;

                        continue;
                    }
                }

                // New unique item
                $dedup_cache[$guid] = $item_hash;
                $buffer[] = $line;
                $buffer_size++;
                $stats['unique_added']++;
            }

            // Flush buffer when full
            if ($buffer_size >= $max_buffer_size) {
                self::flushBuffer($buffer, $output_handle);
                $buffer = [];
                $buffer_size = 0;
            }
        }

        // Flush remaining buffer
        if (!empty($buffer)) {
            self::flushBuffer($buffer, $output_handle);
        }

        fclose($handle);

        return $stats;
    }

    /**
     * Parallel JSONL combination using multiple processes.
     *
     * @param array  $feed_files Array of feed file paths
     * @param string $output_path Output combined JSONL file path
     * @param array  &$stats Processing statistics
     * @return bool Success status
     */
    public static function combineJsonlParallel(array $feed_files, string $output_path, array &$stats = []): bool
    {
        $start_time = microtime(true);

        // Check if parallel processing is available
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            error_log('[PUNTWORK] [JSONL-COMBINE] Parallel processing not available, falling back to streaming');

            return self::combineJsonlStreaming($feed_files, $output_path, $stats);
        }

        $stats = [
            'processing_mode' => 'parallel',
            'workers_used' => min(count($feed_files), self::$config['max_parallel_feeds']),
            'total_files' => count($feed_files),
            'processing_time' => 0,
        ];

        try {
            // Split feeds into chunks for parallel processing
            $feed_chunks = array_chunk($feed_files, ceil(count($feed_files) / $stats['workers_used']));

            $worker_pids = [];
            $temp_files = [];

            // Start worker processes
            foreach ($feed_chunks as $chunk_index => $chunk) {
                $temp_file = $output_path . ".part.$chunk_index";
                $temp_files[] = $temp_file;

                $pid = pcntl_fork();
                if ($pid == -1) {
                    throw new \Exception('Failed to fork worker process');
                } elseif ($pid == 0) {
                    // Child process
                    $worker_stats = [];
                    self::combineJsonlStreaming($chunk, $temp_file, $worker_stats);
                    exit(0);
                } else {
                    // Parent process
                    $worker_pids[] = $pid;
                }
            }

            // Wait for all workers to complete
            foreach ($worker_pids as $pid) {
                pcntl_waitpid($pid, $status);
                if ($status !== 0) {
                    error_log("[PUNTWORK] [JSONL-COMBINE] Worker process $pid failed with status $status");
                }
            }

            // Merge temporary files
            self::mergeTemporaryFiles($temp_files, $output_path, $stats);

            // Cleanup temporary files
            foreach ($temp_files as $temp_file) {
                if (file_exists($temp_file)) {
                    unlink($temp_file);
                }
            }

            $stats['processing_time'] = microtime(true) - $start_time;

            error_log(
                sprintf(
                    '[PUNTWORK] [JSONL-COMBINE] Parallel combination completed: %d workers, %d files in %.3f seconds',
                    $stats['workers_used'],
                    $stats['total_files'],
                    $stats['processing_time']
                )
            );

            return true;
        } catch (\Exception $e) {
            error_log('[PUNTWORK] [JSONL-COMBINE] Parallel combination failed: ' . $e->getMessage());

            // Cleanup on failure
            foreach ($temp_files as $temp_file) {
                if (file_exists($temp_file)) {
                    unlink($temp_file);
                }
            }

            return false;
        }
    }

    /**
     * Progressive JSONL combination for incremental updates.
     *
     * @param array  $new_feed_files New feed files to add
     * @param string $existing_combined_path Path to existing combined file
     * @param array  &$stats Processing statistics
     * @return bool Success status
     */
    public static function combineJsonlProgressive(array $new_feed_files, string $existing_combined_path, array &$stats = []): bool
    {
        $start_time = microtime(true);

        $stats = [
            'processing_mode' => 'progressive',
            'new_files' => count($new_feed_files),
            'existing_file_size' => file_exists($existing_combined_path) ? filesize($existing_combined_path) : 0,
            'processing_time' => 0,
        ];

        try {
            // Load existing deduplication cache if available
            $dedup_cache = self::loadExistingDeduplicationCache($existing_combined_path);

            // Create temporary file for new content
            $temp_file = $existing_combined_path . '.temp';
            $temp_handle = fopen($temp_file, 'w');

            if (!$temp_handle) {
                throw new \Exception("Cannot create temporary file: $temp_file");
            }

            // Process new feeds
            $new_items_added = 0;
            foreach ($new_feed_files as $feed_path) {
                $feed_stats = self::processFeedStreaming($feed_path, $temp_handle, $dedup_cache);
                $new_items_added += $feed_stats['unique_added'];
            }

            fclose($temp_handle);

            if ($new_items_added === 0) {
                // No new items, just clean up
                unlink($temp_file);
                $stats['processing_time'] = microtime(true) - $start_time;

                return true;
            }

            // Append new content to existing file
            if (file_exists($existing_combined_path)) {
                $existing_handle = fopen($existing_combined_path, 'a');
                $temp_handle = fopen($temp_file, 'r');

                while (($line = fgets($temp_handle)) !== false) {
                    fwrite($existing_handle, $line);
                }

                fclose($temp_handle);
                fclose($existing_handle);
            } else {
                // No existing file, just rename temp file
                rename($temp_file, $existing_combined_path);
            }

            // Cleanup
            if (file_exists($temp_file)) {
                unlink($temp_file);
            }

            // Update deduplication cache
            self::saveDeduplicationCache($dedup_cache, $existing_combined_path);

            $stats['new_items_added'] = $new_items_added;
            $stats['processing_time'] = microtime(true) - $start_time;

            error_log(
                sprintf(
                    '[PUNTWORK] [JSONL-COMBINE] Progressive combination completed: %d new files, %d new items added in %.3f seconds',
                    $stats['new_files'],
                    $new_items_added,
                    $stats['processing_time']
                )
            );

            return true;
        } catch (\Exception $e) {
            error_log('[PUNTWORK] [JSONL-COMBINE] Progressive combination failed: ' . $e->getMessage());

            // Cleanup on failure
            if (isset($temp_file) && file_exists($temp_file)) {
                unlink($temp_file);
            }

            return false;
        }
    }

    /**
     * Initialize deduplication cache.
     */
    private static function initializeDeduplicationCache(): array
    {
        return [];
    }

    /**
     * Load existing deduplication cache from combined file.
     */
    private static function loadExistingDeduplicationCache(string $combined_path): array
    {
        $cache_key = 'jsonl_dedup_' . md5($combined_path);
        $cached = EnhancedCacheManager::get($cache_key, CacheManager::GROUP_ANALYTICS);

        if ($cached !== false) {
            return $cached;
        }

        // Rebuild cache from existing file (expensive but necessary)
        $cache = [];
        if (file_exists($combined_path)) {
            $handle = fopen($combined_path, 'r');
            if ($handle) {
                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);
                    if (empty($line)) {
                        continue;
                    }

                    $item = json_decode($line, true);
                    if ($item && isset($item['guid'])) {
                        $cache[$item['guid']] = self::calculateItemHash($item);
                    }
                }
                fclose($handle);
            }
        }

        // Cache for future use
        EnhancedCacheManager::set($cache_key, $cache, CacheManager::GROUP_ANALYTICS, self::$config['deduplication_cache_ttl']);

        return $cache;
    }

    /**
     * Save deduplication cache.
     */
    private static function saveDeduplicationCache(array $cache, string $combined_path): void
    {
        $cache_key = 'jsonl_dedup_' . md5($combined_path);
        EnhancedCacheManager::setCompressed($cache_key, $cache, CacheManager::GROUP_ANALYTICS, self::$config['deduplication_cache_ttl']);
    }

    /**
     * Calculate hash for deduplication.
     */
    private static function calculateItemHash(array $item): string
    {
        // Remove volatile fields for consistent hashing
        $hash_item = $item;
        unset($hash_item['import_timestamp'], $hash_item['processed_at'], $hash_item['batch_id']);

        // Sort for consistent hashing
        ksort($hash_item);

        return md5(json_encode($hash_item));
    }

    /**
     * Order feed files by size for optimal processing.
     */
    private static function orderFeedsBySize(array $feed_files): array
    {
        $file_sizes = [];
        foreach ($feed_files as $file) {
            $file_sizes[$file] = file_exists($file) ? filesize($file) : 0;
        }

        // Sort by size descending (largest first)
        arsort($file_sizes);

        return array_keys($file_sizes);
    }

    /**
     * Flush buffer to output file.
     */
    private static function flushBuffer(array $buffer, $output_handle): void
    {
        foreach ($buffer as $line) {
            fwrite($output_handle, $line . "\n");
        }
    }

    /**
     * Compress output file.
     */
    private static function compressOutputFile(string $output_path): void
    {
        $gz_path = $output_path . '.gz';
        if (function_exists('gzopen')) {
            $gz_handle = gzopen($gz_path, 'w' . self::$config['compression_level']);
            if ($gz_handle) {
                $handle = fopen($output_path, 'r');
                if ($handle) {
                    while (($line = fgets($handle)) !== false) {
                        gzwrite($gz_handle, $line);
                    }
                    fclose($handle);
                }
                gzclose($gz_handle);
            }
        }
    }

    /**
     * Merge temporary files from parallel processing.
     */
    private static function mergeTemporaryFiles(array $temp_files, string $output_path, array &$stats): void
    {
        $output_handle = fopen($output_path, 'w');
        $dedup_cache = [];

        foreach ($temp_files as $temp_file) {
            if (!file_exists($temp_file)) {
                continue;
            }

            $temp_handle = fopen($temp_file, 'r');
            if (!$temp_handle) {
                continue;
            }

            while (($line = fgets($temp_handle)) !== false) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                $item = json_decode($line, true);
                if ($item && isset($item['guid'])) {
                    $guid = $item['guid'];
                    $item_hash = self::calculateItemHash($item);

                    if (!isset($dedup_cache[$guid])) {
                        $dedup_cache[$guid] = $item_hash;
                        fwrite($output_handle, $line . "\n");
                        $stats['unique_items'] = ($stats['unique_items'] ?? 0) + 1;
                    } else {
                        $stats['duplicates_removed'] = ($stats['duplicates_removed'] ?? 0) + 1;
                    }
                } else {
                    // No GUID, include anyway
                    fwrite($output_handle, $line . "\n");
                    $stats['unique_items'] = ($stats['unique_items'] ?? 0) + 1;
                }
            }

            fclose($temp_handle);
        }

        fclose($output_handle);
    }

    /**
     * Perform memory cleanup.
     */
    private static function performMemoryCleanup(): void
    {
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
    }

    /**
     * Configure processing parameters.
     */
    public static function configure(array $config): void
    {
        self::$config = array_merge(self::$config, $config);
    }

    /**
     * Get processing statistics.
     */
    public static function getStats(): array
    {
        return self::$config;
    }

    /**
     * Validate JSONL file with advanced checks.
     */
    public static function validateJsonlAdvanced(string $file_path, array &$validation_stats = []): bool
    {
        $validation_stats = [
            'total_lines' => 0,
            'valid_lines' => 0,
            'invalid_json' => 0,
            'missing_guid' => 0,
            'duplicate_guids' => 0,
            'guids_seen' => [],
            'validation_time' => 0,
        ];

        $start_time = microtime(true);

        if (!file_exists($file_path)) {
            return false;
        }

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return false;
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            $validation_stats['total_lines']++;

            if (empty($line)) {
                continue;
            }

            $item = json_decode($line, true);
            if ($item === null) {
                $validation_stats['invalid_json']++;

                continue;
            }

            $validation_stats['valid_lines']++;

            $guid = $item['guid'] ?? '';
            if (empty($guid)) {
                $validation_stats['missing_guid']++;
            } elseif (isset($validation_stats['guids_seen'][$guid])) {
                $validation_stats['duplicate_guids']++;
            } else {
                $validation_stats['guids_seen'][$guid] = true;
            }
        }

        fclose($handle);

        $validation_stats['validation_time'] = microtime(true) - $start_time;
        $validation_stats['unique_guids'] = count($validation_stats['guids_seen']);

        return $validation_stats['invalid_json'] === 0;
    }
}
