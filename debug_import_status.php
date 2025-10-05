<?php
/**
 * Debug script to check import status and recent logs
 */

// Load WordPress
require_once '../../../wp-load.php';

echo "=== PUNTWORK IMPORT DEBUG ===\n\n";

// Check import status
$status = get_option('job_import_status', array());
echo "Current Import Status:\n";
echo json_encode($status, JSON_PRETTY_PRINT) . "\n\n";

$progress = get_option('job_import_progress', 0);
echo "Import Progress: $progress\n\n";

$last_batch_time = get_option('job_import_last_batch_time', 0);
echo "Last Batch Time: $last_batch_time seconds\n\n";

$last_batch_processed = get_option('job_import_last_batch_processed', 0);
echo "Last Batch Processed: $last_batch_processed items\n\n";

// Check if debug.log exists and show recent entries
$debug_log_path = __DIR__ . '/../debug.log';
if (file_exists($debug_log_path)) {
    echo "=== RECENT DEBUG LOG ENTRIES ===\n";
    $lines = file($debug_log_path);
    $recent_lines = array_slice($lines, -50); // Last 50 lines
    foreach ($recent_lines as $line) {
        echo $line;
    }
} else {
    echo "Debug log not found at: $debug_log_path\n";
}

// Check for FORK-DEBUG and ITEM-DEBUG logs
echo "\n=== SEARCHING FOR DEBUG LOGS ===\n";
$fork_debug_count = 0;
$item_debug_count = 0;
$child_process_errors = 0;

foreach ($lines as $line) {
    if (strpos($line, '[FORK-DEBUG]') !== false) {
        $fork_debug_count++;
    }
    if (strpos($line, '[ITEM-DEBUG]') !== false) {
        $item_debug_count++;
    }
    if (strpos($line, 'Failed to get result from child process') !== false) {
        $child_process_errors++;
    }
}

echo "FORK-DEBUG logs found: $fork_debug_count\n";
echo "ITEM-DEBUG logs found: $item_debug_count\n";
echo "Child process errors: $child_process_errors\n";

// Check if ACF functions are available
echo "\n=== ACF FUNCTIONS CHECK ===\n";
if (function_exists('get_acf_fields')) {
    echo "get_acf_fields() function: AVAILABLE\n";
    $acf_fields = get_acf_fields();
    echo "ACF fields count: " . count($acf_fields) . "\n";
} else {
    echo "get_acf_fields() function: NOT AVAILABLE\n";
}

if (function_exists('get_zero_empty_fields')) {
    echo "get_zero_empty_fields() function: AVAILABLE\n";
    $zero_fields = get_zero_empty_fields();
    echo "Zero empty fields count: " . count($zero_fields) . "\n";
} else {
    echo "get_zero_empty_fields() function: NOT AVAILABLE\n";
}

// Check ACF plugin status
echo "\n=== ACF PLUGIN CHECK ===\n";
if (defined('ACF_VERSION')) {
    echo "ACF_VERSION: " . ACF_VERSION . "\n";
} else {
    echo "ACF_VERSION: NOT DEFINED\n";
}

if (class_exists('ACF')) {
    echo "ACF class: AVAILABLE\n";
} else {
    echo "ACF class: NOT AVAILABLE\n";
}

if (function_exists('acf_get_field')) {
    echo "acf_get_field() function: AVAILABLE\n";
} else {
    echo "acf_get_field() function: NOT AVAILABLE\n";
}

if (function_exists('update_field')) {
    echo "update_field() function: AVAILABLE\n";
} else {
    echo "update_field() function: NOT AVAILABLE\n";
}

// Check if mappings file is loaded
echo "\n=== MAPPINGS FILE CHECK ===\n";
$mappings_path = __DIR__ . '/includes/mappings/mappings-fields.php';
if (file_exists($mappings_path)) {
    echo "Mappings file exists: YES\n";
    echo "Mappings file path: $mappings_path\n";

    // Try to include it manually
    echo "Attempting to include mappings file...\n";
    include_once $mappings_path;

    if (function_exists('get_acf_fields')) {
        echo "get_acf_fields() after manual include: AVAILABLE\n";
    } else {
        echo "get_acf_fields() after manual include: STILL NOT AVAILABLE\n";
    }
} else {
    echo "Mappings file exists: NO\n";
    echo "Checked path: $mappings_path\n";
}

// Check combined JSONL file
$jsonl_path = puntwork_get_combined_jsonl_path();
echo "\n=== JSONL FILE CHECK ===\n";
if (file_exists($jsonl_path)) {
    echo "Combined JSONL file exists: YES\n";
    echo "File size: " . filesize($jsonl_path) . " bytes\n";
    echo "File readable: " . (is_readable($jsonl_path) ? 'YES' : 'NO') . "\n";

    // Try to count lines
    $line_count = 0;
    if ($handle = fopen($jsonl_path, 'r')) {
        while (!feof($handle)) {
            fgets($handle);
            $line_count++;
        }
        fclose($handle);
        echo "Line count: $line_count\n";
    }
} else {
    echo "Combined JSONL file exists: NO\n";
    echo "Expected path: $jsonl_path\n";
}

echo "\n=== END DEBUG ===\n";
?>