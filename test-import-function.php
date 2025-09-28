<?php

/**
 * Test script to debug import_jobs_from_json function
 * Run this from command line to test the import function directly
 */

namespace Puntwork;

// Define constants for testing
define('ABSPATH', '/fake/path/');
define('WP_DEBUG', true);

// Include the necessary files
require_once __DIR__ . '/includes/import/import-batch.php';

// Test if function exists
echo "Testing import_jobs_from_json function...\n";

if (! function_exists('import_jobs_from_json') ) {
    echo "ERROR: import_jobs_from_json function not found!\n";
    exit(1);
}

echo "SUCCESS: import_jobs_from_json function exists\n";

// Test calling the function with batch mode
echo "Testing function call with is_batch=true, batch_start=0...\n";

try {
    $result = import_jobs_from_json(true, 0);
    echo "Function call completed. Result:\n";
    print_r($result);
} catch ( Exception $e ) {
    echo 'ERROR: Exception thrown: ' . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
} catch ( Error $e ) {
    echo 'ERROR: Fatal error: ' . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "Test completed.\n";
