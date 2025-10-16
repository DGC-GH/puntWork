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

function download_feed($url, $xml_path, $output_dir, &$logs, $force_use_wp_remote = false, ?callable $http_get_callable = null) {
    // Validate file paths for security
    if (!is_dir($output_dir) || !is_writable($output_dir)) {
        throw new \Exception('Output directory is not accessible or writable');
    }
    if (strpos($xml_path, $output_dir) !== 0) {
        throw new \Exception('Invalid file path: XML path must be within output directory');
    }
    try {
        if (!$force_use_wp_remote && function_exists('curl_init')) {
            $ch = curl_init($url);
            $fp = fopen($xml_path, 'w');
            if (!$fp) throw new \Exception("Can't open $xml_path for write");
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'WordPress puntWork Importer');
            $success = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fp);
            if (!$success || $http_code !== 200 || filesize($xml_path) < 1000) {
                throw new \Exception("cURL download failed (HTTP $http_code, size: " . filesize($xml_path) . ")");
            }
        } else {
            // Allow tests to inject a custom HTTP GET callable that returns either a string body or an array with 'body'
            if ($http_get_callable !== null) {
                $resp = $http_get_callable($url, ['timeout' => 300]);
                if (is_array($resp) && isset($resp['body'])) {
                    $body = $resp['body'];
                } elseif (is_string($resp)) {
                    $body = $resp;
                } else {
                    throw new \Exception('Invalid response from injected HTTP callable');
                }
            } else {
                $response = wp_remote_get($url, ['timeout' => 300]);
                if (is_wp_error($response)) throw new \Exception($response->get_error_message());
                $body = wp_remote_retrieve_body($response);
            }

            if (empty($body) || strlen($body) < 1000) throw new \Exception('Empty or small response');
            file_put_contents($xml_path, $body);
        }
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Downloaded XML: " . filesize($xml_path) . " bytes";
        error_log("Downloaded XML: " . filesize($xml_path) . " bytes");
        @chmod($xml_path, 0644);
    } catch (\Exception $e) {
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Download error: " . $e->getMessage();
        return false;
    }
    return true;
}
