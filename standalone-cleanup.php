<?php
/**
 * Standalone cleanup script for PuntWork job posts
 * This script runs outside of WordPress to avoid memory issues
 */

// Debug logging function
function debug_log($message) {
    $log_file = dirname(__FILE__) . '/standalone-cleanup-debug.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
}

// Load WordPress configuration - try multiple possible paths
$possible_paths = [
    dirname(__FILE__) . '/../wp-config.php',           // From includes/ up to root
    dirname(__FILE__) . '/../../wp-config.php',        // From includes/ up 2 levels
    dirname(__FILE__) . '/../../../wp-config.php',     // From includes/ up 3 levels
    dirname(__FILE__) . '/../../../../wp-config.php',  // From includes/ up 4 levels
    dirname(__FILE__) . '/../../../../../wp-config.php', // From includes/ up 5 levels
    '/public_html/wp-config.php',                      // Absolute path for common hosting
    $_SERVER['DOCUMENT_ROOT'] . '/wp-config.php',      // Document root
];

$wp_config_found = false;
foreach ($possible_paths as $path) {
    debug_log("Checking wp-config.php at: $path");
    if (file_exists($path)) {
        $wp_config_path = $path;
        $wp_config_found = true;
        debug_log("Found wp-config.php at: $path");
        break;
    }
}

if (!$wp_config_found) {
    debug_log("wp-config.php not found in any expected location");
    die("Error: wp-config.php not found in any of the expected locations\n");
}

debug_log("Loading wp-config.php from: $wp_config_path");
require_once($wp_config_path);
debug_log("wp-config.php loaded successfully");

// Ensure required constants are defined with fallbacks
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', '');
if (!defined('DB_USER')) define('DB_USER', '');
if (!defined('DB_PASSWORD')) define('DB_PASSWORD', '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// WordPress table prefix (get from wp-config.php if available, fallback to wp_)
global $table_prefix;
if (!isset($table_prefix) || empty($table_prefix)) {
    $table_prefix = 'wp_'; // Default WordPress table prefix
}
define('WP_PREFIX', $table_prefix);
debug_log("Table prefix set to: " . WP_PREFIX);

// Parse command line arguments
$options = getopt('', ['batch-size:', 'offset:', 'continue:']);
$batch_size = isset($options['batch-size']) ? (int)$options['batch-size'] : 1;
$offset = isset($options['offset']) ? (int)$options['offset'] : 0;
$is_continue = isset($options['continue']) && $options['continue'] === '1';

// Memory limit for this script (keep it low to avoid server issues)
ini_set('memory_limit', '256M');
ini_set('max_execution_time', 300); // 5 minutes

class StandaloneCleanup {
    private $db;
    private $batch_size;
    private $max_memory_percent = 50; // Stop at 50% memory usage
    private $offset;
    private $is_continue;

    public function __construct($batch_size = 1, $offset = 0, $is_continue = false) {
        $this->batch_size = $batch_size;
        $this->offset = $offset;
        $this->is_continue = $is_continue;
        $this->connect_db();
    }

    private function connect_db() {
        try {
            debug_log("Connecting to database: " . DB_HOST . "/" . DB_NAME . " with user " . DB_USER);
            debug_log("Table prefix: " . WP_PREFIX);
            $this->db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
            if ($this->db->connect_error) {
                throw new Exception("Database connection failed: " . $this->db->connect_error);
            }
            $this->db->set_charset(DB_CHARSET);
            debug_log("Database connected successfully");
        } catch (Exception $e) {
            debug_log("Database connection error: " . $e->getMessage());
            die("Database connection error: " . $e->getMessage() . "\n");
        }
    }

    private function get_memory_usage_percent() {
        $memory_limit = ini_get('memory_limit');
        if (preg_match('/^(\d+)([MG])$/', $memory_limit, $matches)) {
            $value = (int)$matches[1];
            $unit = $matches[2];
            $memory_limit_bytes = $unit === 'G' ? $value * 1024 * 1024 * 1024 : $value * 1024 * 1024;
        } elseif (is_numeric($memory_limit)) {
            $memory_limit_bytes = (int)$memory_limit;
        } else {
            $memory_limit_bytes = 128 * 1024 * 1024; // Default 128MB
        }

        $current_memory = memory_get_usage();
        return ($current_memory / $memory_limit_bytes) * 100;
    }

    private function get_draft_trash_jobs($offset = 0) {
        $query = "SELECT ID, post_status, post_title FROM " . WP_PREFIX . "posts
                 WHERE post_type = 'job'
                 AND post_status IN ('draft', 'trash')
                 ORDER BY ID
                 LIMIT ? OFFSET ?";

        debug_log("Executing query: $query with batch_size=" . $this->batch_size . ", offset=$offset");
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $this->batch_size, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $jobs = [];
        while ($row = $result->fetch_assoc()) {
            $jobs[] = $row;
        }

        debug_log("Found " . count($jobs) . " draft/trash job posts");
        if (!empty($jobs)) {
            debug_log("Sample posts: " . json_encode(array_slice($jobs, 0, 3)));
        }

        $stmt->close();
        return $jobs;
    }

    private function delete_post_efficiently($post_id) {
        $post_id = (int)$post_id;
        if (!$post_id) {
            return false;
        }

        // Start transaction
        $this->db->begin_transaction();

        try {
            // Delete post meta
            $stmt = $this->db->prepare("DELETE FROM " . WP_PREFIX . "postmeta WHERE post_id = ?");
            $stmt->bind_param('i', $post_id);
            $stmt->execute();
            $stmt->close();

            // Delete term relationships
            $stmt = $this->db->prepare("DELETE FROM " . WP_PREFIX . "term_relationships WHERE object_id = ?");
            $stmt->bind_param('i', $post_id);
            $stmt->execute();
            $stmt->close();

            // Delete comments
            $stmt = $this->db->prepare("DELETE FROM " . WP_PREFIX . "comments WHERE comment_post_ID = ?");
            $stmt->bind_param('i', $post_id);
            $stmt->execute();
            $stmt->close();

            // Delete comment meta for these comments
            $this->db->query("DELETE FROM " . WP_PREFIX . "commentmeta
                             WHERE comment_id IN (
                                 SELECT comment_ID FROM " . WP_PREFIX . "comments
                                 WHERE comment_post_ID = $post_id
                             )");

            // Delete revisions
            $stmt = $this->db->prepare("DELETE FROM " . WP_PREFIX . "posts
                                       WHERE post_parent = ? AND post_type = 'revision'");
            $stmt->bind_param('i', $post_id);
            $stmt->execute();
            $stmt->close();

            // Finally delete the post itself
            $stmt = $this->db->prepare("DELETE FROM " . WP_PREFIX . "posts WHERE ID = ?");
            $stmt->bind_param('i', $post_id);
            $result = $stmt->execute();
            $stmt->close();

            if (!$result) {
                $this->db->rollback();
                return false;
            }

            // Commit transaction
            $this->db->commit();

            return true;

        } catch (Exception $e) {
            $this->db->rollback();
            echo "SQL error in efficient deletion: " . $e->getMessage() . "\n";
            return false;
        }
    }

    public function run_cleanup() {
        debug_log("Starting cleanup with batch_size=" . $this->batch_size . ", offset=" . $this->offset . ", is_continue=" . ($this->is_continue ? 'true' : 'false'));
        $total_processed = 0;
        $total_deleted = 0;
        $start_time = microtime(true);
        $logs = [];

        // If continuing, get progress from WordPress options
        if ($this->is_continue) {
            $progress = $this->get_progress_from_wp();
            $total_processed = $progress['total_processed'] ?? 0;
            $total_deleted = $progress['total_deleted'] ?? 0;
            $logs = $progress['logs'] ?? [];
        }

        $current_offset = $this->offset;

        while (true) {
            // Check memory usage
            $memory_percent = $this->get_memory_usage_percent();
            if ($memory_percent > $this->max_memory_percent) {
                $result = [
                    'complete' => false,
                    'error' => "Memory usage too high ({$memory_percent}%), stopping for safety",
                    'total_processed' => $total_processed,
                    'total_deleted' => $total_deleted,
                    'progress_percentage' => 0,
                    'logs' => array_slice($logs, -50)
                ];
                echo json_encode($result);
                return;
            }

            // Get batch of jobs
            $jobs = $this->get_draft_trash_jobs($current_offset);

            if (empty($jobs)) {
                // No more jobs to process - cleanup completed
                $end_time = microtime(true);
                $duration = $end_time - $start_time;

                $result = [
                    'complete' => true,
                    'message' => "Cleanup completed: Processed {$total_processed} jobs, deleted {$total_deleted} draft/trash posts",
                    'total_processed' => $total_processed,
                    'total_deleted' => $total_deleted,
                    'time_elapsed' => $duration,
                    'progress_percentage' => 100,
                    'logs' => array_slice($logs, -50)
                ];
                echo json_encode($result);
                return;
            }

            $batch_deleted = 0;
            foreach ($jobs as $job) {
                // Double-check memory before each deletion
                $memory_percent = $this->get_memory_usage_percent();
                if ($memory_percent > $this->max_memory_percent) {
                    $result = [
                        'complete' => false,
                        'error' => "Memory usage too high during batch ({$memory_percent}%), stopping",
                        'total_processed' => $total_processed,
                        'total_deleted' => $total_deleted,
                        'progress_percentage' => 0,
                        'logs' => array_slice($logs, -50)
                    ];
                    echo json_encode($result);
                    return;
                }

                $result = $this->delete_post_efficiently($job['ID']);
                if ($result) {
                    $batch_deleted++;
                    $log_entry = '[' . date('d-M-Y H:i:s') . ' UTC] Deleted ' . $job['post_status'] . ' job ID: ' . $job['ID'];
                    $logs[] = $log_entry;
                    // Limit logs array to prevent memory issues
                    if (count($logs) > 50) {
                        $logs = array_slice($logs, -50);
                    }
                } else {
                    $log_entry = '[' . date('d-M-Y H:i:s') . ' UTC] Error: Failed to delete job ID: ' . $job['ID'];
                    $logs[] = $log_entry;
                    if (count($logs) > 50) {
                        $logs = array_slice($logs, -50);
                    }
                }

                // Force garbage collection
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }

            $total_processed += count($jobs);
            $total_deleted += $batch_deleted;
            $current_offset += $this->batch_size;

            // Update progress in WordPress options
            $this->update_progress_in_wp($total_processed, $total_deleted, $current_offset, $logs);
        }
    }

    private function get_progress_from_wp() {
        // This would need to be implemented to get progress from WordPress options
        // For now, return empty progress
        return [
            'total_processed' => 0,
            'total_deleted' => 0,
            'logs' => []
        ];
    }

    private function update_progress_in_wp($total_processed, $total_deleted, $current_offset, $logs) {
        // This would need to be implemented to update progress in WordPress options
        // For now, we'll skip this as the standalone script doesn't have access to WP functions
    }

    public function __destruct() {
        if ($this->db) {
            $this->db->close();
        }
    }
}

// Run the cleanup
try {
    debug_log("Script started with batch_size=$batch_size, offset=$offset, is_continue=" . ($is_continue ? 'true' : 'false'));
    $cleanup = new StandaloneCleanup($batch_size, $offset, $is_continue);
    $cleanup->run_cleanup();
} catch (Exception $e) {
    debug_log("Fatal error: " . $e->getMessage());
    $result = [
        'error' => 'Fatal error: ' . $e->getMessage(),
        'complete' => false,
        'total_processed' => 0,
        'total_deleted' => 0,
        'logs' => []
    ];
    echo json_encode($result);
    exit(1);
}
?>