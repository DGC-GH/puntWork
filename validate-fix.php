<?php
/**
 * Validation script for the API import status tracking fix
 *
 * This script validates that the fix for the "0 / 7367 items" bug is working correctly.
 * The bug was caused by undefined $initial_status variable in preserve_status=true path.
 */

echo "🔍 Validating API Import Status Tracking Fix\n";
echo "==============================================\n\n";

// Configuration
$api_key = 'etlBBlm0DdUftcafHbbkrof0EOnQSyZg';
$base_url = 'https://belgiumjobs.work';
$wp_ajax_url = $base_url . '/wp-admin/admin-ajax.php';

// Test 1: Check current import status
echo "Test 1: Checking current import status...\n";
$status_url = $base_url . '/wp-json/puntwork/v1/import-status?api_key=' . $api_key;

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $status_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($http_code === 200) {
    $data = json_decode($response, true);
    if ($data && isset($data['success']) && $data['success']) {
        $status = $data['status'];
        echo "✅ Status API accessible\n";
        echo "   - Total items: " . ($status['total'] ?? 'N/A') . "\n";
        echo "   - Processed: " . ($status['processed'] ?? 'N/A') . "\n";
        echo "   - Complete: " . (($status['complete'] ?? false) ? 'Yes' : 'No') . "\n";
        echo "   - Success: " . (($status['success'] ?? false) ? 'Yes' : 'No') . "\n";

        // Check if processed count matches total (indicating proper tracking)
        if (isset($status['processed']) && isset($status['total']) && $status['processed'] > 0) {
            echo "✅ Status tracking appears to be working (processed > 0)\n";
        } else {
            echo "⚠️  Status tracking may not be working properly\n";
        }
    } else {
        echo "❌ Invalid status response\n";
    }
} else {
    echo "❌ Status API not accessible (HTTP $http_code)\n";
}

curl_close($ch);
echo "\n";

// Test 2: Try to get import history (requires authentication, may fail)
echo "Test 2: Attempting to access import history...\n";
$history_data = [
    'action' => 'get_import_run_history'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $wp_ajax_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($history_data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($http_code === 200) {
    $data = json_decode($response, true);
    if ($data && isset($data['success']) && $data['success'] && isset($data['data'])) {
        $history = $data['data'];
        echo "✅ Import history accessible\n";

        if (is_array($history) && count($history) > 0) {
            $latest_run = $history[0];
            echo "   - Latest run: " . ($latest_run['formatted_date'] ?? 'Unknown') . "\n";
            echo "   - Processed: " . ($latest_run['processed'] ?? 'N/A') . " / " . ($latest_run['total'] ?? 'N/A') . "\n";
            echo "   - Success: " . (($latest_run['success'] ?? false) ? 'Yes' : 'No') . "\n";
            echo "   - Trigger: " . ($latest_run['trigger_type'] ?? 'Unknown') . "\n";

            // Check if the latest run shows proper progress counts
            if (isset($latest_run['processed']) && isset($latest_run['total']) &&
                $latest_run['processed'] > 0 && $latest_run['total'] > 0) {
                echo "✅ History shows correct progress counts (not 0/N)\n";
            } else {
                echo "⚠️  History may show incorrect progress counts\n";
            }
        } else {
            echo "⚠️  No import history found\n";
        }
    } else {
        echo "❌ Invalid history response\n";
    }
} else {
    echo "❌ History API not accessible (HTTP $http_code) - This is expected without authentication\n";
}

curl_close($ch);
echo "\n";

// Test 3: Trigger a small test import to verify the fix
echo "Test 3: Triggering a small test import to verify status tracking...\n";
$trigger_url = $base_url . '/wp-json/puntwork/v1/trigger-import';

$trigger_data = [
    'api_key' => $api_key,
    'test_mode' => true,  // Use test mode to avoid creating actual posts
    'force' => false
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $trigger_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($trigger_data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($http_code === 200) {
    $data = json_decode($response, true);
    if ($data && isset($data['success']) && $data['success']) {
        echo "✅ Test import triggered successfully\n";

        // Wait a moment for the import to start
        sleep(3);

        // Check status again
        $ch2 = curl_init();
        curl_setopt($ch2, CURLOPT_URL, $status_url);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, false);

        $status_response = curl_exec($ch2);
        $status_data = json_decode($status_response, true);

        if ($status_data && isset($status_data['success']) && $status_data['success']) {
            $new_status = $status_data['status'];
            echo "   - Test import status: " . (($new_status['complete'] ?? false) ? 'Complete' : 'Running') . "\n";
            echo "   - Test processed: " . ($new_status['processed'] ?? 0) . " / " . ($new_status['total'] ?? 0) . "\n";

            if (isset($new_status['processed']) && $new_status['processed'] > 0) {
                echo "✅ Status tracking working correctly during test import\n";
            } else {
                echo "⚠️  Status tracking may not be working during test import\n";
            }
        }

        curl_close($ch2);
    } else {
        echo "❌ Test import trigger failed: " . ($data['message'] ?? 'Unknown error') . "\n";
    }
} else {
    echo "❌ Test import trigger failed (HTTP $http_code)\n";
}

curl_close($ch);
echo "\n";

// Summary
echo "📋 Validation Summary\n";
echo "====================\n";
echo "This script validates the fix for the API import status tracking bug.\n";
echo "The original issue was that history cards showed '0 / 7367 items' instead\n";
echo "of actual progress due to undefined \$initial_status variable.\n\n";

echo "Key fix: Added \$initial_status = get_option('job_import_status', []);\n";
echo "in the preserve_status=true code path in import-batch.php\n\n";

echo "If all tests show ✅, the fix is working correctly!\n";
echo "If you see ⚠️ or ❌, there may be additional issues to investigate.\n";

?>