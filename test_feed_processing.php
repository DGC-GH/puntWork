<?php
/**
 * Test script to run feed processing and identify issues
 */

// Define WordPress constants
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}

// Load required files
require_once __DIR__ . '/includes/utilities/PuntWorkLogger.php';
require_once __DIR__ . '/includes/utilities/utility-helpers.php';
require_once __DIR__ . '/includes/utilities/item-cleaning.php';
require_once __DIR__ . '/includes/utilities/item-inference.php';
require_once __DIR__ . '/includes/utilities/AdvancedJsonlProcessor.php';
require_once __DIR__ . '/includes/utilities/JsonlOptimizer.php';
require_once __DIR__ . '/includes/utilities/CacheManager.php';
require_once __DIR__ . '/includes/mappings/mappings-geographic.php';
require_once __DIR__ . '/includes/mappings/mappings-salary.php';
require_once __DIR__ . '/includes/mappings/mappings-icons.php';
require_once __DIR__ . '/includes/mappings/mappings-schema.php';
require_once __DIR__ . '/includes/ai/job-categorizer.php';
require_once __DIR__ . '/includes/ai/content-quality-scorer.php';
require_once __DIR__ . '/includes/import/feed-processor.php';
require_once __DIR__ . '/includes/import/process-xml-batch.php';
require_once __DIR__ . '/includes/import/combine-jsonl.php';
require_once __DIR__ . '/includes/core/core-structure-logic.php';

// Mock WordPress constants and functions
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!function_exists('sanitize_title')) {
    function sanitize_title($title) {
        // Simple mock of WordPress sanitize_title function
        return strtolower(preg_replace('/[^a-zA-Z0-9\-]/', '-', $title));
    }
}
if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return true;
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '') {
        return 'Test Site';
    }
}

if (!function_exists('get_transient')) {
    function get_transient($key) {
        return false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($key, $value, $expiration = 0) {
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($key) {
        return true;
    }
}

if (!function_exists('is_admin')) {
    function is_admin() {
        return false;
    }
}

if (!function_exists('wp_kses')) {
    function wp_kses($content, $allowed_html = []) {
        // Simple mock - just return content as-is for testing
        return $content;
    }
}

if (!function_exists('wp_kses_allowed_html')) {
    function wp_kses_allowed_html($context = 'post') {
        // Simple mock - return basic allowed HTML for testing
        return [
            'a' => ['href' => [], 'title' => []],
            'b' => [],
            'br' => [],
            'em' => [],
            'i' => [],
            'p' => [],
            'strong' => [],
            'ul' => [],
            'ol' => [],
            'li' => [],
        ];
    }
}

// PuntWorkLogger is loaded from the real file above

// Mock FeedProcessor if not loaded
if (!class_exists('Puntwork\\FeedProcessor')) {
    class FeedProcessor {
        public static function detectFormat($url, $content) {
            if (strpos($content, '<rss') !== false || strpos($content, '<channel>') !== false) {
                return 'rss';
            }
            if (strpos($content, '<job>') !== false) {
                return 'xml';
            }
            return 'json';
        }

        public static function processFeed($feed_path, $format, $handle, $feed_key, $output_dir, $fallback_domain, $batch_size, &$total_items, &$logs) {
            // Simple mock implementation
            $logs[] = "Mock processing feed: $feed_key";
            return 10; // Return mock count
        }
    }
}

// Mock gzip_file function
if (!function_exists('gzip_file')) {
    function gzip_file($source, $dest) {
        // Simple copy for testing
        copy($source, $dest);
        return true;
    }
}

// Mock download_feed function
if (!function_exists('download_feed')) {
    function download_feed($url, $file_path, $output_dir, &$logs) {
        // Create a mock XML feed for testing
        $mock_xml = '<?xml version="1.0" encoding="UTF-8"?>
<jobs>
    <job>
        <title>Test Job 1</title>
        <description>Test job description</description>
        <location>Test City</location>
        <guid>test-job-1</guid>
    </job>
    <job>
        <title>Test Job 2</title>
        <description>Another test job</description>
        <location>Another City</location>
        <guid>test-job-2</guid>
    </job>
</jobs>';

        file_put_contents($file_path, $mock_xml);
        $logs[] = "Mock downloaded feed to: $file_path";
        return true;
    }
}

echo "Starting feed processing test...\n";

// Test with mock feeds
$mock_feeds = [
    'test_feed_1' => 'http://example.com/feed1.xml',
    'test_feed_2' => 'http://example.com/feed2.xml',
];

$output_dir = __DIR__ . '/feeds/';
$fallback_domain = 'belgiumjobs.work';
$logs = [];

echo "Mock feeds: " . json_encode($mock_feeds) . "\n";
echo "Output dir: $output_dir\n";

// Ensure output directory exists
if (!wp_mkdir_p($output_dir)) {
    echo "ERROR: Could not create output directory\n";
    exit(1);
}

$total_items = 0;

// Process each mock feed
foreach ($mock_feeds as $feed_key => $url) {
    echo "Processing feed: $feed_key\n";

    try {
        $count = \Puntwork\process_one_feed($feed_key, $url, $output_dir, $fallback_domain, $logs);
        $total_items += $count;
        echo "Feed $feed_key processed: $count items\n";
    } catch (Exception $e) {
        echo "ERROR processing feed $feed_key: " . $e->getMessage() . "\n";
    }
}

echo "Total items processed: $total_items\n";
echo "Logs: " . json_encode($logs, JSON_PRETTY_PRINT) . "\n";

// Check what files were created
$files = glob($output_dir . '*');
echo "Files in output directory:\n";
foreach ($files as $file) {
    $size = filesize($file);
    echo "  " . basename($file) . " ($size bytes)\n";
}

// Try to combine files
echo "\nAttempting to combine JSONL files...\n";
try {
    \Puntwork\combine_jsonl_files($mock_feeds, $output_dir, $total_items, $logs);
    echo "Combination completed\n";
} catch (Exception $e) {
    echo "ERROR during combination: " . $e->getMessage() . "\n";
}

echo "Final logs: " . json_encode($logs, JSON_PRETTY_PRINT) . "\n";

// Check final files
$final_files = glob($output_dir . '*');
echo "Final files in output directory:\n";
foreach ($final_files as $file) {
    $size = filesize($file);
    echo "  " . basename($file) . " ($size bytes)\n";
}

echo "Test completed.\n";
?>