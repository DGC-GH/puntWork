<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

namespace Puntwork;

/**
 * Main mappings and constants file
 * Includes all mapping modules for better organization
 */

// Include geographic mappings
require_once plugin_dir_path(__FILE__) . 'mappings-geographic.php';

// Include salary mappings
require_once plugin_dir_path(__FILE__) . 'mappings-salary.php';

// Include icon mappings
require_once plugin_dir_path(__FILE__) . 'mappings-icons.php';

// Include field mappings
require_once plugin_dir_path(__FILE__) . 'mappings-fields.php';

// Include schema mappings
require_once plugin_dir_path(__FILE__) . 'mappings-schema.php';

// Admin script deregistration
add_action('admin_enqueue_scripts', function($hook) {
    if ($hook === 'job_page_job-import-dashboard') {
        wp_deregister_script('heartbeat');
    }
});
