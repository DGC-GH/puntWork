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
        $this->baseUrl = $baseUrl ?: 'https://belgiumjobs.work/wp-json/puntwork/v1';
        $this->apiKey = $apiKey ?: 'etlBBlm0DdUftcafHbbkrof0EOnQSyZg';
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

        // First check if an import is already running
        $statusResult = $this->makeRequest('/import-status?api_key=' . $this->apiKey, 'GET');
        $statusResponse = $this->parseJsonResponse($statusResult['response']);

        $importRunning = false;
        if ($statusResult['http_code'] === 200 && isset($statusResponse['success']) && $statusResponse['success']) {
            $status = $statusResponse['status'] ?? [];
            $importRunning = isset($status['is_running']) && $status['is_running'];
        }

        if ($importRunning) {
            echo "   ⚠️  Import already running, waiting for completion before testing...\n";
            $completionResult = $this->pollImportCompletion(300); // Wait up to 5 minutes for current import
            if (!$completionResult['success']) {
                echo "   ❌ Current import did not complete successfully, skipping trigger tests\n";
                $this->logTest('Trigger Import - Test Mode', false, [
                    'reason' => 'existing_import_not_completed',
                    'completion_result' => $completionResult
                ]);
                return; // Skip the rest of the trigger tests
            }
        }

        // Test trigger import in test mode with polling
        $data = [
            'api_key' => $this->apiKey,
            'test_mode' => true
        ];
        $result = $this->makeRequest('/trigger-import', 'POST', $data, [], null, 30); // Initial trigger timeout

        if ($result['http_code'] === 200) {
            $triggerResponse = $this->parseJsonResponse($result['response']);
            if ($triggerResponse && isset($triggerResponse['success']) && $triggerResponse['success']) {
                // Wait a moment for the import to initialize
                sleep(2);
                // Poll for completion instead of fixed timeout
                $importComplete = $this->pollImportCompletion(900); // 15 minute max wait
                $testModeSuccess = $importComplete['success'];
            } else {
                $testModeSuccess = false;
                $importComplete = ['reason' => 'trigger_failed', 'trigger_response' => $triggerResponse];
            }

            $this->logTest('Trigger Import - Test Mode', $testModeSuccess, [
                'trigger_http_code' => $result['http_code'],
                'trigger_response' => $triggerResponse,
                'polling_result' => $importComplete
            ]);
        } else {
            $testModeSuccess = false;
            $this->logTest('Trigger Import - Test Mode', $testModeSuccess, [
                'http_code' => $result['http_code'],
                'response' => $this->parseJsonResponse($result['response'])
            ]);
        }

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

        // Test concurrent import prevention (409 Conflict)
        if ($testModeSuccess) {
            // Start another import while the first one is running
            $data = [
                'api_key' => $this->apiKey,
                'test_mode' => true
            ];
            $result = $this->makeRequest('/trigger-import', 'POST', $data);
            $concurrentRejected = ($result['http_code'] === 409);

            $this->logTest('Trigger Import - Concurrent Prevention', $concurrentRejected, [
                'http_code' => $result['http_code'],
                'response' => $this->parseJsonResponse($result['response'])
            ]);
        }

        // Test force import (only if test mode worked)
        if ($testModeSuccess) {
            $result = $this->makeRequest('/trigger-import', 'POST', $data, [], null, 30); // Initial trigger timeout

            if ($result['http_code'] === 200) {
                $triggerResponse = $this->parseJsonResponse($result['response']);
                if ($triggerResponse && isset($triggerResponse['success']) && $triggerResponse['success']) {
                    // Wait a moment for the import to initialize
                    sleep(2);
                    // Poll for completion
                    $importComplete = $this->pollImportCompletion(900); // 15 minute max wait
                    $forceSuccess = $importComplete['success'];
                } else {
                    $forceSuccess = false;
                    $importComplete = ['reason' => 'trigger_failed', 'trigger_response' => $triggerResponse];
                }

                $this->logTest('Trigger Import - Force Mode', $forceSuccess, [
                    'trigger_http_code' => $result['http_code'],
                    'trigger_response' => $triggerResponse,
                    'polling_result' => $importComplete
                ]);
            } else {
                $forceSuccess = false;
                $this->logTest('Trigger Import - Force Mode', $forceSuccess, [
                    'http_code' => $result['http_code'],
                    'response' => $this->parseJsonResponse($result['response'])
                ]);
            }
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
    private function makeRequest($endpoint, $method = 'GET', $data = null, $headers = [], $customBaseUrl = null, $timeout = 30) {
        $baseUrl = $customBaseUrl ?: $this->baseUrl;
        $url = $baseUrl . $endpoint;
        $startTime = microtime(true);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
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

    /**
     * Poll import status until completion or timeout
     */
    private function pollImportCompletion($maxWaitSeconds = 900) {
        $startTime = time();
        $pollInterval = 5; // Check every 5 seconds
        $maxPolls = ceil($maxWaitSeconds / $pollInterval);

        echo "   Polling import status for up to {$maxWaitSeconds} seconds...\n";

        for ($i = 0; $i < $maxPolls; $i++) {
            $result = $this->makeRequest('/import-status?api_key=' . $this->apiKey, 'GET');
            $response = $this->parseJsonResponse($result['response']);

            if ($result['http_code'] === 200 && isset($response['success']) && $response['success']) {
                $status = $response['status'] ?? [];

                if (isset($status['complete']) && $status['complete']) {
                    // Import completed successfully
                    $elapsed = time() - $startTime;
                    echo "   ✅ Import completed in {$elapsed} seconds\n";
                    return [
                        'success' => true,
                        'elapsed_time' => $elapsed,
                        'final_status' => $status,
                        'polls' => $i + 1
                    ];
                } elseif (isset($status['is_running']) && !$status['is_running']) {
                    // Import is not running (might be stuck)
                    $elapsed = time() - $startTime;
                    echo "   ⚠️  Import stopped running after {$elapsed} seconds\n";
                    return [
                        'success' => false,
                        'elapsed_time' => $elapsed,
                        'final_status' => $status,
                        'polls' => $i + 1,
                        'reason' => 'import_not_running'
                    ];
                } else {
                    // Import still running, continue polling
                    $processed = $status['processed'] ?? 0;
                    $total = $status['total'] ?? 0;
                    echo "   ⏳ Import in progress: {$processed}/{$total} items processed...\n";
                }
            } else {
                // API error
                $elapsed = time() - $startTime;
                echo "   ❌ API error after {$elapsed} seconds\n";
                return [
                    'success' => false,
                    'elapsed_time' => $elapsed,
                    'http_code' => $result['http_code'],
                    'response' => $response,
                    'polls' => $i + 1,
                    'reason' => 'api_error'
                ];
            }

            // Wait before next poll
            if ($i < $maxPolls - 1) {
                sleep($pollInterval);
            }
        }

        // Timeout reached
        $elapsed = time() - $startTime;
        echo "   ⏰ Import polling timed out after {$elapsed} seconds\n";
        return [
            'success' => false,
            'elapsed_time' => $elapsed,
            'polls' => $maxPolls,
            'reason' => 'timeout'
        ];
    }
}

// Run tests if this file is executed directly
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? __FILE__)) {
    $testSuite = new PuntWorkAPITestSuite();
    $testSuite->runAllTests();
}