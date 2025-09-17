<?php
/**
 * Job Import Constants
 * Centralized config from various snippets
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'JOB_IMPORT_VERSION', '1.0.0' );
define( 'JOB_IMPORT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) . '../' ); // Relative to includes
define( 'JOB_FEED_URL', 'https://vdab.be/jobs.xml' ); // Updated to real example (VDAB); add Actiris etc.
define( 'JOB_LOG_FILE', JOB_IMPORT_PLUGIN_DIR . 'logs/import.log' ); // Standardized name
define( 'JOB_BATCH_SIZE', 50 ); // Standardized from JOB_MAX_BATCH_SIZE
define( 'JOB_DEFAULT_LANG', 'nl' ); // Fallback language
define( 'JOB_FALLBACK_DOMAIN', 'vlaanderen' ); // Default province domain
define( 'JOB_POST_TYPE', 'job' ); // Added as needed
// Add more constants as needed (e.g., API keys)
?>
