<?php
/**
 * Import setup and initialization
 *
 * @package    Puntwork
 * @subpackage Import
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Import setup and validation
 * Handles preparation and prerequisite validation for job imports
 */

/**
 * Prepare import setup and validate prerequisites.
 *
 * @param int $batch_start Starting index for batch.
 * @return array|WP_Error Setup data or error.
 */
function prepare_import_setup($batch_start = 0) {
    do_action('qm/cease'); // Disable Query Monitor data collection to reduce memory usage
    ini_set('memory_limit', '512M');
    set_time_limit(1800);
    ignore_user_abort(true);

    global $wpdb;
    $acf_fields = get_acf_fields();
    $zero_empty_fields = get_zero_empty_fields();

    if (!defined('WP_IMPORTING')) {
        define('WP_IMPORTING', true);
    }
    wp_suspend_cache_invalidation(true);
    remove_action('post_updated', 'wp_save_post_revision');

    // Check if there's an existing import in progress and use its start time
    $existing_status = get_option('job_import_status');
    if ($existing_status && isset($existing_status['start_time']) && $existing_status['start_time'] > 0) {
        $start_time = $existing_status['start_time'];
        PuntWorkLogger::info('Using existing import start time: ' . $start_time, PuntWorkLogger::CONTEXT_BATCH);
    } else {
        $start_time = microtime(true);
        PuntWorkLogger::info('Starting new import with start time: ' . $start_time, PuntWorkLogger::CONTEXT_BATCH);
    }

    $json_path = ABSPATH . 'feeds/combined-jobs.jsonl';

    if (!file_exists($json_path)) {
        error_log('JSONL file not found: ' . $json_path);
        return ['success' => false, 'message' => 'JSONL file not found', 'logs' => ['JSONL file not found']];
    }

    $total = get_json_item_count($json_path);

    if ($total == 0) {
        return [
            'success' => true,
            'processed' => 0,
            'total' => 0,
            'published' => 0,
            'updated' => 0,
            'skipped' => 0,
            'duplicates_drafted' => 0,
            'time_elapsed' => 0,
            'complete' => true,
            'logs' => [],
            'batch_size' => 0,
            'inferred_languages' => 0,
            'inferred_benefits' => 0,
            'schema_generated' => 0,
            'batch_time' => 0,
            'batch_processed' => 0
        ];
    }

    // Cache existing job GUIDs if not already cached
    if (false === get_option('job_existing_guids')) {
        $all_jobs = $wpdb->get_results("SELECT p.ID, pm.meta_value AS guid FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID = pm.post_id WHERE p.post_type = 'job' AND pm.meta_key = 'guid'");
        update_option('job_existing_guids', $all_jobs, false);
    }

    $processed_guids = get_option('job_import_processed_guids') ?: [];
    $start_index = max((int) get_option('job_import_progress'), $batch_start);

    // For fresh starts (batch_start = 0), reset the status and create new start time
    if ($batch_start === 0) {
        $start_index = 0;
        // Clear processed GUIDs for fresh start
        $processed_guids = [];
        // Clear existing status for fresh start
        delete_option('job_import_status');
        $start_time = microtime(true);
        PuntWorkLogger::info('Fresh import start - resetting status and progress to 0', PuntWorkLogger::CONTEXT_BATCH);
    }

    if ($start_index >= $total) {
        return [
            'success' => true,
            'processed' => $total,
            'total' => $total,
            'published' => 0,
            'updated' => 0,
            'skipped' => 0,
            'duplicates_drafted' => 0,
            'time_elapsed' => 0,
            'complete' => true,
            'logs' => ['Start index beyond total items'],
            'batch_size' => 0,
            'inferred_languages' => 0,
            'inferred_benefits' => 0,
            'schema_generated' => 0,
            'batch_time' => 0,
            'batch_processed' => 0
        ];
    }

    return [
        'acf_fields' => $acf_fields,
        'zero_empty_fields' => $zero_empty_fields,
        'start_time' => $start_time,
        'json_path' => $json_path,
        'total' => $total,
        'processed_guids' => $processed_guids,
        'start_index' => $start_index
    ];
}

/**
 * Get the total count of items in JSONL file.
 *
 * @param string $json_path Path to JSONL file.
 * @return int Total item count.
 */
function get_json_item_count($json_path) {
    $count = 0;
    if (($handle = fopen($json_path, "r")) !== false) {
        while (($line = fgets($handle)) !== false) {
            if (!empty(trim($line))) {
                $count++;
            }
        }
        fclose($handle);
    }
    return $count;
}