<?php
/**
 * Import finalization utilities
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
 * Import finalization and status management
 * Handles completion of import batches and status updates
 */

/**
 * Finalize batch import and update status.
 *
 * @param array $result Processing result.
 * @return array Final result.
 */
function finalize_batch_import($result) {
    if (is_wp_error($result) || !$result['success']) {
        return $result;
    }

    $status = get_option('job_import_status') ?: [
        'total' => $result['total'],
        'processed' => 0,
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'duplicates_drafted' => 0,
        'drafted_old' => 0,
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

    if (!isset($status['start_time'])) {
        $status['start_time'] = $result['start_time'];
    }

    $status['processed'] = $result['processed'];
    $status['created'] += $result['created'];
    $status['updated'] += $result['updated'];
    $status['skipped'] += $result['skipped'];
    $status['duplicates_drafted'] += $result['duplicates_drafted'];
    $status['drafted_old'] += $result['drafted_old'];
    $status['time_elapsed'] += $result['batch_time'];
    $status['complete'] = $result['complete'];
    $status['batch_size'] = $result['batch_size'];
    $status['inferred_languages'] += $result['inferred_languages'];
    $status['inferred_benefits'] += $result['inferred_benefits'];
    $status['schema_generated'] += $result['schema_generated'];
    $status['last_update'] = time();

    update_option('job_import_status', $status, false);

    return $result;
}

/**
 * Clean up import transients and temporary data.
 *
 * @return void
 */
function cleanup_import_data() {
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
}

/**
 * Get import status summary.
 *
 * @return array Status summary.
 */
function get_import_status_summary() {
    $status = get_option('job_import_status', []);

    return [
        'total' => $status['total'] ?? 0,
        'processed' => $status['processed'] ?? 0,
        'created' => $status['created'] ?? 0,
        'updated' => $status['updated'] ?? 0,
        'skipped' => $status['skipped'] ?? 0,
        'duplicates_drafted' => $status['duplicates_drafted'] ?? 0,
        'drafted_old' => $status['drafted_old'] ?? 0,
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
 * @param array $status Current import status.
 * @return float Estimated time remaining in seconds.
 */
function calculate_estimated_time_remaining($status) {
    if ($status['complete'] || $status['processed'] == 0 || $status['time_elapsed'] == 0) {
        return 0;
    }

    $items_remaining = $status['total'] - $status['processed'];
    $time_per_item = $status['time_elapsed'] / $status['processed'];

    return $items_remaining * $time_per_item;
}