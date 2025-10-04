<?php
// Debug script to check combined-jobs.jsonl file
// Run this on the server to diagnose the import issue

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define ABSPATH for standalone execution
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/');
}

echo "=== PUNTWORK IMPORT DEBUG ===\n\n";

$json_path = ABSPATH . 'feeds/combined-jobs.jsonl';

echo "Checking file: $json_path\n";
echo "File exists: " . (file_exists($json_path) ? 'YES' : 'NO') . "\n";

if (!file_exists($json_path)) {
    echo "ERROR: File does not exist!\n";
    exit(1);
}

echo "File size: " . number_format(filesize($json_path)) . " bytes\n";
echo "File readable: " . (is_readable($json_path) ? 'YES' : 'NO') . "\n\n";

if (!is_readable($json_path)) {
    echo "ERROR: File is not readable!\n";
    exit(1);
}

// Check first few lines
echo "=== FIRST 10 LINES ===\n";
$handle = fopen($json_path, 'r');
if ($handle) {
    $line_num = 0;
    while (($line = fgets($handle)) !== false && $line_num < 10) {
        $line_num++;
        $trimmed = trim($line);
        echo "Line $line_num: " . substr($trimmed, 0, 100) . (strlen($trimmed) > 100 ? '...[truncated]' : '') . "\n";

        if (!empty($trimmed)) {
            $item = json_decode($trimmed, true);
            if ($item === null) {
                echo "  -> INVALID JSON: " . json_last_error_msg() . "\n";
            } else {
                echo "  -> VALID JSON, GUID: " . ($item['guid'] ?? 'MISSING') . "\n";
            }
        } else {
            echo "  -> EMPTY LINE\n";
        }
    }
    fclose($handle);
} else {
    echo "ERROR: Cannot open file for reading!\n";
}

echo "\n=== COUNTING VALID ITEMS (first 1000 lines) ===\n";
$handle = fopen($json_path, 'r');
if ($handle) {
    $count = 0;
    $invalid = 0;
    $empty = 0;
    $line_num = 0;
    $bom = "\xef\xbb\xbf";

    while (($line = fgets($handle)) !== false && $line_num < 1000) {
        $line_num++;
        $line = trim($line);

        // Remove BOM if present
        if (substr($line, 0, 3) === $bom) {
            $line = substr($line, 3);
        }

        if (empty($line)) {
            $empty++;
            continue;
        }

        $item = json_decode($line, true);
        if ($item !== null) {
            $count++;
        } else {
            $invalid++;
            if ($invalid <= 5) { // Show first 5 invalid lines
                echo "Invalid JSON at line $line_num: " . json_last_error_msg() . "\n";
                echo "  Preview: " . substr($line, 0, 100) . "\n";
            }
        }
    }
    fclose($handle);

    echo "Results from first 1000 lines:\n";
    echo "  Valid items: $count\n";
    echo "  Invalid JSON: $invalid\n";
    echo "  Empty lines: $empty\n";
    echo "  Total lines checked: $line_num\n";

    if ($count === 0) {
        echo "\nCRITICAL: No valid JSON items found in the first 1000 lines!\n";
        echo "The combined JSONL file appears to be corrupted or empty.\n";
    }
} else {
    echo "ERROR: Cannot open file for counting!\n";
}

echo "\n=== END DEBUG ===\n";
?>