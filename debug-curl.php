<?php
// Debug cURL connectivity - test both GET and POST
$baseUrl = 'https://belgiumjobs.work/wp-json/puntwork/v1';
$apiKey = 'etlBBlm0DdUftcafHbbkrof0EOnQSyZg';

echo "Testing cURL connectivity\n\n";

// Test GET request first
echo "1. Testing GET request:\n";
$url = $baseUrl . '/import-status?api_key=' . $apiKey;
echo "URL: $url\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
$errno = curl_errno($ch);

echo "HTTP Code: $httpCode\n";
echo "cURL Error: $error\n";
echo "cURL Errno: $errno\n";
echo "Response length: " . strlen($response) . "\n\n";

curl_close($ch);

// Test POST request
echo "2. Testing POST request:\n";
$url = $baseUrl . '/trigger-import';
$data = json_encode([
    'api_key' => $apiKey,
    'test_mode' => true
]);
echo "URL: $url\n";
echo "Data: $data\n";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Shorter timeout to see if it responds
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
$errno = curl_errno($ch);

echo "HTTP Code: $httpCode\n";
echo "cURL Error: $error\n";
echo "cURL Errno: $errno\n";
echo "Response length: " . strlen($response) . "\n";
echo "Response preview: " . substr($response, 0, 200) . "...\n";

curl_close($ch);
?>