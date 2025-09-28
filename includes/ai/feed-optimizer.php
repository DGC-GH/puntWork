<?php

/**
 * Automated feed optimization using predictive analytics
 *
 * @package    Puntwork
 * @subpackage AI
 * @since      2.1.0
 */

namespace Puntwork\AI;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Automated feed optimizer using machine learning predictions
 */
class FeedOptimizer
{
    /**
     * Optimization actions
     */
    public const ACTION_ADJUST_FREQUENCY  = 'adjust_frequency';
    public const ACTION_ADJUST_BATCH_SIZE = 'adjust_batch_size';
    public const ACTION_ADJUST_TIMEOUT    = 'adjust_timeout';
    public const ACTION_ENABLE_FEED       = 'enable_feed';
    public const ACTION_DISABLE_FEED      = 'disable_feed';
    public const ACTION_REORDER_FEEDS     = 'reorder_feeds';

    /**
     * Optimization confidence thresholds
     */
    public const CONFIDENCE_HIGH   = 'high';
    public const CONFIDENCE_MEDIUM = 'medium';
    public const CONFIDENCE_LOW    = 'low';

    /**
     * Initialize the feed optimizer
     */
    public static function init(): void
    {
        // Schedule daily optimization
        if (! wp_next_scheduled('puntwork_feed_optimization')) {
            wp_schedule_event(time(), 'daily', 'puntwork_feed_optimization');
        }

        // Add optimization action hook
        add_action('puntwork_feed_optimization', array( __CLASS__, 'runScheduledOptimization' ));

        // Add AJAX endpoint for manual optimization
        add_action('wp_ajax_run_feed_optimization', array( __CLASS__, 'ajaxRunOptimization' ));
    }

    /**
     * Run scheduled optimization (daily)
     */
    public static function runScheduledOptimization(): void
    {
        try {
            $results = self::runOptimization();

            // Log optimization results
            PuntWorkLogger::info(
                'Scheduled feed optimization completed',
                PuntWorkLogger::CONTEXT_AI,
                array(
                    'optimizations_applied' => $results['optimizations_applied'],
                    'feeds_analyzed'        => $results['feeds_analyzed'],
                )
            );

            // Store optimization results for admin display
            update_option(
                'puntwork_last_optimization',
                array(
                    'timestamp' => current_time('timestamp'),
                    'results'   => $results,
                )
            );
        } catch (\Exception $e) {
            PuntWorkLogger::error('Scheduled feed optimization failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AI);
        }
    }

    /**
     * AJAX handler for manual optimization
     */
    public static function ajaxRunOptimization(): void
    {
        try {
            // Verify nonce and permissions
            if (! wp_verify_nonce($_POST['nonce'] ?? '', 'puntwork_feed_optimization')) {
                wp_send_json_error(array( 'message' => 'Security check failed' ));
                return;
            }

            if (! current_user_can('manage_options')) {
                wp_send_json_error(array( 'message' => 'Insufficient permissions' ));
                return;
            }

            $results = self::runOptimization();

            // Store results
            update_option(
                'puntwork_last_optimization',
                array(
                    'timestamp' => current_time('timestamp'),
                    'results'   => $results,
                )
            );

            wp_send_json_success(
                array(
                    'message' => 'Feed optimization completed successfully',
                    'results' => $results,
                )
            );
        } catch (\Exception $e) {
            PuntWorkLogger::error('Manual feed optimization failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AI);
            wp_send_json_error(array( 'message' => 'Optimization failed: ' . $e->getMessage() ));
        }
    }

    /**
     * Run automated optimization with machine learning
     *
     * @return array Optimization results
     */
    public static function runOptimization(): array
    {
        $results = array(
            'optimizations_applied' => 0,
            'feeds_analyzed'        => 0,
            'recommendations'       => array(),
            'errors'                => array(),
        );

        try {
            // Get all active feeds
            $feeds                     = self::getActiveFeeds();
            $results['feeds_analyzed'] = count($feeds);

            foreach ($feeds as $feed) {
                $feedKey = get_post_meta($feed->ID, 'feed_url', true);

                if (empty($feedKey)) {
                    continue;
                }

                // Use machine learning for optimization
                $mlOptimization = MachineLearningEngine::optimizeFeedAutomatically($feedKey);

                if ($mlOptimization['success']) {
                    $results['optimizations_applied'] += count($mlOptimization['applied_optimizations']);
                    $results['recommendations'][]      = array(
                        'feed_id'               => $feed->ID,
                        'feed_name'             => $feed->post_title,
                        'ml_predictions'        => $mlOptimization['predictions'],
                        'recommendations'       => $mlOptimization['recommendations'],
                        'applied_optimizations' => $mlOptimization['applied_optimizations'],
                    );
                } else {
                    // Fallback to rule-based optimization
                    $feedOptimizations = self::optimizeFeed($feed);
                    if (! empty($feedOptimizations)) {
                        $results['optimizations_applied'] += count($feedOptimizations);
                        $results['recommendations'][]      = array(
                            'feed_id'         => $feed->ID,
                            'feed_name'       => $feed->post_title,
                            'recommendations' => $feedOptimizations,
                            'method'          => 'rule_based',
                        );
                    }
                }
            }

            // Run global optimizations
            $globalOptimizations = self::runGlobalOptimizations();
            if (! empty($globalOptimizations)) {
                $results['optimizations_applied'] += count($globalOptimizations);
                $results['recommendations'][]      = array(
                    'feed_id'         => 'global',
                    'feed_name'       => 'Global Optimization',
                    'recommendations' => $globalOptimizations,
                );
            }
        } catch (\Exception $e) {
            $results['errors'][] = 'Optimization failed: ' . $e->getMessage();
            PuntWorkLogger::error('Feed optimization error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AI);
        }

        return $results;
    }

    /**
     * Optimize a single feed based on predictive analytics
     *
     * @param  \WP_Post $feed Feed post object
     * @return array Optimization actions applied
     */
    private static function optimizeFeed(\WP_Post $feed): array
    {
        $optimizations = array();
        $feedKey       = get_post_meta($feed->ID, 'feed_url', true);

        if (empty($feedKey)) {
            return $optimizations;
        }

        // Get predictions for this feed
        $successPrediction     = PredictiveAnalytics::predictImportSuccess($feedKey);
        $reliabilityPrediction = PredictiveAnalytics::predictFeedReliability($feedKey);
        $volumePrediction      = PredictiveAnalytics::predictImportVolume();

        // Apply optimization rules based on predictions

        // 1. Adjust import frequency based on reliability and volume trends
        $frequencyOptimization = self::optimizeImportFrequency($feed, $reliabilityPrediction, $volumePrediction);
        if ($frequencyOptimization) {
            $optimizations[] = $frequencyOptimization;
        }

        // 2. Adjust batch size based on success rate and volume
        $batchOptimization = self::optimizeBatchSize($feed, $successPrediction, $volumePrediction);
        if ($batchOptimization) {
            $optimizations[] = $batchOptimization;
        }

        // 3. Adjust timeout based on response time predictions
        $timeoutOptimization = self::optimizeTimeout($feed, $reliabilityPrediction);
        if ($timeoutOptimization) {
            $optimizations[] = $timeoutOptimization;
        }

        // 4. Enable/disable feed based on reliability
        $statusOptimization = self::optimizeFeedStatus($feed, $reliabilityPrediction);
        if ($statusOptimization) {
            $optimizations[] = $statusOptimization;
        }

        return $optimizations;
    }

    /**
     * Optimize import frequency based on predictions
     *
     * @param  \WP_Post $feed                  Feed post object
     * @param  array    $reliabilityPrediction Reliability prediction data
     * @param  array    $volumePrediction      Volume prediction data
     * @return array|null Optimization action
     */
    private static function optimizeImportFrequency(\WP_Post $feed, array $reliabilityPrediction, array $volumePrediction): ?array
    {
        if ($reliabilityPrediction['confidence'] === self::CONFIDENCE_LOW) {
            return null; // Not confident enough to change frequency
        }

        $currentFrequency = get_post_meta($feed->ID, 'import_frequency', true) ?: 'hourly';
        $reliabilityScore = $reliabilityPrediction['current_reliability'] ?? 0;
        $volumeTrend      = $volumePrediction['trend'] ?? 'stable';

        $newFrequency = $currentFrequency;

        // If reliability is poor and volume is decreasing, reduce frequency
        if ($reliabilityScore < 50 && $volumeTrend === 'decreasing') {
            $newFrequency = self::increaseFrequencyInterval($currentFrequency);
        } elseif ($reliabilityScore > 80 && $volumeTrend === 'increasing') {
            // If reliability is good and volume is increasing, increase frequency
            $newFrequency = self::decreaseFrequencyInterval($currentFrequency);
        }

        if ($newFrequency !== $currentFrequency) {
            update_post_meta($feed->ID, 'import_frequency', $newFrequency);
            return array(
                'action'     => self::ACTION_ADJUST_FREQUENCY,
                'from'       => $currentFrequency,
                'to'         => $newFrequency,
                'reason'     => "Reliability: {$reliabilityScore}%, Volume trend: {$volumeTrend}",
                'confidence' => $reliabilityPrediction['confidence'],
            );
        }

        return null;
    }

    /**
     * Optimize batch size based on predictions
     *
     * @param  \WP_Post $feed              Feed post object
     * @param  array    $successPrediction Success prediction data
     * @param  array    $volumePrediction  Volume prediction data
     * @return array|null Optimization action
     */
    private static function optimizeBatchSize(\WP_Post $feed, array $successPrediction, array $volumePrediction): ?array
    {
        if ($successPrediction['confidence'] === self::CONFIDENCE_LOW) {
            return null;
        }

        $currentBatchSize = get_post_meta($feed->ID, 'batch_size', true) ?: 100;
        $successRate      = $successPrediction['prediction'] ?? 0;
        $predictedVolume  = $volumePrediction['predicted_volume'] ?? $currentBatchSize;

        $newBatchSize = $currentBatchSize;

        // If success rate is high and volume is increasing, increase batch size
        if ($successRate > 90 && $predictedVolume > $currentBatchSize * 1.2) {
            $newBatchSize = min($currentBatchSize * 2, 1000); // Max 1000
        } elseif ($successRate < 70) {
            // If success rate is low, reduce batch size
            $newBatchSize = max($currentBatchSize / 2, 10); // Min 10
        }

        if ($newBatchSize != $currentBatchSize) {
            update_post_meta($feed->ID, 'batch_size', $newBatchSize);
            return array(
                'action'     => self::ACTION_ADJUST_BATCH_SIZE,
                'from'       => $currentBatchSize,
                'to'         => $newBatchSize,
                'reason'     => "Success rate: {$successRate}%, Predicted volume: {$predictedVolume}",
                'confidence' => $successPrediction['confidence'],
            );
        }

        return null;
    }

    /**
     * Optimize timeout based on reliability predictions
     *
     * @param  \WP_Post $feed                  Feed post object
     * @param  array    $reliabilityPrediction Reliability prediction data
     * @return array|null Optimization action
     */
    private static function optimizeTimeout(\WP_Post $feed, array $reliabilityPrediction): ?array
    {
        if ($reliabilityPrediction['confidence'] === self::CONFIDENCE_LOW) {
            return null;
        }

        $currentTimeout = get_post_meta($feed->ID, 'timeout', true) ?: 30;
        $responseTime   = $reliabilityPrediction['factors']['response_time'] ?? '30ms';

        // Extract numeric value from response time string
        preg_match('/(\d+)/', $responseTime, $matches);
        $avgResponseTime = (int) ( $matches[1] ?? 30 );

        $newTimeout = $currentTimeout;

        // If average response time is much lower than timeout, reduce timeout
        if ($avgResponseTime < $currentTimeout * 0.5) {
            $newTimeout = max($avgResponseTime * 2, 10); // At least 10 seconds, double the avg response
        } elseif ($avgResponseTime > $currentTimeout * 0.8) {
            // If response time is approaching timeout, increase timeout
            $newTimeout = min($currentTimeout * 1.5, 300); // Max 5 minutes
        }

        if ($newTimeout != $currentTimeout) {
            update_post_meta($feed->ID, 'timeout', $newTimeout);
            return array(
                'action'     => self::ACTION_ADJUST_TIMEOUT,
                'from'       => $currentTimeout . 's',
                'to'         => $newTimeout . 's',
                'reason'     => "Average response time: {$responseTime}",
                'confidence' => $reliabilityPrediction['confidence'],
            );
        }

        return null;
    }

    /**
     * Optimize feed status (enable/disable) based on reliability
     *
     * @param  \WP_Post $feed                  Feed post object
     * @param  array    $reliabilityPrediction Reliability prediction data
     * @return array|null Optimization action
     */
    private static function optimizeFeedStatus(\WP_Post $feed, array $reliabilityPrediction): ?array
    {
        if ($reliabilityPrediction['confidence'] === self::CONFIDENCE_LOW) {
            return null;
        }

        $currentStatus        = get_post_meta($feed->ID, 'feed_enabled', true) !== '0';
        $reliabilityScore     = $reliabilityPrediction['current_reliability'] ?? 0;
        $predictedReliability = $reliabilityPrediction['predicted_reliability'] ?? 0;

        // If current reliability is very poor and predicted to stay poor, disable feed
        if (! $currentStatus && $reliabilityScore < 20 && $predictedReliability < 30) {
            // Feed is already disabled, no change needed
            return null;
        }

        if ($currentStatus && $reliabilityScore < 20 && $predictedReliability < 30) {
            update_post_meta($feed->ID, 'feed_enabled', '0');
            return array(
                'action'     => self::ACTION_DISABLE_FEED,
                'reason'     => "Reliability too low: {$reliabilityScore}% current, {$predictedReliability}% predicted",
                'confidence' => $reliabilityPrediction['confidence'],
            );
        }

        // If feed is disabled but reliability has improved significantly, re-enable
        if (! $currentStatus && $reliabilityScore > 70 && $predictedReliability > 60) {
            update_post_meta($feed->ID, 'feed_enabled', '1');
            return array(
                'action'     => self::ACTION_ENABLE_FEED,
                'reason'     => "Reliability improved: {$reliabilityScore}% current, {$predictedReliability}% predicted",
                'confidence' => $reliabilityPrediction['confidence'],
            );
        }

        return null;
    }

    /**
     * Run global optimizations across all feeds
     *
     * @return array Global optimization actions
     */
    private static function runGlobalOptimizations(): array
    {
        $optimizations = array();

        // Reorder feeds based on performance
        $reorderOptimization = self::optimizeFeedOrdering();
        if ($reorderOptimization) {
            $optimizations[] = $reorderOptimization;
        }

        return $optimizations;
    }

    /**
     * Optimize feed ordering based on performance metrics
     *
     * @return array|null Optimization action
     */
    private static function optimizeFeedOrdering(): ?array
    {
        $feeds = self::getActiveFeeds();
        if (count($feeds) < 2) {
            return null; // Need at least 2 feeds to reorder
        }

        // Calculate performance scores for each feed
        $feedScores = array();
        foreach ($feeds as $feed) {
            $feedKey = get_post_meta($feed->ID, 'feed_url', true);
            if ($feedKey) {
                $reliability = PredictiveAnalytics::predictFeedReliability($feedKey);
                $success     = PredictiveAnalytics::predictImportSuccess($feedKey);

                // Calculate composite score
                $reliabilityScore = $reliability['current_reliability'] ?? 0;
                $successScore     = $success['prediction'] ?? 0;
                $compositeScore   = ( $reliabilityScore * 0.6 ) + ( $successScore * 0.4 );

                $feedScores[ $feed->ID ] = array(
                    'score'         => $compositeScore,
                    'current_order' => get_post_meta($feed->ID, 'menu_order', true) ?: 0,
                );
            }
        }

        if (empty($feedScores)) {
            return null;
        }

        // Sort feeds by performance score (highest first)
        arsort($feedScores);

        // Check if reordering is needed
        $needsReordering = false;
        $newOrder        = 0;
        foreach ($feedScores as $feedId => $data) {
            if ($data['current_order'] != $newOrder) {
                $needsReordering = true;
                update_post_meta($feedId, 'menu_order', $newOrder);
            }
            ++$newOrder;
        }

        if ($needsReordering) {
            return array(
                'action'    => self::ACTION_REORDER_FEEDS,
                'reason'    => 'Reordered feeds by performance score (highest reliability/success first)',
                'new_order' => array_keys($feedScores),
            );
        }

        return null;
    }

    /**
     * Get all active feeds
     *
     * @return array Array of feed post objects
     */
    private static function getActiveFeeds(): array
    {
        return get_posts(
            array(
                'post_type'      => 'job-feed',
                'post_status'    => 'publish',
                'meta_query'     => array(
                    array(
                        'key'     => 'feed_enabled',
                        'value'   => '0',
                        'compare' => '!=',
                    ),
                ),
                'posts_per_page' => -1,
                'orderby'        => 'menu_order',
                'order'          => 'ASC',
            )
        );
    }

    /**
     * Increase frequency interval (make less frequent)
     *
     * @param  string $currentFrequency Current frequency
     * @return string New frequency
     */
    private static function increaseFrequencyInterval(string $currentFrequency): string
    {
        $frequencies = array(
            '5min'    => '15min',
            '15min'   => '30min',
            '30min'   => 'hourly',
            'hourly'  => '2hours',
            '2hours'  => '4hours',
            '4hours'  => '6hours',
            '6hours'  => '12hours',
            '12hours' => 'daily',
            'daily'   => 'weekly',
        );
        return $frequencies[ $currentFrequency ] ?? $currentFrequency;
    }

    /**
     * Decrease frequency interval (make more frequent)
     *
     * @param  string $currentFrequency Current frequency
     * @return string New frequency
     */
    private static function decreaseFrequencyInterval(string $currentFrequency): string
    {
        $frequencies = array(
            'weekly'  => 'daily',
            'daily'   => '12hours',
            '12hours' => '6hours',
            '6hours'  => '4hours',
            '4hours'  => '2hours',
            '2hours'  => 'hourly',
            'hourly'  => '30min',
            '30min'   => '15min',
            '15min'   => '5min',
        );
        return $frequencies[ $currentFrequency ] ?? $currentFrequency;
    }

    /**
     * Get optimization recommendations without applying them
     *
     * @return array Recommendations
     */
    public static function getOptimizationRecommendations(): array
    {
        $recommendations = array(
            'feed_optimizations'   => array(),
            'global_optimizations' => array(),
        );

        try {
            $feeds = self::getActiveFeeds();

            foreach ($feeds as $feed) {
                $feedRecs = self::getFeedRecommendations($feed);
                if (! empty($feedRecs)) {
                    $recommendations['feed_optimizations'][] = array(
                        'feed_id'         => $feed->ID,
                        'feed_name'       => $feed->post_title,
                        'recommendations' => $feedRecs,
                    );
                }
            }

            $globalRecs                              = self::getGlobalRecommendations();
            $recommendations['global_optimizations'] = $globalRecs;
        } catch (\Exception $e) {
            PuntWorkLogger::error('Error getting optimization recommendations: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AI);
        }

        return $recommendations;
    }

    /**
     * Get recommendations for a single feed
     *
     * @param  \WP_Post $feed Feed post object
     * @return array Recommendations
     */
    private static function getFeedRecommendations(\WP_Post $feed): array
    {
        $recommendations = array();
        $feedKey         = get_post_meta($feed->ID, 'feed_url', true);

        if (empty($feedKey)) {
            return $recommendations;
        }

        $successPrediction     = PredictiveAnalytics::predictImportSuccess($feedKey);
        $reliabilityPrediction = PredictiveAnalytics::predictFeedReliability($feedKey);
        $volumePrediction      = PredictiveAnalytics::predictImportVolume();

        // Generate recommendations based on predictions
        if ($reliabilityPrediction['confidence'] !== self::CONFIDENCE_LOW) {
            $reliabilityScore = $reliabilityPrediction['current_reliability'] ?? 0;
            if ($reliabilityScore < 50) {
                $recommendations[] = array(
                    'type'             => 'reliability',
                    'severity'         => 'high',
                    'message'          => "Feed reliability is low ({$reliabilityScore}%). Consider reviewing feed source or reducing import frequency.",
                    'suggested_action' => 'Review feed configuration and source stability',
                );
            }
        }

        if ($successPrediction['confidence'] !== self::CONFIDENCE_LOW) {
            $successRate = $successPrediction['prediction'] ?? 0;
            if ($successRate < 80) {
                $recommendations[] = array(
                    'type'             => 'success_rate',
                    'severity'         => 'medium',
                    'message'          => "Import success rate is {$successRate}%. Consider adjusting batch size or timeout settings.",
                    'suggested_action' => 'Optimize batch processing parameters',
                );
            }
        }

        if ($volumePrediction['confidence'] !== self::CONFIDENCE_LOW) {
            $trend = $volumePrediction['trend'] ?? 'stable';
            if ($trend === 'decreasing') {
                $recommendations[] = array(
                    'type'             => 'volume_trend',
                    'severity'         => 'medium',
                    'message'          => 'Import volume is trending downward. Feed source may be experiencing issues.',
                    'suggested_action' => 'Monitor feed source and consider alternative sources',
                );
            }
        }

        return $recommendations;
    }

    /**
     * Get global optimization recommendations
     *
     * @return array Global recommendations
     */
    private static function getGlobalRecommendations(): array
    {
        $recommendations = array();

        // Check for feeds that haven't been imported recently
        $staleFeeds = self::getStaleFeeds();
        if (! empty($staleFeeds)) {
            $recommendations[] = array(
                'type'             => 'stale_feeds',
                'severity'         => 'medium',
                'message'          => count($staleFeeds) . ' feeds haven\'t been imported in the last 24 hours.',
                'suggested_action' => 'Check feed configurations and network connectivity',
            );
        }

        // Check for feeds with consistently poor performance
        $poorPerformingFeeds = self::getPoorPerformingFeeds();
        if (! empty($poorPerformingFeeds)) {
            $recommendations[] = array(
                'type'             => 'poor_performance',
                'severity'         => 'high',
                'message'          => count($poorPerformingFeeds) . ' feeds have consistently poor performance.',
                'suggested_action' => 'Review and optimize feed configurations or consider disabling problematic feeds',
            );
        }

        return $recommendations;
    }

    /**
     * Get feeds that haven't been imported recently
     *
     * @return array Stale feed IDs
     */
    private static function getStaleFeeds(): array
    {
        global $wpdb;

        $stale_threshold = date('Y-m-d H:i:s', strtotime('-24 hours'));

        $stale_feeds = $wpdb->get_col(
            $wpdb->prepare(
                "
            SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = 'last_import'
            AND (meta_value < %s OR meta_value IS NULL)
        ",
                $stale_threshold
            )
        );

        return $stale_feeds ?: array();
    }

    /**
     * Get feeds with consistently poor performance
     *
     * @return array Poor performing feed IDs
     */
    private static function getPoorPerformingFeeds(): array
    {
        $feeds      = self::getActiveFeeds();
        $poor_feeds = array();

        foreach ($feeds as $feed) {
            $feedKey = get_post_meta($feed->ID, 'feed_url', true);
            if ($feedKey) {
                $reliability = PredictiveAnalytics::predictFeedReliability($feedKey);
                if (( $reliability['current_reliability'] ?? 100 ) < 50) {
                    $poor_feeds[] = $feed->ID;
                }
            }
        }

        return $poor_feeds;
    }
}
