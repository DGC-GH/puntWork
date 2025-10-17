<?php
/**
 * Streaming import processing - single-item processing without batching
 *
 * @package    Puntwork
 * @subpackage Import
 * @since      1.1.1
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/../utilities/options-utilities.php';

/**
 * Main import file for streaming single-item processing
 * Processes items one by one from feeds without batching or concurrency
 */

// Include import setup
require_once __DIR__ . '/import-setup.php';

// Include import finalization
require_once __DIR__ . '/import-finalization.php';

// Include logger
require_once __DIR__ . '/../utilities/puntwork-logger.php';

// Include core structure logic for feed processing
require_once __DIR__ . '/../core/core-structure-logic.php';

// Include streaming architecture - this is the only processing method now
require_once __DIR__ . '/import-streaming.php';

/**
 * Check if the current import process has exceeded time limits
 * Similar to WooCommerce's time_exceeded() method
 *
 * @return bool True if time limit exceeded
 */
function import_time_exceeded() {
    $start_time = get_import_start_time(microtime(true));
    // Plugin soft time limit (used for performance tuning)
    $time_limit = apply_filters('puntwork_import_time_limit', 120); // default 120s
    // Safe run threshold to pre-empt host kills (seconds)
    if (!defined('PUNTWORK_SAFE_RUN_SECONDS')) {
        define('PUNTWORK_SAFE_RUN_SECONDS', 60);
    }
    $safe_threshold = apply_filters('puntwork_safe_run_seconds', PUNTWORK_SAFE_RUN_SECONDS);
    $current_time = microtime(true);

    $elapsed = $current_time - $start_time;

    // If we've exceeded the plugin's soft time limit, treat as exceeded
    if ($elapsed >= $time_limit) {
        error_log('[PUNTWORK] TIME EXCEEDED: elapsed ' . $elapsed . ' >= limit ' . $time_limit);
        return true;
    }

    // If we've hit the safe threshold (pre-emptive pause), treat as exceeded to pause gracefully
    if ($elapsed >= $safe_threshold) {
        error_log('[PUNTWORK] SAFE THRESHOLD REACHED: elapsed ' . $elapsed . ' >= safe_threshold ' . $safe_threshold);
        return true;
    }

    // Only log OK if debugging
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[PUNTWORK] TIME OK: elapsed ' . $elapsed . ' < limit ' . $time_limit);
    }
    return apply_filters('puntwork_import_time_exceeded', false);
}

/**
 * Check if the current import process has exceeded memory limits
 * Similar to WooCommerce's memory_exceeded() method
 *
 * @return bool True if memory limit exceeded
 */
function import_memory_exceeded() {
    $memory_limit = get_memory_limit_bytes() * 0.9; // 90% of max memory
    $current_memory = memory_get_usage(true);

    if ($current_memory >= $memory_limit) {
        error_log('[PUNTWORK] MEMORY EXCEEDED: ' . round($current_memory / 1024 / 1024, 1) . ' MB >= ' . round($memory_limit / 1024 / 1024, 1) . ' MB');
        return true;
    }

    // Only log OK if debugging
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[PUNTWORK] MEMORY OK: ' . round($current_memory / 1024 / 1024, 1) . ' MB < ' . round($memory_limit / 1024 / 1024, 1) . ' MB');
    }
    return apply_filters('puntwork_import_memory_exceeded', false);
}

/**
 * Check if batch processing should continue
 * Returns false if time or memory limits exceeded
 *
 * @return bool True if processing should continue
 */
function should_continue_batch_processing() {
    error_log('[PUNTWORK] Checking import time limit...');
        if (import_time_exceeded()) {
        error_log('[PUNTWORK] Import time limit exceeded - pausing batch processing');
        return false;
    }

    error_log('[PUNTWORK] Checking import memory limit...');
    if (import_memory_exceeded()) {
        error_log('[PUNTWORK] Import memory limit exceeded - pausing batch processing');
        return false;
    }

    error_log('[PUNTWORK] Continue processing checks passed');
    return true;
}

if (!function_exists('import_jobs_from_json')) {
    /**
     * Import jobs from JSONL file - routes to streaming architecture
     *
     * @param bool $is_batch Whether this is a batch import.
     * @param int $batch_start Starting index for batch.
     * @return array Import result data.
     */
    function import_jobs_from_json($is_batch = false, $batch_start = 0) {
        PuntWorkLogger::info('Routing import to streaming architecture', PuntWorkLogger::CONTEXT_IMPORT, [
            'streaming_only' => true,
            'batch_start' => $batch_start
        ]);

        // Route directly to streaming architecture
        return import_jobs_streaming($batch_start > 0); // preserve_status for continuation
    }
}

if (!function_exists('import_all_jobs_from_json')) {
    /**
     * Import all jobs from JSONL file using streaming architecture
     * Processes items one by one without batching
     *
     * @param bool $preserve_status Whether to preserve existing import status for UI polling
     * @return array Import result data.
     */
    function import_all_jobs_from_json($preserve_status = false) {

        PuntWorkLogger::info('Starting streaming import (no batching)', PuntWorkLogger::CONTEXT_IMPORT, [
            'preserve_status' => $preserve_status,
            'processing' => 'single_item_streaming'
        ]);

        // Stream import handles all the logic - just call it
        return import_jobs_streaming($preserve_status);
    }
}

/**
 * Simple continuation for paused streaming imports
 * Used when timeout/memory limits pause the import
 */
function continue_paused_import() {
    // Continue the streaming import
    $result = import_all_jobs_from_json(true); // preserve status for continuation

    return $result;
}

// Register the continuation hook
add_action('puntwork_continue_import', __NAMESPACE__ . '\\continue_paused_import');
