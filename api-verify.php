<?php
/**
 * puntWork API Configuration Verification Script
 *
 * Upload this file to your WordPress root directory temporarily to verify API setup
 * Access: https://your-site.com/api-verify.php
 * Delete after testing!
 */

echo "<h1>puntWork API Configuration Verification</h1>";
echo "<style>body{font-family:Arial,sans-serif;margin:20px;} .success{color:green;} .error{color:red;} .warning{color:orange;} pre{background:#f5f5f5;padding:10px;border-radius:5px;overflow-x:auto;}</style>";

// Configuration
$api_key = 'etlBBlm0DdUftcafHbbkrof0EOnQSyZg';
$site_url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];

echo "<h2>Configuration</h2>";
echo "<ul>";
echo "<li><strong>Site URL:</strong> $site_url</li>";
echo "<li><strong>API Key:</strong> " . substr($api_key, 0, 10) . "...</li>";
echo "</ul>";

echo "<h2>Tests</h2>";

// Test 1: WordPress REST API
echo "<h3>1. WordPress REST API</h3>";
$wp_api_url = $site_url . '/wp-json/';
$wp_response = @file_get_contents($wp_api_url);
if ($wp_response && strpos($wp_response, 'routes') !== false) {
    echo "<p class='success'>✅ WordPress REST API is working</p>";
    echo "<details><summary>Response Preview</summary><pre>" . substr($wp_response, 0, 500) . "...</pre></details>";
} else {
    echo "<p class='error'>❌ WordPress REST API is not accessible</p>";
    echo "<p><strong>Possible solutions:</strong></p>";
    echo "<ul>";
    echo "<li>Enable pretty permalinks in Settings → Permalinks</li>";
    echo "<li>Check if security plugins are blocking REST API</li>";
    echo "<li>Verify WordPress version (needs 4.7+)</li>";
    echo "</ul>";
}

// Test 2: puntWork Plugin Activation
echo "<h3>2. puntWork Plugin Activation</h3>";
$plugin_check = $site_url . '/wp-json/puntwork/v1/import-status?api_key=invalid';
$plugin_response = @file_get_contents($plugin_check);
$http_response_header = $http_response_header ?? [];

if (strpos($plugin_response, 'rest_no_route') === false) {
    echo "<p class='success'>✅ puntWork plugin appears to be activated</p>";
} else {
    echo "<p class='error'>❌ puntWork plugin is not activated or REST routes not registered</p>";
    echo "<p><strong>Solutions:</strong></p>";
    echo "<ul>";
    echo "<li>Go to Plugins → Installed Plugins</li>";
    echo "<li>Find 'puntWork' and click 'Activate'</li>";
    echo "<li>Check for PHP errors in plugin code</li>";
    echo "</ul>";
}

// Test 3: API Key Configuration
echo "<h3>3. API Key Configuration</h3>";
$api_test_url = $site_url . '/wp-json/puntwork/v1/import-status?api_key=' . $api_key;
$api_response = @file_get_contents($api_test_url);

if ($api_response) {
    $api_data = json_decode($api_response, true);
    if (isset($api_data['success']) && $api_data['success'] === true) {
        echo "<p class='success'>✅ API key is correctly configured</p>";
        echo "<details><summary>Response Details</summary><pre>" . json_encode($api_data, JSON_PRETTY_PRINT) . "</pre></details>";
    } elseif (strpos($api_response, 'Invalid API key') !== false) {
        echo "<p class='error'>❌ API key mismatch</p>";
        echo "<p><strong>Solutions:</strong></p>";
        echo "<ul>";
        echo "<li>Go to WordPress Admin → .work → API</li>";
        echo "<li>Regenerate API key until it matches: <code>$api_key</code></li>";
        echo "<li>Or manually set with: <code>update_option('puntwork_api_key', '$api_key');</code></li>";
        echo "</ul>";
    } elseif (strpos($api_response, 'internal_server_error') !== false) {
        echo "<p class='error'>❌ Server error in plugin code</p>";
        echo "<p>Check WordPress error logs and plugin code for issues.</p>";
    } else {
        echo "<p class='warning'>⚠️ Unexpected API response</p>";
        echo "<details><summary>Raw Response</summary><pre>$api_response</pre></details>";
    }
} else {
    echo "<p class='error'>❌ Cannot connect to API endpoint</p>";
    echo "<p>Check network connectivity and WordPress configuration.</p>";
}

// Test 4: Trigger Import (Test Mode)
echo "<h3>4. Trigger Import (Test Mode)</h3>";
$trigger_data = json_encode([
    'api_key' => $api_key,
    'test_mode' => true
]);

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/json',
        'content' => $trigger_data
    ]
]);

$trigger_response = @file_get_contents($site_url . '/wp-json/puntwork/v1/trigger-import', false, $context);

if ($trigger_response) {
    $trigger_data = json_decode($trigger_response, true);
    if (isset($trigger_data['success']) && $trigger_data['success'] === true) {
        echo "<p class='success'>✅ Trigger import endpoint is working</p>";
        echo "<details><summary>Response Details</summary><pre>" . json_encode($trigger_data, JSON_PRETTY_PRINT) . "</pre></details>";
    } elseif (strpos($trigger_response, 'Invalid API key') !== false) {
        echo "<p class='error'>❌ API key rejected for trigger import</p>";
    } else {
        echo "<p class='warning'>⚠️ Unexpected response from trigger import</p>";
        echo "<details><summary>Raw Response</summary><pre>$trigger_response</pre></details>";
    }
} else {
    echo "<p class='error'>❌ Cannot connect to trigger import endpoint</p>";
}

echo "<hr>";
echo "<h2>Quick Commands</h2>";
echo "<p>Copy and run these commands in your terminal:</p>";
echo "<pre>";
echo "# Test import status\n";
echo "curl -X GET \"$site_url/wp-json/puntwork/v1/import-status?api_key=$api_key\" -H \"Content-Type: application/json\"\n\n";
echo "# Test trigger import (test mode)\n";
echo "curl -X POST \"$site_url/wp-json/puntwork/v1/trigger-import\" -H \"Content-Type: application/json\" -d '{\"api_key\":\"$api_key\",\"test_mode\":true}'\n\n";
echo "# Test trigger import (force mode)\n";
echo "curl -X POST \"$site_url/wp-json/puntwork/v1/trigger-import\" -H \"Content-Type: application/json\" -d '{\"api_key\":\"$api_key\",\"force\":true,\"test_mode\":true}'\n";
echo "</pre>";

echo "<div style='background:#ffeaa7;padding:15px;border-radius:5px;margin:20px 0;'>";
echo "<h3>🔒 Security Reminder</h3>";
echo "<p><strong>Delete this file immediately after testing!</strong> It contains sensitive information.</p>";
echo "</div>";

echo "<p><small>Test completed at: " . date('Y-m-d H:i:s') . "</small></p>";
?>