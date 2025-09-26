<?php
/**
 * Live API Test Script for puntWork
 *
 * Tests the actual REST API endpoints on the live site
 * Run with: php tests/api-live-test.php
 */

// Load environment variables from .env file
function loadEnv($path) {
    if (!file_exists($path)) {
        return false;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
    return true;
}

// Load .env file
$envPath = __DIR__ . '/../.env';
if (!loadEnv($envPath)) {
    die("Error: Could not load .env file from: $envPath\n");
}

// Configuration from environment
$config = [
    'api_key' => getenv('PUNTWORK_API_KEY') ?: 'etlBBlm0DdUftcafHbbkrof0EOnQSyZg',
    'base_url' => getenv('WP_SITEURL') ? rtrim(getenv('WP_SITEURL'), '/') . '/wp-json/puntwork/v1' : 'https://belgiumjobs.work/wp-json/puntwork/v1',
    'timeout' => 30
];

echo "=== puntWork Live API Test ===\n";
echo "Base URL: {$config['base_url']}\n";
echo "API Key: " . substr($config['api_key'], 0, 10) . "...\n";
echo "Loaded from .env: " . (file_exists($envPath) ? "YES" : "NO") . "\n\n";

/**
 * Make HTTP request using curl
 */
function makeRequest($url, $method = 'GET', $data = null, $headers = []) {
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For testing
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? json_encode($data) : $data);
        }
    }

    $defaultHeaders = ['Content-Type: application/json'];
    if (!empty($headers)) {
        $defaultHeaders = array_merge($defaultHeaders, $headers);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $defaultHeaders);

    // Get response and info
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'response' => $response,
        'http_code' => $http_code,
        'error' => $error
    ];
}

/**
 * Pretty print JSON response
 */
function printResponse($result, $test_name) {
    echo "Test: $test_name\n";
    echo "HTTP Code: {$result['http_code']}\n";

    if ($result['error']) {
        echo "Error: {$result['error']}\n";
        echo "Status: ❌ FAILED\n\n";
        return false;
    }

    $json = json_decode($result['response'], true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo "Response: " . json_encode($json, JSON_PRETTY_PRINT) . "\n";
        $success = ($result['http_code'] >= 200 && $result['http_code'] < 300);
    } else {
        echo "Response: {$result['response']}\n";
        $success = false;
    }

    echo "Status: " . ($success ? "✅ PASSED" : "❌ FAILED") . "\n\n";
    return $success;
}

// Test 1: Import Status with valid API key
echo "1. Testing Import Status (Valid API Key)\n";
$url = $config['base_url'] . '/import-status?api_key=' . $config['api_key'];
$result = makeRequest($url);
$status_test_passed = printResponse($result, "Import Status - Valid Key");

// Test 2: Import Status with invalid API key
echo "2. Testing Import Status (Invalid API Key)\n";
$url = $config['base_url'] . '/import-status?api_key=invalid_key_12345';
$result = makeRequest($url);
printResponse($result, "Import Status - Invalid Key");

// Test 3: Import Status without API key
echo "3. Testing Import Status (No API Key)\n";
$url = $config['base_url'] . '/import-status';
$result = makeRequest($url);
printResponse($result, "Import Status - No Key");

// Test 4: Trigger Import in test mode
echo "4. Testing Trigger Import (Test Mode)\n";
$url = $config['base_url'] . '/trigger-import';
$data = [
    'api_key' => $config['api_key'],
    'test_mode' => true
];
$result = makeRequest($url, 'POST', $data);
$trigger_test_passed = printResponse($result, "Trigger Import - Test Mode");

// Test 5: Trigger Import with force
echo "5. Testing Trigger Import (Force Mode)\n";
$url = $config['base_url'] . '/trigger-import';
$data = [
    'api_key' => $config['api_key'],
    'force' => true,
    'test_mode' => true
];
$result = makeRequest($url, 'POST', $data);
printResponse($result, "Trigger Import - Force Mode");

// Test 6: Trigger Import with invalid API key
echo "6. Testing Trigger Import (Invalid API Key)\n";
$url = $config['base_url'] . '/trigger-import';
$data = [
    'api_key' => 'invalid_key_12345',
    'test_mode' => true
];
$result = makeRequest($url, 'POST', $data);
printResponse($result, "Trigger Import - Invalid Key");

// Test 7: Check if plugin is active by testing a non-existent endpoint
echo "7. Testing Plugin Status (Non-existent endpoint)\n";
$url = $config['base_url'] . '/non-existent-endpoint?api_key=' . $config['api_key'];
$result = makeRequest($url);
$plugin_active = ($result['http_code'] !== 404); // If 404, plugin might not be loaded
printResponse($result, "Plugin Status Check");

// Summary
echo "=== Test Summary ===\n";
echo "Import Status Test: " . ($status_test_passed ? "PASSED" : "FAILED") . "\n";
echo "Trigger Import Test: " . ($trigger_test_passed ? "PASSED" : "FAILED") . "\n";
echo "Plugin Appears Active: " . ($plugin_active ? "YES" : "NO") . "\n";

if (!$status_test_passed || !$trigger_test_passed) {
    echo "\n⚠️  Some tests failed. Possible issues:\n";
    echo "   - Plugin not activated on live site\n";
    echo "   - API key not set in WordPress options\n";
    echo "   - Server configuration issues\n";
    echo "   - PHP errors in the plugin code\n";
}

// Generate curl commands for manual testing
echo "\n=== Manual Test Commands ===\n";
echo "# Test import status:\n";
echo "curl -X GET \"{$config['base_url']}/import-status?api_key={$config['api_key']}\" -H \"Content-Type: application/json\"\n\n";

echo "# Test trigger import (test mode):\n";
echo "curl -X POST \"{$config['base_url']}/trigger-import\" -H \"Content-Type: application/json\" -d '{\"api_key\":\"{$config['api_key']}\",\"test_mode\":true}'\n\n";

echo "# Test trigger import (force mode):\n";
echo "curl -X POST \"{$config['base_url']}/trigger-import\" -H \"Content-Type: application/json\" -d '{\"api_key\":\"{$config['api_key']}\",\"force\":true}'\n\n";

echo "# Check WordPress REST API:\n";
echo "curl -X GET \"https://belgiumjobs.work/wp-json/\" -H \"Content-Type: application/json\"\n";