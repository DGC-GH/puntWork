<?php
/**
 * Exporter for Job Import Plugin
 * Handles JSONL export of imported jobs.
 * Ported from old WPCode snippet 2.2 - Combine JSONL.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Export all published jobs to JSONL file.
 */
function export_all_jobs_to_jsonl() {
    $jobs = get_posts( array(
        'post_type' => 'job',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
    ) );

    if ( empty( $jobs ) ) {
        log_import_event( 'No jobs to export to JSONL.', 'info' );
        return;
    }

    $output_path = JOB_IMPORT_PATH . 'exports/jobs.jsonl';
    $exports_dir = dirname( $output_path );
    if ( ! file_exists( $exports_dir ) ) {
        wp_mkdir_p( $exports_dir );
    }

    $handle = fopen( $output_path, 'w' );
    if ( ! $handle ) {
        log_import_event( 'Failed to open JSONL export file.', 'error' );
        return;
    }

    foreach ( $jobs as $job_id ) {
        $job_data = array(
            'id' => $job_id,
            'title' => get_the_title( $job_id ),
            'meta' => get_post_meta( $job_id ), // All inferred fields
            'source_feed' => get_post_meta( $job_id, '_source_feed', true ),
        );
        fwrite( $handle, json_encode( $job_data ) . PHP_EOL );
    }

    fclose( $handle );
    log_import_event( "Exported " . count( $jobs ) . " jobs to JSONL: " . $output_path, 'info' );
}
