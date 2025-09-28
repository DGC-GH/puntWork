<?php

/**
 * Performance monitoring utilities
 *
 * @package    Puntwork
 * @subpackage Utilities
 * @since      1.0.9
 */

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

use Puntwork\Utilities\CacheManager;
use Puntwork\Utilities\EnhancedCacheManager;
use Puntwork\Utilities\AdvancedMemoryManager;
use Puntwork\Utilities\MemoryManager;
use Puntwork\Utilities\CircuitBreaker;
use Puntwork\Utilities\PerformanceMonitor;
use Puntwork\Utilities\DatabasePerformanceMonitor;

/**
 * Check if feed processing can proceed
 *
 * @param  string $feed_url Feed URL
 * @return bool True if processing should proceed
 */
function can_process_feed(string $feed_url): bool
{
    $circuit_name = 'feed_' . md5($feed_url);
    return CircuitBreaker::canProceed($circuit_name);
}

/**
 * Record feed processing success
 *
 * @param string $feed_url Feed URL
 */
function record_feed_success(string $feed_url): void
{
    $circuit_name = 'feed_' . md5($feed_url);
    CircuitBreaker::recordSuccess($circuit_name);
}

/**
 * Record feed processing failure
 *
 * @param string $feed_url Feed URL
 */
function record_feed_failure(string $feed_url): void
{
    $circuit_name = 'feed_' . md5($feed_url);
    CircuitBreaker::recordFailure($circuit_name);
}

/**
 * Get circuit breaker status for monitoring
 *
 * @return array Circuit states
 */
function get_circuit_breaker_status(): array
{
    return CircuitBreaker::getAllStates();
}

/**
 * Start performance monitoring for import operations
 *
 * @param  string $operation Operation name
 * @return string Measurement ID
 */
function start_performance_monitoring(string $operation): string
{
    return \Puntwork\Utilities\PerformanceMonitor::start($operation);
}

/**
 * Add checkpoint to performance monitoring
 *
 * @param string $id         Measurement ID
 * @param string $checkpoint Checkpoint name
 * @param array  $data       Additional data
 */
function checkpoint_performance(string $id, string $checkpoint, array $data = array()): void
{
    \Puntwork\Utilities\PerformanceMonitor::checkpoint($id, $checkpoint, $data);
}

/**
 * End performance monitoring
 *
 * @param  string $id Measurement ID
 * @return array Performance data
 */
function end_performance_monitoring(string $id): array
{
    return \Puntwork\Utilities\PerformanceMonitor::end($id);
}

/**
 * Get current performance snapshot
 *
 * @return array Current performance data
 */
function get_performance_snapshot(): array
{
    return \Puntwork\Utilities\PerformanceMonitor::snapshot();
}

/**
 * Get performance statistics
 *
 * @param  string $operation Operation name (optional)
 * @param  int    $days      Number of days to look back
 * @return array Performance statistics
 */
function get_performance_statistics(?string $operation = '', int $days = 30): array
{
    return \Puntwork\Utilities\PerformanceMonitor::getStatistics($operation ?? '', $days);
}

/**
 * Start database performance monitoring
 */
function start_db_performance_monitoring(): void
{
    \Puntwork\Utilities\DatabasePerformanceMonitor::start();
}

/**
 * End database performance monitoring
 *
 * @return array Database performance statistics
 */
function end_db_performance_monitoring(): array
{
    return \Puntwork\Utilities\DatabasePerformanceMonitor::end();
}

/**
 * Check memory usage during batch processing
 *
 * @param  int   $current_index Current processing index
 * @param  float $threshold     Memory threshold
 * @return array Memory status
 */
function check_batch_memory_usage(int $current_index, float $threshold = 0.8): array
{
    return \Puntwork\Utilities\MemoryManager::checkMemoryUsage($current_index, $threshold);
}

/**
 * Optimize memory for large batch operations
 */
function optimize_memory_for_batch(): void
{
    \Puntwork\Utilities\MemoryManager::optimizeForLargeBatch();
}

/**
 * Reset memory manager
 */
function reset_memory_manager(): void
{
    \Puntwork\Utilities\MemoryManager::reset();
}

/**
 * AJAX handler for warming performance caches
 */
function ajax_warm_performance_caches(): void
{
    try {
        if (! wp_verify_nonce($_POST['nonce'] ?? '', 'puntwork_performance_caches')) {
            wp_send_json_error('Security check failed');
            return;
        }

        if (! current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        \Puntwork\Utilities\EnhancedCacheManager::warmCommonCaches();

        wp_send_json_success(
            array(
                'message'   => 'Performance caches warmed successfully',
                'timestamp' => current_time('timestamp'),
            )
        );
    } catch (\Exception $e) {
        \Puntwork\PuntWorkLogger::error('Cache warming failed: ' . $e->getMessage(), \Puntwork\PuntWorkLogger::CONTEXT_SYSTEM);
        wp_send_json_error('Cache warming failed: ' . $e->getMessage());
    }
}

// Only register WordPress hooks if WordPress functions are available
if (function_exists('add_action')) {
    add_action('wp_ajax_warm_performance_caches', 'ajax_warm_performance_caches');
}

/**
 * AJAX handler for resetting cache analytics
 */
function ajax_reset_cache_analytics(): void
{
    try {
        if (! wp_verify_nonce($_POST['nonce'] ?? '', 'puntwork_cache_analytics')) {
            wp_send_json_error('Security check failed');
            return;
        }

        if (! current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        \Puntwork\Utilities\EnhancedCacheManager::resetAnalytics();

        wp_send_json_success(
            array(
                'message'   => 'Cache analytics reset successfully',
                'timestamp' => current_time('timestamp'),
            )
        );
    } catch (\Exception $e) {
        wp_send_json_error('Analytics reset failed: ' . $e->getMessage());
    }
}

// Only register WordPress hooks if WordPress functions are available
if (function_exists('add_action')) {
    add_action('wp_ajax_reset_cache_analytics', 'ajax_reset_cache_analytics');
}

/**
 * AJAX handler for running memory performance test
 */
function ajax_run_memory_performance_test(): void
{
    try {
        if (! wp_verify_nonce($_POST['nonce'] ?? '', 'puntwork_memory_test')) {
            wp_send_json_error('Security check failed');
            return;
        }

        if (! current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $start_time   = microtime(true);
        $start_memory = memory_get_usage(true);

        // Simulate processing different batch sizes
        $test_results = array();
        for ($batch_size = 100; $batch_size <= 1000; $batch_size += 200) {
            $prediction     = \Puntwork\Utilities\AdvancedMemoryManager::predictMemoryUsage($batch_size);
            $test_results[] = array(
                'batch_size' => $batch_size,
                'prediction' => $prediction,
            );
        }

        $end_memory = memory_get_peak_usage(true);
        $test_time  = microtime(true) - $start_time;

        wp_send_json_success(
            array(
                'message'     => 'Memory performance test completed',
                'peak_memory' => $end_memory,
                'test_time'   => round($test_time, 3),
                'predictions' => $test_results,
            )
        );
    } catch (\Exception $e) {
        wp_send_json_error('Memory test failed: ' . $e->getMessage());
    }
}

// Only register WordPress hooks if WordPress functions are available
if (function_exists('add_action')) {
    add_action('wp_ajax_run_memory_performance_test', 'ajax_run_memory_performance_test');
}

/**
 * AJAX handler for clearing memory pool
 */
function ajax_clear_memory_pool(): void
{
    try {
        if (! wp_verify_nonce($_POST['nonce'] ?? '', 'puntwork_memory_pool')) {
            wp_send_json_error('Security check failed');
            return;
        }

        if (! current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        \Puntwork\Utilities\AdvancedMemoryManager::clearPool();

        wp_send_json_success(
            array(
                'message'   => 'Memory pool cleared successfully',
                'timestamp' => current_time('timestamp'),
            )
        );
    } catch (\Exception $e) {
        wp_send_json_error('Memory pool clear failed: ' . $e->getMessage());
    }
}

// Only register WordPress hooks if WordPress functions are available
if (function_exists('add_action')) {
    add_action('wp_ajax_clear_memory_pool', 'ajax_clear_memory_pool');
}

/**
 * AJAX handler for running ML feed optimization
 */
function ajax_run_ml_feed_optimization(): void
{
    try {
        if (! wp_verify_nonce($_POST['nonce'] ?? '', 'puntwork_ml_optimization')) {
            wp_send_json_error('Security check failed');
            return;
        }

        if (! current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $results = AI\FeedOptimizer::runOptimization();

        wp_send_json_success(
            array(
                'message'               => 'ML feed optimization completed successfully',
                'optimizations_applied' => $results['optimizations_applied'],
                'feeds_analyzed'        => $results['feeds_analyzed'],
                'results'               => $results,
            )
        );
    } catch (\Exception $e) {
        \Puntwork\PuntWorkLogger::error('ML feed optimization failed: ' . $e->getMessage(), \Puntwork\PuntWorkLogger::CONTEXT_AI);
        wp_send_json_error('ML optimization failed: ' . $e->getMessage());
    }
}

// Only register WordPress hooks if WordPress functions are available
if (function_exists('add_action')) {
    add_action('wp_ajax_run_ml_feed_optimization', 'ajax_run_ml_feed_optimization');
}

/**
 * AJAX handler for training ML models
 */
function ajax_train_ml_models(): void
{
    try {
        if (! wp_verify_nonce($_POST['nonce'] ?? '', 'puntwork_train_models')) {
            wp_send_json_error('Security check failed');
            return;
        }

        if (! current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $results = AI\MachineLearningEngine::trainAllModels();

        wp_send_json_success(
            array(
                'message'        => 'Model training completed successfully',
                'models_trained' => $results['models_trained'],
                'avg_accuracy'   => round($results['avg_accuracy'] * 100, 1),
                'results'        => $results,
            )
        );
    } catch (\Exception $e) {
        \Puntwork\PuntWorkLogger::error('Model training failed: ' . $e->getMessage(), \Puntwork\PuntWorkLogger::CONTEXT_AI);
        wp_send_json_error('Model training failed: ' . $e->getMessage());
    }
}

// Only register WordPress hooks if WordPress functions are available
if (function_exists('add_action')) {
    add_action('wp_ajax_train_ml_models', 'ajax_train_ml_models');
}

/**
 * AJAX handler for getting ML insights
 */
function ajax_get_ml_insights(): void
{
    try {
        if (! wp_verify_nonce($_POST['nonce'] ?? '', 'puntwork_ml_insights')) {
            wp_send_json_error('Security check failed');
            return;
        }

        if (! current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $insights = AI\MachineLearningEngine::getInsights();

        wp_send_json_success(
            array(
                'insights' => $insights,
            )
        );
    } catch (\Exception $e) {
        wp_send_json_error('Failed to get ML insights: ' . $e->getMessage());
    }
}

// Only register WordPress hooks if WordPress functions are available
if (function_exists('add_action')) {
    add_action('wp_ajax_get_ml_insights', 'ajax_get_ml_insights');
}
