<?php

/**
 * GraphQL API support for advanced queries and mutations
 *
 * @package    Puntwork
 * @subpackage API
 * @since      2.2.0
 */

namespace Puntwork\API;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

/**
 * GraphQL Schema and Resolvers for Job Data
 */
class GraphQLAPI
{
    private static array $schema = array();

    /**
     * Initialize GraphQL API
     */
    public static function init(): void
    {
        add_action('rest_api_init', array( __CLASS__, 'registerGraphQLEndpoint' ));
        self::buildSchema();
    }

    /**
     * Register GraphQL endpoint
     */
    public static function registerGraphQLEndpoint(): void
    {
        register_rest_route(
            'puntwork/v1',
            '/graphql',
            array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handleGraphQLRequest' ),
            'permission_callback' => array( __CLASS__, 'checkPermissions' ),
            'args'                => array(
            'query'     => array(
            'required'    => true,
            'type'        => 'string',
            'description' => 'GraphQL query string',
                    ),
                    'variables' => array(
                        'type'        => 'object',
                        'description' => 'GraphQL variables',
                    ),
            ),
            )
        );
    }

    /**
     * Check API permissions
     */
    public static function checkPermissions(\WP_REST_Request $request): bool
    {
        // Check API key authentication
        $apiKey = $request->get_header('X-API-Key') ?: $request->get_param('api_key');

        if (empty($apiKey)) {
            return false;
        }

        $storedKey = get_option('puntwork_api_key');
        return hash_equals($storedKey, $apiKey);
    }

    /**
     * Handle GraphQL request
     */
    public static function handleGraphQLRequest(\WP_REST_Request $request): \WP_REST_Response
    {
        $query     = $request->get_param('query');
        $variables = $request->get_param('variables') ?: array();

        try {
            $result = self::executeQuery($query, $variables);
            return new \WP_REST_Response($result, 200);
        } catch (\Exception $e) {
            return new \WP_REST_Response(
                array(
                'errors' => array(
                array(
                'message'   => $e->getMessage(),
                'locations' => array(),
                        ),
                ),
                ),
                400
            );
        }
    }

    /**
     * Execute GraphQL query
     */
    private static function executeQuery(string $query, array $variables = array()): array
    {
        // Parse query (simplified implementation)
        $parsed = self::parseGraphQLQuery($query);

        $data = array();

        foreach ($parsed['fields'] as $field) {
            $resolver = self::getFieldResolver($field['name']);
            if ($resolver) {
                $args                   = array_merge($field['args'], $variables);
                $data[ $field['name'] ] = call_user_func($resolver, $args, $field['selections']);
            }
        }

        return array( 'data' => $data );
    }

    /**
     * Parse GraphQL query (basic implementation)
     */
    private static function parseGraphQLQuery(string $query): array
    {
        // This is a simplified parser - in production, use a proper GraphQL library
        $fields = array();

        // Extract query content
        if (preg_match('/query\s*\{([^}]+)\}/s', $query, $matches)) {
            $queryContent = trim($matches[1]);

            // Split by field declarations
            $fieldDeclarations = preg_split('/(\w+)\s*(\([^)]*\))?\s*\{/', $queryContent, -1, PREG_SPLIT_DELIM_CAPTURE);

            for ($i = 1; $i < count($fieldDeclarations); $i += 3) {
                $fieldName  = $fieldDeclarations[ $i ];
                $args       = $fieldDeclarations[ $i + 1 ] ?? '';
                $selections = $fieldDeclarations[ $i + 2 ] ?? '';

                // Parse arguments
                $parsedArgs = array();
                if (! empty($args)) {
                    preg_match_all('/(\w+):\s*([^,)]+)/', $args, $argMatches, PREG_SET_ORDER);
                    foreach ($argMatches as $arg) {
                        $parsedArgs[ $arg[1] ] = trim($arg[2], '"\'');
                    }
                }

                // Parse selections
                $parsedSelections = array();
                if (! empty($selections)) {
                    preg_match_all('/(\w+)/', $selections, $selMatches);
                    $parsedSelections = $selMatches[1];
                }

                $fields[] = array(
                 'name'       => $fieldName,
                 'args'       => $parsedArgs,
                 'selections' => $parsedSelections,
                );
            }
        }

        return array( 'fields' => $fields );
    }

    /**
     * Get field resolver
     */
    private static function getFieldResolver(string $fieldName): ?callable
    {
        $resolvers = array(
        'jobs'         => array( __CLASS__, 'resolveJobs' ),
        'job'          => array( __CLASS__, 'resolveJob' ),
        'importStatus' => array( __CLASS__, 'resolveImportStatus' ),
        'analytics'    => array( __CLASS__, 'resolveAnalytics' ),
        'feeds'        => array( __CLASS__, 'resolveFeeds' ),
        );

        return $resolvers[ $fieldName ] ?? null;
    }

    /**
     * Resolve jobs query
     */
    public static function resolveJobs(array $args, array $selections): array
    {
        $query = new \WP_Query(
            array(
            'post_type'      => 'job-feed',
            'posts_per_page' => $args['limit'] ?? 10,
            'offset'         => $args['offset'] ?? 0,
            'meta_query'     => array(),
            )
        );

        $jobs = array();
        while ($query->have_posts()) {
            $query->the_post();
            $jobId  = get_the_ID();
            $jobs[] = self::formatJobData($jobId, $selections);
        }

        return array(
        'nodes'       => $jobs,
        'totalCount'  => $query->found_posts,
        'hasNextPage' => ( $query->post_count + ( $args['offset'] ?? 0 ) ) < $query->found_posts,
        );
    }

    /**
     * Resolve single job query
     */
    public static function resolveJob(array $args, array $selections): ?array
    {
        if (empty($args['id'])) {
            return null;
        }

        $job = get_post($args['id']);
        if (! $job || $job->post_type !== 'job-feed') {
            return null;
        }

        return self::formatJobData($args['id'], $selections);
    }

    /**
     * Resolve import status query
     */
    public static function resolveImportStatus(array $args, array $selections): array
    {
        $status = get_option('job_import_status', array());

        return array(
        'isRunning'  => $status['success'] ?? false,
        'progress'   => $status['processed'] ?? 0,
        'total'      => $status['total'] ?? 0,
        'lastUpdate' => $status['last_update'] ?? null,
        'message'    => $status['error_message'] ?? '',
        );
    }

    /**
     * Resolve analytics query
     */
    public static function resolveAnalytics(array $args, array $selections): array
    {
        $period = $args['period'] ?? '30d';

        if (class_exists('\Puntwork\ImportAnalytics')) {
            $data = \Puntwork\ImportAnalytics::get_analytics_data($period);
            return array(
            'period'                => $period,
            'totalImports'          => $data['total_imports'] ?? 0,
            'successfulImports'     => $data['successful_imports'] ?? 0,
            'failedImports'         => $data['failed_imports'] ?? 0,
            'averageProcessingTime' => $data['avg_processing_time'] ?? 0,
            'totalJobsProcessed'    => $data['total_jobs'] ?? 0,
            );
        }

        return array();
    }

    /**
     * Resolve feeds query
     */
    public static function resolveFeeds(array $args, array $selections): array
    {
        $feeds = get_option('job_feed_url', array());

        $feedList = array();
        foreach ($feeds as $slug => $url) {
            $feedList[] = array(
            'slug'       => $slug,
            'url'        => $url,
            'type'       => 'xml', // Default assumption
            'lastImport' => get_option("feed_{$slug}_last_import"),
            'status'     => 'active',
            );
        }

        return array(
        'nodes'      => $feedList,
        'totalCount' => count($feedList),
        );
    }

    /**
     * Format job data for GraphQL response
     */
    private static function formatJobData(int $jobId, array $selections): array
    {
        $job  = get_post($jobId);
        $data = array(
        'id'       => $jobId,
        'title'    => $job->post_title,
        'content'  => $job->post_content,
        'status'   => $job->post_status,
        'date'     => $job->post_date,
        'modified' => $job->post_modified,
        );

        // Add selected meta fields
        if (in_array('category', $selections)) {
            $data['category'] = get_post_meta($jobId, 'job_category', true);
        }

        if (in_array('location', $selections)) {
            $data['location'] = get_post_meta($jobId, 'job_location', true);
        }

        if (in_array('salary', $selections)) {
            $data['salary'] = get_post_meta($jobId, 'job_salary', true);
        }

        if (in_array('qualityScore', $selections)) {
            $data['qualityScore'] = get_post_meta($jobId, 'job_quality_score', true);
        }

        return $data;
    }

    /**
     * Build GraphQL schema definition
     */
    private static function buildSchema(): void
    {
        self::$schema = array(
        'types'   => array(
        'Job'           => array(
        'fields' => array(
         'id'           => array( 'type' => 'ID!' ),
         'title'        => array( 'type' => 'String!' ),
         'content'      => array( 'type' => 'String' ),
         'status'       => array( 'type' => 'String' ),
         'date'         => array( 'type' => 'String' ),
         'modified'     => array( 'type' => 'String' ),
         'category'     => array( 'type' => 'String' ),
         'location'     => array( 'type' => 'String' ),
         'salary'       => array( 'type' => 'String' ),
         'qualityScore' => array( 'type' => 'Float' ),
                    ),
        ),
        'JobConnection' => array(
                    'fields' => array(
                        'nodes'       => array( 'type' => '[Job!]!' ),
                        'totalCount'  => array( 'type' => 'Int!' ),
                        'hasNextPage' => array( 'type' => 'Boolean!' ),
        ),
        ),
        'ImportStatus'  => array(
                    'fields' => array(
                        'isRunning'  => array( 'type' => 'Boolean!' ),
                        'progress'   => array( 'type' => 'Int!' ),
                        'total'      => array( 'type' => 'Int!' ),
                        'lastUpdate' => array( 'type' => 'Int' ),
                        'message'    => array( 'type' => 'String' ),
        ),
        ),
        'Analytics'     => array(
                    'fields' => array(
                        'period'                => array( 'type' => 'String!' ),
                        'totalImports'          => array( 'type' => 'Int!' ),
                        'successfulImports'     => array( 'type' => 'Int!' ),
                        'failedImports'         => array( 'type' => 'Int!' ),
                        'averageProcessingTime' => array( 'type' => 'Float!' ),
                        'totalJobsProcessed'    => array( 'type' => 'Int!' ),
        ),
        ),
        ),
        'queries' => array(
        'jobs'         => array(
                    'type' => 'JobConnection!',
                    'args' => array(
                        'limit'  => array(
                            'type'         => 'Int',
                            'defaultValue' => 10,
                        ),
                        'offset' => array(
                            'type'         => 'Int',
                            'defaultValue' => 0,
                        ),
        ),
        ),
        'job'          => array(
                    'type' => 'Job',
                    'args' => array(
                        'id' => array( 'type' => 'ID!' ),
        ),
        ),
        'importStatus' => array(
                    'type' => 'ImportStatus!',
        ),
        'analytics'    => array(
                    'type' => 'Analytics!',
                    'args' => array(
                        'period' => array(
                            'type'         => 'String',
                            'defaultValue' => '30d',
                        ),
        ),
        ),
        ),
        );
    }

    /**
     * Get GraphQL schema
     */
    public static function getSchema(): array
    {
        return self::$schema;
    }
}
