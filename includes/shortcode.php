<?php
// includes/shortcode.php
// Shortcode for status display. Added esc_html for output safety.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Job import shortcode: Show count and status.
 * @param array $atts Shortcode attributes.
 * @return string HTML output.
 */
function job_import_shortcode( $atts = [] ) {
    $atts = shortcode_atts( [
        'show_count' => true,
        'show_status' => true,
    ], $atts, 'job_import' );

    $count = (int) wp_count_posts( 'job' )->publish;
    $last_run = (int) get_option( 'job_import_last_run', 0 );
    $status = ( time() - $last_run < 3600 ) ? 'Recent' : 'Overdue';

    $output = '<div class="job-import-status">';
    if ( $atts['show_count'] ) {
        $output .= '<p>Total Jobs: ' . esc_html( $count ) . '</p>';
    }
    if ( $atts['show_status'] ) {
        $output .= '<p>Last Import: ' . esc_html( date( 'Y-m-d H:i', $last_run ) ) . ' (' . esc_html( $status ) . ')</p>';
    }
    $output .= '</div>';

    return $output;
}

add_shortcode( 'job_import', 'job_import_shortcode' );
?>
