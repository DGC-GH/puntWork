<?php

/**
 * Database query performance monitor
 *
 * @package    Puntwork
 * @subpackage Utilities
 * @since      1.0.9
 */

namespace Puntwork\Utilities;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database query performance monitor
 */
class DatabasePerformanceMonitor {

	private static array $query_log  = array();
	private static float $start_time = 0.0;

	/**
	 * Start monitoring database queries
	 */
	public static function start(): void {
		self::$query_log  = array();
		self::$start_time = microtime( true );

		error_log( '[PUNTWORK] DatabasePerformanceMonitor: Starting database query monitoring' );

		// Hook into WordPress database queries
		add_filter( 'query', array( __CLASS__, 'logQuery' ) );
		add_filter( 'get_col', array( __CLASS__, 'logQuery' ) );
		add_filter( 'get_row', array( __CLASS__, 'logQuery' ) );
		add_filter( 'get_results', array( __CLASS__, 'logQuery' ) );

		error_log( '[PUNTWORK] DatabasePerformanceMonitor: Filters added successfully' );
	}

	/**
	 * Log a database query
	 *
	 * @param  string $query The SQL query
	 * @return string The query (unchanged)
	 */
	public static function logQuery( string $query ): string {
		$query_start = microtime( true );
		$backtrace   = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 5 );

		// Store query info
		self::$query_log[] = array(
			'query'      => $query,
			'start_time' => $query_start,
			'backtrace'  => $backtrace,
			'query_type' => self::getQueryType( $query ),
		);

		error_log( '[PUNTWORK] DatabasePerformanceMonitor: Logged query - ' . substr( $query, 0, 100 ) . '...' );
		return $query;
	}

	/**
	 * End monitoring and return statistics
	 *
	 * @return array Query performance statistics
	 */
	public static function end(): array {
		$end_time   = microtime( true );
		$total_time = $end_time - self::$start_time;

		error_log( '[PUNTWORK] DatabasePerformanceMonitor: Ending database query monitoring, total queries: ' . count( self::$query_log ) );

		// Remove hooks
		remove_filter( 'query', array( __CLASS__, 'logQuery' ) );
		remove_filter( 'get_col', array( __CLASS__, 'logQuery' ) );
		remove_filter( 'get_row', array( __CLASS__, 'logQuery' ) );
		remove_filter( 'get_results', array( __CLASS__, 'logQuery' ) );

		error_log( '[PUNTWORK] DatabasePerformanceMonitor: Filters removed successfully' );

		$query_count  = count( self::$query_log );
		$slow_queries = array_filter( self::$query_log, fn( $q ) => ( $q['start_time'] ?? 0 ) > 0.1 ); // Queries > 100ms

		return array(
			'total_queries'      => $query_count,
			'total_time'         => round( $total_time, 4 ),
			'avg_query_time'     => $query_count > 0 ? round( $total_time / $query_count, 4 ) : 0,
			'slow_queries_count' => count( $slow_queries ),
			'slow_queries'       => array_slice( $slow_queries, 0, 10 ), // Top 10 slow queries
			'query_types'        => self::analyzeQueryTypes(),
		);
	}

	/**
	 * Get query type from SQL
	 */
	private static function getQueryType( string $query ): string {
		$query = strtoupper( trim( $query ) );
		if ( strpos( $query, 'SELECT' ) === 0 ) {
			return 'SELECT';
		}
		if ( strpos( $query, 'INSERT' ) === 0 ) {
			return 'INSERT';
		}
		if ( strpos( $query, 'UPDATE' ) === 0 ) {
			return 'UPDATE';
		}
		if ( strpos( $query, 'DELETE' ) === 0 ) {
			return 'DELETE';
		}
		return 'OTHER';
	}

	/**
	 * Analyze query types distribution
	 */
	private static function analyzeQueryTypes(): array {
		$types = array();
		foreach ( self::$query_log as $query ) {
			$type           = $query['query_type'];
			$types[ $type ] = ( $types[ $type ] ?? 0 ) + 1;
		}
		return $types;
	}
}
