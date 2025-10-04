<?php
/**
 * Debug script to check current import status
 */

// Load WordPress
require_once '../../../wp-load.php';

echo "Current import status:\n";
$status = get_option('job_import_status', array());
if (empty($status)) {
    echo "No import status found\n";
} else {
    echo json_encode($status, JSON_PRETTY_PRINT) . "\n";
}

echo "\nCurrent import progress: " . get_option('job_import_progress', 0) . "\n";

echo "\nCombined JSONL file exists: " . (file_exists('feeds/combined-jobs.jsonl') ? 'yes' : 'no') . "\n";
if (file_exists('feeds/combined-jobs.jsonl')) {
    echo "File size: " . filesize('feeds/combined-jobs.jsonl') . " bytes\n";
    $count = shell_exec('wc -l < feeds/combined-jobs.jsonl 2>/dev/null');
    echo "Line count: " . trim($count) . "\n";
}

echo "\nExisting GUIDs cache: " . (get_option('job_existing_guids') !== false ? 'exists' : 'not cached') . "\n";
echo "Processed GUIDs: " . (get_option('job_import_processed_guids') !== false ? 'exists' : 'not cached') . "\n";