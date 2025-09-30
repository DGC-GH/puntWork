<?php

/**
 * LinkedIn Jobs Integration.
 *
 * @since      2.2.0
 */

namespace Puntwork\JobBoards;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * LinkedIn Jobs integration.
 */
class LinkedInBoard extends JobBoard
{
    /**
     * Access token for LinkedIn API.
     */
    protected string $access_token = '';

    /**
     * Constructor.
     */
    public function __construct(array $config = [])
    {
        $this->board_id = 'linkedin';
        $this->board_name = 'LinkedIn Jobs';
        $this->api_url = 'https://api.linkedin.com/v2';

        parent::__construct($config);
    }

    /**
     * Configure LinkedIn integration.
     */
    public function configure(array $config): void
    {
        parent::configure($config);

        if (isset($config['access_token'])) {
            $this->access_token = $config['access_token'];
        }
    }

    /**
     * Check if LinkedIn is properly configured.
     */
    public function isConfigured(): bool
    {
        return parent::isConfigured() && !empty($this->access_token);
    }

    /**
     * Get authentication headers.
     */
    protected function getHeaders(): array
    {
        $headers = parent::getHeaders();
        if (!empty($this->access_token)) {
            $headers['Authorization'] = 'Bearer ' . $this->access_token;
        }

        return $headers;
    }

    /**
     * Fetch jobs from LinkedIn.
     */
    public function fetchJobs(array $params = []): array
    {
        if (!$this->isConfigured()) {
            throw new \Exception('LinkedIn integration not properly configured');
        }

        $default_params = [
            'count' => 25,
            'start' => 0,
            'sort' => 'RECENCY',
        ];

        $search_params = array_merge($default_params, $params);

        try {
            $response = $this->makeApiRequest('/jobs', $search_params);

            if (!isset($response['elements'])) {
                return [];
            }

            $jobs = [];
            foreach ($response['elements'] as $job_data) {
                $job_details = $this->getJobDetails($job_data['job']['id'] ?? '');
                if ($job_details) {
                    $jobs[] = $job_details;
                }
            }

            return $jobs;
        } catch (\Exception $e) {
            PuntWorkLogger::error(
                'LinkedIn API error',
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
        if (empty($jobId)) {
            return null;
        }

        try {
            $response = $this->makeApiRequest("/jobs/{$jobId}");

            if (empty($response)) {
                return null;
            }

            return $this->normalizeLinkedInJob($response);
        } catch (\Exception $e) {
            PuntWorkLogger::error(
                'LinkedIn job details error',
                PuntWorkLogger::CONTEXT_IMPORT,
                [
                    'job_id' => $jobId,
                    'error' => $e->getMessage(),
                ]
            );

            return null;
        }
    }

    /**
     * Search jobs with filters.
     */
    public function searchJobs(array $filters = []): array
    {
        $params = [];

        if (isset($filters['keywords'])) {
            $params['keywords'] = $filters['keywords'];
        }

        if (isset($filters['location'])) {
            $params['location'] = $filters['location'];
        }

        if (isset($filters['company'])) {
            $params['company'] = $filters['company'];
        }

        if (isset($filters['job_type'])) {
            $params['jobType'] = $this->mapJobTypeToLinkedIn($filters['job_type']);
        }

        if (isset($filters['experience_level'])) {
            $params['experienceLevel'] = $this->mapExperienceLevel($filters['experience_level']);
        }

        return $this->fetchJobs($params);
    }

    /**
     * Normalize LinkedIn job data.
     */
    private function normalizeLinkedInJob(array $jobData): array
    {
        return [
            'id' => $jobData['id'] ?? uniqid('linkedin_'),
            'title' => $jobData['title'] ?? '',
            'description' => $jobData['description'] ?? '',
            'company' => $jobData['company']['name'] ?? $jobData['companyName'] ?? '',
            'location' => $jobData['location']['displayName'] ?? $jobData['locationName'] ?? '',
            'salary' => $this->formatSalary($jobData['compensation'] ?? []),
            'job_type' => $this->mapLinkedInJobType($jobData['type'] ?? ''),
            'category' => $jobData['categories'] ?? '',
            'url' => $jobData['listingUrl'] ?? '',
            'date_posted' => $this->parseLinkedInDate($jobData['listedAt'] ?? 0),
            'application_deadline' => $this->parseLinkedInDate($jobData['expireAt'] ?? 0),
            'requirements' => $jobData['requirements'] ?? '',
            'benefits' => $jobData['benefits'] ?? '',
            'contact_info' => $jobData['contactInfo'] ?? '',
            'source' => 'linkedin',
            'raw_data' => $jobData,
        ];
    }

    /**
     * Format salary information.
     */
    private function formatSalary(array $compensation): string
    {
        if (empty($compensation)) {
            return '';
        }

        $salary = '';
        if (isset($compensation['baseSalary'])) {
            $base = $compensation['baseSalary'];
            $currency = $base['currencyCode'] ?? 'USD';
            $min = $base['minValue'] ?? 0;
            $max = $base['maxValue'] ?? 0;

            if ($min > 0 && $max > 0) {
                $salary = "{$currency} {$min} - {$max}";
            } elseif ($min > 0) {
                $salary = "{$currency} {$min}+";
            }
        }

        return $salary;
    }

    /**
     * Map LinkedIn job types.
     */
    private function mapLinkedInJobType(string $linkedinType): string
    {
        $type_map = [
            'FULL_TIME' => 'full-time',
            'PART_TIME' => 'part-time',
            'CONTRACT' => 'contract',
            'TEMPORARY' => 'temporary',
            'INTERNSHIP' => 'internship',
            'VOLUNTEER' => 'volunteer',
        ];

        return $type_map[$linkedinType] ?? 'full-time';
    }

    /**
     * Map standard job type to LinkedIn format.
     */
    private function mapJobTypeToLinkedIn(string $jobType): string
    {
        $type_map = [
            'full-time' => 'FULL_TIME',
            'part-time' => 'PART_TIME',
            'contract' => 'CONTRACT',
            'temporary' => 'TEMPORARY',
            'internship' => 'INTERNSHIP',
        ];

        return $type_map[$jobType] ?? 'FULL_TIME';
    }

    /**
     * Map experience level.
     */
    private function mapExperienceLevel(string $level): string
    {
        $level_map = [
            'entry' => 'ENTRY_LEVEL',
            'mid' => 'MID_SENIOR',
            'senior' => 'SENIOR',
            'executive' => 'EXECUTIVE',
        ];

        return $level_map[$level] ?? 'ENTRY_LEVEL';
    }

    /**
     * Parse LinkedIn timestamp.
     */
    private function parseLinkedInDate(int $timestamp): string
    {
        if ($timestamp == 0) {
            return '';
        }

        return date('Y-m-d', $timestamp / 1000); // LinkedIn uses milliseconds
    }
}
