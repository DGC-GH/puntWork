<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Download feed from snippet 1.8
function job_download_feed() {
    $feed = job_import_handle_gzip(JOB_IMPORT_FEED_URL);
    if (!$feed) {
        job_import_log('Feed download failed', 'error');
        return false;
    }
    $temp_file = JOB_IMPORT_PATH . 'temp/feed.xml';
    file_put_contents($temp_file, $feed);
    job_import_log('Feed downloaded successfully');
    return $temp_file;
}

// Process XML batch from snippet 1.9
function job_process_xml_batch($batch_size = JOB_IMPORT_BATCH_SIZE) {
    $feed_file = job_download_feed();
    if (!$feed_file) return;

    $xml = simplexml_load_file($feed_file);
    if (!$xml) {
        job_import_log('XML parsing failed', 'error');
        return;
    }

    $items = [];
    foreach ($xml->job as $job_node) { // Assume <job> nodes
        $item = [];
        foreach ($job_import_mappings as $wp_field => $xml_field) {
            $item[$wp_field] = (string) $job_node->$xml_field;
        }
        $items[] = $item;
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
        $item = job_import_clean_item($item); // From helpers
        $item = job_import_infer_item($item); // From 1.7

        if (job_import_handle_duplicate($item)) continue; // From 2.4

        $post_id = wp_insert_post([
            'post_title' => $item['title'],
            'post_content' => $item['description'],
            'post_type' => 'job',
            'post_status' => 'publish',
            'meta_input' => [
                'job_location' => $item['location'],
                'job_salary' => $item['salary'],
                // Add other meta
            ],
        ]);
        if (is_wp_error($post_id)) {
            job_import_log('Import failed for: ' . $item['title'], 'error');
        }
    }
}

// Item inference from snippet 1.7
function job_import_infer_item($item) {
    if (empty($item['category'])) {
        foreach ($job_import_categories as $cat => $keywords) {
            foreach ($keywords as $kw) {
                if (stripos($item['title'] . $item['description'], $kw) !== false) {
                    $item['category'] = $cat;
                    break 2;
                }
            }
        }
    }
    return $item;
}

// Handle duplicates from snippet 2.4
function job_import_handle_duplicate($item) {
    $hash = md5($item['title'] . $item['description']);
    $recent_posts = get_posts([
        'post_type' => 'job',
        'posts_per_page' => JOB_IMPORT_MAX_DUPLICATES,
        'meta_query' => [['key' => 'job_hash', 'value' => $hash]],
    ]);
    if (!empty($recent_posts)) {
        job_import_log('Duplicate found: ' . $item['title']);
        return true;
    }
    $item['hash'] = $hash; // For meta
    return false;
}
?>
