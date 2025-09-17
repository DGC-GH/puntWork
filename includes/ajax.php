<?php
/**
 * AJAX handlers for Job Import plugin.
 *
 * @package puntWork
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Enqueue if needed, but typically loaded via enqueue.php

/**
 * Handle manual import AJAX request.
 * Imports from all published job-feed CPTs using their feed-url meta.
 */
function handle_manual_import() {
    error_log('=== Job Import Debug: Manual import AJAX initiated ===');
    
    // Security check
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'job_import_nonce')) {
        error_log('=== Job Import Debug: Nonce verification FAILED ===');
        wp_die('Security check failed');
    }
    error_log('=== Job Import Debug: Nonce verified successfully ===');
    
    // Query all published job-feeds with non-empty feed-url
    $feeds = get_posts([
        'post_type'      => 'job-feed',
        'post_status'    => 'publish',
        'numberposts'    => -1,
        'meta_query'     => [
            [
                'key'     => 'feed-url',
                'value'   => '',
                'compare' => '!='
            ]
        ]
    ]);
    
    error_log('=== Job Import Debug: Found ' . count($feeds) . ' eligible feeds ===');
    
    if (empty($feeds)) {
        error_log('=== Job Import Debug: No feeds to process ===');
        wp_send_json_error('No configured feeds found.');
    }
    
    $results = [];
    foreach ($feeds as $feed) {
        $feed_id = $feed->ID;
        $url = get_post_meta($feed_id, 'feed-url', true);
        
        error_log("=== Job Import Debug: Processing feed ID {$feed_id} from URL: {$url} ===");
        
        // Call the import processor (assumes function exists in processor.php or core.php)
        // Add more granular logs inside import_jobs_from_feed() if needed
        $result = import_jobs_from_feed($url, $feed_id);  // Replace with actual function name
        
        $results[] = [
            'feed_id' => $feed_id,
            'url'     => $url,
            'result'  => $result
        ];
        
        error_log("=== Job Import Debug: Completed feed ID {$feed_id}, result: " . print_r($result, true) . " ===");
    }
    
    error_log('=== Job Import Debug: All feeds processed successfully ===');
    
    wp_send_json_success([
        'message' => 'Manual import completed for ' . count($feeds) . ' feeds.',
        'results' => $results
    ]);
}
add_action('wp_ajax_manual_import', 'handle_manual_import');
