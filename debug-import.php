<?php

/**
 * Debug script to test the import pipeline step by step
 */

echo "=== PUNTWORK IMPORT DEBUGGING ===\n\n";

// Define paths
$json_path    = '/Users/dg/Documents/GitHub/puntWork/feeds/combined-jobs.jsonl';
$includes_dir = '/Users/dg/Documents/GitHub/puntWork/includes';

// Check if file exists
echo "1. Checking JSONL file...\n";
if ( ! file_exists( $json_path ) ) {
	echo "ERROR: JSONL file not found at $json_path\n";
	exit( 1 );
}
echo '✓ JSONL file exists, size: ' . filesize( $json_path ) . " bytes\n";

// Count lines
$line_count = intval( trim( shell_exec( "wc -l < '$json_path'" ) ) );
echo "✓ JSONL file has $line_count lines\n\n";

// Test loading a few items
echo "2. Testing load_json_batch function...\n";

function load_json_batch_test( $json_path, $start_index, $batch_size ) {
	$items         = array();
	$count         = 0;
	$current_index = 0;

	if ( ( $handle = fopen( $json_path, 'r' ) ) !== false ) {
		// Skip to start_index
		while ( $current_index < $start_index && ( $line = fgets( $handle ) ) !== false ) {
			++$current_index;
		}

		// Read batch_size items
		while ( $count < $batch_size && ( $line = fgets( $handle ) ) !== false ) {
			$line = trim( $line );
			if ( ! empty( $line ) ) {
				$item = json_decode( $line, true );
				if ( $item !== null ) {
					$items[] = $item;
					++$count;
				} else {
					echo 'WARNING: Failed to decode JSON at line ' . ( $current_index + $count + 1 ) . ': ' . json_last_error_msg() . "\n";
				}
			}
			++$current_index;
		}
		fclose( $handle );
	}

	return $items;
}

// Load first 5 items
$test_items = load_json_batch_test( $json_path, 0, 5 );
echo '✓ Loaded ' . count( $test_items ) . " test items\n";

foreach ( $test_items as $i => $item ) {
	$guid  = $item['guid'] ?? 'MISSING';
	$title = $item['functiontitle'] ?? 'MISSING';
	echo '  Item ' . ( $i + 1 ) . ": GUID='$guid', Title='$title'\n";
}

echo "\n3. Testing load_and_prepare_batch_items logic...\n";

// Simulate the load_and_prepare_batch_items logic
$batch_items   = array();
$batch_guids   = array();
$loaded_count  = count( $test_items );
$skipped_count = 0;

echo "Processing $loaded_count loaded items...\n";

for ( $i = 0; $i < count( $test_items ); $i++ ) {
	$current_index = $i;
	$item          = $test_items[ $i ];
	$guid          = $item['guid'] ?? '';

	if ( empty( $guid ) ) {
		echo '  SKIPPED #' . ( $current_index + 1 ) . ': Empty GUID - Item keys: ' . implode( ', ', array_keys( $item ) ) . "\n";
		++$skipped_count;
		continue;
	}

	$batch_guids[] = $guid;
	echo '  PROCESSED #' . ( $current_index + 1 ) . ' GUID: ' . $guid . "\n";
	$batch_items[ $guid ] = array(
		'item'  => $item,
		'index' => $current_index,
	);
}

$valid_items_count = count( $batch_guids );
echo "\n✓ Prepared $valid_items_count valid items for processing (skipped $skipped_count items)\n";

echo "\n4. Summary:\n";
echo "  - JSONL file: $line_count lines\n";
echo '  - Test loaded: ' . count( $test_items ) . " items\n";
echo "  - Valid GUIDs: $valid_items_count\n";
echo "  - Skipped: $skipped_count\n";

if ( $valid_items_count > 0 ) {
	echo "\n✓ Items are being loaded and have valid GUIDs\n";
	echo "✓ The issue is likely in the actual import execution, not in data loading\n";
} else {
	echo "\n✗ No valid items found - this would cause 0 processed items\n";
}

echo "\n=== DEBUG COMPLETE ===\n";
