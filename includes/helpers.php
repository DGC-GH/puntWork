<?php
/**
 * Helper functions for Job Import Plugin
 * Based on old WPCode snippets utilities (1.2, 1.6, 1.7).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/mappings.php'; // For maps

/**
 * Get all published job-feed URLs from ACF CPT.
 *
 * @return array Array of feed URLs, keyed by post ID for logging.
 */
function get_job_feed_urls() {
    if ( ! class_exists( 'ACF' ) ) {
        log_import_event( 'ACF not active, skipping feed fetch.', 'error' );
        return array();
    }

    $feeds = array();
    $query = new WP_Query( array(
        'post_type'      => 'job-feed',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ) );

    if ( $query->have_posts() ) {
        foreach ( $query->posts as $post_id ) {
            $feed_url = get_field( 'feed_url', $post_id ); // ACF field
            if ( ! empty( $feed_url ) && filter_var( $feed_url, FILTER_VALIDATE_URL ) ) {
                $feeds[ $post_id ] = $feed_url;
            } else {
                log_import_event( "Invalid or missing feed_url for job-feed post ID {$post_id}", 'warn' );
            }
        }
    }

    wp_reset_postdata();
    return $feeds;
}

/**
 * Get last run timestamp for a specific feed (stored as post meta).
 *
 * @param int $post_id Job-feed post ID.
 * @return string Timestamp or empty.
 */
function get_feed_last_run( $post_id ) {
    return get_post_meta( $post_id, '_feed_last_run', true );
}

/**
 * Update last run timestamp for a feed.
 *
 * @param int $post_id Job-feed post ID.
 * @return bool True on success.
 */
function update_feed_last_run( $post_id ) {
    return update_post_meta( $post_id, '_feed_last_run', current_time( 'mysql' ) );
}

/**
 * Clean item data (ported/adapted from old 1.6 - Item Cleaning.php).
 * Sanitizes and trims fields, handles common XML quirks.
 *
 * @param array $data Raw item data from XML.
 * @return array Cleaned data.
 */
function clean_item( $data ) {
    $clean = array();
    foreach ( $data as $key => $value ) {
        if ( is_array( $value ) ) {
            $clean[ $key ] = clean_item( $value ); // Recursive for nested
        } else {
            $clean[ $key ] = trim( sanitize_text_field( (string) $value ) );
        }
    }
    // Remove empty fields
    return array_filter( $clean, function( $v ) { return ! empty( $v ); } );
}

/**
 * Infer additional details for item (ported from old 1.7 - Item Inference.php).
 * Handles salary estimates, benefits regex, skills, enhanced title, etc.
 *
 * @param array $item Cleaned item data.
 * @param string $fallback_domain Default domain (e.g., 'be').
 * @param string $lang Language (e.g., 'nl', 'fr', 'en').
 * @return array Inferred data merged with original.
 */
function infer_item_details( $item, $fallback_domain = 'be', $lang = 'en' ) {
    $inferred = $item;

    $province = strtolower( trim( $item['province'] ?? '' ) );
    $norm_province = get_province_map()[ $province ] ?? $fallback_domain;

    $title = $item['functiontitle'] ?? '';
    $enhanced_title = $title;
    if ( isset( $item['city'] ) ) {
        $enhanced_title .= ' in ' . $item['city'];
    }
    if ( isset( $item['province'] ) ) {
        $enhanced_title .= ', ' . $item['province'];
    }
    $enhanced_title = trim( $enhanced_title );
    $slug = sanitize_title( $enhanced_title . '-' . ( $item['guid'] ?? '' ) );
    $inferred['job_link'] = 'https://' . $norm_province . '/job/' . $slug;

    $fg = strtolower( trim( $item['functiongroup'] ?? '' ) );
    $estimate_key = array_reduce(
        array_keys( get_salary_estimates() ),
        function( $carry, $key ) use ( $fg ) {
            return strpos( $fg, strtolower( $key ) ) !== false ? $key : $carry;
        },
        'default'
    );

    $salary_text = '';
    if ( isset( $item['salaryfrom'] ) && $item['salaryfrom'] != '0' && isset( $item['salaryto'] ) && $item['salaryto'] != '0' ) {
        $salary_text = '€' . $item['salaryfrom'] . ' - €' . $item['salaryto'];
    } elseif ( isset( $item['salaryfrom'] ) && $item['salaryfrom'] != '0' ) {
        $salary_text = '€' . $item['salaryfrom'];
    } else {
        $est_prefix = ( $lang == 'nl' ? 'Geschat ' : ( $lang == 'fr' ? 'Estimé ' : 'Est. ' ) );
        $low = get_salary_estimates()[ $estimate_key ]['low'];
        $high = get_salary_estimates()[ $estimate_key ]['high'];
        $salary_text = $est_prefix . '€' . $low . ' - €' . $high;
    }
    $inferred['salary_text'] = $salary_text;

    $apply_link = $item['applylink'] ?? '';
    if ( $apply_link ) {
        $apply_link .= '?utm_source=puntwork&utm_term=' . ( $item['guid'] ?? '' );
    }
    $inferred['apply_link'] = $apply_link;

    $icon_key = array_reduce(
        array_keys( get_icon_map() ),
        function( $carry, $key ) use ( $fg ) {
            return strpos( $fg, strtolower( $key ) ) !== false ? $key : $carry;
        },
        null
    );
    $inferred['icon'] = $icon_key ? get_icon_map()[ $icon_key ] : '';

    $all_text = strtolower( implode( ' ', [
        $item['functiontitle'] ?? '',
        $item['description'] ?? '',
        $item['functiondescription'] ?? '',
        $item['offerdescription'] ?? '',
        $item['requirementsdescription'] ?? '',
        $item['companydescription'] ?? ''
    ] ) );

    $inferred['job_car'] = (bool) preg_match( '/bedrijfs(wagen|auto)|firmawagen|voiture de société|company car/i', $all_text );
    $inferred['job_remote'] = (bool) preg_match( '/thuiswerk|télétravail|remote work|home office/i', $all_text );
    $inferred['job_meal_vouchers'] = (bool) preg_match( '/maaltijdcheques|chèques repas|meal vouchers/i', $all_text );
    $inferred['job_flex_hours'] = (bool) preg_match( '/flexibele uren|heures flexibles|flexible hours/i', $all_text );

    $inferred['job_skills'] = [];
    if ( preg_match( '/\bexcel\b|\bmicrosoft excel\b|\bms excel\b/i', $all_text ) ) {
        $inferred['job_skills'][] = 'Excel';
    }
    if ( preg_match( '/\bwinbooks\b/i', $all_text ) ) {
        $inferred['job_skills'][] = 'WinBooks';
    }

    $parttime = isset( $item['parttime'] ) && $item['parttime'] == 'true';
    $inferred['job_time'] = $parttime
        ? ( $lang == 'nl' ? 'Deeltijds' : ( $lang == 'fr' ? 'Temps partiel' : 'Part-time' ) )
        : ( $lang == 'nl' ? 'Voltijds' : ( $lang == 'fr' ? 'Temps plein' : 'Full-time' ) );

    $job_desc = ( $lang == 'nl' ? 'Vacature' : ( $lang == 'fr' ? 'Emploi' : 'Job' ) ) . ': ' . $enhanced_title . '. ';
    if ( isset( $item['functiondescription'] ) ) {
        $job_desc .= $item['functiondescription'];
    }
    $inferred['job_desc'] = $job_desc;

    return $inferred;
}

/**
 * Structured logging (ported from old 1.2 - Utility Helpers.php).
 *
 * @param string $msg Message.
 * @param string $level info|warn|error.
 */
function log_import_event( $msg, $level = 'info' ) {
    $log_entry = '[' . current_time( 'Y-m-d H:i:s' ) . '] [' . $level . '] ' . $msg . PHP_EOL;
    file_put_contents( JOB_IMPORT_LOGS, $log_entry, FILE_APPEND | LOCK_EX );
}

/**
 * Format date for locale (ported from 1.2).
 *
 * @param string $timestamp MySQL timestamp.
 * @param string $lang nl|fr|en.
 * @return string Formatted date.
 */
function format_date_locale( $timestamp, $lang = 'en' ) {
    $date = new DateTime( $timestamp );
    switch ( $lang ) {
        case 'nl':
            return $date->format( 'd/m/Y H:i' );
        case 'fr':
            return $date->format( 'd/m/Y H:i' );
        default:
            return $date->format( 'Y-m-d H:i' );
    }
}

/**
 * Validate URL (from 1.2).
 *
 * @param string $url.
 * @return bool.
 */
function validate_feed_url( $url ) {
    return ! empty( $url ) && filter_var( $url, FILTER_VALIDATE_URL ) && strpos( $url, '.xml' ) !== false;
}

/**
 * Generate slug for job (enhanced from 1.2).
 *
 * @param string $title.
 * @param string $guid.
 * @return string.
 */
function generate_job_slug( $title, $guid ) {
    return sanitize_title( $title . '-' . $guid );
}
