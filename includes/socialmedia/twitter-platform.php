<?php

/**
 * Twitter/X Social Media Integration
 *
 * @package    Puntwork
 * @subpackage SocialMedia
 * @since      2.2.0
 */

namespace Puntwork\SocialMedia;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Twitter/X platform integration
 */
class TwitterPlatform extends SocialMediaPlatform {

	/**
	 * Ads manager instance
	 */
	private ?TwitterAdsManager $ads_manager = null;

	/**
	 * API base URL
	 */
	private string $api_base = 'https://api.twitter.com/2';

	/**
	 * Ads API base URL
	 */
	private string $ads_api_base = 'https://ads-api.twitter.com/12';

	/**
	 * Constructor
	 */
	public function __construct( array $config = array() ) {
		$this->platform_id   = 'twitter';
		$this->platform_name = 'Twitter/X';
		$this->rate_limits   = array(
			'posts_per_hour' => 300,  // Twitter allows 300 posts per 3 hours
			'posts_per_day'  => 5000,   // Conservative daily limit
		);

		parent::__construct( $config );

		// Initialize ads manager if ads credentials are provided
		if ( $this->hasAdsCredentials() ) {
			$this->ads_manager = new TwitterAdsManager( $this );
		}
	}

	/**
	 * Check if Twitter is properly configured
	 */
	public function isConfigured(): bool {
		return parent::isConfigured() &&
		isset( $this->credentials['api_key'] ) &&
		isset( $this->credentials['api_secret'] ) &&
		isset( $this->credentials['access_token'] ) &&
		isset( $this->credentials['access_token_secret'] );
	}

	/**
	 * Check if ads credentials are configured
	 */
	public function hasAdsCredentials(): bool {
		return isset( $this->credentials['ads_account_id'] ) &&
		isset( $this->credentials['bearer_token'] );
	}

	/**
	 * Check if ads functionality is available
	 */
	public function supportsAds(): bool {
		return $this->ads_manager !== null;
	}

	/**
	 * Post content to Twitter
	 */
	public function post( array $content, array $options = array() ): array {
		if ( ! $this->isConfigured() ) {
			throw new \Exception( 'Twitter integration not properly configured' );
		}

		// Validate content
		$validation_errors = $this->validateContent( $content );
		if ( ! empty( $validation_errors ) ) {
			throw new \Exception( 'Content validation failed: ' . implode( ', ', $validation_errors ) );
		}

		try {
			$post_data = $this->preparePostData( $content, $options );
			$response  = $this->makeApiRequest( 'tweets', $post_data, 'POST' );

			$this->recordPost();

			return array(
				'success'   => true,
				'post_id'   => $response['data']['id'] ?? null,
				'url'       => isset( $response['data']['id'] ) ? "https://twitter.com/i/status/{$response['data']['id']}" : null,
				'platform'  => 'twitter',
				'timestamp' => time(),
			);
		} catch ( \Exception $e ) {
			PuntWorkLogger::error(
				'Twitter posting failed',
				PuntWorkLogger::CONTEXT_SOCIAL,
				array(
					'error'   => $e->getMessage(),
					'content' => $content,
				)
			);

			return array(
				'success'   => false,
				'error'     => $e->getMessage(),
				'platform'  => 'twitter',
				'timestamp' => time(),
			);
		}
	}

	/**
	 * Post content with ads campaign
	 */
	public function postWithAds( array $content, array $ads_config ): array {
		if ( ! $this->supportsAds() ) {
			throw new \Exception( 'Twitter ads not configured. Please provide ads_account_id and bearer_token.' );
		}

		// First post the regular tweet
		$post_result = $this->post( $content );

		if ( ! $post_result['success'] ) {
			return $post_result;
		}

		try {
			// Create ads campaign for the tweet
			$ads_result = $this->ads_manager->postJobWithAds(
				array( 'tweet_id' => $post_result['post_id'] ),
				$ads_config
			);

			return array_merge(
				$post_result,
				array(
					'ads_campaign' => $ads_result,
					'has_ads'      => true,
				)
			);
		} catch ( \Exception $e ) {
			PuntWorkLogger::error(
				'Twitter ads creation failed',
				PuntWorkLogger::CONTEXT_SOCIAL,
				array(
					'tweet_id' => $post_result['post_id'],
					'error'    => $e->getMessage(),
				)
			);

			// Return the successful tweet post but with ads error
			return array_merge(
				$post_result,
				array(
					'ads_error' => $e->getMessage(),
					'has_ads'   => false,
				)
			);
		}
	}

	/**
	 * Get ads campaign metrics
	 */
	public function getAdsMetrics( string $campaign_id ): array {
		if ( ! $this->supportsAds() ) {
			throw new \Exception( 'Twitter ads not configured' );
		}

		return $this->ads_manager->getCampaignMetrics(
			$campaign_id,
			$this->credentials['ads_account_id']
		);
	}

	/**
	 * Get posting limits and remaining quota
	 */
	public function getLimits(): array {
		$transient_key = 'socialmedia_ratelimit_' . $this->platform_id;
		$posts_today   = get_transient( $transient_key ) ?: 0;

		$hourly_key = $transient_key . '_hour_' . date( 'Y-m-d-H' );
		$posts_hour = get_transient( $hourly_key ) ?: 0;

		return array(
			'posts_today'       => $posts_today,
			'posts_today_limit' => $this->rate_limits['posts_per_day'],
			'posts_hour'        => $posts_hour,
			'posts_hour_limit'  => $this->rate_limits['posts_per_hour'],
			'remaining_today'   => max( 0, $this->rate_limits['posts_per_day'] - $posts_today ),
			'remaining_hour'    => max( 0, $this->rate_limits['posts_per_hour'] - $posts_hour ),
		);
	}

	/**
	 * Get maximum text length
	 */
	protected function getMaxTextLength(): int {
		return 280;
	}

	/**
	 * Prepare post data for Twitter API
	 */
	private function preparePostData( array $content, array $options ): array {
		$post_data = array();

		// Add text content
		if ( ! empty( $content['text'] ) ) {
			$text              = $this->processTextContent( $content['text'], $options );
			$post_data['text'] = $text;
		}

		// Add media if provided
		if ( ! empty( $content['media'] ) ) {
			$media_ids = $this->uploadMedia( $content['media'] );
			if ( ! empty( $media_ids ) ) {
				$post_data['media'] = array( 'media_ids' => $media_ids );
			}
		}

		// Add reply settings
		if ( isset( $options['reply_settings'] ) ) {
			$post_data['reply_settings'] = $options['reply_settings'];
		}

		// Add poll if provided
		if ( ! empty( $content['poll'] ) ) {
			$post_data['poll'] = $this->preparePoll( $content['poll'] );
		}

		return $post_data;
	}

	/**
	 * Process text content with URL shortening and hashtag handling
	 */
	private function processTextContent( string $text, array $options ): string {
		// Shorten URLs if enabled
		if ( isset( $options['shorten_urls'] ) && $options['shorten_urls'] ) {
			$text = $this->shortenUrls( $text );
		}

		// Add hashtags if provided
		if ( ! empty( $options['hashtags'] ) ) {
			$hashtags = is_array( $options['hashtags'] ) ? $options['hashtags'] : array( $options['hashtags'] );
			$text    .= ' ' . implode(
				' ',
				array_map(
					function ( $tag ) {
							return '#' . ltrim( $tag, '#' );
					},
					$hashtags
				)
			);
		}

		// Ensure text doesn't exceed limit
		if ( strlen( $text ) > $this->getMaxTextLength() ) {
			$text = substr( $text, 0, $this->getMaxTextLength() - 3 ) . '...';
		}

		return $text;
	}

	/**
	 * Upload media to Twitter
	 */
	private function uploadMedia( array $media_files ): array {
		$media_ids = array();

		foreach ( $media_files as $media_file ) {
			try {
				if ( is_string( $media_file ) ) {
					// Assume it's a URL or file path
					$file_path = $this->downloadMediaFile( $media_file );
				} elseif ( is_array( $media_file ) && isset( $media_file['tmp_name'] ) ) {
					// WordPress upload array
					$file_path = $media_file['tmp_name'];
				} else {
					continue;
				}

				$media_id = $this->uploadMediaToTwitter( $file_path );
				if ( $media_id ) {
					$media_ids[] = $media_id;
				}
			} catch ( \Exception $e ) {
				PuntWorkLogger::error(
					'Twitter media upload failed',
					PuntWorkLogger::CONTEXT_SOCIAL,
					array(
						'error'      => $e->getMessage(),
						'media_file' => $media_file,
					)
				);
			}
		}

		return $media_ids;
	}

	/**
	 * Upload media file to Twitter
	 */
	private function uploadMediaToTwitter( string $file_path ): ?string {
		if ( ! file_exists( $file_path ) ) {
			return null;
		}

		// For Twitter API v2, we'd use the media upload endpoint
		// This is a simplified implementation - in production, you'd use proper OAuth

		$file_data = file_get_contents( $file_path );
		$file_size = strlen( $file_data );

		// Check file size limits (5MB for images, 15MB for video)
		if ( $file_size > 5 * 1024 * 1024 ) {
			throw new \Exception( 'File size exceeds Twitter limits' );
		}

		// In a real implementation, you'd make the actual API call here
		// For now, return a mock media ID
		return 'media_' . uniqid();
	}

	/**
	 * Download media file from URL
	 */
	private function downloadMediaFile( string $url ): string {
		$temp_file = wp_tempnam();
		$response  = wp_remote_get( $url );

		if ( is_wp_error( $response ) ) {
			throw new \Exception( 'Failed to download media file' );
		}

		file_put_contents( $temp_file, wp_remote_retrieve_body( $response ) );
		return $temp_file;
	}

	/**
	 * Prepare poll data
	 */
	private function preparePoll( array $poll ): array {
		return array(
			'options'          => array_slice( $poll['options'], 0, 4 ), // Max 4 options
			'duration_minutes' => min( max( $poll['duration_minutes'] ?? 1440, 5 ), 10080 ), // 5 min to 7 days
		);
	}

	/**
	 * Shorten URLs in text
	 */
	private function shortenUrls( string $text ): string {
		// Simple URL shortening - in production, you'd use a URL shortener service
		return preg_replace_callback(
			'/https?:\/\/[^\s]+/',
			function ( $matches ) {
				$url = $matches[0];
				// For demo purposes, just return the original URL
				// In production, you'd shorten it
				return $url;
			},
			$text
		);
	}

	/**
	 * Execute API request
	 */
	protected function executeApiRequest( string $endpoint, array $params, string $method ) {
		$url = $this->api_base . '/' . $endpoint;

		// In a real implementation, you'd use proper OAuth 1.0a authentication
		// For now, this is a mock implementation
		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . ( $this->credentials['bearer_token'] ?? '' ),
				'Content-Type'  => 'application/json',
			),
			'body'    => json_encode( $params ),
			'timeout' => 30,
		);

		return wp_remote_request( $url, $args );
	}

	/**
	 * Handle Twitter API errors
	 */
	protected function handleApiError( array $response ): void {
		if ( isset( $response['errors'] ) ) {
			$error_messages = array_column( $response['errors'], 'message' );
			throw new \Exception( 'Twitter API Error: ' . implode( ', ', $error_messages ) );
		}

		if ( isset( $response['title'] ) ) {
			throw new \Exception( 'Twitter API Error: ' . $response['title'] );
		}
	}
}
