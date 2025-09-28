<?php

/**
 * Parallel feed download utilities using Symfony HTTP Client.
 *
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Download multiple feeds in parallel using Symfony HTTP Client.
 *
 * @param  array  $feeds         Array of feed URLs keyed by feed slug
 * @param  string $output_dir   Directory to store downloaded feeds
 * @param  array  &$logs         Reference to logs array for recording processing details
 * @param  int    $max_concurrent Maximum number of concurrent downloads (default: 5)
 * @return array Array of download results keyed by feed key
 */
function download_feeds_parallel(array $feeds, string $output_dir, array &$logs, int $max_concurrent = 5): array
{
    if (empty($feeds)) {
        return [];
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[PUNTWORK] Starting parallel feed downloads for ' . count($feeds) . ' feeds');
    }

    $client = HttpClient::create(
        [
            'timeout' => 300, // 5 minutes timeout
            'max_duration' => 600, // 10 minutes max duration
            'headers' => [
                'User-Agent' => 'WordPress puntWork Importer',
                'Accept-Encoding' => 'gzip, deflate',
            ],
        ]
    );

    $responses = [];
    $feed_paths = [];

    // Start all requests
    foreach ($feeds as $feed_key => $url) {
        $extension = \Puntwork\FeedProcessor::detectFormat($url);
        $feed_path = $output_dir . $feed_key . '.' . $extension;
        $feed_paths[$feed_key] = $feed_path;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[PUNTWORK] Starting download for {$feed_key}: {$url}");
        }

        try {
            $responses[$feed_key] = $client->request('GET', $url);
        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[PUNTWORK] Failed to start request for {$feed_key}: " . $e->getMessage());
            }
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Failed to start download for ' . $feed_key . ': ' . $e->getMessage();

            continue;
        }
    }

    $results = [];

    // Process responses as they complete
    foreach ($responses as $feed_key => $response) {
        $feed_path = $feed_paths[$feed_key];
        $url = $feeds[$feed_key];

        try {
            // Get response content
            $content = $response->getContent();

            // Check if download was successful
            $status_code = $response->getStatusCode();
            if ($status_code !== 200) {
                throw new \Exception("HTTP {$status_code}");
            }

            if (empty($content) || strlen($content) < 10) {
                throw new \Exception('Empty or small response');
            }

            // Write to file
            $bytes_written = file_put_contents($feed_path, $content);
            if ($bytes_written === false) {
                throw new \Exception('Failed to write file');
            }

            // Set permissions
            @chmod($feed_path, 0644);

            // Detect format from content
            $format = \Puntwork\FeedProcessor::detectFormat($url, $content);

            $results[$feed_key] = [
                'success' => true,
                'path' => $feed_path,
                'size' => $bytes_written,
                'format' => $format,
                'url' => $url,
            ];

            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Downloaded feed (' . $format . '): ' . number_format($bytes_written) . ' bytes';
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[PUNTWORK] Successfully downloaded {$feed_key}: " . number_format($bytes_written) . ' bytes');
            }
        } catch (TransportExceptionInterface $e) {
            $error_msg = "Transport error for {$feed_key}: " . $e->getMessage();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[PUNTWORK] ' . $error_msg);
            }
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . $error_msg;

            $results[$feed_key] = [
                'success' => false,
                'error' => $error_msg,
                'url' => $url,
            ];
        } catch (\Exception $e) {
            $error_msg = "Download failed for {$feed_key}: " . $e->getMessage();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[PUNTWORK] ' . $error_msg);
            }
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . $error_msg;

            $results[$feed_key] = [
                'success' => false,
                'error' => $error_msg,
                'url' => $url,
            ];
        }
    }

    $successful = count(array_filter($results, fn ($r) => $r['success']));
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("[PUNTWORK] Parallel downloads completed: {$successful}/" . count($feeds) . ' successful');
    }

    return $results;
}

/**
 * Download a single feed with caching support.
 *
 * @param  string $url         Feed URL to download
 * @param  string $feed_path   Path to save the downloaded feed
 * @param  string $output_dir  Output directory
 * @param  array  &$logs        Reference to logs array
 * @param  string &$format     Reference to detected format
 * @param  bool   $use_cache     Whether to use HTTP caching headers
 * @return bool True if download succeeded
 */
function download_feed_cached($url, $feed_path, $output_dir, &$logs, &$format = null, $use_cache = true): bool
{
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[PUNTWORK] ==== download_feed_cached START ===');
        error_log('[PUNTWORK] URL: ' . $url);
        error_log('[PUNTWORK] Feed path: ' . $feed_path);
    }

    // Check cache headers if enabled
    if ($use_cache && file_exists($feed_path)) {
        $cache_headers = get_feed_cache_headers($feed_path);
        if (!empty($cache_headers)) {
            $client = HttpClient::create(
                [
                    'timeout' => 30,
                    'headers' => array_merge(
                        [
                            'User-Agent' => 'WordPress puntWork Importer',
                            'Accept-Encoding' => 'gzip, deflate',
                        ],
                        $cache_headers
                    ),
                ]
            );

            try {
                $response = $client->request('HEAD', $url);
                $status_code = $response->getStatusCode();

                if ($status_code === 304) {
                    // Not modified, use cached version
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[PUNTWORK] Using cached feed (304 Not Modified)');
                    }
                    $content = file_get_contents($feed_path);
                    $format = \Puntwork\FeedProcessor::detectFormat($url, $content);

                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Used cached feed (' . $format . '): ' . filesize($feed_path) . ' bytes';

                    return true;
                }
            } catch (\Exception $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[PUNTWORK] Cache check failed, proceeding with full download: ' . $e->getMessage());
                }
            }
        }
    }

    // Full download using Symfony HTTP Client
    $client = HttpClient::create(
        [
            'timeout' => 300,
            'max_duration' => 600,
            'headers' => [
                'User-Agent' => 'WordPress puntWork Importer',
                'Accept-Encoding' => 'gzip, deflate',
            ],
        ]
    );

    try {
        $response = $client->request('GET', $url);
        $content = $response->getContent();

        $status_code = $response->getStatusCode();
        if ($status_code !== 200) {
            throw new \Exception("HTTP {$status_code}");
        }

        if (empty($content) || strlen($content) < 10) {
            throw new \Exception('Empty or small response');
        }

        // Write to file
        $bytes_written = file_put_contents($feed_path, $content);
        if ($bytes_written === false) {
            throw new \Exception('Failed to write file');
        }

        @chmod($feed_path, 0644);

        // Store cache headers for future requests
        if ($use_cache) {
            store_feed_cache_headers($feed_path, $response->getHeaders());
        }

        $format = \Puntwork\FeedProcessor::detectFormat($url, $content);

        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] Downloaded feed (' . $format . '): ' . number_format($bytes_written) . ' bytes';
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[PUNTWORK] Downloaded feed (' . $format . '): ' . number_format($bytes_written) . ' bytes');
        }

        return true;
    } catch (TransportExceptionInterface $e) {
        $error_msg = 'Transport error: ' . $e->getMessage();
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[PUNTWORK] ' . $error_msg);
        }
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . $error_msg;

        return false;
    } catch (\Exception $e) {
        $error_msg = 'Download error: ' . $e->getMessage();
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[PUNTWORK] ' . $error_msg);
        }
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . $error_msg;

        return false;
    }
}

/**
 * Get cache headers for a feed file.
 *
 * @param  string $feed_path Path to the feed file
 * @return array Cache headers for HTTP request
 */
function get_feed_cache_headers(string $feed_path): array
{
    $cache_file = $feed_path . '.cache';
    if (!file_exists($cache_file)) {
        return [];
    }

    $cache_data = json_decode(file_get_contents($cache_file), true);
    if (!$cache_data || !isset($cache_data['etag']) && !isset($cache_data['last_modified'])) {
        return [];
    }

    $headers = [];
    if (isset($cache_data['etag'])) {
        $headers['If-None-Match'] = $cache_data['etag'];
    }
    if (isset($cache_data['last_modified'])) {
        $headers['If-Modified-Since'] = $cache_data['last_modified'];
    }

    return $headers;
}

/**
 * Store cache headers for a feed file.
 *
 * @param string $feed_path Path to the feed file
 * @param array  $headers Response headers
 */
function store_feed_cache_headers(string $feed_path, array $headers): void
{
    $cache_data = [];

    // Extract ETag
    if (isset($headers['etag'])) {
        $cache_data['etag'] = is_array($headers['etag']) ? $headers['etag'][0] : $headers['etag'];
    }

    // Extract Last-Modified
    if (isset($headers['last-modified'])) {
        $cache_data['last_modified'] = is_array($headers['last-modified']) ? $headers['last-modified'][0] : $headers['last-modified'];
    }

    if (!empty($cache_data)) {
        $cache_data['cached_at'] = time();
        file_put_contents($feed_path . '.cache', json_encode($cache_data));
    }
}

/**
 * Clean up old cache files.
 *
 * @param string $output_dir Output directory
 * @param int    $max_age Maximum age in seconds (default: 24 hours)
 */
function cleanup_feed_cache(string $output_dir, int $max_age = 86400): void
{
    $cache_files = glob($output_dir . '*.cache');
    $cutoff_time = time() - $max_age;

    foreach ($cache_files as $cache_file) {
        $cache_data = json_decode(file_get_contents($cache_file), true);
        if (!$cache_data || !isset($cache_data['cached_at']) || $cache_data['cached_at'] < $cutoff_time) {
            @unlink($cache_file);
        }
    }
}
