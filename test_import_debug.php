<?php
/**
 * Import Process Debug Test
 * Tests the entire import process from start to finish
 */

// Include WordPress
require_once '../../../wp-load.php';

if (!defined('ABSPATH')) {
    die('WordPress not loaded');
}

echo "=== PUNTWORK IMPORT PROCESS DEBUG TEST ===\n\n";

// Test 1: Check batch size management
echo "1. Testing batch size management...\n";
$current_batch_size = get_option('job_import_batch_size', 5);
echo "   Current batch size: $current_batch_size\n";

$previous_time = get_option('job_import_previous_batch_time', 0);
$last_time = get_option('job_import_last_batch_time', 0);
echo "   Previous batch time: {$previous_time}s\n";
echo "   Last batch time: {$last_time}s\n";

// Test 2: Check feeds configuration
echo "\n2. Testing feeds configuration...\n";
$feeds = get_option('job_import_feeds', array());
if (empty($feeds)) {
    echo "   ERROR: No feeds configured!\n";
} else {
    echo "   Found " . count($feeds) . " feeds:\n";
    foreach ($feeds as $key => $url) {
        echo "   - $key: $url\n";
    }
}

// Test 3: Check database indexes
echo "\n3. Testing database optimization...\n";
if (function_exists('get_database_optimization_status')) {
    $status = get_database_optimization_status();
    echo "   Indexes created: {$status['indexes_created']}/{$status['total_indexes']}\n";
    if (!empty($status['missing_indexes'])) {
        echo "   Missing indexes: " . implode(', ', $status['missing_indexes']) . "\n";
    }
} else {
    echo "   Database optimization function not available\n";
}

// Test 4: Check recent jobs
echo "\n4. Testing recent jobs in database...\n";
global $wpdb;
$recent_jobs = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'job' AND post_status IN ('publish', 'draft')");
echo "   Total jobs in database: $recent_jobs\n";

$guid_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'guid'");
echo "   Jobs with GUIDs: $guid_count\n";

// Test 5: Check JSONL files
echo "\n5. Testing JSONL file processing...\n";
$feeds_dir = WP_CONTENT_DIR . '/feeds/';
if (file_exists($feeds_dir)) {
    $files = glob($feeds_dir . '*.jsonl');
    echo "   Found " . count($files) . " JSONL files:\n";
    foreach ($files as $file) {
        $size = filesize($file);
        $basename = basename($file);
        echo "   - $basename: " . number_format($size) . " bytes\n";

        // Test reading first few lines
        $handle = fopen($file, 'r');
        if ($handle) {
            $line_count = 0;
            $valid_json = 0;
            while (($line = fgets($handle)) !== false && $line_count < 5) {
                $line_count++;
                $line = trim($line);
                if (!empty($line)) {
                    $data = json_decode($line, true);
                    if ($data !== null) {
                        $valid_json++;
                        $guid = $data['guid'] ?? 'MISSING';
                        echo "     Line $line_count: GUID=$guid\n";
                    } else {
                        echo "     Line $line_count: INVALID JSON\n";
                    }
                }
            }
            fclose($handle);
            echo "     Valid JSON lines in sample: $valid_json/$line_count\n";
        }
    }
} else {
    echo "   Feeds directory does not exist: $feeds_dir\n";
}

// Test 6: Test batch size adjustment logic
echo "\n6. Testing batch size adjustment logic...\n";
if (function_exists('adjust_batch_size')) {
    $test_scenarios = [
        ['batch_size' => 10, 'memory_ratio' => 0.3, 'current_time' => 2.0, 'previous_time' => 2.5],
        ['batch_size' => 10, 'memory_ratio' => 0.3, 'current_time' => 1.5, 'previous_time' => 2.5],
        ['batch_size' => 10, 'memory_ratio' => 0.3, 'current_time' => 2.5, 'previous_time' => 2.5],
        ['batch_size' => 10, 'memory_ratio' => 0.8, 'current_time' => 2.0, 'previous_time' => 2.5],
    ];

    foreach ($test_scenarios as $i => $scenario) {
        $result = adjust_batch_size(
            $scenario['batch_size'],
            100 * 1024 * 1024, // 100MB
            $scenario['memory_ratio'],
            $scenario['current_time'],
            $scenario['previous_time']
        );
        echo "   Scenario " . ($i + 1) . ": " . json_encode($scenario) . " -> batch_size: {$result['batch_size']}, reason: {$result['reason']}\n";
    }
} else {
    echo "   Batch size adjustment function not available\n";
}

echo "\n=== TEST COMPLETE ===\n";
echo "Check the debug.log file for detailed import process logs.\n";