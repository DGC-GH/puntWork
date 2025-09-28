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
if (! defined('ABSPATH')) {
    exit;
}

function download_feed($url, $feed_path, $output_dir, &$logs, &$format = null)
{
    error_log('[PUNTWORK] ===== download_feed START =====');
    error_log('[PUNTWORK] URL: ' . $url);
    error_log('[PUNTWORK] Feed path: ' . $feed_path);
    error_log('[PUNTWORK] Output dir: ' . $output_dir);

    // Start tracing span for feed download (only if available)
    $span = null;
    if (class_exists('\Puntwork\PuntworkTracing')) {
        $span = \Puntwork\PuntworkTracing::startActiveSpan(
            'download_feed',
            array(
                'feed.url'   => $url,
                'feed.path'  => $feed_path,
                'output.dir' => $output_dir,
            )
        );
    }

    try {
        $real_output_dir = realpath($output_dir);
        $real_feed_path  = realpath(dirname($feed_path)) . '/' . basename($feed_path);

        error_log('[PUNTWORK] Real output dir: ' . $real_output_dir);
        error_log('[PUNTWORK] Real feed path: ' . $real_feed_path);
        error_log('[PUNTWORK] Is writable: ' . ( is_writable($output_dir) ? 'yes' : 'no' ));

        if ($real_output_dir === false || strpos($real_feed_path, $real_output_dir) !== 0) {
            error_log('[PUNTWORK] Invalid file path detected');
            throw new \Exception('Invalid file path: Feed path must be within output directory');
        }
        if (! is_writable($output_dir)) {
            error_log('[PUNTWORK] Output directory not writable');
            throw new \Exception('Output directory is not writable');
        }

        try {
            error_log('[PUNTWORK] Starting download...');
            // Download the feed
            if (function_exists('curl_init')) {
                error_log('[PUNTWORK] Using cURL for download');
                $ch = curl_init($url);
                $fp = fopen($feed_path, 'w');
                if (! $fp) {
                    error_log('[PUNTWORK] Failed to open file for writing: ' . $feed_path);
                    throw new \Exception("Can't open $feed_path for write");
                }
                curl_setopt($ch, CURLOPT_FILE, $fp);
                curl_setopt($ch, CURLOPT_TIMEOUT, 300);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_USERAGENT, 'WordPress puntWork Importer');
                $success   = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                fclose($fp);

                error_log('[PUNTWORK] cURL success: ' . ( $success ? 'true' : 'false' ));
                error_log('[PUNTWORK] HTTP code: ' . $http_code);
                error_log('[PUNTWORK] File size: ' . filesize($feed_path));

                if (! $success || $http_code !== 200 || filesize($feed_path) < 10) {
                    error_log('[PUNTWORK] cURL download failed');
                    throw new \Exception("cURL download failed (HTTP $http_code, size: " . filesize($feed_path) . ')');
                }
            } else {
                error_log('[PUNTWORK] Using wp_remote_get for download');
                $response = wp_remote_get($url, array( 'timeout' => 300 ));
                if (is_wp_error($response)) {
                    error_log('[PUNTWORK] wp_remote_get error: ' . $response->get_error_message());
                    throw new \Exception($response->get_error_message());
                }
                $body = wp_remote_retrieve_body($response);
                error_log('[PUNTWORK] Response body length: ' . strlen($body));
                if (empty($body) || strlen($body) < 10) {
                    error_log('[PUNTWORK] Empty or small response');
                    throw new \Exception('Empty or small response');
                }
                file_put_contents($feed_path, $body);
                error_log('[PUNTWORK] File written successfully');
            }

            // Detect format from downloaded content
            $content = file_get_contents($feed_path);
            $format  = \Puntwork\FeedProcessor::detectFormat($url, $content);

            error_log('[PUNTWORK] Detected format: ' . $format);
            error_log('[PUNTWORK] Content preview: ' . substr($content, 0, 200));

            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' .
            "Downloaded feed ($format): " . filesize($feed_path) . ' bytes';
            error_log("Downloaded feed ($format): " . filesize($feed_path) . ' bytes');
            @chmod($feed_path, 0644);

            if ($span) {
                $span->setAttribute('feed.size', filesize($feed_path));
                $span->setAttribute('feed.format', $format);
                $span->end();
            }

            error_log('[PUNTWORK] ===== download_feed SUCCESS =====');
            return true;
        } catch (\Exception $e) {
            error_log('[PUNTWORK] ===== download_feed ERROR =====');
            error_log('[PUNTWORK] Exception: ' . $e->getMessage());
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Download error: ' . $e->getMessage();

            if ($span) {
                $span->recordException($e);
                $span->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR, $e->getMessage());
                $span->end();
            }

            return false;
        }
        // Close outer try block
    } catch (\Exception $e) {
        error_log('[PUNTWORK] ===== download_feed OUTER ERROR =====');
        error_log('[PUNTWORK] Outer exception: ' . $e->getMessage());
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Outer download error: ' . $e->getMessage();
        if ($span) {
            $span->recordException($e);
            $span->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR, $e->getMessage());
            $span->end();
        }
        return false;
    }
}
