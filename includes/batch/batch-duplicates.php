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
    // Use advanced deduplication if available and enabled
    if (class_exists('Puntwork\\JobDeduplicator') && apply_filters('puntwork_use_advanced_deduplication', true)) {
        \Puntwork\JobDeduplicator::handleDuplicatesAdvanced($batch_guids, $existing_by_guid, $logs, $duplicates_drafted, $post_ids_by_guid);
    } else {
        // Fallback to original deduplication logic
        handle_duplicates($batch_guids, $existing_by_guid, $logs, $duplicates_drafted, $post_ids_by_guid);
    }
}
