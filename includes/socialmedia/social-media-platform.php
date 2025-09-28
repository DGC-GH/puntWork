<?php

/**
 * Social Media Integration System.
 *
 * @since      2.2.0
 */

namespace Puntwork\SocialMedia;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Abstract base class for social media platforms.
 */
abstract class SocialMediaPlatform
{
    /**
     * Platform identifier.
     */
    protected string $platform_id;

    /**
     * Platform name.
     */
    protected string $platform_name;

    /**
     * API credentials.
     */
    protected array $credentials = [];

    /**
     * Rate limiting settings.
     */
    protected array $rate_limits = [
        'posts_per_hour' => 50,
        'posts_per_day' => 300,
    ];

    /**
     * Constructor.
     */
    public function __construct(array $config = [])
    {
        $this->configure($config);
    }

    /**
     * Configure the platform.
     */
    public function configure(array $config): void
    {
        if (isset($config['credentials'])) {
            $this->credentials = $config['credentials'];
        }

        if (isset($config['rate_limits'])) {
            $this->rate_limits = array_merge($this->rate_limits, $config['rate_limits']);
        }
    }

    /**
     * Get platform identifier.
     */
    public function getPlatformId(): string
    {
        return $this->platform_id;
    }

    /**
     * Get platform name.
     */
    public function getPlatformName(): string
    {
        return $this->platform_name;
    }

    /**
     * Check if platform is properly configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->credentials);
    }

    /**
     * Post content to the platform.
     */
    abstract public function post(array $content, array $options = []): array;

    /**
     * Get posting limits and remaining quota.
     */
    abstract public function getLimits(): array;

    /**
     * Validate content for platform requirements.
     */
    public function validateContent(array $content): array
    {
        $errors = [];

        if (empty($content['text']) && empty($content['media'])) {
            $errors[] = 'Content must include text or media';
        }

        if (isset($content['text']) && strlen($content['text']) > $this->getMaxTextLength()) {
            $errors[] = 'Text exceeds maximum length of ' . $this->getMaxTextLength() . ' characters';
        }

        return $errors;
    }

    /**
     * Get maximum text length for posts.
     */
    protected function getMaxTextLength(): int
    {
        return 280; // Default Twitter-like limit
    }

    /**
     * Check rate limits.
     */
    protected function checkRateLimit(): bool
    {
        $transient_key = 'socialmedia_ratelimit_' . $this->platform_id;
        $posts_today = get_transient($transient_key) ?: 0;

        if ($posts_today >= $this->rate_limits['posts_per_day']) {
            return false;
        }

        // Check hourly limit
        $hourly_key = $transient_key . '_hour_' . date('Y-m-d-H');
        $posts_hour = get_transient($hourly_key) ?: 0;

        if ($posts_hour >= $this->rate_limits['posts_per_hour']) {
            return false;
        }

        return true;
    }

    /**
     * Record a successful post for rate limiting.
     */
    protected function recordPost(): void
    {
        $transient_key = 'socialmedia_ratelimit_' . $this->platform_id;
        $posts_today = get_transient($transient_key) ?: 0;
        set_transient($transient_key, $posts_today + 1, DAY_IN_SECONDS);

        $hourly_key = $transient_key . '_hour_' . date('Y-m-d-H');
        $posts_hour = get_transient($hourly_key) ?: 0;
        set_transient($hourly_key, $posts_hour + 1, HOUR_IN_SECONDS);
    }

    /**
     * Make API request with error handling.
     */
    protected function makeApiRequest(string $endpoint, array $params = [], string $method = 'POST'): array
    {
        // Rate limiting check
        if (!$this->checkRateLimit()) {
            throw new \Exception("Rate limit exceeded for {$this->platform_name}");
        }

        $response = $this->executeApiRequest($endpoint, $params, $method);

        if (is_wp_error($response)) {
            throw new \Exception('API request failed: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON response from API');
        }

        $this->handleApiError($data);

        return $data;
    }

    /**
     * Execute the actual API request (to be implemented by subclasses).
     */
    abstract protected function executeApiRequest(string $endpoint, array $params, string $method);

    /**
     * Handle API-specific errors.
     */
    abstract protected function handleApiError(array $response): void;
}
