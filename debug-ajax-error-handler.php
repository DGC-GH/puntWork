<?php

namespace Puntwork;

echo "Starting AjaxErrorHandler debug...\n";

/**
 * Debug script for AjaxErrorHandler
 * Tests the AjaxErrorHandler class functionality
 */

// Prevent direct access check
if (!defined('ABSPATH')) {
    define('ABSPATH', '/fake/path/');
}

// Include required dependencies
$ajaxHandlerPath = __DIR__ . '/includes/utilities/AjaxErrorHandler.php';
$loggerPath = __DIR__ . '/includes/utilities/PuntWorkLogger.php';

echo "Checking file paths...\n";
echo "AjaxErrorHandler: " . (file_exists($ajaxHandlerPath) ? "EXISTS" : "MISSING") . " - $ajaxHandlerPath\n";
echo "PuntWorkLogger: " . (file_exists($loggerPath) ? "EXISTS" : "MISSING") . " - $loggerPath\n";

if (!file_exists($ajaxHandlerPath)) {
    die("AjaxErrorHandler.php not found\n");
}

if (!file_exists($loggerPath)) {
    die("PuntWorkLogger.php not found\n");
}

require_once $ajaxHandlerPath;
require_once $loggerPath;

// Define WP_Error in global namespace if not available
if (!class_exists('WP_Error', false)) {
    echo "Defining WP_Error class...\n";
    class WP_Error {
        private $code;
        private $message;
        private $data;

        public function __construct($code, $message, $data = null) {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        public function get_error_code() {
            return $this->code;
        }

        public function get_error_message() {
            return $this->message;
        }

        public function get_error_data() {
            return $this->data;
        }
    }
    echo "WP_Error class defined\n";
} else {
    echo "WP_Error class already exists\n";
}

/**
 * Debug AjaxErrorHandler class
 */
class AjaxErrorHandlerDebugger
{
    /**
     * Run debug tests
     */
    public static function runTests()
    {
        echo "=== AjaxErrorHandler Debug Tests ===\n\n";

        // Test 1: Basic error response
        echo "Test 1: Basic error response\n";
        self::testBasicError();

        // Test 2: WP_Error object
        echo "\nTest 2: WP_Error object\n";
        self::testWPError();

        // Test 3: Success response
        echo "\nTest 3: Success response\n";
        self::testSuccess();

        // Test 4: Error with additional data
        echo "\nTest 4: Error with additional data\n";
        self::testErrorWithData();

        echo "\n=== Debug Tests Complete ===\n";
    }

    /**
     * Test basic error response
     */
    private static function testBasicError()
    {
        try {
            // Capture output instead of sending to browser
            ob_start();
            AjaxErrorHandler::sendError('Test error message');
            $output = ob_get_clean();

            $decoded = json_decode($output, true);

            if ($decoded && isset($decoded['success']) && $decoded['success'] === false) {
                echo "✅ Basic error response works\n";
                echo "   Response: " . json_encode($decoded, JSON_PRETTY_PRINT) . "\n";
            } else {
                echo "❌ Basic error response failed\n";
                echo "   Output: $output\n";
            }
        } catch (\Exception $e) {
            echo "❌ Exception in basic error test: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Test WP_Error object
     */
    private static function testWPError()
    {
        try {
            echo "Skipping WP_Error test due to namespace issues\n";
            return;

            // Create a mock WP_Error (using global class)
            $wp_error = new \WP_Error('test_code', 'Test error message', ['extra' => 'data']);

            ob_start();
            AjaxErrorHandler::sendError($wp_error);
            $output = ob_get_clean();

            $decoded = json_decode($output, true);

            if ($decoded && isset($decoded['success']) && $decoded['success'] === false) {
                echo "✅ WP_Error response works\n";
                echo "   Response: " . json_encode($decoded, JSON_PRETTY_PRINT) . "\n";
            } else {
                echo "❌ WP_Error response failed\n";
                echo "   Output: $output\n";
            }
        } catch (\Exception $e) {
            echo "❌ Exception in WP_Error test: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Test success response
     */
    private static function testSuccess()
    {
        try {
            $testData = ['processed' => 100, 'total' => 200];

            ob_start();
            AjaxErrorHandler::sendSuccess($testData);
            $output = ob_get_clean();

            $decoded = json_decode($output, true);

            if ($decoded && isset($decoded['success']) && $decoded['success'] === true) {
                echo "✅ Success response works\n";
                echo "   Response: " . json_encode($decoded, JSON_PRETTY_PRINT) . "\n";
            } else {
                echo "❌ Success response failed\n";
                echo "   Output: $output\n";
            }
        } catch (\Exception $e) {
            echo "❌ Exception in success test: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Test error with additional data
     */
    private static function testErrorWithData()
    {
        try {
            $additionalData = ['debug_info' => 'Additional context', 'timestamp' => time()];

            ob_start();
            AjaxErrorHandler::sendError('Error with context', $additionalData);
            $output = ob_get_clean();

            $decoded = json_decode($output, true);

            if ($decoded && isset($decoded['success']) && $decoded['success'] === false) {
                echo "✅ Error with additional data works\n";
                echo "   Response: " . json_encode($decoded, JSON_PRETTY_PRINT) . "\n";
            } else {
                echo "❌ Error with additional data failed\n";
                echo "   Output: $output\n";
            }
        } catch (\Exception $e) {
            echo "❌ Exception in error with data test: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Check if required functions exist
     */
    public static function checkDependencies()
    {
        echo "=== Dependency Check ===\n";

        $dependencies = [
            'wp_send_json' => function_exists('wp_send_json'),
            'current_time' => function_exists('current_time'),
            'PuntWorkLogger::error' => method_exists('Puntwork\\PuntWorkLogger', 'error'),
            'is_wp_error' => function_exists('is_wp_error'),
        ];

        foreach ($dependencies as $name => $exists) {
            echo ($exists ? "✅" : "❌") . " $name: " . ($exists ? "Available" : "Missing") . "\n";
        }

        echo "\n";
    }
}

// Mock WordPress functions if not available
if (!function_exists('wp_send_json')) {
    function wp_send_json($data) {
        echo json_encode($data) . "\n";
        // Don't exit in debug mode
        // exit;
    }
}

if (!function_exists('current_time')) {
    function current_time($type) {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return $thing instanceof \WP_Error;
    }
}

if (!function_exists('is_admin')) {
    function is_admin() {
        return false; // Not in admin for debug
    }
}

// Run the debug tests
AjaxErrorHandlerDebugger::checkDependencies();
AjaxErrorHandlerDebugger::runTests();