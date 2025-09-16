<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Shortcode for status display from snippet 5
function job_import_shortcode_status($atts) {
    $atts = shortcode_atts(['show_count' => true], $atts);
    $count = wp_count_posts('job')->publish;
    $last_run = get_option('job_import_last_run', 0);
    $status = time() - $last_run < 3600 ? 'Recent' : 'Overdue';

    $output = '<div class="job-import-status">';
    $output .= '<p>Total Jobs: ' . $count . '</p>';
    if ($atts['show_count']) {
        $output .= '<p>Last Import: ' . date('Y-m-d H:i', $last_run) . ' (' . $status . ')</p>';
    }
    $output .= '</div>';

    return $output;
}
?>
