<?php
// Debug cURL connectivity - test POST with verbose logging
$url = 'https://belgiumjobs.work/wp-json/puntwork/v1/trigger-import';
$data = json_encode([
    'api_key' => 'etlBBlm0DdUftcafHbbkrof0EOnQSyZg',
    'test_mode' => true
]);

echo "Testing POST request with verbose logging:\n";
echo "URL: $url\n";
echo "Data: $data\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_VERBOSE, true);
curl_setopt($ch, CURLOPT_STDERR, fopen('php://temp', 'rw+'));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
$errno = curl_errno($ch);

// Get verbose output
$stderr = curl_getinfo($ch, CURLOPT_STDERR);
if ($stderr) {
    rewind($stderr);
    $verbose = stream_get_contents($stderr);
} else {
    $verbose = "No verbose output available";
}

echo "HTTP Code: $httpCode\n";
echo "cURL Error: $error\n";
echo "cURL Errno: $errno\n";
echo "Response length: " . strlen($response) . "\n";
echo "Verbose output:\n$verbose\n";
echo "Response preview: " . substr($response, 0, 200) . "...\n";

curl_close($ch);
?>