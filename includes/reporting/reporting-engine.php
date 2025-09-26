<?php

/**
 * Advanced Reporting Engine
 *
 * Generates custom dashboards, automated reports, and analytics visualizations.
 *
 * @package    Puntwork
 * @subpackage Reporting
 * @since      2.4.0
 */

namespace Puntwork\Reporting;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Advanced Reporting Engine
 */
class ReportingEngine
{
    /**
     * Report types
     */
    const REPORT_TYPE_PERFORMANCE = 'performance';
    const REPORT_TYPE_FEED_HEALTH = 'feed_health';
    const REPORT_TYPE_JOB_ANALYTICS = 'job_analytics';
    const REPORT_TYPE_NETWORK = 'network';
    const REPORT_TYPE_ML_INSIGHTS = 'ml_insights';

    /**
     * Report formats
     */
    const FORMAT_HTML = 'html';
    const FORMAT_PDF = 'pdf';
    const FORMAT_CSV = 'csv';
    const FORMAT_JSON = 'json';

    /**
     * Dashboard widgets
     */
    private static array $dashboardWidgets = [];

    /**
     * Scheduled reports
     */
    private static array $scheduledReports = [];

    /**
     * Initialize reporting system
     */
    public static function init(): void
    {
        add_action('init', [self::class, 'setupReporting']);
        add_action('wp_dashboard_setup', [self::class, 'registerDashboardWidgets']);
        add_action('wp_ajaxGenerateCustomReport', [self::class, 'ajaxGenerateCustomReport']);
        add_action('wp_ajaxScheduleReport', [self::class, 'ajaxScheduleReport']);
        add_action('wp_ajaxGetReportData', [self::class, 'ajaxGetReportData']);

        // Schedule automated reports
        if (!wp_next_scheduled('puntwork_generateAutomatedReports')) {
            wp_schedule_event(time(), 'daily', 'puntwork_generateAutomatedReports');
        }
        add_action('puntwork_generateAutomatedReports', [self::class, 'generateAutomatedReports']);
    }

    /**
     * Setup reporting functionality
     */
    public static function setupReporting(): void
    {
        self::createReportsTable();
        self::registerReportSettings();
        self::loadDashboardWidgets();
    }

    /**
     * Create reports table
     */
    private static function createReportsTable(): void
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'puntwork_reports';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            report_type varchar(50) NOT NULL,
            report_title varchar(255) NOT NULL,
            report_data longtext NOT NULL,
            format varchar(10) NOT NULL DEFAULT 'html',
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            scheduled tinyint(1) NOT NULL DEFAULT 0,
            schedule_config text,
            last_generated datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY report_type (report_type),
            KEY created_by (created_by),
            KEY scheduled (scheduled)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Register report settings
     */
    private static function registerReportSettings(): void
    {
        register_setting('puntwork_reporting', 'puntwork_automated_reports_enabled', [
            'type' => 'boolean',
            'default' => true
        ]);

        register_setting('puntwork_reporting', 'puntwork_report_retention_days', [
            'type' => 'integer',
            'default' => 90
        ]);

        register_setting('puntwork_reporting', 'puntwork_dashboard_refresh_interval', [
            'type' => 'integer',
            'default' => 300 // 5 minutes
        ]);
    }

    /**
     * Load dashboard widgets
     */
    private static function loadDashboardWidgets(): void
    {
        self::$dashboardWidgets = [
            'performance_overview' => [
                'title' => __('Performance Overview', 'puntwork'),
                'callback' => [self::class, 'renderPerformanceWidget'],
                'context' => 'normal',
                'priority' => 'high'
            ],
            'feed_health_summary' => [
                'title' => __('Feed Health Summary', 'puntwork'),
                'callback' => [self::class, 'renderFeedHealthWidget'],
                'context' => 'normal',
                'priority' => 'high'
            ],
            'job_analytics_chart' => [
                'title' => __('Job Analytics', 'puntwork'),
                'callback' => [self::class, 'renderJobAnalyticsWidget'],
                'context' => 'normal',
                'priority' => 'core'
            ],
            'ml_insights_widget' => [
                'title' => __('ML Insights', 'puntwork'),
                'callback' => [self::class, 'renderMlInsightsWidget'],
                'context' => 'side',
                'priority' => 'core'
            ],
            'recent_activity' => [
                'title' => __('Recent Activity', 'puntwork'),
                'callback' => [self::class, 'renderRecentActivityWidget'],
                'context' => 'side',
                'priority' => 'core'
            ]
        ];
    }

    /**
     * Register dashboard widgets
     */
    public static function registerDashboardWidgets(): void
    {
        foreach (self::$dashboardWidgets as $widget_id => $widget) {
            wp_add_dashboard_widget(
                'puntwork_' . $widget_id,
                $widget['title'],
                $widget['callback'],
                null,
                null,
                $widget['context'],
                $widget['priority']
            );
        }
    }

    /**
     * Generate custom report
     */
    public static function generateReport(string $type, array $params = [], string $format = self::FORMAT_HTML): array
    {
        $report_data = [];

        switch ($type) {
            case self::REPORT_TYPE_PERFORMANCE:
                $report_data = self::generatePerformanceReport($params);
                break;
            case self::REPORT_TYPE_FEED_HEALTH:
                $report_data = self::generateFeedHealthReport($params);
                break;
            case self::REPORT_TYPE_JOB_ANALYTICS:
                $report_data = self::generateJobAnalyticsReport($params);
                break;
            case self::REPORT_TYPE_NETWORK:
                $report_data = self::generateNetworkReport($params);
                break;
            case self::REPORT_TYPE_ML_INSIGHTS:
                $report_data = self::generateMlInsightsReport($params);
                break;
            default:
                return ['error' => 'Unknown report type: ' . $type];
        }

        // Format the report
        $formatted_report = self::formatReport($report_data, $format);

        // Save report to database
        $report_id = self::saveReport($type, $report_data['title'], $formatted_report, $format);

        return [
            'success' => true,
            'report_id' => $report_id,
            'data' => $report_data,
            'formatted' => $formatted_report
        ];
    }

    /**
     * Generate performance report
     */
    private static function generatePerformanceReport(array $params): array
    {
        $date_range = $params['date_range'] ?? 30; // days
        $start_date = date('Y-m-d', strtotime("-{$date_range} days"));

        global $wpdb;
        $analytics_table = $wpdb->prefix . 'puntwork_import_analytics';

        // Performance metrics
        $performance_data = $wpdb->get_row($wpdb->prepare("
            SELECT
                COUNT(*) as total_imports,
                AVG(success_rate) as avg_success_rate,
                AVG(avg_response_time) as avg_response_time,
                SUM(processed_jobs) as total_jobs,
                SUM(failed_jobs) as total_failed,
                AVG(batch_size) as avg_batch_size,
                MIN(created_at) as earliest_import,
                MAX(created_at) as latest_import
            FROM $analytics_table
            WHERE created_at >= %s
        ", $start_date), ARRAY_A);

        // Daily performance trends
        $daily_trends = $wpdb->get_results($wpdb->prepare("
            SELECT
                DATE(created_at) as date,
                COUNT(*) as imports_count,
                AVG(success_rate) as success_rate,
                AVG(avg_response_time) as response_time,
                SUM(processed_jobs) as jobs_processed
            FROM $analytics_table
            WHERE created_at >= %s
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ", $start_date), ARRAY_A);

        // Top performing feeds
        $top_feeds = $wpdb->get_results($wpdb->prepare("
            SELECT
                feed_url,
                COUNT(*) as import_count,
                AVG(success_rate) as avg_success,
                SUM(processed_jobs) as total_jobs
            FROM $analytics_table
            WHERE created_at >= %s
            GROUP BY feed_url
            ORDER BY avg_success DESC
            LIMIT 10
        ", $start_date), ARRAY_A);

        return [
            'title' => sprintf(__('Performance Report - Last %d Days', 'puntwork'), $date_range),
            'generated_at' => current_time('mysql'),
            'date_range' => $date_range,
            'summary' => [
                'total_imports' => (int)$performance_data->total_imports,
                'avg_success_rate' => round($performance_data->avg_success_rate * 100, 1),
                'avg_response_time' => round($performance_data->avg_response_time, 2),
                'total_jobs' => (int)$performance_data->total_jobs,
                'total_failed' => (int)$performance_data->total_failed,
                'avg_batch_size' => round($performance_data->avg_batch_size, 1)
            ],
            'trends' => $daily_trends,
            'top_feeds' => $top_feeds,
            'period' => [
                'start' => $start_date,
                'end' => date('Y-m-d')
            ]
        ];
    }

    /**
     * Generate feed health report
     */
    private static function generateFeedHealthReport(array $params): array
    {
        $feeds = get_posts([
            'post_type' => 'job-feed',
            'posts_per_page' => -1,
            'post_status' => 'any'
        ]);

        $feed_health = [];
        $overall_stats = [
            'total_feeds' => count($feeds),
            'active_feeds' => 0,
            'healthy_feeds' => 0,
            'warning_feeds' => 0,
            'critical_feeds' => 0
        ];

        foreach ($feeds as $feed) {
            $feed_data = self::analyzeFeedHealth($feed);
            $feed_health[] = $feed_data;

            if ($feed_data['status'] === 'active') {
                $overall_stats['active_feeds']++;
            }

            switch ($feed_data['health_score']) {
                case 'healthy':
                    $overall_stats['healthy_feeds']++;
                    break;
                case 'warning':
                    $overall_stats['warning_feeds']++;
                    break;
                case 'critical':
                    $overall_stats['critical_feeds']++;
                    break;
            }
        }

        return [
            'title' => __('Feed Health Report', 'puntwork'),
            'generated_at' => current_time('mysql'),
            'overall_stats' => $overall_stats,
            'feeds' => $feed_health,
            'recommendations' => self::generateHealthRecommendations($feed_health)
        ];
    }

    /**
     * Analyze individual feed health
     */
    private static function analyzeFeedHealth(\WP_Post $feed): array
    {
        $feed_id = $feed->ID;
        $feed_url = get_post_meta($feed_id, 'feed_url', true);
        $last_import = get_post_meta($feed_id, 'last_import_time', true);
        $success_rate = get_post_meta($feed_id, 'success_rate', true) ?: 0;
        $error_count = get_post_meta($feed_id, 'error_count', true) ?: 0;

        // Calculate health score
        $health_score = 'healthy';
        $issues = [];

        if ($success_rate < 0.5) {
            $health_score = 'critical';
            $issues[] = __('Low success rate (< 50%)', 'puntwork');
        } elseif ($success_rate < 0.8) {
            $health_score = 'warning';
            $issues[] = __('Moderate success rate (50-80%)', 'puntwork');
        }

        if ($error_count > 10) {
            $health_score = 'critical';
            $issues[] = __('High error count', 'puntwork');
        }

        $days_since_import = $last_import ? (time() - strtotime($last_import)) / DAY_IN_SECONDS : 999;
        if ($days_since_import > 7) {
            $health_score = 'warning';
            $issues[] = __('No recent imports', 'puntwork');
        }

        return [
            'id' => $feed_id,
            'title' => $feed->post_title,
            'url' => $feed_url,
            'status' => $feed->post_status,
            'last_import' => $last_import,
            'success_rate' => round($success_rate * 100, 1),
            'error_count' => $error_count,
            'health_score' => $health_score,
            'issues' => $issues,
            'days_since_import' => round($days_since_import, 1)
        ];
    }

    /**
     * Generate health recommendations
     */
    private static function generateHealthRecommendations(array $feed_health): array
    {
        $recommendations = [];

        $critical_count = count(array_filter($feed_health, fn($f) => $f['health_score'] === 'critical'));
        $warning_count = count(array_filter($feed_health, fn($f) => $f['health_score'] === 'warning'));

        if ($critical_count > 0) {
            $recommendations[] = sprintf(__('Address %d critical feed issues immediately', 'puntwork'), $critical_count);
        }

        if ($warning_count > 0) {
            $recommendations[] = sprintf(__('Review %d feeds with warnings', 'puntwork'), $warning_count);
        }

        $inactive_feeds = count(array_filter($feed_health, fn($f) => $f['status'] !== 'active'));
        if ($inactive_feeds > 0) {
            $recommendations[] = sprintf(__('Reactivate or remove %d inactive feeds', 'puntwork'), $inactive_feeds);
        }

        return $recommendations;
    }

    /**
     * Generate job analytics report
     */
    private static function generateJobAnalyticsReport(array $params): array
    {
        $date_range = $params['date_range'] ?? 30;

        // Job categories distribution
        $categories = get_terms([
            'taxonomy' => 'job_category',
            'hide_empty' => false
        ]);

        $category_stats = [];
        foreach ($categories as $category) {
            $jobs_count = $category->count;
            $category_stats[] = [
                'name' => $category->name,
                'count' => $jobs_count,
                'percentage' => 0 // Will be calculated below
            ];
        }

        $total_jobs = array_sum(array_column($category_stats, 'count'));
        foreach ($category_stats as &$stat) {
            $stat['percentage'] = $total_jobs > 0 ? round(($stat['count'] / $total_jobs) * 100, 1) : 0;
        }

        // Job posting trends
        $trends = self::getJobPostingTrends($date_range);

        // Geographic distribution
        $locations = self::getJobLocationDistribution();

        return [
            'title' => sprintf(__('Job Analytics Report - Last %d Days', 'puntwork'), $date_range),
            'generated_at' => current_time('mysql'),
            'total_jobs' => $total_jobs,
            'categories' => $category_stats,
            'trends' => $trends,
            'locations' => $locations
        ];
    }

    /**
     * Get job posting trends
     */
    private static function getJobPostingTrends(int $days): array
    {
        global $wpdb;

        $trends = $wpdb->get_results($wpdb->prepare("
            SELECT
                DATE(post_date) as date,
                COUNT(*) as job_count
            FROM {$wpdb->posts}
            WHERE post_type = 'job'
            AND post_status = 'publish'
            AND post_date >= DATE_SUB(NOW(), INTERVAL %d DAY)
            GROUP BY DATE(post_date)
            ORDER BY date ASC
        ", $days), ARRAY_A);

        return $trends ?: [];
    }

    /**
     * Get job location distribution
     */
    private static function getJobLocationDistribution(): array
    {
        global $wpdb;

        $locations = $wpdb->get_results("
            SELECT
                meta_value as location,
                COUNT(*) as count
            FROM {$wpdb->postmeta}
            WHERE meta_key = 'location'
            AND meta_value != ''
            GROUP BY meta_value
            ORDER BY count DESC
            LIMIT 20
        ", ARRAY_A);

        return $locations ?: [];
    }

    /**
     * Generate network report (for multisite)
     */
    private static function generateNetworkReport(array $params): array
    {
        if (!is_multisite()) {
            return [
                'title' => __('Network Report', 'puntwork'),
                'generated_at' => current_time('mysql'),
                'error' => __('Multisite not enabled', 'puntwork')
            ];
        }

        // This would integrate with MultiSiteManager
        $network_data = [
            'total_sites' => 0,
            'active_sites' => 0,
            'total_jobs' => 0,
            'network_performance' => []
        ];

        return [
            'title' => __('Network Report', 'puntwork'),
            'generated_at' => current_time('mysql'),
            'data' => $network_data
        ];
    }

    /**
     * Generate ML insights report
     */
    private static function generateMlInsightsReport(array $params): array
    {
        // This would integrate with MachineLearningEngine
        $ml_data = [
            'model_performance' => [],
            'predictions' => [],
            'feature_importance' => []
        ];

        return [
            'title' => __('ML Insights Report', 'puntwork'),
            'generated_at' => current_time('mysql'),
            'data' => $ml_data
        ];
    }

    /**
     * Format report for output
     */
    private static function formatReport(array $data, string $format): string
    {
        switch ($format) {
            case self::FORMAT_HTML:
                return self::formatHtmlReport($data);
            case self::FORMAT_JSON:
                return json_encode($data, JSON_PRETTY_PRINT);
            case self::FORMAT_CSV:
                return self::formatCsvReport($data);
            default:
                return self::formatHtmlReport($data);
        }
    }

    /**
     * Format HTML report
     */
    private static function formatHtmlReport(array $data): string
    {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title><?php echo esc_html($data['title']); ?></title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .header { background: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
                .section { margin-bottom: 30px; }
                .metric { display: inline-block; margin: 10px; padding: 15px; background: #fff; border: 1px solid #dee2e6; border-radius: 5px; }
                .metric-value { font-size: 24px; font-weight: bold; color: #007cba; }
                .metric-label { color: #666; font-size: 14px; }
                table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                th, td { padding: 8px; text-align: left; border-bottom: 1px solid #dee2e6; }
                th { background: #f8f9fa; }
                .chart-placeholder { background: #f0f0f0; padding: 40px; text-align: center; border: 2px dashed #ccc; margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1><?php echo esc_html($data['title']); ?></h1>
                <p>Generated on: <?php echo esc_html($data['generated_at']); ?></p>
            </div>

            <?php if (isset($data['summary'])) : ?>
            <div class="section">
                <h2>Summary</h2>
                <div>
                    <?php foreach ($data['summary'] as $key => $value) : ?>
                    <div class="metric">
                        <div class="metric-value"><?php echo esc_html($value); ?></div>
                        <div class="metric-label"><?php echo esc_html(ucwords(str_replace('_', ' ', $key))); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (isset($data['trends'])) : ?>
            <div class="section">
                <h2>Trends</h2>
                <div class="chart-placeholder">
                    <p>Performance Trends Chart</p>
                    <small>Interactive chart would be displayed here</small>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Imports</th>
                            <th>Success Rate</th>
                            <th>Jobs Processed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['trends'] as $trend) : ?>
                        <tr>
                            <td><?php echo esc_html($trend['date']); ?></td>
                            <td><?php echo esc_html($trend['imports_count']); ?></td>
                            <td><?php echo esc_html($trend['success_rate'] * 100); ?>%</td>
                            <td><?php echo esc_html($trend['jobs_processed']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (isset($data['feeds'])) : ?>
            <div class="section">
                <h2>Feed Health</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Feed</th>
                            <th>Status</th>
                            <th>Success Rate</th>
                            <th>Health</th>
                            <th>Issues</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['feeds'] as $feed) : ?>
                        <tr>
                            <td><?php echo esc_html($feed['title']); ?></td>
                            <td><?php echo esc_html($feed['status']); ?></td>
                            <td><?php echo esc_html($feed['success_rate']); ?>%</td>
                            <td><?php echo esc_html($feed['health_score']); ?></td>
                            <td><?php echo esc_html(implode(', ', $feed['issues'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Format CSV report
     */
    private static function formatCsvReport(array $data): string
    {
        $output = '';

        // Add title
        $output .= $data['title'] . "\n";
        $output .= "Generated on: " . $data['generated_at'] . "\n\n";

        // Add summary if available
        if (isset($data['summary'])) {
            $output .= "Summary\n";
            foreach ($data['summary'] as $key => $value) {
                $output .= ucwords(str_replace('_', ' ', $key)) . ": " . $value . "\n";
            }
            $output .= "\n";
        }

        // Add trends if available
        if (isset($data['trends'])) {
            $output .= "Trends\n";
            $output .= "Date,Imports,Success Rate,Jobs Processed\n";
            foreach ($data['trends'] as $trend) {
                $output .= $trend['date'] . ",";
                $output .= $trend['imports_count'] . ",";
                $output .= ($trend['success_rate'] * 100) . "%,";
                $output .= $trend['jobs_processed'] . "\n";
            }
            $output .= "\n";
        }

        return $output;
    }

    /**
     * Save report to database
     */
    private static function saveReport(string $type, string $title, string $data, string $format): int
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'puntwork_reports';

        $wpdb->insert($table_name, [
            'report_type' => $type,
            'report_title' => $title,
            'report_data' => $data,
            'format' => $format,
            'created_by' => get_current_user_id(),
            'last_generated' => current_time('mysql')
        ]);

        return $wpdb->insert_id;
    }

    /**
     * Generate automated reports
     */
    public static function generateAutomatedReports(): void
    {
        if (!get_option('puntwork_automated_reports_enabled', true)) {
            return;
        }

        $reports_to_generate = [
            self::REPORT_TYPE_PERFORMANCE,
            self::REPORT_TYPE_FEED_HEALTH,
            self::REPORT_TYPE_JOB_ANALYTICS
        ];

        foreach ($reports_to_generate as $report_type) {
            try {
                $result = self::generateReport($report_type, ['date_range' => 7], self::FORMAT_HTML);
                if ($result['success']) {
                    PuntWorkLogger::info("Automated report generated: {$report_type}", PuntWorkLogger::CONTEXT_REPORTING);
                }
            } catch (\Exception $e) {
                PuntWorkLogger::error("Failed to generate automated report {$report_type}: " . $e->getMessage(), PuntWorkLogger::CONTEXT_REPORTING);
            }
        }
    }

    /**
     * Render dashboard widgets
     */
    public static function renderPerformanceWidget(): void
    {
        $performance_data = self::generatePerformanceReport(['date_range' => 7]);
        ?>
        <div class="puntwork-performance-widget">
            <div class="widget-metrics">
                <div class="metric">
                    <span class="metric-value"><?php echo esc_html($performance_data['summary']['total_imports']); ?></span>
                    <span class="metric-label"><?php _e('Imports', 'puntwork'); ?></span>
                </div>
                <div class="metric">
                    <span class="metric-value"><?php echo esc_html($performance_data['summary']['avg_success_rate']); ?>%</span>
                    <span class="metric-label"><?php _e('Success Rate', 'puntwork'); ?></span>
                </div>
                <div class="metric">
                    <span class="metric-value"><?php echo esc_html($performance_data['summary']['total_jobs']); ?></span>
                    <span class="metric-label"><?php _e('Jobs', 'puntwork'); ?></span>
                </div>
            </div>
            <div class="widget-actions">
                <a href="#" class="button button-small" onclick="generateCustomReport('performance')">
                    <?php _e('View Report', 'puntwork'); ?>
                </a>
            </div>
        </div>
        <?php
    }

    public static function renderFeedHealthWidget(): void
    {
        $health_data = self::generateFeedHealthReport([]);
        ?>
        <div class="puntwork-health-widget">
            <div class="health-status">
                <div class="status-item healthy">
                    <span class="count"><?php echo esc_html($health_data['overall_stats']['healthy_feeds']); ?></span>
                    <span class="label"><?php _e('Healthy', 'puntwork'); ?></span>
                </div>
                <div class="status-item warning">
                    <span class="count"><?php echo esc_html($health_data['overall_stats']['warning_feeds']); ?></span>
                    <span class="label"><?php _e('Warning', 'puntwork'); ?></span>
                </div>
                <div class="status-item critical">
                    <span class="count"><?php echo esc_html($health_data['overall_stats']['critical_feeds']); ?></span>
                    <span class="label"><?php _e('Critical', 'puntwork'); ?></span>
                </div>
            </div>
        </div>
        <?php
    }

    public static function renderJobAnalyticsWidget(): void
    {
        $analytics_data = self::generateJobAnalyticsReport(['date_range' => 30]);
        ?>
        <div class="puntwork-analytics-widget">
            <canvas id="job-analytics-chart" width="400" height="200"></canvas>
            <script>
            // Simple chart placeholder - would integrate with Chart.js
            document.getElementById('job-analytics-chart').innerHTML =
                '<div style="text-align: center; padding: 40px; color: #666;">Job Analytics Chart</div>';
            </script>
        </div>
        <?php
    }

    public static function renderMlInsightsWidget(): void
    {
        // Placeholder for ML insights
        ?>
        <div class="puntwork-ml-widget">
            <p><?php _e('ML insights would be displayed here', 'puntwork'); ?></p>
        </div>
        <?php
    }

    public static function renderRecentActivityWidget(): void
    {
        global $wpdb;
        $analytics_table = $wpdb->prefix . 'puntwork_import_analytics';

        $recent_activity = $wpdb->get_results("
            SELECT feed_url, success_rate, processed_jobs, created_at
            FROM $analytics_table
            ORDER BY created_at DESC
            LIMIT 5
        ", ARRAY_A);
        ?>
        <div class="puntwork-activity-widget">
            <ul class="activity-list">
                <?php foreach ($recent_activity as $activity) : ?>
                <li class="activity-item">
                    <div class="activity-feed"><?php echo esc_html(basename($activity['feed_url'])); ?></div>
                    <div class="activity-details">
                        <?php echo esc_html($activity['processed_jobs']); ?> jobs,
                        <?php echo esc_html(round($activity['success_rate'] * 100, 1)); ?>% success
                    </div>
                    <div class="activity-time"><?php echo esc_html(human_time_diff(strtotime($activity['created_at']))); ?> ago</div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /**
     * AJAX handlers
     */
    public static function ajaxGenerateCustomReport(): void
    {
        try {
            if (!wp_verify_nonce($_POST['nonce'] ?? '', 'puntwork_generateReport')) {
                wp_send_json_error('Security check failed');
                return;
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error('Insufficient permissions');
                return;
            }

            $report_type = sanitize_text_field($_POST['report_type'] ?? '');
            $date_range = intval($_POST['date_range'] ?? 30);
            $format = sanitize_text_field($_POST['format'] ?? self::FORMAT_HTML);

            $result = self::generateReport($report_type, ['date_range' => $date_range], $format);

            wp_send_json_success($result);
        } catch (\Exception $e) {
            PuntWorkLogger::error('Report generation failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_REPORTING);
            wp_send_json_error('Report generation failed: ' . $e->getMessage());
        }
    }

    public static function ajaxScheduleReport(): void
    {
        try {
            if (!wp_verify_nonce($_POST['nonce'] ?? '', 'puntwork_schedule_report')) {
                wp_send_json_error('Security check failed');
                return;
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error('Insufficient permissions');
                return;
            }

            // Implementation for scheduling reports
            wp_send_json_success(['message' => 'Report scheduling not yet implemented']);
        } catch (\Exception $e) {
            wp_send_json_error('Report scheduling failed: ' . $e->getMessage());
        }
    }

    public static function ajaxGetReportData(): void
    {
        try {
            if (!wp_verify_nonce($_POST['nonce'] ?? '', 'puntwork_get_report_data')) {
                wp_send_json_error('Security check failed');
                return;
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error('Insufficient permissions');
                return;
            }

            $report_id = intval($_POST['report_id'] ?? 0);

            if (!$report_id) {
                wp_send_json_error('Invalid report ID');
                return;
            }

            global $wpdb;
            $table_name = $wpdb->prefix . 'puntwork_reports';

            $report = $wpdb->get_row($wpdb->prepare("
                SELECT * FROM $table_name WHERE id = %d
            ", $report_id), ARRAY_A);

            if (!$report) {
                wp_send_json_error('Report not found');
                return;
            }

            wp_send_json_success([
                'report' => $report,
                'data' => json_decode($report['report_data'], true)
            ]);
        } catch (\Exception $e) {
            wp_send_json_error('Failed to get report data: ' . $e->getMessage());
        }
    }
}