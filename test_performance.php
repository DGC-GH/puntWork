<?php
// Define ABSPATH for WordPress compatibility
if (! defined('ABSPATH') ) {
    define('ABSPATH', __DIR__ . '/');
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Starting test...\n";

// Load required classes first
require_once __DIR__ . '/includes/utilities/PerformanceMonitor.php';
require_once __DIR__ . '/includes/utilities/DatabasePerformanceMonitor.php';
require_once __DIR__ . '/includes/utilities/MemoryManager.php';
require_once __DIR__ . '/includes/utilities/CircuitBreaker.php';
require_once __DIR__ . '/includes/utilities/CacheManager.php';
require_once __DIR__ . '/includes/utilities/EnhancedCacheManager.php';
require_once __DIR__ . '/includes/utilities/AdvancedMemoryManager.php';
echo "Required classes loaded\n";

require_once __DIR__ . '/includes/utilities/performance-functions.php';
echo "Performance functions loaded successfully\n";

if (! function_exists('start_performance_monitoring') ) {
    echo "start_performance_monitoring function not found\n";
    exit(1);
}

$id = start_performance_monitoring('test');
echo 'Performance monitoring started with ID: ' . $id . "\n";

$data = end_performance_monitoring($id);
echo "Performance monitoring ended\n";
echo 'Duration: ' . ( $data['duration'] ?? 'N/A' ) . " seconds\n";
echo "Test completed successfully\n";
