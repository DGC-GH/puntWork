<?php

/**
 * Load Balancer for puntWork
 * Distributes workload across multiple server instances
 */

namespace Puntwork;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Load Balancer Class
 * Manages load distribution across multiple server instances
 */
class PuntworkLoadBalancer
{
    private const LOAD_BALANCER_TABLE = 'puntwork_load_balancer';
    private const HEALTH_CHECK_TIMEOUT = 10; // seconds
    private const MAX_RETRIES = 3;

    private $balancing_strategy;
    private $health_checks;

    public function __construct()
    {
        $this->balancing_strategy = get_option('puntwork_lb_strategy', 'round_robin');
        $this->health_checks = [];

        // Only initialize database operations if WordPress is properly loaded
        if ($this->is_wordpress_environment()) {
            $this->init_hooks();
            $this->create_load_balancer_table();
        }
    }

    /**
     * Check if we're in a proper WordPress environment
     */
    public function is_wordpress_environment()
    {
        global $wpdb;
        return isset($wpdb) && $wpdb instanceof \wpdb && defined('ABSPATH') && file_exists(ABSPATH . 'wp-admin/includes/upgrade.php');
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks()
    {
        add_action('init', [$this, 'process_load_balanced_jobs']);
        add_action('wp_ajax_puntwork_lb_health_check', [$this, 'ajax_health_check_all']);
        add_action('wp_ajax_puntwork_lb_stats', [$this, 'ajax_get_stats']);

        // Add admin menu
        add_action('admin_menu', [$this, 'add_load_balancer_menu'], 30);
    }

    /**
     * Add load balancer admin menu
     */
    public function add_load_balancer_menu()
    {
        add_submenu_page(
            'puntwork-dashboard',
            __('Load Balancer', 'puntwork'),
            __('Load Balancer', 'puntwork'),
            'manage_options',
            'puntwork-load-balancer',
            [$this, 'render_load_balancer_page']
        );
    }

    /**
     * Render load balancer admin page
     */
    public function render_load_balancer_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $stats = $this->get_load_balancer_stats();
        $instances = $this->get_all_instances();
        $strategy = $this->balancing_strategy;

        ?>
        <div class="wrap">
            <h1><?php _e('puntWork Load Balancer', 'puntwork'); ?></h1>

            <div class="puntwork-lb-stats">
                <h2><?php _e('Load Balancer Statistics', 'puntwork'); ?></h2>
                <div class="stats-grid">
                    <div class="stat-card">
                        <h3><?php _e('Active Instances', 'puntwork'); ?></h3>
                        <span class="stat-number"><?php echo esc_html($stats['active_instances']); ?></span>
                    </div>
                    <div class="stat-card">
                        <h3><?php _e('Total Requests', 'puntwork'); ?></h3>
                        <span class="stat-number"><?php echo esc_html($stats['total_requests']); ?></span>
                    </div>
                    <div class="stat-card">
                        <h3><?php _e('Successful Requests', 'puntwork'); ?></h3>
                        <span class="stat-number"><?php echo esc_html($stats['successful_requests']); ?></span>
                    </div>
                    <div class="stat-card">
                        <h3><?php _e('Failed Requests', 'puntwork'); ?></h3>
                        <span class="stat-number"><?php echo esc_html($stats['failed_requests']); ?></span>
                    </div>
                </div>
            </div>

            <div class="puntwork-lb-strategy">
                <h2><?php _e('Load Balancing Strategy', 'puntwork'); ?></h2>
                <form method="post" action="">
                    <?php wp_nonce_field('puntwork_lb_strategy', 'puntwork_lb_nonce'); ?>
                    <select name="lb_strategy" id="lb_strategy">
                        <option value="round_robin" <?php selected($strategy, 'round_robin'); ?>>
                            <?php _e('Round Robin', 'puntwork'); ?>
                        </option>
                        <option value="least_loaded" <?php selected($strategy, 'least_loaded'); ?>>
                            <?php _e('Least Loaded', 'puntwork'); ?>
                        </option>
                        <option value="weighted" <?php selected($strategy, 'weighted'); ?>>
                            <?php _e('Weighted Round Robin', 'puntwork'); ?>
                        </option>
                        <option value="ip_hash" <?php selected($strategy, 'ip_hash'); ?>>
                            <?php _e('IP Hash', 'puntwork'); ?>
                        </option>
                    </select>
                    <button type="submit" class="button button-primary">
                        <?php _e('Update Strategy', 'puntwork'); ?>
                    </button>
                </form>
            </div>

            <div class="puntwork-instances">
                <h2><?php _e('Server Instances', 'puntwork'); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Instance ID', 'puntwork'); ?></th>
                            <th><?php _e('Server', 'puntwork'); ?></th>
                            <th><?php _e('IP Address', 'puntwork'); ?></th>
                            <th><?php _e('Role', 'puntwork'); ?></th>
                            <th><?php _e('Status', 'puntwork'); ?></th>
                            <th><?php _e('CPU', 'puntwork'); ?></th>
                            <th><?php _e('Memory', 'puntwork'); ?></th>
                            <th><?php _e('Last Seen', 'puntwork'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($instances as $instance) : ?>
                        <tr>
                            <td><?php echo esc_html($instance['instance_id']); ?></td>
                            <td><?php echo esc_html($instance['server_name']); ?></td>
                            <td><?php echo esc_html($instance['ip_address']); ?></td>
                            <td><?php echo esc_html($instance['role']); ?></td>
                            <td>
                                <span class="status-<?php echo esc_attr($instance['status']); ?>">
                                    <?php echo esc_html(ucfirst($instance['status'])); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($instance['cpu_count']); ?></td>
                            <td><?php echo esc_html(size_format($instance['memory_limit'])); ?></td>
                            <td><?php echo esc_html($instance['last_seen']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .stat-card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
        }
        .stat-card h3 {
            margin: 0 0 10px 0;
            color: #666;
            font-size: 14px;
        }
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #007cba;
        }
        .status-active { color: #46b450; }
        .status-inactive { color: #dc3232; }
        .status-maintenance { color: #f56e28; }
        </style>
        <?php
    }

    /**
     * Create load balancer table
     */
    private function create_load_balancer_table()
    {
        if (!$this->is_wordpress_environment()) {
            return;
        }

        global $wpdb;

        $table_name = $wpdb->prefix . self::LOAD_BALANCER_TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            instance_id varchar(100) NOT NULL,
            request_type varchar(50) NOT NULL,
            request_data longtext,
            response_status varchar(20),
            response_time float DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY instance_request (instance_id, request_type),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Process load balanced jobs
     */
    public function process_load_balanced_jobs()
    {
        // Get pending jobs that can be load balanced
        $pending_jobs = $this->get_pending_load_balanced_jobs();

        foreach ($pending_jobs as $job) {
            $this->distribute_job($job);
        }
    }

    /**
     * Get pending jobs suitable for load balancing
     */
    private function get_pending_load_balanced_jobs()
    {
        if (!$this->is_wordpress_environment()) {
            return [];
        }

        global $wpdb;

        $queue_table = $wpdb->prefix . 'puntwork_queue';

        // Get jobs that can be distributed across instances
        return $wpdb->get_results($wpdb->prepare("
            SELECT * FROM $queue_table
            WHERE status = 'pending'
            AND scheduled_at <= %s
            AND job_type IN ('feed_import', 'batch_process', 'analytics_update')
            ORDER BY priority ASC, created_at ASC
            LIMIT 10
        ", current_time('mysql')), ARRAY_A);
    }

    /**
     * Distribute job to optimal instance
     */
    private function distribute_job($job)
    {
        $instance = $this->select_instance_for_job($job);

        if (!$instance) {
            // No suitable instance available, mark for retry later
            return;
        }

        $this->send_job_to_instance($job, $instance);
    }

    /**
     * Select best instance for job using current strategy
     */
    private function select_instance_for_job($job)
    {
        $active_instances = $this->get_active_instances();

        if (empty($active_instances)) {
            return null;
        }

        switch ($this->balancing_strategy) {
            case 'round_robin':
                return $this->round_robin_selection($active_instances, $job['job_type']);

            case 'least_loaded':
                return $this->least_loaded_selection($active_instances, $job['job_type']);

            case 'weighted':
                return $this->weighted_selection($active_instances, $job['job_type']);

            case 'ip_hash':
                return $this->ip_hash_selection($active_instances, $job['job_type']);

            default:
                return $this->round_robin_selection($active_instances, $job['job_type']);
        }
    }

    /**
     * Round robin instance selection
     */
    private function round_robin_selection($instances, $job_type)
    {
        static $last_index = [];

        if (!isset($last_index[$job_type])) {
            $last_index[$job_type] = 0;
        }

        $capable_instances = array_filter($instances, function ($instance) use ($job_type) {
            return $this->instance_can_handle_job($instance, $job_type);
        });

        if (empty($capable_instances)) {
            return null;
        }

        $instance_array = array_values($capable_instances);
        $selected_instance = $instance_array[$last_index[$job_type] % count($instance_array)];
        $last_index[$job_type] = ($last_index[$job_type] + 1) % count($instance_array);

        return $selected_instance;
    }

    /**
     * Least loaded instance selection
     */
    private function least_loaded_selection($instances, $job_type)
    {
        $capable_instances = array_filter($instances, function ($instance) use ($job_type) {
            return $this->instance_can_handle_job($instance, $job_type);
        });

        if (empty($capable_instances)) {
            return null;
        }

        // Get load metrics for each instance
        $instance_loads = [];
        foreach ($capable_instances as $instance) {
            $instance_loads[$instance['instance_id']] = $this->get_instance_load($instance);
        }

        // Return instance with lowest load
        asort($instance_loads);
        $least_loaded_id = key($instance_loads);

        return array_filter($capable_instances, function ($instance) use ($least_loaded_id) {
            return $instance['instance_id'] === $least_loaded_id;
        })[$least_loaded_id] ?? null;
    }

    /**
     * Weighted round robin selection
     */
    private function weighted_selection($instances, $job_type)
    {
        $capable_instances = array_filter($instances, function ($instance) use ($job_type) {
            return $this->instance_can_handle_job($instance, $job_type);
        });

        if (empty($capable_instances)) {
            return null;
        }

        // Weight instances by CPU count and memory
        $weighted_instances = [];
        foreach ($capable_instances as $instance) {
            $weight = $instance['cpu_count'] * ($instance['memory_limit'] / (128 * 1024 * 1024)); // Base weight on 128MB
            $weighted_instances[] = array_merge($instance, ['weight' => max(1, $weight)]);
        }

        // Select based on weights
        $total_weight = array_sum(array_column($weighted_instances, 'weight'));
        $random_weight = mt_rand(1, $total_weight);

        $current_weight = 0;
        foreach ($weighted_instances as $instance) {
            $current_weight += $instance['weight'];
            if ($random_weight <= $current_weight) {
                return $instance;
            }
        }

        return reset($weighted_instances);
    }

    /**
     * IP hash selection for session stickiness
     */
    private function ip_hash_selection($instances, $job_type)
    {
        $capable_instances = array_filter($instances, function ($instance) use ($job_type) {
            return $this->instance_can_handle_job($instance, $job_type);
        });

        if (empty($capable_instances)) {
            return null;
        }

        // Use job ID or client IP for consistent hashing
        $hash_input = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $hash = crc32($hash_input);
        $index = $hash % count($capable_instances);

        return array_values($capable_instances)[$index];
    }

    /**
     * Check if instance can handle specific job type
     */
    private function instance_can_handle_job($instance, $job_type)
    {
        $role_capabilities = [
            'coordinator_only' => ['notification', 'analytics_update', 'cleanup'],
            'light_processing' => ['notification', 'analytics_update', 'cleanup', 'feed_import'],
            'standard_processing' => ['notification', 'analytics_update', 'cleanup', 'feed_import', 'batch_process'],
            'heavy_processing' => ['notification', 'analytics_update', 'cleanup', 'feed_import', 'batch_process']
        ];

        return in_array($job_type, $role_capabilities[$instance['role']] ?? []);
    }

    /**
     * Get instance load metrics
     */
    private function get_instance_load($instance)
    {
        if (!$this->is_wordpress_environment()) {
            return 0;
        }

        global $wpdb;

        $lb_table = $wpdb->prefix . self::LOAD_BALANCER_TABLE;

        // Get recent request count and average response time
        $recent_requests = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM $lb_table
            WHERE instance_id = %s
            AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ", $instance['instance_id']));

        $avg_response_time = $wpdb->get_var($wpdb->prepare("
            SELECT AVG(response_time) FROM $lb_table
            WHERE instance_id = %s
            AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ", $instance['instance_id'])) ?: 0;

        // Calculate load score (lower is better)
        return $recent_requests * 0.7 + $avg_response_time * 0.3;
    }

    /**
     * Send job to specific instance
     */
    private function send_job_to_instance($job, $instance)
    {
        $start_time = microtime(true);

        try {
            // For now, simulate sending job to instance
            // In a real distributed setup, this would use HTTP requests or message queues
            $result = $this->simulate_instance_processing($job, $instance);

            $response_time = microtime(true) - $start_time;
            $status = $result['success'] ? 'success' : 'failed';

            $this->record_load_balancer_request($instance['instance_id'], $job['job_type'], $status, $response_time);

            if ($result['success']) {
                // Mark job as completed in local queue
                $this->mark_job_completed($job['id']);
            } else {
                // Handle failure
                $this->handle_job_failure($job, $result['error']);
            }
        } catch (\Exception $e) {
            $response_time = microtime(true) - $start_time;
            $this->record_load_balancer_request($instance['instance_id'], $job['job_type'], 'error', $response_time);

            $this->handle_job_failure($job, $e->getMessage());
        }
    }

    /**
     * Simulate processing job on remote instance
     */
    private function simulate_instance_processing($job, $instance)
    {
        // In a real implementation, this would make HTTP request to the instance
        // For now, we'll simulate based on instance capabilities

        $job_data = json_decode($job['job_data'], true);
        $processing_time = $this->estimate_processing_time($job['job_type'], $job_data, $instance);

        // Simulate processing delay
        usleep($processing_time * 1000000); // Convert to microseconds

        // Simulate occasional failures
        if (mt_rand(1, 100) <= 5) { // 5% failure rate
            return [
                'success' => false,
                'error' => 'Simulated processing failure'
            ];
        }

        return [
            'success' => true,
            'result' => 'Job processed successfully on ' . $instance['instance_id']
        ];
    }

    /**
     * Estimate processing time based on job type and instance capabilities
     */
    private function estimate_processing_time($job_type, $job_data, $instance)
    {
        $base_times = [
            'feed_import' => 2.0,    // 2 seconds base
            'batch_process' => 5.0,  // 5 seconds base
            'analytics_update' => 1.0 // 1 second base
        ];

        $base_time = $base_times[$job_type] ?? 1.0;

        // Adjust based on instance capabilities
        $speed_factor = 1.0;
        if ($instance['role'] === 'heavy_processing') {
            $speed_factor = 0.7; // 30% faster
        } elseif ($instance['role'] === 'light_processing') {
            $speed_factor = 1.5; // 50% slower
        }

        // Add some randomness
        $random_factor = mt_rand(80, 120) / 100; // ±20%

        return $base_time * $speed_factor * $random_factor;
    }

    /**
     * Record load balancer request
     */
    private function record_load_balancer_request($instance_id, $request_type, $status, $response_time)
    {
        if (!$this->is_wordpress_environment()) {
            return;
        }

        global $wpdb;

        $table_name = $wpdb->prefix . self::LOAD_BALANCER_TABLE;

        $wpdb->insert(
            $table_name,
            [
                'instance_id' => $instance_id,
                'request_type' => $request_type,
                'response_status' => $status,
                'response_time' => $response_time,
                'created_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%f', '%s']
        );
    }

    /**
     * Mark job as completed
     */
    private function mark_job_completed($job_id)
    {
        if (!$this->is_wordpress_environment()) {
            return;
        }

        global $wpdb;

        $queue_table = $wpdb->prefix . 'puntwork_queue';

        $wpdb->update(
            $queue_table,
            [
                'status' => 'completed',
                'completed_at' => current_time('mysql')
            ],
            ['id' => $job_id],
            ['%s', '%s'],
            ['%d']
        );
    }

    /**
     * Handle job failure
     */
    private function handle_job_failure($job, $error)
    {
        if (!$this->is_wordpress_environment()) {
            return;
        }

        global $wpdb;

        $queue_table = $wpdb->prefix . 'puntwork_queue';

        $attempts = $job['attempts'] + 1;

        if ($attempts >= $job['max_attempts']) {
            // Mark as failed
            $wpdb->update(
                $queue_table,
                ['status' => 'failed'],
                ['id' => $job['id']],
                ['%s'],
                ['%d']
            );
        } else {
            // Reset to pending for retry
            $wpdb->update(
                $queue_table,
                ['status' => 'pending'],
                ['id' => $job['id']],
                ['%s'],
                ['%d']
            );
        }

        error_log(sprintf(
            '[PUNTWORK] Job %d failed (attempt %d/%d): %s',
            $job['id'],
            $attempts,
            $job['max_attempts'],
            $error
        ));
    }

    /**
     * Get active instances
     */
    private function get_active_instances()
    {
        if (!$this->is_wordpress_environment()) {
            return [];
        }

        global $wpdb;

        $instance_table = $wpdb->prefix . 'puntwork_instances';

        return $wpdb->get_results("
            SELECT * FROM $instance_table
            WHERE status = 'active'
            ORDER BY last_seen DESC
        ", ARRAY_A) ?: [];
    }

    /**
     * Get all instances
     */
    private function get_all_instances()
    {
        if (!$this->is_wordpress_environment()) {
            return [];
        }

        global $wpdb;

        $instance_table = $wpdb->prefix . 'puntwork_instances';

        return $wpdb->get_results("
            SELECT * FROM $instance_table
            ORDER BY last_seen DESC
        ", ARRAY_A) ?: [];
    }

    /**
     * Get load balancer statistics
     */
    private function get_load_balancer_stats()
    {
        if (!$this->is_wordpress_environment()) {
            return [
                'active_instances' => 0,
                'total_requests' => 0,
                'successful_requests' => 0,
                'failed_requests' => 0
            ];
        }

        global $wpdb;

        $lb_table = $wpdb->prefix . self::LOAD_BALANCER_TABLE;
        $instance_table = $wpdb->prefix . 'puntwork_instances';

        $stats = $wpdb->get_row("
            SELECT
                (SELECT COUNT(*) FROM $instance_table WHERE status = 'active') as active_instances,
                COUNT(*) as total_requests,
                COUNT(CASE WHEN response_status = 'success' THEN 1 END) as successful_requests,
                COUNT(CASE WHEN response_status IN ('failed', 'error') THEN 1 END) as failed_requests
            FROM $lb_table
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ", ARRAY_A);

        return $stats ?: [
            'active_instances' => 0,
            'total_requests' => 0,
            'successful_requests' => 0,
            'failed_requests' => 0
        ];
    }

    /**
     * AJAX health check for all instances
     */
    public function ajax_health_check_all()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        $instances = $this->get_all_instances();
        $health_results = [];

        foreach ($instances as $instance) {
            $health_results[] = [
                'instance_id' => $instance['instance_id'],
                'healthy' => $this->check_instance_health($instance),
                'last_seen' => $instance['last_seen']
            ];
        }

        wp_send_json_success([
            'health_checks' => $health_results,
            'timestamp' => current_time('mysql')
        ]);
    }

    /**
     * Check health of specific instance
     */
    private function check_instance_health($instance)
    {
        // In a real distributed setup, this would make HTTP request to instance health endpoint
        // For now, just check if instance was seen recently
        $last_seen = strtotime($instance['last_seen']);
        $now = time();

        return ($now - $last_seen) < 300; // 5 minutes
    }

    /**
     * AJAX get load balancer stats
     */
    public function ajax_get_stats()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        $stats = $this->get_load_balancer_stats();

        wp_send_json_success([
            'stats' => $stats,
            'timestamp' => current_time('mysql')
        ]);
    }

    /**
     * Update load balancing strategy
     */
    public function update_strategy($new_strategy)
    {
        $valid_strategies = ['round_robin', 'least_loaded', 'weighted', 'ip_hash'];

        if (in_array($new_strategy, $valid_strategies)) {
            update_option('puntwork_lb_strategy', $new_strategy);
            $this->balancing_strategy = $new_strategy;
            return true;
        }

        return false;
    }
}

// Initialize load balancer
new PuntworkLoadBalancer();