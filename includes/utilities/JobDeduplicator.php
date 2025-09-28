<?php

/**
 * Advanced Job Deduplication Algorithms
 *
 * @package    Puntwork
 * @subpackage Utilities
 * @since      1.0.14
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Advanced deduplication system with multiple algorithms
 */
class JobDeduplicator {

	// Similarity thresholds
	public const EXACT_MATCH       = 1.0;
	public const HIGH_SIMILARITY   = 0.85;
	public const MEDIUM_SIMILARITY = 0.70;
	public const LOW_SIMILARITY    = 0.50;

	// Deduplication strategies
	public const STRATEGY_GUID          = 'guid';
	public const STRATEGY_TITLE_COMPANY = 'title_company';
	public const STRATEGY_CONTENT_HASH  = 'content_hash';
	public const STRATEGY_FUZZY_TITLE   = 'fuzzy_title';

	/**
	 * Configuration for deduplication rules
	 */
	private static $config = array(
		'enable_fuzzy_matching'        => false, // Disabled to prevent N+1 queries during import
		'title_similarity_threshold'   => 0.85,
		'company_similarity_threshold' => 0.90,
		'max_candidates'               => 10,
		'strategies'                   => array(
			self::STRATEGY_GUID,
			self::STRATEGY_TITLE_COMPANY,
			self::STRATEGY_CONTENT_HASH,
			self::STRATEGY_FUZZY_TITLE,
		),
	);

	/**
	 * Find potential duplicates for a job item
	 *
	 * @param  object $job_item      Job item to check for duplicates
	 * @param  array  $existing_jobs Array of existing job posts
	 * @return array Array of potential duplicates with similarity scores
	 */
	public static function findDuplicates( $job_item, $existing_jobs = null ) {
		if ( $existing_jobs === null ) {
			$existing_jobs = self::getExistingJobsForComparison( $job_item );
		}

		$duplicates = array();

		foreach ( $existing_jobs as $existing_job ) {
			$similarity = self::calculateSimilarity( $job_item, $existing_job );

			if ( $similarity >= self::$config['title_similarity_threshold'] ) {
				$duplicates[] = array(
					'post_id'    => $existing_job->ID,
					'similarity' => $similarity,
					'reasons'    => self::getSimilarityReasons( $job_item, $existing_job ),
					'strategy'   => self::determineMatchingStrategy( $job_item, $existing_job ),
				);
			}
		}

		// Sort by similarity (highest first)
		usort(
			$duplicates,
			function ( $a, $b ) {
				return $b['similarity'] <=> $a['similarity'];
			}
		);

		return array_slice( $duplicates, 0, self::$config['max_candidates'] );
	}

	/**
	 * Calculate overall similarity between two job items
	 */
	private static function calculateSimilarity( $job1, $job2 ) {
		$similarity = 0;
		$weights    = 0;

		// Title similarity (highest weight)
		$title_sim   = self::calculateTextSimilarity(
			self::normalizeText( $job1->title ?? '' ),
			self::normalizeText( $job2->post_title ?? '' )
		);
		$similarity += $title_sim * 0.4;
		$weights    += 0.4;

		// Company similarity
		$company_sim = self::calculateTextSimilarity(
			self::normalizeText( $job1->company ?? $job1->companyname ?? '' ),
			self::normalizeText( get_post_meta( $job2->ID, 'company', true ) ?? '' )
		);
		$similarity += $company_sim * 0.3;
		$weights    += 0.3;

		// Location similarity
		$location_sim = self::calculateTextSimilarity(
			self::normalizeText( $job1->location ?? $job1->city ?? '' ),
			self::normalizeText( get_post_meta( $job2->ID, 'location', true ) ?? '' )
		);
		$similarity  += $location_sim * 0.2;
		$weights     += 0.2;

		// Content similarity (lower weight)
		$content_sim = self::calculateTextSimilarity(
			self::normalizeText( $job1->description ?? '' ),
			self::normalizeText( $job2->post_content ?? '' )
		);
		$similarity += $content_sim * 0.1;
		$weights    += 0.1;

		return $weights > 0 ? $similarity / $weights : 0;
	}

	/**
	 * Calculate text similarity using multiple algorithms
	 */
	private static function calculateTextSimilarity( $text1, $text2 ) {
		if ( empty( $text1 ) || empty( $text2 ) ) {
			return empty( $text1 ) && empty( $text2 ) ? 1.0 : 0.0;
		}

		// Exact match
		if ( strtolower( $text1 ) === strtolower( $text2 ) ) {
			return self::EXACT_MATCH;
		}

		// Levenshtein distance for short strings
		if ( strlen( $text1 ) < 100 && strlen( $text2 ) < 100 ) {
			$levenshtein = levenshtein( strtolower( $text1 ), strtolower( $text2 ) );
			$max_len     = max( strlen( $text1 ), strlen( $text2 ) );
			return $max_len > 0 ? 1 - ( $levenshtein / $max_len ) : 0;
		}

		// Jaccard similarity for longer texts
		return self::jaccardSimilarity( $text1, $text2 );
	}

	/**
	 * Calculate Jaccard similarity coefficient
	 */
	private static function jaccardSimilarity( $text1, $text2 ) {
		$words1 = self::getWordTokens( $text1 );
		$words2 = self::getWordTokens( $text2 );

		if ( empty( $words1 ) && empty( $words2 ) ) {
			return 1.0;
		}

		$intersection = array_intersect( $words1, $words2 );
		$union        = array_unique( array_merge( $words1, $words2 ) );

		return count( $union ) > 0 ? count( $intersection ) / count( $union ) : 0;
	}

	/**
	 * Tokenize text into words
	 */
	private static function getWordTokens( $text ) {
		// Remove punctuation and normalize
		$text  = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $text );
		$text  = preg_replace( '/\s+/', ' ', $text );
		$words = explode( ' ', strtolower( trim( $text ) ) );

		// Filter out common stop words and short words
		$stop_words = array( 'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should', 'may', 'might', 'must', 'can', 'shall' );

		return array_filter(
			$words,
			function ( $word ) use ( $stop_words ) {
				return strlen( $word ) > 2 && ! in_array( $word, $stop_words );
			}
		);
	}

	/**
	 * Normalize text for comparison
	 */
	private static function normalizeText( $text ) {
		// Convert to lowercase and trim
		$text = strtolower( trim( $text ) );

		// Remove extra whitespace
		$text = preg_replace( '/\s+/', ' ', $text );

		// Remove common prefixes/suffixes that don't affect meaning
		$prefixes_to_remove = array( 'job:', 'position:', 'vacancy:', 'opening:' );
		$suffixes_to_remove = array( 'job', 'position', 'vacancy', 'opening' );

		foreach ( $prefixes_to_remove as $prefix ) {
			if ( strpos( $text, $prefix ) === 0 ) {
				$text = trim( substr( $text, strlen( $prefix ) ) );
				break;
			}
		}

		foreach ( $suffixes_to_remove as $suffix ) {
			if ( substr( $text, -strlen( $suffix ) ) === $suffix ) {
				$text = trim( substr( $text, 0, -strlen( $suffix ) ) );
				break;
			}
		}

		return $text;
	}

	/**
	 * Get reasons why items are considered similar
	 */
	private static function getSimilarityReasons( $job1, $job2 ) {
		$reasons = array();

		// Check title similarity
		$title_sim = self::calculateTextSimilarity(
			self::normalizeText( $job1->title ?? '' ),
			self::normalizeText( $job2->post_title ?? '' )
		);
		if ( $title_sim >= self::HIGH_SIMILARITY ) {
			$reasons[] = 'Similar title';
		}

		// Check company similarity
		$company_sim = self::calculateTextSimilarity(
			self::normalizeText( $job1->company ?? $job1->companyname ?? '' ),
			self::normalizeText( get_post_meta( $job2->ID, 'company', true ) ?? '' )
		);
		if ( $company_sim >= self::HIGH_SIMILARITY ) {
			$reasons[] = 'Same company';
		}

		// Check location similarity
		$location_sim = self::calculateTextSimilarity(
			self::normalizeText( $job1->location ?? $job1->city ?? '' ),
			self::normalizeText( get_post_meta( $job2->ID, 'location', true ) ?? '' )
		);
		if ( $location_sim >= self::MEDIUM_SIMILARITY ) {
			$reasons[] = 'Similar location';
		}

		return $reasons;
	}

	/**
	 * Determine which strategy was used for matching
	 */
	private static function determineMatchingStrategy( $job1, $job2 ) {
		// Check GUID match
		if ( isset( $job1->guid ) && ! empty( $job1->guid ) ) {
			$existing_guid = get_post_meta( $job2->ID, 'guid', true );
			if ( $existing_guid === $job1->guid ) {
				return self::STRATEGY_GUID;
			}
		}

		// Check title + company combination
		$title_sim   = self::calculate_text_similarity(
			self::normalize_text( $job1->title ?? '' ),
			self::normalize_text( $job2->post_title ?? '' )
		);
		$company_sim = self::calculate_text_similarity(
			self::normalize_text( $job1->company ?? $job1->companyname ?? '' ),
			self::normalize_text( get_post_meta( $job2->ID, 'company', true ) ?? '' )
		);

		if ( $title_sim >= self::HIGH_SIMILARITY && $company_sim >= self::HIGH_SIMILARITY ) {
			return self::STRATEGY_TITLE_COMPANY;
		}

		// Check content hash
		$content_hash  = self::generateContentHash( $job1 );
		$existing_hash = get_post_meta( $job2->ID, '_import_hash', true );
		if ( $content_hash === $existing_hash ) {
			return self::STRATEGY_CONTENT_HASH;
		}

		return self::STRATEGY_FUZZY_TITLE;
	}

	/**
	 * Generate content hash for comparison
	 */
	private static function generateContentHash( $job ) {
		$content  = '';
		$content .= $job->title ?? '';
		$content .= $job->company ?? $job->companyname ?? '';
		$content .= $job->location ?? $job->city ?? '';
		$content .= $job->description ?? '';

		return md5( strtolower( trim( $content ) ) );
	}

	/**
	 * Get existing jobs for comparison (with caching)
	 */
	private static function getExistingJobsForComparison( $job_item ) {
		$cache_key = 'puntwork_dedup_jobs_' . md5( $job_item->title ?? '' . $job_item->company ?? '' );
		$cached    = wp_cache_get( $cache_key );

		if ( $cached !== false ) {
			return $cached;
		}

		// Query recent jobs that might be duplicates
		$args = array(
			'post_type'      => 'job',
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => 50,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'date_query'     => array(
				'after' => '30 days ago',
			),
		);

		// If we have company info, filter by company
		if ( ! empty( $job_item->company ?? $job_item->companyname ?? '' ) ) {
			$args['meta_query'] = array(
				array(
					'key'     => 'company',
					'value'   => $job_item->company ?? $job_item->companyname ?? '',
					'compare' => 'LIKE',
				),
			);
		}

		$jobs = get_posts( $args );
		wp_cache_set( $cache_key, $jobs, '', 3600 ); // Cache for 1 hour

		return $jobs;
	}

	/**
	 * Enhanced duplicate handling with advanced algorithms
	 */
	public static function handleDuplicatesAdvanced( $batch_guids, $existing_by_guid, &$logs, &$duplicates_drafted, &$post_ids_by_guid ) {
		global $wpdb;

		// First, handle exact GUID matches with existing logic
		self::handleExactGuidDuplicates( $batch_guids, $existing_by_guid, $logs, $duplicates_drafted, $post_ids_by_guid );

		// Then, apply fuzzy matching for remaining items
		if ( self::$config['enable_fuzzy_matching'] ) {
			self::handleFuzzyDuplicates( $batch_guids, $logs, $duplicates_drafted, $post_ids_by_guid );
		}
	}

	/**
	 * Handle exact GUID duplicates (existing logic)
	 */
	private static function handleExactGuidDuplicates( $batch_guids, $existing_by_guid, &$logs, &$duplicates_drafted, &$post_ids_by_guid ) {
		global $wpdb;

		foreach ( $batch_guids as $guid ) {
			if ( isset( $existing_by_guid[ $guid ] ) ) {
				$posts_data = $existing_by_guid[ $guid ];
				if ( count( $posts_data ) > 1 ) {
					// Extract post IDs for duplicate processing
					$post_ids = array();
					foreach ( $posts_data as $item ) {
						if ( is_array( $item ) && isset( $item['id'] ) ) {
							$post_ids[] = $item['id'];
						} else {
							$post_ids[] = $item;
						}
					}

					$existing = get_posts(
						array(
							'post_type'      => 'job',
							'post__in'       => $post_ids,
							'posts_per_page' => -1,
							'post_status'    => 'any',
							'fields'         => 'ids',
						)
					) ?: array();

					if ( empty( $existing ) ) {
						continue; // No posts found, skip
					}

					$post_to_keep        = null;
					$duplicates_to_draft = array();

					// BULK LOAD all required metadata and post fields to avoid N+1 queries
					$placeholders   = implode( ',', array_fill( 0, count( $existing ), '%d' ) );
					$hashes_query   = $wpdb->prepare(
						"SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_import_hash' AND post_id IN ($placeholders)",
						$existing
					);
					$hashes_results = $wpdb->get_results( $hashes_query, OBJECT_K );
					$hashes         = array();
					foreach ( $hashes_results as $row ) {
						$hashes[ $row->post_id ] = $row->meta_value;
					}

					// Batch load post_modified and post_title
					$posts_query   = $wpdb->prepare(
						"SELECT ID, post_modified, post_title FROM $wpdb->posts WHERE ID IN ($placeholders)",
						$existing
					);
					$posts_results = $wpdb->get_results( $posts_query, OBJECT_K );
					$post_modified = array();
					$post_titles   = array();
					foreach ( $posts_results as $post_id => $post ) {
						$post_modified[ $post_id ] = $post->post_modified;
						$post_titles[ $post_id ]   = $post->post_title;
					}

					foreach ( $existing as $post_id ) {
						if ( $post_to_keep === null ) {
							$post_to_keep = $post_id;
						} else {
							// If hashes are identical, draft the duplicate
							if ( $hashes[ $post_to_keep ] === $hashes[ $post_id ] ) {
								$duplicates_to_draft[] = $post_id;
							} else {
								// If hashes differ, keep the most recently modified
								if ( strtotime( $post_modified[ $post_id ] ) > strtotime( $post_modified[ $post_to_keep ] ) ) {
										$duplicates_to_draft[] = $post_to_keep;
										$post_to_keep          = $post_id;
								} else {
									$duplicates_to_draft[] = $post_id;
								}
							}
						}
					}

					// Draft duplicates instead of deleting them, and append reason to title
					foreach ( $duplicates_to_draft as $dup_id ) {
						// Get current title from batched data
						$current_title = $post_titles[ $dup_id ] ?? '';

						// Determine reason for drafting
						$reason = 'Duplicate - ';
						if ( $hashes[ $dup_id ] === $hashes[ $post_to_keep ] ) {
							$reason .= 'Identical content';
						} else {
							$reason .= 'Older version kept';
						}

						// Append reason to title if not already present
						if ( strpos( $current_title, $reason ) === false ) {
							$new_title = $current_title . ' [' . $reason . ']';
						} else {
							$new_title = $current_title;
						}

						// Update post to draft status and modify title
						wp_update_post(
							array(
								'ID'          => $dup_id,
								'post_title'  => $new_title,
								'post_status' => 'draft',
							)
						);

						++$duplicates_drafted;
						$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Drafted duplicate ID: ' . $dup_id . ' GUID: ' . $guid . ' - ' . $reason;
						error_log( '[PUNTWORK] [DUPLICATES-DEBUG] Drafted duplicate ID: ' . $dup_id . ' GUID: ' . $guid . ' - ' . $reason );
					}
					$post_ids_by_guid[ $guid ] = $post_to_keep;
				} else {
					// Single existing post for this GUID
					$first_item                = $posts_data[0];
					$post_ids_by_guid[ $guid ] = is_array( $first_item ) && isset( $first_item['id'] ) ? $first_item['id'] : $first_item;
				}
			}
		}
	}

	/**
	 * Handle fuzzy duplicates using advanced algorithms
	 */
	private static function handleFuzzyDuplicates( $batch_guids, &$logs, &$duplicates_drafted, &$post_ids_by_guid ) {
		// Get all jobs in current batch that don't have exact GUID matches
		$batch_jobs = array();
		foreach ( $batch_guids as $guid ) {
			if ( ! isset( $post_ids_by_guid[ $guid ] ) ) {
				// This GUID doesn't have an exact match, get the job data
				$batch_jobs[ $guid ] = self::getJobDataByGuid( $guid );
			}
		}

		// Check each batch job against existing jobs
		foreach ( $batch_jobs as $guid => $job_data ) {
			if ( ! $job_data ) {
				continue;
			}

			$duplicates = self::findDuplicates( $job_data );

			if ( ! empty( $duplicates ) ) {
				$best_match = $duplicates[0];

				if ( $best_match['similarity'] >= self::$config['title_similarity_threshold'] ) {
					// Mark this as a duplicate of the existing job
					$post_ids_by_guid[ $guid ] = $best_match['post_id'];

					$reasons = implode( ', ', $best_match['reasons'] );
					$logs[]  = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . sprintf(
						'Fuzzy duplicate detected: GUID %s matches existing job ID %d (similarity: %.2f) - %s',
						$guid,
						$best_match['post_id'],
						$best_match['similarity'],
						$reasons
					);
				}
			}
		}

		// Use AI-powered similarity detection for content-based duplicates
		if ( class_exists( '\\Puntwork\\AI\\DuplicateDetector' ) ) {
			self::handleAiDuplicates( $logs, $duplicates_drafted );
		}
	}

	/**
	 * Handle AI-powered content similarity duplicates
	 */
	private static function handleAiDuplicates( &$logs, &$duplicates_drafted ) {
		global $wpdb;

		// Get all published jobs for AI similarity analysis
		$existing_jobs = get_posts(
			array(
				'post_type'      => 'job',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
			)
		);

		if ( empty( $existing_jobs ) || count( $existing_jobs ) < 2 ) {
			return;
		}

		// BULK LOAD all required metadata and post fields to avoid N+1 queries
		$placeholders = implode( ',', array_fill( 0, count( $existing_jobs ), '%d' ) );

		// Batch load post titles
		$posts_query   = $wpdb->prepare(
			"SELECT ID, post_title FROM $wpdb->posts WHERE ID IN ($placeholders)",
			$existing_jobs
		);
		$posts_results = $wpdb->get_results( $posts_query, OBJECT_K );
		$post_titles   = array();
		foreach ( $posts_results as $post_id => $post ) {
			$post_titles[ $post_id ] = $post->post_title;
		}

		// Batch load metadata for job_description and company
		$meta_keys    = array( 'job_description', 'company' );
		$meta_query   = $wpdb->prepare(
			"SELECT post_id, meta_key, meta_value FROM $wpdb->postmeta
             WHERE post_id IN ($placeholders) AND meta_key IN ('job_description', 'company')",
			$existing_jobs
		);
		$meta_results = $wpdb->get_results( $meta_query );
		$meta_data    = array();
		foreach ( $meta_results as $row ) {
			if ( ! isset( $meta_data[ $row->post_id ] ) ) {
				$meta_data[ $row->post_id ] = array();
			}
			$meta_data[ $row->post_id ][ $row->meta_key ] = $row->meta_value;
		}

		// Build job data array for AI similarity detection
		$job_data = array();
		foreach ( $existing_jobs as $post_id ) {
			$job_data[] = array(
				'job_title'       => $post_titles[ $post_id ] ?? '',
				'job_description' => $meta_data[ $post_id ]['job_description'] ?? '',
				'job_company'     => $meta_data[ $post_id ]['company'] ?? '',
			);
		}

		// Detect duplicate groups using AI algorithms
		$duplicate_groups = AI\DuplicateDetector::detectDuplicates( $job_data );

		// Process duplicate groups
		foreach ( $duplicate_groups as $group_indices ) {
			if ( count( $group_indices ) < 2 ) {
				continue;
			}

			// Map indices back to post IDs
			$post_ids_in_group = array_map(
				function ( $index ) use ( $existing_jobs ) {
					return $existing_jobs[ $index ];
				},
				$group_indices
			);

			// Sort by post ID to keep the oldest (lowest ID)
			sort( $post_ids_in_group );
			$keep_id    = $post_ids_in_group[0];
			$duplicates = array_slice( $post_ids_in_group, 1 );

			// Draft the AI-detected duplicates
			foreach ( $duplicates as $dup_id ) {
				$current_title = $post_titles[ $dup_id ] ?? '';
				$new_title     = strpos( $current_title, 'Duplicate - ' ) === false ?
				$current_title . ' [Duplicate - AI Content Similarity]' : $current_title;

				wp_update_post(
					array(
						'ID'          => $dup_id,
						'post_title'  => $new_title,
						'post_status' => 'draft',
					)
				);

					++$duplicates_drafted;
					$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Drafted AI-detected duplicate ID: ' . $dup_id . ' - Content similarity with ID: ' . $keep_id;
					error_log( '[PUNTWORK] [DUPLICATES-DEBUG] Drafted AI-detected duplicate ID: ' . $dup_id . ' - Content similarity with ID: ' . $keep_id );
			}
		}
	}

	/**
	 * Get job data by GUID from current import batch
	 */
	private static function getJobDataByGuid( $guid ) {
		// This would need to be implemented to get job data from the current batch
		// For now, return null - this would be integrated with the import process
		return null;
	}

	/**
	 * Update deduplication configuration
	 */
	public static function updateConfig( $new_config ) {
		self::$config = array_merge( self::$config, $new_config );
	}

	/**
	 * Get current configuration
	 */
	public static function getConfig() {
		return self::$config;
	}
}
