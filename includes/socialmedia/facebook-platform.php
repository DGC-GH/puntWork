<?php

/**
 * Facebook Social Media Integration
 *
 * @package    Puntwork
 * @subpackage SocialMedia
 * @since      2.2.1
 */

namespace Puntwork\SocialMedia;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Facebook platform integration
 */
class FacebookPlatform extends SocialMediaPlatform {

	/**
	 * Ads manager instance
	 */
	private ?FacebookAdsManager $ads_manager = null;

	/**
	 * API base URL
	 */
	private string $api_base = 'https://graph.facebook.com/v18.0';

	/**
	 * Constructor
	 */
	public function __construct( array $config = array() ) {
		$this->platform_id   = 'facebook';
		$this->platform_name = 'Facebook';
		$this->rate_limits   = array(
			'posts_per_hour' => 200,  // Facebook allows ~200 posts per hour
			'posts_per_day'  => 1000,   // Conservative daily limit
		);

		parent::__construct( $config );

		// Initialize ads manager if ads credentials are provided
		if ( $this->hasAdsCredentials() ) {
			$this->ads_manager = new FacebookAdsManager( $this );
		}
	}

	/**
	 * Check if Facebook is properly configured
	 */
	public function isConfigured(): bool {
		return parent::isConfigured() &&
		isset( $this->credentials['app_id'] ) &&
		isset( $this->credentials['app_secret'] ) &&
		isset( $this->credentials['access_token'] ) &&
		isset( $this->credentials['page_id'] );
	}

	/**
	 * Check if ads credentials are configured
	 */
	public function hasAdsCredentials(): bool {
		return isset( $this->credentials['ad_account_id'] ) &&
		isset( $this->credentials['access_token'] );
	}

	/**
	 * Check if ads functionality is available
	 */
	public function supportsAds(): bool {
		return $this->ads_manager !== null;
	}

	/**
	 * Post content to Facebook
	 */
	public function post( array $content, array $options = array() ): array {
		if ( ! $this->isConfigured() ) {
			throw new \Exception( 'Facebook integration not properly configured' );
		}

		// Validate content
		$validation_errors = $this->validateContent( $content );
		if ( ! empty( $validation_errors ) ) {
			throw new \Exception( 'Content validation failed: ' . implode( ', ', $validation_errors ) );
		}

		try {
			$post_data = $this->preparePostData( $content, $options );
			$response  = $this->makeApiRequest( $this->credentials['page_id'] . '/feed', $post_data, 'POST' );

			$this->recordPost();

			return array(
				'success'   => true,
				'post_id'   => $response['id'] ?? null,
				'url'       => isset( $response['id'] ) ? "https://facebook.com/{$response['id']}" : null,
				'platform'  => 'facebook',
				'timestamp' => time(),
			);
		} catch ( \Exception $e ) {
			PuntWorkLogger::error(
				'Facebook posting failed',
				PuntWorkLogger::CONTEXT_SOCIAL,
				array(
					'error'   => $e->getMessage(),
					'content' => $content,
				)
			);

			return array(
				'success'   => false,
				'error'     => $e->getMessage(),
				'platform'  => 'facebook',
				'timestamp' => time(),
			);
		}
	}

	/**
	 * Post content with ads campaign
	 */
	public function postWithAds( array $content, array $ads_config ): array {
		if ( ! $this->supportsAds() ) {
			throw new \Exception( 'Facebook ads not configured. Please provide ad_account_id and access_token.' );
		}

		// First post the regular Facebook post
		$post_result = $this->post( $content );

		if ( ! $post_result['success'] ) {
			return $post_result;
		}

		try {
			// Create ads campaign for the post
			$ads_result = $this->ads_manager->postJobWithAds(
				array( 'post_id' => $post_result['post_id'] ),
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
				'Facebook ads creation failed',
				PuntWorkLogger::CONTEXT_SOCIAL,
				array(
					'post_id' => $post_result['post_id'],
					'error'   => $e->getMessage(),
				)
			);

			// Return the successful post but with ads error
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
			throw new \Exception( 'Facebook ads not configured' );
		}

		return $this->ads_manager->getCampaignMetrics(
			$campaign_id,
			$this->credentials['ad_account_id']
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
		return 63206; // Facebook allows very long posts
	}

	/**
	 * Prepare post data for Facebook API
	 */
	private function preparePostData( array $content, array $options ): array {
		$post_data = array();

		// Add text content
		if ( ! empty( $content['text'] ) ) {
			$text                 = $this->processTextContent( $content['text'], $options );
			$post_data['message'] = $text;
		}

		// Add link if provided
		if ( ! empty( $content['link'] ) ) {
			$post_data['link'] = $content['link'];
		}

		// Add media if provided
		if ( ! empty( $content['media'] ) ) {
			$media_ids = $this->uploadMedia( $content['media'] );
			if ( ! empty( $media_ids ) ) {
				// Facebook handles media differently - we need to upload first
				$post_data['attached_media'] = $media_ids;
			}
		}

		// Add privacy settings
		if ( isset( $options['privacy'] ) ) {
			$post_data['privacy'] = json_encode( array( 'value' => $options['privacy'] ) );
		}

		return $post_data;
	}

	/**
	 * Process text content with URL handling
	 */
	private function processTextContent( string $text, array $options ): string {
		// Facebook doesn't need URL shortening as much as Twitter
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
	 * Upload media to Facebook
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

				$media_id = $this->uploadMediaToFacebook( $file_path );
				if ( $media_id ) {
					$media_ids[] = array( 'media_fbid' => $media_id );
				}
			} catch ( \Exception $e ) {
				PuntWorkLogger::error(
					'Facebook media upload failed',
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
	 * Upload media file to Facebook
	 */
	private function uploadMediaToFacebook( string $file_path ): ?string {
		if ( ! file_exists( $file_path ) ) {
			return null;
		}

		// Check file size limits (Facebook allows up to 10GB for video, but we'll be conservative)
		$file_size = filesize( $file_path );
		if ( $file_size > 100 * 1024 * 1024 ) { // 100MB limit for safety
			throw new \Exception( 'File size exceeds Facebook limits' );
		}

		// In a real implementation, you'd upload to Facebook's photo/video endpoints
		// For now, return a mock media ID
		return 'facebook_media_' . uniqid();
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
	 * Execute API request
	 */
	protected function executeApiRequest( string $endpoint, array $params, string $method ) {
		$url = $this->api_base . '/' . $endpoint;

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . ( $this->credentials['access_token'] ?? '' ),
				'Content-Type'  => 'application/json',
			),
			'timeout' => 30,
		);

		if ( $method === 'GET' ) {
			$url .= '?' . http_build_query( $params );
		} else {
			$args['body'] = json_encode( $params );
		}

		return wp_remote_request( $url, $args );
	}

	/**
	 * Handle Facebook API errors
	 */
	protected function handleApiError( array $response ): void {
		if ( isset( $response['error'] ) ) {
			$error   = $response['error'];
			$message = $error['message'] ?? 'Unknown error';
			if ( isset( $error['code'] ) ) {
				$message .= " (Code: {$error['code']})";
			}
			throw new \Exception( 'Facebook API Error: ' . $message );
		}
	}
}
