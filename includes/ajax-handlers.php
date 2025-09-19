<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

namespace Puntwork;

/**
 * Main AJAX handlers file
 * Includes all AJAX handler modules for better organization
 */

// Include import control handlers
require_once plugin_dir_path(__FILE__) . 'ajax-import-control.php';

// Include feed processing handlers
require_once plugin_dir_path(__FILE__) . 'ajax-feed-processing.php';

// Include purge handlers
require_once plugin_dir_path(__FILE__) . 'ajax-purge.php';
