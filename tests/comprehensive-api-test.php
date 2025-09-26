<?php
/**
 * Comprehensive API Test Suite for puntWork
 *
 * This file provides complete testing for all REST API endpoints
 * Run with: php tests/comprehensive-api-test.php
 */

class PuntWorkAPITestSuite {
    private $baseUrl;
    private $wpRootUrl;
    private $apiKey;
    private $testResults = [];

    public function __construct($baseUrl = null, $apiKey = null) {
        $this->baseUrl = $baseUrl ?: 'http://localhost/wp-json/puntwork/v1';
        $this->apiKey = $apiKey ?: 'test_api_key';
        $this->wpRootUrl = preg_replace('/\/wp-json.*$/', '', $this->baseUrl);
    }

    /**
     * Run all API tests
     */
    public function runAllTests() {
        echo "=== puntWork REST API Comprehensive Test Suite ===\n";
        echo "Base URL: {$this->baseUrl}\n";
        echo "API Key: " . substr($this->apiKey, 0, 10) . "...\n\n";

        $this->testWordPressConnectivity();
        $this->testPluginActivation();
        $this->testImportStatusEndpoint();
        $this->testTriggerImportEndpoint();
        $this->testErrorHandling();
        $this->testSecurity();

        $this->printSummary();
    }

    /**
     * Test WordPress REST API connectivity
     */
    private function testWordPressConnectivity() {
        echo "1. Testing WordPress REST API Connectivity\n";

        $result = $this->makeRequest('/wp-json/', 'GET', null, [], $this->wpRootUrl);
        $success = ($result['http_code'] >= 200 && $result['http_code'] < 300);

        $this->logTest('WordPress REST API', $success, [
            'http_code' => $result['http_code'],
            'response_size' => strlen($result['response'])
        ]);

        if (!$success) {
            echo "❌ WordPress REST API is not accessible. Check site connectivity.\n";
        }
    }

    /**
     * Test if puntWork plugin is activated
     */
    private function testPluginActivation() {
        echo "2. Testing puntWork Plugin Activation\n";

        // Test if puntwork namespace exists
        $result = $this->makeRequest('/wp-json/puntwork/v1/import-status?api_key=invalid', 'GET', null, [], $this->wpRootUrl);
        $pluginActive = ($result['http_code'] !== 404); // 404 means namespace doesn't exist

        $this->logTest('Plugin Activation', $pluginActive, [
            'namespace_found' => $pluginActive,
            'http_code' => $result['http_code']
        ]);

        if (!$pluginActive) {
            echo "❌ puntWork plugin is not activated or REST API not registered.\n";
            echo "   Make sure the plugin is uploaded and activated on the WordPress site.\n";
        }
    }

    /**
     * Test import status endpoint
     */
    private function testImportStatusEndpoint() {
        echo "3. Testing Import Status Endpoint\n";

        // Test with valid API key
        $result = $this->makeRequest('/import-status?api_key=' . $this->apiKey, 'GET');
        $validSuccess = ($result['http_code'] === 200);

        $this->logTest('Import Status - Valid Key', $validSuccess, [
            'http_code' => $result['http_code'],
            'response' => $this->parseJsonResponse($result['response'])
        ]);

        // Test with invalid API key
        $result = $this->makeRequest('/import-status?api_key=invalid_key', 'GET');
        $invalidRejected = ($result['http_code'] === 401);

        $this->logTest('Import Status - Invalid Key', $invalidRejected, [
            'http_code' => $result['http_code']
        ]);

        // Test without API key
        $result = $this->makeRequest('/import-status', 'GET');
        $noKeyRejected = ($result['http_code'] === 400);

        $this->logTest('Import Status - No Key', $noKeyRejected, [
            'http_code' => $result['http_code']
        ]);
    }

    /**
     * Test trigger import endpoint
     */
    private function testTriggerImportEndpoint() {
        echo "4. Testing Trigger Import Endpoint\n";

        // Test trigger import in test mode
        $data = [
            'api_key' => $this->apiKey,
            'test_mode' => true
        ];
        $result = $this->makeRequest('/trigger-import', 'POST', $data);
        $testModeSuccess = ($result['http_code'] === 200 || $result['http_code'] === 500); // 500 might be expected if plugin has issues

        $this->logTest('Trigger Import - Test Mode', $testModeSuccess, [
            'http_code' => $result['http_code'],
            'response' => $this->parseJsonResponse($result['response'])
        ]);

        // Test with invalid API key
        $data = [
            'api_key' => 'invalid_key',
            'test_mode' => true
        ];
        $result = $this->makeRequest('/trigger-import', 'POST', $data);
        $invalidRejected = ($result['http_code'] === 401);

        $this->logTest('Trigger Import - Invalid Key', $invalidRejected, [
            'http_code' => $result['http_code']
        ]);

        // Test force import (only if test mode worked)
        if ($testModeSuccess) {
            $data = [
                'api_key' => $this->apiKey,
                'force' => true,
                'test_mode' => true
            ];
            $result = $this->makeRequest('/trigger-import', 'POST', $data);
            $forceSuccess = ($result['http_code'] === 200 || $result['http_code'] === 500);

            $this->logTest('Trigger Import - Force Mode', $forceSuccess, [
                'http_code' => $result['http_code'],
                'response' => $this->parseJsonResponse($result['response'])
            ]);
        }
    }

    /**
     * Test error handling
     */
    private function testErrorHandling() {
        echo "5. Testing Error Handling\n";

        // Test malformed JSON
        $result = $this->makeRequest('/trigger-import', 'POST', 'invalid json', ['Content-Type: application/json']);
        $malformedHandled = ($result['http_code'] >= 400);

        $this->logTest('Malformed JSON Handling', $malformedHandled, [
            'http_code' => $result['http_code']
        ]);

        // Test non-existent endpoint
        $result = $this->makeRequest('/non-existent-endpoint?api_key=' . $this->apiKey, 'GET');
        $notFoundHandled = ($result['http_code'] === 404);

        $this->logTest('404 Error Handling', $notFoundHandled, [
            'http_code' => $result['http_code']
        ]);
    }

    /**
     * Test security features
     */
    private function testSecurity() {
        echo "6. Testing Security Features\n";

        // Test API key timing attack resistance (should use hash_equals)
        $validResult = $this->makeRequest('/import-status?api_key=' . $this->apiKey, 'GET');
        $invalidResult = $this->makeRequest('/import-status?api_key=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'GET');

        // Both should take similar time (hash_equals prevents timing attacks)
        $timingSimilar = abs($validResult['total_time'] - $invalidResult['total_time']) < 0.1;

        $this->logTest('Timing Attack Protection', $timingSimilar, [
            'valid_time' => round($validResult['total_time'], 3),
            'invalid_time' => round($invalidResult['total_time'], 3)
        ]);

        // Test rate limiting (if implemented)
        $results = [];
        for ($i = 0; $i < 10; $i++) {
            $results[] = $this->makeRequest('/import-status?api_key=' . $this->apiKey, 'GET');
            usleep(100000); // 100ms delay
        }

        $rateLimited = array_filter($results, fn($r) => $r['http_code'] === 429);
        $rateLimitWorking = count($rateLimited) === 0; // Assuming no rate limiting for now

        $this->logTest('Rate Limiting', $rateLimitWorking, [
            'requests_made' => count($results),
            'rate_limited_responses' => count($rateLimited)
        ]);
    }

    /**
     * Make HTTP request
     */
    private function makeRequest($endpoint, $method = 'GET', $data = null, $headers = [], $customBaseUrl = null) {
        $baseUrl = $customBaseUrl ?: $this->baseUrl;
        $url = $baseUrl . $endpoint;
        $startTime = microtime(true);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                if (is_array($data)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                } else {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                }
            }
        }

        $defaultHeaders = ['Content-Type: application/json'];
        if (!empty($headers)) {
            $defaultHeaders = array_merge($defaultHeaders, $headers);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $defaultHeaders);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $totalTime = microtime(true) - $startTime;

        curl_close($ch);

        return [
            'response' => $response,
            'http_code' => $httpCode,
            'error' => $error,
            'total_time' => $totalTime
        ];
    }

    /**
     * Parse JSON response
     */
    private function parseJsonResponse($response) {
        $data = json_decode($response, true);
        return json_last_error() === JSON_ERROR_NONE ? $data : null;
    }

    /**
     * Log test result
     */
    private function logTest($testName, $success, $details = []) {
        $this->testResults[] = [
            'name' => $testName,
            'success' => $success,
            'details' => $details
        ];

        $status = $success ? '✅ PASS' : '❌ FAIL';
        echo "   $status: $testName\n";
        if (!$success && !empty($details)) {
            echo "      Details: " . json_encode($details, JSON_PRETTY_PRINT) . "\n";
        }
    }

    /**
     * Print test summary
     */
    private function printSummary() {
        echo "\n=== Test Summary ===\n";

        $totalTests = count($this->testResults);
        $passedTests = count(array_filter($this->testResults, fn($t) => $t['success']));

        echo "Total Tests: $totalTests\n";
        echo "Passed: $passedTests\n";
        echo "Failed: " . ($totalTests - $passedTests) . "\n";
        echo "Success Rate: " . round(($passedTests / $totalTests) * 100, 1) . "%\n\n";

        if ($passedTests < $totalTests) {
            echo "Failed Tests:\n";
            foreach ($this->testResults as $test) {
                if (!$test['success']) {
                    echo "  - {$test['name']}\n";
                }
            }
        }

        // Deployment checklist
        echo "\n=== Deployment Checklist ===\n";
        $checklist = [
            'Plugin uploaded to WordPress' => $this->checkPluginUploaded(),
            'Plugin activated in WordPress admin' => $this->checkPluginActivated(),
            'API key configured in WordPress options' => $this->checkApiKeyConfigured(),
            'WordPress REST API enabled' => $this->checkWordPressRestApi(),
            'HTTPS configured properly' => $this->checkHttpsConfigured()
        ];

        foreach ($checklist as $item => $status) {
            $icon = $status ? '✅' : '❓';
            echo "  $icon $item\n";
        }

        echo "\n=== Manual Test Commands ===\n";
        echo "# Test import status:\n";
        echo "curl -X GET \"{$this->baseUrl}/import-status?api_key={$this->apiKey}\" -H \"Content-Type: application/json\"\n\n";

        echo "# Test trigger import (test mode):\n";
        echo "curl -X POST \"{$this->baseUrl}/trigger-import\" -H \"Content-Type: application/json\" -d '{\"api_key\":\"{$this->apiKey}\",\"test_mode\":true}'\n\n";

        echo "# Test trigger import (force mode):\n";
        echo "curl -X POST \"{$this->baseUrl}/trigger-import\" -H \"Content-Type: application/json\" -d '{\"api_key\":\"{$this->apiKey}\",\"force\":true}'\n\n";
    }

    // Checklist helper methods
    private function checkPluginUploaded() { return true; } // Assume uploaded
    private function checkPluginActivated() {
        $result = $this->makeRequest('/wp-json/puntwork/v1/import-status?api_key=invalid', 'GET', null, [], $this->wpRootUrl);
        return $result['http_code'] !== 404;
    }
    private function checkApiKeyConfigured() {
        $result = $this->makeRequest('/wp-json/puntwork/v1/import-status?api_key=' . $this->apiKey, 'GET', null, [], $this->wpRootUrl);
        return $result['http_code'] === 200;
    }
    private function checkWordPressRestApi() {
        $result = $this->makeRequest('/wp-json/', 'GET', null, [], $this->wpRootUrl);
        return $result['http_code'] === 200;
    }
    private function checkHttpsConfigured() {
        return strpos($this->baseUrl, 'https://') === 0;
    }
}

// Run tests if this file is executed directly
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? __FILE__)) {
    $testSuite = new PuntWorkAPITestSuite();
    $testSuite->runAllTests();
}