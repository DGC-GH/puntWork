<?php
/**
 * Command-line API verification script
 * Run with: php verify-api-cli.php
 */

echo "=== puntWork API Verification (CLI) ===\n\n";

// Configuration
$api_key = 'etlBBlm0DdUftcafHbbkrof0EOnQSyZg';
$site_url = 'https://belgiumjobs.work';

echo "Configuration:\n";
echo "- Site URL: $site_url\n";
echo "- API Key: " . substr($api_key, 0, 10) . "...\n\n";

function testEndpoint($url, $method = 'GET', $data = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    if ($method === 'POST' && $data) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [$response, $http_code, $error];
}

// Test 1: WordPress REST API
echo "1. Testing WordPress REST API...\n";
list($response, $code, $error) = testEndpoint($site_url . '/wp-json/');
if ($code === 200 && strpos($response, 'routes') !== false) {
    echo "✅ WordPress REST API is working (HTTP $code)\n";
} else {
    echo "❌ WordPress REST API failed (HTTP $code)\n";
    if ($error) echo "   Error: $error\n";
}
echo "\n";

// Test 2: puntWork namespace
echo "2. Testing puntWork plugin activation...\n";
list($response, $code, $error) = testEndpoint($site_url . '/wp-json/puntwork/v1/import-status?api_key=invalid');
if ($code !== 404) {
    echo "✅ puntWork plugin appears activated (HTTP $code)\n";
} else {
    echo "❌ puntWork plugin not activated or namespace not found (HTTP 404)\n";
}
echo "\n";

// Test 3: API Key authentication
echo "3. Testing API key authentication...\n";
list($response, $code, $error) = testEndpoint($site_url . '/wp-json/puntwork/v1/import-status?api_key=' . $api_key);
if ($code === 200) {
    echo "✅ API key authentication successful (HTTP $code)\n";
    $data = json_decode($response, true);
    if (isset($data['success']) && $data['success']) {
        echo "   Response indicates success\n";
    }
} elseif ($code === 401) {
    echo "❌ API key rejected - authentication failed (HTTP 401)\n";
    echo "   Check if API key is configured in WordPress options\n";
} elseif ($code === 500) {
    echo "❌ Server error (HTTP 500) - check plugin code and logs\n";
} else {
    echo "⚠️ Unexpected response (HTTP $code)\n";
    if ($error) echo "   Error: $error\n";
}
echo "\n";

// Test 4: Trigger import
echo "4. Testing trigger import endpoint...\n";
$trigger_data = json_encode(['api_key' => $api_key, 'test_mode' => true]);
list($response, $code, $error) = testEndpoint($site_url . '/wp-json/puntwork/v1/trigger-import', 'POST', $trigger_data);
if ($code === 200) {
    echo "✅ Trigger import successful (HTTP $code)\n";
    $data = json_decode($response, true);
    if (isset($data['success']) && $data['success']) {
        echo "   Import triggered successfully\n";
    }
} elseif ($code === 401) {
    echo "❌ API key rejected for trigger import (HTTP 401)\n";
} elseif ($code === 500) {
    echo "❌ Server error on trigger import (HTTP 500)\n";
} else {
    echo "⚠️ Unexpected response (HTTP $code)\n";
    if ($error) echo "   Error: $error\n";
}
echo "\n";

echo "=== Summary ===\n";
echo "If all tests show ✅, your API is ready!\n";
echo "If you see ❌, check the troubleshooting steps in docs/API-CONFIGURATION.md\n\n";

echo "Manual test commands:\n";
echo "curl -X GET \"$site_url/wp-json/puntwork/v1/import-status?api_key=$api_key\" -H \"Content-Type: application/json\"\n";
echo "curl -X POST \"$site_url/wp-json/puntwork/v1/trigger-import\" -H \"Content-Type: application/json\" -d '{\"api_key\":\"$api_key\",\"test_mode\":true}'\n";
?>