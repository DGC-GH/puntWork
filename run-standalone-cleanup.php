<?php
/**
 * Web-accessible cleanup runner
 * This script can be called via AJAX to run the standalone cleanup
 */

// Security check - only allow specific access
if (!defined('ABSPATH')) {
    // Direct access protection
    if (!isset($_GET['token']) || $_GET['token'] !== 'puntwork_cleanup_2024') {
        http_response_code(403);
        die('Access denied');
    }
}

// Include the standalone cleanup class
require_once __DIR__ . '/standalone-cleanup.php';

// Run cleanup and return JSON response
try {
    $cleanup = new StandaloneCleanup();
    ob_start();
    $cleanup->run_cleanup();
    $output = ob_get_clean();

    // Return success response
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Standalone cleanup completed',
        'output' => $output,
        'timestamp' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Cleanup failed: ' . $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>