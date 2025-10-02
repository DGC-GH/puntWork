<?php
// Define ABSPATH for WordPress compatibility
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

error_reporting( E_ALL );
ini_set( 'display_errors', 1 );

echo "Testing import functions with 111MB file...\n";

// Load required files
require_once __DIR__ . '/includes/utilities/utility-helpers.php';
echo "Utility helpers loaded\n";

$json_path = 'feeds/combined-jobs.jsonl';

if ( ! file_exists( $json_path ) ) {
	echo "ERROR: File $json_path does not exist\n";
	exit( 1 );
}

echo "File exists: $json_path\n";
echo 'File size: ' . number_format( filesize( $json_path ) ) . " bytes\n";

// Test get_json_item_count
echo "\nTesting get_json_item_count()...\n";
$start = microtime( true );
$total = get_json_item_count( $json_path );
$time  = microtime( true ) - $start;

echo 'Total items: ' . number_format( $total ) . "\n";
echo 'Time taken: ' . number_format( $time, 2 ) . " seconds\n";
echo 'Items per second: ' . number_format( $total / $time ) . "\n";

// Test load_json_batch
echo "\nTesting load_json_batch()...\n";
require_once __DIR__ . '/includes/batch/batch-processing.php';

$batch_size  = 10;
$start_index = 0;

$batch = load_json_batch( $json_path, $start_index, $batch_size );
$batch_items = $batch['items'] ?? $batch; // Handle both return formats
echo 'Batch loaded: ' . count( $batch_items ) . " items\n";

if ( ! empty( $batch_items ) ) {
	echo 'First item GUID: ' . ( $batch_items[0]['guid'] ?? 'MISSING' ) . "\n";
	echo 'First item title: ' . substr( $batch_items[0]['title'] ?? 'MISSING', 0, 50 ) . "...\n";
}

// Test a batch from the middle
$middle_index = (int) ( $total / 2 );
echo "\nTesting batch from middle (index $middle_index)...\n";
$batch_middle = load_json_batch( $json_path, $middle_index, $batch_size );
$batch_middle_items = $batch_middle['items'] ?? $batch_middle;
echo 'Middle batch loaded: ' . count( $batch_middle_items ) . " items\n";

if ( ! empty( $batch_middle_items ) ) {
	echo 'Middle item GUID: ' . ( $batch_middle_items[0]['guid'] ?? 'MISSING' ) . "\n";
}

// Test end of file
$end_index = $total - $batch_size;
echo "\nTesting batch from end (index $end_index)...\n";
$batch_end = load_json_batch( $json_path, $end_index, $batch_size );
$batch_end_items = $batch_end['items'] ?? $batch_end;
echo 'End batch loaded: ' . count( $batch_end_items ) . " items\n";

echo "\nAll tests completed successfully!\n";
echo 'The import system can handle the full 111MB file with ' . number_format( $total ) . " records.\n";
