//
//  ajax.php
//  
//
//  Created by Dimitri Gulla on 09/09/2025.
//

<?php
if (!defined('ABSPATH')) {
    exit;
}

// AJAX handlers.
add_action('wp_ajax_run_job_import_batch', 'run_job_import_batch_ajax');
function run_job_import_batch_ajax() {
    // Paste code from snippet 4.
    // Ensure check_ajax_referer and current_user_can.
}

// Add others: cancel_job_import_ajax, clear_import_cancel_ajax, get_job_import_status_ajax, job_import_purge_ajax, process_feed_ajax, combine_jsonl_ajax, generate_json_ajax.
