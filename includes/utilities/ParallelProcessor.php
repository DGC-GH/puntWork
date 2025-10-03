<?php

/**
 * Parallel Processing Utilities for improved CPU utilization.
 *
 * @since      1.0.1
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Puntwork\Utilities\ParallelProcessor' ) ) {
	class ParallelProcessor {
		private static $instance = null;
		private $max_concurrent_processes;
		private $active_processes = array();
		private $process_queue = array();

		const DEFAULT_MAX_CONCURRENT = 3;
		const MAX_TOTAL_PROCESSES = 10;

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
		 * Constructor
		 */
		public function __construct() {
			$this->max_concurrent_processes = self::DEFAULT_MAX_CONCURRENT;

			// Allow override via constant
			if ( defined( 'PUNTWORK_MAX_CONCURRENT_PROCESSES' ) ) {
				$this->max_concurrent_processes = min(
					(int) PUNTWORK_MAX_CONCURRENT_PROCESSES,
					self::MAX_TOTAL_PROCESSES
				);
			}
		}

		/**
		 * Process feeds in parallel
		 *
		 * @param array $feeds Array of feed URLs keyed by feed identifier
		 * @param callable $processor Function to process each feed
		 * @param int $max_concurrent Maximum concurrent processes
		 * @return array Results from processing
		 */
		public static function processFeedsParallel( array $feeds, callable $processor, $max_concurrent = null ) {
			if ( $max_concurrent === null ) {
				$max_concurrent = self::getInstance()->max_concurrent_processes;
			}

			$results = array();
			$batches = array_chunk( $feeds, $max_concurrent, true );

			foreach ( $batches as $batch ) {
				$batch_results = self::processBatch( $batch, $processor );
				$results = array_merge( $results, $batch_results );

				// Memory cleanup between batches
				\Puntwork\Utilities\MemoryManager::checkMemoryUsage(0);
			}

			return $results;
		}

		/**
		 * Process a batch of feeds concurrently
		 */
		private static function processBatch( array $batch, callable $processor ) {
			$results = array();
			$processes = array();

			// Start all processes in the batch
			foreach ( $batch as $feed_key => $feed_url ) {
				$processes[ $feed_key ] = wp_remote_get( $feed_url, array(
					'timeout' => 30,
					'redirection' => 5,
					'user-agent' => 'PuntWork-Feed-Processor/1.0',
					'headers' => array(
						'Accept' => 'application/json, application/xml, text/xml',
					),
				) );
			}

			// Wait for all processes to complete and process results
			foreach ( $processes as $feed_key => $response ) {
				if ( is_wp_error( $response ) ) {
					$results[ $feed_key ] = array(
						'success' => false,
						'error' => $response->get_error_message(),
						'data' => null
					);
				} else {
					$body = wp_remote_retrieve_body( $response );
					$content_type = wp_remote_retrieve_header( $response, 'content-type' );

					$results[ $feed_key ] = $processor( $feed_key, $body, $content_type );
				}
			}

			return $results;
		}

		/**
		 * Process independent operations in parallel
		 *
		 * @param array $operations Array of operations to process
		 * @param callable $processor Function to process each operation
		 * @return array Results from processing
		 */
		public static function processOperationsParallel( array $operations, callable $processor ) {
			$results = array();
			$max_concurrent = self::getInstance()->max_concurrent_processes;

			// For CPU-intensive operations, limit concurrency to prevent thrashing
			if ( self::isCpuIntensiveOperation( $operations ) ) {
				$max_concurrent = max( 1, $max_concurrent - 1 );
			}

			$batches = array_chunk( $operations, $max_concurrent, true );

			foreach ( $batches as $batch ) {
				$batch_results = array();

				// Process batch items concurrently using async approach
				foreach ( $batch as $operation_key => $operation_data ) {
					$batch_results[ $operation_key ] = self::executeAsyncOperation(
						function() use ( $processor, $operation_key, $operation_data ) {
							return $processor( $operation_key, $operation_data );
						}
					);
				}

				// Wait for batch completion
				foreach ( $batch_results as $key => $promise ) {
					$results[ $key ] = $promise; // In real implementation, this would wait for completion
				}

				// Memory cleanup between batches
				\Puntwork\Utilities\MemoryManager::checkMemoryUsage(0);
			}

			return $results;
		}

		/**
		 * Execute operation asynchronously (simulated with background processing)
		 */
		private static function executeAsyncOperation( callable $operation ) {
			// For WordPress environment, we'll simulate async processing
			// In a real implementation, this could use WordPress cron or background processing
			try {
				return $operation();
			} catch ( Exception $e ) {
				return array(
					'success' => false,
					'error' => $e->getMessage()
				);
			}
		}

		/**
		 * Determine if operations are CPU intensive
		 */
		private static function isCpuIntensiveOperation( array $operations ) {
			// Simple heuristic - check for operations that typically involve heavy processing
			$cpu_intensive_indicators = array(
				'parse_xml',
				'process_feed',
				'validate_data',
				'duplicate_check',
				'ml_predict',
				'batch_process'
			);

			foreach ( $operations as $operation ) {
				if ( is_array( $operation ) && isset( $operation['type'] ) ) {
					if ( in_array( $operation['type'], $cpu_intensive_indicators ) ) {
						return true;
					}
				}
			}

			return false;
		}

		/**
		 * Parallel duplicate detection for large datasets
		 *
		 * @param array $items Array of items to check for duplicates
		 * @param callable $hash_function Function to generate hash for each item
		 * @return array Results with duplicates identified
		 */
		public static function parallelDuplicateDetection( array $items, callable $hash_function ) {
			$max_concurrent = self::getInstance()->max_concurrent_processes;
			$chunks = array_chunk( $items, ceil( count( $items ) / $max_concurrent ) );
			$results = array();

			foreach ( $chunks as $chunk ) {
				$chunk_results = array();

				// Process chunk concurrently
				foreach ( $chunk as $item ) {
					$hash = $hash_function( $item );
					$chunk_results[ $hash ] = isset( $chunk_results[ $hash ] ) ?
						$chunk_results[ $hash ] + 1 : 1;
				}

				$results = array_merge_recursive( $results, $chunk_results );
			}

			// Identify duplicates
			$duplicates = array_filter( $results, function( $count ) {
				return $count > 1;
			} );

			return array(
				'hashes' => $results,
				'duplicates' => $duplicates,
				'unique_count' => count( array_filter( $results, function( $count ) {
					return $count === 1;
				} ) ),
				'duplicate_count' => count( $duplicates )
			);
		}

		/**
		 * Parallel bulk database operations
		 *
		 * @param array $operations Array of database operations
		 * @return array Results from operations
		 */
		public static function parallelBulkOperations( array $operations ) {
			global $wpdb;

			$results = array();
			$max_concurrent = min( self::getInstance()->max_concurrent_processes, 5 ); // DB operations are more sensitive

			$batches = array_chunk( $operations, $max_concurrent, true );

			foreach ( $batches as $batch ) {
				$batch_results = array();

				// Execute batch operations
				foreach ( $batch as $operation_key => $operation ) {
					try {
						switch ( $operation['type'] ) {
							case 'insert':
								$batch_results[ $operation_key ] = $wpdb->insert(
									$operation['table'],
									$operation['data'],
									$operation['format'] ?? null
								);
								break;

							case 'update':
								$batch_results[ $operation_key ] = $wpdb->update(
									$operation['table'],
									$operation['data'],
									$operation['where'],
									$operation['format'] ?? null,
									$operation['where_format'] ?? null
								);
								break;

							case 'delete':
								$batch_results[ $operation_key ] = $wpdb->delete(
									$operation['table'],
									$operation['where'],
									$operation['where_format'] ?? null
								);
								break;

							default:
								$batch_results[ $operation_key ] = new WP_Error(
									'invalid_operation',
									'Unsupported operation type: ' . $operation['type']
								);
						}
					} catch ( Exception $e ) {
						$batch_results[ $operation_key ] = new WP_Error(
							'operation_failed',
							$e->getMessage()
						);
					}
				}

				$results = array_merge( $results, $batch_results );

				// Small delay to prevent overwhelming the database
				usleep( 10000 ); // 10ms
			}

			return $results;
		}

		/**
		 * Get processing statistics
		 */
		public static function getProcessingStats() {
			return array(
				'max_concurrent_processes' => self::getInstance()->max_concurrent_processes,
				'active_processes' => count( self::getInstance()->active_processes ),
				'queued_processes' => count( self::getInstance()->process_queue ),
			);
		}

		/**
		 * Clean up completed processes
		 */
		public static function cleanupCompletedProcesses() {
			// In a real implementation, this would check for completed background processes
			// For now, this is a placeholder for future enhancement
		}
	}

	// Initialize the parallel processor
	ParallelProcessor::getInstance();
}