<?php
/**
 * Debug script to test load_json_batch function directly
 */

define('ABSPATH', '/Users/dg/Documents/GitHub/puntWork/');
define('WP_DEBUG', true);

require_once ABSPATH . 'includes/utilities/utility-helpers.php';
require_once ABSPATH . 'includes/batch/batch-loading.php';

$json_path = ABSPATH . 'feeds/combined-jobs.jsonl';

echo "Testing load_json_batch function...\n";
echo "File exists: " . (file_exists($json_path) ? 'YES' : 'NO') . "\n";
echo "File readable: " . (is_readable($json_path) ? 'YES' : 'NO') . "\n";
echo "File size: " . (file_exists($json_path) ? filesize($json_path) : 'N/A') . " bytes\n";

$result = load_json_batch($json_path, 0, 5);

echo "Result: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";

if (!empty($result['items'])) {
    echo "First item GUID: " . ($result['items'][0]['guid'] ?? 'MISSING') . "\n";
    echo "First item keys: " . implode(', ', array_keys($result['items'][0])) . "\n";
}