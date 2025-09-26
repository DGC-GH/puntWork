<?php
// Test basic HTTPS connectivity with PHP cURL
echo "Testing basic HTTPS connectivity with PHP cURL\n\n";

$testUrls = [
    'https://httpbin.org/get',
    'https://google.com',
    'https://belgiumjobs.work/wp-json/'
];

foreach ($testUrls as $url) {
    echo "Testing: $url\n";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $errno = curl_errno($ch);

    echo "  HTTP Code: $httpCode\n";
    echo "  Error: $error\n";
    echo "  Errno: $errno\n";
    echo "  Response length: " . strlen($response) . "\n\n";

    curl_close($ch);
}
?>