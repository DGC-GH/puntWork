<?php
/**
 * Job Import Core Logic
 * Consolidated from snippets 1, 1.2, 1.3, 1.4/1.5, 1.6, 1.7
 * Includes reference to mappings.php for data separation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Include mappings for efficiency
require_once JOB_IMPORT_PATH . 'includes/mappings.php';

// =========================================================================== SNIPPET 1.2: Utility Helpers ===========================================================================
function log_message( $message ) {
    $log = date( 'Y-m-d H:i:s' ) . ' - ' . $message . PHP_EOL;
    file_put_contents( JOB_IMPORT_LOGS, $log, FILE_APPEND | LOCK_EX );
}

function sanitize_job_data( $data ) {
    return array_map( 'sanitize_text_field', $data );
}

// =========================================================================== SNIPPET 1: Core Structure and Logic ===========================================================================
function get_feeds() {
    $feeds = [];
    $args = [
        'post_type' => 'job-feed',
        'post_status' => 'publish',
        'posts_per_page' => -1,
    ];
    $query = new WP_Query($args);
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $feed_key = get_post_field('post_name', get_the_ID());
            $url = get_field('feed-url', get_the_ID());
            $url = trim($url);
            if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
                $feeds[$feed_key] = $url;
            }
        }
        wp_reset_postdata();
    }
    return $feeds;
}

function process_one_feed($feed_key, $url, $output_dir, $fallback_domain, &$logs) {
    $xml_path = $output_dir . $feed_key . '.xml';
    $json_filename = $feed_key . '.json';
    $json_path = $output_dir . $json_filename;
    $gz_json_path = $json_path . '.gz';

    if (!download_feed($url, $xml_path, $output_dir, $logs)) {
        return 0;
    }

    $handle = fopen($json_path, 'w');
    if (!$handle) {
        log_message("Can't open $json_path");
        throw new Exception("Can't open $json_path");
    }
    $batch_size = 100;
    $total_items = 0;
    $count = process_xml_batch($xml_path, $handle, $feed_key, $output_dir, $fallback_domain, $batch_size, $total_items, $logs);
    fclose($handle);
    @chmod($json_path, 0644);

    gzip_file($json_path, $gz_json_path);
    return $count;
}

function job_import_run() {
    global $import_logs;
    $import_logs = [];
    ini_set('memory_limit', '512M');
    set_time_limit(1800);
    $feeds = get_feeds();
    $output_dir = JOB_IMPORT_PATH . 'feeds/';
    if (!wp_mkdir_p($output_dir) || !is_writable($output_dir)) {
        log_message("Directory $output_dir not writable");
        throw new Exception('Feeds directory not writable - check Hosting permissions');
    }
    $fallback_domain = 'belgiumjobs.work';

    $total_items = 0;
    libxml_use_internal_errors(true);

    foreach ($feeds as $feed_key => $url) {
        $count = process_one_feed($feed_key, $url, $output_dir, $fallback_domain, $import_logs);
        $total_items += $count;
    }

    combine_json_files($feeds, $output_dir, $total_items, $import_logs);
    return $import_logs;
}

// =========================================================================== SNIPPET 1.3: Scheduling and Triggers ===========================================================================
add_action( 'job_import_cron', 'job_import_run' );

// Manual trigger.
function trigger_import() {
    if ( ! wp_next_scheduled( 'job_import_cron' ) ) {
        wp_schedule_single_event( time(), 'job_import_cron' );
    }
}

// =========================================================================== SNIPPET 1.4/1.5: Heartbeat Control ===========================================================================
add_action( 'wp_ajax_heartbeat_control', 'job_heartbeat_handler' );

function job_heartbeat_handler() {
    wp_die( 'Heartbeat controlled' ); // Placeholder for real-time updates; expand in heartbeat.php
}

// =========================================================================== SNIPPET 1.6: Item Cleaning ===========================================================================
function clean_item( $item ) {
    $item->title = strip_tags( (string) $item->title );
    $item->description = wp_strip_all_tags( (string) $item->description );
    return $item;
}

// =========================================================================== SNIPPET 1.7: Item Inference ===========================================================================
function infer_item_details( &$item, $fallback_domain, $lang, &$job_obj ) {
    $province = strtolower( trim( isset( $item->province ) ? (string) $item->province : '' ) );
    $norm_province = get_province_map()[$province] ?? $fallback_domain;

    $title = isset( $item->functiontitle ) ? (string) $item->functiontitle : '';
    $enhanced_title = $title;
    if ( isset( $item->city ) ) $enhanced_title .= ' in ' . (string) $item->city;
    if ( isset( $item->province ) ) $enhanced_title .= ', ' . (string) $item->province;
    $enhanced_title = trim( $enhanced_title );
    $slug = sanitize_title( $enhanced_title . '-' . (string) $item->guid );
    $job_link = 'https://' . $norm_province . '/job/' . $slug;

    $fg = strtolower( trim( isset( $item->functiongroup ) ? (string) $item->functiongroup : '' ) );
    $estimate_key = array_reduce( array_keys( get_salary_estimates() ), function( $carry, $key ) use ( $fg ) {
        return strpos( $fg, strtolower( $key ) ) !== false ? $key : $carry;
    }, null );

    $salary_text = '';
    if ( isset( $item->salaryfrom ) && $item->salaryfrom != '0' && isset( $item->salaryto ) && $item->salaryto != '0' ) {
        $salary_text = '€' . (string) $item->salaryfrom . ' - €' . (string) $item->salaryto;
    } elseif ( isset( $item->salaryfrom ) && $item->salaryfrom != '0' ) {
        $salary_text = '€' . (string) $item->salaryfrom;
    } else {
        $est_prefix = ( $lang == 'nl' ? 'Geschat ' : ( $lang == 'fr' ? 'Estimé ' : 'Est. ' ) );
        if ( $estimate_key ) {
            $low = get_salary_estimates()[$estimate_key]['low'];
            $high = get_salary_estimates()[$estimate_key]['high'];
            $salary_text = $est_prefix . '€' . $low . ' - €' . $high;
        }
    }

    // Assign to job_obj (assuming this is used in processing)
    $job_obj['enhanced_title'] = $enhanced_title;
    $job_obj['slug'] = $slug;
    $job_obj['job_link'] = $job_link;
    $job_obj['salary_text'] = $salary_text;
}
