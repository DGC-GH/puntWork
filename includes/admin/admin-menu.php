<?php
/**
 * Admin menu setup for job import plugin
 *
 * @package    Puntwork
 * @subpackage Admin
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action('admin_menu', function() {
    add_submenu_page(
        'edit.php?post_type=job',
        'Job Import Dashboard',
        'Import Jobs',
        'manage_options',
        'job-import-dashboard',
        __NAMESPACE__ . '\\job_import_admin_page',
        1
    );
});
