<?php

/**
 * Enhanced error handling for AJAX responses.
 *
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enhanced error handling for AJAX responses.
 */
class AjaxErrorHandler {

	/**
	 * Send JSON error response with proper formatting.
	 *
	 * @param string|\WP_Error $error           Error message or WP_Error object
	 * @param array            $additional_data Additional data to include
	 */
	public static function sendError( $error, array $additional_data = array() ) {
		$error_data = array(
			'success'   => false,
			'timestamp' => current_time( 'mysql' ),
		);

		if ( is_wp_error( $error ) ) {
			$error_data['error'] = array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'data'    => $error->get_error_data(),
			);
		} else {
			$error_data['error'] = array(
				'code'    => 'general_error',
				'message' => $error,
			);
		}

		$error_data = array_merge( $error_data, $additional_data );

		// Log error for security monitoring
		if ( is_wp_error( $error ) ) {
			PuntWorkLogger::error(
				'AJAX Error Response: ' . $error->get_error_message(),
				PuntWorkLogger::CONTEXT_AJAX,
				array(
					'error_code' => $error->get_error_code(),
					'error_data' => $error->get_error_data(),
				)
			);
		} else {
			PuntWorkLogger::error( 'AJAX Error Response: ' . $error, PuntWorkLogger::CONTEXT_AJAX );
		}

		wp_send_json( $error_data );
	}

	/**
	 * Send JSON success response with proper formatting.
	 *
	 * @param mixed $data            Response data
	 * @param array $additional_data Additional data to include
	 */
	public static function sendSuccess( $data = null, array $additional_data = array() ) {
		$response_data = array(
			'success'   => true,
			'timestamp' => current_time( 'mysql' ),
		);

		if ( $data !== null ) {
			$response_data['data'] = $data;
		}

		$response_data = array_merge( $response_data, $additional_data );

		wp_send_json( $response_data );
	}
}
