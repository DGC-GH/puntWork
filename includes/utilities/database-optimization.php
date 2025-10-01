<?php

/**
 * Database optimization utilities.
 *
 * @since      1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Puntwork\Utilities\CacheManager;

/**
 * Create database indexes for performance optimization.
 */
function create_database_indexes(): void {
	global $wpdb;

	\Puntwork\PuntWorkLogger::info( 'Starting database index creation', \Puntwork\PuntWorkLogger::CONTEXT_SYSTEM );

	// Index for GUID lookups (critical for duplicate detection)
	$start_time = microtime( true );
	$result     = $wpdb->query(
		"
        CREATE INDEX IF NOT EXISTS idx_postmeta_guid
        ON {$wpdb->postmeta} (meta_key, meta_value(50))
    "
	);
	$duration   = microtime( true ) - $start_time;
	\Puntwork\PuntWorkLogger::debug(
		'Created idx_postmeta_guid index',
		\Puntwork\PuntWorkLogger::CONTEXT_SYSTEM,
		array(
			'result'   => $result !== false,
			'duration' => round( $duration, 4 ),
			'table'    => $wpdb->postmeta,
		)
	);

	// Index for import hash lookups
	$start_time = microtime( true );
	$result     = $wpdb->query(
		"
        CREATE INDEX IF NOT EXISTS idx_postmeta_import_hash
        ON {$wpdb->postmeta} (meta_key, meta_value(32))
    "
	);
	$duration   = microtime( true ) - $start_time;
	\Puntwork\PuntWorkLogger::debug(
		'Created idx_postmeta_import_hash index',
		\Puntwork\PuntWorkLogger::CONTEXT_SYSTEM,
		array(
			'result'   => $result !== false,
			'duration' => round( $duration, 4 ),
			'table'    => $wpdb->postmeta,
		)
	);

	// Index for last import update timestamps
	$start_time = microtime( true );
	$result     = $wpdb->query(
		"
        CREATE INDEX IF NOT EXISTS idx_postmeta_last_update
        ON {$wpdb->postmeta} (meta_key, post_id)
    "
	);
	$duration   = microtime( true ) - $start_time;
	\Puntwork\PuntWorkLogger::debug(
		'Created idx_postmeta_last_update index',
		\Puntwork\PuntWorkLogger::CONTEXT_SYSTEM,
		array(
			'result'   => $result !== false,
			'duration' => round( $duration, 4 ),
			'table'    => $wpdb->postmeta,
		)
	);

	// Composite index for post status and type (for job queries)
	$start_time = microtime( true );
	$result     = $wpdb->query(
		"
        CREATE INDEX IF NOT EXISTS idx_posts_job_status
        ON {$wpdb->posts} (post_type, post_status, post_modified)
    "
	);
	$duration   = microtime( true ) - $start_time;
	\Puntwork\PuntWorkLogger::debug(
		'Created idx_posts_job_status index',
		\Puntwork\PuntWorkLogger::CONTEXT_SYSTEM,
		array(
			'result'   => $result !== false,
			'duration' => round( $duration, 4 ),
			'table'    => $wpdb->posts,
		)
	);

	// Index for feed URL lookups
	$start_time = microtime( true );
	$result     = $wpdb->query(
		"
        CREATE INDEX IF NOT EXISTS idx_postmeta_feed_url
        ON {$wpdb->postmeta} (meta_key, meta_value(255))
    "
	);
	$duration   = microtime( true ) - $start_time;
	\Puntwork\PuntWorkLogger::debug(
		'Created idx_postmeta_feed_url index',
		\Puntwork\PuntWorkLogger::CONTEXT_SYSTEM,
		array(
			'result'   => $result !== false,
			'duration' => round( $duration, 4 ),
			'table'    => $wpdb->postmeta,
		)
	);

	// Additional performance indexes
	$start_time = microtime( true );
	$result     = $wpdb->query(
		"
        CREATE INDEX IF NOT EXISTS idx_posts_job_date
        ON {$wpdb->posts} (post_type, post_date, post_modified)
    "
	);
	$duration   = microtime( true ) - $start_time;
	\Puntwork\PuntWorkLogger::debug(
		'Created idx_posts_job_date index',
		\Puntwork\PuntWorkLogger::CONTEXT_SYSTEM,
		array(
			'result'   => $result !== false,
			'duration' => round( $duration, 4 ),
			'table'    => $wpdb->posts,
		)
	);

	// Index for job title searches
	$start_time = microtime( true );
	$result     = $wpdb->query(
		"
        CREATE INDEX IF NOT EXISTS idx_posts_job_title
        ON {$wpdb->posts} (post_type, post_title(100))
    "
	);
	$duration   = microtime( true ) - $start_time;
	\Puntwork\PuntWorkLogger::debug(
		'Created idx_posts_job_title index',
		\Puntwork\PuntWorkLogger::CONTEXT_SYSTEM,
		array(
			'result'   => $result !== false,
			'duration' => round( $duration, 4 ),
			'table'    => $wpdb->posts,
		)
	);

	// Index for performance logs queries
	$performance_table = $wpdb->prefix . 'puntwork_performance_logs';
	if ( $wpdb->get_var( "SHOW TABLES LIKE '$performance_table'" ) ) {
		$start_time = microtime( true );
		$result     = $wpdb->query(
			"
        CREATE INDEX IF NOT EXISTS idx_performance_operation_time
        ON {$performance_table} (operation, created_at)
    "
		);
		$duration   = microtime( true ) - $start_time;
		\Puntwork\PuntWorkLogger::debug(
			'Created idx_performance_operation_time index',
			\Puntwork\PuntWorkLogger::CONTEXT_SYSTEM,
			array(
				'result'   => $result !== false,
				'duration' => round( $duration, 4 ),
				'table'    => $performance_table,
			)
		);

		$start_time = microtime( true );
		$result     = $wpdb->query(
			"
        CREATE INDEX IF NOT EXISTS idx_performance_duration
        ON {$performance_table} (total_time, items_per_second)
    "
		);
		$duration   = microtime( true ) - $start_time;
		\Puntwork\PuntWorkLogger::debug(
			'Created idx_performance_duration index',
			\Puntwork\PuntWorkLogger::CONTEXT_SYSTEM,
			array(
				'result'   => $result !== false,
				'duration' => round( $duration, 4 ),
				'table'    => $performance_table,
			)
		);
	} else {
		\Puntwork\PuntWorkLogger::debug(
			'Performance logs table does not exist, skipping index creation',
			\Puntwork\PuntWorkLogger::CONTEXT_SYSTEM,
			array(
				'table' => $performance_table,
			)
		);
	}

	\Puntwork\PuntWorkLogger::info( 'Database index creation completed', \Puntwork\PuntWorkLogger::CONTEXT_SYSTEM );
}

/**
 * Bulk update post meta values for better performance.
 *
 * @param int|array $post_id   Post ID or array of post IDs
 * @param array     $meta_data Array of meta_key => meta_value pairs, or array of arrays for multiple posts
 */
function bulk_update_post_meta( $post_id, array $meta_data ): void {
	global $wpdb;

	if ( empty( $meta_data ) ) {
		return;
	}

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[PUNTWORK] [DB-DEBUG] bulk_update_post_meta called for post ' . ( is_array( $post_id ) ? 'multiple posts' : $post_id ) . ' with ' . count( $meta_data ) . ' fields' );
	}

	$values       = array();
	$placeholders = array();

	// Handle single post
	if ( ! is_array( $post_id ) ) {
		foreach ( $meta_data as $key => $value ) {
			// Serialize array values to prevent wpdb->prepare errors
			if ( is_array( $value ) ) {
				$value = serialize( $value );
			}

			$values[]       = $post_id;
			$values[]       = $key;
			$values[]       = $value;
			$placeholders[] = '(%d, %s, %s)';
		}
	} else {
		// Handle multiple posts
		foreach ( $post_id as $index => $pid ) {
			if ( ! isset( $meta_data[ $index ] ) ) {
				continue;
			}
			foreach ( $meta_data[ $index ] as $key => $value ) {
				// Serialize array values to prevent wpdb->prepare errors
				if ( is_array( $value ) ) {
					$value = serialize( $value );
				}

				$values[]       = $pid;
				$values[]       = $key;
				$values[]       = $value;
				$placeholders[] = '(%d, %s, %s)';
			}
		}
	}

	$query = $wpdb->prepare(
		"
        INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
        VALUES " . implode( ', ', $placeholders ) . '
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)
    ',
		...$values
	);

	$start_time = microtime( true );
	$wpdb->query( $query );
	$query_time = microtime( true ) - $start_time;

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[PUNTWORK] [DB-DEBUG] bulk_update_post_meta completed in ' . number_format( $query_time, 4 ) . ' seconds' );
	}
}

/**
 * Bulk update ACF fields for multiple posts using direct postmeta updates for performance.
 * This bypasses ACF processing for speed.
 *
 * @param  array $post_ids Array of post IDs
 * @param  array $acf_data Array of ACF field data keyed by post index
 * @return void
 */
function bulk_update_acf_fields( array $post_ids, array $acf_data ): void {
	if ( empty( $acf_data ) || empty( $post_ids ) ) {
		return;
	}

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[PUNTWORK] [ACF-DEBUG] bulk_update_acf_fields called for ' . count( $post_ids ) . ' posts with ACF data' );
	}

	$start_time = microtime( true );

	// For bulk import operations, use direct postmeta updates to bypass ACF overhead
	// This is much faster than individual update_field() calls
	global $wpdb;

	$meta_inserts = array();
	$meta_updates = array();

	foreach ( $post_ids as $index => $post_id ) {
		if ( ! isset( $acf_data[ $index ] ) ) {
			continue;
		}

		$fields = $acf_data[ $index ];
		foreach ( $fields as $field_name => $value ) {
			// Prepare meta data for bulk insertion/update
			$meta_inserts[] = array(
				'post_id'    => $post_id,
				'meta_key'   => $field_name,
				'meta_value' => $value,
			);
		}
	}

	if ( ! empty( $meta_inserts ) ) {
		// First, delete existing ACF meta keys to avoid duplicates
		$post_ids_list = array_unique( array_column( $meta_inserts, 'post_id' ) );
		$meta_keys_list = array_unique( array_column( $meta_inserts, 'meta_key' ) );

		if ( ! empty( $post_ids_list ) && ! empty( $meta_keys_list ) ) {
			$post_placeholders = implode( ',', array_fill( 0, count( $post_ids_list ), '%d' ) );
			$key_placeholders = implode( ',', array_fill( 0, count( $meta_keys_list ), '%s' ) );

			$delete_query = $wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$post_placeholders}) AND meta_key IN ({$key_placeholders})",
				array_merge( $post_ids_list, $meta_keys_list )
			);

			$wpdb->query( $delete_query );
		}

		// Bulk insert new ACF meta data
		$values = array();
		$placeholders = array();

		foreach ( $meta_inserts as $meta ) {
			$values[] = $meta['post_id'];
			$values[] = $meta['meta_key'];
			$values[] = $meta['meta_value'];
			$placeholders[] = '(%d, %s, %s)';
		}

		if ( ! empty( $values ) ) {
			$query = "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ', ', $placeholders );
			$wpdb->query( $wpdb->prepare( $query, $values ) );
		}
	}

	$total_time = microtime( true ) - $start_time;
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[PUNTWORK] [ACF-DEBUG] bulk_update_acf_fields completed in ' . number_format( $total_time, 4 ) . ' seconds total (' . number_format( $total_time / count( $post_ids ), 4 ) . ' seconds per post)' );
	}
}

/**
 * Bulk insert postmeta data for maximum performance.
 *
 * @param array $meta_data Array of meta data arrays with post_id, meta_key, meta_value
 * @return void
 */
function bulk_insert_postmeta( array $meta_data ): void {
	if ( empty( $meta_data ) ) {
		return;
	}

	global $wpdb;

	// First, delete existing meta keys to avoid duplicates
	$post_ids = array_unique( array_column( $meta_data, 'post_id' ) );
	$meta_keys = array_unique( array_column( $meta_data, 'meta_key' ) );

	if ( ! empty( $post_ids ) && ! empty( $meta_keys ) ) {
		$post_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$key_placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );

		$delete_query = $wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$post_placeholders}) AND meta_key IN ({$key_placeholders})",
			array_merge( $post_ids, $meta_keys )
		);

		$wpdb->query( $delete_query );
	}

	// Bulk insert new meta data
	$values = array();
	$placeholders = array();

	foreach ( $meta_data as $meta ) {
		$values[] = $meta['post_id'];
		$values[] = $meta['meta_key'];
		$values[] = $meta['meta_value'];
		$placeholders[] = '(%d, %s, %s)';
	}

	if ( ! empty( $values ) ) {
		$query = "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ', ', $placeholders );
		$wpdb->query( $wpdb->prepare( $query, $values ) );
	}
}

/**
 * Bulk insert posts for maximum performance.
 *
 * @param array $posts_data Array of post data arrays
 * @return array Array of inserted post IDs
 */
function bulk_insert_posts( array $posts_data ): array {
	if ( empty( $posts_data ) ) {
		return array();
	}

	global $wpdb;

	$inserted_ids = array();
	$values = array();
	$placeholders = array();

	foreach ( $posts_data as $post_data ) {
		$values[] = $post_data['post_author'] ?? 1;
		$values[] = $post_data['post_date'] ?? current_time( 'mysql' );
		$values[] = $post_data['post_date_gmt'] ?? current_time( 'mysql', true );
		$values[] = $post_data['post_content'] ?? '';
		$values[] = $post_data['post_title'];
		$values[] = $post_data['post_excerpt'] ?? '';
		$values[] = $post_data['post_status'] ?? 'publish';
		$values[] = $post_data['comment_status'] ?? 'closed';
		$values[] = $post_data['ping_status'] ?? 'closed';
		$values[] = $post_data['post_password'] ?? '';
		$values[] = $post_data['post_name'];
		$values[] = $post_data['to_ping'] ?? '';
		$values[] = $post_data['pinged'] ?? '';
		$values[] = $post_data['post_modified'] ?? current_time( 'mysql' );
		$values[] = $post_data['post_modified_gmt'] ?? current_time( 'mysql', true );
		$values[] = $post_data['post_content_filtered'] ?? '';
		$values[] = $post_data['post_parent'] ?? 0;
		$values[] = $post_data['guid'] ?? '';
		$values[] = $post_data['menu_order'] ?? 0;
		$values[] = $post_data['post_type'] ?? 'post';
		$values[] = $post_data['post_mime_type'] ?? '';
		$values[] = $post_data['comment_count'] ?? 0;

		$placeholders[] = '(%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %s, %d, %s, %s, %d)';
	}

	if ( ! empty( $values ) ) {
		$query = "INSERT INTO {$wpdb->posts} (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count) VALUES " . implode( ', ', $placeholders );
		$wpdb->query( $wpdb->prepare( $query, $values ) );

		// Get the inserted IDs
		$first_id = $wpdb->insert_id;
		$count = count( $posts_data );
		for ( $i = 0; $i < $count; $i++ ) {
			$inserted_ids[] = $first_id + $i;
		}
	}

	return $inserted_ids;
}

/**
 * Bulk update posts for maximum performance.
 *
 * @param array $posts_data Array of post data arrays with ID key
 * @return void
 */
function bulk_update_posts( array $posts_data ): void {
	if ( empty( $posts_data ) ) {
		return;
	}

	global $wpdb;

	// Process updates in chunks to avoid overly large queries
	$chunk_size = 50;
	$chunks = array_chunk( $posts_data, $chunk_size );

	foreach ( $chunks as $chunk ) {
		$when_clauses = array();
		$ids = array();

		foreach ( $chunk as $post_data ) {
			$id = $post_data['ID'];
			$ids[] = $id;

			// Build CASE statements for each field
			foreach ( $post_data as $field => $value ) {
				if ( $field === 'ID' ) continue;

				if ( ! isset( $when_clauses[ $field ] ) ) {
					$when_clauses[ $field ] = "WHEN {$wpdb->posts}.ID = %d THEN %s";
				} else {
					$when_clauses[ $field ] .= " WHEN {$wpdb->posts}.ID = %d THEN %s";
				}
			}
		}

		if ( empty( $ids ) ) continue;

		// Build the bulk update query
		$set_clauses = array();
		$values = array();

		foreach ( $when_clauses as $field => $when_clause ) {
			$set_clauses[] = "{$field} = CASE " . $when_clause . " END";

			// Add values for each WHEN condition
			foreach ( $chunk as $post_data ) {
				$id = $post_data['ID'];
				if ( isset( $post_data[ $field ] ) ) {
					$values[] = $id;
					$values[] = $post_data[ $field ];
				}
			}
		}

		// Add IDs for WHERE clause
		$id_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$values = array_merge( $values, $ids );

		$query = "UPDATE {$wpdb->posts} SET " . implode( ', ', $set_clauses ) . " WHERE ID IN ({$id_placeholders})";
		$wpdb->query( $wpdb->prepare( $query, $values ) );
	}
}

/**
 * Bulk get post statuses for multiple posts.
 *
 * @param  array $post_ids Array of post IDs
 * @return array Post ID => status mapping
 */
function bulk_get_post_statuses( array $post_ids ): array {
	global $wpdb;

	if ( empty( $post_ids ) ) {
		return array();
	}

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[PUNTWORK] [DB-DEBUG] bulk_get_post_statuses called with ' . count( $post_ids ) . ' post IDs' );
	}

	// Create cache key from sorted post IDs
	sort( $post_ids );
	$cache_key     = 'post_statuses_' . md5( implode( ',', $post_ids ) );
	$cached_result = CacheManager::get( $cache_key, CacheManager::GROUP_ANALYTICS );

	if ( $cached_result !== false ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [DB-DEBUG] Returning cached result for post statuses' );
		}

		return $cached_result;
	}

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[PUNTWORK] [DB-DEBUG] Cache miss, querying post statuses' );
	}

	$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
	$query        = $wpdb->prepare(
		"
        SELECT ID, post_status
        FROM {$wpdb->posts}
        WHERE ID IN ({$placeholders})
    ",
		$post_ids
	);

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[PUNTWORK] [DB-DEBUG] Executing post status query' );
	}

	$start_time = microtime( true );
	$results    = $wpdb->get_results( $query, OBJECT_K );
	$query_time = microtime( true ) - $start_time;

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[PUNTWORK] [DB-DEBUG] Post status query returned ' . count( $results ) . ' results in ' . number_format( $query_time, 4 ) . ' seconds' );
	}

	$statuses = array();

	foreach ( $results as $post_id => $post ) {
		$statuses[ $post_id ] = $post->post_status;
	}

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[PUNTWORK] [DB-DEBUG] Processed ' . count( $statuses ) . ' post statuses' );
	}

	// Cache for 6 hours - post statuses change less frequently
	CacheManager::set( $cache_key, $statuses, CacheManager::GROUP_ANALYTICS, 6 * HOUR_IN_SECONDS );

	return $statuses;
}

/**
 * Optimized function to get posts by GUID with status.
 *
 * @param  array $guids Array of GUIDs to look up
 * @return array GUID => post data mapping
 */
function get_posts_by_guids_with_status( array $guids ): array {
	global $wpdb;

	if ( empty( $guids ) ) {
		return array();
	}

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[PUNTWORK] [DB-DEBUG] get_posts_by_guids_with_status called with ' . count( $guids ) . ' GUIDs' );
	}

	// Create cache key from sorted GUIDs to ensure consistency
	sort( $guids );
	$cache_key     = 'posts_by_guids_' . md5( implode( ',', $guids ) );
	$cached_result = CacheManager::get( $cache_key, CacheManager::GROUP_ANALYTICS );

	if ( $cached_result !== false ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [DB-DEBUG] Returning cached result for GUID lookup' );
		}

		return $cached_result;
	}

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[PUNTWORK] [DB-DEBUG] Cache miss, querying database' );
	}

	$guid_placeholders = implode( ',', array_fill( 0, count( $guids ), '%s' ) );
	$query             = $wpdb->prepare(
		"
        SELECT pm.meta_value AS guid, p.ID, p.post_status, p.post_modified
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE pm.meta_key = 'guid'
        AND pm.meta_value IN ({$guid_placeholders})
        AND p.post_type = 'job'
    ",
		$guids
	);

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[PUNTWORK] [DB-DEBUG] Executing query: ' . $query );
	}

	$start_time = microtime( true );
	$results    = $wpdb->get_results( $query );
	$query_time = microtime( true ) - $start_time;

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[PUNTWORK] [DB-DEBUG] Query returned ' . count( $results ) . ' results in ' . number_format( $query_time, 4 ) . ' seconds' );
	}

	$posts_by_guid = array();

	foreach ( $results as $row ) {
		if ( ! isset( $posts_by_guid[ $row->guid ] ) ) {
			$posts_by_guid[ $row->guid ] = array();
		}
		$posts_by_guid[ $row->guid ][] = array(
			'id'       => (int) $row->ID,
			'status'   => $row->post_status,
			'modified' => $row->post_modified,
		);
	}

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[PUNTWORK] [DB-DEBUG] Processed ' . count( $posts_by_guid ) . ' unique GUIDs' );
		error_log( '[PUNTWORK] [DB-DEBUG] Sample GUIDs found: ' . implode( ', ', array_slice( array_keys( $posts_by_guid ), 0, 5 ) ) );
	}

	// Cache for 6 hours - GUID lookups change relatively frequently during imports
	CacheManager::set( $cache_key, $posts_by_guid, CacheManager::GROUP_ANALYTICS, 6 * HOUR_IN_SECONDS );

	return $posts_by_guid;
}

/**
 * Preload all post meta for a batch of posts to avoid N+1 queries.
 *
 * @param  array $post_ids Array of post IDs
 * @return array Post ID => meta_key => meta_value mapping
 */
function preload_post_meta_batch( array $post_ids ): array {
	global $wpdb;

	if ( empty( $post_ids ) ) {
		return array();
	}

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[PUNTWORK] [DB-DEBUG] preload_post_meta_batch called with ' . count( $post_ids ) . ' post IDs' );
	}

	$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
	$query        = $wpdb->prepare(
		"
        SELECT post_id, meta_key, meta_value
        FROM {$wpdb->postmeta}
        WHERE post_id IN ({$placeholders})
    ",
		$post_ids
	);

	$start_time = microtime( true );
	$results    = $wpdb->get_results( $query );
	$query_time = microtime( true ) - $start_time;

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[PUNTWORK] [DB-DEBUG] preload_post_meta_batch query returned ' . count( $results ) . ' meta rows in ' . number_format( $query_time, 4 ) . ' seconds' );
	}

	$meta_cache = array();
	foreach ( $results as $row ) {
		if ( ! isset( $meta_cache[ $row->post_id ] ) ) {
			$meta_cache[ $row->post_id ] = array();
		}
		$meta_cache[ $row->post_id ][ $row->meta_key ] = $row->meta_value;
	}

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[PUNTWORK] [DB-DEBUG] Preloaded meta for ' . count( $meta_cache ) . ' posts' );
	}

	return $meta_cache;
}

/**
 * Get database optimization status.
 *
 * @return array Status information
 */
function get_database_optimization_status(): array {
	global $wpdb;

	\Puntwork\PuntWorkLogger::debug( 'Checking database optimization status', \Puntwork\PuntWorkLogger::CONTEXT_SYSTEM );

	$indexes = array(
		'idx_postmeta_guid'              => false,
		'idx_postmeta_import_hash'       => false,
		'idx_postmeta_last_update'       => false,
		'idx_posts_job_status'           => false,
		'idx_postmeta_feed_url'          => false,
		'idx_posts_job_date'             => false,
		'idx_posts_job_title'            => false,
		'idx_performance_operation_time' => false,
		'idx_performance_duration'       => false,
	);

	// Check which indexes exist - try information_schema first, fallback to SHOW INDEXES
	try {
		\Puntwork\PuntWorkLogger::debug( 'Attempting to check indexes using information_schema', \Puntwork\PuntWorkLogger::CONTEXT_SYSTEM );
		$start_time       = microtime( true );
		$existing_indexes = $wpdb->get_col(
			"
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('{$wpdb->postmeta}', '{$wpdb->posts}', '{$wpdb->prefix}puntwork_performance_logs')
        AND INDEX_NAME IN ('" . implode( "','", array_keys( $indexes ) ) . "')
    "
		);
		$duration         = microtime( true ) - $start_time;

		foreach ( $existing_indexes as $index ) {
			$indexes[ $index ] = true;
		}

		\Puntwork\PuntWorkLogger::debug(
			'Successfully checked indexes using information_schema',
			\Puntwork\PuntWorkLogger::CONTEXT_SYSTEM,
			array(
				'found_indexes' => count( $existing_indexes ),
				'duration'      => round( $duration, 4 ),
			)
		);
	} catch ( \Exception $e ) {
		// Fallback: Try SHOW INDEXES queries for each table
		\Puntwork\PuntWorkLogger::warn(
			'information_schema access denied, using SHOW INDEXES fallback',
			\Puntwork\PuntWorkLogger::CONTEXT_SYSTEM,
			array(
				'error' => $e->getMessage(),
			)
		);

		$tables_to_check = array( $wpdb->postmeta, $wpdb->posts, $wpdb->prefix . 'puntwork_performance_logs' );

		foreach ( $tables_to_check as $table ) {
			try {
				\Puntwork\PuntWorkLogger::debug( "Checking indexes for table: $table", \Puntwork\PuntWorkLogger::CONTEXT_SYSTEM );
				$table_indexes = $wpdb->get_col( "SHOW INDEXES FROM `$table` WHERE Key_name IN ('" . implode( "','", array_keys( $indexes ) ) . "')" );
				foreach ( $table_indexes as $index ) {
					$indexes[ $index ] = true;
				}
				\Puntwork\PuntWorkLogger::debug( 'Found ' . count( $table_indexes ) . " indexes in table $table", \Puntwork\PuntWorkLogger::CONTEXT_SYSTEM );
			} catch ( \Exception $table_e ) {
				// If table doesn't exist or can't be accessed, continue
				\Puntwork\PuntWorkLogger::warn(
					"Could not check indexes for table $table",
					\Puntwork\PuntWorkLogger::CONTEXT_SYSTEM,
					array(
						'error' => $table_e->getMessage(),
					)
				);
			}
		}
	}

	$missing_indexes = array_filter(
		$indexes,
		function ( $exists ) {
			return ! $exists;
		}
	);

	$status = array(
		'indexes_created'       => count( $indexes ) - count( $missing_indexes ),
		'total_indexes'         => count( $indexes ),
		'missing_indexes'       => array_keys( $missing_indexes ),
		'optimization_complete' => empty( $missing_indexes ),
	);

	\Puntwork\PuntWorkLogger::info(
		'Database optimization status check completed',
		\Puntwork\PuntWorkLogger::CONTEXT_SYSTEM,
		array(
			'indexes_created'       => $status['indexes_created'],
			'total_indexes'         => $status['total_indexes'],
			'missing_indexes'       => $status['missing_indexes'],
			'optimization_complete' => $status['optimization_complete'],
		)
	);

	return $status;
}

/**
 * Start detailed performance monitoring for import operations.
 *
 * @return array Monitoring data
 */
function start_import_performance_monitoring(): array {
	$start_time   = microtime( true );
	$start_memory = memory_get_peak_usage( true );

	// Clear any existing transients that might interfere
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_puntwork_%'" );

	return array(
		'start_time'        => $start_time,
		'start_memory'      => $start_memory,
		'query_count_start' => $wpdb->num_queries,
	);
}

/**
 * End performance monitoring and log results.
 *
 * @param  array  $monitoring_data Data from start_import_performance_monitoring
 * @param  string $operation       Operation name
 * @param  int    $items_processed Number of items processed
 * @return void
 */
function end_import_performance_monitoring( array $monitoring_data, string $operation, int $items_processed = 0 ): void {
	$end_time   = microtime( true );
	$end_memory = memory_get_peak_usage( true );

	global $wpdb;
	$query_count_end = $wpdb->num_queries;

	$total_time   = $end_time - $monitoring_data['start_time'];
	$memory_used  = $end_memory - $monitoring_data['start_memory'];
	$queries_used = $query_count_end - $monitoring_data['query_count_start'];

	$items_per_second = $items_processed > 0 ? $items_processed / $total_time : 0;
	$queries_per_item = $items_processed > 0 ? $queries_used / $items_processed : 0;

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log(
			sprintf(
				'[PUNTWORK] [PERFORMANCE] %s completed in %.3fs, %d items (%.2f items/sec), Memory: %.2fMB used, Queries: %d total (%d new, %.1f per item)',
				$operation,
				$total_time,
				$items_processed,
				$items_per_second,
				$memory_used / 1024 / 1024,
				$query_count_end,
				$queries_used,
				$queries_per_item
			)
		);
	}

	// Store in performance logs table if it exists
	if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}puntwork_performance_logs'" ) ) {
		$wpdb->insert(
			$wpdb->prefix . 'puntwork_performance_logs',
			array(
				'operation'        => $operation,
				'total_time'       => $total_time,
				'items_processed'  => $items_processed,
				'items_per_second' => $items_per_second,
				'memory_used'      => $memory_used,
				'query_count'      => $queries_used,
				'created_at'       => current_time( 'mysql' ),
			)
		);
	}
}

/**
 * Comprehensive database connection test with detailed logging.
 *
 * @return array Test results with connection status and details
 */
function test_database_connection(): array {
	$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;
	$results    = array(
		'connected' => false,
		'error'     => null,
		'details'   => array(),
		'tests'     => array(),
	);

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [DB-CONNECTION] ===== DATABASE CONNECTION TEST START =====' );
	}

	global $wpdb;

	// Test 1: Basic WordPress database connection
	try {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [DB-CONNECTION] Testing basic WordPress database connection...' );
			error_log( '[PUNTWORK] [DB-CONNECTION] DB_HOST: ' . DB_HOST );
			error_log( '[PUNTWORK] [DB-CONNECTION] DB_NAME: ' . DB_NAME );
			error_log( '[PUNTWORK] [DB-CONNECTION] DB_USER: ' . DB_USER );
			error_log( '[PUNTWORK] [DB-CONNECTION] Table prefix: ' . $wpdb->prefix );
		}

		$test_query = $wpdb->get_var( 'SELECT 1 as test' );
		if ( $test_query === '1' ) {
			$results['tests']['basic_connection']   = true;
			$results['details']['basic_connection'] = 'SUCCESS';
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [DB-CONNECTION] Basic connection test PASSED' );
			}
		} else {
			$results['tests']['basic_connection']   = false;
			$results['details']['basic_connection'] = 'FAILED: Unexpected result: ' . $test_query;
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [DB-CONNECTION] Basic connection test FAILED: Unexpected result: ' . $test_query );
			}
		}
	} catch ( \Exception $e ) {
		$results['tests']['basic_connection']   = false;
		$results['details']['basic_connection'] = 'EXCEPTION: ' . $e->getMessage();
		$results['error']                       = $e->getMessage();
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [DB-CONNECTION] Basic connection test EXCEPTION: ' . $e->getMessage() );
		}
	}

	// Test 2: WordPress check_connection method
	try {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [DB-CONNECTION] Testing WordPress check_connection method...' );
		}

		if ( method_exists( $wpdb, 'check_connection' ) ) {
			$check_result                           = $wpdb->check_connection();
			$results['tests']['check_connection']   = $check_result;
			$results['details']['check_connection'] = $check_result ? 'SUCCESS' : 'FAILED';
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [DB-CONNECTION] check_connection method: ' . ( $check_result ? 'PASSED' : 'FAILED' ) );
			}
		} else {
			$results['tests']['check_connection']   = 'not_available';
			$results['details']['check_connection'] = 'Method not available';
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [DB-CONNECTION] check_connection method not available' );
			}
		}
	} catch ( \Exception $e ) {
		$results['tests']['check_connection']   = false;
		$results['details']['check_connection'] = 'EXCEPTION: ' . $e->getMessage();
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [DB-CONNECTION] check_connection method EXCEPTION: ' . $e->getMessage() );
		}
	}

	// Test 3: wpdb last_error check
	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [DB-CONNECTION] Checking wpdb last_error: ' . ( $wpdb->last_error ?: 'None' ) );
	}
	$results['details']['last_error'] = $wpdb->last_error ?: null;

	// Test 4: Table existence check
	try {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [DB-CONNECTION] Testing core table access...' );
		}

		$posts_count                       = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} LIMIT 1" );
		$results['tests']['posts_table']   = true;
		$results['details']['posts_table'] = 'SUCCESS: Found ' . $posts_count . ' posts';
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [DB-CONNECTION] Posts table access: SUCCESS (' . $posts_count . ' posts)' );
		}
	} catch ( \Exception $e ) {
		$results['tests']['posts_table']   = false;
		$results['details']['posts_table'] = 'EXCEPTION: ' . $e->getMessage();
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [DB-CONNECTION] Posts table access: EXCEPTION: ' . $e->getMessage() );
		}
	}

	// Test 5: Options table access (critical for get_option calls)
	try {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [DB-CONNECTION] Testing options table access...' );
		}

		$option_test                         = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'siteurl' ) );
		$results['tests']['options_table']   = true;
		$results['details']['options_table'] = 'SUCCESS: siteurl = ' . substr( $option_test, 0, 50 ) . '...';
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [DB-CONNECTION] Options table access: SUCCESS' );
		}
	} catch ( \Exception $e ) {
		$results['tests']['options_table']   = false;
		$results['details']['options_table'] = 'EXCEPTION: ' . $e->getMessage();
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [DB-CONNECTION] Options table access: EXCEPTION: ' . $e->getMessage() );
		}
	}

	// Overall connection status
	$results['connected'] = $results['tests']['basic_connection'] &&
							( $results['tests']['check_connection'] === true || $results['tests']['check_connection'] === 'not_available' ) &&
							$results['tests']['posts_table'] &&
							$results['tests']['options_table'];

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [DB-CONNECTION] ===== DATABASE CONNECTION TEST COMPLETE =====' );
		error_log( '[PUNTWORK] [DB-CONNECTION] Overall status: ' . ( $results['connected'] ? 'CONNECTED' : 'FAILED' ) );
		if ( ! $results['connected'] ) {
			error_log(
				'[PUNTWORK] [DB-CONNECTION] Failed tests: ' . json_encode(
					array_filter(
						$results['tests'],
						function ( $v ) {
							return $v === false;
						}
					)
				)
			);
		}
	}

	return $results;
}

/**
 * Safe get_option wrapper with debug logging.
 *
 * @param  string $option_name Option name
 * @param  mixed  $default     Default value
 * @return mixed Option value or default
 */
function safe_get_option( string $option_name, $default = false ) {
	$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [DB-DEBUG] safe_get_option called for: ' . $option_name );
	}

	try {
		global $wpdb;

		// Check if we can access the database
		if ( ! $wpdb->check_connection() ) {
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [DB-ERROR] Database connection check failed for get_option: ' . $option_name );
			}

			return $default;
		}

		$value = get_option( $option_name, $default );

		if ( $debug_mode ) {
			$value_type    = gettype( $value );
			$value_preview = is_string( $value ) ? substr( $value, 0, 100 ) : $value;
			error_log( '[PUNTWORK] [DB-DEBUG] get_option result for ' . $option_name . ': ' . $value_type . ' - ' . ( is_array( $value ) ? 'Array(' . count( $value ) . ')' : $value_preview ) );
		}

		return $value;
	} catch ( \Exception $e ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [DB-ERROR] Exception in safe_get_option for ' . $option_name . ': ' . $e->getMessage() );
			error_log( '[PUNTWORK] [DB-ERROR] Stack trace: ' . $e->getTraceAsString() );
		}

		return $default;
	}
}
