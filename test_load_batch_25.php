<?php
require_once 'includes/batch/batch-loading.php';

$json_path = 'feeds/combined-jobs.jsonl';
$result = load_json_batch($json_path, 0, 25);
echo 'Items loaded: ' . count($result['items']) . PHP_EOL;
echo 'Lines read: ' . $result['lines_read'] . PHP_EOL;

if (count($result['items']) == 0) {
    echo 'No items loaded - checking first few lines manually...' . PHP_EOL;
    $handle = fopen($json_path, 'r');
    if ($handle) {
        for ($i = 0; $i < 5; $i++) {
            $line = fgets($handle);
            if ($line === false) break;
            $line = trim($line);
            echo 'Line ' . ($i+1) . ': ' . substr($line, 0, 100) . (strlen($line) > 100 ? '...' : '') . PHP_EOL;
            $item = json_decode($line, true);
            echo '  Valid JSON: ' . ($item !== null ? 'yes' : 'no') . PHP_EOL;
            if ($item !== null && isset($item['guid'])) {
                echo '  GUID: ' . $item['guid'] . PHP_EOL;
            }
        }
        fclose($handle);
    }
} else {
    echo 'First item GUID: ' . ($result['items'][0]['guid'] ?? 'MISSING') . PHP_EOL;
}
?>