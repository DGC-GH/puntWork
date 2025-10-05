<?php
/**
 * Standalone cleanup script for PuntWork job posts
 * This script runs outside of WordPress to avoid memory issues
 */

// Database configuration - update these values for your environment
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASSWORD', 'your_database_password');
define('DB_CHARSET', 'utf8mb4');

// WordPress table prefix
define('WP_PREFIX', 'wp_');

// Memory limit for this script (keep it low to avoid server issues)
ini_set('memory_limit', '256M');
ini_set('max_execution_time', 300); // 5 minutes

class StandaloneCleanup {
    private $db;
    private $batch_size = 1; // Process 1 post at a time
    private $max_memory_percent = 50; // Stop at 50% memory usage

    public function __construct() {
        $this->connect_db();
    }

    private function connect_db() {
        try {
            $this->db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
            if ($this->db->connect_error) {
                throw new Exception("Database connection failed: " . $this->db->connect_error);
            }
            $this->db->set_charset(DB_CHARSET);
            echo "Database connected successfully\n";
        } catch (Exception $e) {
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

        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $this->batch_size, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $jobs = [];
        while ($row = $result->fetch_assoc()) {
            $jobs[] = $row;
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
        echo "Starting standalone cleanup operation...\n";
        echo "Memory limit: " . ini_get('memory_limit') . "\n";
        echo "Batch size: {$this->batch_size}\n";
        echo "Max memory threshold: {$this->max_memory_percent}%\n\n";

        $offset = 0;
        $total_processed = 0;
        $total_deleted = 0;
        $start_time = microtime(true);

        while (true) {
            // Check memory usage
            $memory_percent = $this->get_memory_usage_percent();
            if ($memory_percent > $this->max_memory_percent) {
                echo "Memory usage too high ({$memory_percent}%), stopping for safety\n";
                break;
            }

            // Get batch of jobs
            $jobs = $this->get_draft_trash_jobs($offset);

            if (empty($jobs)) {
                echo "No more jobs to process\n";
                break;
            }

            echo "Processing batch at offset $offset (memory: " . round($memory_percent, 1) . "%)\n";

            $batch_deleted = 0;
            foreach ($jobs as $job) {
                // Double-check memory before each deletion
                $memory_percent = $this->get_memory_usage_percent();
                if ($memory_percent > $this->max_memory_percent) {
                    echo "Memory usage too high during batch ({$memory_percent}%), stopping\n";
                    break 2;
                }

                $result = $this->delete_post_efficiently($job['ID']);
                if ($result) {
                    $batch_deleted++;
                    echo "  Deleted {$job['post_status']} job ID: {$job['ID']}\n";
                } else {
                    echo "  Failed to delete job ID: {$job['ID']}\n";
                }

                // Force garbage collection
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }

            $total_processed += count($jobs);
            $total_deleted += $batch_deleted;
            $offset += $this->batch_size;

            echo "Batch completed: processed " . count($jobs) . ", deleted $batch_deleted\n";

            // Small delay to prevent overwhelming the server
            usleep(100000); // 0.1 seconds
        }

        $end_time = microtime(true);
        $duration = $end_time - $start_time;

        echo "\nCleanup completed!\n";
        echo "Total processed: $total_processed\n";
        echo "Total deleted: $total_deleted\n";
        echo "Duration: " . round($duration, 2) . " seconds\n";
        echo "Final memory usage: " . round($this->get_memory_usage_percent(), 1) . "%\n";
    }

    public function __destruct() {
        if ($this->db) {
            $this->db->close();
        }
    }
}

// Run the cleanup
try {
    $cleanup = new StandaloneCleanup();
    $cleanup->run_cleanup();
} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nScript completed successfully\n";
?>