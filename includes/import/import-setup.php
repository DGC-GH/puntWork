<?php

/**
 * Import setup and initialization.
 *
 * @since      1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import setup and validation
 * Handles preparation and prerequisite validation for job imports.
 */

// Include field mappings
require_once __DIR__ . '/../mappings/mappings-fields.php';

// Include utility helpers
require_once __DIR__ . '/../utilities/utility-helpers.php';

/**
 * Check import dependencies and prerequisites
 *
 * @return array|WP_Error Array of dependency check results or WP_Error on critical failure
 */
function check_import_dependencies() {
	$checks = array();
	$errors = array();

	// Check 1: Combined JSONL file status
	$combined_file = puntwork_get_combined_jsonl_path();
	$file_exists = file_exists( $combined_file );
	$file_size = $file_exists ? filesize( $combined_file ) : 0;
	$file_readable = $file_exists && is_readable( $combined_file );

	$checks['combined_file'] = array(
		'exists' => $file_exists,
		'size' => $file_size,
		'readable' => $file_readable,
		'path' => $combined_file,
	);

	if ( ! $file_exists ) {
		$errors[] = 'Combined JSONL file does not exist. Run feed processing first.';
	} elseif ( ! $file_readable ) {
		$errors[] = 'Combined JSONL file is not readable. Check file permissions.';
	} elseif ( $file_size === 0 ) {
		$errors[] = 'Combined JSONL file is empty. Feed processing may have failed.';
	}

	// Check 2: Action Scheduler availability
	$as_available = function_exists( 'as_schedule_single_action' ) && class_exists( 'ActionScheduler_Store' );
	$checks['action_scheduler'] = array(
		'available' => $as_available,
		'function_exists' => function_exists( 'as_schedule_single_action' ),
		'class_exists' => class_exists( 'ActionScheduler_Store' ),
	);

	if ( ! $as_available ) {
		$errors[] = 'Action Scheduler is not available. Install WooCommerce or Action Scheduler plugin.';
	}

	// Check 3: WordPress cron status
	$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	$checks['wordpress_cron'] = array(
		'disabled' => $cron_disabled,
		'alternative_enabled' => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
	);

	if ( $cron_disabled ) {
		$errors[] = 'WordPress cron is disabled. Action Scheduler may not work properly.';
	}

	// Check 4: ACF availability
	$acf_available = function_exists( 'get_field' ) && class_exists( 'ACF' );
	$checks['acf'] = array(
		'available' => $acf_available,
		'function_exists' => function_exists( 'get_field' ),
		'class_exists' => class_exists( 'ACF' ),
	);

	if ( ! $acf_available ) {
		$errors[] = 'Advanced Custom Fields (ACF) is not available. Install and activate ACF.';
	}

	// Check 5: Required directories
	$feeds_dir = puntwork_get_feeds_directory();
	$checks['directories'] = array(
		'feeds_dir_exists' => is_dir( $feeds_dir ),
		'feeds_dir_writable' => is_writable( $feeds_dir ),
		'feeds_dir_path' => $feeds_dir,
	);

	if ( ! is_dir( $feeds_dir ) ) {
		$errors[] = 'Feeds directory does not exist: ' . $feeds_dir;
	} elseif ( ! is_writable( $feeds_dir ) ) {
		$errors[] = 'Feeds directory is not writable: ' . $feeds_dir;
	}

	// If there are critical errors, return WP_Error
	if ( ! empty( $errors ) ) {
		return new WP_Error(
			'dependency_check_failed',
			'Import dependencies not met: ' . implode( '; ', $errors ),
			array(
				'checks' => $checks,
				'errors' => $errors
			)
		);
	}

	return array(
		'success' => true,
		'checks' => $checks,
		'warnings' => array(), // Could add non-critical warnings here
	);
}

/**
 * Prepare import setup and validate prerequisites.
 *
 * @param  int $batch_start Starting index for batch.
 * @return array|WP_Error Setup data or error.
 */
function prepare_import_setup( $batch_start = 0, $is_batch = false ) {
	global $wpdb;

	// First, check import dependencies
	$dependency_check = check_import_dependencies();
	if ( is_wp_error( $dependency_check ) ) {
		return $dependency_check;
	}

	try {
		$acf_fields = get_acf_fields();
	} catch ( \Exception $e ) {
		return new WP_Error( 'acf_error', 'Failed to get ACF fields: ' . $e->getMessage() );
	}

	try {
		$zero_empty_fields = get_zero_empty_fields();
	} catch ( \Exception $e ) {
		return new WP_Error( 'zero_fields_error', 'Failed to get zero empty fields: ' . $e->getMessage() );
	}

	if ( ! defined( 'WP_IMPORTING' ) ) {
		define( 'WP_IMPORTING', true );
	}
	wp_suspend_cache_invalidation( true );
	remove_action( 'post_updated', 'wp_save_post_revision' );

	// Check if there's an existing import in progress and use its start time
	$existing_status = safe_get_option( 'job_import_status' );
	if ( $existing_status && isset( $existing_status['start_time'] ) && $existing_status['start_time'] > 0 ) {
		$start_time = $existing_status['start_time'];
	} else {
		$start_time = microtime( true );
	}

	$json_path = puntwork_get_combined_jsonl_path();

	// Ensure the path is absolute for consistency
	if ( ! str_starts_with( $json_path, '/' ) ) {
		$json_path = realpath( $json_path ) ?: $json_path;
	}

	// Ensure feeds directory exists and is writable
	$ensure_result = puntwork_ensure_feeds_directory();
	if ( is_wp_error( $ensure_result ) ) {
		return array(
			'success' => false,
			'message' => $ensure_result->get_error_message(),
			'logs'    => array( $ensure_result->get_error_message() ),
		);
	}

	if ( ! file_exists( $json_path ) ) {
		return array(
			'success' => false,
			'message' => 'JSONL file not found - feeds may need to be processed first. Run feed processing to download and convert feeds to JSONL format.',
			'logs'    => array( 'JSONL file not found - run feed processing first to create individual feed files, then combine them' ),
		);
	}

	if ( ! is_readable( $json_path ) ) {
		return array(
			'success' => false,
			'message' => 'JSONL file not readable',
			'logs'    => array( 'JSONL file not readable - check file permissions' ),
		);
	}

	try {
		$total = get_json_item_count( $json_path );
	} catch ( \Exception $e ) {
		return new WP_Error( 'count_error', 'Failed to count JSONL items: ' . $e->getMessage() );
	}

	if ( $total === 0 ) {
		return array(
			'success' => false,
			'message' => 'No items found in JSONL file - feeds may need to be processed first',
			'processed' => 0,
			'total' => 0,
			'published' => 0,
			'updated' => 0,
			'skipped' => 0,
			'duplicates_drafted' => 0,
			'time_elapsed' => 0,
			'complete' => true,
			'logs' => array( 'No items found in JSONL file - run feed processing first' ),
			'batch_size' => 0,
			'inferred_languages' => 0,
			'inferred_benefits' => 0,
			'schema_generated' => 0,
			'batch_time' => 0,
			'batch_processed' => 0,
		);
	}

	// Cache existing job GUIDs if not already cached
	if ( false === safe_get_option( 'job_existing_guids' ) ) {
		$all_jobs = $wpdb->get_results( "SELECT p.ID, pm.meta_value AS guid FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID = pm.post_id WHERE p.post_type = 'job' AND pm.meta_key = 'guid'" );
		update_option( 'job_existing_guids', $all_jobs, false );
	}

	$processed_guids = safe_get_option( 'job_import_processed_guids' ) ?: array();

	// For batch processing, use batch_start as absolute starting index
	if ( $is_batch ) {
		$start_index = $batch_start;
	} else {
		$start_index = max( (int) safe_get_option( 'job_import_progress' ), $batch_start );
	}

	// For fresh starts (batch_start = 0), reset the status and create new start time
	// But only if there's no existing valid status OR if the existing status is complete
	$existing_status  = safe_get_option( 'job_import_status' );
	$has_valid_status = ! empty( $existing_status ) && isset( $existing_status['total'] ) && $existing_status['total'] > 0 && ( ! isset( $existing_status['complete'] ) || ! $existing_status['complete'] );

	if ( $batch_start == 0 && ! $has_valid_status ) {
		$start_index = 0;
		// Clear processed GUIDs for fresh start
		$processed_guids = array();
		// Clear existing status for fresh start
		delete_option( 'job_import_status' );
		// Clear progress for fresh start
		update_option( 'job_import_progress', 0, false );
		// Reset batch size for fresh start to allow dynamic adjustment
		delete_option( 'job_import_batch_size' );
		$start_time = microtime( true );

		// Initialize status for manual import
		$initial_status = array(
			'total'              => $total,
			'processed'          => 0,
			'published'          => 0,
			'updated'            => 0,
			'skipped'            => 0,
			'duplicates_drafted' => 0,
			'time_elapsed'       => 0,
			'complete'           => false,
			'success'            => false,
			'error_message'      => '',
			'batch_size'         => safe_get_option( 'job_import_batch_size' ) ?: 1,
			'inferred_languages' => 0,
			'inferred_benefits'  => 0,
			'schema_generated'   => 0,
			'start_time'         => $start_time,
			'end_time'           => null,
			'last_update'        => time(),
			'logs'               => array( 'Manual import started - preparing to process items...' ),
		);
		update_option( 'job_import_status', $initial_status, false );
	} elseif ( $batch_start == 0 && $has_valid_status ) {
		// Resuming from existing status - reset batch size for dynamic adjustment but keep other status
		$start_index = 0; // Reset to start from beginning
		// Reset batch size to allow dynamic adjustment to start fresh
		delete_option( 'job_import_batch_size' );
		// Reset progress for restart
		update_option( 'job_import_progress', 0, false );

		// Update existing status for restart
		$existing_status['processed'] = 0;
		$existing_status['published'] = 0;
		$existing_status['updated'] = 0;
		$existing_status['skipped'] = 0;
		$existing_status['duplicates_drafted'] = 0;
		$existing_status['time_elapsed'] = 0;
		$existing_status['complete'] = false;
		$existing_status['success'] = false;
		$existing_status['error_message'] = '';
		$existing_status['start_time'] = microtime( true );
		$existing_status['end_time'] = null;
		$existing_status['last_update'] = time();
		$existing_status['logs'][] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Import restarted from beginning';
		update_option( 'job_import_status', $existing_status, false );
	}

	// Check for early return
	if ( $start_index >= $total ) {
		return array(
			'success'            => true,
			'processed'          => $total,
			'total'              => $total,
			'published'          => 0,
			'updated'            => 0,
			'skipped'            => 0,
			'duplicates_drafted' => 0,
			'time_elapsed'       => 0,
			'complete'           => true,
			'logs'               => array( 'Start index beyond total items - import appears complete' ),
			'batch_size'         => 0,
			'inferred_languages' => 0,
			'inferred_benefits'  => 0,
			'schema_generated'   => 0,
			'batch_time'         => 0,
			'batch_processed'    => 0,
		);
	}

	return array(
		'acf_fields'        => $acf_fields,
		'zero_empty_fields' => $zero_empty_fields,
		'start_time'        => $start_time,
		'json_path'         => $json_path,
		'total'             => $total,
		'processed_guids'   => $processed_guids,
		'start_index'       => $start_index,
	);
}
