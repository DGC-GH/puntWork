<?php
/**
 * Job Board Integration System
 *
 * @package    Puntwork
 * @subpackage JobBoards
 * @since      2.2.0
 */

namespace Puntwork\JobBoards;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Abstract base class for job board integrations
 */
abstract class JobBoard {

    /**
     * Job board identifier
     */
    protected string $board_id;

    /**
     * Job board name
     */
    protected string $board_name;

    /**
     * API endpoint URL
     */
    protected string $api_url;

    /**
     * Authentication credentials
     */
    protected array $credentials = [];

    /**
     * Rate limiting settings
     */
    protected array $rate_limits = [
        'requests_per_minute' => 60,
        'requests_per_hour' => 1000
    ];

    /**
     * Constructor
     *
     * @param array $config Configuration array
     */
    public function __construct(array $config = []) {
        $this->configure($config);
    }

    /**
     * Configure the job board
     *
     * @param array $config Configuration options
     */
    public function configure(array $config): void {
        if (isset($config['api_url'])) {
            $this->api_url = $config['api_url'];
        }

        if (isset($config['credentials'])) {
            $this->credentials = $config['credentials'];
        }

        if (isset($config['rate_limits'])) {
            $this->rate_limits = array_merge($this->rate_limits, $config['rate_limits']);
        }
    }

    /**
     * Get job board identifier
     */
    public function getBoardId(): string {
        return $this->board_id;
    }

    /**
     * Get job board name
     */
    public function getBoardName(): string {
        return $this->board_name;
    }

    /**
     * Check if the job board is properly configured
     */
    public function isConfigured(): bool {
        return !empty($this->api_url) && !empty($this->credentials);
    }

    /**
     * Fetch jobs from the job board
     *
     * @param array $params Query parameters
     * @return array Array of job data
     */
    abstract public function fetchJobs(array $params = []): array;

    /**
     * Get job details by ID
     *
     * @param string $jobId Job identifier
     * @return array|null Job details or null if not found
     */
    abstract public function getJobDetails(string $jobId): ?array;

    /**
     * Search jobs with filters
     *
     * @param array $filters Search filters
     * @return array Array of matching jobs
     */
    abstract public function searchJobs(array $filters = []): array;

    /**
     * Get supported search filters
     */
    public function getSupportedFilters(): array {
        return [
            'keywords',
            'location',
            'category',
            'company',
            'date_posted',
            'salary_min',
            'salary_max',
            'job_type',
            'experience_level'
        ];
    }

    /**
     * Normalize job data to standard format
     *
     * @param array $jobData Raw job data from API
     * @return array Normalized job data
     */
    protected function normalizeJobData(array $jobData): array {
        return [
            'id' => $jobData['id'] ?? uniqid($this->board_id . '_'),
            'title' => $jobData['title'] ?? '',
            'description' => $jobData['description'] ?? '',
            'company' => $jobData['company'] ?? '',
            'location' => $jobData['location'] ?? '',
            'salary' => $jobData['salary'] ?? '',
            'job_type' => $jobData['job_type'] ?? 'full-time',
            'category' => $jobData['category'] ?? '',
            'url' => $jobData['url'] ?? '',
            'date_posted' => $jobData['date_posted'] ?? date('Y-m-d'),
            'application_deadline' => $jobData['application_deadline'] ?? null,
            'requirements' => $jobData['requirements'] ?? '',
            'benefits' => $jobData['benefits'] ?? '',
            'contact_info' => $jobData['contact_info'] ?? '',
            'source' => $this->board_id,
            'raw_data' => $jobData
        ];
    }

    /**
     * Make API request with rate limiting
     *
     * @param string $endpoint API endpoint
     * @param array $params Query parameters
     * @param string $method HTTP method
     * @return array Response data
     */
    protected function makeApiRequest(string $endpoint, array $params = [], string $method = 'GET'): array {
        // Rate limiting check
        $this->checkRateLimit();

        $url = $this->api_url . $endpoint;

        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => $this->getHeaders()
        ];

        if ($method === 'POST' && !empty($params)) {
            $args['body'] = json_encode($params);
            $args['headers']['Content-Type'] = 'application/json';
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            throw new \Exception('API request failed: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON response from API');
        }

        return $data;
    }

    /**
     * Get authentication headers
     */
    protected function getHeaders(): array {
        return [
            'User-Agent' => 'PuntWork/' . PUNTWORK_VERSION . ' (WordPress Plugin)',
            'Accept' => 'application/json'
        ];
    }

    /**
     * Check rate limits
     */
    protected function checkRateLimit(): void {
        $transient_key = 'jobboard_ratelimit_' . $this->board_id;
        $requests = get_transient($transient_key) ?: [];

        // Clean old requests (older than 1 minute)
        $requests = array_filter($requests, function($timestamp) {
            return $timestamp > (time() - 60);
        });

        if (count($requests) >= $this->rate_limits['requests_per_minute']) {
            $oldest_request = min($requests);
            $wait_time = 60 - (time() - $oldest_request);
            if ($wait_time > 0) {
                sleep($wait_time);
            }
        }

        $requests[] = time();
        set_transient($transient_key, $requests, 70); // Cache for 70 seconds
    }

    /**
     * Handle API errors
     *
     * @param array $response API response
     */
    protected function handleApiError(array $response): void {
        if (isset($response['error'])) {
            $error_msg = $response['error']['message'] ?? 'Unknown API error';
            throw new \Exception("{$this->board_name} API Error: {$error_msg}");
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code >= 400) {
            throw new \Exception("{$this->board_name} API returned status {$status_code}");
        }
    }
}