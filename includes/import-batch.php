<?php
/**
 * Batch import processing
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
 * Main import batch processing file
 * Includes all import-related modules and provides the main import function
 */

// Include batch size management
require_once plugin_dir_path(__FILE__) . 'batch-size-management.php';

// Include import setup
require_once plugin_dir_path(__FILE__) . 'import-setup.php';

// Include batch processing
require_once plugin_dir_path(__FILE__) . 'batch-processing.php';

// Include import finalization
require_once plugin_dir_path(__FILE__) . 'import-finalization.php';

if (!function_exists('import_jobs_from_json')) {
    /**
     * Import jobs from JSONL file in batches.
     *
     * @param bool $is_batch Whether this is a batch import.
     * @param int $batch_start Starting index for batch.
     * @return array Import result data.
     */
    function import_jobs_from_json($is_batch = false, $batch_start = 0) {
        $setup = prepare_import_setup($batch_start);
        if (is_wp_error($setup)) {
            return ['success' => false, 'message' => $setup->get_error_message()];
        }
        if (isset($setup['success'])) {
            return $setup; // Early return for empty or completed cases
        }

        $result = process_batch_items_logic($setup);
        return finalize_batch_import($result);
    }
}
