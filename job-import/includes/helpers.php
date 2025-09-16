<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Utility functions from snippet 1.2
function job_import_sanitize_string($str) {
    return sanitize_text_field(trim(strip_tags($str)));
}

function job_import_format_date($date_str, $format = 'Y-m-d') {
    $timestamp = strtotime($date_str);
    return $timestamp ? date($format, $timestamp) : $format;
}

// Item cleaning from snippet 1.6
function job_import_clean_item($item) {
    $item['title'] = job_import_sanitize_string($item['title']);
    $item['description'] = wp_kses_post($item['description']);
    $item['location'] = job_import_sanitize_string($item['location']);
    // Clean other fields
    return $item;
}

// Gzip handling from snippet 2.1
function job_import_handle_gzip($url) {
    $response = wp_remote_get($url);
    if (is_wp_error($response)) {
        return false;
    }
    $body = wp_remote_retrieve_body($response);
    if (strpos($response['headers']['content-encoding'], 'gzip') !== false) {
        $body = gzdecode($body);
    }
    return $body;
}

// Combine JSONL from snippet 2.2
function job_import_combine_jsonl($files) {
    $combined = [];
    foreach ($files as $file) {
        if (($handle = fopen($file, 'r')) !== false) {
            while (($line = fgets($handle)) !== false) {
                $combined[] = json_decode($line, true);
            }
            fclose($handle);
        }
    }
    return $combined;
}

// Log helper
function job_import_log($message, $level = 'info') {
    if ($level !== JOB_IMPORT_LOG_LEVEL) return;
    $log_file = JOB_IMPORT_LOGS . date('Y-m-d') . '.log';
    file_put_contents($log_file, date('Y-m-d H:i:s') . " [$level] $message\n", FILE_APPEND | LOCK_EX);
}
?>
