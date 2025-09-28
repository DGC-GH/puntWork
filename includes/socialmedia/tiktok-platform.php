<?php

/**
 * TikTok Social Media Integration
 *
 * @package    Puntwork
 * @subpackage SocialMedia
 * @since      2.2.1
 */

namespace Puntwork\SocialMedia;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

/**
 * TikTok platform integration
 */
class TikTokPlatform extends SocialMediaPlatform
{
    /**
     * Ads manager instance
     */
    private ?TikTokAdsManager $ads_manager = null;

    /**
     * API base URL
     */
    private string $api_base = 'https://open-api.tiktok.com';

    /**
     * Constructor
     */
    public function __construct(array $config = array())
    {
        $this->platform_id   = 'tiktok';
        $this->platform_name = 'TikTok';
        $this->rate_limits   = array(
        'posts_per_hour' => 50,  // TikTok allows ~50 posts per hour
        'posts_per_day'  => 200,   // Conservative daily limit
        );

        parent::__construct($config);

        // Initialize ads manager if ads credentials are provided
        if ($this->hasAdsCredentials()) {
            $this->ads_manager = new TikTokAdsManager($this);
        }
    }

    /**
     * Check if TikTok is properly configured
     */
    public function isConfigured(): bool
    {
        return parent::isConfigured() &&
        isset($this->credentials['app_id']) &&
        isset($this->credentials['app_secret']) &&
        isset($this->credentials['access_token']) &&
        isset($this->credentials['open_id']);
    }

    /**
     * Check if ads credentials are configured
     */
    public function hasAdsCredentials(): bool
    {
        return isset($this->credentials['advertiser_id']) &&
        isset($this->credentials['access_token']);
    }

    /**
     * Check if ads functionality is available
     */
    public function supportsAds(): bool
    {
        return $this->ads_manager !== null;
    }

    /**
     * Post content to TikTok
     */
    public function post(array $content, array $options = array()): array
    {
        if (! $this->isConfigured()) {
            throw new \Exception('TikTok integration not properly configured');
        }

        // Validate content
        $validation_errors = $this->validateContent($content);
        if (! empty($validation_errors)) {
            throw new \Exception('Content validation failed: ' . implode(', ', $validation_errors));
        }

        try {
            // TikTok requires video content for posts
            if (empty($content['media'])) {
                throw new \Exception('TikTok requires video content for posting');
            }

            $post_data = $this->preparePostData($content, $options);
            $response  = $this->makeApiRequest('share/video/upload/', $post_data, 'POST');

            $this->recordPost();

            return array(
            'success'   => true,
            'post_id'   => $response['data']['share_id'] ?? null,
            'url'       => isset($response['data']['share_id']) ? "https://tiktok.com/@{$this->credentials['open_id']}/video/{$response['data']['share_id']}" : null,
            'platform'  => 'tiktok',
            'timestamp' => time(),
            );
        } catch (\Exception $e) {
            PuntWorkLogger::error(
                'TikTok posting failed',
                PuntWorkLogger::CONTEXT_SOCIAL,
                array(
                'error'   => $e->getMessage(),
                'content' => $content,
                )
            );

            return array(
            'success'   => false,
            'error'     => $e->getMessage(),
            'platform'  => 'tiktok',
            'timestamp' => time(),
            );
        }
    }

    /**
     * Post content with ads campaign
     */
    public function postWithAds(array $content, array $ads_config): array
    {
        if (! $this->supportsAds()) {
            throw new \Exception('TikTok ads not configured. Please provide advertiser_id and access_token.');
        }

        // First post the regular TikTok video
        $post_result = $this->post($content);

        if (! $post_result['success']) {
            return $post_result;
        }

        try {
            // Create ads campaign for the video
            $ads_result = $this->ads_manager->postJobWithAds(
                array( 'video_id' => $post_result['post_id'] ),
                $ads_config
            );

            return array_merge(
                $post_result,
                array(
                'ads_campaign' => $ads_result,
                'has_ads'      => true,
                )
            );
        } catch (\Exception $e) {
            PuntWorkLogger::error(
                'TikTok ads creation failed',
                PuntWorkLogger::CONTEXT_SOCIAL,
                array(
                'video_id' => $post_result['post_id'],
                'error'    => $e->getMessage(),
                )
            );

            // Return the successful video post but with ads error
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
    public function getAdsMetrics(string $campaign_id): array
    {
        if (! $this->supportsAds()) {
            throw new \Exception('TikTok ads not configured');
        }

        return $this->ads_manager->getCampaignMetrics(
            $campaign_id,
            $this->credentials['advertiser_id']
        );
    }

    /**
     * Get posting limits and remaining quota
     */
    public function getLimits(): array
    {
        $transient_key = 'socialmedia_ratelimit_' . $this->platform_id;
        $posts_today   = get_transient($transient_key) ?: 0;

        $hourly_key = $transient_key . '_hour_' . date('Y-m-d-H');
        $posts_hour = get_transient($hourly_key) ?: 0;

        return array(
        'posts_today'       => $posts_today,
        'posts_today_limit' => $this->rate_limits['posts_per_day'],
        'posts_hour'        => $posts_hour,
        'posts_hour_limit'  => $this->rate_limits['posts_per_hour'],
        'remaining_today'   => max(0, $this->rate_limits['posts_per_day'] - $posts_today),
        'remaining_hour'    => max(0, $this->rate_limits['posts_per_hour'] - $posts_hour),
        );
    }

    /**
     * Get maximum text length
     */
    protected function getMaxTextLength(): int
    {
        return 2200; // TikTok allows up to 2200 characters
    }

    /**
     * Prepare post data for TikTok API
     */
    private function preparePostData(array $content, array $options): array
    {
        $post_data = array();

        // Add text content
        if (! empty($content['text'])) {
            $text               = $this->processTextContent($content['text'], $options);
            $post_data['title'] = $text;
        }

        // Add video content (required for TikTok)
        if (! empty($content['media'])) {
            $video_data = $this->prepareVideoData($content['media']);
            $post_data  = array_merge($post_data, $video_data);
        }

        // Add privacy settings
        if (isset($options['privacy_level'])) {
            $post_data['privacy_level'] = $options['privacy_level']; // PUBLIC_TO_EVERYONE, MUTUAL_FOLLOW_FRIENDS, FOLLOWER_OF_CREATOR, SELF_ONLY
        }

        // Add music/sound if specified
        if (! empty($options['music_id'])) {
            $post_data['music_id'] = $options['music_id'];
        }

        return $post_data;
    }

    /**
     * Process text content with hashtag handling
     */
    private function processTextContent(string $text, array $options): string
    {
        // Add hashtags if provided
        if (! empty($options['hashtags'])) {
            $hashtags = is_array($options['hashtags']) ? $options['hashtags'] : array( $options['hashtags'] );
            $text    .= ' ' . implode(
                ' ',
                array_map(
                    function ($tag) {
                            return '#' . ltrim($tag, '#');
                    },
                    $hashtags
                )
            );
        }

        // Ensure text doesn't exceed limit
        if (strlen($text) > $this->getMaxTextLength()) {
            $text = substr($text, 0, $this->getMaxTextLength() - 3) . '...';
        }

        return $text;
    }

    /**
     * Prepare video data for TikTok upload
     */
    private function prepareVideoData(array $media_files): array
    {
        $video_data = array();

        foreach ($media_files as $media_file) {
            try {
                if (is_string($media_file)) {
                    // Assume it's a URL or file path
                    $file_path = $this->downloadMediaFile($media_file);
                } elseif (is_array($media_file) && isset($media_file['tmp_name'])) {
                    // WordPress upload array
                    $file_path = $media_file['tmp_name'];
                } else {
                    continue;
                }

                // Validate video file
                $this->validateVideoFile($file_path);

                $video_data['video'] = $file_path;
                break; // TikTok only supports one video per post
            } catch (\Exception $e) {
                PuntWorkLogger::error(
                    'TikTok video preparation failed',
                    PuntWorkLogger::CONTEXT_SOCIAL,
                    array(
                    'error'      => $e->getMessage(),
                    'media_file' => $media_file,
                    )
                );
            }
        }

        if (empty($video_data['video'])) {
            throw new \Exception('No valid video file found for TikTok posting');
        }

        return $video_data;
    }

    /**
     * Validate video file for TikTok requirements
     */
    private function validateVideoFile(string $file_path): void
    {
        if (! file_exists($file_path)) {
            throw new \Exception('Video file does not exist');
        }

        // Check file size (TikTok allows up to 500MB for videos)
        $file_size = filesize($file_path);
        if ($file_size > 500 * 1024 * 1024) {
            throw new \Exception('Video file size exceeds TikTok limits (500MB max)');
        }

        // Check file type
        $mime_type     = mime_content_type($file_path);
        $allowed_types = array( 'video/mp4', 'video/quicktime', 'video/x-msvideo' );
        if (! in_array($mime_type, $allowed_types)) {
            throw new \Exception('Video file type not supported by TikTok');
        }

        // Check video duration (should be between 3 seconds and 10 minutes)
        // This would require additional video processing libraries in production
    }

    /**
     * Download media file from URL
     */
    private function downloadMediaFile(string $url): string
    {
        $temp_file = wp_tempnam();
        $response  = wp_remote_get($url);

        if (is_wp_error($response)) {
            throw new \Exception('Failed to download media file');
        }

        file_put_contents($temp_file, wp_remote_retrieve_body($response));
        return $temp_file;
    }

    /**
     * Execute API request
     */
    protected function executeApiRequest(string $endpoint, array $params, string $method)
    {
        $url = $this->api_base . '/' . $endpoint;

        $args = array(
        'method'  => $method,
        'headers' => array(
        'Authorization' => 'Bearer ' . ( $this->credentials['access_token'] ?? '' ),
        'Content-Type'  => 'application/json',
        ),
        'timeout' => 60, // TikTok uploads can take longer
        );

        if ($method === 'GET') {
            $url .= '?' . http_build_query($params);
        } else {
            $args['body'] = json_encode($params);
        }

        return wp_remote_request($url, $args);
    }

    /**
     * Handle TikTok API errors
     */
    protected function handleApiError(array $response): void
    {
        if (isset($response['error'])) {
            $error   = $response['error'];
            $message = $error['message'] ?? 'Unknown error';
            if (isset($error['code'])) {
                $message .= " (Code: {$error['code']})";
            }
            throw new \Exception('TikTok API Error: ' . $message);
        }

        if (isset($response['message'])) {
            throw new \Exception('TikTok API Error: ' . $response['message']);
        }
    }
}
