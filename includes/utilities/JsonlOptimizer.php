<?php

/**
 * JSONL Optimization System for Batch Processing Synergy.
 *
 * @since      1.0.9
 */

namespace Puntwork\Utilities;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * JSONL Optimization System.
 *
 * Optimizes JSONL file organization for maximum batch processing performance
 * through intelligent sorting, grouping, and indexing strategies.
 */
class JsonlOptimizer
{
    /**
     * Optimization strategies configuration.
     */
    private static array $optimization_config = [
        'sort_by_update_frequency' => [
            'enabled' => true,
            'weight' => 0.4,
            'description' => 'Sort by historical update frequency',
        ],
        'group_by_content_type' => [
            'enabled' => true,
            'weight' => 0.3,
            'description' => 'Group similar content types together',
        ],
        'sort_by_complexity' => [
            'enabled' => true,
            'weight' => 0.2,
            'description' => 'Sort by content complexity for memory management',
        ],
        'cluster_by_location' => [
            'enabled' => true,
            'weight' => 0.1,
            'description' => 'Group by geographic location',
        ],
        'generate_batch_index' => [
            'enabled' => true,
            'batch_size' => 100,
            'description' => 'Generate index for batch boundary optimization',
        ],
    ];

    /**
     * Optimize JSONL file for batch processing performance.
     *
     * @param string $input_file Input JSONL file path
     * @param string $output_file Output optimized JSONL file path
     * @param array &$stats Optimization statistics
     * @return bool Success status
     */
    public static function optimizeForBatchProcessing(string $input_file, string $output_file, array &$stats = []): bool
    {
        $start_time = microtime(true);
        $stats = [
            'input_file_size' => 0,
            'output_file_size' => 0,
            'items_processed' => 0,
            'optimization_time' => 0,
            'memory_peak_mb' => 0,
            'strategies_applied' => [],
            'grouping_stats' => [],
        ];

        try {
            if (!file_exists($input_file)) {
                throw new \Exception("Input file not found: $input_file");
            }

            $stats['input_file_size'] = filesize($input_file);

            // Load and analyze all items
            $items = self::loadAndAnalyzeItems($input_file, $stats);

            if (empty($items)) {
                throw new \Exception('No valid items found in input file');
            }

            // Apply optimization strategies
            $optimized_items = self::applyOptimizationStrategies($items, $stats);

            // Write optimized JSONL file
            self::writeOptimizedJsonl($optimized_items, $output_file, $stats);

            // Generate batch index if enabled
            if (self::$optimization_config['generate_batch_index']['enabled']) {
                self::generateBatchIndex($optimized_items, $output_file, $stats);
            }

            $stats['output_file_size'] = filesize($output_file);
            $stats['optimization_time'] = microtime(true) - $start_time;
            $stats['memory_peak_mb'] = memory_get_peak_usage(true) / 1024 / 1024;

            error_log(sprintf(
                '[PUNTWORK] [JSONL-OPTIMIZE] Optimization completed: %d items processed, %d strategies applied, %.3f seconds, %.1f MB memory',
                $stats['items_processed'],
                count($stats['strategies_applied']),
                $stats['optimization_time'],
                $stats['memory_peak_mb']
            ));

            // Record performance for learning system
            self::recordOptimizationPerformance($stats, [
                'input_file' => basename($input_file),
                'output_file' => basename($output_file),
                'optimization_config' => self::$optimization_config,
            ]);

            return true;
        } catch (\Exception $e) {
            error_log('[PUNTWORK] [JSONL-OPTIMIZE] Optimization failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Load and analyze items from JSONL file.
     */
    private static function loadAndAnalyzeItems(string $input_file, array &$stats): array
    {
        $items = [];
        $handle = fopen($input_file, 'r');

        if (!$handle) {
            throw new \Exception("Cannot open input file: $input_file");
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $item = json_decode($line, true);
            if ($item === null) {
                continue;
            }

            // Analyze item for optimization metadata
            $analyzed_item = self::analyzeItemForOptimization($item);
            $items[] = $analyzed_item;
            $stats['items_processed']++;
        }

        fclose($handle);

        return $items;
    }

    /**
     * Analyze individual item for optimization metadata.
     */
    private static function analyzeItemForOptimization(array $item): array
    {
        $analysis = [
            'data' => $item,
            'guid' => $item['guid'] ?? '',
            'optimization_scores' => [],
            'metadata' => [],
        ];

        // Calculate update frequency score (based on timestamps)
        if (self::$optimization_config['sort_by_update_frequency']['enabled']) {
            $analysis['optimization_scores']['update_frequency'] = self::calculateUpdateFrequencyScore($item);
        }

        // Calculate content type grouping
        if (self::$optimization_config['group_by_content_type']['enabled']) {
            $analysis['metadata']['content_type'] = self::determineContentType($item);
            $analysis['optimization_scores']['content_type'] = self::getContentTypePriority($analysis['metadata']['content_type']);
        }

        // Calculate complexity score
        if (self::$optimization_config['sort_by_complexity']['enabled']) {
            $analysis['optimization_scores']['complexity'] = self::calculateComplexityScore($item);
        }

        // Calculate location clustering
        if (self::$optimization_config['cluster_by_location']['enabled']) {
            $analysis['metadata']['location'] = self::extractLocation($item);
            $analysis['optimization_scores']['location'] = self::getLocationPriority($analysis['metadata']['location']);
        }

        // Calculate overall optimization score
        $analysis['overall_score'] = self::calculateOverallScore($analysis['optimization_scores']);

        return $analysis;
    }

    /**
     * Calculate update frequency score based on item timestamps.
     */
    private static function calculateUpdateFrequencyScore(array $item): float
    {
        $update_time = strtotime($item['updated'] ?? $item['date'] ?? '');
        if (!$update_time) {
            return 0.5; // Neutral score for items without timestamps
        }

        $days_since_update = (time() - $update_time) / (60 * 60 * 24);

        // Items updated more recently get higher scores (processed first)
        if ($days_since_update < 1) {
            return 1.0;
        }     // Updated today
        if ($days_since_update < 7) {
            return 0.9;
        }     // Updated this week
        if ($days_since_update < 30) {
            return 0.7;
        }    // Updated this month
        if ($days_since_update < 90) {
            return 0.5;
        }    // Updated this quarter

        return 0.3;                                 // Updated longer ago
    }

    /**
     * Determine content type for grouping.
     */
    private static function determineContentType(array $item): string
    {
        // Check job type first
        if (isset($item['job_type'])) {
            return 'job_' . strtolower(str_replace(' ', '_', $item['job_type']));
        }

        // Check category
        if (isset($item['category'])) {
            return 'category_' . strtolower(str_replace(' ', '_', $item['category']));
        }

        // Check title keywords
        $title = strtolower($item['title'] ?? '');
        if (strpos($title, 'engineer') !== false || strpos($title, 'developer') !== false) {
            return 'tech_engineering';
        }
        if (strpos($title, 'manager') !== false || strpos($title, 'director') !== false) {
            return 'management';
        }
        if (strpos($title, 'analyst') !== false || strpos($title, 'data') !== false) {
            return 'analytics_data';
        }
        if (strpos($title, 'designer') !== false || strpos($title, 'creative') !== false) {
            return 'design_creative';
        }

        return 'general';
    }

    /**
     * Get content type priority for sorting.
     */
    private static function getContentTypePriority(string $content_type): float
    {
        $priorities = [
            'tech_engineering' => 0.9,
            'management' => 0.8,
            'analytics_data' => 0.8,
            'design_creative' => 0.7,
            'general' => 0.5,
        ];

        // Extract base type if it's a job_type variant
        if (strpos($content_type, 'job_') === 0) {
            $base_type = substr($content_type, 4);

            return $priorities[$base_type] ?? 0.6;
        }

        return $priorities[$content_type] ?? 0.5;
    }

    /**
     * Calculate content complexity score.
     */
    private static function calculateComplexityScore(array $item): float
    {
        $score = 0.0;

        // Field count contributes to complexity
        $field_count = count($item);
        $score += min($field_count / 20, 0.3); // Max 0.3 for field count

        // ACF fields increase complexity
        if (isset($item['acf']) && is_array($item['acf'])) {
            $acf_count = count($item['acf']);
            $score += min($acf_count / 10, 0.4); // Max 0.4 for ACF fields
        }

        // Long descriptions increase complexity
        $text_fields = ['description', 'content', 'excerpt', 'job_description'];
        foreach ($text_fields as $field) {
            if (isset($item[$field])) {
                $length = strlen($item[$field]);
                $score += min($length / 5000, 0.2); // Max 0.2 for text length
            }
        }

        // Location data increases complexity
        if (isset($item['location']) || isset($item['city']) || isset($item['state'])) {
            $score += 0.1;
        }

        return min($score, 1.0);
    }

    /**
     * Extract location information for clustering.
     */
    private static function extractLocation(array $item): string
    {
        $location_parts = [];

        if (isset($item['city'])) {
            $location_parts[] = $item['city'];
        }
        if (isset($item['state'])) {
            $location_parts[] = $item['state'];
        }
        if (isset($item['country'])) {
            $location_parts[] = $item['country'];
        }
        if (isset($item['location'])) {
            $location_parts[] = $item['location'];
        }

        return implode(', ', $location_parts) ?: 'unknown';
    }

    /**
     * Get location priority for geographic clustering.
     */
    private static function getLocationPriority(string $location): float
    {
        // This could be enhanced with geographic importance data
        // For now, use string hash for consistent ordering
        return (crc32($location) % 1000) / 1000.0;
    }

    /**
     * Calculate overall optimization score.
     */
    private static function calculateOverallScore(array $scores): float
    {
        $total_score = 0.0;
        $total_weight = 0.0;

        foreach (self::$optimization_config as $strategy => $config) {
            if ($config['enabled'] && isset($scores[$strategy])) {
                $total_score += $scores[$strategy] * $config['weight'];
                $total_weight += $config['weight'];
            }
        }

        return $total_weight > 0 ? $total_score / $total_weight : 0.5;
    }

    /**
     * Apply optimization strategies to sort and group items.
     */
    private static function applyOptimizationStrategies(array $items, array &$stats): array
    {
        $strategies_applied = [];

        // Multi-dimensional sort by overall score (descending - higher scores first)
        usort($items, function ($a, $b) {
            return $b['overall_score'] <=> $a['overall_score'];
        });

        $strategies_applied[] = 'overall_score_sorting';
        $stats['strategies_applied'] = $strategies_applied;

        // Calculate grouping statistics
        $stats['grouping_stats'] = self::calculateGroupingStats($items);

        return $items;
    }

    /**
     * Calculate statistics about the groupings.
     */
    private static function calculateGroupingStats(array $items): array
    {
        $stats = [
            'content_type_distribution' => [],
            'complexity_distribution' => [
                'low' => 0,    // < 0.3
                'medium' => 0, // 0.3-0.7
                'high' => 0,   // > 0.7
            ],
            'update_frequency_distribution' => [
                'recent' => 0,    // > 0.8
                'medium' => 0,    // 0.4-0.8
                'old' => 0,       // < 0.4
            ],
        ];

        foreach ($items as $item) {
            // Content type distribution
            $content_type = $item['metadata']['content_type'] ?? 'unknown';
            $stats['content_type_distribution'][$content_type] = ($stats['content_type_distribution'][$content_type] ?? 0) + 1;

            // Complexity distribution
            $complexity = $item['optimization_scores']['complexity'] ?? 0;
            if ($complexity < 0.3) {
                $stats['complexity_distribution']['low']++;
            } elseif ($complexity < 0.7) {
                $stats['complexity_distribution']['medium']++;
            } else {
                $stats['complexity_distribution']['high']++;
            }

            // Update frequency distribution
            $update_freq = $item['optimization_scores']['update_frequency'] ?? 0.5;
            if ($update_freq > 0.8) {
                $stats['update_frequency_distribution']['recent']++;
            } elseif ($update_freq > 0.4) {
                $stats['update_frequency_distribution']['medium']++;
            } else {
                $stats['update_frequency_distribution']['old']++;
            }
        }

        return $stats;
    }

    /**
     * Write optimized JSONL file.
     */
    private static function writeOptimizedJsonl(array $items, string $output_file, array &$stats): void
    {
        $handle = fopen($output_file, 'w');
        if (!$handle) {
            throw new \Exception("Cannot open output file: $output_file");
        }

        foreach ($items as $item) {
            $json_line = json_encode($item['data']);
            fwrite($handle, $json_line . "\n");
        }

        fclose($handle);
        chmod($output_file, 0644);
    }

    /**
     * Generate batch index for optimized batch processing.
     */
    private static function generateBatchIndex(array $items, string $output_file, array &$stats): void
    {
        $batch_size = self::$optimization_config['generate_batch_index']['batch_size'];
        $index_file = $output_file . '.idx';

        $index_data = [
            'version' => '1.0',
            'total_items' => count($items),
            'batch_size' => $batch_size,
            'batches' => [],
            'optimization_metadata' => [
                'strategies_applied' => $stats['strategies_applied'],
                'grouping_stats' => $stats['grouping_stats'],
                'created_at' => time(),
            ],
        ];

        $current_batch = [];
        $batch_start_line = 0;

        foreach ($items as $line_number => $item) {
            $current_batch[] = [
                'guid' => $item['guid'],
                'content_type' => $item['metadata']['content_type'] ?? 'unknown',
                'complexity' => $item['optimization_scores']['complexity'] ?? 0,
                'update_frequency' => $item['optimization_scores']['update_frequency'] ?? 0.5,
            ];

            // Check if batch is complete
            if (count($current_batch) >= $batch_size) {
                $index_data['batches'][] = [
                    'batch_number' => count($index_data['batches']) + 1,
                    'start_line' => $batch_start_line,
                    'end_line' => $line_number,
                    'item_count' => count($current_batch),
                    'content_types' => array_unique(array_column($current_batch, 'content_type')),
                    'avg_complexity' => array_sum(array_column($current_batch, 'complexity')) / count($current_batch),
                    'avg_update_frequency' => array_sum(array_column($current_batch, 'update_frequency')) / count($current_batch),
                ];

                $current_batch = [];
                $batch_start_line = $line_number + 1;
            }
        }

        // Handle remaining items
        if (!empty($current_batch)) {
            $index_data['batches'][] = [
                'batch_number' => count($index_data['batches']) + 1,
                'start_line' => $batch_start_line,
                'end_line' => count($items) - 1,
                'item_count' => count($current_batch),
                'content_types' => array_unique(array_column($current_batch, 'content_type')),
                'avg_complexity' => array_sum(array_column($current_batch, 'complexity')) / count($current_batch),
                'avg_update_frequency' => array_sum(array_column($current_batch, 'update_frequency')) / count($current_batch),
            ];
        }

        // Write index file
        file_put_contents($index_file, json_encode($index_data, JSON_PRETTY_PRINT));
        chmod($index_file, 0644);

        $stats['batch_index_generated'] = true;
        $stats['total_batches'] = count($index_data['batches']);
    }

    /**
     * Load and use batch index for optimized processing.
     */
    public static function loadBatchIndex(string $jsonl_file): array
    {
        $index_file = $jsonl_file . '.idx';

        if (!file_exists($index_file)) {
            return [];
        }

        $index_data = json_decode(file_get_contents($index_file), true);

        if (!$index_data) {
            return [];
        }

        return $index_data;
    }

    /**
     * Get batch recommendations based on index.
     */
    public static function getBatchRecommendations(string $jsonl_file, int $target_batch_size = 100): array
    {
        $index_data = self::loadBatchIndex($jsonl_file);

        if (empty($index_data['batches'])) {
            return ['use_standard_batching' => true];
        }

        $recommendations = [
            'optimal_batch_sizes' => [],
            'content_type_clusters' => [],
            'complexity_zones' => [],
        ];

        // Analyze batches for optimal sizes
        foreach ($index_data['batches'] as $batch) {
            $complexity = $batch['avg_complexity'];
            $content_types = $batch['content_types'];

            // Group by complexity
            if ($complexity < 0.3) {
                $recommendations['complexity_zones']['low'][] = $batch;
            } elseif ($complexity < 0.7) {
                $recommendations['complexity_zones']['medium'][] = $batch;
            } else {
                $recommendations['complexity_zones']['high'][] = $batch;
            }

            // Group by content types
            foreach ($content_types as $type) {
                $recommendations['content_type_clusters'][$type][] = $batch;
            }
        }

        return $recommendations;
    }

    /**
     * Configure optimization strategies.
     */
    public static function configure(array $config): void
    {
        self::$optimization_config = array_merge(self::$optimization_config, $config);
    }

    /**
     * Get optimization statistics.
     */
    public static function getStats(): array
    {
        return self::$optimization_config;
    }

    /**
     * Get optimization configuration.
     */
    public static function getOptimizationConfig(): array
    {
        return self::$optimization_config;
    }

    /**
     * Update optimization configuration.
     */
    public static function updateOptimizationConfig(array $new_config): bool
    {
        self::$optimization_config = array_merge(self::$optimization_config, $new_config);

        return true;
    }

    /**
     * Record optimization performance for learning.
     */
    public static function recordOptimizationPerformance(array $stats, array $context = []): bool
    {
        if (!class_exists('Puntwork\\Utilities\\IterativeLearner')) {
            return false;
        }

        $performance_data = [
            'optimization_type' => 'jsonl_optimization',
            'processing_time_per_item' => $stats['optimization_time'] / max(1, $stats['items_processed']),
            'memory_usage_mb' => $stats['memory_peak_mb'],
            'items_processed' => $stats['items_processed'],
            'strategies_applied' => $stats['strategies_applied'],
            'compression_ratio' => $stats['output_file_size'] / max(1, $stats['input_file_size']),
        ];

        return IterativeLearner::recordSessionPerformance($performance_data, $context);
    }
}
