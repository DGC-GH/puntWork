<?php

/**
 * Social Media Manager
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
 * Manages social media integrations and posting
 */
class SocialMediaManager {

	/**
	 * Available social media platforms
	 */
	private static array $available_platforms = array(
		'twitter'  => TwitterPlatform::class,
		'facebook' => FacebookPlatform::class,
		'tiktok'   => TikTokPlatform::class,
	);

	/**
	 * Configured platform instances
	 */
	private array $platforms = array();

	/**
	 * Constructor - loads configured platforms
	 */
	public function __construct() {
		$this->loadConfiguredPlatforms();
	}

	/**
	 * Load configured social media platforms
	 */
	private function loadConfiguredPlatforms(): void {
		$platform_configs = get_option( 'puntwork_social_media', array() );

		foreach ( $platform_configs as $platform_id => $config ) {
			if ( isset( self::$available_platforms[ $platform_id ] ) && isset( $config['enabled'] ) && $config['enabled'] ) {
				try {
					$platform_class                  = self::$available_platforms[ $platform_id ];
					$this->platforms[ $platform_id ] = new $platform_class( $config );
				} catch ( \Exception $e ) {
					PuntWorkLogger::error(
						'Failed to initialize social media platform',
						PuntWorkLogger::CONTEXT_SOCIAL,
						array(
							'platform_id' => $platform_id,
							'error'       => $e->getMessage(),
						)
					);
				}
			}
		}
	}

	/**
	 * Get all available social media platforms
	 */
	public static function getAvailablePlatforms(): array {
		$platforms = array();

		foreach ( self::$available_platforms as $platform_id => $platform_class ) {
			$platforms[ $platform_id ] = array(
				'name'  => $platform_class::getPlatformName(),
				'class' => $platform_class,
			);
		}

		return $platforms;
	}

	/**
	 * Get configured platforms
	 */
	public function getConfiguredPlatforms(): array {
		return array_keys( $this->platforms );
	}

	/**
	 * Check if a platform is configured
	 */
	public function isPlatformConfigured( string $platform_id ): bool {
		return isset( $this->platforms[ $platform_id ] );
	}

	/**
	 * Get a specific platform instance
	 */
	public function getPlatform( string $platform_id ): ?SocialMediaPlatform {
		return $this->platforms[ $platform_id ] ?? null;
	}

	/**
	 * Post content to multiple platforms
	 */
	public function postToPlatforms( array $content, array $platforms = array(), array $options = array() ): array {
		$results          = array();
		$target_platforms = empty( $platforms ) ? $this->platforms : array_intersect_key( $this->platforms, array_flip( $platforms ) );

		foreach ( $target_platforms as $platform_id => $platform ) {
			try {
				$platform_options = $options[ $platform_id ] ?? $options;
				$result           = $platform->post( $content, $platform_options );

				$results[ $platform_id ] = $result;

				PuntWorkLogger::info(
					'Posted to social media platform',
					PuntWorkLogger::CONTEXT_SOCIAL,
					array(
						'platform_id' => $platform_id,
						'success'     => $result['success'],
						'post_id'     => $result['post_id'] ?? null,
					)
				);
			} catch ( \Exception $e ) {
				PuntWorkLogger::error(
					'Failed to post to social media platform',
					PuntWorkLogger::CONTEXT_SOCIAL,
					array(
						'platform_id' => $platform_id,
						'error'       => $e->getMessage(),
					)
				);

				$results[ $platform_id ] = array(
					'success'   => false,
					'error'     => $e->getMessage(),
					'platform'  => $platform_id,
					'timestamp' => time(),
				);
			}
		}

		return $results;
	}

	/**
	 * Post job to social media platforms with ads
	 */
	public function postJobWithAds( array $job_data, array $platforms = array(), array $ads_config = array() ): array {
		$results          = array();
		$target_platforms = empty( $platforms ) ? $this->platforms : array_intersect_key( $this->platforms, array_flip( $platforms ) );

		foreach ( $target_platforms as $platform_id => $platform ) {
			try {
				$platform_ads_config = $ads_config[ $platform_id ] ?? $ads_config;

				if ( $platform->supportsAds() && ! empty( $platform_ads_config ) ) {
					// Post with ads
					$result = $platform->postWithAds(
						$this->formatJobForSocialMedia( $job_data, $ads_config['options'] ?? array() ),
						$platform_ads_config
					);
				} else {
					// Regular post
					$result = $platform->post( $this->formatJobForSocialMedia( $job_data, $ads_config['options'] ?? array() ) );
				}

				$results[ $platform_id ] = $result;

				PuntWorkLogger::info(
					'Posted job to social media platform',
					PuntWorkLogger::CONTEXT_SOCIAL,
					array(
						'platform_id' => $platform_id,
						'job_title'   => $job_data['title'] ?? '',
						'success'     => $result['success'],
						'has_ads'     => $result['has_ads'] ?? false,
						'post_id'     => $result['post_id'] ?? null,
					)
				);
			} catch ( \Exception $e ) {
				PuntWorkLogger::error(
					'Failed to post job to social media platform',
					PuntWorkLogger::CONTEXT_SOCIAL,
					array(
						'platform_id' => $platform_id,
						'error'       => $e->getMessage(),
					)
				);

				$results[ $platform_id ] = array(
					'success'   => false,
					'error'     => $e->getMessage(),
					'platform'  => $platform_id,
					'timestamp' => time(),
				);
			}
		}

		return $results;
	}

	/**
	 * Format job data for social media posting
	 */
	private function formatJobForSocialMedia( array $job_data, array $options = array() ): array {
		$template = $options['template'] ?? 'default';

		switch ( $template ) {
			case 'concise':
				$text = $this->createConciseJobPost( $job_data );
				break;
			case 'detailed':
				$text = $this->createDetailedJobPost( $job_data );
				break;
			default:
				$text = $this->createDefaultJobPost( $job_data );
		}

		$content = array( 'text' => $text );

		// Add media if available
		if ( ! empty( $job_data['company_logo'] ) ) {
			$content['media'] = array( $job_data['company_logo'] );
		}

		return $content;
	}

	/**
	 * Create default job post format
	 */
	private function createDefaultJobPost( array $job_data ): string {
		$title    = $job_data['title'] ?? '';
		$company  = $job_data['company'] ?? '';
		$location = $job_data['location'] ?? '';
		$url      = $job_data['url'] ?? '';

		$post  = "🚀 New Job Opportunity!\n\n";
		$post .= "📋 {$title}\n";
		$post .= "🏢 {$company}\n";

		if ( $location ) {
			$post .= "📍 {$location}\n";
		}

		$post .= "\nApply now: {$url}";

		return $post;
	}

	/**
	 * Create concise job post format
	 */
	private function createConciseJobPost( array $job_data ): string {
		$title   = $job_data['title'] ?? '';
		$company = $job_data['company'] ?? '';
		$url     = $job_data['url'] ?? '';

		return "💼 {$title} at {$company}\n\nApply: {$url}";
	}

	/**
	 * Create detailed job post format
	 */
	private function createDetailedJobPost( array $job_data ): string {
		$title       = $job_data['title'] ?? '';
		$company     = $job_data['company'] ?? '';
		$location    = $job_data['location'] ?? '';
		$description = $job_data['description'] ?? '';
		$salary      = $job_data['salary'] ?? '';
		$url         = $job_data['url'] ?? '';

		$post  = "🎯 Exciting Career Opportunity!\n\n";
		$post .= "📋 Position: {$title}\n";
		$post .= "🏢 Company: {$company}\n";

		if ( $location ) {
			$post .= "📍 Location: {$location}\n";
		}

		if ( $salary ) {
			$post .= "💰 Salary: {$salary}\n";
		}

		if ( $description ) {
			// Truncate description to fit within limits
			$desc_preview = wp_trim_words( $description, 30, '...' );
			$post        .= "\n📝 {$desc_preview}\n";
		}

		$post .= "\n🔗 Apply here: {$url}";

		return $post;
	}

	/**
	 * Get posting limits for all platforms
	 */
	public function getAllLimits(): array {
		$limits = array();

		foreach ( $this->platforms as $platform_id => $platform ) {
			try {
				$limits[ $platform_id ] = $platform->getLimits();
			} catch ( \Exception $e ) {
				$limits[ $platform_id ] = array(
					'error' => $e->getMessage(),
				);
			}
		}

		return $limits;
	}

	/**
	 * Configure a social media platform
	 */
	public static function configurePlatform( string $platform_id, array $config ): bool {
		if ( ! isset( self::$available_platforms[ $platform_id ] ) ) {
			return false;
		}

		$platform_configs                 = get_option( 'puntwork_social_media', array() );
		$platform_configs[ $platform_id ] = $config;

		return update_option( 'puntwork_social_media', $platform_configs );
	}

	/**
	 * Remove a platform configuration
	 */
	public static function removePlatform( string $platform_id ): bool {
		$platform_configs = get_option( 'puntwork_social_media', array() );

		if ( isset( $platform_configs[ $platform_id ] ) ) {
			unset( $platform_configs[ $platform_id ] );
			return update_option( 'puntwork_social_media', $platform_configs );
		}

		return false;
	}

	/**
	 * Get platform configuration
	 */
	public static function getPlatformConfig( string $platform_id ): ?array {
		$platform_configs = get_option( 'puntwork_social_media', array() );
		return $platform_configs[ $platform_id ] ?? null;
	}

	/**
	 * Get all platform configurations
	 */
	public static function getAllPlatformConfigs(): array {
		return get_option( 'puntwork_social_media', array() );
	}

	/**
	 * Test platform configuration
	 */
	public function testPlatform( string $platform_id ): array {
		$platform = $this->getPlatform( $platform_id );

		if ( ! $platform ) {
			return array(
				'success' => false,
				'message' => 'Platform not configured',
			);
		}

		try {
			// Try to get limits as a test
			$limits = $platform->getLimits();

			return array(
				'success' => true,
				'message' => 'Platform connection successful',
				'limits'  => $limits,
			);
		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'message' => 'Platform test failed: ' . $e->getMessage(),
			);
		}
	}

	/**
	 * Schedule social media post
	 */
	public function schedulePost( array $content, array $platforms, int $timestamp, array $options = array() ): int {
		$post_data = array(
			'content'        => $content,
			'platforms'      => $platforms,
			'options'        => $options,
			'scheduled_time' => $timestamp,
		);

		// Store in database for cron processing
		global $wpdb;
		$table_name = $wpdb->prefix . 'puntwork_social_posts';

		$wpdb->insert(
			$table_name,
			array(
				'post_data'      => json_encode( $post_data ),
				'scheduled_time' => date( 'Y-m-d H:i:s', $timestamp ),
				'status'         => 'scheduled',
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		return $wpdb->insert_id;
	}

	/**
	 * Process scheduled posts (called by cron)
	 */
	public function processScheduledPosts(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'puntwork_social_posts';

		$scheduled_posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE status = 'scheduled' AND scheduled_time <= %s",
				current_time( 'mysql' )
			),
			ARRAY_A
		);

		foreach ( $scheduled_posts as $post ) {
			$post_data = json_decode( $post['post_data'], true );

			if ( $post_data ) {
				$results = $this->postToPlatforms(
					$post_data['content'],
					$post_data['platforms'],
					$post_data['options']
				);

				// Update post status
				$wpdb->update(
					$table_name,
					array(
						'status'    => 'posted',
						'results'   => json_encode( $results ),
						'posted_at' => current_time( 'mysql' ),
					),
					array( 'id' => $post['id'] ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
			}
		}
	}
}
