<?php
/**
 * Timeout Protection and Background Processing Fixes
 *
 * This file documents the timeout protection fixes implemented to prevent
 * WordPress cron jobs from being automatically stopped due to execution time limits.
 *
 * @package    Puntwork
 * @subpackage Documentation
 * @since      1.0.1
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * TIMEOUT PROTECTION FIXES
 *
 * Problem: WordPress scheduled imports were being automatically stopped when
 * they exceeded PHP execution time limits (typically 30-60 seconds on shared hosting).
 *
 * Solution: Implemented timeout protection similar to WooCommerce's background
 * processing system with the following components:
 *
 * 1. Time Limit Checking (20 seconds default)
 *    - import_time_exceeded() - checks if current batch exceeds time limit
 *    - import_memory_exceeded() - checks if memory usage exceeds 90% of limit
 *    - should_continue_streaming_processing() - combines time/memory checks
 *
 * 2. Background Continuation
 *    - Imports pause when time limits exceeded
 *    - WordPress cron schedules continuation in background
 *    - Import status shows "paused" state to users
 *
 * 3. Health Check System
 *    - Monitors for stuck imports (running > 10 minutes)
 *    - Automatically resets stuck import status
 *    - Continues paused imports after 5 minutes
 *
 * 4. Cron Integration
 *    - Scheduled imports check for paused processes
 *    - Automatically continue paused imports
 *    - Prevents duplicate concurrent imports
 *
 * RECOMMENDED: SYSTEM CRON SETUP
 *
 * For production sites, replace WP-Cron with system cron for reliability:
 *
 * 1. Disable WP-Cron in wp-config.php:
 *    define('DISABLE_WP_CRON', true);
 *
 * 2. Add system cron job (crontab -e):
 *    # Run WP-Cron every 5 minutes
 *    *#/#5 * * * * curl -s https://yoursite.com/wp-cron.php > /dev/null 2>&1
 *
 * This ensures cron jobs run reliably every 5 minutes regardless of traffic.
 *
 * FILTERS AVAILABLE:
 * - puntwork_import_time_limit: Change default 20-second time limit
 * - puntwork_import_time_exceeded: Override time exceeded logic
 * - puntwork_import_memory_exceeded: Override memory exceeded logic
 */
