<?php

/**
 * Security and validation utilities
 *
 * @package    Puntwork
 * @subpackage Utilities
 * @since      1.0.10
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security utilities class
 */
class SecurityUtils {

	/**
	 * Rate limiting storage
	 */
	private static $rate_limits = array();

	/**
	 * Get dynamic rate limit configuration for an action
	 *
	 * @param  string $action Action name
	 * @return array Rate limit configuration with 'max_requests' and 'time_window'
	 */
	public static function getRateLimitConfig( string $action ): array {
		// Get stored rate limits from options
		$stored_limits = get_option( 'puntwork_rate_limits', array() );

		// Default rate limits
		$defaults = array(
			'default'                => array(
				'max_requests' => 10,
				'time_window'  => 60, // 1 minute
			),
			'run_job_import_batch'   => array(
				'max_requests' => 100,
				'time_window'  => 300, // 5 minutes
			),
			'get_job_import_status'  => array(
				'max_requests' => 30,
				'time_window'  => 60, // 1 minute
			),
			'process_feed'           => array(
				'max_requests' => 20,
				'time_window'  => 300, // 5 minutes
			),
			'test_single_job_import' => array(
				'max_requests' => 5,
				'time_window'  => 300, // 5 minutes
			),
			'clear_rate_limits'      => array(
				'max_requests' => 3,
				'time_window'  => 3600, // 1 hour
			),
		);

		// Apply filter for customization
		$defaults = apply_filters( 'puntwork_rate_limit_defaults', $defaults );

		// Merge stored limits with defaults
		$config = array_merge( $defaults, $stored_limits );

		// Return specific action config or default
		return $config[ $action ] ?? $config['default'];
	}

	/**
	 * Set rate limit configuration for an action
	 *
	 * @param string $action       Action name
	 * @param int    $max_requests Maximum requests allowed
	 * @param int    $time_window  Time window in seconds
	 * @return bool True if saved successfully
	 */
	public static function setRateLimitConfig( string $action, int $max_requests, int $time_window ): bool {
		// Validate inputs
		if ( $max_requests < 1 || $time_window < 1 ) {
			return false;
		}

		$stored_limits            = get_option( 'puntwork_rate_limits', array() );
		$stored_limits[ $action ] = array(
			'max_requests' => $max_requests,
			'time_window'  => $time_window,
		);

		return update_option( 'puntwork_rate_limits', $stored_limits );
	}

	/**
	 * Reset rate limit configuration for an action to default
	 *
	 * @param string $action Action name
	 * @return bool True if reset successfully
	 */
	public static function resetRateLimitConfig( string $action ): bool {
		$stored_limits = get_option( 'puntwork_rate_limits', array() );
		if ( isset( $stored_limits[ $action ] ) ) {
			unset( $stored_limits[ $action ] );
			return update_option( 'puntwork_rate_limits', $stored_limits );
		}
		return true; // Already at default
	}

	/**
	 * Get all rate limit configurations
	 *
	 * @return array All rate limit configurations
	 */
	public static function getAllRateLimitConfigs(): array {
		$stored_limits = get_option( 'puntwork_rate_limits', array() );

		// Default rate limits
		$defaults = array(
			'default'                => array(
				'max_requests' => 10,
				'time_window'  => 60,
			),
			'run_job_import_batch'   => array(
				'max_requests' => 100,
				'time_window'  => 300,
			),
			'get_job_import_status'  => array(
				'max_requests' => 60,
				'time_window'  => 60,
			),
			'process_feed'           => array(
				'max_requests' => 20,
				'time_window'  => 300,
			),
			'test_single_job_import' => array(
				'max_requests' => 5,
				'time_window'  => 300,
			),
			'clear_rate_limits'      => array(
				'max_requests' => 3,
				'time_window'  => 3600,
			),
		);

		// Apply filter for customization
		$defaults = apply_filters( 'puntwork_rate_limit_defaults', $defaults );

		// Merge stored limits with defaults
		return array_merge( $defaults, $stored_limits );
	}

	/**
	 * Reset all rate limit configurations to defaults
	 *
	 * @return bool True if reset successfully
	 */
	public static function resetAllRateLimitConfigs(): bool {
		return delete_option( 'puntwork_rate_limits' );
	}

	/**
	 * Validate AJAX request with comprehensive security checks
	 *
	 * @param  string $action           Action name for logging
	 * @param  string $nonce_action     Nonce action string
	 * @param  array  $required_fields  Required POST fields
	 * @param  array  $validation_rules Validation rules for fields
	 * @return array|WP_Error Validation result or error
	 */
	public static function validateAjaxRequest(
		string $action,
		string $nonce_action = 'puntwork_admin_nonce',
		array $required_fields = array(),
		array $validation_rules = array()
	) {
		try {
			// Check rate limiting first with action-specific limits
			$rate_limit_check = self::checkRateLimit( $action );
			if ( is_wp_error( $rate_limit_check ) ) {
				PuntWorkLogger::warn( "Rate limit exceeded for action: {$action}", PuntWorkLogger::CONTEXT_SECURITY );
				return $rate_limit_check;
			}

			// Verify nonce
			if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', $nonce_action ) ) {
				PuntWorkLogger::error( "Nonce verification failed for action: {$action}", PuntWorkLogger::CONTEXT_SECURITY );
				return new \WP_Error( 'security', 'Security check failed: invalid nonce' );
			}

			// Check user capabilities
			if ( ! current_user_can( 'manage_options' ) ) {
				PuntWorkLogger::error( "Permission denied for action: {$action}", PuntWorkLogger::CONTEXT_SECURITY );
				return new \WP_Error( 'permissions', 'Insufficient permissions' );
			}

			// Validate required fields
			foreach ( $required_fields as $field ) {
				if ( ! isset( $_POST[ $field ] ) || $_POST[ $field ] == '' ) {
					PuntWorkLogger::error( "Missing required field: {$field} for action: {$action}", PuntWorkLogger::CONTEXT_SECURITY );
					return new \WP_Error( 'validation', "Missing required field: {$field}" );
				}
			}

			// Apply validation rules
			foreach ( $validation_rules as $field => $rules ) {
				if ( isset( $_POST[ $field ] ) ) {
					$validation_result = self::validateField( $_POST[ $field ], $rules, $field );
					if ( is_wp_error( $validation_result ) ) {
						PuntWorkLogger::error( "Field validation failed for {$field}: " . $validation_result->get_error_message(), PuntWorkLogger::CONTEXT_SECURITY );
						return $validation_result;
					}
					// Sanitize the validated value
					$_POST[ $field ] = $validation_result;
				}
			}

			PuntWorkLogger::debug( "AJAX request validation passed for action: {$action}", PuntWorkLogger::CONTEXT_SECURITY );
			return true;
		} catch ( \Exception $e ) {
			PuntWorkLogger::error( "Exception during AJAX validation for {$action}: " . $e->getMessage(), PuntWorkLogger::CONTEXT_SECURITY );
			return new \WP_Error( 'exception', 'Validation error: ' . $e->getMessage() );
		}
	}

	/**
	 * Check rate limiting for AJAX requests
	 *
	 * @param  string $action       Action name
	 * @param  ?int   $max_requests Maximum requests per time window (optional override)
	 * @param  ?int   $time_window  Time window in seconds (optional override)
	 * @return bool|WP_Error True if allowed, WP_Error if rate limited
	 */
	public static function checkRateLimit( string $action, ?int $max_requests = null, ?int $time_window = null ) {
		// Use dynamic configuration if no overrides provided
		if ( $max_requests == null || $time_window == null ) {
			$config       = self::getRateLimitConfig( $action );
			$max_requests = $max_requests ?? $config['max_requests'];
			$time_window  = $time_window ?? $config['time_window'];
		}

		// Allow filter to override rate limits
		$max_requests = apply_filters( 'puntwork_rate_limit_max_requests', $max_requests, $action );
		$time_window  = apply_filters( 'puntwork_rate_limit_time_window', $time_window, $action );

		$user_id = get_current_user_id();
		$key     = "rate_limit_{$action}_{$user_id}";

		$current_time = time();
		$requests     = get_transient( $key );

		if ( ! $requests ) {
			$requests = array();
		}

		// Remove old requests outside the time window
		$requests = array_filter(
			$requests,
			function ( $timestamp ) use ( $current_time, $time_window ) {
				return ( $current_time - $timestamp ) < $time_window;
			}
		);

		// Check if rate limit exceeded
		if ( count( $requests ) >= $max_requests ) {
			$remaining_time = 0;
			if ( ! empty( $requests ) ) {
				$oldest_request = min( $requests );
				$remaining_time = $time_window - ( $current_time - $oldest_request );
				$remaining_time = max( 0, $remaining_time );
			}

			$error_message = sprintf(
				'Rate limit exceeded for "%s". Maximum %d requests per %d seconds. Please wait %d seconds before trying again.',
				$action,
				$max_requests,
				$time_window,
				$remaining_time
			);

			return new \WP_Error( 'rate_limit', $error_message );
		}

		// Add current request
		$requests[] = $current_time;

		// Store updated requests (with 10% buffer on time window)
		set_transient( $key, $requests, $time_window + 6 );

		return true;
	}

	/**
	 * Clear rate limit for a specific action and user
	 *
	 * @param  string $action Action name
	 * @param  ?int   $user_id User ID (optional, defaults to current user)
	 * @return bool True if cleared
	 */
	public static function clearRateLimit( string $action, ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();
		$key     = "rate_limit_{$action}_{$user_id}";

		return delete_transient( $key );
	}

	/**
	 * Clear all rate limits for current user
	 *
	 * @return int Number of transients cleared
	 */
	public static function clearAllRateLimits(): int {
		global $wpdb;
		$user_id = get_current_user_id();
		$pattern = $wpdb->esc_like( "_transient_rate_limit_%_{$user_id}" ) . '%';

		$cleared    = 0;
		$transients = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$pattern
			)
		);

		foreach ( $transients as $transient ) {
			$transient_name = str_replace( '_transient_', '', $transient );
			if ( delete_transient( $transient_name ) ) {
				++$cleared;
			}
		}

		return $cleared;
	}

	/**
	 * Validate a single field against rules
	 *
	 * @param  mixed  $value      Field value
	 * @param  array  $rules      Validation rules
	 * @param  string $field_name Field name for error messages
	 * @return mixed|WP_Error Sanitized value or error
	 */
	public static function validateField( $value, array $rules, string $field_name ) {
		// Type validation
		if ( isset( $rules['type'] ) ) {
			switch ( $rules['type'] ) {
				case 'int':
				case 'integer':
					if ( ! is_numeric( $value ) ) {
						return new \WP_Error( 'validation', "{$field_name} must be a number" );
					}
					$value = intval( $value );
					break;
				case 'float':
				case 'double':
					if ( ! is_numeric( $value ) ) {
						return new \WP_Error( 'validation', "{$field_name} must be a number" );
					}
					$value = floatval( $value );
					break;
				case 'bool':
				case 'boolean':
						$value = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
					if ( $value == null ) {
						return new \WP_Error( 'validation', "{$field_name} must be a boolean" );
					}
					break;
				case 'string':
					$value = strval( $value );
					break;
				case 'key':
					$value = sanitize_key( $value );
					break;
				case 'text':
					$value = sanitize_text_field( $value );
					break;
				case 'textarea':
					$value = sanitize_textarea_field( $value );
					break;
				case 'email':
					$value = sanitize_email( $value );
					if ( ! is_email( $value ) ) {
						return new \WP_Error( 'validation', "{$field_name} must be a valid email address" );
					}
					break;
				case 'url':
					$value = esc_url_raw( $value );
					if ( ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
						return new \WP_Error( 'validation', "{$field_name} must be a valid URL" );
					}
					break;
				case 'html':
					$value = wp_kses_post( $value ); // Allow safe HTML
					break;
				case 'json':
					$value   = strval( $value );
					$decoded = json_decode( $value, true );
					if ( json_last_error() !== JSON_ERROR_NONE ) {
						return new \WP_Error( 'validation', "{$field_name} must be valid JSON" );
					}
					$value = $decoded;
					break;
				case 'array':
					if ( ! is_array( $value ) ) {
						return new \WP_Error( 'validation', "{$field_name} must be an array" );
					}
					// Sanitize array elements if rules specify element validation
					if ( isset( $rules['element_rules'] ) ) {
						$sanitized_array = array();
						foreach ( $value as $key => $element ) {
							$result = self::validate_field( $element, $rules['element_rules'], "{$field_name}[{$key}]" );
							if ( is_wp_error( $result ) ) {
								return $result;
							}
							$sanitized_array[ $key ] = $result;
						}
						$value = $sanitized_array;
					}
					break;
			}
		}

		// Range validation
		if ( isset( $rules['min'] ) && is_numeric( $value ) && $value < $rules['min'] ) {
			return new \WP_Error( 'validation', "{$field_name} must be at least {$rules['min']}" );
		}

		if ( isset( $rules['max'] ) && is_numeric( $value ) && $value > $rules['max'] ) {
			return new \WP_Error( 'validation', "{$field_name} must be at most {$rules['max']}" );
		}

		// Length validation for strings
		if ( is_string( $value ) ) {
			if ( isset( $rules['min_length'] ) && strlen( $value ) < $rules['min_length'] ) {
				return new \WP_Error( 'validation', "{$field_name} must be at least {$rules['min_length']} characters" );
			}

			if ( isset( $rules['max_length'] ) && strlen( $value ) > $rules['max_length'] ) {
				return new \WP_Error( 'validation', "{$field_name} must be at most {$rules['max_length']} characters" );
			}
		}

		// Array size validation
		if ( is_array( $value ) ) {
			if ( isset( $rules['min_count'] ) && count( $value ) < $rules['min_count'] ) {
				return new \WP_Error( 'validation', "{$field_name} must contain at least {$rules['min_count']} items" );
			}

			if ( isset( $rules['max_count'] ) && count( $value ) > $rules['max_count'] ) {
				return new \WP_Error( 'validation', "{$field_name} must contain at most {$rules['max_count']} items" );
			}
		}

		// Enum validation
		if ( isset( $rules['enum'] ) && is_array( $rules['enum'] ) && ! in_array( $value, $rules['enum'] ) ) {
			return new \WP_Error( 'validation', "{$field_name} must be one of: " . implode( ', ', $rules['enum'] ) );
		}

		// Pattern validation
		if ( isset( $rules['pattern'] ) && ! preg_match( $rules['pattern'], $value ) ) {
			return new \WP_Error( 'validation', "{$field_name} format is invalid" );
		}

		// Custom validation callback
		if ( isset( $rules['callback'] ) && is_callable( $rules['callback'] ) ) {
			$callback_result = call_user_func( $rules['callback'], $value, $field_name );
			if ( is_wp_error( $callback_result ) ) {
				return $callback_result;
			}
			$value = $callback_result;
		}

		return $value;
	}

	/**
	 * Sanitize and validate array of data recursively
	 *
	 * @param  array $data  Data to sanitize
	 * @param  array $rules Validation rules
	 * @return array|WP_Error Sanitized data or error
	 */
	public static function sanitizeDataArray( array $data, array $rules ) {
		$sanitized = array();

		foreach ( $rules as $field => $field_rules ) {
			if ( isset( $data[ $field ] ) ) {
				$result = self::validateField( $data[ $field ], $field_rules, $field );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$sanitized[ $field ] = $result;
			} elseif ( isset( $field_rules['required'] ) && $field_rules['required'] ) {
				return new \WP_Error( 'validation', "Required field missing: {$field}" );
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitize all request input (GET, POST, etc.)
	 *
	 * @param  string $method Request method ('GET', 'POST', etc.)
	 * @return array Sanitized input data
	 */
	public static function sanitizeRequestInput( string $method = 'POST' ): array {
		$input = array();

		switch ( $method ) {
			case 'GET':
				$input = $_GET;
				break;
			case 'POST':
				$input = $_POST;
				break;
			case 'REQUEST':
				$input = $_REQUEST;
				break;
			default:
				return array();
		}

		$sanitized = array();
		foreach ( $input as $key => $value ) {
			$sanitized[ $key ] = self::sanitizeDeep( $value );
		}

		return $sanitized;
	}

	/**
	 * Recursively sanitize data
	 *
	 * @param  mixed $data Data to sanitize
	 * @return mixed Sanitized data
	 */
	public static function sanitizeDeep( $data ) {
		if ( is_array( $data ) ) {
			$sanitized = array();
			foreach ( $data as $key => $value ) {
				$sanitized[ sanitize_key( $key ) ] = self::sanitizeDeep( $value );
			}
			return $sanitized;
		} elseif ( is_object( $data ) ) {
			$sanitized = new \stdClass();
			foreach ( $data as $key => $value ) {
				$sanitized->{sanitize_key( $key )} = self::sanitizeDeep( $value );
			}
			return $sanitized;
		} elseif ( is_string( $data ) ) {
			return sanitize_text_field( $data );
		} else {
			return $data;
		}
	}

	/**
	 * Validate file upload security
	 *
	 * @param  array $file          $_FILES array entry
	 * @param  array $allowed_types Allowed MIME types
	 * @param  int   $max_size      Maximum file size in bytes
	 * @return bool|WP_Error True if valid, WP_Error if invalid
	 */
	public static function validateFileUpload( array $file, array $allowed_types = array(), int $max_size = 0 ) {
		// Check if file was uploaded
		if ( ! isset( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new \WP_Error( 'upload_error', 'No file was uploaded' );
		}

		// Check file size
		if ( $max_size > 0 && $file['size'] > $max_size ) {
			return new \WP_Error( 'upload_error', 'File size exceeds maximum allowed size' );
		}

		// Check MIME type
		$file_type = wp_check_filetype( $file['name'] );
		if ( ! empty( $allowed_types ) && ! in_array( $file_type['type'], $allowed_types ) ) {
			return new \WP_Error( 'upload_error', 'File type not allowed' );
		}

		// Check for malicious file content (basic check)
		$file_content = file_get_contents( $file['tmp_name'] );
		if ( strpos( $file_content, '<?php' ) !== false || strpos( $file_content, '<script' ) !== false ) {
			return new \WP_Error( 'upload_error', 'File contains potentially malicious content' );
		}

		return true;
	}

	/**
	 * Generate a secure random string
	 *
	 * @param  int $length Length of the string
	 * @return string Random string
	 */
	public static function generateSecureToken( int $length = 32 ): string {
		return bin2hex( random_bytes( $length / 2 ) );
	}

	/**
	 * Log security event
	 *
	 * @param string $event Event type
	 * @param array  $data  Additional data
	 */
	public static function logSecurityEvent( string $event, array $data = array() ) {
		$log_data = array_merge(
			$data,
			array(
				'user_id'    => get_current_user_id(),
				'user_ip'    => self::getClientIp(),
				'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
				'timestamp'  => current_time( 'mysql' ),
			)
		);

		PuntWorkLogger::info( "Security event: {$event}", PuntWorkLogger::CONTEXT_SECURITY, $log_data );
	}

	/**
	 * Get client IP address
	 *
	 * @return string Client IP
	 */
	public static function getClientIp(): string {
		$headers = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_CLIENT_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_FORWARDED',
			'HTTP_X_CLUSTER_CLIENT_IP',
			'HTTP_FORWARDED_FOR',
			'HTTP_FORWARDED',
			'REMOTE_ADDR',
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = trim( explode( ',', $_SERVER[ $header ] )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					return $ip;
				}
			}
		}

		return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
	}

	/**
	 * Check if request is from a trusted source (basic implementation)
	 *
	 * @return bool True if trusted
	 */
	public static function isTrustedRequest(): bool {
		// Check if request is from same domain
		$referer = wp_get_referer();
		if ( $referer ) {
			$site_url = get_site_url();
			return strpos( $referer, $site_url ) == 0;
		}

		return false;
	}

	/**
	 * Enhanced URL validation with security checks
	 *
	 * @param  string $url             URL to validate
	 * @param  array  $allowed_schemes Allowed URL schemes
	 * @return bool True if URL is valid and safe
	 */
	public static function validateSecureUrl( string $url, array $allowed_schemes = array( 'http', 'https' ) ): bool {
		// Basic URL validation
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		$parsed = parse_url( $url );
		if ( ! $parsed ) {
			return false;
		}

		// Check scheme
		if ( ! in_array( strtolower( $parsed['scheme'] ?? '' ), $allowed_schemes ) ) {
			return false;
		}

		// Check for suspicious patterns
		$suspicious_patterns = array(
			'/\.\./',  // Directory traversal
			'/localhost/i',
			'/127\.0\.0\.1/',
			'/0\.0\.0\.0/',
			'/169\.254\./',  // Link-local
			'/10\./',        // Private network
			'/172\.(1[6-9]|2[0-9]|3[0-1])\./', // Private network
			'/192\.168\./',  // Private network
		);

		foreach ( $suspicious_patterns as $pattern ) {
			if ( preg_match( $pattern, $url ) ) {
				return false;
			}
		}

		// Check host length (prevent very long hosts)
		if ( isset( $parsed['host'] ) && strlen( $parsed['host'] ) > 253 ) {
			return false;
		}

		// Additional security headers check for HTTPS
		if ( $parsed['scheme'] == 'https' ) {
			// Could add certificate validation here if needed
		}

		return true;
	}

	/**
	 * Validate and sanitize job data
	 *
	 * @param  array $job_data Raw job data
	 * @return array|WP_Error Sanitized data or error
	 */
	public static function validateJobData( array $job_data ) {
		$sanitized = array();
		$errors    = array();

		// Required fields validation
		$required_fields = array( 'job_title', 'job_desc' );
		foreach ( $required_fields as $field ) {
			if ( empty( $job_data[ $field ] ) ) {
				$errors[] = "Missing required field: {$field}";
				continue;
			}
		}

		if ( ! empty( $errors ) ) {
			return new \WP_Error( 'validation_error', 'Job data validation failed', $errors );
		}

		// Sanitize text fields
		$text_fields = array( 'job_title', 'job_desc', 'job_location', 'job_company', 'job_type' );
		foreach ( $text_fields as $field ) {
			if ( isset( $job_data[ $field ] ) ) {
				$sanitized[ $field ] = self::sanitizeText( $job_data[ $field ] );
			}
		}

		// Validate and sanitize URLs
		$url_fields = array( 'job_url', 'job_apply_url', 'company_url' );
		foreach ( $url_fields as $field ) {
			if ( isset( $job_data[ $field ] ) && ! empty( $job_data[ $field ] ) ) {
				if ( ! self::validateSecureUrl( $job_data[ $field ] ) ) {
					$errors[] = "Invalid URL in field: {$field}";
				} else {
					$sanitized[ $field ] = esc_url_raw( $job_data[ $field ] );
				}
			}
		}

		// Validate salary fields
		$salary_fields = array( 'job_salary_min', 'job_salary_max' );
		foreach ( $salary_fields as $field ) {
			if ( isset( $job_data[ $field ] ) ) {
				$salary = self::sanitizeNumeric( $job_data[ $field ] );
				if ( $salary !== null ) {
					$sanitized[ $field ] = $salary;
				}
			}
		}

		// Validate email
		if ( isset( $job_data['job_email'] ) && ! empty( $job_data['job_email'] ) ) {
			if ( ! is_email( $job_data['job_email'] ) ) {
				$errors[] = 'Invalid email address';
			} else {
				$sanitized['job_email'] = sanitize_email( $job_data['job_email'] );
			}
		}

		// Validate dates
		$date_fields = array( 'job_date_posted', 'job_date_expires' );
		foreach ( $date_fields as $field ) {
			if ( isset( $job_data[ $field ] ) && ! empty( $job_data[ $field ] ) ) {
				$timestamp = strtotime( $job_data[ $field ] );
				if ( $timestamp == false ) {
					$errors[] = "Invalid date format in field: {$field}";
				} else {
					$sanitized[ $field ] = date( 'Y-m-d H:i:s', $timestamp );
				}
			}
		}

		if ( ! empty( $errors ) ) {
			return new \WP_Error( 'validation_error', 'Job data validation failed', $errors );
		}

		return $sanitized;
	}

	/**
	 * Sanitize text input with length limits
	 *
	 * @param  string $text       Text to sanitize
	 * @param  int    $max_length Maximum length
	 * @return string Sanitized text
	 */
	private static function sanitizeText( string $text, int $max_length = 10000 ): string {
		$text = wp_strip_all_tags( $text );
		$text = sanitize_text_field( $text );
		if ( strlen( $text ) > $max_length ) {
			$text = substr( $text, 0, $max_length );
		}
		return $text;
	}

	/**
	 * Sanitize numeric input
	 *
	 * @param  mixed $value Value to sanitize
	 * @return float|null Sanitized number or null
	 */
	private static function sanitizeNumeric( $value ): ?float {
		if ( is_numeric( $value ) ) {
			$num = (float) $value;
			return $num >= 0 ? $num : null;
		}
		return null;
	}

	/**
	 * Validate feed data structure
	 *
	 * @param  array $feed_data Feed data to validate
	 * @return array|WP_Error Validated data or error
	 */
	public static function validateFeedData( array $feed_data ) {
		$errors = array();

		// Check for required feed metadata
		if ( empty( $feed_data['url'] ) ) {
			$errors[] = 'Feed URL is required';
		} elseif ( ! self::validateSecureUrl( $feed_data['url'] ) ) {
			$errors[] = 'Invalid feed URL';
		}

		if ( empty( $feed_data['format'] ) ) {
			$errors[] = 'Feed format is required';
		} elseif ( ! in_array( $feed_data['format'], array( 'xml', 'json', 'csv' ) ) ) {
			$errors[] = 'Unsupported feed format';
		}

		if ( ! empty( $errors ) ) {
			return new \WP_Error( 'feed_validation_error', 'Feed validation failed', $errors );
		}

		return array(
			'url'         => esc_url_raw( $feed_data['url'] ),
			'format'      => sanitize_text_field( $feed_data['format'] ),
			'name'        => sanitize_text_field( $feed_data['name'] ?? '' ),
			'description' => self::sanitizeText( $feed_data['description'] ?? '', 500 ),
		);
	}
}
