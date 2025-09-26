<?php
/**
 * Quick API Trigger Test with Polling
 */

class QuickAPITest {
    private $baseUrl = 'https://belgiumjobs.work/wp-json/puntwork/v1';
    private $apiKey = 'etlBBlm0DdUftcafHbbkrof0EOnQSyZg';

    public function runTest() {
        echo "=== Quick API Trigger Test ===\n";

        // Check if import is already running
        $status = $this->getImportStatus();
        if ($status['is_running']) {
            echo "Import already running, waiting for completion...\n";
            $result = $this->pollImportCompletion(300);
            if (!$result['success']) {
                echo "Failed to wait for existing import: " . ($result['reason'] ?? 'unknown') . "\n";
                return;
            }
        }

        // Trigger import in test mode
        echo "Triggering import in test mode...\n";
        $triggerResult = $this->triggerImport(true);

        if ($triggerResult['success']) {
            echo "Import triggered successfully, polling for completion...\n";
            $pollResult = $this->pollImportCompletion(900); // 15 minutes max

            if ($pollResult['success']) {
                echo "✅ Test PASSED: Import completed successfully in {$pollResult['elapsed_time']} seconds\n";
            } else {
                echo "❌ Test FAILED: Import did not complete: " . ($pollResult['reason'] ?? 'unknown') . "\n";
            }
        } else {
            echo "❌ Test FAILED: Could not trigger import\n";
        }
    }

    private function getImportStatus() {
        $result = $this->makeRequest('/import-status?api_key=' . $this->apiKey, 'GET');
        if ($result['http_code'] === 200) {
            $response = json_decode($result['response'], true);
            if ($response && isset($response['status'])) {
                return $response['status'];
            }
        }
        return ['is_running' => false];
    }

    private function triggerImport($testMode = false) {
        $data = [
            'api_key' => $this->apiKey,
            'test_mode' => $testMode
        ];

        $result = $this->makeRequest('/trigger-import', 'POST', $data);

        echo "Trigger result: HTTP {$result['http_code']}, Response: {$result['response']}\n";

        if ($result['http_code'] === 200) {
            $response = json_decode($result['response'], true);
            return [
                'success' => $response && isset($response['success']) && $response['success'],
                'response' => $response
            ];
        }

        return ['success' => false, 'http_code' => $result['http_code']];
    }

    private function pollImportCompletion($maxWaitSeconds = 900) {
        $startTime = time();
        $pollInterval = 5;

        while (time() - $startTime < $maxWaitSeconds) {
            $status = $this->getImportStatus();

            if (isset($status['complete']) && $status['complete']) {
                return [
                    'success' => true,
                    'elapsed_time' => time() - $startTime,
                    'final_status' => $status
                ];
            }

            if (!isset($status['is_running']) || !$status['is_running']) {
                return [
                    'success' => false,
                    'elapsed_time' => time() - $startTime,
                    'reason' => 'import_not_running',
                    'final_status' => $status
                ];
            }

            sleep($pollInterval);
        }

        return [
            'success' => false,
            'elapsed_time' => time() - $startTime,
            'reason' => 'timeout'
        ];
    }

    private function makeRequest($endpoint, $method = 'GET', $data = null) {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        return [
            'response' => $response,
            'http_code' => $httpCode,
            'error' => $error
        ];
    }
}

// Run the test
$test = new QuickAPITest();
$test->runTest();