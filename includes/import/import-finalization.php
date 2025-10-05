<?php

/**
 * Import finalization utilities.
 *
 * @since      1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Include required dependencies
require_once __DIR__ . '/../scheduling/scheduling-history.php';

/**
 * Import finalization and status management
 * Handles completion of import batches and status updates.
 */

/**
 * Finalize batch import and update status.
 *
 * @param  array $result Processing result.
 * @return array Final result.
 */
function finalize_batch_import( $result ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[PUNTWORK] [FINALIZE-START] finalize_batch_import called with result success=' . ( isset( $result['success'] ) ? $result['success'] : 'not set' ) . ', complete=' . ( isset( $result['complete'] ) ? $result['complete'] : 'not set' ) );
	}

	if ( is_wp_error( $result ) || ! $result['success'] ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [FINALIZE-ERROR] finalize_batch_import returning early due to error or failure' );
		}

		return $result;
	}

	$status = get_option( 'job_import_status' ) ?: array(
		'total'              => $result['total'],
		'processed'          => 0,
		'published'          => 0,
		'updated'            => 0,
		'skipped'            => 0,
		'duplicates_drafted' => 0,
		'time_elapsed'       => 0,
		'complete'           => false,
		'batch_size'         => $result['batch_size'],
		'inferred_languages' => 0,
		'inferred_benefits'  => 0,
		'schema_generated'   => 0,
		'start_time'         => $result['start_time'],
		'last_update'        => time(),
		'logs'               => array(),
	);

	// Ensure start_time is set properly
	if ( ! isset( $status['start_time'] ) || $status['start_time'] === 0 ) {
		$status['start_time'] = $result['start_time'] ?? microtime( true );
	}

	$status['processed']           = $result['processed'] ?? 0;
	$status['published']          += $result['published'];
	$status['updated']            += $result['updated'];
	$status['skipped']            += $result['skipped'];
	$status['duplicates_drafted'] += $result['duplicates_drafted'];

	// Calculate total elapsed time from start to now
	$current_time           = microtime( true );
	$total_elapsed          = $current_time - $status['start_time'];
	$status['time_elapsed'] = $total_elapsed;

	$status['complete']            = $result['complete'];
	$status['success']             = $result['success']; // Set success status
	$status['error_message']       = $result['message'] ?? ''; // Set error message if any
	$status['batch_size']          = $result['batch_size'] ?? 50; // Default batch size
	$status['inferred_languages'] += $result['inferred_languages'] ?? 0;
	$status['inferred_benefits']  += $result['inferred_benefits'] ?? 0;
	$status['schema_generated']   += $result['schema_generated'] ?? 0;
	$status['last_update']         = time();

	update_option( 'job_import_status', $status, false );
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[PUNTWORK] [FINALIZE-STATUS] Updated import status: processed=' . ($status['processed'] ?? 0) . '/' . ($status['total'] ?? 0) . ', complete=' . ( ($status['complete'] ?? false) ? 'true' : 'false' ) . ', elapsed=' . round( $total_elapsed, 2 ) . 's' );
	}

	// Log completed import to history
	if ( $result['complete'] && $result['success'] ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [FINALIZE-COMPLETE] Import completed successfully, logging to history' );
		}
		$trigger_type = $status['trigger_type'] ?? 'scheduled';
		$test_mode    = $status['test_mode'] ?? false;

		$details = array(
			'success'       => true,
			'duration'      => $total_elapsed,
			'processed'     => $result['processed'] ?? 0,
			'total'         => $result['total'],
			'published'     => $result['published'],
			'updated'       => $result['updated'],
			'skipped'       => $result['skipped'],
			'error_message' => '',
			'timestamp'     => time(),
		);

		// Use the appropriate logging function based on trigger type
		if ( $trigger_type == 'manual' ) {
			log_manual_import_run( $details );
		} else {
			log_scheduled_run( $details, $test_mode, $trigger_type );
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log(
				sprintf(
					'[PUNTWORK] Import completed and logged to history - Trigger: %s, Duration: %.2fs, Processed: %d/%d',
					$trigger_type,
					$total_elapsed,
					$result['processed'] ?? 0,
					$result['total']
				)
			);
		}

		// Post new jobs to social media if enabled
		if ( get_option( 'puntwork_social_auto_post_jobs', false ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[PUNTWORK] [FINALIZE-SOCIAL] Auto-posting new jobs to social media' );
			}
			post_new_jobs_to_social_media( $result );
		} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [FINALIZE-SOCIAL] Social media auto-post disabled' );
		}

		// Clean up draft and trash jobs after successful import
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [FINALIZE-CLEANUP] Starting cleanup of draft/trash jobs after import' );
		}
		$cleanup_result = cleanup_draft_trash_jobs_after_import();
		if ( $cleanup_result['success'] ) {
			$result['cleanup_deleted'] = $cleanup_result['deleted'];
			$result['cleanup_duration'] = $cleanup_result['duration'];
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[PUNTWORK] [FINALIZE-CLEANUP] Draft/trash cleanup completed, deleted ' . $cleanup_result['deleted'] . ' jobs in ' . number_format( $cleanup_result['duration'], 2 ) . 's' );
			}
		} else {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[PUNTWORK] [FINALIZE-CLEANUP] Draft/trash cleanup failed: ' . ( $cleanup_result['error'] ?? 'Unknown error' ) );
			}
		}

		// Purge old jobs not present in feeds after successful import (now safe - drafts instead of deletes)
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [FINALIZE-PURGE] Starting safe purge of old jobs not in feeds after import' );
		}
		$purge_result = purge_old_jobs_not_in_feeds_after_import();
		if ( $purge_result['success'] ) {
			$result['purge_deleted'] = $purge_result['deleted'];
			$result['purge_duration'] = $purge_result['duration'];
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[PUNTWORK] [FINALIZE-PURGE] Safe purge completed, drafted ' . $purge_result['deleted'] . ' jobs in ' . number_format( $purge_result['duration'], 2 ) . 's' );
			}
		} else {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[PUNTWORK] [FINALIZE-PURGE] Safe purge failed: ' . ( $purge_result['error'] ?? 'Unknown error' ) );
			}
		}

		// Aggressive cleanup: Delete jobs not in current feed (user requested this)
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PUNTWORK] [FINALIZE-AGGRESSIVE-CLEANUP] Starting aggressive cleanup of jobs not in current feed' );
		}
		$aggressive_cleanup_result = cleanup_jobs_not_in_current_feed();
		if ( $aggressive_cleanup_result['success'] ) {
			$result['aggressive_cleanup_deleted'] = $aggressive_cleanup_result['deleted'];
			$result['aggressive_cleanup_duration'] = $aggressive_cleanup_result['duration'];
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[PUNTWORK] [FINALIZE-AGGRESSIVE-CLEANUP] Aggressive cleanup completed, deleted ' . $aggressive_cleanup_result['deleted'] . ' jobs in ' . number_format( $aggressive_cleanup_result['duration'], 2 ) . 's' );
			}
		} else {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[PUNTWORK] [FINALIZE-AGGRESSIVE-CLEANUP] Aggressive cleanup failed: ' . ( $aggressive_cleanup_result['error'] ?? 'Unknown error' ) );
			}
		}
	} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[PUNTWORK] [FINALIZE-INCOMPLETE] Import not complete or failed, skipping history logging, social media posting, and cleanup' );
	}

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[PUNTWORK] [FINALIZE-END] finalize_batch_import completed' );
	}

	return $result;
}

/**
 * Clean up import transients and temporary data.
 *
 * @return void
 */
function cleanup_import_data() {
	// Clean up transients
	delete_transient( 'import_cancel' );

	// Clean up options that are no longer needed after successful import
	delete_option( 'job_import_progress' );
	delete_option( 'job_import_processed_guids' );
	delete_option( 'job_existing_guids' );

	// Reset performance metrics for next import
	delete_option( 'job_import_time_per_job' );
	delete_option( 'job_import_avg_time_per_job' );
	delete_option( 'job_import_last_peak_memory' );
	delete_option( 'job_import_batch_size' );
	delete_option( 'job_import_consecutive_small_batches' );

	// Clean up batch timing data
	delete_option( 'job_import_last_batch_time' );
	delete_option( 'job_import_last_batch_processed' );
}

/**
 * Get import status summary.
 *
 * @return array Status summary.
 */
function get_import_status_summary() {
	$status = get_option( 'job_import_status', array() );

	return array(
		'total'                    => $status['total'] ?? 0,
		'processed'                => $status['processed'] ?? 0,
		'published'                => $status['published'] ?? 0,
		'updated'                  => $status['updated'] ?? 0,
		'skipped'                  => $status['skipped'] ?? 0,
		'duplicates_drafted'       => $status['duplicates_drafted'] ?? 0,
		'complete'                 => $status['complete'] ?? false,
		'progress_percentage'      => ($status['total'] ?? 0) > 0 ? round( ( ($status['processed'] ?? 0) / ($status['total'] ?? 1) ) * 100, 2 ) : 0,
		'time_elapsed'             => $status['time_elapsed'] ?? 0,
		'estimated_time_remaining' => calculate_estimated_time_remaining( $status ),
		'last_update'              => $status['last_update'] ?? null,
	);
}

/**
 * Calculate estimated time remaining for import.
 *
 * @param  array $status Current import status.
 * @return float Estimated time remaining in seconds.
 */
function calculate_estimated_time_remaining( $status ) {
	if ( ($status['complete'] ?? false) || ($status['processed'] ?? 0) === 0 || ($status['job_importing_time_elapsed'] ?? 0) === 0 ) {
		return 0;
	}

	$items_remaining   = ($status['total'] ?? 0) - ($status['processed'] ?? 0);
	$time_per_item     = ($status['job_importing_time_elapsed'] ?? 0) / ($status['processed'] ?? 1);
	$estimated_seconds = $items_remaining * $time_per_item;

	// PuntWorkLogger::debug('PHP time calculation', PuntWorkLogger::CONTEXT_BATCH, [
	// 'total' => $status['total'],
	// 'processed' => $status['processed'],
	// 'job_importing_time_elapsed' => $status['job_importing_time_elapsed'],
	// 'items_remaining' => $items_remaining,
	// 'time_per_item' => $time_per_item,
	// 'estimated_seconds' => $estimated_seconds
	// ]);

	return $estimated_seconds;
}

/**
 * Post new jobs to configured social media platforms.
 *
 * @param  array $import_result The import result data
 * @return void
 */
function post_new_jobs_to_social_media( $import_result ) {
	if ( ! class_exists( 'SocialMedia\\SocialMediaManager' ) ) {
		return;
	}

	try {
		$social_manager    = new SocialMedia\SocialMediaManager();
		$default_platforms = get_option( 'puntwork_social_default_platforms', array() );

		if ( empty( $default_platforms ) ) {
			return;
		}

		// Get recently published jobs (from this import)
		$recent_jobs = get_recent_imported_jobs( $import_result );

		if ( empty( $recent_jobs ) ) {
			return;
		}

		$posted_count = 0;
		$max_posts    = apply_filters( 'puntwork_social_max_auto_posts', 5 ); // Limit to prevent spam

		foreach ( $recent_jobs as $job ) {
			if ( $posted_count >= $max_posts ) {
				break;
			}

			$job_data = array(
				'title'        => get_the_title( $job->ID ),
				'company'      => get_post_meta( $job->ID, 'company', true ),
				'location'     => get_post_meta( $job->ID, 'location', true ),
				'url'          => get_permalink( $job->ID ),
				'company_logo' => get_post_meta( $job->ID, 'company_logo', true ),
				'description'  => get_post_meta( $job->ID, 'description', true ),
			);

			$results = $social_manager->postJob( $job_data, $default_platforms );

			$success_count = 0;
			foreach ( $results as $platform => $result ) {
				if ( $result['success'] ) {
					++$success_count;
				}
			}

			if ( $success_count > 0 ) {
				++$posted_count;
			}

			\Puntwork\PuntWorkLogger::info(
				'Auto-posted job to social media',
				\Puntwork\PuntWorkLogger::CONTEXT_SOCIAL,
				array(
					'job_id'        => $job->ID,
					'job_title'     => $job_data['title'],
					'platforms'     => $default_platforms,
					'success_count' => $success_count,
				)
			);
		}

		if ( $posted_count > 0 ) {
			\Puntwork\PuntWorkLogger::info(
				'Completed auto-posting jobs to social media',
				\Puntwork\PuntWorkLogger::CONTEXT_SOCIAL,
				array(
					'total_jobs_posted' => $posted_count,
					'platforms'         => $default_platforms,
				)
			);
		}
	} catch ( \Exception $e ) {
		\Puntwork\PuntWorkLogger::error(
			'Failed to auto-post jobs to social media',
			\Puntwork\PuntWorkLogger::CONTEXT_SOCIAL,
			array(
				'error' => $e->getMessage(),
			)
		);
	}
}

/**
 * Get recently imported jobs from the current import session.
 *
 * @param  array $import_result The import result data
 * @return array Array of job post objects
 */
function get_recent_imported_jobs( $import_result ) {
	$jobs = array();

	// Get jobs imported in the last hour (to be safe)
	$args = array(
		'post_type'      => 'job-feed',
		'posts_per_page' => 20,
		'post_status'    => 'publish',
		'orderby'        => 'date',
		'order'          => 'DESC',
		'date_query'     => array(
			array(
				'after' => '1 hour ago',
			),
		),
	);

	$query = new \WP_Query( $args );

	if ( $query->have_posts() ) {
		$jobs = $query->posts;
	}

	return $jobs;
}

/**
 * Clean up draft and trash jobs after import completion.
 *
 * @return array Cleanup result with deleted count and logs.
 */
function cleanup_draft_trash_jobs_after_import() {
	global $wpdb;

	$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;
	$start_time = microtime( true );
	$deleted_count = 0;
	$logs = array();

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [CLEANUP-AFTER-IMPORT] Starting cleanup of draft/trash jobs' );
	}

	try {
		// Get batch size limit to prevent timeouts (default 100 jobs per batch)
		$batch_size_limit = get_option( 'puntwork_cleanup_batch_size', 100 );

		// Get all draft and trash jobs
		$draft_trash_jobs = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT p.ID, p.post_status, p.post_title
				FROM {$wpdb->posts} p
				WHERE p.post_type = 'job'
				AND p.post_status IN ('draft', 'trash')
				ORDER BY p.ID
				LIMIT %d
			",
				$batch_size_limit
			)
		);

		if ( empty( $draft_trash_jobs ) ) {
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [CLEANUP-AFTER-IMPORT] No draft/trash jobs found to clean up' );
			}
			return array(
				'success' => true,
				'deleted' => 0,
				'logs' => array( 'No draft/trash jobs found to clean up' ),
			);
		}

		// Check if we hit the batch limit - log a warning
		$total_available = $wpdb->get_var(
			"
			SELECT COUNT(*) FROM {$wpdb->posts} p
			WHERE p.post_type = 'job'
			AND p.post_status IN ('draft', 'trash')
		"
		);

		if ( $total_available > $batch_size_limit ) {
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [CLEANUP-AFTER-IMPORT] Large cleanup detected: ' . $total_available . ' draft/trash jobs found, processing first ' . $batch_size_limit . ' in this batch' );
			}
			$logs[] = 'Large cleanup: ' . $total_available . ' draft/trash jobs found, processing ' . $batch_size_limit . ' in this batch. Remaining jobs will be cleaned up in subsequent operations.';
		}

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [CLEANUP-AFTER-IMPORT] Processing ' . count( $draft_trash_jobs ) . ' draft/trash jobs (batch size limit: ' . $batch_size_limit . ')' );
		}

		// Defer term and comment counting for better performance
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );

		foreach ( $draft_trash_jobs as $job ) {
			$result = job_import_delete_post_efficiently( $job->ID );
			if ( $result ) {
				++$deleted_count;
				$log_entry = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Permanently deleted ' . $job->post_status . ' job ID: ' . $job->ID . ' - ' . $job->post_title;
				$logs[] = $log_entry;

				if ( class_exists( '\\Puntwork\\PuntWorkLogger' ) ) {
					\Puntwork\PuntWorkLogger::info(
						'Deleted draft/trash job during import cleanup',
						\Puntwork\PuntWorkLogger::CONTEXT_PURGE,
						array(
							'job_id'      => $job->ID,
							'post_status' => $job->post_status,
							'title'       => $job->post_title,
						)
					);
				}
			} else {
				$log_entry = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Error: Failed to delete job ID: ' . $job->ID;
				$logs[] = $log_entry;

				if ( class_exists( '\\Puntwork\\PuntWorkLogger' ) ) {
					\Puntwork\PuntWorkLogger::error(
						'Failed to delete draft/trash job during import cleanup',
						\Puntwork\PuntWorkLogger::CONTEXT_PURGE,
						array( 'job_id' => $job->ID )
					);
				}
			}
		}

		// Re-enable term and comment counting
		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );

		$end_time = microtime( true );
		$duration = $end_time - $start_time;

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [CLEANUP-AFTER-IMPORT] Cleanup completed in ' . number_format( $duration, 2 ) . 's, deleted ' . $deleted_count . ' jobs' );
		}

		return array(
			'success' => true,
			'deleted' => $deleted_count,
			'duration' => $duration,
			'logs' => $logs,
		);

	} catch ( \Exception $e ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [CLEANUP-AFTER-IMPORT] Exception during cleanup: ' . $e->getMessage() );
		}

		if ( class_exists( '\\Puntwork\\PuntWorkLogger' ) ) {
			\Puntwork\PuntWorkLogger::error(
				'Exception during draft/trash cleanup after import',
				\Puntwork\PuntWorkLogger::CONTEXT_PURGE,
				array( 'error' => $e->getMessage() )
			);
		}

		return array(
			'success' => false,
			'deleted' => $deleted_count,
			'error' => $e->getMessage(),
			'logs' => $logs,
		);
	}
}

/**
 * Remove jobs that are no longer present in the feeds after import completion.
 * This function is now much more conservative to prevent accidental data loss.
 *
 * @return array Purge result with deleted count and logs.
 */
function purge_old_jobs_not_in_feeds_after_import() {
	global $wpdb;

	$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;
	$start_time = microtime( true);
	$deleted_count = 0;
	$logs = array();

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [PURGE-AFTER-IMPORT] Starting purge of old jobs not in feeds' );
	}

	try {
		// SAFETY CHECK 1: Check if automatic purging is enabled
		$auto_purge_enabled = get_option( 'puntwork_auto_purge_old_jobs', false );
		if ( ! $auto_purge_enabled ) {
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [PURGE-AFTER-IMPORT] Automatic purging disabled, skipping purge' );
			}
			return array(
				'success' => true,
				'deleted' => 0,
				'logs' => array( 'Automatic purging disabled - use manual purge if needed' ),
			);
		}

		// Get processed GUIDs from the import
		$processed_guids = get_option( 'job_import_processed_guids', array() );

		if ( empty( $processed_guids ) ) {
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [PURGE-AFTER-IMPORT] No processed GUIDs found, skipping purge' );
			}
			return array(
				'success' => true,
				'deleted' => 0,
				'logs' => array( 'No processed GUIDs found, skipping purge' ),
			);
		}

		// SAFETY CHECK 2: Validate that import was complete and successful
		$import_status = get_option( 'job_import_status', array() );
		if ( empty( $import_status ) || ! isset( $import_status['complete'] ) || ! $import_status['complete'] ) {
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [PURGE-AFTER-IMPORT] Import not marked as complete, skipping purge' );
			}
			return array(
				'success' => true,
				'deleted' => 0,
				'logs' => array( 'Import not marked as complete, skipping purge' ),
			);
		}

		// SAFETY CHECK 3: Ensure we processed a reasonable number of jobs (not a failed partial import)
		$min_jobs_threshold = get_option( 'puntwork_purge_min_jobs_threshold', 10 );
		if ( count( $processed_guids ) < $min_jobs_threshold ) {
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [PURGE-AFTER-IMPORT] Only ' . count( $processed_guids ) . ' GUIDs processed, below threshold of ' . $min_jobs_threshold . ', skipping purge' );
			}
			return array(
				'success' => true,
				'deleted' => 0,
				'logs' => array( 'Too few GUIDs processed (' . count( $processed_guids ) . '), below safety threshold' ),
			);
		}

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [PURGE-AFTER-IMPORT] Found ' . count( $processed_guids ) . ' processed GUIDs' );
		}

		// Get purge age threshold (default 30 days)
		$purge_age_days = get_option( 'puntwork_purge_age_threshold_days', 30 );
		$purge_age_seconds = $purge_age_days * 24 * 60 * 60;
		$cutoff_date = date( 'Y-m-d H:i:s', time() - $purge_age_seconds );

		// Get all published jobs with GUIDs that are older than the threshold
		$all_jobs = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT p.ID, pm.meta_value AS guid, p.post_title, p.post_date
				FROM {$wpdb->posts} p
				JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'guid'
				WHERE p.post_type = 'job'
				AND p.post_status = 'publish'
				AND p.post_date < %s
				ORDER BY p.ID
			",
				$cutoff_date
			)
		);

		if ( empty( $all_jobs ) ) {
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [PURGE-AFTER-IMPORT] No published jobs older than ' . $purge_age_days . ' days found to check' );
			}
			return array(
				'success' => true,
				'deleted' => 0,
				'logs' => array( 'No jobs older than ' . $purge_age_days . ' days found to check' ),
			);
		}

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [PURGE-AFTER-IMPORT] Found ' . count( $all_jobs ) . ' published jobs older than ' . $purge_age_days . ' days to check' );
		}

		// Defer term and comment counting for better performance
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );

		foreach ( $all_jobs as $job ) {
			if ( ! in_array( $job->guid, $processed_guids ) ) {
				// SAFETY: Instead of immediate deletion, draft the job first
				// This allows recovery if the purge was incorrect
				$draft_result = wp_update_post( array(
					'ID' => $job->ID,
					'post_status' => 'draft'
				) );

				if ( $draft_result ) {
					++$deleted_count; // Count as "deleted" for compatibility, but it's actually drafted
					$log_entry = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'DRAFTED (not deleted) ID: ' . $job->ID . ' GUID: ' . $job->guid . ' - No longer in feed (aged ' . $purge_age_days . '+ days) - ' . $job->post_title;
					$logs[] = $log_entry;

					if ( class_exists( '\\Puntwork\\PuntWorkLogger' ) ) {
						\Puntwork\PuntWorkLogger::info(
							'Drafted old job not in feeds during import cleanup (safe purge)',
							\Puntwork\PuntWorkLogger::CONTEXT_PURGE,
							array(
								'job_id' => $job->ID,
								'guid'   => $job->guid,
								'title'  => $job->post_title,
								'age_days' => $purge_age_days,
							)
						);
					}
				} else {
					$log_entry = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Failed to draft ID: ' . $job->ID . ' GUID: ' . $job->guid;
					$logs[] = $log_entry;

					if ( class_exists( '\\Puntwork\\PuntWorkLogger' ) ) {
						\Puntwork\PuntWorkLogger::error(
							'Failed to draft old job during import cleanup',
							\Puntwork\PuntWorkLogger::CONTEXT_PURGE,
							array(
								'job_id' => $job->ID,
								'guid'   => $job->guid,
							)
						);
					}
				}
			}
		}

		// Re-enable term and comment counting
		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );

		$end_time = microtime( true );
		$duration = $end_time - $start_time;

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [PURGE-AFTER-IMPORT] Safe purge completed in ' . number_format( $duration, 2 ) . 's, drafted ' . $deleted_count . ' jobs' );
		}

		return array(
			'success' => true,
			'deleted' => $deleted_count,
			'duration' => $duration,
			'logs' => $logs,
		);

	} catch ( \Exception $e ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [PURGE-AFTER-IMPORT] Exception during purge: ' . $e->getMessage() );
		}

		if ( class_exists( '\\Puntwork\\PuntWorkLogger' ) ) {
			\Puntwork\PuntWorkLogger::error(
				'Exception during old jobs purge after import',
				\Puntwork\PuntWorkLogger::CONTEXT_PURGE,
				array( 'error' => $e->getMessage() )
			);
		}

		return array(
			'success' => false,
			'deleted' => $deleted_count,
			'error' => $e->getMessage(),
			'logs' => $logs,
		);
	}
}

/**
 * Aggressively clean up jobs that are no longer present in the current feed.
 * This function deletes (not just drafts) jobs that are not in the current feed,
 * addressing the user's need to remove old jobs they don't want.
 *
 * @return array Cleanup result with deleted count and logs.
 */
function cleanup_jobs_not_in_current_feed() {
	global $wpdb;

	$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;
	$start_time = microtime( true );
	$deleted_count = 0;
	$logs = array();

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [AGGRESSIVE-CLEANUP] Starting aggressive cleanup of jobs not in current feed' );
	}

	try {
		// Get processed GUIDs from the import (these are the jobs currently in the feed)
		$processed_guids = get_option( 'job_import_processed_guids', array() );

		if ( empty( $processed_guids ) ) {
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [AGGRESSIVE-CLEANUP] No processed GUIDs found, cannot determine current feed contents' );
			}
			return array(
				'success' => true,
				'deleted' => 0,
				'logs' => array( 'No processed GUIDs found - cannot determine current feed contents' ),
			);
		}

		// SAFETY CHECK: Validate that import was complete and successful
		$import_status = get_option( 'job_import_status', array() );
		if ( empty( $import_status ) || ! isset( $import_status['complete'] ) || ! $import_status['complete'] ) {
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [AGGRESSIVE-CLEANUP] Import not marked as complete, skipping aggressive cleanup' );
			}
			return array(
				'success' => true,
				'deleted' => 0,
				'logs' => array( 'Import not marked as complete, skipping aggressive cleanup' ),
			);
		}

		// Get all published jobs with GUIDs
		$all_published_jobs = $wpdb->get_results(
			"
			SELECT p.ID, pm.meta_value AS guid, p.post_title, p.post_date
			FROM {$wpdb->posts} p
			JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'guid'
			WHERE p.post_type = 'job'
			AND p.post_status = 'publish'
			ORDER BY p.ID
			"
		);

		if ( empty( $all_published_jobs ) ) {
			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [AGGRESSIVE-CLEANUP] No published jobs found to check' );
			}
			return array(
				'success' => true,
				'deleted' => 0,
				'logs' => array( 'No published jobs found to check' ),
			);
		}

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [AGGRESSIVE-CLEANUP] Found ' . count( $all_published_jobs ) . ' published jobs, checking against ' . count( $processed_guids ) . ' current feed GUIDs' );
		}

		// Defer term and comment counting for better performance
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );

		foreach ( $all_published_jobs as $job ) {
			// Check if this job's GUID is in the current feed
			if ( ! in_array( $job->guid, $processed_guids ) ) {
				// Job is not in current feed - delete it permanently
				$delete_result = job_import_delete_post_efficiently( $job->ID );

				if ( $delete_result ) {
					++$deleted_count;
					$log_entry = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'DELETED ID: ' . $job->ID . ' GUID: ' . $job->guid . ' - No longer in current feed - ' . $job->post_title;
					$logs[] = $log_entry;

					if ( class_exists( '\\Puntwork\\PuntWorkLogger' ) ) {
						\Puntwork\PuntWorkLogger::info(
							'Deleted job not in current feed during aggressive cleanup',
							\Puntwork\PuntWorkLogger::CONTEXT_PURGE,
							array(
								'job_id' => $job->ID,
								'guid'   => $job->guid,
								'title'  => $job->post_title,
								'reason' => 'not_in_current_feed',
							)
						);
					}
				} else {
					$log_entry = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Failed to delete ID: ' . $job->ID . ' GUID: ' . $job->guid;
					$logs[] = $log_entry;

					if ( class_exists( '\\Puntwork\\PuntWorkLogger' ) ) {
						\Puntwork\PuntWorkLogger::error(
							'Failed to delete job during aggressive cleanup',
							\Puntwork\PuntWorkLogger::CONTEXT_PURGE,
							array(
								'job_id' => $job->ID,
								'guid'   => $job->guid,
							)
						);
					}
				}
			}
		}

		// Re-enable term and comment counting
		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );

		$end_time = microtime( true );
		$duration = $end_time - $start_time;

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [AGGRESSIVE-CLEANUP] Aggressive cleanup completed in ' . number_format( $duration, 2 ) . 's, deleted ' . $deleted_count . ' jobs' );
		}

		return array(
			'success' => true,
			'deleted' => $deleted_count,
			'duration' => $duration,
			'logs' => $logs,
		);

	} catch ( \Exception $e ) {
		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [AGGRESSIVE-CLEANUP] Exception during aggressive cleanup: ' . $e->getMessage() );
		}

		if ( class_exists( '\\Puntwork\\PuntWorkLogger' ) ) {
			\Puntwork\PuntWorkLogger::error(
				'Exception during aggressive cleanup after import',
				\Puntwork\PuntWorkLogger::CONTEXT_PURGE,
				array( 'error' => $e->getMessage() )
			);
		}

		return array(
			'success' => false,
			'deleted' => $deleted_count,
			'error' => $e->getMessage(),
			'logs' => $logs,
		);
	}
}

/**
 * Efficiently delete a post using direct SQL queries to avoid memory overhead.
 * This bypasses wp_delete_post() which loads the entire post object and all metadata.
 *
 * @param int $post_id Post ID to delete
 * @return bool True on success, false on failure
 */
function job_import_delete_post_efficiently( $post_id ) {
	global $wpdb;

	$post_id = (int) $post_id;
	if ( ! $post_id ) {
		return false;
	}

	// Start transaction for data integrity
	$wpdb->query( 'START TRANSACTION' );

	try {
		// Delete post meta
		$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $post_id ) );

		// Delete term relationships
		$wpdb->delete( $wpdb->term_relationships, array( 'object_id' => $post_id ) );

		// Delete comments
		$wpdb->delete( $wpdb->comments, array( 'comment_post_ID' => $post_id ) );

		// Delete comment meta for these comments
		$comment_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID = %d",
			$post_id
		) );
		if ( ! empty( $comment_ids ) ) {
			$wpdb->query( "DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN (" . implode( ',', $comment_ids ) . ")" );
		}

		// Delete revisions
		$wpdb->delete( $wpdb->posts, array(
			'post_parent' => $post_id,
			'post_type'   => 'revision'
		) );

		// Finally delete the post itself
		$result = $wpdb->delete( $wpdb->posts, array( 'ID' => $post_id ) );

		if ( $result === false ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}

		// Commit transaction
		$wpdb->query( 'COMMIT' );

		// Clean caches without loading post object
		wp_cache_delete( $post_id, 'posts' );
		wp_cache_delete( $post_id, 'post_meta' );

		return true;

	} catch ( Exception $e ) {
		$wpdb->query( 'ROLLBACK' );
		error_log( '[PUNTWORK] [CLEANUP] SQL error in efficient deletion: ' . $e->getMessage() );
		return false;
	}
}
