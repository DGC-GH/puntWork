<?php
/**
 * Horizontal Scaling Manager for puntWork
 * Provides distributed processing capabilities across multiple instances
 */

namespace Puntwork;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Horizontal Scaling Manager Class
 * Manages distributed processing across multiple server instances
 */
class PuntworkHorizontalScalingManager {
    private const INSTANCE_TABLE = 'puntwork_instances';
    private const HEALTH_CHECK_INTERVAL = 30; // seconds
    private const INSTANCE_TIMEOUT = 300; // 5 minutes
    private const MAX_INSTANCES = 10;

    private $instance_id;
    private $instance_role;
    private $last_health_check;

    public function __construct() {
        $this->instance_id = $this->generate_instance_id();
        $this->instance_role = $this->determine_instance_role();
        $this->last_health_check = time();

        // Only initialize database operations if WordPress is properly loaded
        if ($this->is_wordpress_environment()) {
            $this->init_hooks();
            $this->create_instance_table();
            $this->register_instance();
        }
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('init', [$this, 'health_check']);
        add_action('wp_ajax_puntwork_scaling_health', [$this, 'ajax_health_check']);
        add_action('wp_ajax_nopriv_puntwork_scaling_health', [$this, 'ajax_health_check']);
        add_action('puntwork_cleanup_instances', [$this, 'cleanup_dead_instances']);

        // Schedule cleanup
        if (!wp_next_scheduled('puntwork_cleanup_instances')) {
            wp_schedule_event(time(), 'hourly', 'puntwork_cleanup_instances');
        }

        // Register shutdown function for cleanup
        add_action('shutdown', [$this, 'unregister_instance']);
    }

    /**
     * Check if we're in a proper WordPress environment
     */
    private function is_wordpress_environment() {
        global $wpdb;
        return isset($wpdb) && $wpdb instanceof \wpdb;
    }

    /**
     * Generate unique instance ID
     */
    private function generate_instance_id() {
        $server_id = gethostname() ?: 'unknown';
        $process_id = getmypid() ?: rand(1000, 9999);
        $timestamp = time();

        return sprintf('%s-%d-%d', $server_id, $process_id, $timestamp);
    }

    /**
     * Determine instance role based on server capabilities
     */
    private function determine_instance_role() {
        // Check server capabilities to determine role
        $memory_limit = ini_get('memory_limit');
        $memory_bytes = $this->parse_size($memory_limit);

        $cpu_count = $this->get_cpu_count();

        // Determine role based on resources
        if ($memory_bytes >= 512 * 1024 * 1024 && $cpu_count >= 4) {
            return 'heavy_processing'; // Can handle large imports
        } elseif ($memory_bytes >= 256 * 1024 * 1024 && $cpu_count >= 2) {
            return 'standard_processing'; // Standard processing
        } elseif ($memory_bytes >= 128 * 1024 * 1024) {
            return 'light_processing'; // Light processing only
        } else {
            return 'coordinator_only'; // Coordination and API only
        }
    }

    /**
     * Parse size string to bytes
     */
    private function parse_size($size) {
        $unit = strtolower(substr($size, -1));
        $value = (int) substr($size, 0, -1);

        switch ($unit) {
            case 'g': return $value * 1024 * 1024 * 1024;
            case 'm': return $value * 1024 * 1024;
            case 'k': return $value * 1024;
            default: return $value;
        }
    }

    /**
     * Get CPU count
     */
    private function get_cpu_count() {
        if (function_exists('shell_exec')) {
            $cpu_count = shell_exec('nproc 2>/dev/null') ?: shell_exec('sysctl -n hw.ncpu 2>/dev/null');
            if ($cpu_count) {
                return (int) trim($cpu_count);
            }
        }

        // Fallback: estimate based on memory
        $memory_limit = ini_get('memory_limit');
        $memory_bytes = $this->parse_size($memory_limit);

        if ($memory_bytes >= 1024 * 1024 * 1024) { // 1GB+
            return 4;
        } elseif ($memory_bytes >= 512 * 1024 * 1024) { // 512MB+
            return 2;
        } else {
            return 1;
        }
    }

    /**
     * Create instances table
     */
    private function create_instance_table() {
        if (!$this->is_wordpress_environment()) {
            return;
        }

        global $wpdb;

        $table_name = $wpdb->prefix . self::INSTANCE_TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            instance_id varchar(100) NOT NULL,
            server_name varchar(100) NOT NULL,
            ip_address varchar(45) NOT NULL,
            role varchar(50) NOT NULL,
            status enum('active','inactive','maintenance') DEFAULT 'active',
            cpu_count int(11) DEFAULT 1,
            memory_limit bigint(20) DEFAULT 0,
            last_seen datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (instance_id),
            KEY server_name (server_name),
            KEY status_last_seen (status, last_seen)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Register this instance
     */
    private function register_instance() {
        if (!$this->is_wordpress_environment()) {
            return;
        }

        global $wpdb;

        $table_name = $wpdb->prefix . self::INSTANCE_TABLE;

        $data = [
            'instance_id' => $this->instance_id,
            'server_name' => gethostname() ?: 'unknown',
            'ip_address' => $this->get_server_ip(),
            'role' => $this->instance_role,
            'status' => 'active',
            'cpu_count' => $this->get_cpu_count(),
            'memory_limit' => $this->parse_size(ini_get('memory_limit')),
            'last_seen' => current_time('mysql')
        ];

        $wpdb->replace($table_name, $data);
    }

    /**
     * Get server IP address
     */
    private function get_server_ip() {
        $server_ip = $_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? '127.0.0.1';

        // Try to get public IP if behind load balancer
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded_ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $server_ip = trim($forwarded_ips[0]);
        } elseif (isset($_SERVER['HTTP_X_REAL_IP'])) {
            $server_ip = $_SERVER['HTTP_X_REAL_IP'];
        }

        return $server_ip;
    }

    /**
     * Health check for this instance
     */
    public function health_check() {
        if (!$this->is_wordpress_environment()) {
            return;
        }

        $now = time();

        // Only run health check every 30 seconds
        if ($now - $this->last_health_check < self::HEALTH_CHECK_INTERVAL) {
            return;
        }

        $this->last_health_check = $now;

        // Update last seen timestamp
        global $wpdb;
        $table_name = $wpdb->prefix . self::INSTANCE_TABLE;

        $wpdb->update(
            $table_name,
            ['last_seen' => current_time('mysql')],
            ['instance_id' => $this->instance_id],
            ['%s'],
            ['%s']
        );

        // Check system resources
        $health_status = $this->check_system_health();

        if (!$health_status['healthy']) {
            // Mark instance as unhealthy
            $wpdb->update(
                $table_name,
                ['status' => 'maintenance'],
                ['instance_id' => $this->instance_id],
                ['%s'],
                ['%s']
            );

            error_log('[PUNTWORK] Instance unhealthy: ' . implode(', ', $health_status['issues']));
        }
    }

    /**
     * Check system health
     */
    private function check_system_health() {
        $issues = [];
        $healthy = true;

        // Check memory usage
        $memory_usage = memory_get_peak_usage(true);
        $memory_limit = $this->parse_size(ini_get('memory_limit'));

        if ($memory_usage / $memory_limit > 0.9) { // 90% memory usage
            $issues[] = 'High memory usage';
            $healthy = false;
        }

        // Check disk space
        $disk_free = disk_free_space(__DIR__);
        $disk_total = disk_total_space(__DIR__);

        if ($disk_free / $disk_total < 0.1) { // Less than 10% free space
            $issues[] = 'Low disk space';
            $healthy = false;
        }

        // Check database connectivity (only if WordPress environment)
        if ($this->is_wordpress_environment()) {
            global $wpdb;
            if (!$wpdb->check_connection()) {
                $issues[] = 'Database connection failed';
                $healthy = false;
            }
        }

        return [
            'healthy' => $healthy,
            'issues' => $issues
        ];
    }

    /**
     * AJAX health check endpoint
     */
    public function ajax_health_check() {
        $health_status = $this->check_system_health();

        if ($health_status['healthy']) {
            wp_send_json_success([
                'status' => 'healthy',
                'instance_id' => $this->instance_id,
                'role' => $this->instance_role,
                'timestamp' => current_time('mysql')
            ]);
        } else {
            wp_send_json_error([
                'status' => 'unhealthy',
                'issues' => $health_status['issues'],
                'instance_id' => $this->instance_id,
                'timestamp' => current_time('mysql')
            ], 503);
        }
    }

    /**
     * Cleanup dead instances
     */
    public function cleanup_dead_instances() {
        if (!$this->is_wordpress_environment()) {
            return;
        }

        global $wpdb;

        $table_name = $wpdb->prefix . self::INSTANCE_TABLE;
        $timeout_time = date('Y-m-d H:i:s', time() - self::INSTANCE_TIMEOUT);

        $wpdb->query($wpdb->prepare("
            UPDATE $table_name
            SET status = 'inactive'
            WHERE last_seen < %s AND status = 'active'
        ", $timeout_time));
    }

    /**
     * Unregister instance on shutdown
     */
    public function unregister_instance() {
        if (!$this->is_wordpress_environment()) {
            return;
        }

        global $wpdb;

        $table_name = $wpdb->prefix . self::INSTANCE_TABLE;

        $wpdb->update(
            $table_name,
            ['status' => 'inactive'],
            ['instance_id' => $this->instance_id],
            ['%s'],
            ['%s']
        );
    }

    /**
     * Get active instances
     */
    public function get_active_instances($role = null) {
        if (!$this->is_wordpress_environment()) {
            return [];
        }

        global $wpdb;

        $table_name = $wpdb->prefix . self::INSTANCE_TABLE;

        $where = "status = 'active'";
        $params = [];

        if ($role) {
            $where .= " AND role = %s";
            $params[] = $role;
        }

        $query = "SELECT * FROM $table_name WHERE $where ORDER BY last_seen DESC";

        if (!empty($params)) {
            $query = $wpdb->prepare($query, $params);
        }

        return $wpdb->get_results($query, ARRAY_A);
    }

    /**
     * Get instance statistics
     */
    public function get_instance_stats() {
        if (!$this->is_wordpress_environment()) {
            return ['active' => 0, 'inactive' => 0, 'maintenance' => 0, 'total' => 0];
        }

        global $wpdb;

        $table_name = $wpdb->prefix . self::INSTANCE_TABLE;

        $stats = $wpdb->get_row("
            SELECT
                COUNT(CASE WHEN status = 'active' THEN 1 END) as active,
                COUNT(CASE WHEN status = 'inactive' THEN 1 END) as inactive,
                COUNT(CASE WHEN status = 'maintenance' THEN 1 END) as maintenance,
                COUNT(*) as total
            FROM $table_name
        ", ARRAY_A);

        return $stats ?: ['active' => 0, 'inactive' => 0, 'maintenance' => 0, 'total' => 0];
    }

    /**
     * Get current instance info
     */
    public function get_current_instance() {
        return [
            'instance_id' => $this->instance_id,
            'role' => $this->instance_role,
            'server_name' => gethostname() ?: 'unknown',
            'ip_address' => $this->get_server_ip(),
            'cpu_count' => $this->get_cpu_count(),
            'memory_limit' => $this->parse_size(ini_get('memory_limit'))
        ];
    }

    /**
     * Check if this instance can handle a specific job type
     */
    public function can_handle_job($job_type) {
        $role_capabilities = [
            'coordinator_only' => ['notification', 'analytics_update', 'cleanup'],
            'light_processing' => ['notification', 'analytics_update', 'cleanup', 'feed_import'],
            'standard_processing' => ['notification', 'analytics_update', 'cleanup', 'feed_import', 'batch_process'],
            'heavy_processing' => ['notification', 'analytics_update', 'cleanup', 'feed_import', 'batch_process']
        ];

        return in_array($job_type, $role_capabilities[$this->instance_role] ?? []);
    }

    /**
     * Get optimal instance for job type
     */
    public function get_optimal_instance($job_type) {
        $instances = $this->get_active_instances();

        $capable_instances = array_filter($instances, function($instance) use ($job_type) {
            $role_capabilities = [
                'coordinator_only' => ['notification', 'analytics_update', 'cleanup'],
                'light_processing' => ['notification', 'analytics_update', 'cleanup', 'feed_import'],
                'standard_processing' => ['notification', 'analytics_update', 'cleanup', 'feed_import', 'batch_process'],
                'heavy_processing' => ['notification', 'analytics_update', 'cleanup', 'feed_import', 'batch_process']
            ];

            return in_array($job_type, $role_capabilities[$instance['role']] ?? []);
        });

        if (empty($capable_instances)) {
            return null;
        }

        // Prefer heavy processing instances for heavy jobs
        if ($job_type === 'batch_process') {
            $heavy_instances = array_filter($capable_instances, function($instance) {
                return $instance['role'] === 'heavy_processing';
            });

            if (!empty($heavy_instances)) {
                return reset($heavy_instances);
            }
        }

        // Return first available instance
        return reset($capable_instances);
    }
}

// Initialize horizontal scaling manager
new PuntworkHorizontalScalingManager();