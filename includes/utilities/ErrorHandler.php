<?php

namespace Puntwork;

/**
 * Enhanced Error Handling and Recovery System for PuntWork.
 *
 * @since      1.0.1
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Puntwork\ErrorHandler' ) ) {
	class ErrorHandler {
		const ERROR_LEVEL_CRITICAL = 'critical';
		const ERROR_LEVEL_ERROR = 'error';
		const ERROR_LEVEL_WARNING = 'warning';
		const ERROR_LEVEL_INFO = 'info';
		const ERROR_LEVEL_DEBUG = 'debug';

		const ERROR_TYPE_SYSTEM = 'system';
		const ERROR_TYPE_DATABASE = 'database';
		const ERROR_TYPE_NETWORK = 'network';
		const ERROR_TYPE_VALIDATION = 'validation';
		const ERROR_TYPE_PROCESSING = 'processing';
		const ERROR_TYPE_CONFIGURATION = 'configuration';

		const RECOVERY_STRATEGY_RETRY = 'retry';
		const RECOVERY_STRATEGY_SKIP = 'skip';
		const RECOVERY_STRATEGY_ABORT = 'abort';
		const RECOVERY_STRATEGY_DEGRADE = 'degrade';
		const RECOVERY_STRATEGY_MANUAL = 'manual';

		private static $instance = null;
		private static $error_log = array();
		private static $recovery_strategies = array();
		private static $error_context = array();

		/**
		 * Get singleton instance
		 */
		public static function getInstance() {
			if ( self::$instance === null ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Constructor - initialize error handling
		 */
		public function __construct() {
			$this->initializeErrorHandling();
			$this->setupRecoveryStrategies();
		}

		/**
		 * Initialize comprehensive error handling
		 */
		private function initializeErrorHandling() {
			// Set up PHP error handling
			set_error_handler( array( $this, 'handlePHPErrors' ) );
			set_exception_handler( array( $this, 'handleUncaughtExceptions' ) );
			register_shutdown_function( array( $this, 'handleShutdown' ) );

			// Set up WordPress error handling
			add_action( 'wp_die_handler', array( $this, 'enhancedWPDieHandler' ) );
			add_action( 'shutdown', array( $this, 'handleWordPressShutdown' ) );
		}

		/**
		 * Set up recovery strategies for different error types
		 */
		private function setupRecoveryStrategies() {
			self::$recovery_strategies = array(
				self::ERROR_TYPE_DATABASE => array(
					'timeout' => array(
						'strategy' => self::RECOVERY_STRATEGY_RETRY,
						'max_retries' => 3,
						'retry_delay' => 1,
						'degradation' => 'reduce_batch_size'
					),
					'connection_lost' => array(
						'strategy' => self::RECOVERY_STRATEGY_RETRY,
						'max_retries' => 5,
						'retry_delay' => 2,
						'degradation' => 'switch_to_alternative_connection'
					),
					'deadlock' => array(
						'strategy' => self::RECOVERY_STRATEGY_RETRY,
						'max_retries' => 2,
						'retry_delay' => 0.5,
						'degradation' => 'reduce_concurrency'
					)
				),
				self::ERROR_TYPE_NETWORK => array(
					'timeout' => array(
						'strategy' => self::RECOVERY_STRATEGY_RETRY,
						'max_retries' => 3,
						'retry_delay' => 2,
						'degradation' => 'use_cached_data'
					),
					'connection_failed' => array(
						'strategy' => self::RECOVERY_STRATEGY_SKIP,
						'alternative' => 'skip_feed'
					),
					'ssl_error' => array(
						'strategy' => self::RECOVERY_STRATEGY_DEGRADE,
						'degradation' => 'disable_ssl_verification'
					)
				),
				self::ERROR_TYPE_PROCESSING => array(
					'memory_exhausted' => array(
						'strategy' => self::RECOVERY_STRATEGY_DEGRADE,
						'degradation' => 'reduce_chunk_size'
					),
					'timeout' => array(
						'strategy' => self::RECOVERY_STRATEGY_DEGRADE,
						'degradation' => 'pause_and_resume'
					),
					'invalid_data' => array(
						'strategy' => self::RECOVERY_STRATEGY_SKIP,
						'alternative' => 'log_and_continue'
					)
				),
				self::ERROR_TYPE_VALIDATION => array(
					'schema_mismatch' => array(
						'strategy' => self::RECOVERY_STRATEGY_DEGRADE,
						'degradation' => 'use_fallback_schema'
					),
					'missing_required_field' => array(
						'strategy' => self::RECOVERY_STRATEGY_SKIP,
						'alternative' => 'use_default_value'
					)
				),
				self::ERROR_TYPE_CONFIGURATION => array(
					'missing_config' => array(
						'strategy' => self::RECOVERY_STRATEGY_DEGRADE,
						'degradation' => 'use_defaults'
					),
					'invalid_config' => array(
						'strategy' => self::RECOVERY_STRATEGY_ABORT,
						'requires_manual' => true
					)
				)
			);
		}

		/**
		 * Enhanced exception handling with recovery
		 *
		 * @param string $operation Operation name
		 * @param callable $operation_callback Operation to execute
		 * @param array $context Additional context
		 * @return mixed Operation result or false on failure
		 */
		public static function executeWithRecovery( string $operation, callable $operation_callback, array $context = array() ) {
			$start_time = microtime( true );
			$attempt = 0;
			$max_attempts = 3;

			self::setContext( 'operation', $operation );
			self::setContext( 'start_time', $start_time );

			while ( $attempt < $max_attempts ) {
				try {
					$result = $operation_callback();

					// Success - clear any previous errors for this operation
					self::clearOperationErrors( $operation );

					return $result;

				} catch ( \Exception $e ) {
					$attempt++;
					$error_details = self::analyzeError( $e, $context );

					// Log the error
					self::logError( $error_details );

					// Try recovery strategy
					$recovery_result = self::attemptRecovery( $error_details, $attempt, $max_attempts );

					if ( $recovery_result['success'] ) {
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( sprintf(
								'[PUNTWORK] [RECOVERY] Recovery successful for %s on attempt %d',
								$operation, $attempt
							) );
						}
						continue; // Retry the operation
					} else {
						// Recovery failed or not possible
						if ( $recovery_result['should_abort'] ) {
							self::handleUnrecoverableError( $error_details );
							return false;
						}

						// Try degraded operation if available
						if ( isset( $recovery_result['degraded_callback'] ) ) {
							try {
								$result = $recovery_result['degraded_callback']();
								if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
									error_log( sprintf(
										'[PUNTWORK] [DEGRADATION] Degraded operation successful for %s',
										$operation
									) );
								}
								return $result;
							} catch ( \Exception $degraded_e ) {
								// Degraded operation also failed
								self::logError( self::analyzeError( $degraded_e, $context ) );
							}
						}
					}

					// If we get here, all recovery attempts failed
					break;
				}
			}

			// All attempts failed
			$error_message = sprintf(
				'Operation %s failed after %d attempts',
				$operation, $max_attempts
			);

			self::logError( array(
				'level' => self::ERROR_LEVEL_CRITICAL,
				'type' => self::ERROR_TYPE_SYSTEM,
				'message' => $error_message,
				'operation' => $operation,
				'attempts' => $max_attempts,
				'duration' => microtime( true ) - $start_time
			) );

			return false;
		}

		/**
		 * Analyze error and determine type and recovery strategy
		 */
		private static function analyzeError( \Throwable $e, array $context = array() ) {
			$error_message = $e->getMessage();
			$error_code = $e->getCode();
			$file = $e->getFile();
			$line = $e->getLine();
			$trace = $e->getTraceAsString();

			// Determine error type based on message patterns
			$error_type = self::classifyError( $error_message, $context );

			// Determine error level
			$error_level = self::determineErrorLevel( $error_type, $error_message );

			// Get recovery strategy
			$recovery_strategy = self::getRecoveryStrategy( $error_type, $error_message );

			return array(
				'level' => $error_level,
				'type' => $error_type,
				'message' => $error_message,
				'code' => $error_code,
				'file' => $file,
				'line' => $line,
				'trace' => $trace,
				'context' => $context,
				'recovery_strategy' => $recovery_strategy,
				'timestamp' => time()
			);
		}

		/**
		 * Classify error type based on message and context
		 */
		private static function classifyError( string $message, array $context ) {
			$message_lower = strtolower( $message );

			// Database errors
			if ( strpos( $message_lower, 'mysql' ) !== false ||
				 strpos( $message_lower, 'database' ) !== false ||
				 strpos( $message_lower, 'sql' ) !== false ||
				 strpos( $message_lower, 'deadlock' ) !== false ||
				 strpos( $message_lower, 'connection' ) !== false ) {
				return self::ERROR_TYPE_DATABASE;
			}

			// Network errors
			if ( strpos( $message_lower, 'http' ) !== false ||
				 strpos( $message_lower, 'curl' ) !== false ||
				 strpos( $message_lower, 'timeout' ) !== false ||
				 strpos( $message_lower, 'connection' ) !== false ||
				 strpos( $message_lower, 'ssl' ) !== false ||
				 isset( $context['url'] ) ) {
				return self::ERROR_TYPE_NETWORK;
			}

			// Memory/processing errors
			if ( strpos( $message_lower, 'memory' ) !== false ||
				 strpos( $message_lower, 'timeout' ) !== false ||
				 strpos( $message_lower, 'exhausted' ) !== false ||
				 strpos( $message_lower, 'limit' ) !== false ) {
				return self::ERROR_TYPE_PROCESSING;
			}

			// Validation errors
			if ( strpos( $message_lower, 'invalid' ) !== false ||
				 strpos( $message_lower, 'missing' ) !== false ||
				 strpos( $message_lower, 'required' ) !== false ||
				 strpos( $message_lower, 'validation' ) !== false ) {
				return self::ERROR_TYPE_VALIDATION;
			}

			// Configuration errors
			if ( strpos( $message_lower, 'config' ) !== false ||
				 strpos( $message_lower, 'setting' ) !== false ||
				 strpos( $message_lower, 'option' ) !== false ) {
				return self::ERROR_TYPE_CONFIGURATION;
			}

			return self::ERROR_TYPE_SYSTEM;
		}

		/**
		 * Determine error level based on type and message
		 */
		private static function determineErrorLevel( string $error_type, string $message ) {
			$message_lower = strtolower( $message );

			// Critical errors
			if ( strpos( $message_lower, 'fatal' ) !== false ||
				 strpos( $message_lower, 'critical' ) !== false ||
				 $error_type === self::ERROR_TYPE_CONFIGURATION ) {
				return self::ERROR_LEVEL_CRITICAL;
			}

			// Error level
			if ( strpos( $message_lower, 'error' ) !== false ||
				 $error_type === self::ERROR_TYPE_DATABASE ) {
				return self::ERROR_LEVEL_ERROR;
			}

			// Warning level
			if ( strpos( $message_lower, 'warning' ) !== false ||
				 strpos( $message_lower, 'deprecated' ) !== false ) {
				return self::ERROR_LEVEL_WARNING;
			}

			return self::ERROR_LEVEL_ERROR; // Default to error
		}

		/**
		 * Get recovery strategy for error type
		 */
		private static function getRecoveryStrategy( string $error_type, string $message ) {
			if ( ! isset( self::$recovery_strategies[ $error_type ] ) ) {
				return array(
					'strategy' => self::RECOVERY_STRATEGY_ABORT,
					'reason' => 'no_strategy_defined'
				);
			}

			$message_lower = strtolower( $message );

			foreach ( self::$recovery_strategies[ $error_type ] as $pattern => $strategy ) {
				if ( strpos( $message_lower, $pattern ) !== false ) {
					return $strategy;
				}
			}

			// Return default strategy for error type
			return array(
				'strategy' => self::RECOVERY_STRATEGY_ABORT,
				'reason' => 'no_matching_pattern'
			);
		}

		/**
		 * Attempt error recovery
		 */
		private static function attemptRecovery( array $error_details, int $attempt, int $max_attempts ) {
			$strategy = $error_details['recovery_strategy'];

			switch ( $strategy['strategy'] ) {
				case self::RECOVERY_STRATEGY_RETRY:
					if ( $attempt < ( $strategy['max_retries'] ?? 3 ) ) {
						$delay = $strategy['retry_delay'] ?? 1;
						sleep( $delay );
						return array( 'success' => true, 'action' => 'retry' );
					}
					break;

				case self::RECOVERY_STRATEGY_SKIP:
					return array(
						'success' => false,
						'should_abort' => false,
						'action' => 'skip',
						'alternative' => $strategy['alternative'] ?? null
					);

				case self::RECOVERY_STRATEGY_DEGRADE:
					$degraded_callback = self::getDegradedOperation( $strategy['degradation'] ?? null, $error_details );
					if ( $degraded_callback ) {
						return array(
							'success' => false,
							'should_abort' => false,
							'action' => 'degrade',
							'degraded_callback' => $degraded_callback
						);
					}
					break;

				case self::RECOVERY_STRATEGY_ABORT:
				default:
					return array( 'success' => false, 'should_abort' => true, 'action' => 'abort' );
			}

			return array( 'success' => false, 'should_abort' => true, 'action' => 'abort' );
		}

		/**
		 * Get degraded operation callback
		 */
		private static function getDegradedOperation( $degradation_type, array $error_details ) {
			switch ( $degradation_type ) {
				case 'reduce_batch_size':
					return function() {
						// Reduce batch size and retry
						add_option( 'puntwork_degraded_batch_size', get_option( 'job_import_batch_size', 1 ) / 2 );
						return true; // Signal to retry with smaller batch
					};

				case 'reduce_chunk_size':
					return function() {
						// Reduce chunk size for memory operations
						\Puntwork\Utilities\AdvancedMemoryManager::setOptimalChunkSize(
							\Puntwork\Utilities\AdvancedMemoryManager::getOptimalChunkSize() / 2
						);
						return true;
					};

				case 'use_cached_data':
					return function() use ( $error_details ) {
						// Try to use cached data instead of fresh fetch
						$context = $error_details['context'];
						if ( isset( $context['cache_key'] ) ) {
							return get_transient( $context['cache_key'] );
						}
						return false;
					};

				case 'pause_and_resume':
					return function() {
						// Pause current operation and schedule resume
						update_option( 'puntwork_operation_paused', true );
						wp_schedule_single_event( time() + 60, 'puntwork_resume_operation' );
						return true;
					};

				default:
					return null;
			}
		}

		/**
		 * Handle unrecoverable errors
		 */
		private static function handleUnrecoverableError( array $error_details ) {
			// Log critical error
			self::logError( array_merge( $error_details, array(
				'level' => self::ERROR_LEVEL_CRITICAL,
				'recovered' => false
			) ) );

			// Send admin notification if configured
			if ( function_exists( 'get_option' ) && function_exists( 'update_option' ) ) {
				self::notifyAdminOfCriticalError( $error_details );

				// Set system status
				update_option( 'puntwork_system_error', array(
					'error' => $error_details,
					'timestamp' => time(),
					'requires_attention' => true
				) );
			}
		}

		/**
		 * Log error with comprehensive details
		 */
		public static function logError( array $error_details ) {
			$log_entry = array_merge( $error_details, array(
				'id' => uniqid( 'error_', true ),
				'session_id' => session_id() ?: 'no_session',
				'user_id' => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
				'memory_usage' => memory_get_usage( true ),
				'peak_memory' => memory_get_peak_usage( true ),
				'php_version' => PHP_VERSION,
				'wp_version' => function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : 'unknown',
				'server_info' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown'
			) );

			self::$error_log[] = $log_entry;

			// Keep only last 1000 errors in memory
			if ( count( self::$error_log ) > 1000 ) {
				array_shift( self::$error_log );
			}

			// Write to WordPress error log
			$log_message = sprintf(
				'[PUNTWORK] [%s] %s: %s (File: %s:%d)',
				strtoupper( $error_details['level'] ),
				$error_details['type'],
				$error_details['message'],
				$error_details['file'] ?? 'unknown',
				$error_details['line'] ?? 0
			);

			error_log( $log_message );

			// Store persistent error log for critical errors
			if ( $error_details['level'] === self::ERROR_LEVEL_CRITICAL ) {
				$persistent_errors = get_option( 'puntwork_critical_errors', array() );
				$persistent_errors[] = $log_entry;

				// Keep only last 50 critical errors
				if ( count( $persistent_errors ) > 50 ) {
					$persistent_errors = array_slice( $persistent_errors, -50 );
				}

				update_option( 'puntwork_critical_errors', $persistent_errors );
			}
		}

		/**
		 * Set error context
		 */
		public static function setContext( string $key, $value ) {
			self::$error_context[ $key ] = $value;
		}

		/**
		 * Get error context
		 */
		public static function getContext( ?string $key = null ) {
			if ( $key === null ) {
				return self::$error_context;
			}
			return self::$error_context[ $key ] ?? null;
		}

		/**
		 * Clear operation errors
		 */
		private static function clearOperationErrors( string $operation ) {
			self::$error_log = array_filter( self::$error_log, function( $error ) use ( $operation ) {
				return ( $error['operation'] ?? '' ) !== $operation;
			} );
		}

		/**
		 * Get error statistics
		 */
		public static function getErrorStats() {
			$stats = array(
				'total_errors' => count( self::$error_log ),
				'by_level' => array(),
				'by_type' => array(),
				'recent_errors' => array_slice( self::$error_log, -10 ),
				'critical_errors' => function_exists( 'get_option' ) ? get_option( 'puntwork_critical_errors', array() ) : array()
			);

			foreach ( self::$error_log as $error ) {
				$level = $error['level'] ?? 'unknown';
				$type = $error['type'] ?? 'unknown';

				$stats['by_level'][ $level ] = ( $stats['by_level'][ $level ] ?? 0 ) + 1;
				$stats['by_type'][ $type ] = ( $stats['by_type'][ $type ] ?? 0 ) + 1;
			}

			return $stats;
		}

		/**
		 * Notify admin of critical errors
		 */
		private static function notifyAdminOfCriticalError( array $error_details ) {
			if ( ! function_exists( 'get_option' ) || ! function_exists( 'wp_mail' ) ) {
				return; // WordPress not loaded yet
			}

			if ( ! get_option( 'puntwork_error_notifications_enabled', true ) ) {
				return;
			}

			$admin_email = get_option( 'admin_email' );
			$subject = sprintf( '[PuntWork] Critical Error: %s', $error_details['type'] );

			$message = sprintf(
				"A critical error has occurred in PuntWork:\n\n" .
				"Error Type: %s\n" .
				"Message: %s\n" .
				"File: %s:%d\n" .
				"Time: %s\n\n" .
				"Please check the error logs for more details.",
				$error_details['type'],
				$error_details['message'],
				$error_details['file'] ?? 'unknown',
				$error_details['line'] ?? 0,
				date( 'Y-m-d H:i:s', $error_details['timestamp'] )
			);

			wp_mail( $admin_email, $subject, $message );
		}

		/**
		 * PHP error handler
		 */
		public function handlePHPErrors( $errno, $errstr, $errfile, $errline ) {
			// Convert PHP errors to exceptions for consistent handling
			if ( ! ( error_reporting() & $errno ) ) {
				return false;
			}

			$error_details = array(
				'level' => self::ERROR_LEVEL_ERROR,
				'type' => self::ERROR_TYPE_SYSTEM,
				'message' => $errstr,
				'file' => $errfile,
				'line' => $errline,
				'php_error_code' => $errno,
				'context' => self::getContext()
			);

			self::logError( $error_details );

			// Don't execute PHP's internal error handler
			return true;
		}

		/**
		 * Handle uncaught exceptions
		 */
		public function handleUncaughtExceptions( \Throwable $exception ) {
			$error_details = self::analyzeError( $exception, self::getContext() );
			$error_details['level'] = self::ERROR_LEVEL_CRITICAL;

			self::logError( $error_details );
			self::handleUnrecoverableError( $error_details );

			// Show user-friendly error page
			if ( ! is_admin() ) {
				wp_die(
					'An unexpected error occurred. Please try again later.',
					'System Error',
					array( 'response' => 500 )
				);
			}
		}

		/**
		 * Handle shutdown (fatal errors)
		 */
		public function handleShutdown() {
			$error = error_get_last();
			if ( $error !== null && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ) ) ) {
				$error_details = array(
					'level' => self::ERROR_LEVEL_CRITICAL,
					'type' => self::ERROR_TYPE_SYSTEM,
					'message' => $error['message'],
					'file' => $error['file'],
					'line' => $error['line'],
					'fatal_error' => true,
					'context' => self::getContext()
				);

				self::logError( $error_details );
				self::handleUnrecoverableError( $error_details );
			}
		}

		/**
		 * Enhanced WordPress die handler
		 */
		public function enhancedWPDieHandler( $message, $title = '', $args = array() ) {
			// Log WordPress die events
			$error_details = array(
				'level' => self::ERROR_LEVEL_ERROR,
				'type' => self::ERROR_TYPE_SYSTEM,
				'message' => 'WordPress die: ' . $message,
				'wp_die_title' => $title,
				'wp_die_args' => $args,
				'context' => self::getContext()
			);

			self::logError( $error_details );

			// Return original handler
			return '_default_wp_die_handler';
		}

		/**
		 * Handle WordPress shutdown
		 */
		public function handleWordPressShutdown() {
			if ( ! function_exists( 'update_option' ) || ! function_exists( 'get_option' ) ) {
				return; // WordPress not loaded
			}

			// Check for any remaining errors
			$stats = self::getErrorStats();
			if ( ! empty( $stats['critical_errors'] ) ) {
				// System has critical errors - could trigger maintenance mode
				update_option( 'puntwork_system_health', 'critical' );
			} elseif ( $stats['total_errors'] > 10 ) {
				// High error rate - degraded mode
				update_option( 'puntwork_system_health', 'degraded' );
			} else {
				// System healthy
				update_option( 'puntwork_system_health', 'healthy' );
			}
		}
	}

	// Initialize the error handler
	ErrorHandler::getInstance();
}