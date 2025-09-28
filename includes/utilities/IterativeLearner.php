<?php

/**
 * Iterative Learning System for Dynamic Weight Adjustments.
 *
 * @since      1.0.9
 */

namespace Puntwork\Utilities;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Iterative Learning System for Dynamic Weight Adjustments.
 *
 * Learns from import session performance and automatically adjusts optimization weights
 * to improve performance over time through cross-session analysis.
 */
class IterativeLearner
{
    /**
     * Debug information for analysis.
     */
    private static array $debug_info = [];

    /**
     * Learning configuration.
     */
    private static array $learning_config = [
        'enabled' => true,
        'max_history_sessions' => 50,
        'learning_rate' => 0.1,
        'decay_factor' => 0.95, // How much older sessions influence learning
        'min_weight' => 0.05,
        'max_weight' => 0.8,
        'confidence_threshold' => 10, // Minimum sessions before applying learned weights
        'performance_metrics' => [
            'processing_time_per_item',
            'memory_usage_mb',
            'error_rate',
            'cache_hit_ratio',
            'batch_completion_rate',
        ],
    ];

    /**
     * Performance data storage key.
     */
    private const PERFORMANCE_DATA_KEY = 'puntwork_learning_performance_data';

    /**
     * Weight adjustment data storage key.
     */
    private const WEIGHT_ADJUSTMENTS_KEY = 'puntwork_learning_weight_adjustments';

    /**
     * Record performance metrics from an import session.
     *
     * @param array $session_data Session performance data
     * @param array $optimization_context Context of optimizations applied
     * @return bool Success status
     */
    public static function recordSessionPerformance(array $session_data, array $optimization_context = []): bool
    {
        if (!self::$learning_config['enabled']) {
            return true;
        }

        try {
            $performance_record = [
                'session_id' => uniqid('session_', true),
                'timestamp' => time(),
                'session_data' => $session_data,
                'optimization_context' => $optimization_context,
                'weights_used' => self::getCurrentWeightsSnapshot(),
            ];

            // Store performance data
            $performance_history = self::getPerformanceHistory();
            array_unshift($performance_history, $performance_record); // Add to beginning

            // Limit history size
            if (count($performance_history) > self::$learning_config['max_history_sessions']) {
                $performance_history = array_slice($performance_history, 0, self::$learning_config['max_history_sessions']);
            }

            update_option(self::PERFORMANCE_DATA_KEY, $performance_history);

            // Trigger learning analysis
            self::analyzeAndAdjustWeights();

            error_log(sprintf(
                '[PUNTWORK] [LEARNING] Recorded performance for session %s',
                $performance_record['session_id']
            ));

            return true;

        } catch (\Exception $e) {
            error_log('[PUNTWORK] [LEARNING] Failed to record session performance: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Analyze performance history and adjust weights automatically.
     *
     * @return array Adjustment results
     */
    public static function analyzeAndAdjustWeights(): array
    {
        $results = [
            'analysis_performed' => false,
            'adjustments_made' => [],
            'confidence_level' => 0,
        ];

        if (!self::$learning_config['enabled']) {
            return $results;
        }

        $performance_history = self::getPerformanceHistory();

        if (count($performance_history) < self::$learning_config['confidence_threshold']) {
            return $results; // Not enough data for confident adjustments
        }

        $results['analysis_performed'] = true;
        $results['confidence_level'] = min(1.0, count($performance_history) / (self::$learning_config['confidence_threshold'] * 2));

        // Analyze performance patterns
        $performance_analysis = self::analyzePerformancePatterns($performance_history);

        // Calculate weight adjustments
        $weight_adjustments = self::calculateWeightAdjustments($performance_analysis);

        // Apply adjustments if they meet criteria
        if (!empty($weight_adjustments)) {
            $applied_adjustments = self::applyWeightAdjustments($weight_adjustments);
            $results['adjustments_made'] = $applied_adjustments;

            error_log(sprintf(
                '[PUNTWORK] [LEARNING] Applied %d weight adjustments with %.2f confidence',
                count($applied_adjustments),
                $results['confidence_level']
            ));
        }

        return $results;
    }

    /**
     * Analyze performance patterns from historical data.
     *
     * @param array $performance_history Historical performance records
     * @return array Performance analysis results
     */
    private static function analyzePerformancePatterns(array $performance_history): array
    {
        $analysis = [
            'strategy_effectiveness' => [],
            'performance_correlations' => [],
            'optimal_ranges' => [],
            'trend_analysis' => [],
        ];

        $recent_sessions = array_slice($performance_history, 0, min(20, count($performance_history)));

        foreach ($recent_sessions as $index => $session) {
            $decay_weight = pow(self::$learning_config['decay_factor'], $index);

            $session_data = $session['session_data'];
            $weights_used = $session['weights_used'];

            // Calculate session performance score (lower is better)
            $performance_score = self::calculatePerformanceScore($session_data);

            // Analyze strategy effectiveness
            foreach ($weights_used as $strategy => $weight) {
                if (!isset($analysis['strategy_effectiveness'][$strategy])) {
                    $analysis['strategy_effectiveness'][$strategy] = [
                        'total_weighted_score' => 0,
                        'total_weight' => 0,
                        'session_count' => 0,
                    ];
                }

                $analysis['strategy_effectiveness'][$strategy]['total_weighted_score'] += $performance_score * $weight * $decay_weight;
                $analysis['strategy_effectiveness'][$strategy]['total_weight'] += $weight * $decay_weight;
                $analysis['strategy_effectiveness'][$strategy]['session_count']++;
            }

            // Analyze performance correlations
            $analysis['performance_correlations'][] = [
                'weights' => $weights_used,
                'performance_score' => $performance_score,
                'decay_weight' => $decay_weight,
            ];
        }

        // Calculate average effectiveness for each strategy
        foreach ($analysis['strategy_effectiveness'] as $strategy => &$data) {
            if ($data['total_weight'] > 0) {
                $data['average_effectiveness'] = $data['total_weighted_score'] / $data['total_weight'];
            }
        }

        // Debug: Store analysis results for inspection
        self::$debug_info = [
            'strategies_analyzed' => count($analysis['strategy_effectiveness']),
            'strategy_effectiveness' => $analysis['strategy_effectiveness'],
            'baseline_effectiveness' => $baseline_effectiveness ?? 0,
        ];

        return $analysis;
    }

    /**
     * Calculate weight adjustments based on performance analysis.
     *
     * @param array $performance_analysis Performance analysis results
     * @return array Weight adjustment recommendations
     */
    private static function calculateWeightAdjustments(array $performance_analysis): array
    {
        $adjustments = [];

        $strategy_effectiveness = $performance_analysis['strategy_effectiveness'];

        if (empty($strategy_effectiveness)) {
            return $adjustments;
        }

        // Calculate baseline performance (average of all strategies)
        $total_effectiveness = 0;
        $strategy_count = 0;

        foreach ($strategy_effectiveness as $data) {
            if (isset($data['average_effectiveness'])) {
                $total_effectiveness += $data['average_effectiveness'];
                $strategy_count++;
            }
        }

        $baseline_effectiveness = $strategy_count > 0 ? $total_effectiveness / $strategy_count : 1.0;

        // Calculate adjustments for each strategy
        foreach ($strategy_effectiveness as $strategy => $data) {
            if (!isset($data['average_effectiveness'])) {
                continue;
            }

            $effectiveness_ratio = $data['average_effectiveness'] / $baseline_effectiveness;

            // Calculate adjustment (positive for better than average, negative for worse)
            $raw_adjustment = ($effectiveness_ratio - 1.0) * self::$learning_config['learning_rate'];

            // Apply bounds and smoothing
            $adjustment = max(-0.2, min(0.2, $raw_adjustment));

            if (abs($adjustment) > 0.01) { // Only adjust if change is meaningful
                $adjustments[$strategy] = $adjustment;
            }
        }

        return $adjustments;
    }

    /**
     * Apply calculated weight adjustments to optimization utilities.
     *
     * @param array $weight_adjustments Weight adjustment recommendations
     * @return array Applied adjustments
     */
    private static function applyWeightAdjustments(array $weight_adjustments): array
    {
        $applied = [];

        foreach ($weight_adjustments as $strategy => $adjustment) {
            $success = false;

            switch ($strategy) {
                case 'sort_by_update_frequency':
                case 'group_by_content_type':
                case 'sort_by_complexity':
                case 'cluster_by_location':
                    $success = self::adjustJsonlOptimizerWeight($strategy, $adjustment);
                    break;

                case 'priority_new':
                case 'priority_updated':
                case 'priority_unchanged':
                    $success = self::adjustBatchPrioritizerWeight($strategy, $adjustment);
                    break;

                case 'resource_small':
                case 'resource_medium':
                case 'resource_large':
                case 'resource_xlarge':
                    $success = self::adjustResourceManagerWeight($strategy, $adjustment);
                    break;
            }

            if ($success) {
                $applied[$strategy] = $adjustment;
            }
        }

        // Store adjustment history
        $adjustment_history = get_option(self::WEIGHT_ADJUSTMENTS_KEY, []);
        $adjustment_history[] = [
            'timestamp' => time(),
            'adjustments' => $applied,
        ];

        // Keep only recent adjustments
        if (count($adjustment_history) > 20) {
            $adjustment_history = array_slice($adjustment_history, -20);
        }

        update_option(self::WEIGHT_ADJUSTMENTS_KEY, $adjustment_history);

        return $applied;
    }

    /**
     * Adjust JsonlOptimizer weight.
     */
    private static function adjustJsonlOptimizerWeight(string $strategy, float $adjustment): bool
    {
        if (!class_exists('Puntwork\\Utilities\\JsonlOptimizer')) {
            return false;
        }

        $config = JsonlOptimizer::getOptimizationConfig();

        if (isset($config[$strategy]['weight'])) {
            $current_weight = $config[$strategy]['weight'];
            $new_weight = max(self::$learning_config['min_weight'], min(self::$learning_config['max_weight'], $current_weight + $adjustment));

            $config[$strategy]['weight'] = $new_weight;
            JsonlOptimizer::updateOptimizationConfig($config);

            return true;
        }

        return false;
    }

    /**
     * Adjust BatchPrioritizer weight.
     */
    private static function adjustBatchPrioritizerWeight(string $strategy, float $adjustment): bool
    {
        if (!class_exists('Puntwork\\Utilities\\BatchPrioritizer')) {
            return false;
        }

        $config = BatchPrioritizer::getConfig();

        $priority_map = [
            'priority_new' => BatchPrioritizer::PRIORITY_NEW,
            'priority_updated' => BatchPrioritizer::PRIORITY_UPDATED,
            'priority_unchanged' => BatchPrioritizer::PRIORITY_UNCHANGED,
        ];

        if (isset($priority_map[$strategy]) && isset($config['priority_weights'][$priority_map[$strategy]])) {
            $current_weight = $config['priority_weights'][$priority_map[$strategy]];
            $new_weight = max(0.1, min(2.0, $current_weight + $adjustment));

            $config['priority_weights'][$priority_map[$strategy]] = $new_weight;
            BatchPrioritizer::configure($config);

            return true;
        }

        return false;
    }

    /**
     * Adjust AdaptiveResourceManager weight.
     */
    private static function adjustResourceManagerWeight(string $strategy, float $adjustment): bool
    {
        if (!class_exists('Puntwork\\Utilities\\AdaptiveResourceManager')) {
            return false;
        }

        $profile_key = str_replace('resource_', '', $strategy);
        $profiles = AdaptiveResourceManager::getResourceProfiles();

        if (isset($profiles[$profile_key])) {
            // Adjust memory buffer based on learning
            $current_buffer = $profiles[$profile_key]['memory_buffer'];
            $adjustment_factor = 1 + ($adjustment * 0.5); // Conservative adjustment
            $new_buffer = max(10 * 1024 * 1024, min(1024 * 1024 * 1024, $current_buffer * $adjustment_factor));

            $profiles[$profile_key]['memory_buffer'] = (int)$new_buffer;
            AdaptiveResourceManager::updateResourceProfiles($profiles);

            return true;
        }

        return false;
    }

    /**
     * Calculate performance score for a session (lower is better).
     */
    private static function calculatePerformanceScore(array $session_data): float
    {
        $score = 1.0; // Base score

        // Processing time per item (lower is better)
        if (isset($session_data['processing_time_per_item'])) {
            $time_score = min(2.0, $session_data['processing_time_per_item'] / 0.1); // Normalize to 0.1s per item baseline
            $score *= $time_score;
        }

        // Memory usage (lower is better)
        if (isset($session_data['memory_usage_mb'])) {
            $memory_score = min(2.0, $session_data['memory_usage_mb'] / 100); // Normalize to 100MB baseline
            $score *= $memory_score;
        }

        // Error rate (lower is better)
        if (isset($session_data['error_rate'])) {
            $error_score = 1 + ($session_data['error_rate'] * 2); // Errors heavily penalize score
            $score *= $error_score;
        }

        // Cache hit ratio (higher is better - invert the penalty)
        if (isset($session_data['cache_hit_ratio'])) {
            $cache_score = max(0.5, 2.0 - ($session_data['cache_hit_ratio'] * 1.5));
            $score *= $cache_score;
        }

        return max(0.1, $score); // Ensure minimum score
    }

    /**
     * Get current weights snapshot from all optimization utilities.
     */
    private static function getCurrentWeightsSnapshot(): array
    {
        $weights = [];

        // JsonlOptimizer weights
        if (class_exists('Puntwork\\Utilities\\JsonlOptimizer')) {
            $config = JsonlOptimizer::getOptimizationConfig();
            foreach ($config as $strategy => $settings) {
                if (isset($settings['weight'])) {
                    $weights[$strategy] = $settings['weight'];
                }
            }
        }

        // BatchPrioritizer weights
        if (class_exists('Puntwork\\Utilities\\BatchPrioritizer')) {
            $config = BatchPrioritizer::getConfig();
            if (isset($config['priority_weights'])) {
                $priority_map = [
                    BatchPrioritizer::PRIORITY_NEW => 'priority_new',
                    BatchPrioritizer::PRIORITY_UPDATED => 'priority_updated',
                    BatchPrioritizer::PRIORITY_UNCHANGED => 'priority_unchanged',
                ];

                foreach ($config['priority_weights'] as $level => $weight) {
                    if (isset($priority_map[$level])) {
                        $weights[$priority_map[$level]] = $weight;
                    }
                }
            }
        }

        // AdaptiveResourceManager profiles (simplified as weights)
        if (class_exists('Puntwork\\Utilities\\AdaptiveResourceManager')) {
            $profiles = AdaptiveResourceManager::getResourceProfiles();
            foreach ($profiles as $size => $profile) {
                $weights['resource_' . $size] = $profile['memory_buffer'] / (100 * 1024 * 1024); // Normalize
            }
        }

        return $weights;
    }

    /**
     * Get performance history from storage.
     */
    private static function getPerformanceHistory(): array
    {
        return get_option(self::PERFORMANCE_DATA_KEY, []);
    }

    /**
     * Get learning statistics and insights.
     */
    public static function getLearningStats(): array
    {
        $performance_history = self::getPerformanceHistory();
        $adjustment_history = get_option(self::WEIGHT_ADJUSTMENTS_KEY, []);

        return [
            'enabled' => self::$learning_config['enabled'],
            'sessions_recorded' => count($performance_history),
            'adjustments_made' => count($adjustment_history),
            'confidence_level' => min(1.0, count($performance_history) / (self::$learning_config['confidence_threshold'] * 2)),
            'last_adjustment' => !empty($adjustment_history) ? end($adjustment_history)['timestamp'] : null,
            'learning_config' => self::$learning_config,
            'debug_info' => self::$debug_info,
        ];
    }

    /**
     * Reset learning data (for testing or manual reset).
     */
    public static function resetLearningData(): bool
    {
        delete_option(self::PERFORMANCE_DATA_KEY);
        delete_option(self::WEIGHT_ADJUSTMENTS_KEY);

        error_log('[PUNTWORK] [LEARNING] Learning data reset');
        return true;
    }

    /**
     * Manually adjust a specific weight (for testing/demonstration).
     */
    public static function adjustWeight(string $strategy, float $adjustment): bool
    {
        $adjustments = [$strategy => $adjustment];
        $applied = self::applyWeightAdjustments($adjustments);
        return isset($applied[$strategy]);
    }

    /**
     * Update learning configuration.
     */
    public static function updateLearningConfig(array $new_config): bool
    {
        self::$learning_config = array_merge(self::$learning_config, $new_config);
        return true;
    }
}