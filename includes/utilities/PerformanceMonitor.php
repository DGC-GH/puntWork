<?php

/**
 * Performance monitoring utilities
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
 * Performance monitoring class
 */
class PerformanceMonitor
{
    private static array $measurements = [];
    private static array $checkpoints = [];

    /**
     * Start performance monitoring
     *
     * @param string $operation Operation name
     * @return string Measurement ID
     */
    public static function start(string $operation): string
    {
        $id = uniqid('perf_', true);
        self::$measurements[$id] = [
            'operation' => $operation,
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true),
            'checkpoints' => []
        ];
        return $id;
    }

    /**
     * Add checkpoint to performance monitoring
     *
     * @param string $id Measurement ID
     * @param string $checkpoint Checkpoint name
     * @param array $data Additional data
     */
    public static function checkpoint(string $id, string $checkpoint, array $data = []): void
    {
        if (!isset(self::$measurements[$id])) {
            return;
        }

        $timestamp = microtime(true);
        $elapsed = $timestamp - self::$measurements[$id]['start_time'];
        $memory = memory_get_usage(true);

        self::$measurements[$id]['checkpoints'][] = [
            'name' => $checkpoint,
            'time' => $timestamp,
            'elapsed' => $elapsed,
            'memory' => $memory,
            'data' => $data
        ];
    }

    /**
     * End performance monitoring
     *
     * @param string $id Measurement ID
     * @return array Performance data
     */
    public static function end(string $id): array
    {
        if (!isset(self::$measurements[$id])) {
            return [];
        }

        $measurement = self::$measurements[$id];
        $end_time = microtime(true);
        $end_memory = memory_get_usage(true);

        $result = [
            'operation' => $measurement['operation'],
            'start_time' => $measurement['start_time'],
            'end_time' => $end_time,
            'duration' => $end_time - $measurement['start_time'],
            'start_memory' => $measurement['start_memory'],
            'end_memory' => $end_memory,
            'memory_used' => $end_memory - $measurement['start_memory'],
            'checkpoints' => $measurement['checkpoints']
        ];

        unset(self::$measurements[$id]);
        return $result;
    }

    /**
     * Get current performance snapshot
     *
     * @return array Current performance data
     */
    public static function snapshot(): array
    {
        return [
            'current_memory' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'active_measurements' => count(self::$measurements),
            'measurements' => self::$measurements
        ];
    }

    /**
     * Get performance statistics
     *
     * @param string $operation Operation name
     * @param int $days Number of days to look back
     * @return array Performance statistics
     */
    public static function getStatistics(string $operation = '', int $days = 30): array
    {
        // For now, return mock statistics
        // In a real implementation, this would query stored performance data
        return [
            'operation' => $operation,
            'total_measurements' => 0,
            'avg_duration' => 0,
            'avg_memory' => 0,
            'period_days' => $days
        ];
    }

    /**
     * Clean up old performance logs
     *
     * @param int $days Days to keep
     * @return bool Success
     */
    public static function cleanup_old_logs(int $days): bool
    {
        // Mock implementation - in real implementation would clean up old logs
        return true;
    }
}
