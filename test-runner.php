<?php
/**
 * Test runner script for puntWork
 * Run with: php test-runner.php
 */

// Define test environment
define('PUNTWORK_TESTING', true);

// Set up basic WordPress test environment paths
$_tests_dir = getenv('WP_TESTS_DIR');
if (!$_tests_dir) {
    $_tests_dir = '/tmp/wordpress-tests-lib';
}

// Check if WordPress test library exists
if (!file_exists($_tests_dir . '/includes/bootstrap.php')) {
    echo "WordPress test library not found at {$_tests_dir}\n";
    echo "Please install it with: git clone https://github.com/WordPress/wordpress-develop.git /tmp/wordpress-tests-lib\n";
    echo "Or set WP_TESTS_DIR environment variable to the correct path.\n";
    exit(1);
}

// Load test bootstrap
require_once __DIR__ . '/tests/bootstrap.php';

// Run tests
echo "Running puntWork test suite...\n\n";

$result = null;
try {
    // Use PHPUnit to run tests
    $command = 'cd ' . escapeshellarg(dirname(__DIR__)) . ' && phpunit';
    $output = shell_exec($command);
    echo $output;
} catch (Exception $e) {
    echo "Error running tests: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nTest run complete.\n";