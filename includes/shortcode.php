<?php
/**
 * Shortcode definitions for job import plugin
 *
 * @package    Puntwork
 * @subpackage Frontend
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_shortcode('job_update_status', function($atts, $content, $tag) {
    global $post;
    if ($post->post_modified > $post->post_date) {
        return '<span class="updated-badge">Updated ' . human_time_diff(strtotime($post->post_modified)) . ' ago</span>';
    }
    return '';
});
