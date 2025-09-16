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
define( 'JOB_FEED_URL', 'https://example.com/jobs.xml' ); // From snippet 1; update to real VDAB/Actiris feed
define( 'JOB_LOG_FILE', JOB_IMPORT_PLUGIN_DIR . 'logs/job-import.log' );
define( 'JOB_MAX_BATCH_SIZE', 50 ); // From snippet 2.5
define( 'JOB_DEFAULT_LANG', 'nl' ); // Fallback language
define( 'JOB_FALLBACK_DOMAIN', 'vlaanderen' ); // Default province domain
// Add more constants as needed (e.g., API keys, post types: define('JOB_POST_TYPE', 'job_post'); )
