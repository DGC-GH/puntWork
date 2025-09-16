<?php
// includes/core.php
// Core logic: Mappings, inference, cleaning. Added error handling, filters for extensibility.
// Removed unused snippet comments for cleanliness.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Province mapping with filter for custom overrides.
 */
function get_province_map() {
    $map = [
        'vlaanderen' => 'vlaanderen',
        'brussels' => 'brussels',
        'wallonie' => 'wallonie',
        // Add more mappings.
    ];
    return apply_filters( 'job_import_province_map', $map );
}

/**
 * Salary estimates with filter.
 */
function get_salary_estimates() {
    $estimates = [
        'developer' => [ 'low' => 3500, 'high' => 5500 ],
        'admin' => [ 'low' => 2500, 'high' => 4000 ],
        // Add more.
    ];
    return apply_filters( 'job_import_salary_estimates', $estimates );
}

/**
 * Icon map.
 */
function get_icon_map() {
    return [
        'developer' => 'code-icon',
        // Add more.
    ];
}

/**
 * Main import runner with try-catch.
 */
function job_import_run() {
    try {
        $feed = download_feed( get_option( 'job_feed_url', JOB_FEED_URL ) );
        if ( $feed ) {
            $xml = process_xml_batch( $feed );
            import_batch( $xml );
            update_option( 'job_import_last_run', time() );
        }
    } catch ( Exception $e ) {
        log_message( 'Import Run Error: ' . $e->getMessage() );
        // Optional: Send admin email.
        wp_mail( get_option( 'admin_email' ), 'Job Import Failed', $e->getMessage() );
    }
}

/**
 * Clean item data safely.
 */
function clean_item( $item ) {
    if ( ! is_object( $item ) ) {
        return null; // Early return for invalid items.
    }
    $item->title = isset( $item->title ) ? strip_tags( (string) $item->title ) : '';
    $item->description = isset( $item->description ) ? wp_strip_all_tags( (string) $item->description ) : '';
    return $item;
}

/**
 * Infer details with fallbacks and error logging.
 */
function infer_item_details( &$item, $fallback_domain, $lang, &$job_obj ) {
    if ( ! is_object( $item ) ) {
        log_message( 'Infer: Invalid item object.' );
        return;
    }

    $province = isset( $item->province ) ? strtolower( trim( (string) $item->province ) ) : '';
    $norm_province = get_province_map()[ $province ] ?? $fallback_domain;

    $title = isset( $item->functiontitle ) ? (string) $item->functiontitle : '';
    $enhanced_title = $title;
    if ( isset( $item->city ) ) {
        $enhanced_title .= ' in ' . (string) $item->city;
    }
    if ( isset( $item->province ) ) {
        $enhanced_title .= ', ' . (string) $item->province;
    }
    $enhanced_title = trim( $enhanced_title );
    $slug = sanitize_title( $enhanced_title . '-' . ( (string) $item->guid ?? uniqid() ) ); // Fallback for missing GUID.
    $job_link = 'https://' . $norm_province . '/job/' . $slug;

    $fg = isset( $item->functiongroup ) ? strtolower( trim( (string) $item->functiongroup ) ) : '';
    $estimate_key = null;
    foreach ( array_keys( get_salary_estimates() ) as $key ) {
        if ( strpos( $fg, strtolower( $key ) ) !== false ) {
            $estimate_key = $key;
            break;
        }
    }

    $salary_text = '';
    if ( isset( $item->salaryfrom, $item->salaryto ) && $item->salaryfrom != '0' && $item->salaryto != '0' ) {
        $salary_text = '€' . (string) $item->salaryfrom . ' - €' . (string) $item->salaryto;
    } elseif ( isset( $item->salaryfrom ) && $item->salaryfrom != '0' ) {
        $salary_text = '€' . (string) $item->salaryfrom;
    } else {
        $est_prefix = ( $lang == 'nl' ? 'Geschat ' : ( $lang == 'fr' ? 'Estimé ' : 'Est. ' ) );
        if ( $estimate_key ) {
            $low = get_salary_estimates()[ $estimate_key ]['low'];
            $high = get_salary_estimates()[ $estimate_key ]['high'];
            $salary_text = $est_prefix . '€' . $low . ' - €' . $high;
        } else {
            $salary_text = '€3000 - €4500'; // Default fallback.
        }
    }

    $apply_link = isset( $item->applylink ) ? esc_url( (string) $item->applylink ) : '';

    // Apply to job_obj for use in import.
    $job_obj['link'] = $job_link;
    $job_obj['apply_link'] = $apply_link;
    $job_obj['salary_text'] = $salary_text;
    // Add more inferences as needed.
}

// Hook the cron.
add_action( 'job_import_cron', 'job_import_run' );
?>
