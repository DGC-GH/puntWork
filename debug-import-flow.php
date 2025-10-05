<?php

/**
 * Debug script to test the import flow and verify debug logging
 * This helps prevent future issues by providing comprehensive logging
 */

// Include WordPress
require_once '../../../wp-load.php';

if ( ! defined( 'ABSPATH' ) ) {
	die( 'WordPress not loaded' );
}

echo "=== PUNTWORK IMPORT FLOW DEBUG TEST ===\n\n";

// Test 1: Check if required functions are available
echo "1. Testing function availability:\n";
$functions_to_check = array(
	'import_jobs_from_json',
	'prepare_import_setup',
	'combine_jsonl_files',
	'validate_jsonl_file',
	'get_json_item_count',
);

foreach ( $functions_to_check as $func ) {
	$available = function_exists( $func );
	echo "   - $func: " . ( $available ? '✅ Available' : '❌ Missing' ) . "\n";
}
echo "\n";

// Test 2: Check file paths
echo "2. Testing file paths:\n";
$json_path = puntwork_get_combined_jsonl_path();
echo "   - JSONL path: $json_path\n";
echo '   - File exists: ' . ( file_exists( $json_path ) ? '✅ Yes' : '❌ No' ) . "\n";
if ( file_exists( $json_path ) ) {
	echo '   - File size: ' . filesize( $json_path ) . " bytes\n";
	echo '   - File readable: ' . ( is_readable( $json_path ) ? '✅ Yes' : '❌ No' ) . "\n";
}
echo '   - Feeds directory exists: ' . ( is_dir( puntwork_get_feeds_directory() ) ? '✅ Yes' : '❌ No' ) . "\n";
echo '   - Feeds directory writable: ' . ( is_writable( puntwork_get_feeds_directory() ) ? '✅ Yes' : '❌ No' ) . "\n";

$feed_files = glob( puntwork_get_feeds_directory() . '*.jsonl' );
echo '   - Individual feed files: ' . count( $feed_files ) . " found\n";
if ( ! empty( $feed_files ) ) {
	echo '   - Feed files: ' . implode( ', ', array_map( 'basename', $feed_files ) ) . "\n";
}
echo "\n";

// Test 3: Test import setup (without actually starting import)
echo "3. Testing prepare_import_setup (dry run):\n";
if ( function_exists( 'prepare_import_setup' ) ) {
	try {
		$setup_result = prepare_import_setup( 0, false );
		if ( is_wp_error( $setup_result ) ) {
			echo '   ❌ Setup failed: ' . $setup_result->get_error_message() . "\n";
		} elseif ( isset( $setup_result['success'] ) && $setup_result['success'] === false ) {
			echo '   ⚠️  Setup returned early: ' . ( $setup_result['message'] ?? 'No message' ) . "\n";
		} else {
			echo "   ✅ Setup successful\n";
			echo '   - Total items: ' . ( $setup_result['total'] ?? 'not set' ) . "\n";
			echo '   - Start index: ' . ( $setup_result['start_index'] ?? 'not set' ) . "\n";
			echo '   - JSON path exists: ' . ( isset( $setup_result['json_path'] ) && file_exists( $setup_result['json_path'] ) ? '✅ Yes' : '❌ No' ) . "\n";
		}
	} catch ( Exception $e ) {
		echo '   ❌ Setup exception: ' . $e->getMessage() . "\n";
	}
} else {
	echo "   ❌ prepare_import_setup function not available\n";
}
echo "\n";

// Test 4: Check import status
echo "4. Checking import status:\n";
$status = get_option( 'job_import_status', array() );
echo '   - Status exists: ' . ( ! empty( $status ) ? '✅ Yes' : '❌ No' ) . "\n";
if ( ! empty( $status ) ) {
	echo '   - Total: ' . ( $status['total'] ?? 'not set' ) . "\n";
	echo '   - Processed: ' . ( $status['processed'] ?? 'not set' ) . "\n";
	echo '   - Complete: ' . ( isset( $status['complete'] ) ? ( $status['complete'] ? '✅ Yes' : '❌ No' ) : 'not set' ) . "\n";
}
echo "\n";

// Test 5: Check transients
echo "5. Checking transients:\n";
$import_lock   = get_transient( 'puntwork_import_lock' );
$import_cancel = get_transient( 'import_cancel' );
echo '   - Import lock: ' . ( $import_lock ? '🔒 Active' : '✅ Clear' ) . "\n";
echo '   - Import cancel: ' . ( $import_cancel ? '🚫 Active' : '✅ Clear' ) . "\n";
echo "\n";

echo "=== DEBUG TEST COMPLETE ===\n";
echo "Check the debug.log file for detailed logs during actual import runs.\n";
echo "Look for log entries prefixed with [PUNTWORK] for import flow tracing.\n";
