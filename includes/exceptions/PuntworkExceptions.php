<?php

namespace Puntwork;

/**
 * Custom exception classes for PuntWork plugin.
 *
 * @since      1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base PuntWork exception class
 */
class PuntworkException extends \Exception {
	protected $error_code;
	protected $error_data;

	public function __construct( $message = '', $code = 0, $error_code = '', $error_data = array(), \Throwable $previous = null ) {
		parent::__construct( $message, $code, $previous );
		$this->error_code = $error_code;
		$this->error_data = $error_data;
	}

	public function getErrorCode() {
		return $this->error_code;
	}

	public function getErrorData() {
		return $this->error_data;
	}
}

/**
 * Database-related exceptions
 */
class PuntworkDatabaseException extends PuntworkException {
	public function __construct( $message = 'Database error', $error_data = array(), \Throwable $previous = null ) {
		parent::__construct( $message, 0, 'database_error', $error_data, $previous );
	}
}

/**
 * Network-related exceptions
 */
class PuntworkNetworkException extends PuntworkException {
	public function __construct( $message = 'Network error', $error_data = array(), \Throwable $previous = null ) {
		parent::__construct( $message, 0, 'network_error', $error_data, $previous );
	}
}

/**
 * Validation-related exceptions
 */
class PuntworkValidationException extends PuntworkException {
	public function __construct( $message = 'Validation error', $error_data = array(), \Throwable $previous = null ) {
		parent::__construct( $message, 0, 'validation_error', $error_data, $previous );
	}
}

/**
 * Configuration-related exceptions
 */
class PuntworkConfigurationException extends PuntworkException {
	public function __construct( $message = 'Configuration error', $error_data = array(), \Throwable $previous = null ) {
		parent::__construct( $message, 0, 'configuration_error', $error_data, $previous );
	}
}

/**
 * Processing-related exceptions
 */
class PuntworkProcessingException extends PuntworkException {
	public function __construct( $message = 'Processing error', $error_data = array(), \Throwable $previous = null ) {
		parent::__construct( $message, 0, 'processing_error', $error_data, $previous );
	}
}

/**
 * Import-specific exceptions
 */
class PuntworkImportException extends PuntworkException {
	public function __construct( $message = 'Import error', $error_data = array(), \Throwable $previous = null ) {
		parent::__construct( $message, 0, 'import_error', $error_data, $previous );
	}
}

/**
 * Feed-specific exceptions
 */
class PuntworkFeedException extends PuntworkException {
	public function __construct( $message = 'Feed error', $error_data = array(), \Throwable $previous = null ) {
		parent::__construct( $message, 0, 'feed_error', $error_data, $previous );
	}
}