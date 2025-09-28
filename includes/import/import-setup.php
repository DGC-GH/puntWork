<?php

/**
 * Import setup and initialization
 *
 * @package    Puntwork
 * @subpackage Import
 * @since      1.0.0
 */

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Import setup and validation
 * Handles preparation and prerequisite validation for job imports
 */

// Include field mappings
require_once __DIR__ . '/../mappings/mappings-fields.php';

// Include utility helpers
require_once __DIR__ . '/../utilities/utility-helpers.php';

/**
 * Validate JSONL file integrity by checking a sample of lines.
 *
 * @param  string $json_path Path to JSONL file.
 * @return true|WP_Error True if valid, WP_Error if invalid.
 */
function validate_jsonl_file($json_path)
{
    if (( $handle = fopen($json_path, 'r') ) === false) {
        return new WP_Error('file_open_failed', 'Cannot open JSONL file for validation');
    }

    $bom           = "\xef\xbb\xbf";
    $checked_lines = 0;
    $max_check     = min(100, filesize($json_path) / 100); // Check up to 100 lines or 1% of file

    while ($checked_lines < $max_check && ( $line = fgets($handle) ) !== false) {
        $line = trim($line);
        // Remove BOM if present
        if (substr($line, 0, 3) === $bom) {
            $line = substr($line, 3);
        }
        if (! empty($line)) {
            $item = json_decode($line, true);
            if ($item === null && json_last_error() !== JSON_ERROR_NONE) {
                fclose($handle);
                return new WP_Error('invalid_json', 'Invalid JSON at line ' . ( $checked_lines + 1 ) . ': ' . json_last_error_msg());
            }
            // Check for required fields
            if (! isset($item['guid']) || empty($item['guid'])) {
                fclose($handle);
                return new WP_Error('missing_guid', 'Missing or empty GUID at line ' . ( $checked_lines + 1 ));
            }
            ++$checked_lines;
        }
    }

    fclose($handle);
    return true;
}

/**
 * Prepare import setup and validate prerequisites.
 *
 * @param  int $batch_start Starting index for batch.
 * @return array|WP_Error Setup data or error.
 */
function prepare_import_setup($batch_start = 0)
{
    do_action('qm/cease'); // Disable Query Monitor data collection to reduce memory usage
    ini_set('memory_limit', '512M');
    set_time_limit(1800);
    ignore_user_abort(true);

    global $wpdb;
    error_log('[PUNTWORK] prepare_import_setup called with batch_start=' . $batch_start);

    try {
        $acf_fields = get_acf_fields();
        error_log('[PUNTWORK] Got ACF fields: ' . count($acf_fields));
    } catch (\Exception $e) {
        error_log('[PUNTWORK] Error getting ACF fields: ' . $e->getMessage());
        return new WP_Error('acf_error', 'Failed to get ACF fields: ' . $e->getMessage());
    }

    try {
        $zero_empty_fields = get_zero_empty_fields();
        error_log('[PUNTWORK] Got zero empty fields: ' . count($zero_empty_fields));
    } catch (\Exception $e) {
        error_log('[PUNTWORK] Error getting zero empty fields: ' . $e->getMessage());
        return new WP_Error('zero_fields_error', 'Failed to get zero empty fields: ' . $e->getMessage());
    }

    if (! defined('WP_IMPORTING')) {
        define('WP_IMPORTING', true);
    }
    wp_suspend_cache_invalidation(true);
    remove_action('post_updated', 'wp_save_post_revision');

    // Check if there's an existing import in progress and use its start time
    $existing_status = get_option('job_import_status');
    if ($existing_status && isset($existing_status['start_time']) && $existing_status['start_time'] > 0) {
        $start_time = $existing_status['start_time'];
        \Puntwork\PuntWorkLogger::info('Using existing import start time: ' . $start_time, \Puntwork\PuntWorkLogger::CONTEXT_BATCH);
    } else {
        $start_time = microtime(true);
        \Puntwork\PuntWorkLogger::info('Starting new import with start time: ' . $start_time, \Puntwork\PuntWorkLogger::CONTEXT_BATCH);
    }

    $json_path = ABSPATH . 'feeds/combined-jobs.jsonl';
    error_log('[PUNTWORK] [DEBUG] prepare_import_setup: JSONL path: ' . $json_path);
    error_log('[PUNTWORK] [DEBUG] prepare_import_setup: ABSPATH: ' . ABSPATH);
    error_log('[PUNTWORK] [DEBUG] prepare_import_setup: feeds/ directory exists: ' . ( is_dir(ABSPATH . 'feeds/') ? 'yes' : 'no' ));
    error_log('[PUNTWORK] [DEBUG] prepare_import_setup: feeds/ directory writable: ' . ( is_writable(ABSPATH . 'feeds/') ? 'yes' : 'no' ));
    $files_in_feeds = glob(ABSPATH . 'feeds/*');
    error_log('[PUNTWORK] [DEBUG] prepare_import_setup: Files in feeds/ directory: ' . print_r($files_in_feeds, true));
    error_log('[PUNTWORK] [DEBUG] prepare_import_setup: File exists: ' . ( file_exists($json_path) ? 'yes' : 'no' ));
    if (file_exists($json_path)) {
        error_log('[PUNTWORK] [DEBUG] prepare_import_setup: File size: ' . filesize($json_path) . ' bytes');
        $mtime = filemtime($json_path);
        error_log('[PUNTWORK] [DEBUG] prepare_import_setup: File mtime: ' . date('Y-m-d H:i:s', $mtime) . ', age: ' . ( time() - $mtime ) . ' seconds');
        $first_line = '';
        $handle     = fopen($json_path, 'r');
        if ($handle) {
            $first_line = fgets($handle);
            fclose($handle);
            error_log('[PUNTWORK] [DEBUG] prepare_import_setup: First line preview: ' . substr($first_line, 0, 200));
        }
    }

    if (! file_exists($json_path)) {
        error_log('[PUNTWORK] [DEBUG] prepare_import_setup: JSONL file not found: ' . $json_path . ' - checking if feeds need to be processed first');
        // Check if there are any individual feed files
        $feed_files = glob(ABSPATH . 'feeds/*.jsonl');
        error_log('[PUNTWORK] [DEBUG] prepare_import_setup: Individual feed files found: ' . print_r($feed_files, true));
        if (empty($feed_files)) {
            error_log('[PUNTWORK] [DEBUG] prepare_import_setup: No individual feed files found - feeds may not be configured or processed');
        } else {
            error_log('[PUNTWORK] [DEBUG] prepare_import_setup: Individual feeds exist but combined file missing - need to run combine_jsonl_files');
        }
        return array(
            'success' => false,
            'message' => 'JSONL file not found - feeds may need to be processed first',
            'logs'    => array( 'JSONL file not found - run feed processing first' ),
        );
    }

    if (! is_readable($json_path)) {
        error_log('[PUNTWORK] JSONL file not readable: ' . $json_path);
        return array(
            'success' => false,
            'message' => 'JSONL file not readable',
            'logs'    => array( 'JSONL file not readable' ),
        );
    }

    // Validate JSONL file integrity
    $validation = validate_jsonl_file($json_path);
    if (is_wp_error($validation)) {
        error_log('[PUNTWORK] JSONL validation failed: ' . $validation->get_error_message());
        return array(
            'success' => false,
            'message' => 'JSONL file validation failed: ' . $validation->get_error_message(),
            'logs'    => array( 'JSONL file validation failed: ' . $validation->get_error_message() ),
        );
    }

    try {
        $total = get_json_item_count($json_path);
        error_log('[PUNTWORK] Total items in JSONL: ' . $total);
    } catch (\Exception $e) {
        error_log('[PUNTWORK] Error counting JSONL items: ' . $e->getMessage());
        return new WP_Error('count_error', 'Failed to count JSONL items: ' . $e->getMessage());
    }

    if ($total == 0) {
        return array(
            'success'            => true,
            'processed'          => 0,
            'total'              => 0,
            'published'          => 0,
            'updated'            => 0,
            'skipped'            => 0,
            'duplicates_drafted' => 0,
            'time_elapsed'       => 0,
            'complete'           => true,
            'logs'               => array(),
            'batch_size'         => 0,
            'inferred_languages' => 0,
            'inferred_benefits'  => 0,
            'schema_generated'   => 0,
            'batch_time'         => 0,
            'batch_processed'    => 0,
        );
    }

    // Cache existing job GUIDs if not already cached
    if (false === get_option('job_existing_guids')) {
        $all_jobs = $wpdb->get_results("SELECT p.ID, pm.meta_value AS guid FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID = pm.post_id WHERE p.post_type = 'job' AND pm.meta_key = 'guid'");
        update_option('job_existing_guids', $all_jobs, false);
    }

    $processed_guids = get_option('job_import_processed_guids') ?: array();
    $start_index     = max((int) get_option('job_import_progress'), $batch_start);
    error_log('[PUNTWORK] prepare_import_setup: initial start_index calculation: max(' . (int) get_option('job_import_progress') . ', ' . $batch_start . ') = ' . $start_index);

    // For fresh starts (batch_start = 0), reset the status and create new start time
    if ($batch_start === 0) {
        $start_index = 0;
        error_log('[PUNTWORK] prepare_import_setup: fresh start detected, setting start_index to 0');
        // Check if existing status total matches current total
        $existing_status = get_option('job_import_status', array());
        if (isset($existing_status['total']) && $existing_status['total'] != $total) {
            error_log('[PUNTWORK] [WARNING] Existing status total (' . $existing_status['total'] . ') does not match current file total (' . $total . ') - resetting status');
            delete_option('job_import_status');
        }
        // Clear processed GUIDs for fresh start
        $processed_guids = array();
        // Clear existing status for fresh start
        delete_option('job_import_status');
        // Clear progress for fresh start
        update_option('job_import_progress', 0, false);
        $start_time = microtime(true);
        \Puntwork\PuntWorkLogger::info('Fresh import start - resetting status and progress to 0', \Puntwork\PuntWorkLogger::CONTEXT_BATCH);

        // Initialize status for manual import
        $initial_status = array(
            'total'              => $total,
            'processed'          => 0,
            'published'          => 0,
            'updated'            => 0,
            'skipped'            => 0,
            'duplicates_drafted' => 0,
            'time_elapsed'       => 0,
            'complete'           => false,
            'success'            => false,
            'error_message'      => '',
            'batch_size'         => get_option('job_import_batch_size') ?: 1,
            'inferred_languages' => 0,
            'inferred_benefits'  => 0,
            'schema_generated'   => 0,
            'start_time'         => $start_time,
            'end_time'           => null,
            'last_update'        => time(),
            'logs'               => array( 'Manual import started - preparing to process items...' ),
        );
        update_option('job_import_status', $initial_status, false);
    }

    if ($start_index >= $total) {
        error_log('[PUNTWORK] prepare_import_setup: EARLY RETURN - start_index (' . $start_index . ') >= total (' . $total . ')');
        return array(
            'success'            => true,
            'processed'          => $total,
            'total'              => $total,
            'published'          => 0,
            'updated'            => 0,
            'skipped'            => 0,
            'duplicates_drafted' => 0,
            'time_elapsed'       => 0,
            'complete'           => true,
            'logs'               => array( 'Start index beyond total items' ),
            'batch_size'         => 0,
            'inferred_languages' => 0,
            'inferred_benefits'  => 0,
            'schema_generated'   => 0,
            'batch_time'         => 0,
            'batch_processed'    => 0,
        );
    }

    return array(
        'acf_fields'        => $acf_fields,
        'zero_empty_fields' => $zero_empty_fields,
        'start_time'        => $start_time,
        'json_path'         => $json_path,
        'total'             => $total,
        'processed_guids'   => $processed_guids,
        'start_index'       => $start_index,
    );
}
