<?php

/**
 * Indeed Job Board Integration.
 *
 * @since      2.2.0
 */

namespace Puntwork\JobBoards;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Indeed job board integration.
 */
class IndeedBoard extends JobBoard
{
    /**
     * Publisher ID for Indeed API.
     */
    protected string $publisher_id = '';

    /**
     * Constructor.
     */
    public function __construct(array $config = [])
    {
        $this->board_id = 'indeed';
        $this->board_name = 'Indeed';
        $this->api_url = 'https://api.indeed.com/ads/apisearch';

        parent::__construct($config);
    }

    /**
     * Configure Indeed integration.
     */
    public function configure(array $config): void
    {
        parent::configure($config);

        if (isset($config['publisher_id'])) {
            $this->publisher_id = $config['publisher_id'];
        }
    }

    /**
     * Check if Indeed is properly configured.
     */
    public function isConfigured(): bool
    {
        return parent::isConfigured() && !empty($this->publisher_id);
    }

    /**
     * Fetch jobs from Indeed.
     */
    public function fetchJobs(array $params = []): array
    {
        if (!$this->isConfigured()) {
            throw new \Exception('Indeed integration not properly configured');
        }

        $default_params = [
            'publisher' => $this->publisher_id,
            'v' => '2',
            'format' => 'json',
            'limit' => 25,
            'fromage' => 30, // Jobs from last 30 days
            'sort' => 'date',
        ];

        $search_params = array_merge($default_params, $params);

        try {
            $response = $this->makeApiRequest('', $search_params);

            if (!isset($response['results'])) {
                return [];
            }

            $jobs = [];
            foreach ($response['results'] as $job_data) {
                $jobs[] = $this->normalizeIndeedJob($job_data);
            }

            return $jobs;
        } catch (\Exception $e) {
            PuntWorkLogger::error(
                'Indeed API error',
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
        // Indeed doesn't provide detailed job info via API
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

        if (isset($filters['date_posted'])) {
            // Convert to Indeed's fromage parameter (days ago)
            $days_ago = $this->calculateDaysAgo($filters['date_posted']);
            $params['fromage'] = $days_ago;
        }

        return $this->fetchJobs($params);
    }

    /**
     * Normalize Indeed job data.
     */
    private function normalizeIndeedJob(array $jobData): array
    {
        return [
            'id' => $jobData['jobkey'] ?? uniqid('indeed_'),
            'title' => $jobData['jobtitle'] ?? '',
            'description' => $jobData['snippet'] ?? '',
            'company' => $jobData['company'] ?? '',
            'location' => $jobData['formattedLocation'] ?? $jobData['city'] ?? '',
            'salary' => $jobData['formattedRelativeTime'] ?? '',
            'job_type' => $this->mapJobType($jobData['jobtype'] ?? ''),
            'category' => $jobData['category'] ?? '',
            'url' => $jobData['url'] ?? '',
            'date_posted' => $this->parseDatePosted($jobData['date'] ?? ''),
            'source' => 'indeed',
            'raw_data' => $jobData,
        ];
    }

    /**
     * Map Indeed job types to standard format.
     */
    private function mapJobType(string $indeedType): string
    {
        $type_map = [
            'fulltime' => 'full-time',
            'parttime' => 'part-time',
            'contract' => 'contract',
            'temporary' => 'temporary',
            'internship' => 'internship',
        ];

        return $type_map[strtolower($indeedType)] ?? 'full-time';
    }

    /**
     * Parse Indeed date format.
     */
    private function parseDatePosted(string $dateString): string
    {
        if (empty($dateString)) {
            return date('Y-m-d');
        }

        // Indeed returns relative dates like "1 day ago", "2 days ago"
        if (preg_match('/(\d+)\s+days?\s+ago/i', $dateString, $matches)) {
            $days_ago = (int)$matches[1];

            return date('Y-m-d', strtotime("-{$days_ago} days"));
        }

        return date('Y-m-d');
    }

    /**
     * Calculate days ago from date string.
     */
    private function calculateDaysAgo(string $dateString): int
    {
        try {
            $date = new \DateTime($dateString);
            $now = new \DateTime();
            $interval = $now->diff($date);

            return $interval->days;
        } catch (\Exception $e) {
            return 30; // Default to 30 days
        }
    }
}
