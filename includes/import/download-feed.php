<?php
/**
 * Feed download utilities
 *
 * @package    Puntwork
 * @subpackage Utilities
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function download_feed($url, $feed_path, $output_dir, &$logs, &$format = null) {
    // Validate file paths for security
    $real_output_dir = realpath($output_dir);
    $real_feed_path = realpath(dirname($feed_path)) . '/' . basename($feed_path);
    if ($real_output_dir === false || strpos($real_feed_path, $real_output_dir) !== 0) {
        throw new \Exception('Invalid file path: Feed path must be within output directory');
    }
    if (!is_writable($output_dir)) {
        throw new \Exception('Output directory is not writable');
    }

    try {
        // Download the feed
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $fp = fopen($feed_path, 'w');
            if (!$fp) throw new \Exception("Can't open $feed_path for write");
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'WordPress puntWork Importer');
            $success = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fp);
            if (!$success || $http_code !== 200 || filesize($feed_path) < 10) {
                throw new \Exception("cURL download failed (HTTP $http_code, size: " . filesize($feed_path) . ")");
            }
        } else {
            $response = wp_remote_get($url, ['timeout' => 300]);
            if (is_wp_error($response)) throw new \Exception($response->get_error_message());
            $body = wp_remote_retrieve_body($response);
            if (empty($body) || strlen($body) < 10) throw new \Exception('Empty or small response');
            file_put_contents($feed_path, $body);
        }

        // Detect format from downloaded content
        $content = file_get_contents($feed_path);
        $format = FeedProcessor::detect_format($url, $content);

        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Downloaded feed ($format): " . filesize($feed_path) . " bytes";
        error_log("Downloaded feed ($format): " . filesize($feed_path) . " bytes");
        @chmod($xml_path, 0644);
    } catch (\Exception $e) {
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Download error: " . $e->getMessage();
        return false;
    }
    return true;
}
