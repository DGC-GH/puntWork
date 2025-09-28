<?php
// Test script for async import scheduling
require_once __DIR__ . '/tests/bootstrap.php';

error_reporting( E_ALL );
ini_set( 'display_errors', 1 );

echo "Testing async import scheduling...\n";

// Load required files
require_once __DIR__ . '/includes/import/import-batch.php';
echo "Import batch functions loaded\n";

// Test that the functions exist
if ( function_exists( 'start_scheduled_import' ) ) {
	echo "✓ start_scheduled_import function exists\n";
} else {
	echo "✗ start_scheduled_import function missing\n";
}

if ( function_exists( 'continue_paused_import' ) ) {
	echo "✓ continue_paused_import function exists\n";
} else {
	echo "✗ continue_paused_import function missing\n";
}

// Test that hooks are registered
global $wp_filter;
if ( isset( $wp_filter['puntwork_start_scheduled_import'] ) ) {
	echo "✓ puntwork_start_scheduled_import hook registered\n";
} else {
	echo "✗ puntwork_start_scheduled_import hook not registered\n";
}

if ( isset( $wp_filter['puntwork_continue_import'] ) ) {
	echo "✓ puntwork_continue_import hook registered\n";
} else {
	echo "✗ puntwork_continue_import hook not registered\n";
}

echo "\nAsync import scheduling test completed!\n";