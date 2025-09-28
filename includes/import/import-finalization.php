<?php

/**
 * Import finalization utilities.
 *
 * @since      1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Import finalization and status management
 * Handles completion of import batches and status updates.
 */

/**
 * Finalize batch import and update status.
 *
 * @param  array $result Processing result.
 * @return array Final result.
 */
function finalize_batch_import($result)
{
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[PUNTWORK] [FINALIZE-START] finalize_batch_import called with result success=' . (isset($result['success']) ? $result['success'] : 'not set') . ', complete=' . (isset($result['complete']) ? $result['complete'] : 'not set'));
    }

    if (is_wp_error($result) || !$result['success']) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[PUNTWORK] [FINALIZE-ERROR] finalize_batch_import returning early due to error or failure');
        }

        return $result;
    }

    $status = get_option('job_import_status') ?: [
        'total' => $result['total'],
        'processed' => 0,
        'published' => 0,
        'updated' => 0,
        'skipped' => 0,
        'duplicates_drafted' => 0,
        'time_elapsed' => 0,
        'complete' => false,
        'batch_size' => $result['batch_size'],
        'inferred_languages' => 0,
        'inferred_benefits' => 0,
        'schema_generated' => 0,
        'start_time' => $result['start_time'],
        'last_update' => time(),
        'logs' => [],
    ];

    // Ensure start_time is set properly
    if (!isset($status['start_time']) || $status['start_time'] === 0) {
        $status['start_time'] = $result['start_time'] ?? microtime(true);
    }

    $status['processed'] = $result['processed'];
    $status['published'] += $result['published'];
    $status['updated'] += $result['updated'];
    $status['skipped'] += $result['skipped'];
    $status['duplicates_drafted'] += $result['duplicates_drafted'];

    // Calculate total elapsed time from start to now
    $current_time = microtime(true);
    $total_elapsed = $current_time - $status['start_time'];
    $status['time_elapsed'] = $total_elapsed;

    $status['complete'] = $result['complete'];
    $status['success'] = $result['success']; // Set success status
    $status['error_message'] = $result['message'] ?? ''; // Set error message if any
    $status['batch_size'] = $result['batch_size'];
    $status['inferred_languages'] += $result['inferred_languages'];
    $status['inferred_benefits'] += $result['inferred_benefits'];
    $status['schema_generated'] += $result['schema_generated'];
    $status['last_update'] = time();

    update_option('job_import_status', $status, false);
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[PUNTWORK] [FINALIZE-STATUS] Updated import status: processed=' . $status['processed'] . '/' . $status['total'] . ', complete=' . ($status['complete'] ? 'true' : 'false') . ', elapsed=' . round($total_elapsed, 2) . 's');
    }

    // Log completed import to history
    if ($result['complete'] && $result['success']) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[PUNTWORK] [FINALIZE-COMPLETE] Import completed successfully, logging to history');
        }
        $trigger_type = $status['trigger_type'] ?? 'scheduled';
        $test_mode = $status['test_mode'] ?? false;

        $details = [
            'success' => true,
            'duration' => $total_elapsed,
            'processed' => $result['processed'],
            'total' => $result['total'],
            'published' => $result['published'],
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
            'error_message' => '',
            'timestamp' => time(),
        ];

        // Use the appropriate logging function based on trigger type
        if ($trigger_type == 'manual') {
            log_manual_import_run($details);
        } else {
            log_scheduled_run($details, $test_mode, $trigger_type);
        }

        error_log(
            sprintf(
                '[PUNTWORK] Import completed and logged to history - Trigger: %s, Duration: %.2fs, Processed: %d/%d',
                $trigger_type,
                $total_elapsed,
                $result['processed'],
                $result['total']
            )
        );

        // Post new jobs to social media if enabled
        if (get_option('puntwork_social_auto_post_jobs', false)) {
            error_log('[PUNTWORK] [FINALIZE-SOCIAL] Auto-posting new jobs to social media');
            post_new_jobs_to_social_media($result);
        } else {
            error_log('[PUNTWORK] [FINALIZE-SOCIAL] Social media auto-post disabled');
        }
    } else {
        error_log('[PUNTWORK] [FINALIZE-INCOMPLETE] Import not complete or failed, skipping history logging and social media posting');
    }

    error_log('[PUNTWORK] [FINALIZE-END] finalize_batch_import completed');

    return $result;
}

/**
 * Clean up import transients and temporary data.
 *
 * @return void
 */
function cleanup_import_data()
{
    // Clean up transients
    delete_transient('import_cancel');

    // Clean up options that are no longer needed after successful import
    delete_option('job_import_progress');
    delete_option('job_import_processed_guids');
    delete_option('job_existing_guids');

    // Reset performance metrics for next import
    delete_option('job_import_time_per_job');
    delete_option('job_import_avg_time_per_job');
    delete_option('job_import_last_peak_memory');
    delete_option('job_import_batch_size');
    delete_option('job_import_consecutive_small_batches');

    // Clean up batch timing data
    delete_option('job_import_last_batch_time');
    delete_option('job_import_last_batch_processed');
}

/**
 * Get import status summary.
 *
 * @return array Status summary.
 */
function get_import_status_summary()
{
    $status = get_option('job_import_status', []);

    return [
        'total' => $status['total'] ?? 0,
        'processed' => $status['processed'] ?? 0,
        'published' => $status['published'] ?? 0,
        'updated' => $status['updated'] ?? 0,
        'skipped' => $status['skipped'] ?? 0,
        'duplicates_drafted' => $status['duplicates_drafted'] ?? 0,
        'complete' => $status['complete'] ?? false,
        'progress_percentage' => $status['total'] > 0 ? round(($status['processed'] / $status['total']) * 100, 2) : 0,
        'time_elapsed' => $status['time_elapsed'] ?? 0,
        'estimated_time_remaining' => calculate_estimated_time_remaining($status),
        'last_update' => $status['last_update'] ?? null,
    ];
}

/**
 * Calculate estimated time remaining for import.
 *
 * @param  array $status Current import status.
 * @return float Estimated time remaining in seconds.
 */
function calculate_estimated_time_remaining($status)
{
    if ($status['complete'] || $status['processed'] === 0 || $status['job_importing_time_elapsed'] === 0) {
        return 0;
    }

    $items_remaining = $status['total'] - $status['processed'];
    $time_per_item = $status['job_importing_time_elapsed'] / $status['processed'];
    $estimated_seconds = $items_remaining * $time_per_item;

    // PuntWorkLogger::debug('PHP time calculation', PuntWorkLogger::CONTEXT_BATCH, [
    // 'total' => $status['total'],
    // 'processed' => $status['processed'],
    // 'job_importing_time_elapsed' => $status['job_importing_time_elapsed'],
    // 'items_remaining' => $items_remaining,
    // 'time_per_item' => $time_per_item,
    // 'estimated_seconds' => $estimated_seconds
    // ]);

    return $estimated_seconds;
}

/**
 * Post new jobs to configured social media platforms.
 *
 * @param  array $import_result The import result data
 * @return void
 */
function post_new_jobs_to_social_media($import_result)
{
    if (!class_exists('SocialMedia\\SocialMediaManager')) {
        return;
    }

    try {
        $social_manager = new SocialMedia\SocialMediaManager();
        $default_platforms = get_option('puntwork_social_default_platforms', []);

        if (empty($default_platforms)) {
            return;
        }

        // Get recently published jobs (from this import)
        $recent_jobs = get_recent_imported_jobs($import_result);

        if (empty($recent_jobs)) {
            return;
        }

        $posted_count = 0;
        $max_posts = apply_filters('puntwork_social_max_auto_posts', 5); // Limit to prevent spam

        foreach ($recent_jobs as $job) {
            if ($posted_count >= $max_posts) {
                break;
            }

            $job_data = [
                'title' => get_the_title($job->ID),
                'company' => get_post_meta($job->ID, 'company', true),
                'location' => get_post_meta($job->ID, 'location', true),
                'url' => get_permalink($job->ID),
                'company_logo' => get_post_meta($job->ID, 'company_logo', true),
                'description' => get_post_meta($job->ID, 'description', true),
            ];

            $results = $social_manager->postJob($job_data, $default_platforms);

            $success_count = 0;
            foreach ($results as $platform => $result) {
                if ($result['success']) {
                    $success_count++;
                }
            }

            if ($success_count > 0) {
                $posted_count++;
            }

            \Puntwork\PuntWorkLogger::info(
                'Auto-posted job to social media',
                \Puntwork\PuntWorkLogger::CONTEXT_SOCIAL,
                [
                    'job_id' => $job->ID,
                    'job_title' => $job_data['title'],
                    'platforms' => $default_platforms,
                    'success_count' => $success_count,
                ]
            );
        }

        if ($posted_count > 0) {
            \Puntwork\PuntWorkLogger::info(
                'Completed auto-posting jobs to social media',
                \Puntwork\PuntWorkLogger::CONTEXT_SOCIAL,
                [
                    'total_jobs_posted' => $posted_count,
                    'platforms' => $default_platforms,
                ]
            );
        }
    } catch (\Exception $e) {
        \Puntwork\PuntWorkLogger::error(
            'Failed to auto-post jobs to social media',
            \Puntwork\PuntWorkLogger::CONTEXT_SOCIAL,
            [
                'error' => $e->getMessage(),
            ]
        );
    }
}

/**
 * Get recently imported jobs from the current import session.
 *
 * @param  array $import_result The import result data
 * @return array Array of job post objects
 */
function get_recent_imported_jobs($import_result)
{
    $jobs = [];

    // Get jobs imported in the last hour (to be safe)
    $args = [
        'post_type' => 'job-feed',
        'posts_per_page' => 20,
        'post_status' => 'publish',
        'orderby' => 'date',
        'order' => 'DESC',
        'date_query' => [
            [
                'after' => '1 hour ago',
            ],
        ],
    ];

    $query = new \WP_Query($args);

    if ($query->have_posts()) {
        $jobs = $query->posts;
    }

    return $jobs;
}
