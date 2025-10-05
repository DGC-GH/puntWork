<?php
require_once 'wp-load.php';
require_once 'includes/import/import-batch.php';

// Check if combined file exists
$combined_file = puntwork_get_combined_jsonl_path();
if (file_exists($combined_file)) {
    echo 'Combined file exists: ' . $combined_file . PHP_EOL;
    echo 'File size: ' . filesize($combined_file) . ' bytes' . PHP_EOL;

    // Check if it has content
    $handle = fopen($combined_file, 'r');
    $line_count = 0;
    while (!feof($handle) && $line_count < 5) {
        $line = fgets($handle);
        if (!empty(trim($line))) {
            $line_count++;
        }
    }
    fclose($handle);
    echo 'Lines in file (first 5): ' . $line_count . PHP_EOL;
} else {
    echo 'Combined file does not exist: ' . $combined_file . PHP_EOL;
}

// Check import status
$status = get_option('job_import_status', array());
echo 'Import status: ' . json_encode($status, JSON_PRETTY_PRINT) . PHP_EOL;

// Check if import is locked
$lock = get_transient('puntwork_import_lock');
echo 'Import lock: ' . ($lock ? 'true' : 'false') . PHP_EOL;

// Check scheduled events
if (function_exists('wp_get_scheduled_events')) {
    $events = wp_get_scheduled_events('puntwork_start_scheduled_import');
    echo 'Scheduled puntwork_start_scheduled_import events: ' . count($events) . PHP_EOL;
    foreach ($events as $event) {
        echo '  - Scheduled for: ' . date('Y-m-d H:i:s', $event->timestamp) . PHP_EOL;
    }
}