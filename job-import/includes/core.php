<?php
// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ==================== SNIPPET 1.1: Mappings and Constants ====================
define( 'JOB_FEED_URL', 'https://example.com/jobs.xml.gz' ); // Example feed URL.
define( 'JOB_BATCH_SIZE', 50 );
define( 'JOB_LOG_FILE', JOB_IMPORT_PLUGIN_DIR . 'logs/import.log' );

function get_province_map() {
    return [
        'vlaanderen' => 'vlaanderen',
        'brussels' => 'brussels',
        'wallonie' => 'wallonie',
        // Add more mappings.
    ];
}

function get_salary_estimates() {
    return [
        'developer' => ['low' => 3500, 'high' => 5500],
        'admin' => ['low' => 2500, 'high' => 4000],
        // Add more.
    ];
}

function get_icon_map() {
    return [
        'developer' => 'code-icon',
        // Add more.
    ];
}

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
    $feed = download_feed( JOB_FEED_URL );
    if ( $feed ) {
        $xml = process_xml_batch( $feed );
        import_batch( $xml );
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
    wp_die( 'Heartbeat controlled' ); // Placeholder for real-time updates.
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
            $salary_text = '€3000 - €4500';
        }
    }
    $apply_link = isset( $item->applylink ) ? (string) $item->applylink : '';
    if ( $apply_link ) $apply_link .= '?utm_source=puntwork&utm_term=' . (string) $item->guid;
    $icon_key = array_reduce( array_keys( get_icon_map() ), function( $carry, $key ) use ( $fg ) {
        return strpos( $fg, strtolower( $key ) ) !== false ? $key : $carry;
    }, null );
    $icon = $icon_key ? get_icon_map()[$icon_key] : '';
    $all_text = strtolower( implode( ' ', [
        (string) $item->functiontitle,
        (string) $item->description,
        (string) $item->functiondescription,
        (string) $item->offerdescription,
        (string) $item->requirementsdescription,
        (string) $item->companydescription
    ] ) );
    $job_car = (bool) preg_match( '/bedrijfs(wagen|auto)|firmawagen|voiture de société|company car/i', $all_text );
    $job_remote = (bool) preg_match( '/thuiswerk|télétravail|remote work|home office/i', $all_text );
    $job_meal_vouchers = (bool) preg_match( '/maaltijdcheques|chèques repas|meal vouchers/i', $all_text );
    $job_flex_hours = (bool) preg_match( '/flexibele uren|heures flexibles|flexible hours/i', $all_text );
    $job_skills = [];
    if ( preg_match( '/\bexcel\b|\bmicrosoft excel\b|\bms excel\b/i', $all_text ) ) $job_skills[] = 'Excel';
    if ( preg_match( '/\bwinbooks\b/i', $all_text ) ) $job_skills[] = 'WinBooks';
    $parttime = isset( $item->parttime ) && (string) $item->parttime == 'true';
    $job_time = $parttime ? ( $lang == 'nl' ? 'Deeltijds' : ( $lang == 'fr' ? 'Temps partiel' : 'Part-time' ) ) :
        ( $lang == 'nl' ? 'Voltijds' : ( $lang == 'fr' ? 'Temps plein' : 'Full-time' ) );
    $job_desc = ( $lang == 'nl' ? 'Vacature' : ( $lang == 'fr' ? 'Emploi' : 'Job' ) ) . ': ' . $enhanced_title . '. ' .
        ( isset( $item->functiondescription ) ? (string) $item->functiondescription : '' ) .
        ( $lang == 'nl' ? ' Bij ' : ( $lang == 'fr' ? ' Chez ' : ' At ' ) ) .
        ( isset( $item->company ) ? (string) $item->company : 'Company' ) . '. ' .
        $salary_text . '. ' . implode( ', ', $job_skills );
    // Assign to job_obj.
    $job_obj->title = $enhanced_title;
    $job_obj->link = $job_link;
    $job_obj->apply_link = $apply_link;
    $job_obj->icon = $icon;
    $job_obj->perks = [ $job_car ? 'Company Car' : '', $job_remote ? 'Remote' : '', $job_meal_vouchers ? 'Meal Vouchers' : '', $job_flex_hours ? 'Flex Hours' : '' ];
    $job_obj->skills = $job_skills;
    $job_obj->type = $job_time;
    $job_obj->description = $job_desc;
}

// ==================== SNIPPET 1.8: Download Feed ====================
function download_feed( $url ) {
    $response = wp_remote_get( $url, [ 'timeout' => 30 ] );
    if ( is_wp_error( $response ) ) {
        log_message( 'Download error: ' . $response->get_error_message() );
        return false;
    }
    $body = wp_remote_retrieve_body( $response );
    if ( wp_remote_retrieve_header( $response, 'content-encoding' ) === 'gzip' ) {
        $body = gzdecode( $body ); // Delegate to 2.1 if needed.
    }
    return $body;
}

// ==================== SNIPPET 1.9: Process XML Batch ====================
function process_xml_batch( $xml ) {
    $doc = new SimpleXMLElement( $xml );
    $items = [];
    foreach ( $doc->job as $item ) { // Assume <job> nodes.
        $clean_item = clean_item( $item );
        $inferred = [];
        infer_item_details( $clean_item, 'default.be', 'en', $inferred );
        $items[] = process_batch_item( $clean_item, $inferred ); // From 2.5.
    }
    return array_slice( $items, 0, JOB_BATCH_SIZE );
}

// ==================== SNIPPET 2.1: Gzip File ====================
function decompress_gzip( $gz_data ) {
    return gzdecode( $gz_data );
}

// ==================== SNIPPET 2.2: Combine JSONL ====================
function combine_jsonl( $files ) {
    $combined = '';
    foreach ( $files as $file ) {
        $combined .= file_get_contents( $file ) . PHP_EOL;
    }
    return $combined;
}

// ==================== SNIPPET 2.5: Process Batch Items ====================
function process_batch_item( $item, $inferred ) {
    $processed = (array) $item;
    $processed = array_merge( $processed, $inferred );
    $processed['guid'] = (string) $item->guid;
    handle_duplicates( $processed ); // From 2.4.
    return $processed;
}

// ==================== SNIPPET 2.4: Handle Duplicates ====================
function handle_duplicates( &$item ) {
    $existing = get_posts( [
        'post_type' => JOB_IMPORT_POST_TYPE,
        'meta_key' => 'job_guid',
        'meta_value' => $item['guid'],
        'numberposts' => 1,
    ] );
    if ( ! empty( $existing ) ) {
        $item['status'] = 'duplicate';
        update_post_meta( $existing[0]->ID, 'job_data', $item );
    } else {
        $item['status'] = 'new';
    }
}

// ==================== SNIPPET 2.3: Import Batch ====================
function import_batch( $batch ) {
    foreach ( $batch as $item ) {
        if ( $item['status'] === 'new' ) {
            $post_id = wp_insert_post( [
                'post_title' => $item['title'],
                'post_content' => $item['description'],
                'post_type' => JOB_IMPORT_POST_TYPE,
                'post_status' => 'publish',
            ] );
            if ( $post_id ) {
                update_post_meta( $post_id, 'job_guid', $item['guid'] );
                update_post_meta( $post_id, 'job_link', $item['link'] );
                update_post_meta( $post_id, 'job_apply', $item['apply_link'] );
                update_post_meta( $post_id, 'job_salary', $item['salary_text'] ?? '' );
                update_post_meta( $post_id, 'job_perks', $item['perks'] );
                update_post_meta( $post_id, 'job_skills', $item['skills'] );
                update_post_meta( $post_id, 'job_type', $item['type'] );
                log_message( 'Imported job ID: ' . $post_id );
            }
        }
    }
}

// ==================== SNIPPET 5: Shortcode ====================
add_shortcode( 'job_listings', 'job_import_shortcode' );
function job_import_shortcode( $atts ) {
    $atts = shortcode_atts( [ 'count' => 10 ], $atts );
    $jobs = get_posts( [
        'post_type' => JOB_IMPORT_POST_TYPE,
        'posts_per_page' => $atts['count'],
        'post_status' => 'publish',
    ] );
    $output = '<ul class="job-listings">';
    foreach ( $jobs as $job ) {
        $title = get_the_title( $job->ID );
        $link = get_post_meta( $job->ID, 'job_link', true );
        $output .= '<li><a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a></li>';
    }
    $output .= '</ul>';
    return $output;
}
