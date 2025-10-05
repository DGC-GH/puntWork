<?php
require_once 'includes/utilities/utility-helpers.php';

$json_path = 'feeds/combined-jobs.jsonl';
echo 'File exists: ' . (file_exists($json_path) ? 'yes' : 'no') . PHP_EOL;
echo 'File readable: ' . (is_readable($json_path) ? 'yes' : 'no') . PHP_EOL;
if (file_exists($json_path)) {
    echo 'File size: ' . filesize($json_path) . ' bytes' . PHP_EOL;
    echo 'get_json_item_count: ' . get_json_item_count($json_path) . PHP_EOL;
}
?>