<?php
/**
 * AJAX handlers for job import plugin
 *
 * @package    Puntwork
 * @subpackage AJAX
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main AJAX handlers file
 * Includes all AJAX handler modules for better organization
 */

// Include import control handlers
require_once __DIR__ . '/ajax-import-control.php';

// Include feed processing handlers
require_once __DIR__ . '/ajax-feed-processing.php';

// Include purge handlers
require_once __DIR__ . '/ajax-purge.php';

// Diagnostics settings (save REST token)
require_once __DIR__ . '/ajax-diagnostics-settings.php';

// Include scheduling handlers
require_once __DIR__ . '/../scheduling/scheduling-ajax.php';
