<?php
/**
 * Debug PHP cURL vs Manual Curl for trigger-import endpoint
 */

class CurlDebugTest {
    private $baseUrl = 'https://belgiumjobs.work/wp-json/puntwork/v1';
    private $apiKey = 'etlBBlm0DdUftcafHbbkrof0EOnQSyZg';

    public function runDebug() {
        echo "=== PHP cURL vs Manual Curl Debug Test ===\n\n";

        // Test 1: Compare GET requests (should work)
        echo "1. Testing GET request to import-status:\n";
        $this->testGetRequest();

        echo "\n2. Testing POST request to trigger-import:\n";
        $this->testPostRequest();

        echo "\n3. Testing manual curl command (run this in terminal):\n";
        $this->showManualCurlCommand();
    }

    private function testGetRequest() {
        $url = $this->baseUrl . '/import-status?api_key=' . $this->apiKey;

        // PHP cURL GET
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);

        curl_close($ch);

        echo "PHP cURL GET:\n";
        echo "  HTTP Code: $httpCode\n";
        echo "  Error: $error (errno: $errno)\n";
        echo "  Response length: " . strlen($response) . "\n";
        if ($httpCode == 200) {
            echo "  ✅ SUCCESS\n";
        } else {
            echo "  ❌ FAILED\n";
        }
    }

    private function testPostRequest() {
        $url = $this->baseUrl . '/trigger-import';
        $data = [
            'api_key' => $this->apiKey,
            'test_mode' => true
        ];

        // PHP cURL POST with user agent
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_USERAGENT, 'curl/7.68.0'); // Mimic manual curl

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);

        curl_close($ch);

        echo "PHP cURL POST (with curl user agent):\n";
        echo "  HTTP Code: $httpCode\n";
        echo "  Error: $error (errno: $errno)\n";
        echo "  Response length: " . strlen($response) . "\n";
        if ($httpCode == 200) {
            echo "  ✅ SUCCESS\n";
        } else {
            echo "  ❌ FAILED\n";
        }
    }

    private function showManualCurlCommand() {
        $data = [
            'api_key' => $this->apiKey,
            'test_mode' => true
        ];

        echo "curl -X POST \"" . $this->baseUrl . "/trigger-import\" \\\n";
        echo "  -H \"Content-Type: application/json\" \\\n";
        echo "  -d '" . json_encode($data) . "' \\\n";
        echo "  --connect-timeout 10 \\\n";
        echo "  --max-time 30 \\\n";
        echo "  -v\n";
    }
}

// Run the debug test
$debug = new CurlDebugTest();
$debug->runDebug();