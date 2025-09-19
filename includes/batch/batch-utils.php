<?php
/**
 * Batch processing utility functions
 * Contains utility functions for batch operations
 *
 * @package    Puntwork
 * @subpackage Batch
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Load a batch of items from JSONL file.
 *
 * @param string $json_path Path to JSONL file.
 * @param int $start_index Starting index.
 * @param int $batch_size Batch size.
 * @return array Array of JSON items.
 */
function load_json_batch($json_path, $start_index, $batch_size) {
    $items = [];
    $count = 0;
    $current_index = 0;

    if (($handle = fopen($json_path, "r")) !== false) {
        while (($line = fgets($handle)) !== false) {
            if ($current_index >= $start_index && $count < $batch_size) {
                $line = trim($line);
                if (!empty($line)) {
                    $item = json_decode($line, true);
                    if ($item !== null) {
                        $items[] = $item;
                        $count++;
                    }
                }
            } elseif ($current_index >= $start_index + $batch_size) {
                break;
            }
            $current_index++;
        }
        fclose($handle);
    }

    return $items;
}