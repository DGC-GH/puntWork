<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Download feed with gzip handling (new: adapted from snippet 2.1 for decompression)
function job_import_handle_gzip($url) {
    $response = wp_remote_get($url, ['decompress' => true]);
    if (is_wp_error($response)) {
        log_message('Feed download error: ' . $response->get_error_message());
        return false;
    }
    $body = wp_remote_retrieve_body($response);
    if (wp_remote_retrieve_header($response, 'content-encoding') === 'gzip') {
        $body = gzdecode($body);
    }
    return $body;
}

// Download feed from snippet 1.8
function job_download_feed() {
    $feed = job_import_handle_gzip(JOB_FEED_URL);
    if (!$feed) {
        log_message('Feed download failed', 'error');
        return false;
    }
    $temp_file = JOB_IMPORT_PLUGIN_DIR . 'temp/feed.xml'; // Use plugin dir constant
    file_put_contents($temp_file, $feed);
    log_message('Feed downloaded successfully');
    return $temp_file;
}

// Process XML batch from snippet 1.9 (added JSON support)
function job_process_xml_batch($batch_size = JOB_BATCH_SIZE) { // Standardized constant
    $feed_file = job_download_feed();
    if (!$feed_file) return;

    // Detect format (XML or JSON)
    $content = file_get_contents($feed_file);
    if (json_decode($content) !== null) {
        // Handle JSON (from snippet 2.2 - basic combine/parse)
        $items = json_decode($content, true)['jobs'] ?? []; // Assume 'jobs' key
    } else {
        $xml = simplexml_load_file($feed_file);
        if (!$xml) {
            log_message('XML parsing failed', 'error');
            return;
        }
        $items = [];
        foreach ($xml->job as $job_node) { // Assume <job> nodes
            $item = [];
            foreach (get_job_mappings() as $wp_field => $xml_field) { // From mappings.php
                $item[$wp_field] = (string) $job_node->$xml_field;
            }
            $items[] = $item;
        }
    }

    // Process in batches from 2.5
    $batches = array_chunk($items, $batch_size);
    foreach ($batches as $batch) {
        job_import_batch($batch);
    }
    unlink($feed_file);
}

// Import batch from snippet 2.3
function job_import_batch($items) {
    foreach ($items as $item) {
        $item = job_import_clean_item($item); // Standardized
        $item = job_import_infer_item($item); // Standardized

        if (job_import_handle_duplicate($item)) continue; // From 2.4

        $post_id = wp_insert_post([
            'post_title' => $item['title'],
            'post_content' => $item['description'],
            'post_type' => JOB_POST_TYPE, // From constants
            'post_status' => 'publish',
            'meta_input' => [
                'job_location' => $item['location'],
                'job_salary' => $item['salary'],
                // Add other meta
            ],
        ]);
        if (is_wp_error($post_id)) {
            log_message('Import failed for: ' . $item['title'], 'error');
        }
    }
}

// Item cleaning (standardized from core.php/snippet 1.6)
function job_import_clean_item($item) {
    $item['title'] = strip_tags($item['title'] ?? '');
    $item['description'] = wp_strip_all_tags($item['description'] ?? '');
    return $item;
}

// Item inference from snippet 1.7 (moved/standardized from core.php)
function job_import_infer_item($item) {
    if (empty($item['category'])) {
        foreach (get_job_categories() as $cat => $keywords) { // From mappings
            foreach ($keywords as $kw) {
                if (stripos(($item['title'] ?? '') . ($item['description'] ?? ''), $kw) !== false) {
                    $item['category'] = $cat;
                    break 2;
                }
            }
        }
    }
    // Province and title enhancement (from partial core.php)
    $province = strtolower(trim($item['province'] ?? ''));
    $norm_province = get_province_map()[$province] ?? JOB_FALLBACK_DOMAIN;
    $title = $item['functiontitle'] ?? '';
    $enhanced_title = $title;
    if (isset($item['city'])) $enhanced_title .= ' in ' . $item['city'];
    if (isset($item['province'])) $enhanced_title .= ', ' . $item['province'];
    $enhanced_title = trim($enhanced_title);
    $slug = sanitize_title($enhanced_title . '-' . ($item['guid'] ?? ''));
    $item['job_link'] = esc_url('https://' . $norm_province . '/job/' . $slug);

    // Salary inference
    $fg = strtolower(trim($item['functiongroup'] ?? ''));
    $estimate_key = array_reduce(array_keys(get_salary_estimates()), function($carry, $key) use ($fg) {
        return strpos($fg, strtolower($key)) !== false ? $key : $carry;
    });
    $salary_text = '';
    if (isset($item['salaryfrom']) && $item['salaryfrom'] != '0' && isset($item['salaryto']) && $item['salaryto'] != '0') {
        $salary_text = '€' . $item['salaryfrom'] . ' - €' . $item['salaryto'];
    } elseif (isset($item['salaryfrom']) && $item['salaryfrom'] != '0') {
        $salary_text = '€' . $item['salaryfrom'];
    } else {
        $lang = $item['lang'] ?? JOB_DEFAULT_LANG;
        $est_prefix = ($lang == 'nl' ? 'Geschat ' : ($lang == 'fr' ? 'Estimé ' : 'Est. '));
        if ($estimate_key) {
            $low = get_salary_estimates()[$estimate_key]['low'];
            $high = get_salary_estimates()[$estimate_key]['high'];
            $salary_text = $est_prefix . '€' . $low . ' - €' . $high;
        }
    }
    $item['salary'] = $salary_text;

    return $item;
}

// Handle duplicates from snippet 2.4
function job_import_handle_duplicate($item) {
    $hash = md5(($item['title'] ?? '') . ($item['description'] ?? ''));
    $recent_posts = get_posts([
        'post_type' => JOB_POST_TYPE,
        'posts_per_page' => 100, // Arbitrary max for duplicates
        'meta_query' => [['key' => 'job_hash', 'value' => $hash]],
    ]);
    if (!empty($recent_posts)) {
        log_message('Duplicate found: ' . ($item['title'] ?? ''));
        return true;
    }
    $item['hash'] = $hash; // For meta
    return false;
}

// Helper: Get job mappings (new, for XML/JSON fields)
function get_job_mappings() {
    return [
        'title' => 'functiontitle',
        'description' => 'description',
        'location' => 'city',
        'province' => 'province',
        'salaryfrom' => 'salaryfrom',
        'salaryto' => 'salaryto',
        'guid' => 'guid',
        'functiongroup' => 'functiongroup',
        // Add more as needed
    ];
}
?>
