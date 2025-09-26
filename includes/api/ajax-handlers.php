<?php

/**
 * AJAX handlers for job import plugin
 *
 * @package    Puntwork
 * @subpackage AJAX
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Main AJAX handlers file
 * Includes all AJAX handler modules for better organization
 */

// Include import control handlers
require_once __DIR__ . '/ajax-import-control.php';

// Include feed processing handlers
require_once __DIR__ . '/ajax-feed-processing.php';

// Include purge handlers
require_once __DIR__ . '/ajax-purge.php';

// Include database optimization handlers
require_once __DIR__ . '/ajax-db-optimization.php';

// Include scheduling handlers
require_once __DIR__ . '/../scheduling/scheduling-ajax.php';

/**
 * AJAX handler for loading job listings with lazy loading
 */
add_action('wp_ajax_puntwork_load_jobs', __NAMESPACE__ . '\\handle_load_jobs_ajax');
function handle_load_jobs_ajax()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'puntwork_load_jobs')) {
        wp_die(json_encode([
            'success' => false,
            'message' => 'Security check failed'
        ]));
    }

    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die(json_encode([
            'success' => false,
            'message' => 'Insufficient permissions'
        ]));
    }

    try {
        $page = intval($_POST['page'] ?? 1);
        $per_page = intval($_POST['per_page'] ?? 20);
        $status = sanitize_text_field($_POST['status'] ?? 'any');
        $search = sanitize_text_field($_POST['search'] ?? '');

        // Use the existing REST API handler for consistency
        $request = new \WP_REST_Request('GET', '/puntwork/v1/jobs');
        $request->set_param('page', $page);
        $request->set_param('per_page', $per_page);
        $request->set_param('status', $status);
        $request->set_param('search', $search);

        $response = handle_get_jobs($request);

        if ($response instanceof \WP_REST_Response) {
            $data = $response->get_data();
            wp_die(json_encode($data));
        } else {
            wp_die(json_encode([
                'success' => false,
                'message' => 'Failed to load jobs'
            ]));
        }
    } catch (\Exception $e) {
        PuntWorkLogger::error('AJAX load jobs error', PuntWorkLogger::CONTEXT_AJAX, [
            'error' => $e->getMessage()
        ]);

        wp_die(json_encode([
            'success' => false,
            'message' => 'Error loading jobs: ' . $e->getMessage()
        ]));
    }
}

/**
 * AJAX handler for loading analytics data with lazy loading
 */
add_action('wp_ajax_puntwork_load_analytics', __NAMESPACE__ . '\\handle_load_analytics_ajax');
function handle_load_analytics_ajax()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'puntwork_analytics_nonce')) {
        wp_die(json_encode([
            'success' => false,
            'message' => 'Security check failed'
        ]));
    }

    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die(json_encode([
            'success' => false,
            'message' => 'Insufficient permissions'
        ]));
    }

    try {
        $period = sanitize_text_field($_POST['period'] ?? '30days');

        // Get analytics data with caching
        $analytics_data = ImportAnalytics::get_analytics_data($period);

        if ($analytics_data === false) {
            wp_die(json_encode([
                'success' => false,
                'message' => 'Failed to retrieve analytics data'
            ]));
        }

        // Generate HTML content for the analytics dashboard
        $html = generate_analytics_html($analytics_data, $period);

        wp_die(json_encode([
            'success' => true,
            'data' => [
                'html' => $html,
                'analytics_data' => $analytics_data
            ]
        ]));
    } catch (\Exception $e) {
        PuntWorkLogger::error('AJAX load analytics error', PuntWorkLogger::CONTEXT_AJAX, [
            'error' => $e->getMessage()
        ]);

        wp_die(json_encode([
            'success' => false,
            'message' => 'Error loading analytics: ' . $e->getMessage()
        ]));
    }
}

/**
 * Generate HTML content for analytics dashboard
 */
function generate_analytics_html($analytics_data, $period)
{
    ob_start();
    ?>
    <!-- Overview Metrics -->
    <div class="analytics-section">
        <h2>Overview</h2>
        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-value"><?php echo number_format($analytics_data['overview']['total_imports']); ?></div>
                <div class="metric-label">Total Imports</div>
            </div>
            <div class="metric-card">
                <div class="metric-value"><?php echo number_format($analytics_data['overview']['total_processed']); ?></div>
                <div class="metric-label">Jobs Processed</div>
            </div>
            <div class="metric-card">
                <div class="metric-value"><?php echo $analytics_data['overview']['avg_success_rate']; ?>%</div>
                <div class="metric-label">Avg Success Rate</div>
            </div>
            <div class="metric-card">
                <div class="metric-value"><?php echo $analytics_data['overview']['avg_duration']; ?>s</div>
                <div class="metric-label">Avg Duration</div>
            </div>
        </div>
    </div>

    <!-- Performance Breakdown -->
    <div class="analytics-section">
        <h2>Performance by Trigger Type</h2>
        <div class="performance-breakdown">
            <?php foreach ($analytics_data['performance'] as $trigger_type => $stats) : ?>
                <div class="performance-card">
                    <h3><?php echo ucfirst($trigger_type); ?> Imports</h3>
                    <div class="performance-stats">
                        <div class="stat">
                            <span class="stat-label">Count:</span>
                            <span class="stat-value"><?php echo number_format($stats['count']); ?></span>
                        </div>
                        <div class="stat">
                            <span class="stat-label">Avg Duration:</span>
                            <span class="stat-value"><?php echo $stats['avg_duration']; ?>s</span>
                        </div>
                        <div class="stat">
                            <span class="stat-label">Success Rate:</span>
                            <span class="stat-value"><?php echo $stats['avg_success_rate']; ?>%</span>
                        </div>
                        <div class="stat">
                            <span class="stat-label">Jobs Processed:</span>
                            <span class="stat-value"><?php echo number_format($stats['total_processed']); ?></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Trends Chart -->
    <div class="analytics-section">
        <h2>Import Trends</h2>
        <div class="chart-container">
            <canvas id="trends-chart" width="400" height="200"></canvas>
        </div>
    </div>

    <!-- Feed Statistics -->
    <div class="analytics-section">
        <h2>Feed Performance</h2>
        <div class="feed-stats-grid">
            <div class="feed-stat-card">
                <div class="stat-value"><?php echo $analytics_data['feed_stats']['avg_feeds_processed']; ?></div>
                <div class="stat-label">Avg Feeds Processed</div>
            </div>
            <div class="feed-stat-card">
                <div class="stat-value"><?php echo $analytics_data['feed_stats']['avg_feeds_successful']; ?></div>
                <div class="stat-label">Avg Feeds Successful</div>
            </div>
            <div class="feed-stat-card">
                <div class="stat-value"><?php echo $analytics_data['feed_stats']['avg_feeds_failed']; ?></div>
                <div class="stat-label">Avg Feeds Failed</div>
            </div>
            <div class="feed-stat-card">
                <div class="stat-value"><?php echo $analytics_data['feed_stats']['avg_response_time']; ?>s</div>
                <div class="stat-label">Avg Response Time</div>
            </div>
        </div>
    </div>

    <!-- Job Statistics -->
    <div class="analytics-section">
        <h2>Job Processing Statistics</h2>
        <div class="job-stats-breakdown">
            <div class="job-stat-item">
                <span class="job-stat-label">Published:</span>
                <span class="job-stat-value"><?php echo number_format($analytics_data['overview']['total_published']); ?></span>
                <div class="job-stat-bar">
                    <div class="job-stat-fill published" style="width: <?php echo $analytics_data['overview']['total_processed'] > 0 ? ($analytics_data['overview']['total_published'] / $analytics_data['overview']['total_processed'] * 100) : 0; ?>%;"></div>
                </div>
            </div>
            <div class="job-stat-item">
                <span class="job-stat-label">Updated:</span>
                <span class="job-stat-value"><?php echo number_format($analytics_data['overview']['total_updated']); ?></span>
                <div class="job-stat-bar">
                    <div class="job-stat-fill updated" style="width: <?php echo $analytics_data['overview']['total_processed'] > 0 ? ($analytics_data['overview']['total_updated'] / $analytics_data['overview']['total_processed'] * 100) : 0; ?>%;"></div>
                </div>
            </div>
            <div class="job-stat-item">
                <span class="job-stat-label">Duplicates:</span>
                <span class="job-stat-value"><?php echo number_format($analytics_data['overview']['total_duplicates']); ?></span>
                <div class="job-stat-bar">
                    <div class="job-stat-fill duplicates" style="width: <?php echo $analytics_data['overview']['total_processed'] > 0 ? ($analytics_data['overview']['total_duplicates'] / $analytics_data['overview']['total_processed'] * 100) : 0; ?>%;"></div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($analytics_data['errors']['total_errors'] > 0) : ?>
    <!-- Error Summary -->
    <div class="analytics-section">
        <h2>Error Summary</h2>
        <div class="error-summary">
            <div class="error-count">
                <span class="error-number"><?php echo number_format($analytics_data['errors']['total_errors']); ?></span>
                <span class="error-label">imports had errors</span>
            </div>
            <?php if ($analytics_data['errors']['error_messages']) : ?>
                <div class="error-messages">
                    <strong>Common Error Messages:</strong>
                    <p><?php echo esc_html($analytics_data['errors']['error_messages']); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Hourly Distribution -->
    <div class="analytics-section">
        <h2>Import Activity by Hour</h2>
        <div class="hourly-chart-container">
            <canvas id="hourly-chart" width="400" height="150"></canvas>
        </div>
    </div>

    <script>
        // Make analytics data available globally for chart initialization
        window.puntworkAnalytics = <?php echo json_encode($analytics_data); ?>;
    </script>
    <?php
    return ob_get_clean();
}

/**
 * AJAX handler for saving feed configuration (create/update)
 */
add_action('wp_ajax_puntwork_save_feed', __NAMESPACE__ . '\\handle_save_feed_ajax');
function handle_save_feed_ajax()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'puntwork_feed_config')) {
        wp_die(json_encode([
            'success' => false,
            'message' => 'Security check failed'
        ]));
    }

    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die(json_encode([
            'success' => false,
            'message' => 'Insufficient permissions'
        ]));
    }

    $feed_id = intval($_POST['feed_id'] ?? 0);
    $feed_title = sanitize_text_field($_POST['feed_title'] ?? '');
    $feed_url = esc_url_raw($_POST['feed_url'] ?? '');
    $feed_slug = sanitize_title($_POST['feed_slug'] ?? '');
    $feed_enabled = isset($_POST['feed_enabled']) ? 1 : 0;

    // Validate required fields
    if (empty($feed_title) || empty($feed_url)) {
        wp_die(json_encode([
            'success' => false,
            'message' => 'Feed title and URL are required'
        ]));
    }

    // Validate URL
    if (!filter_var($feed_url, FILTER_VALIDATE_URL)) {
        wp_die(json_encode([
            'success' => false,
            'message' => 'Invalid feed URL'
        ]));
    }

    $post_data = [
        'post_title' => $feed_title,
        'post_type' => 'job-feed',
        'post_status' => 'publish',
        'post_name' => $feed_slug,
        'meta_input' => [
            'feed_url' => $feed_url,
            'feed_enabled' => $feed_enabled,
        ]
    ];

    if ($feed_id > 0) {
        // Update existing feed
        $post_data['ID'] = $feed_id;
        $result = wp_update_post($post_data, true);
    } else {
        // Create new feed
        $result = wp_insert_post($post_data, true);
    }

    if (is_wp_error($result)) {
        wp_die(json_encode([
            'success' => false,
            'message' => $result->get_error_message()
        ]));
    }

    wp_die(json_encode([
        'success' => true,
        'feed_id' => $result
    ]));
}

/**
 * AJAX handler for toggling feed enabled/disabled status
 */
add_action('wp_ajax_puntwork_toggle_feed', __NAMESPACE__ . '\\handle_toggle_feed_ajax');
function handle_toggle_feed_ajax()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'puntwork_feed_config')) {
        wp_die(json_encode([
            'success' => false,
            'message' => 'Security check failed'
        ]));
    }

    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die(json_encode([
            'success' => false,
            'message' => 'Insufficient permissions'
        ]));
    }

    $feed_id = intval($_POST['feed_id'] ?? 0);
    $enabled = isset($_POST['enabled']) ? 1 : 0;

    if (!$feed_id) {
        wp_die(json_encode([
            'success' => false,
            'message' => 'Invalid feed ID'
        ]));
    }

    $result = update_post_meta($feed_id, 'feed_enabled', $enabled);

    if ($result === false) {
        wp_die(json_encode([
            'success' => false,
            'message' => 'Failed to update feed status'
        ]));
    }

    wp_die(json_encode([
        'success' => true
    ]));
}

/**
 * AJAX handler for deleting feeds
 */
add_action('wp_ajax_puntwork_delete_feed', __NAMESPACE__ . '\\handle_delete_feed_ajax');
function handle_delete_feed_ajax()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'puntwork_feed_config')) {
        wp_die(json_encode([
            'success' => false,
            'message' => 'Security check failed'
        ]));
    }

    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die(json_encode([
            'success' => false,
            'message' => 'Insufficient permissions'
        ]));
    }

    $feed_id = intval($_POST['feed_id'] ?? 0);

    if (!$feed_id) {
        wp_die(json_encode([
            'success' => false,
            'message' => 'Invalid feed ID'
        ]));
    }

    // Verify the post exists and is a job-feed
    $post = get_post($feed_id);
    if (!$post || $post->post_type !== 'job-feed') {
        wp_die(json_encode([
            'success' => false,
            'message' => 'Feed not found'
        ]));
    }

    $result = wp_delete_post($feed_id, true);

    if (!$result) {
        wp_die(json_encode([
            'success' => false,
            'message' => 'Failed to delete feed'
        ]));
    }

    wp_die(json_encode([
        'success' => true
    ]));
}

/**
 * AJAX handler for saving feed order (drag-and-drop)
 */
add_action('wp_ajax_puntwork_save_feed_order', __NAMESPACE__ . '\\handle_save_feed_order_ajax');
function handle_save_feed_order_ajax()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'puntwork_feed_config')) {
        wp_die(json_encode([
            'success' => false,
            'message' => 'Security check failed'
        ]));
    }

    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die(json_encode([
            'success' => false,
            'message' => 'Insufficient permissions'
        ]));
    }

    $feed_order = $_POST['feed_order'] ?? [];

    if (!is_array($feed_order)) {
        wp_die(json_encode([
            'success' => false,
            'message' => 'Invalid feed order data'
        ]));
    }

    // Update menu_order for each feed
    foreach ($feed_order as $index => $feed_id) {
        $feed_id = intval($feed_id);
        if ($feed_id > 0) {
            wp_update_post([
                'ID' => $feed_id,
                'menu_order' => $index
            ]);
        }
    }

    wp_die(json_encode([
        'success' => true
    ]));
}
