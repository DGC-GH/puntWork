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
require_once JOB_IMPORT_PLUGIN_DIR . 'includes/mappings.php';

// ==================== SNIPPET 1.2: Utility Helpers ====================
function log_message( $message ) {
    $log = date( 'Y-m-d H:i:s' ) . ' - ' . $message . PHP_EOL;
    file_put_contents( JOB_LOG_FILE, $log, FILE_APPEND | LOCK_EX );
}

function sanitize_job_data( $data ) {
    return array_map( 'sanitize_text_field', $data );
}

// ==================== SNIPPET 1: Core Structure and Logic ====================
function job_import_run() {
    $feed = download_feed( JOB_FEED_URL ); // From snippet 1.8, impl in processor.php
    if ( $feed ) {
        $xml = process_xml_batch( $feed ); // From snippet 1.9, impl in processor.php
        import_batch( $xml ); // From snippet 2.3, impl in admin.php or processor
    }
}

// ==================== SNIPPET 1.3: Scheduling and Triggers ====================
add_action( 'job_import_cron', 'job_import_run' );

// Manual trigger.
function trigger_import() {
    if ( ! wp_next_scheduled( 'job_import_cron' ) ) {
        wp_schedule_single_event( time(), 'job_import_cron' );
    }
}

// ==================== SNIPPET 1.4/1.5: Heartbeat Control ====================
add_action( 'wp_ajax_heartbeat_control', 'job_heartbeat_handler' );

function job_heartbeat_handler() {
    wp_die( 'Heartbeat controlled' ); // Placeholder for real-time updates; expand in heartbeat.php
}

// ==================== SNIPPET 1.6: Item Cleaning ====================
function clean_item( $item ) {
    $item->title = strip_tags( (string) $item->title );
    $item->description = wp_strip_all_tags( (string) $item->description );
    return $item;
}

// ==================== SNIPPET 1.7: Item Inference ====================
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
        } else {
            $salary_text = '€3000 - €4500'; // Default fallback
        }
    }

    $apply_link = isset( $item->applylink ) ? (string) $item->applylink : '';

    // Complete assignments to job_obj (from snippet logic)
    $job_obj->title = $enhanced_title;
    $job_obj->link = $job_link;
    $job_obj->salary = $salary_text;
    if ( $apply_link ) {
        $job_obj->apply_link = $apply_link;
    }
    $job_obj->icon_class = get_icon_map()[$estimate_key] ?? 'default-job-icon'; // Use estimate_key or fallback
    $item->province = $norm_province; // Update item for consistency

    log_message( "Inferred details for job: " . $enhanced_title );
}

// Initialize core on plugin load
add_action( 'plugins_loaded', 'job_import_init_core' );
function job_import_init_core() {
    // Any init logic here, e.g., schedule cron if not set
    if ( ! wp_next_scheduled( 'job_import_cron' ) ) {
        wp_schedule_event( time(), 'daily', 'job_import_cron' ); // Default daily
    }
}
