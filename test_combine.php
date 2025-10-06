<?php
echo "Testing manual JSONL combination...\n";

// Define feeds directory
$feeds_dir = '/Users/dg/Documents/GitHub/puntWork/feeds/';

// Find all individual JSONL files (exclude combined)
$jsonl_files = glob($feeds_dir . '*.jsonl');
$individual_files = array_filter($jsonl_files, function($file) {
    return basename($file) !== 'combined-jobs.jsonl';
});

echo 'Found ' . count($individual_files) . ' individual JSONL files:' . "\n";
foreach ($individual_files as $file) {
    echo '  - ' . basename($file) . ' (' . filesize($file) . ' bytes)' . "\n";
}

// Create combined file
$combined_path = $feeds_dir . 'combined-jobs.jsonl';
$combined_handle = fopen($combined_path, 'w');

if (!$combined_handle) {
    echo 'ERROR: Cannot create combined file' . "\n";
    exit(1);
}

$seen_guids = array();
$duplicate_count = 0;
$unique_count = 0;

foreach ($individual_files as $feed_file) {
    echo 'Processing ' . basename($feed_file) . '...' . "\n";

    $handle = fopen($feed_file, 'r');
    if (!$handle) {
        echo '  ERROR: Cannot open ' . basename($feed_file) . "\n";
        continue;
    }

    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if (empty($line)) {
            continue;
        }

        $item = json_decode($line, true);
        if ($item === null) {
            echo '  WARNING: Invalid JSON in ' . basename($feed_file) . "\n";
            continue;
        }

        $guid = $item['guid'] ?? '';
        if (empty($guid)) {
            // Include items without GUID
            fwrite($combined_handle, $line . "\n");
            $unique_count++;
            continue;
        }

        if (isset($seen_guids[$guid])) {
            $duplicate_count++;
            continue;
        }

        $seen_guids[$guid] = true;
        fwrite($combined_handle, $line . "\n");
        $unique_count++;
    }

    fclose($handle);
}

fclose($combined_handle);

echo 'Combination completed!' . "\n";
echo 'Unique items: ' . $unique_count . "\n";
echo 'Duplicates removed: ' . $duplicate_count . "\n";
echo 'Combined file size: ' . filesize($combined_path) . ' bytes' . "\n";
?>