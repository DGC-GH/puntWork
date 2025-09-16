<?php
// includes/helpers.php
// Utility functions. Added dir checks for logging, sanitization.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Log message with dir creation and locking. (Alias for job_import_log)
 */
function log_message( $message ) {
    $logs_dir = plugin_dir_path( __FILE__ ) . '../../logs/'; // Relative to includes
    if ( ! file_exists( $logs_dir ) ) {
        wp_mkdir_p( $logs_dir );
    }
    $log = date( 'Y-m-d H:i:s' ) . ' - ' . $message . PHP_EOL;
    file_put_contents( JOB_LOG_FILE, $log, FILE_APPEND | LOCK_EX );
}

// Alias for consistency with processor
function job_import_log( $message, $level = 'info' ) {
    log_message( strtoupper($level) . ': ' . $message );
}

/**
 * Sanitize job data array.
 */
function sanitize_job_data( $data ) {
    if ( ! is_array( $data ) ) {
        return [];
    }
    return array_map( 'sanitize_text_field', $data );
}
?>
