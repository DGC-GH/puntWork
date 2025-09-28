<?php

/**
 * Content-Based Batch Prioritization System for Import Performance.
 *
 * @since      1.0.9
 */

namespace Puntwork\Utilities;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Content-Based Batch Prioritization System.
 *
 * Sorts batches by change type (new > updated > unchanged) before processing
 * to ensure users see new content faster, improving perceived performance.
 */
class BatchPrioritizer
{
    /**
     * Priority levels for different content types.
     */
    public const PRIORITY_NEW = 1;
    public const PRIORITY_UPDATED = 2;
    public const PRIORITY_UNCHANGED = 3;

    /**
     * Priority configuration.
     */
    private static array $priority_config = [
        'enable_prioritization' => true,
        'priority_weights' => [
            self::PRIORITY_NEW => 1.0,
            self::PRIORITY_UPDATED => 0.7,
            self::PRIORITY_UNCHANGED => 0.3,
        ],
        'max_priority_boost' => 2.0,
        'min_priority_penalty' => 0.1,
    ];

    /**
     * Analyze and prioritize batch items based on content changes.
     *
     * @param array $batch_guids Array of GUIDs in the batch
     * @param array $batch_items Array of batch items
     * @param array $batch_metadata Batch metadata (last_updates, hashes_by_post)
     * @param array $post_ids_by_guid Mapping of GUIDs to post IDs
     * @return array Prioritized batch data
     */
    public static function prioritizeBatch(
        array $batch_guids,
        array $batch_items,
        array $batch_metadata,
        array $post_ids_by_guid
    ): array {
        if (!self::$priority_config['enable_prioritization']) {
            return [
                'prioritized_guids' => $batch_guids,
                'prioritized_items' => $batch_items,
                'priority_stats' => ['prioritization_disabled' => true],
            ];
        }

        $start_time = microtime(true);

        // Analyze each item to determine its change type and priority
        $item_priorities = self::analyzeItemPriorities(
            $batch_guids,
            $batch_items,
            $batch_metadata,
            $post_ids_by_guid
        );

        // Sort items by priority (lower number = higher priority)
        $sorted_indices = self::sortByPriority($item_priorities);

        // Reorder batch data based on priority
        $prioritized_data = self::reorderBatchData(
            $batch_guids,
            $batch_items,
            $sorted_indices
        );

        $prioritization_time = microtime(true) - $start_time;

        // Calculate priority statistics
        $priority_stats = self::calculatePriorityStats($item_priorities, $prioritization_time);

        error_log(sprintf(
            '[PUNTWORK] [PRIORITY] Prioritized %d items in %.3f seconds - New: %d, Updated: %d, Unchanged: %d',
            count($batch_guids),
            $prioritization_time,
            $priority_stats['new_count'],
            $priority_stats['updated_count'],
            $priority_stats['unchanged_count']
        ));

        return [
            'prioritized_guids' => $prioritized_data['guids'],
            'prioritized_items' => $prioritized_data['items'],
            'priority_stats' => $priority_stats,
            'item_priorities' => $item_priorities, // For debugging/analysis
        ];
    }

    /**
     * Analyze the priority of each batch item.
     */
    private static function analyzeItemPriorities(
        array $batch_guids,
        array $batch_items,
        array $batch_metadata,
        array $post_ids_by_guid
    ): array {
        $item_priorities = [];

        foreach ($batch_guids as $index => $guid) {
            $item = $batch_items[$guid] ?? [];
            $post_id = $post_ids_by_guid[$guid] ?? null;

            $priority_analysis = self::analyzeSingleItemPriority(
                $guid,
                $item,
                $post_id,
                $batch_metadata
            );

            $item_priorities[$index] = [
                'guid' => $guid,
                'priority' => $priority_analysis['priority'],
                'change_type' => $priority_analysis['change_type'],
                'confidence' => $priority_analysis['confidence'],
                'reasons' => $priority_analysis['reasons'],
            ];
        }

        return $item_priorities;
    }

    /**
     * Analyze the priority of a single batch item.
     */
    private static function analyzeSingleItemPriority(
        string $guid,
        array $item,
        ?int $post_id,
        array $batch_metadata
    ): array {
        $analysis = [
            'priority' => self::PRIORITY_UNCHANGED,
            'change_type' => 'unchanged',
            'confidence' => 1.0,
            'reasons' => [],
        ];

        // If no existing post, this is new content (highest priority)
        if (!$post_id) {
            $analysis['priority'] = self::PRIORITY_NEW;
            $analysis['change_type'] = 'new';
            $analysis['reasons'][] = 'no_existing_post';
            return $analysis;
        }

        // Check if content has actually changed
        $has_changed = self::hasContentChanged($item, $post_id, $batch_metadata);

        if ($has_changed) {
            $analysis['priority'] = self::PRIORITY_UPDATED;
            $analysis['change_type'] = 'updated';
            $analysis['reasons'] = $has_changed['reasons'];
            $analysis['confidence'] = $has_changed['confidence'];
        } else {
            $analysis['priority'] = self::PRIORITY_UNCHANGED;
            $analysis['change_type'] = 'unchanged';
            $analysis['reasons'][] = 'content_unchanged';
        }

        // Apply priority modifiers based on content characteristics
        $analysis = self::applyPriorityModifiers($analysis, $item);

        return $analysis;
    }

    /**
     * Check if the content has changed compared to existing post.
     */
    private static function hasContentChanged(array $new_item, int $post_id, array $batch_metadata): array|false
    {
        $changes = ['reasons' => [], 'confidence' => 1.0];
        $has_changes = false;

        // Check import hash if available
        $existing_hash = $batch_metadata['hashes_by_post'][$post_id] ?? null;
        if ($existing_hash) {
            $new_hash = self::calculateItemHash($new_item);
            if ($existing_hash !== $new_hash) {
                $changes['reasons'][] = 'hash_mismatch';
                $has_changes = true;
            }
        }

        // Check last update timestamp
        $last_update = $batch_metadata['last_updates'][$post_id] ?? null;
        if ($last_update) {
            $last_update_time = strtotime($last_update->meta_value ?? '');
            $item_update_time = strtotime($new_item['updated'] ?? $new_item['date'] ?? '');

            if ($item_update_time && $item_update_time > $last_update_time) {
                $changes['reasons'][] = 'newer_timestamp';
                $has_changes = true;
            }
        }

        // Check key content fields for changes
        $content_fields = ['title', 'content', 'excerpt', 'job_description'];
        foreach ($content_fields as $field) {
            if (isset($new_item[$field])) {
                $existing_value = get_post_field($field, $post_id);
                if ($existing_value !== $new_item[$field]) {
                    $changes['reasons'][] = "field_changed:{$field}";
                    $has_changes = true;
                }
            }
        }

        // Check ACF fields if available
        if (function_exists('get_fields')) {
            $existing_acf = get_fields($post_id);
            $new_acf = $new_item['acf'] ?? [];

            if (self::haveAcfFieldsChanged($existing_acf, $new_acf)) {
                $changes['reasons'][] = 'acf_fields_changed';
                $has_changes = true;
            }
        }

        return $has_changes ? $changes : false;
    }

    /**
     * Calculate a hash for the item content.
     */
    private static function calculateItemHash(array $item): string
    {
        // Remove volatile fields that shouldn't affect content comparison
        $hash_item = $item;
        unset($hash_item['import_timestamp'], $hash_item['batch_id'], $hash_item['processed_at']);

        // Sort array for consistent hashing
        ksort($hash_item);

        return md5(json_encode($hash_item));
    }

    /**
     * Check if ACF fields have changed.
     */
    private static function haveAcfFieldsChanged(?array $existing_acf, array $new_acf): bool
    {
        if (!$existing_acf && !$new_acf) {
            return false;
        }

        if (!$existing_acf || !$new_acf) {
            return true;
        }

        // Simple comparison - could be enhanced with more sophisticated diffing
        return json_encode($existing_acf) !== json_encode($new_acf);
    }

    /**
     * Apply priority modifiers based on content characteristics.
     */
    private static function applyPriorityModifiers(array $analysis, array $item): array
    {
        $base_priority = $analysis['priority'];

        // Boost priority for urgent/high-priority content
        if (isset($item['priority']) && $item['priority'] === 'urgent') {
            $analysis['priority'] = max(1, $base_priority - 0.5);
            $analysis['reasons'][] = 'urgent_content';
        }

        // Boost priority for featured content
        if (isset($item['featured']) && $item['featured']) {
            $analysis['priority'] = max(1, $base_priority - 0.3);
            $analysis['reasons'][] = 'featured_content';
        }

        // Reduce priority for draft content
        if (isset($item['status']) && $item['status'] === 'draft') {
            $analysis['priority'] = min(3, $base_priority + 0.2);
            $analysis['reasons'][] = 'draft_content';
        }

        // Apply time-based decay for updated content
        if ($analysis['change_type'] === 'updated') {
            $days_since_update = self::calculateDaysSinceUpdate($item);
            if ($days_since_update > 30) {
                $decay_factor = min(0.5, $days_since_update / 365); // Max 50% decay
                $analysis['priority'] = min(3, $base_priority + $decay_factor);
                $analysis['reasons'][] = 'time_decay';
            }
        }

        return $analysis;
    }

    /**
     * Calculate days since the item was last updated.
     */
    private static function calculateDaysSinceUpdate(array $item): int
    {
        $update_time = strtotime($item['updated'] ?? $item['date'] ?? '');
        if (!$update_time) {
            return 0;
        }

        return (time() - $update_time) / (60 * 60 * 24);
    }

    /**
     * Sort items by priority (stable sort to maintain relative order for same priority).
     */
    private static function sortByPriority(array $item_priorities): array
    {
        $indices = array_keys($item_priorities);

        usort($indices, function($a, $b) use ($item_priorities) {
            $priority_a = $item_priorities[$a]['priority'];
            $priority_b = $item_priorities[$b]['priority'];

            // Primary sort by priority (lower = higher priority)
            if ($priority_a !== $priority_b) {
                return $priority_a <=> $priority_b;
            }

            // Secondary sort by confidence (higher confidence first)
            $confidence_a = $item_priorities[$a]['confidence'];
            $confidence_b = $item_priorities[$b]['confidence'];

            return $confidence_b <=> $confidence_a;
        });

        return $indices;
    }

    /**
     * Reorder batch data based on sorted indices.
     */
    private static function reorderBatchData(array $batch_guids, array $batch_items, array $sorted_indices): array
    {
        $prioritized_guids = [];
        $prioritized_items = [];

        foreach ($sorted_indices as $original_index) {
            $guid = $batch_guids[$original_index];
            $prioritized_guids[] = $guid;
            $prioritized_items[$guid] = $batch_items[$guid];
        }

        return [
            'guids' => $prioritized_guids,
            'items' => $prioritized_items,
        ];
    }

    /**
     * Calculate statistics about the prioritization.
     */
    private static function calculatePriorityStats(array $item_priorities, float $prioritization_time): array
    {
        $stats = [
            'total_items' => count($item_priorities),
            'new_count' => 0,
            'updated_count' => 0,
            'unchanged_count' => 0,
            'prioritization_time' => $prioritization_time,
            'avg_confidence' => 0,
        ];

        $total_confidence = 0;

        foreach ($item_priorities as $item) {
            switch ($item['change_type']) {
                case 'new':
                    $stats['new_count']++;
                    break;
                case 'updated':
                    $stats['updated_count']++;
                    break;
                case 'unchanged':
                    $stats['unchanged_count']++;
                    break;
            }

            $total_confidence += $item['confidence'];
        }

        $stats['avg_confidence'] = $stats['total_items'] > 0 ? $total_confidence / $stats['total_items'] : 0;

        return $stats;
    }

    /**
     * Get priority configuration.
     */
    public static function getConfig(): array
    {
        return self::$priority_config;
    }

    /**
     * Update priority configuration.
     */
    public static function configure(array $config): void
    {
        self::$priority_config = array_merge(self::$priority_config, $config);
    }

    /**
     * Get priority statistics for a batch.
     */
    public static function getPriorityStats(array $item_priorities): array
    {
        return self::calculatePriorityStats($item_priorities, 0);
    }
}