<?php

/**
 * Feed download utilities.
 *
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function download_feed( $url, $feed_path, $output_dir, &$logs, &$format = null ) {
	$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;

	if ( $debug_mode ) {
		error_log( '[PUNTWORK] [DOWNLOAD-START] ===== DOWNLOAD_FEED START =====' );
		error_log( '[PUNTWORK] [DOWNLOAD-START] URL: ' . $url );
		error_log( '[PUNTWORK] [DOWNLOAD-START] Feed path: ' . $feed_path );
		error_log( '[PUNTWORK] [DOWNLOAD-START] Output dir: ' . $output_dir );
		error_log( '[PUNTWORK] [DOWNLOAD-START] Memory usage at start: ' . memory_get_usage( true ) . ' bytes' );
	}

	// Start tracing span for feed download (only if available)
	$span = null;
	if ( class_exists( '\Puntwork\PuntworkTracing' ) ) {
		$span = \Puntwork\PuntworkTracing::startActiveSpan(
			'download_feed',
			array(
				'feed.url'   => $url,
				'feed.path'  => $feed_path,
				'output.dir' => $output_dir,
			)
		);
	}

	try {
		// Handle both absolute and relative paths
		if ( strpos( $feed_path, '/' ) === 0 ) {
			// Absolute path
			$full_feed_path = $feed_path;
		} else {
			// Relative path - construct full path from output_dir
			$full_feed_path = $output_dir . $feed_path;
		}

		$real_output_dir = realpath( $output_dir );
		$real_feed_path  = realpath( dirname( $full_feed_path ) ) . '/' . basename( $full_feed_path );

		if ( $debug_mode ) {
			error_log( '[PUNTWORK] [DOWNLOAD-DEBUG] Full feed path: ' . $full_feed_path );
			error_log( '[PUNTWORK] [DOWNLOAD-DEBUG] Real output dir: ' . $real_output_dir );
			error_log( '[PUNTWORK] [DOWNLOAD-DEBUG] Real feed path: ' . $real_feed_path );
			error_log( '[PUNTWORK] [DOWNLOAD-DEBUG] Is writable: ' . ( is_writable( $output_dir ) ? 'yes' : 'no' ) );
		}

		if ( $real_output_dir == false || strpos( $real_feed_path, $real_output_dir ) !== 0 ) {
			error_log( '[PUNTWORK] [DOWNLOAD-ERROR] Invalid file path detected' );

			throw new \Exception( 'Invalid file path: Feed path must be within output directory' );
		}
		if ( ! is_writable( $output_dir ) ) {
			error_log( '[PUNTWORK] [DOWNLOAD-ERROR] Output directory not writable' );

			throw new \Exception( 'Output directory is not writable' );
		}

		// Check feed cache first
		$cache_key   = 'puntwork_feed_cache_' . md5( $url );
		$cached_feed = get_transient( $cache_key );

		if ( $cached_feed !== false && isset( $cached_feed['content'] ) && isset( $cached_feed['format'] ) ) {
			$cache_age = time() - $cached_feed['timestamp'];
			$max_age   = apply_filters( 'puntwork_feed_cache_max_age', HOUR_IN_SECONDS ); // 1 hour default, filterable

			if ( $cache_age < $max_age ) {
				if ( $debug_mode ) {
					error_log( '[PUNTWORK] [DOWNLOAD-CACHE] Using cached feed (age: ' . round( $cache_age / 60, 1 ) . ' minutes)' );
				}

				// Use cached content
				file_put_contents( $full_feed_path, $cached_feed['content'] );
				$format = $cached_feed['format'];

				$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' .
				"Used cached feed ($format): " . strlen( $cached_feed['content'] ) . ' bytes (cached ' . round( $cache_age / 60, 1 ) . ' minutes ago)';
				error_log( "Used cached feed ($format): " . strlen( $cached_feed['content'] ) . ' bytes (cached ' . round( $cache_age / 60, 1 ) . ' minutes ago)' );
				@chmod( $full_feed_path, 0644 );

				if ( $span ) {
					$span->setAttribute( 'feed.cached', true );
					$span->setAttribute( 'feed.size', strlen( $cached_feed['content'] ) );
					$span->setAttribute( 'feed.format', $format );
					$span->setAttribute( 'cache.age', $cache_age );
					$span->end();
				}

				if ( $debug_mode ) {
					error_log( '[PUNTWORK] [DOWNLOAD-END] ===== DOWNLOAD_FEED CACHE HIT =====' );
				}

				return true;
			} elseif ( $debug_mode ) {
					error_log( '[PUNTWORK] [DOWNLOAD-CACHE] Cache expired (age: ' . round( $cache_age / 60, 1 ) . ' minutes), downloading fresh feed' );
			}
		} elseif ( $debug_mode ) {
				error_log( '[PUNTWORK] [DOWNLOAD-CACHE] No cache found, downloading feed' );
		}

		// Check if URL is a local file path (for testing)
		$is_local_file   = false;
		$local_file_path = null;
		if ( strpos( $url, 'file://' ) === 0 ) {
			$is_local_file   = true;
			$local_file_path = substr( $url, 7 ); // Remove 'file://' prefix
		} elseif ( strpos( $url, '/' ) === 0 || strpos( $url, './' ) === 0 || strpos( $url, '../' ) === 0 ) {
			// Relative or absolute local path
			$is_local_file   = true;
			$local_file_path = $url;
		}

		try {
			if ( $is_local_file ) {
				if ( $debug_mode ) {
					error_log( '[PUNTWORK] [DOWNLOAD-DEBUG] Using local file copy for: ' . $local_file_path );
				}
				if ( ! file_exists( $local_file_path ) ) {
					throw new \Exception( 'Local file does not exist: ' . $local_file_path );
				}
				if ( ! copy( $local_file_path, $full_feed_path ) ) {
					throw new \Exception( 'Failed to copy local file from ' . $local_file_path . ' to ' . $full_feed_path );
				}
				if ( $debug_mode ) {
					error_log( '[PUNTWORK] [DOWNLOAD-DEBUG] Local file copied successfully' );
				}
			} elseif ( function_exists( 'curl_init' ) ) {
				if ( $debug_mode ) {
					error_log( '[PUNTWORK] [DOWNLOAD-DEBUG] Using cURL for download' );
				}
				$ch = curl_init( $url );
				$fp = fopen( $full_feed_path, 'w' );
				if ( ! $fp ) {
					error_log( '[PUNTWORK] [DOWNLOAD-ERROR] Failed to open file for writing: ' . $full_feed_path );

					throw new \Exception( "Can't open $full_feed_path for write" );
				}
				curl_setopt( $ch, CURLOPT_FILE, $fp );
				curl_setopt( $ch, CURLOPT_TIMEOUT, 300 );
				curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
				curl_setopt( $ch, CURLOPT_USERAGENT, 'WordPress puntWork Importer' );
				$success    = curl_exec( $ch );
				$http_code  = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
				$curl_error = curl_error( $ch );
				curl_close( $ch );
				fclose( $fp );

				if ( $debug_mode ) {
					error_log( '[PUNTWORK] [DOWNLOAD-DEBUG] cURL success: ' . ( $success ? 'true' : 'false' ) );
					error_log( '[PUNTWORK] [DOWNLOAD-DEBUG] HTTP code: ' . $http_code );
					error_log( '[PUNTWORK] [DOWNLOAD-DEBUG] cURL error: ' . $curl_error );
					error_log( '[PUNTWORK] [DOWNLOAD-DEBUG] File size: ' . filesize( $full_feed_path ) );
				}

				if ( ! $success || $http_code !== 200 || filesize( $full_feed_path ) < 10 ) {
					$error_details = "cURL download failed (HTTP $http_code, size: " . filesize( $full_feed_path ) . ' bytes, error: ' . $curl_error . ', URL: ' . $url . ')';
					error_log( '[PUNTWORK] [DOWNLOAD-ERROR] ' . $error_details );

					throw new \Exception( $error_details );
				}
			} else {
				if ( $debug_mode ) {
					error_log( '[PUNTWORK] [DOWNLOAD-DEBUG] Using wp_remote_get for download' );
				}
				$response = wp_remote_get( $url, array( 'timeout' => 300 ) );
				if ( is_wp_error( $response ) ) {
					error_log( '[PUNTWORK] [DOWNLOAD-ERROR] wp_remote_get error: ' . $response->get_error_message() );

					throw new \Exception( $response->get_error_message() );
				}
				$body = wp_remote_retrieve_body( $response );
				if ( $debug_mode ) {
					error_log( '[PUNTWORK] [DOWNLOAD-DEBUG] Response body length: ' . strlen( $body ) );
				}
				if ( empty( $body ) || strlen( $body ) < 10 ) {
					error_log( '[PUNTWORK] [DOWNLOAD-ERROR] Empty or small response' );

					throw new \Exception( 'Empty or small response' );
				}
				file_put_contents( $full_feed_path, $body );
				if ( $debug_mode ) {
					error_log( '[PUNTWORK] [DOWNLOAD-DEBUG] File written successfully' );
				}
			}

			// Detect format from downloaded content
			$content = file_get_contents( $full_feed_path );
			$format  = \Puntwork\FeedProcessor::detectFormat( $url, $content );

			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [DOWNLOAD-DEBUG] Detected format: ' . $format );
				error_log( '[PUNTWORK] [DOWNLOAD-DEBUG] Content preview: ' . substr( $content, 0, 200 ) );
			}

			// Cache the downloaded feed content
			$cache_data = array(
				'content'   => $content,
				'format'    => $format,
				'timestamp' => time(),
				'url'       => $url,
			);
			set_transient( $cache_key, $cache_data, apply_filters( 'puntwork_feed_cache_expiration', 1 * HOUR_IN_SECONDS ) ); // 1 hour default

			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [DOWNLOAD-CACHE] Feed cached for 1 hour' );
			}

			$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' .
			"Downloaded feed ($format): " . filesize( $full_feed_path ) . ' bytes';
			error_log( "Downloaded feed ($format): " . filesize( $full_feed_path ) . ' bytes' );
			@chmod( $full_feed_path, 0644 );

			if ( $span ) {
				$span->setAttribute( 'feed.cached', false );
				$span->setAttribute( 'feed.size', filesize( $full_feed_path ) );
				$span->setAttribute( 'feed.format', $format );
				$span->end();
			}

			if ( $debug_mode ) {
				error_log( '[PUNTWORK] [DOWNLOAD-END] ===== DOWNLOAD_FEED SUCCESS =====' );
			}

			return true;
		} catch ( \Exception $e ) {
			error_log( '[PUNTWORK] [DOWNLOAD-ERROR] Download exception: ' . $e->getMessage() );
			$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Download error: ' . $e->getMessage();

			if ( $span ) {
				$span->recordException( $e );
				$span->setStatus( \OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR, $e->getMessage() );
				$span->end();
			}

			return false;
		}
	} catch ( \Exception $e ) {
		error_log( '[PUNTWORK] [DOWNLOAD-ERROR] Outer download exception: ' . $e->getMessage() );
		$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . 'Outer download error: ' . $e->getMessage();
		if ( $span ) {
			$span->recordException( $e );
			$span->setStatus( \OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR, $e->getMessage() );
			$span->end();
		}

		return false;
	}
}
