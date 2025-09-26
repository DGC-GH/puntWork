<?php
// Test POST requests to different endpoints
echo "Testing POST requests\n\n";

$testPosts = [
    [
        'url' => 'https://httpbin.org/post',
        'data' => json_encode(['test' => 'data']),
        'headers' => ['Content-Type: application/json']
    ],
    [
        'url' => 'https://belgiumjobs.work/wp-json/puntwork/v1/trigger-import',
        'data' => json_encode([
            'api_key' => 'etlBBlm0DdUftcafHbbkrof0EOnQSyZg',
            'test_mode' => true
        ]),
        'headers' => ['Content-Type: application/json'],
        'options' => [
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, // Force IPv4
            CURLOPT_USERAGENT => 'curl/7.68.0' // Match manual curl user agent
        ]
    ]
];

foreach ($testPosts as $test) {
    echo "Testing POST to: {$test['url']}\n";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $test['url']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60); // Increase timeout to 60 seconds
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $test['data']);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $test['headers']);

    // Apply additional options if specified
    if (isset($test['options'])) {
        foreach ($test['options'] as $option => $value) {
            curl_setopt($ch, $option, $value);
        }
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $errno = curl_errno($ch);

    echo "  HTTP Code: $httpCode\n";
    echo "  Error: $error\n";
    echo "  Errno: $errno\n";
    echo "  Response length: " . strlen($response) . "\n";
    echo "  Response preview: " . substr($response, 0, 100) . "...\n\n";

    curl_close($ch);
}
?>