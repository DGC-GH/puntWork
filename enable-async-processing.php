<?php
/**
 * Enable Async Processing Script
 * This script enables async processing for PuntWork plugin
 */

// Define WordPress paths - adjust these if your WordPress installation is in a different location
$wordpress_paths = [
    __DIR__ . '/../../../wp-load.php',  // If plugin is in wp-content/plugins/
    __DIR__ . '/../../../../wp-load.php', // If plugin is deeper
    '/var/www/html/wp-load.php', // Common path
    '/usr/local/bin/wp-load.php', // Another common path
];

$wp_load_found = false;
foreach ($wordpress_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $wp_load_found = true;
        break;
    }
}

if (!$wp_load_found) {
    echo "Error: Could not find wp-load.php. Please ensure this script is run from within a WordPress installation.\n";
    echo "Current directory: " . __DIR__ . "\n";
    exit(1);
}

// Check if we have the necessary WordPress functions
if (!function_exists('update_option')) {
    echo "Error: WordPress functions not available. Make sure wp-load.php was loaded correctly.\n";
    exit(1);
}

// Enable async processing
$enabled = update_option('puntwork_async_processing_enabled', true);

if ($enabled) {
    echo "✓ Async processing has been enabled successfully!\n";
    echo "The 'puntwork_async_processing_enabled' option is now set to 'true'.\n";
    echo "\nYou can now run imports with proper async processing using Action Scheduler.\n";
} else {
    // Check current value
    $current_value = get_option('puntwork_async_processing_enabled', false);
    if ($current_value === true) {
        echo "✓ Async processing is already enabled.\n";
    } else {
        echo "✗ Failed to enable async processing. Current value: " . var_export($current_value, true) . "\n";
    }
}

echo "\nScript completed.\n";
?>