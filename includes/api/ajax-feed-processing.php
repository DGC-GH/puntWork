<?php

/**
 * Sanitize status data to prevent "undefined" values from breaking JSON serialization
 * and limit log size to prevent memory issues.
 *
 * @param array $status The status array to sanitize
 * @return array Sanitized status array
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ensure required dependencies are loaded for AJAX requests
require_once __DIR__ . '/../utilities/PuntWorkLogger.php';
require_once __DIR__ . '/../utilities/SecurityUtils.php';
require_once __DIR__ . '/../utilities/DynamicRateLimiter.php';
require_once __DIR__ . '/../utilities/AjaxErrorHandler.php';
require_once __DIR__ . '/../utilities/CacheManager.php';
require_once __DIR__ . '/../core/core-structure-logic.php';
require_once __DIR__ . '/../import/feed-processor.php';
require_once __DIR__ . '/../jobboards/jobboard.php';
require_once __DIR__ . '/../jobboards/jobboard-manager.php';
require_once __DIR__ . '/../jobboards/indeed-board.php';
require_once __DIR__ . '/../jobboards/linkedin-board.php';
require_once __DIR__ . '/../jobboards/glassdoor-board.php';
require_once __DIR__ . '/../ai/job-categorizer.php';
require_once __DIR__ . '/../ai/content-quality-scorer.php';
function sanitize_import_status( $status ) {
	if ( ! is_array( $status ) ) {
		error_log( '[PUNTWORK] [SANITIZE] Status is not an array: ' . var_export( $status, true ) );

		return array();
	}

	$sanitized = array();
	foreach ( $status as $key => $value ) {
		// Skip invalid keys
		if ( ! is_string( $key ) && ! is_int( $key ) ) {
			error_log( '[PUNTWORK] [SANITIZE] Skipping invalid key: ' . var_export( $key, true ) );

			continue;
		}

		// Sanitize values
		if ( $value === 'undefined' || $value === null ) {
			error_log( '[PUNTWORK] [SANITIZE] Converting "undefined"/null to empty for key: ' . $key );
			$value = '';
		} elseif ( is_string( $value ) && strpos( $value, 'undefined' ) !== false ) {
			error_log( '[PUNTWORK] [SANITIZE] Removing "undefined" from string value for key: ' . $key );
			$value = str_replace( 'undefined', '', $value );
		} elseif ( is_array( $value ) ) {
			// Special handling for logs array - limit to last 50 entries
			if ( $key === 'logs' && is_array( $value ) ) {
				$value = array_slice( $value, -50 ); // Keep only the last 50 log entries
			} else {
				$value = sanitize_import_status( $value ); // Recursive sanitization
			}
		} elseif ( ! is_scalar( $value ) && ! is_array( $value ) ) {
			error_log( '[PUNTWORK] [SANITIZE] Converting non-scalar value to string for key: ' . $key );
			$value = (string) $value;
		}

		$sanitized[ $key ] = $value;
	}

	return $sanitized;
}

// Duplicate action removed - handled in ajax-import-control.php
// add_action('wp_ajax_process_feed', __NAMESPACE__ . '\\process_feed_ajax');
// Duplicate function removed - handled in ajax-import-control.php

// Duplicate action removed - handled in ajax-import-control.php
// add_action('wp_ajax_combine_jsonl', __NAMESPACE__ . '\\combine_jsonl_ajax');
// Duplicate function removed - handled in ajax-import-control.php

add_action( 'wp_ajax_generate_json', __NAMESPACE__ . '\\generate_json_ajax' );
function generate_json_ajax() {
	PuntWorkLogger::logAjaxRequest( 'generate_json', $_POST );

	// Use comprehensive security validation
	$validation = SecurityUtils::validateAjaxRequest( 'generate_json', 'job_import_nonce' );
	if ( is_wp_error( $validation ) ) {
		AjaxErrorHandler::sendError( $validation );

		return;
	}

	try {
		PuntWorkLogger::info( 'Starting JSONL generation process', PuntWorkLogger::CONTEXT_FEED );

		$gen_logs = fetch_and_generate_combined_json();
		PuntWorkLogger::info( 'JSONL generation completed successfully', PuntWorkLogger::CONTEXT_FEED );

		PuntWorkLogger::logAjaxResponse(
			'generate_json',
			array(
				'message'    => 'JSONL generated successfully',
				'logs_count' => count( $gen_logs ),
			)
		);
		AjaxErrorHandler::sendSuccess(
			array(
				'message' => 'JSONL generated successfully',
				'logs'    => $gen_logs,
			)
		);
	} catch ( \Exception $e ) {
		PuntWorkLogger::error( 'JSONL generation failed: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_FEED );

		PuntWorkLogger::logAjaxResponse( 'generate_json', array( 'message' => 'JSONL generation failed: ' . $e->getMessage() ), false );
		AjaxErrorHandler::sendError( 'JSONL generation failed: ' . $e->getMessage() );
	}
}
