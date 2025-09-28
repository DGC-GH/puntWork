<?php
/**
 * Test script to debug import issues
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "Starting test...\n";

// Define WordPress constants
define('ABSPATH', '/Users/dg/Documents/GitHub/puntWork/'); // Adjust as needed
define('WP_DEBUG', true);

require_once '/Users/dg/Documents/GitHub/puntWork/puntwork.php';

echo "Plugin loaded...\n";

// Simulate the setup
$json_path = '/home/u164580062/domains/belgiumjobs.work/public_html/feeds/combined-jobs.jsonl'; // Adjust path if needed
$start_index = 0;
$batch_size = 2;

echo "Testing load_json_batch...\n";

if (!function_exists('load_json_batch')) {
    echo "ERROR: load_json_batch function not found\n";
    exit(1);
}

$batch = load_json_batch($json_path, $start_index, $batch_size);
echo "Loaded " . count($batch) . " items\n";

if (empty($batch)) {
    echo "No items loaded. Checking file...\n";
    if (file_exists($json_path)) {
        echo "File exists, size: " . filesize($json_path) . "\n";
        $handle = fopen($json_path, 'r');
        if ($handle) {
            $line = fgets($handle);
            echo "First line: " . substr(trim($line), 0, 100) . "\n";
            fclose($handle);
        }
    } else {
        echo "File does not exist\n";
    }
} else {
    echo "First item GUID: " . ($batch[0]['guid'] ?? 'MISSING') . "\n";
}

echo "\nTesting load_and_prepare_batch_items...\n";

if (!function_exists('load_and_prepare_batch_items')) {
    echo "ERROR: load_and_prepare_batch_items function not found\n";
    exit(1);
}

$logs = [];
$result = load_and_prepare_batch_items($json_path, $start_index, $batch_size, 100 * 1024 * 1024, $logs); // 100MB threshold

echo "Result: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
echo "Logs:\n" . implode("\n", $logs) . "\n";
?>