<?php

/**
 * Fix HTML encoding in existing JSONL file
 *
 * @package    Puntwork
 * @subpackage Utilities
 * @since      1.0.0
 */

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

function fix_jsonl_html_encoding($jsonl_path) {
    if (!file_exists($jsonl_path)) {
        return new WP_Error('file_not_found', 'JSONL file not found: ' . $jsonl_path);
    }

    $temp_path = $jsonl_path . '.fixed';
    $handle = fopen($jsonl_path, 'r');
    $temp_handle = fopen($temp_path, 'w');

    if (!$handle || !$temp_handle) {
        return new WP_Error('file_open_error', 'Could not open files for processing');
    }

    $processed = 0;
    $errors = 0;

    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if (empty($line)) {
            continue;
        }

        try {
            $job = json_decode($line, true);
            if ($job === null) {
                $errors++;
                continue;
            }

            // Fix HTML encoding in description fields
            $html_fields = ['description', 'functiondescription', 'offerdescription', 'requirementsdescription', 'companydescription'];
            foreach ($html_fields as $field) {
                if (isset($job[$field])) {
                    $content = $job[$field];
                    // Decode HTML entities (handle double-encoding)
                    $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5);
                    $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5);
                    // Apply WordPress content sanitization
                    $content = wp_kses($content, wp_kses_allowed_html('post'));
                    // Clean up styling and other unwanted elements
                    $content = preg_replace('/\s*style\s*=\s*["\'][^"\']*["\']/', '', $content);
                    $content = preg_replace('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F1E0}-\x{1F1FF}\x{2702}-\x{27B0}\x{24C2}-\x{1F251}\x{1F900}-\x{1F9FF}\x{1FA70}-\x{1FAFF}]/u', '', $content);
                    $content = str_replace('&nbsp;', ' ', $content);
                    $job[$field] = trim($content);
                }
            }

            // Fix HTML encoding in title fields
            $title_fields = ['functiontitle', 'title'];
            foreach ($title_fields as $field) {
                if (isset($job[$field])) {
                    $content = $job[$field];
                    $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5);
                    $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5);
                    $content = preg_replace('/\s+(m\/v\/x|h\/f\/x|m\/f\/x)$/i', '', $content);
                    $job[$field] = trim($content);
                }
            }

            // Write the fixed job back to temp file
            fwrite($temp_handle, json_encode($job, JSON_UNESCAPED_UNICODE) . "\n");
            $processed++;

            if ($processed % 500 == 0) {
                error_log("Fixed $processed jobs so far...");
            }

        } catch (Exception $e) {
            $errors++;
            error_log("Error processing job: " . $e->getMessage());
        }
    }

    fclose($handle);
    fclose($temp_handle);

    // Replace original file with fixed version
    if (rename($temp_path, $jsonl_path)) {
        return [
            'success' => true,
            'processed' => $processed,
            'errors' => $errors,
            'message' => "Fixed HTML encoding for $processed jobs with $errors errors"
        ];
    } else {
        return new WP_Error('file_replace_error', 'Could not replace original file with fixed version');
    }
}

// Usage example:
// $result = fix_jsonl_html_encoding(ABSPATH . 'feeds/combined-jobs.jsonl');
// if (is_wp_error($result)) {
//     error_log('Fix failed: ' . $result->get_error_message());
// } else {
//     error_log('Fix completed: ' . $result['message']);
// }