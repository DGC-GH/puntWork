<?php
/**
 * puntWork Logging Utility
 * Centralized logging system for development and debugging
 *
 * @package    Puntwork
 * @subpackage Utilities
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Centralized logging utility for puntWork plugin
 * Provides structured logging with different levels and contexts
 */
class PuntWorkLogger {

    // Log levels
    const DEBUG = 'DEBUG';
    const INFO = 'INFO';
    const WARN = 'WARN';
    const ERROR = 'ERROR';

    // Log contexts
    const CONTEXT_AJAX = 'AJAX';
    const CONTEXT_BATCH = 'BATCH';
    const CONTEXT_FEED = 'FEED';
    const CONTEXT_UI = 'UI';
    const CONTEXT_SYSTEM = 'SYSTEM';

    /**
     * Log a debug message
     *
     * @param string $message Log message
     * @param string $context Context identifier
     * @param array $data Additional data to log
     */
    public static function debug($message, $context = self::CONTEXT_SYSTEM, $data = []) {
        self::log($message, self::DEBUG, $context, $data, false);
    }

    /**
     * Log an info message
     *
     * @param string $message Log message
     * @param string $context Context identifier
     * @param array $data Additional data to log
     */
    public static function info($message, $context = self::CONTEXT_SYSTEM, $data = []) {
        self::log($message, self::INFO, $context, $data);
    }

    /**
     * Log a warning message
     *
     * @param string $message Log message
     * @param string $context Context identifier
     * @param array $data Additional data to log
     */
    public static function warn($message, $context = self::CONTEXT_SYSTEM, $data = []) {
        self::log($message, self::WARN, $context, $data);
    }

    /**
     * Log an error message
     *
     * @param string $message Log message
     * @param string $context Context identifier
     * @param array $data Additional data to log
     */
    public static function error($message, $context = self::CONTEXT_SYSTEM, $data = []) {
        self::log($message, self::ERROR, $context, $data);
    }

    /**
     * Core logging method
     *
     * @param string $message Log message
     * @param string $level Log level
     * @param string $context Context identifier
     * @param array $data Additional data to log
     * @param bool $includeFunction Whether to include calling function context
     */
    private static function log($message, $level, $context, $data = [], $includeFunction = true) {
        // Skip debug logs in production unless WP_DEBUG is true
        if ($level === self::DEBUG && (!defined('WP_DEBUG') || !WP_DEBUG)) {
            return;
        }

        $function = $includeFunction ? self::getCallingFunction() : null;

        // Format the log message (without timestamp since error_log adds it)
        $formattedMessage = sprintf(
            '[%s] [%s] %s',
            $level,
            $context,
            $message
        );

        // Add function context if available and requested
        if ($function) {
            $formattedMessage .= " (in {$function})";
        }

        // Add additional data if provided
        if (!empty($data)) {
            $formattedMessage .= ' | Data: ' . json_encode($data, JSON_UNESCAPED_SLASHES);
        }

        // Log to WordPress debug.log
        error_log($formattedMessage);

        // Also add to admin logs if in admin context
        if (is_admin() && isset($GLOBALS['import_logs'])) {
            $timestamp = date('d-M-Y H:i:s T');
            $adminMessage = "[{$timestamp}] {$formattedMessage}";
            $GLOBALS['import_logs'][] = $adminMessage;
        }
    }

    /**
     * Get the calling function for context
     *
     * @return string|null Calling function name
     */
    private static function getCallingFunction() {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);

        // Skip the first two entries (this method and the calling log method)
        if (isset($backtrace[2])) {
            $caller = $backtrace[2];
            $function = isset($caller['function']) ? $caller['function'] : null;
            $class = isset($caller['class']) ? $caller['class'] : null;

            if ($class && $function) {
                return $class . '::' . $function;
            } elseif ($function) {
                return $function;
            }
        }

        return null;
    }

    /**
     * Log AJAX request details
     *
     * @param string $action AJAX action name
     * @param array $postData POST data
     */
    public static function logAjaxRequest($action, $postData = []) {
        $safeData = self::sanitizeLogData($postData);
        self::debug("AJAX Request: {$action}", self::CONTEXT_AJAX, $safeData);
    }

    /**
     * Log AJAX response details
     *
     * @param string $action AJAX action name
     * @param mixed $response Response data
     * @param bool $success Whether the request was successful
     */
    public static function logAjaxResponse($action, $response, $success = true) {
        $level = $success ? self::DEBUG : self::ERROR;
        $status = $success ? 'SUCCESS' : 'FAILED';

        if (is_array($response) || is_object($response)) {
            $responseData = self::sanitizeLogData($response);
            self::log("AJAX Response: {$action} - {$status}", $level, self::CONTEXT_AJAX, $responseData, false);
        } else {
            self::log("AJAX Response: {$action} - {$status}: {$response}", $level, self::CONTEXT_AJAX, [], false);
        }
    }

    /**
     * Log batch processing details
     *
     * @param int $processed Number of items processed
     * @param int $total Total number of items
     * @param int $batchSize Current batch size
     * @param float $timeElapsed Time elapsed in seconds
     */
    public static function logBatchProgress($processed, $total, $batchSize, $timeElapsed) {
        $percent = $total > 0 ? round(($processed / $total) * 100, 1) : 0;
        $message = "Batch Progress: {$processed}/{$total} ({$percent}%) | Batch Size: {$batchSize} | Time: {$timeElapsed}s";

        self::info($message, self::CONTEXT_BATCH, [
            'processed' => $processed,
            'total' => $total,
            'percentage' => $percent,
            'batch_size' => $batchSize,
            'time_elapsed' => $timeElapsed
        ]);
    }

    /**
     * Log feed processing details
     *
     * @param string $feedKey Feed identifier
     * @param string $url Feed URL
     * @param int $itemCount Number of items processed
     * @param bool $success Whether processing was successful
     */
    public static function logFeedProcessing($feedKey, $url, $itemCount, $success = true) {
        $status = $success ? 'SUCCESS' : 'FAILED';
        $message = "Feed Processing: {$feedKey} - {$status} | Items: {$itemCount}";

        $data = [
            'feed_key' => $feedKey,
            'url' => self::sanitizeUrl($url),
            'item_count' => $itemCount,
            'success' => $success
        ];

        if ($success) {
            self::info($message, self::CONTEXT_FEED, $data);
        } else {
            self::error($message, self::CONTEXT_FEED, $data);
        }
    }

    /**
     * Sanitize sensitive data for logging
     *
     * @param mixed $data Data to sanitize
     * @return mixed Sanitized data
     */
    private static function sanitizeLogData($data) {
        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                if (strpos(strtolower($key), 'password') !== false ||
                    strpos(strtolower($key), 'key') !== false ||
                    strpos(strtolower($key), 'secret') !== false ||
                    strpos(strtolower($key), 'token') !== false) {
                    $sanitized[$key] = '[REDACTED]';
                } elseif (is_array($value) || is_object($value)) {
                    $sanitized[$key] = self::sanitizeLogData($value);
                } else {
                    $sanitized[$key] = $value;
                }
            }
            return $sanitized;
        } elseif (is_object($data)) {
            return self::sanitizeLogData((array) $data);
        }

        return $data;
    }

    /**
     * Sanitize URLs for logging (remove sensitive parameters)
     *
     * @param string $url URL to sanitize
     * @return string Sanitized URL
     */
    private static function sanitizeUrl($url) {
        // Remove potential sensitive parameters
        $parsed = parse_url($url);
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $params);
            $safeParams = self::sanitizeLogData($params);
            $parsed['query'] = http_build_query($safeParams);
            return self::buildUrl($parsed);
        }
        return $url;
    }

    /**
     * Build URL from parsed components
     *
     * @param array $parsed Parsed URL components
     * @return string Built URL
     */
    private static function buildUrl($parsed) {
        $url = '';
        if (isset($parsed['scheme'])) {
            $url .= $parsed['scheme'] . '://';
        }
        if (isset($parsed['user'])) {
            $url .= $parsed['user'];
            if (isset($parsed['pass'])) {
                $url .= ':' . $parsed['pass'];
            }
            $url .= '@';
        }
        if (isset($parsed['host'])) {
            $url .= $parsed['host'];
        }
        if (isset($parsed['port'])) {
            $url .= ':' . $parsed['port'];
        }
        if (isset($parsed['path'])) {
            $url .= $parsed['path'];
        }
        if (isset($parsed['query'])) {
            $url .= '?' . $parsed['query'];
        }
        if (isset($parsed['fragment'])) {
            $url .= '#' . $parsed['fragment'];
        }
        return $url;
    }

    /**
     * Add a log entry to the admin interface logs
     *
     * @param string $message Log message
     * @param string $level Log level
     * @param string $context Context identifier
     */
    public static function addAdminLog($message, $level = self::INFO, $context = self::CONTEXT_SYSTEM) {
        // Format the log message (without timestamp since error_log adds it)
        $formattedMessage = "[{$level}] [{$context}] {$message}";

        // Add to global import logs if available (with timestamp for admin display)
        if (isset($GLOBALS['import_logs']) && is_array($GLOBALS['import_logs'])) {
            $timestamp = date('d-M-Y H:i:s T');
            $adminMessage = "[{$timestamp}] {$formattedMessage}";
            $GLOBALS['import_logs'][] = $adminMessage;
        }

        // Also log to WordPress debug.log
        error_log($formattedMessage);
    }
}