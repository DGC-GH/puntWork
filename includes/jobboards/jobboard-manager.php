<?php

/**
 * Job Board Manager
 *
 * @package    Puntwork
 * @subpackage JobBoards
 * @since      2.2.0
 */

namespace Puntwork\JobBoards;

// Prevent direct access
if (! defined('ABSPATH') ) {
    exit;
}

/**
 * Manages multiple job board integrations
 */
class JobBoardManager
{

    /**
     * Available job board classes
     */
    private static array $available_boards = array(
    'indeed'    => IndeedBoard::class,
    'linkedin'  => LinkedInBoard::class,
    'glassdoor' => GlassdoorBoard::class,
    );

    /**
     * Configured job board instances
     */
    private array $boards = array();

    /**
     * Constructor - loads configured job boards
     */
    public function __construct()
    {
        $this->loadConfiguredBoards();
    }

    /**
     * Load configured job boards from WordPress options
     */
    private function loadConfiguredBoards(): void
    {
        $board_configs = get_option('puntwork_job_boards', array());

        foreach ( $board_configs as $board_id => $config ) {
            if (isset(self::$available_boards[ $board_id ]) && isset($config['enabled']) && $config['enabled'] ) {
                try {
                    $board_class               = self::$available_boards[ $board_id ];
                    $this->boards[ $board_id ] = new $board_class($config);
                } catch ( \Exception $e ) {
                    PuntWorkLogger::error(
                        'Failed to initialize job board',
                        PuntWorkLogger::CONTEXT_IMPORT,
                        array(
                        'board_id' => $board_id,
                        'error'    => $e->getMessage(),
                        )
                    );
                }
            }
        }
    }

    /**
     * Get all available job board types
     */
    public static function getAvailableBoards(): array
    {
        $boards = array();

        foreach ( self::$available_boards as $board_id => $board_class ) {
            $boards[ $board_id ] = array(
            'name'  => $board_class::getBoardName(),
            'class' => $board_class,
            );
        }

        return $boards;
    }

    /**
     * Get configured job boards
     */
    public function getConfiguredBoards(): array
    {
        return array_keys($this->boards);
    }

    /**
     * Check if a job board is configured
     */
    public function isBoardConfigured( string $board_id ): bool
    {
        return isset($this->boards[ $board_id ]);
    }

    /**
     * Get a specific job board instance
     */
    public function getBoard( string $board_id ): ?JobBoard
    {
        return $this->boards[ $board_id ] ?? null;
    }

    /**
     * Fetch jobs from all configured boards
     *
     * @param  array $params    Search parameters
     * @param  array $board_ids Specific board IDs to search (empty = all)
     * @return array Combined job results
     */
    public function fetchAllJobs( array $params = array(), array $board_ids = array() ): array
    {
        $all_jobs         = array();
        $boards_to_search = empty($board_ids) ? $this->boards : array_intersect_key($this->boards, array_flip($board_ids));

        foreach ( $boards_to_search as $board_id => $board ) {
            try {
                $jobs     = $board->fetchJobs($params);
                $all_jobs = array_merge($all_jobs, $jobs);

                PuntWorkLogger::info(
                    'Fetched jobs from board',
                    PuntWorkLogger::CONTEXT_IMPORT,
                    array(
                    'board_id'  => $board_id,
                    'job_count' => count($jobs),
                    )
                );
            } catch ( \Exception $e ) {
                PuntWorkLogger::error(
                    'Failed to fetch jobs from board',
                    PuntWorkLogger::CONTEXT_IMPORT,
                    array(
                    'board_id' => $board_id,
                    'error'    => $e->getMessage(),
                    )
                );
            }
        }

        return $all_jobs;
    }

    /**
     * Search jobs across all configured boards
     *
     * @param  array $filters   Search filters
     * @param  array $board_ids Specific board IDs to search (empty = all)
     * @return array Combined search results
     */
    public function searchAllBoards( array $filters = array(), array $board_ids = array() ): array
    {
        $all_jobs         = array();
        $boards_to_search = empty($board_ids) ? $this->boards : array_intersect_key($this->boards, array_flip($board_ids));

        foreach ( $boards_to_search as $board_id => $board ) {
            try {
                $jobs     = $board->searchJobs($filters);
                $all_jobs = array_merge($all_jobs, $jobs);
            } catch ( \Exception $e ) {
                PuntWorkLogger::error(
                    'Failed to search board',
                    PuntWorkLogger::CONTEXT_IMPORT,
                    array(
                    'board_id' => $board_id,
                    'error'    => $e->getMessage(),
                    )
                );
            }
        }

        return $all_jobs;
    }

    /**
     * Get job details from specific board
     */
    public function getJobDetails( string $board_id, string $job_id ): ?array
    {
        $board = $this->getBoard($board_id);

        if (! $board ) {
            return null;
        }

        try {
            return $board->getJobDetails($job_id);
        } catch ( \Exception $e ) {
            PuntWorkLogger::error(
                'Failed to get job details',
                PuntWorkLogger::CONTEXT_IMPORT,
                array(
                'board_id' => $board_id,
                'job_id'   => $job_id,
                'error'    => $e->getMessage(),
                )
            );
            return null;
        }
    }

    /**
     * Configure a job board
     */
    public static function configureBoard( string $board_id, array $config ): bool
    {
        if (! isset(self::$available_boards[ $board_id ]) ) {
            return false;
        }

        $board_configs              = get_option('puntwork_job_boards', array());
        $board_configs[ $board_id ] = $config;

        return update_option('puntwork_job_boards', $board_configs);
    }

    /**
     * Remove a job board configuration
     */
    public static function removeBoard( string $board_id ): bool
    {
        $board_configs = get_option('puntwork_job_boards', array());

        if (isset($board_configs[ $board_id ]) ) {
            unset($board_configs[ $board_id ]);
            return update_option('puntwork_job_boards', $board_configs);
        }

        return false;
    }

    /**
     * Get board configuration
     */
    public static function getBoardConfig( string $board_id ): ?array
    {
        $board_configs = get_option('puntwork_job_boards', array());
        return $board_configs[ $board_id ] ?? null;
    }

    /**
     * Get all board configurations
     */
    public static function getAllBoardConfigs(): array
    {
        return get_option('puntwork_job_boards', array());
    }

    /**
     * Test board configuration
     */
    public function testBoard( string $board_id ): array
    {
        $board = $this->getBoard($board_id);

        if (! $board ) {
            return array(
            'success' => false,
            'message' => 'Board not configured',
            );
        }

        try {
            // Try to fetch a small number of jobs to test the connection
            $test_jobs = $board->fetchJobs(array( 'limit' => 1 ));

            return array(
            'success'   => true,
            'message'   => 'Board connection successful',
            'job_count' => count($test_jobs),
            );
        } catch ( \Exception $e ) {
            return array(
            'success' => false,
            'message' => 'Board connection failed: ' . $e->getMessage(),
            );
        }
    }

    /**
     * Get supported filters for all boards
     */
    public function getSupportedFilters(): array
    {
        $all_filters = array();

        foreach ( $this->boards as $board ) {
            $filters     = $board->getSupportedFilters();
            $all_filters = array_unique(array_merge($all_filters, $filters));
        }

        return $all_filters;
    }
}
