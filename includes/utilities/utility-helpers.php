<?php

/**
 * Utility helper functions
 *
 * @package    Puntwork
 * @subpackage Utilities
 * @since      1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'get_memory_limit_bytes' ) ) {
	function get_memory_limit_bytes() {
		$memory_limit = ini_get( 'memory_limit' );
		if ( $memory_limit == '-1' ) {
			return PHP_INT_MAX;
		}
		$number = (int) preg_replace( '/[^0-9]/', '', $memory_limit );
		$suffix = preg_replace( '/[0-9]/', '', $memory_limit );
		switch ( strtoupper( $suffix ) ) {
			case 'G':
				return $number * 1024 * 1024 * 1024;
			case 'M':
				return $number * 1024 * 1024;
			case 'K':
				return $number * 1024;
			default:
				return $number;
		}
	}
}

if ( ! function_exists( 'get_json_item_count' ) ) {
	/**
	 * Get the total count of items in JSONL file.
	 *
	 * @param  string $json_path Path to JSONL file.
	 * @return int Total item count.
	 */
	function get_json_item_count( $json_path ) {
		error_log( '[PUNTWORK] get_json_item_count called with path: ' . $json_path );
		$count        = 0;
		$sample_lines = array();
		$bom          = "\xef\xbb\xbf";
		if ( ( $handle = fopen( $json_path, 'r' ) ) !== false ) {
			$line_num = 0;
			while ( ( $line = fgets( $handle ) ) !== false ) {
				++$line_num;
				$line = trim( $line );
				// Remove BOM if present
				if ( substr( $line, 0, 3 ) === $bom ) {
					$line = substr( $line, 3 );
				}
				if ( ! empty( $line ) ) {
					$item = json_decode( $line, true );
					if ( $item !== null ) {
						++$count;
						// Collect first 5 valid items for debugging
						if ( $count <= 5 ) {
							$sample_lines[] = 'Line ' . $line_num . ': GUID=' . ( $item['guid'] ?? 'MISSING' ) . ', keys=' . implode( ',', array_keys( $item ) );
						}
					} else {
						error_log( '[PUNTWORK] get_json_item_count: Invalid JSON at line ' . $line_num . ': ' . json_last_error_msg() );
					}
				}
			}
			fclose( $handle );
		}
		error_log( '[PUNTWORK] get_json_item_count: Total valid items: ' . $count . ' (file has ' . ( file_exists( $json_path ) ? 'exists' : 'does not exist' ) . ')' );
		if ( ! empty( $sample_lines ) ) {
			error_log( '[PUNTWORK] get_json_item_count: Sample items: ' . implode( ' | ', $sample_lines ) );
		}
		return $count;
	}
}
