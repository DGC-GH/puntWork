<?php

/**
 * Advanced Memory Manager for large-scale imports
 *
 * @package    Puntwork
 * @subpackage Utilities
 * @since      1.0.9
 */

namespace Puntwork\Utilities;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Advanced Memory Manager for large-scale imports
 */
class AdvancedMemoryManager extends MemoryManager
{
    /**
     * Streaming JSONL processor for memory-efficient large file handling
     */
    public static function processJsonlStreaming(string $filePath, callable $processor, int $chunkSize = 1000): array
    {
        $stats = array(
        'total_processed' => 0,
        'memory_peaks'    => array(),
        'processing_time' => 0,
        );

        $startTime = microtime(true);

        if (! file_exists($filePath)) {
            throw new \Exception("File not found: $filePath");
        }

        $handle = fopen($filePath, 'r');
        if (! $handle) {
            throw new \Exception("Cannot open file: $filePath");
        }

        $buffer     = array();
        $lineNumber = 0;

        while (( $line = fgets($handle) ) !== false) {
            ++$lineNumber;
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            $item = json_decode($line, true);
            if ($item === null) {
                continue; // Skip invalid JSON
            }

            $buffer[] = $item;

            // Process in chunks
            if (count($buffer) >= $chunkSize) {
                $processed                 = $processor($buffer);
                $stats['total_processed'] += $processed;
                $stats['memory_peaks'][]   = memory_get_peak_usage(true);
                $buffer                    = array();

                // Memory check and cleanup
                self::checkAndCleanup();
            }
        }

        // Process remaining items
        if (! empty($buffer)) {
            $processed                 = $processor($buffer);
            $stats['total_processed'] += $processed;
            $stats['memory_peaks'][]   = memory_get_peak_usage(true);
        }

        fclose($handle);

        $stats['processing_time']         = microtime(true) - $startTime;
        $stats['avg_memory_peak'] = ! empty($stats['memory_peaks'])
        ? array_sum($stats['memory_peaks']) / count($stats['memory_peaks'])
        : 0;

        return $stats;
    }

    /**
     * Memory-mapped file reader for extremely large files
     */
    public static function readLargeFileChunk(string $filePath, int $offset, int $length): string
    {
        if (! function_exists('fopen')) {
            throw new \Exception('File functions not available');
        }

        $handle = fopen($filePath, 'r');
        if (! $handle) {
            throw new \Exception("Cannot open file: $filePath");
        }

        fseek($handle, $offset);
        $data = fread($handle, $length);
        fclose($handle);

        return $data;
    }

    /**
     * Adaptive batch sizing based on memory usage patterns
     */
    public static function calculateOptimalBatchSize(
        int $currentBatchSize,
        float $memoryUsage,
        float $targetMemoryRatio = 0.7
    ): int {
        $memoryLimit  = self::getMemoryLimitBytes();
        $currentRatio = $memoryUsage / $memoryLimit;

        if ($currentRatio > $targetMemoryRatio) {
            // Reduce batch size
            $newSize = max(1, (int) ( $currentBatchSize * 0.8 ));
        } elseif ($currentRatio < $targetMemoryRatio * 0.5) {
            // Can increase batch size
            $newSize = min($currentBatchSize * 2, 10000); // Cap at 10k
        } else {
            // Keep current size
            $newSize = $currentBatchSize;
        }

        return $newSize;
    }

    /**
     * Memory pool for reusable objects
     */
    private static $objectPool = array();

    public static function getFromPool(string $className, ...$args)
    {
        $key = $className . '_' . md5(serialize($args));

        if (isset(self::$objectPool[ $key ])) {
            return self::$objectPool[ $key ];
        }

        // Create new instance
        $instance                 = new $className(...$args);
        self::$objectPool[ $key ] = $instance;

        return $instance;
    }

    public static function clearPool(): void
    {
        self::$objectPool = array();
    }

    /**
     * Progressive memory cleanup
     */
    public static function checkAndCleanup(): void
    {
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = self::getMemoryLimitBytes();
        $ratio       = $memoryUsage / $memoryLimit;

        if ($ratio > 0.85) {
            // Aggressive cleanup
            self::clearPool();
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
            gc_collect_cycles();
        } elseif ($ratio > 0.75) {
            // Moderate cleanup
            gc_collect_cycles();
        }
    }

    /**
     * Memory usage prediction for batch operations
     */
    public static function predictMemoryUsage(int $batchSize, int $itemSizeEstimate = 1024): array
    {
        $baseMemory           = memory_get_usage(true);
        $estimatedBatchMemory = $batchSize * $itemSizeEstimate;
        $safetyBuffer         = 50 * 1024 * 1024; // 50MB safety buffer

        $predictedPeak = $baseMemory + $estimatedBatchMemory + $safetyBuffer;
        $memoryLimit   = self::getMemoryLimitBytes();

        return array(
        'predicted_peak'         => $predictedPeak,
        'memory_limit'           => $memoryLimit,
        'will_exceed_limit'      => $predicted_peak > $memoryLimit,
        'recommended_batch_size' => $predicted_peak > $memoryLimit ?
        max(1, (int) ( $batchSize * ( $memoryLimit - $baseMemory - $safetyBuffer ) / $estimatedBatchMemory )) :
        $batchSize,
        );
    }

    /**
     * Compressed caching for large datasets
     */
    public static function setCompressed(string $key, $data, string $group = '', int $expiration = 3600): bool
    {
        $compressed = gzcompress(serialize($data), 6);
        return self::set($key . '_compressed', $compressed, $group, $expiration);
    }

    public static function getCompressed(string $key, string $group = '')
    {
        $compressed = self::get($key . '_compressed', $group);
        if ($compressed === false) {
            return false;
        }

        return unserialize(gzuncompress($compressed));
    }
}
