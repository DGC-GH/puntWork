<?php

/**
 * Import Process Debugger.
 *
 * This script helps debug and optimize the import process by providing detailed profiling
 * and performance analysis tools.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Run import process profiling.
 */
function run_import_profiling()
{
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }

    $feed_key = $_POST['feed_key'] ?? '';
    $batch_size = intval($_POST['batch_size'] ?? 10);
    $enable_debug = isset($_POST['enable_debug']);

    if (empty($feed_key)) {
        wp_send_json_error('Feed key is required');

        return;
    }

    // Enable detailed debugging if requested
    if ($enable_debug) {
        define('WP_DEBUG', true);
        define('WP_DEBUG_LOG', true);
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
    }

    try {
        // Start comprehensive profiling
        $profile_id = start_import_performance_monitoring();

        // Get feed data
        $feeds = get_feeds();
        if (!isset($feeds[$feed_key])) {
            throw new Exception("Feed key '$feed_key' not found");
        }

        $feed_url = $feeds[$feed_key];

        // Process feed with profiling
        $result = process_one_feed($feed_key, $feed_url, ABSPATH . 'feeds/', 'belgiumjobs.work');

        if ($result['success']) {
            // Run a sample batch import with profiling
            $json_path = ABSPATH . 'feeds/combined-jobs.jsonl';
            if (file_exists($json_path)) {
                $total_items = get_json_item_count($json_path);

                // Simulate batch processing
                $setup = [
                    'start_index' => 0,
                    'total' => min($total_items, 100), // Test with first 100 items
                    'json_path' => $json_path,
                    'start_time' => microtime(true),
                ];

                // Override batch size for testing
                update_option('job_import_batch_size', $batch_size);

                $batch_result = process_batch_items_logic($setup);

                end_import_performance_monitoring($profile_id, 'debug_import_test', $batch_result['batch_processed'] ?? 0);

                wp_send_json_success(
                    [
                        'message' => 'Import profiling completed',
                        'feed_result' => $result,
                        'batch_result' => $batch_result,
                        'profiling_data' => get_performance_snapshot(),
                    ]
                );
            } else {
                wp_send_json_error('Combined JSONL file not found');
            }
        } else {
            wp_send_json_error('Feed processing failed: ' . ($result['message'] ?? 'Unknown error'));
        }
    } catch (Exception $e) {
        end_import_performance_monitoring($profile_id, 'debug_import_error', 0);
        wp_send_json_error('Profiling failed: ' . $e->getMessage());
    }
}

/**
 * Get import performance recommendations.
 */
function get_import_optimization_recommendations()
{
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }

    $recommendations = [];

    // Check database indexes
    $indexes_status = get_database_optimization_status();
    if (!$indexes_status['optimization_complete']) {
        $recommendations[] = [
            'type' => 'database',
            'priority' => 'high',
            'title' => 'Missing Database Indexes',
            'description' => 'Create missing database indexes for better performance',
            'missing_indexes' => $indexes_status['missing_indexes'],
            'action' => 'create_database_indexes',
        ];
    }

    // Check memory limits
    $memory_limit = ini_get('memory_limit');
    $memory_bytes = wp_convert_hr_to_bytes($memory_limit);
    if ($memory_bytes < 1024 * 1024 * 1024) { // Less than 1GB
        $recommendations[] = [
            'type' => 'memory',
            'priority' => 'high',
            'title' => 'Low Memory Limit',
            'description' => 'Increase PHP memory limit to at least 1GB for large imports',
            'current' => $memory_limit,
            'recommended' => '1024M',
        ];
    }

    // Check batch size settings
    $current_batch_size = get_option('job_import_batch_size', 5);
    if ($current_batch_size < 50) {
        $recommendations[] = [
            'type' => 'batch',
            'priority' => 'medium',
            'title' => 'Small Batch Size',
            'description' => 'Current batch size is small, consider increasing for better performance',
            'current' => $current_batch_size,
            'recommended' => '50-100',
        ];
    }

    // Check for performance logs table
    global $wpdb;
    $perf_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}puntwork_performance_logs'") !== null;
    if (!$perf_table_exists) {
        $recommendations[] = [
            'type' => 'monitoring',
            'priority' => 'low',
            'title' => 'Performance Monitoring',
            'description' => 'Enable performance logging for better optimization insights',
            'action' => 'create_performance_logs_table',
        ];
    }

    wp_send_json_success(
        [
            'recommendations' => $recommendations,
            'total' => count($recommendations),
        ]
    );
}

/**
 * Apply optimization recommendations.
 */
function apply_import_optimizations()
{
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }

    $optimizations = $_POST['optimizations'] ?? [];
    $results = [];

    foreach ($optimizations as $opt) {
        try {
            switch ($opt) {
                case 'create_database_indexes':
                    create_database_indexes();
                    $results[] = 'Database indexes created successfully';

                    break;

                case 'increase_memory_limit':
                    $new_limit = '1024M';
                    ini_set('memory_limit', $new_limit);
                    $results[] = "Memory limit increased to $new_limit";

                    break;

                case 'optimize_batch_size':
                    $new_batch_size = 50;
                    update_option('job_import_batch_size', $new_batch_size);
                    $results[] = "Batch size optimized to $new_batch_size";

                    break;

                case 'create_performance_logs_table':
                    // Create performance logs table if it doesn't exist
                    global $wpdb;
                    $table_name = $wpdb->prefix . 'puntwork_performance_logs';
                    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
                        $charset_collate = $wpdb->get_charset_collate();
                        $sql = "CREATE TABLE $table_name (
                            id bigint(20) NOT NULL AUTO_INCREMENT,
                            operation varchar(100) NOT NULL,
                            total_time float NOT NULL,
                            items_processed int NOT NULL DEFAULT 0,
                            items_per_second float NOT NULL,
                            memory_used bigint(20) NOT NULL,
                            query_count int NOT NULL DEFAULT 0,
                            created_at datetime DEFAULT CURRENT_TIMESTAMP,
                            PRIMARY KEY (id),
                            KEY operation_time (operation, created_at),
                            KEY duration (total_time, items_per_second)
                        ) $charset_collate;";
                        $wpdb->query($sql);
                        $results[] = 'Performance logs table created';
                    }

                    break;

                default:
                    $results[] = "Unknown optimization: $opt";
            }
        } catch (Exception $e) {
            $results[] = "Failed to apply $opt: " . $e->getMessage();
        }
    }

    wp_send_json_success(
        [
            'message' => 'Optimizations applied',
            'results' => $results,
        ]
    );
}

// AJAX handlers
add_action('wp_ajax_run_import_profiling', 'run_import_profiling');
add_action('wp_ajax_get_import_optimization_recommendations', 'get_import_optimization_recommendations');
add_action('wp_ajax_apply_import_optimizations', 'apply_import_optimizations');
