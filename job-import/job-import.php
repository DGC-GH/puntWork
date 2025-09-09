//
//  job-import.php
//  
//
//  Created by Dimitri Gulla on 09/09/2025.
//

<?php
/**
 * Plugin Name: Job Import
 * Plugin URI: https://your-site.com/job-import
 * Description: Handles job imports from XML feeds, replacing WPCode snippets.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://your-site.com
 * License: GPL-2.0+
 * Text Domain: job-import
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Define constants.
define('JOB_IMPORT_PATH', plugin_dir_path(__FILE__));
define('JOB_IMPORT_URL', plugin_dir_url(__FILE__));
define('JOB_IMPORT_LOG_DIR', JOB_IMPORT_PATH . 'logs/');

// Include files.
require_once JOB_IMPORT_PATH . 'includes/core.php';
require_once JOB_IMPORT_PATH . 'includes/admin.php';
require_once JOB_IMPORT_PATH . 'includes/ajax.php';

// Initialize the plugin (e.g., add hooks here if needed globally).
add_action('plugins_loaded', function() {
    // Ensure logs dir exists and is writable.
    if (!is_dir(JOB_IMPORT_LOG_DIR)) {
        wp_mkdir_p(JOB_IMPORT_LOG_DIR);
    }
});
