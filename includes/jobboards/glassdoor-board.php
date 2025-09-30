<?php

/**
 * Glassdoor Job Board Integration.
 *
 * @since      2.2.0
 */

namespace Puntwork\JobBoards;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Glassdoor job board integration.
 */
class GlassdoorBoard extends JobBoard
{
    /**
     * Partner ID for Glassdoor API.
     */
    protected string $partner_id = '';

    /**
     * Partner key for Glassdoor API.
     */
    protected string $partner_key = '';

    /**
     * Constructor.
     */
    public function __construct(array $config = [])
    {
        $this->board_id = 'glassdoor';
        $this->board_name = 'Glassdoor';
        $this->api_url = 'https://api.glassdoor.com/api';

        parent::__construct($config);
    }

    /**
     * Configure Glassdoor integration.
     */
    public function configure(array $config): void
    {
        parent::configure($config);

        if (isset($config['partner_id'])) {
            $this->partner_id = $config['partner_id'];
        }

        if (isset($config['partner_key'])) {
            $this->partner_key = $config['partner_key'];
        }
    }

    /**
     * Check if Glassdoor is properly configured.
     */
    public function isConfigured(): bool
    {
        return parent::isConfigured() && !empty($this->partner_id) && !empty($this->partner_key);
    }

    /**
     * Fetch jobs from Glassdoor.
     */
    public function fetchJobs(array $params = []): array
    {
        if (!$this->isConfigured()) {
            throw new \Exception('Glassdoor integration not properly configured');
        }

        $default_params = [
            'v' => '1',
            'format' => 'json',
            'action' => 'jobs',
            'pn' => 1, // Page number
            'ps' => 50, // Page size
            't.p' => $this->partner_id,
            't.k' => $this->partner_key,
            'userip' => $this->getUserIP(),
            'useragent' => $this->getUserAgent(),
        ];

        $search_params = array_merge($default_params, $params);

        try {
            $response = $this->makeApiRequest('', $search_params);

            if (!isset($response['response']) || !isset($response['response']['job'])) {
                return [];
            }

            $jobs = [];
            foreach ($response['response']['job'] as $job_data) {
                $jobs[] = $this->normalizeGlassdoorJob($job_data);
            }

            return $jobs;
        } catch (\Exception $e) {
            PuntWorkLogger::error(
                'Glassdoor API error',
                PuntWorkLogger::CONTEXT_IMPORT,
                [
                    'error' => $e->getMessage(),
                    'params' => $search_params,
                ]
            );

            return [];
        }
    }

    /**
     * Get job details by ID.
     */
    public function getJobDetails(string $jobId): ?array
    {
        // Glassdoor doesn't provide detailed job info via API
        // Return basic info if we have it cached, otherwise null
        return null;
    }

    /**
     * Search jobs with filters.
     */
    public function searchJobs(array $filters = []): array
    {
        $params = [];

        if (isset($filters['keywords'])) {
            $params['q'] = $filters['keywords'];
        }

        if (isset($filters['location'])) {
            $params['l'] = $filters['location'];
        }

        if (isset($filters['category'])) {
            $params['co'] = $filters['category'];
        }

        if (isset($filters['job_type'])) {
            $params['jt'] = $filters['job_type'];
        }

        return $this->fetchJobs($params);
    }

    /**
     * Normalize Glassdoor job data.
     */
    private function normalizeGlassdoorJob(array $jobData): array
    {
        return [
            'id' => $jobData['id'] ?? uniqid('glassdoor_'),
            'title' => $jobData['jobTitle'] ?? '',
            'description' => $jobData['jobDescription'] ?? '',
            'company' => $jobData['company'] ?? '',
            'location' => $jobData['location'] ?? '',
            'salary' => $this->formatGlassdoorSalary($jobData),
            'job_type' => $jobData['jobType'] ?? 'full-time',
            'category' => $jobData['category'] ?? '',
            'url' => $jobData['jobLink'] ?? '',
            'date_posted' => $jobData['postDate'] ?? date('Y-m-d'),
            'source' => 'glassdoor',
            'raw_data' => $jobData,
        ];
    }

    /**
     * Format Glassdoor salary information.
     */
    private function formatGlassdoorSalary(array $jobData): string
    {
        $salary = '';

        if (isset($jobData['payLow']) && isset($jobData['payHigh'])) {
            $currency = $jobData['currency'] ?? 'USD';
            $low = $jobData['payLow'];
            $high = $jobData['payHigh'];
            $period = $jobData['payPeriod'] ?? 'yearly';

            $salary = "{$currency} {$low} - {$high} per {$period}";
        }

        return $salary;
    }

    /**
     * Get user IP address for Glassdoor API.
     */
    private function getUserIP(): string
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        }
    }

    /**
     * Get user agent for Glassdoor API.
     */
    private function getUserAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'PuntWork/' . PUNTWORK_VERSION;
    }
}
