<?php

$file = 'feeds/combined-jobs.jsonl';
$handle = fopen($file, 'r');
$count = 0;

echo "Testing JSONL file GUIDs:\n";

while (($line = fgets($handle)) !== false && $count < 5) {
    $line = trim($line);
    if (!empty($line)) {
        $item = json_decode($line, true);
        if ($item !== null) {
            echo 'Item ' . ($count + 1) . ' GUID: ' . ($item['guid'] ?? 'MISSING') . PHP_EOL;
            echo 'Item ' . ($count + 1) . ' keys: ' . implode(', ', array_keys($item)) . PHP_EOL;
            echo '---' . PHP_EOL;
        } else {
            echo 'Item ' . ($count + 1) . ' JSON decode failed: ' . json_last_error_msg() . PHP_EOL;
        }
        $count++;
    }
}

fclose($handle);
echo "Test completed.\n";