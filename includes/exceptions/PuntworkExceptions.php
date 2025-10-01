<?php

/**
 * Custom Exception Classes for PuntWork.
 *
 * @since      1.0.1
 */

namespace Puntwork\Exceptions;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base PuntWork Exception
 */
class PuntworkException extends \Exception {
	protected $error_type;
	protected $context;
	protected $recovery_suggestions;

	public function __construct( $message = '', $code = 0, ?\Throwable $previous = null, $context = array(), $recovery_suggestions = array() ) {
		parent::__construct( $message, $code, $previous );
		$this->context = $context;
		$this->recovery_suggestions = $recovery_suggestions;
		$this->error_type = 'general';
	}

	public function getErrorType() {
		return $this->error_type;
	}

	public function getContext() {
		return $this->context;
	}

	public function getRecoverySuggestions() {
		return $this->recovery_suggestions;
	}

	public function getDetailedMessage() {
		$message = $this->getMessage();
		if ( ! empty( $this->context ) ) {
			$message .= ' | Context: ' . json_encode( $this->context );
		}
		return $message;
	}
}

/**
 * Database-related exceptions
 */
class DatabaseException extends PuntworkException {
	public function __construct( $message = '', $code = 0, \Throwable $previous = null, $context = array() ) {
		parent::__construct( $message, $code, $previous, $context, array(
			'Check database connection settings',
			'Verify table permissions',
			'Check for database locks or deadlocks',
			'Consider increasing timeout values'
		) );
		$this->error_type = 'database';
	}
}

class DatabaseConnectionException extends DatabaseException {
	public function __construct( $message = 'Database connection failed', $code = 0, \Throwable $previous = null, $context = array() ) {
		parent::__construct( $message, $code, $previous, $context );
		$this->recovery_suggestions = array(
			'Verify database credentials',
			'Check database server status',
			'Review firewall settings',
			'Try alternative connection method'
		);
	}
}

class DatabaseQueryException extends DatabaseException {
	public function __construct( $message = 'Database query failed', $code = 0, \Throwable $previous = null, $context = array() ) {
		parent::__construct( $message, $code, $previous, $context );
		$this->recovery_suggestions = array(
			'Validate SQL syntax',
			'Check table and column existence',
			'Verify data types and constraints',
			'Review query performance'
		);
	}
}

class DatabaseDeadlockException extends DatabaseException {
	public function __construct( $message = 'Database deadlock detected', $code = 0, \Throwable $previous = null, $context = array() ) {
		parent::__construct( $message, $code, $previous, $context );
		$this->recovery_suggestions = array(
			'Reduce concurrent operations',
			'Implement retry logic with exponential backoff',
			'Optimize transaction scope',
			'Consider table locking strategy'
		);
	}
}

/**
 * Network-related exceptions
 */
class NetworkException extends PuntworkException {
	public function __construct( $message = '', $code = 0, \Throwable $previous = null, $context = array() ) {
		parent::__construct( $message, $code, $previous, $context, array(
			'Check network connectivity',
			'Verify URL accessibility',
			'Review proxy settings',
			'Check SSL/TLS configuration'
		) );
		$this->error_type = 'network';
	}
}

class HTTPException extends NetworkException {
	protected $http_code;
	protected $url;

	public function __construct( $message = '', $http_code = 0, $url = '', $code = 0, \Throwable $previous = null, $context = array() ) {
		parent::__construct( $message, $code, $previous, $context );
		$this->http_code = $http_code;
		$this->url = $url;

		$this->recovery_suggestions = array(
			"Check HTTP status code: {$http_code}",
			'Verify URL: ' . $url,
			'Review authentication credentials',
			'Check rate limiting',
			'Consider using cached data'
		);
	}

	public function getHttpCode() {
		return $this->http_code;
	}

	public function getUrl() {
		return $this->url;
	}
}

class TimeoutException extends NetworkException {
	public function __construct( $message = 'Operation timed out', $code = 0, \Throwable $previous = null, $context = array() ) {
		parent::__construct( $message, $code, $previous, $context );
		$this->recovery_suggestions = array(
			'Increase timeout values',
			'Implement retry logic',
			'Use asynchronous processing',
			'Consider breaking operation into smaller chunks'
		);
	}
}

class SSLException extends NetworkException {
	public function __construct( $message = 'SSL/TLS error occurred', $code = 0, \Throwable $previous = null, $context = array() ) {
		parent::__construct( $message, $code, $previous, $context );
		$this->recovery_suggestions = array(
			'Verify SSL certificate validity',
			'Check certificate chain',
			'Review SSL/TLS configuration',
			'Consider disabling SSL verification (not recommended for production)'
		);
	}
}

/**
 * Processing-related exceptions
 */
class ProcessingException extends PuntworkException {
	public function __construct( $message = '', $code = 0, \Throwable $previous = null, $context = array() ) {
		parent::__construct( $message, $code, $previous, $context, array(
			'Check system resources (memory, CPU)',
			'Review data format and size',
			'Implement chunked processing',
			'Consider background processing'
		) );
		$this->error_type = 'processing';
	}
}

class MemoryException extends ProcessingException {
	public function __construct( $message = 'Memory limit exceeded', $code = 0, \Throwable $previous = null, $context = array() ) {
		parent::__construct( $message, $code, $previous, $context );
		$this->recovery_suggestions = array(
			'Increase PHP memory limit',
			'Process data in smaller chunks',
			'Implement streaming processing',
			'Use temporary files for large datasets'
		);
	}
}

class TimeoutProcessingException extends ProcessingException {
	public function __construct( $message = 'Processing timed out', $code = 0, \Throwable $previous = null, $context = array() ) {
		parent::__construct( $message, $code, $previous, $context );
		$this->recovery_suggestions = array(
			'Increase execution time limit',
			'Break processing into smaller batches',
			'Implement resumable processing',
			'Use background jobs'
		);
	}
}

/**
 * Validation-related exceptions
 */
class ValidationException extends PuntworkException {
	public function __construct( $message = '', $code = 0, \Throwable $previous = null, $context = array() ) {
		parent::__construct( $message, $code, $previous, $context, array(
			'Validate input data format',
			'Check required fields',
			'Review data constraints',
			'Use default values where appropriate'
		) );
		$this->error_type = 'validation';
	}
}

class SchemaValidationException extends ValidationException {
	public function __construct( $message = 'Data schema validation failed', $code = 0, \Throwable $previous = null, $context = array() ) {
		parent::__construct( $message, $code, $previous, $context );
		$this->recovery_suggestions = array(
			'Update data mapping configuration',
			'Use fallback schema',
			'Skip invalid records',
			'Log validation errors for review'
		);
	}
}

class DataIntegrityException extends ValidationException {
	public function __construct( $message = 'Data integrity check failed', $code = 0, \Throwable $previous = null, $context = array() ) {
		parent::__construct( $message, $code, $previous, $context );
		$this->recovery_suggestions = array(
			'Verify data source integrity',
			'Implement data cleansing',
			'Use data validation rules',
			'Consider manual data review'
		);
	}
}

/**
 * Configuration-related exceptions
 */
class ConfigurationException extends PuntworkException {
	public function __construct( $message = '', $code = 0, \Throwable $previous = null, $context = array() ) {
		parent::__construct( $message, $code, $previous, $context, array(
			'Review configuration settings',
			'Check file permissions',
			'Validate configuration file syntax',
			'Use default configuration values'
		) );
		$this->error_type = 'configuration';
	}
}

class MissingConfigurationException extends ConfigurationException {
	public function __construct( $message = 'Required configuration missing', $code = 0, \Throwable $previous = null, $context = array() ) {
		parent::__construct( $message, $code, $previous, $context );
		$this->recovery_suggestions = array(
			'Add missing configuration values',
			'Use environment variables',
			'Implement configuration wizard',
			'Apply default settings'
		);
	}
}

/**
 * Import-specific exceptions
 */
class ImportException extends PuntworkException {
	public function __construct( $message = '', $code = 0, \Throwable $previous = null, $context = array() ) {
		parent::__construct( $message, $code, $previous, $context, array(
			'Check import data format',
			'Verify feed accessibility',
			'Review import settings',
			'Monitor system resources during import'
		) );
		$this->error_type = 'import';
	}
}

class FeedProcessingException extends ImportException {
	public function __construct( $message = 'Feed processing failed', $code = 0, \Throwable $previous = null, $context = array() ) {
		parent::__construct( $message, $code, $previous, $context );
		$this->recovery_suggestions = array(
			'Validate feed URL and format',
			'Check feed authentication',
			'Implement feed retry logic',
			'Use cached feed data'
		);
	}
}

class JobMappingException extends ImportException {
	public function __construct( $message = 'Job data mapping failed', $code = 0, \Throwable $previous = null, $context = array() ) {
		parent::__construct( $message, $code, $previous, $context );
		$this->recovery_suggestions = array(
			'Update field mapping configuration',
			'Review job data structure',
			'Implement flexible mapping',
			'Skip unmappable records'
		);
	}
}

/**
 * Batch processing exceptions
 */
class BatchProcessingException extends ProcessingException {
	public function __construct( $message = 'Batch processing failed', $code = 0, \Throwable $previous = null, $context = array() ) {
		parent::__construct( $message, $code, $previous, $context );
		$this->recovery_suggestions = array(
			'Reduce batch size',
			'Implement batch retry logic',
			'Process batches sequentially',
			'Monitor batch progress and failures'
		);
	}
}

class BatchRollbackException extends BatchProcessingException {
	public function __construct( $message = 'Batch rollback failed', $code = 0, \Throwable $previous = null, $context = array() ) {
		parent::__construct( $message, $code, $previous, $context );
		$this->recovery_suggestions = array(
			'Check database transaction status',
			'Implement manual rollback procedures',
			'Review batch transaction boundaries',
			'Consider partial rollback strategies'
		);
	}
}