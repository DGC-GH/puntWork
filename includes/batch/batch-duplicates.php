<?php

/**
 * Batch duplicate handling utilities
 *
 * @package    Puntwork
 * @subpackage Batch
 * @since      1.0.0
 */

declare(strict_types=1);

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Handle duplicates for the batch.
 */
function handle_batch_duplicates(array $batch_guids, array $existing_by_guid, array &$logs, int &$duplicates_drafted, array &$post_ids_by_guid): void
{
    error_log('[PUNTWORK] [DUPLICATES-DEBUG] handle_batch_duplicates called with ' . count($batch_guids) . ' batch GUIDs');
    error_log('[PUNTWORK] [DUPLICATES-DEBUG] existing_by_guid has ' . count($existing_by_guid) . ' entries');

    // Use advanced deduplication if available and enabled
    if (class_exists('Puntwork\\JobDeduplicator') && apply_filters('puntwork_use_advanced_deduplication', true)) {
        error_log('[PUNTWORK] [DUPLICATES-DEBUG] Using advanced deduplication');
        \Puntwork\JobDeduplicator::handleDuplicatesAdvanced($batch_guids, $existing_by_guid, $logs, $duplicates_drafted, $post_ids_by_guid);
    } else {
        error_log('[PUNTWORK] [DUPLICATES-DEBUG] Using basic deduplication');
        // Fallback to original deduplication logic
        handle_duplicates($batch_guids, $existing_by_guid, $logs, $duplicates_drafted, $post_ids_by_guid);
    }

    error_log('[PUNTWORK] [DUPLICATES-DEBUG] After deduplication: ' . count($post_ids_by_guid) . ' GUIDs matched to existing posts');
    $new_jobs = count($batch_guids) - count($post_ids_by_guid);
    error_log('[PUNTWORK] [DUPLICATES-DEBUG] Expected new jobs: ' . $new_jobs);
}
