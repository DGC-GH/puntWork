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

require_once __DIR__ . '/../utilities/options-utilities.php';

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
    $existing_status = get_import_status();
    if ($existing_status && isset($existing_status['start_time']) && $existing_status['start_time'] > 0) {
        $start_time = $existing_status['start_time'];
        PuntWorkLogger::info('Using existing import start time: ' . $start_time, PuntWorkLogger::CONTEXT_BATCH);
    } else {
        $start_time = microtime(true);
        PuntWorkLogger::info('Starting new import with start time: ' . $start_time, PuntWorkLogger::CONTEXT_BATCH);
    }

    $json_path = PUNTWORK_PATH . 'feeds/combined-jobs.jsonl';

    if (!file_exists($json_path)) {
        error_log('JSONL file not found: ' . $json_path);
        return ['success' => false, 'message' => 'JSONL file not found', 'logs' => ['JSONL file not found']];
    }

    if (!is_readable($json_path)) {
        error_log('JSONL file not readable: ' . $json_path);
        return ['success' => false, 'message' => 'JSONL file not readable', 'logs' => ['JSONL file not readable']];
    }

    $total = get_json_item_count($json_path);

    if ($total == 0) {
        error_log('JSONL file is empty or contains no valid items: ' . $json_path);
        return ['success' => false, 'message' => 'JSONL file is empty or contains no valid items', 'logs' => ['JSONL file is empty or contains no valid items']];
    }

    // Cache existing job GUIDs if not already cached
    if (false === get_existing_guids()) {
        $all_jobs = $wpdb->get_results("SELECT p.ID, pm.meta_value AS guid FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID = pm.post_id WHERE p.post_type = 'job' AND pm.meta_key = 'guid'");
        set_existing_guids($all_jobs);
    }

    $processed_guids = get_processed_guids();
    $start_index = max(get_import_progress(), $batch_start);

    // For fresh starts (batch_start = 0), reset the status and create new start time
    if ($batch_start === 0) {
        $start_index = 0;
        // Clear processed GUIDs for fresh start
        $processed_guids = [];
        
        // Check if status is already properly initialized (from run_job_import_batch_ajax)
        $existing_status = get_import_status();
        $needs_reinit = true;
        
        if ($existing_status && 
            isset($existing_status['total']) && $existing_status['total'] === $total &&
            isset($existing_status['processed']) && $existing_status['processed'] === 0 &&
            isset($existing_status['complete']) && $existing_status['complete'] === false) {
            // Status is already properly initialized, don't clear it
            $needs_reinit = false;
            PuntWorkLogger::info('Using pre-initialized import status', PuntWorkLogger::CONTEXT_BATCH, [
                'total' => $existing_status['total'],
                'start_time' => $existing_status['start_time']
            ]);
        }
        
        if ($needs_reinit) {
            // Clear existing status for fresh start
            delete_option('job_import_status');
            $start_time = microtime(true);
            PuntWorkLogger::info('Fresh import start - resetting status and progress to 0', PuntWorkLogger::CONTEXT_BATCH);

            // Initialize status for manual import
            $initial_status = initialize_import_status($total, 'Manual import started - preparing to process items...', $start_time);
            set_import_status($initial_status);
        }
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
            $line = trim($line);
            if (!empty($line)) {
                $item = json_decode($line, true);
                if ($item !== null) {
                    $count++;
                }
            }
        }
        fclose($handle);
    }
    return $count;
}