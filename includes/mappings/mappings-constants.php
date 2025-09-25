<?php
/**
 * Mapping constants and definitions
 *
 * @package    Puntwork
 * @subpackage Mappings
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main mappings and constants file
 * Includes all mapping modules for better organization
 */

// Include geographic mappings
require_once __DIR__ . '/mappings-geographic.php';

// Include salary mappings
require_once __DIR__ . '/mappings-salary.php';

// Include icon mappings
require_once __DIR__ . '/mappings-icons.php';

// Include field mappings
require_once __DIR__ . '/mappings-fields.php';

// Include schema mappings
require_once __DIR__ . '/mappings-schema.php';

// Admin script deregistration
add_action('admin_enqueue_scripts', function($hook) {
    if ($hook === 'puntwork-dashboard_page_job-feed-dashboard') {
        wp_deregister_script('heartbeat');
    }
});
