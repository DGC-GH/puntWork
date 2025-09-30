<?php

/**
 * Adaptive Resource Allocation Manager for Import Performance.
 *
 * @since      1.0.9
 */

namespace Puntwork\Utilities;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adaptive Resource Allocation Manager.
 *
 * Dynamically adjusts PHP memory limits and execution times based on batch characteristics
 * to prevent over-allocation for small batches and under-allocation for large ones.
 */
class AdaptiveResourceManager
{
    /**
     * Resource allocation profiles.
     */
    private static array $resource_profiles = [
        'small' => [
            'memory_limit' => '256M',
            'max_execution_time' => 120,
            'memory_buffer' => 50 * 1024 * 1024, // 50MB buffer
            'description' => 'Small batches (< 50 items)',
        ],
        'medium' => [
            'memory_limit' => '512M',
            'max_execution_time' => 300,
            'memory_buffer' => 100 * 1024 * 1024, // 100MB buffer
            'description' => 'Medium batches (50-200 items)',
        ],
        'large' => [
            'memory_limit' => '1024M',
            'max_execution_time' => 600,
            'memory_buffer' => 200 * 1024 * 1024, // 200MB buffer
            'description' => 'Large batches (200-1000 items)',
        ],
        'xlarge' => [
            'memory_limit' => '2048M',
            'max_execution_time' => 1200,
            'memory_buffer' => 500 * 1024 * 1024, // 500MB buffer
            'description' => 'Extra large batches (> 1000 items)',
        ],
    ];

    /**
     * Historical performance data for adaptive learning.
     */
    private static array $performance_history = [];

    /**
     * Current resource allocation state.
     */
    private static array $current_allocation = [];

    /**
     * Analyze batch characteristics and allocate optimal resources.
     *
     * @param array $batch_data Batch data including items, metadata, etc.
     * @return array Resource allocation recommendations
     */
    public static function analyzeAndAllocate(array $batch_data): array
    {
        $batch_size = $batch_data['batch_size'] ?? count($batch_data['batch_items'] ?? []);
        $content_complexity = self::calculateContentComplexity($batch_data);
        $historical_performance = self::getHistoricalPerformance($batch_size);

        // Determine optimal resource profile
        $profile = self::selectOptimalProfile($batch_size, $content_complexity, $historical_performance);

        // Calculate dynamic adjustments
        $adjustments = self::calculateDynamicAdjustments($batch_data, $profile, $historical_performance);

        // Apply resource allocation
        $allocation = self::applyResourceAllocation($profile, $adjustments);

        // Store current allocation for monitoring
        self::$current_allocation = $allocation;

        // Record performance for learning system
        self::recordResourcePerformance(
            $allocation,
            [
                'batch_size' => $batch_size,
                'content_complexity' => $content_complexity,
                'profile_selected' => $profile,
            ]
        );

        return $allocation;
    }

    /**
     * Calculate content complexity score based on batch characteristics.
     */
    private static function calculateContentComplexity(array $batch_data): float
    {
        $complexity = 1.0; // Base complexity

        // Factor 1: Content length and field count
        $avg_field_count = 0;
        $avg_content_length = 0;
        $item_count = 0;

        if (isset($batch_data['batch_items'])) {
            foreach ($batch_data['batch_items'] as $item) {
                if (is_array($item)) {
                    $field_count = count($item);
                    $content_length = strlen(json_encode($item));
                    $avg_field_count += $field_count;
                    $avg_content_length += $content_length;
                    $item_count++;
                }
            }

            if ($item_count > 0) {
                $avg_field_count /= $item_count;
                $avg_content_length /= $item_count;

                // Increase complexity for items with many fields
                if ($avg_field_count > 20) {
                    $complexity *= 1.5;
                } elseif ($avg_field_count > 10) {
                    $complexity *= 1.2;
                }

                // Increase complexity for long content
                if ($avg_content_length > 10000) {
                    $complexity *= 1.8;
                } elseif ($avg_content_length > 5000) {
                    $complexity *= 1.4;
                }
            }
        }

        // Factor 2: ACF field complexity
        if (isset($batch_data['acf_fields']) && is_array($batch_data['acf_fields'])) {
            $acf_complexity = count($batch_data['acf_fields']) * 0.1;
            $complexity *= (1 + $acf_complexity);
        }

        // Factor 3: Taxonomy term count
        if (isset($batch_data['taxonomy_terms'])) {
            $tax_complexity = count($batch_data['taxonomy_terms']) * 0.05;
            $complexity *= (1 + $tax_complexity);
        }

        return min($complexity, 5.0); // Cap at 5x complexity
    }

    /**
     * Get historical performance data for similar batch sizes.
     */
    private static function getHistoricalPerformance(int $batch_size): array
    {
        $cache_key = 'adaptive_resources_history_' . floor($batch_size / 100) * 100;
        $cached = EnhancedCacheManager::get($cache_key, CacheManager::GROUP_ANALYTICS);

        if ($cached === false) {
            return [
                'avg_memory_mb' => 128,
                'avg_time_seconds' => 60,
                'success_rate' => 0.95,
                'sample_count' => 0,
            ];
        }

        return $cached;
    }

    /**
     * Select the optimal resource profile based on analysis.
     */
    private static function selectOptimalProfile(int $batch_size, float $complexity, array $historical): string
    {
        // Base selection on batch size
        if ($batch_size < 50) {
            $profile = 'small';
        } elseif ($batch_size < 200) {
            $profile = 'medium';
        } elseif ($batch_size < 1000) {
            $profile = 'large';
        } else {
            $profile = 'xlarge';
        }

        // Adjust based on complexity
        if ($complexity > 2.0 && $profile !== 'xlarge') {
            $profile = self::getNextProfile($profile);
        }

        // Adjust based on historical performance
        if ($historical['sample_count'] > 5) {
            $predicted_memory_mb = $historical['avg_memory_mb'] * $complexity;
            $predicted_time_sec = $historical['avg_time_seconds'] * $complexity;

            // If historical data suggests higher requirements, upgrade profile
            if ($predicted_memory_mb > 600 && $profile === 'medium') {
                $profile = 'large';
            } elseif ($predicted_memory_mb > 1200 && $profile === 'large') {
                $profile = 'xlarge';
            }

            if ($predicted_time_sec > 400 && $profile === 'medium') {
                $profile = 'large';
            } elseif ($predicted_time_sec > 800 && $profile === 'large') {
                $profile = 'xlarge';
            }
        }

        return $profile;
    }

    /**
     * Get the next higher resource profile.
     */
    private static function getNextProfile(string $current): string
    {
        $order = ['small', 'medium', 'large', 'xlarge'];
        $current_index = array_search($current, $order);

        if ($current_index !== false && $current_index < count($order) - 1) {
            return $order[$current_index + 1];
        }

        return $current;
    }

    /**
     * Calculate dynamic adjustments based on real-time conditions.
     */
    private static function calculateDynamicAdjustments(array $batch_data, string $profile, array $historical): array
    {
        $adjustments = [
            'memory_multiplier' => 1.0,
            'time_multiplier' => 1.0,
            'buffer_multiplier' => 1.0,
        ];

        // Adjust for system load
        $system_load = self::getSystemLoad();
        if ($system_load > 0.8) {
            $adjustments['memory_multiplier'] *= 1.2;
            $adjustments['time_multiplier'] *= 1.5;
        } elseif ($system_load < 0.3) {
            $adjustments['memory_multiplier'] *= 0.9;
            $adjustments['time_multiplier'] *= 0.8;
        }

        // Adjust for available memory
        $available_memory = self::getAvailableMemory();
        $profile_memory_bytes = self::parseMemoryLimit(self::$resource_profiles[$profile]['memory_limit']);

        if ($available_memory < $profile_memory_bytes * 1.5) {
            $adjustments['memory_multiplier'] *= 0.8;
            $adjustments['buffer_multiplier'] *= 0.5;
        }

        // Adjust for historical failure rate
        if ($historical['success_rate'] < 0.9 && $historical['sample_count'] > 10) {
            $adjustments['memory_multiplier'] *= 1.3;
            $adjustments['time_multiplier'] *= 1.4;
        }

        return $adjustments;
    }

    /**
     * Apply the calculated resource allocation.
     */
    private static function applyResourceAllocation(string $profile, array $adjustments): array
    {
        $base_profile = self::$resource_profiles[$profile];

        $allocation = [
            'profile' => $profile,
            'memory_limit' => self::adjustMemoryLimit($base_profile['memory_limit'], $adjustments['memory_multiplier']),
            'max_execution_time' => (int)($base_profile['max_execution_time'] * $adjustments['time_multiplier']),
            'memory_buffer' => (int)($base_profile['memory_buffer'] * $adjustments['buffer_multiplier']),
            'applied_at' => time(),
            'adjustments' => $adjustments,
        ];

        // Apply PHP settings
        self::applyPHPLimits($allocation);

        return $allocation;
    }

    /**
     * Adjust memory limit based on multiplier.
     */
    private static function adjustMemoryLimit(string $base_limit, float $multiplier): string
    {
        $bytes = self::parseMemoryLimit($base_limit);
        $adjusted_bytes = (int)($bytes * $multiplier);

        return self::formatMemoryLimit($adjusted_bytes);
    }

    /**
     * Parse memory limit string to bytes.
     */
    private static function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $unit = strtoupper(substr($limit, -1));
        $value = (int)substr($limit, 0, -1);

        switch ($unit) {
            case 'G':
                return $value * 1024 * 1024 * 1024;
            case 'M':
                return $value * 1024 * 1024;
            case 'K':
                return $value * 1024;
            default:
                return $value;
        }
    }

    /**
     * Format bytes as memory limit string.
     */
    private static function formatMemoryLimit(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024 * 1024), 1) . 'G';
        } elseif ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . 'M';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 1) . 'K';
        } else {
            return $bytes . 'B';
        }
    }

    /**
     * Apply PHP resource limits.
     */
    private static function applyPHPLimits(array $allocation): void
    {
        // Set memory limit
        if (function_exists('ini_set')) {
            ini_set('memory_limit', $allocation['memory_limit']);
        }

        // Set execution time limit
        if (function_exists('set_time_limit')) {
            set_time_limit($allocation['max_execution_time']);
        }

        // Set other performance-related ini settings
        ini_set('max_execution_time', (string)$allocation['max_execution_time']);
        ini_set('max_input_time', (string)min($allocation['max_execution_time'], 300));

        error_log(
            sprintf(
                '[PUNTWORK] [ADAPTIVE] Applied resource allocation - Memory: %s, Time: %ds, Profile: %s',
                $allocation['memory_limit'],
                $allocation['max_execution_time'],
                $allocation['profile']
            )
        );
    }

    /**
     * Get current system load (0-1 scale).
     */
    private static function getSystemLoad(): float
    {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if ($load) {
                // Normalize to 0-1 scale (assuming 4 cores as baseline)
                return min($load[0] / 4.0, 1.0);
            }
        }

        return 0.5; // Default moderate load
    }

    /**
     * Get available system memory in bytes.
     */
    private static function getAvailableMemory(): int
    {
        $memory_limit = ini_get('memory_limit');
        if ($memory_limit) {
            return self::parseMemoryLimit($memory_limit);
        }

        return 256 * 1024 * 1024; // Default 256MB
    }

    /**
     * Record performance metrics for adaptive learning.
     */
    public static function recordPerformanceMetrics(array $metrics): void
    {
        $batch_size = $metrics['batch_size'] ?? 0;
        $history_key = 'adaptive_resources_history_' . floor($batch_size / 100) * 100;

        $existing = EnhancedCacheManager::get($history_key, CacheManager::GROUP_ANALYTICS) ?: [
            'avg_memory_mb' => 0,
            'avg_time_seconds' => 0,
            'success_rate' => 1.0,
            'sample_count' => 0,
        ];

        // Update rolling averages
        $count = $existing['sample_count'] + 1;
        $weight = 1.0 / $count;

        $existing['avg_memory_mb'] = $existing['avg_memory_mb'] * (1 - $weight) + ($metrics['memory_mb'] ?? 128) * $weight;
        $existing['avg_time_seconds'] = $existing['avg_time_seconds'] * (1 - $weight) + ($metrics['time_seconds'] ?? 60) * $weight;
        $existing['success_rate'] = $existing['success_rate'] * (1 - $weight) + ($metrics['success'] ? 1 : 0) * $weight;
        $existing['sample_count'] = $count;

        EnhancedCacheManager::set($history_key, $existing, CacheManager::GROUP_ANALYTICS, HOUR_IN_SECONDS);
    }

    /**
     * Get current resource allocation status.
     */
    public static function getCurrentAllocation(): array
    {
        return self::$current_allocation ?: ['status' => 'no_allocation'];
    }

    /**
     * Reset resource allocation to defaults.
     */
    public static function resetToDefaults(): void
    {
        if (function_exists('ini_restore')) {
            ini_restore('memory_limit');
            ini_restore('max_execution_time');
        }

        self::$current_allocation = [];
    }

    /**
     * Get resource allocation statistics.
     */
    public static function getStats(): array
    {
        return [
            'profiles' => self::$resource_profiles,
            'current_allocation' => self::getCurrentAllocation(),
            'system_load' => self::getSystemLoad(),
            'available_memory' => self::getAvailableMemory(),
        ];
    }

    /**
     * Get resource profiles.
     */
    public static function getResourceProfiles(): array
    {
        return self::$resource_profiles;
    }

    /**
     * Update resource profiles.
     */
    public static function updateResourceProfiles(array $new_profiles): bool
    {
        self::$resource_profiles = array_merge(self::$resource_profiles, $new_profiles);

        return true;
    }

    /**
     * Record resource allocation performance for learning.
     */
    public static function recordResourcePerformance(array $allocation, array $context = []): bool
    {
        if (!class_exists('Puntwork\\Utilities\\IterativeLearner')) {
            return false;
        }

        $performance_data = [
            'optimization_type' => 'resource_allocation',
            'memory_limit_mb' => self::parseMemoryLimit($allocation['memory_limit']) / (1024 * 1024), // Convert bytes to MB
            'max_execution_time' => $allocation['max_execution_time'],
            'memory_buffer_mb' => $allocation['memory_buffer'] / (1024 * 1024),
            'system_load' => self::getSystemLoad(),
            'available_memory_mb' => self::getAvailableMemory() / (1024 * 1024),
        ];

        return IterativeLearner::recordSessionPerformance($performance_data, $context);
    }
}
