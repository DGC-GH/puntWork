<?php
/**
 * Security and validation utilities
 *
 * @package    Puntwork
 * @subpackage Utilities
 * @since      1.0.10
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Security utilities class
 */
class SecurityUtils {

    /**
     * Rate limiting storage
     */
    private static $rate_limits = [];

    /**
     * Validate AJAX request with comprehensive security checks
     *
     * @param string $action Action name for logging
     * @param string $nonce_action Nonce action string
     * @param array $required_fields Required POST fields
     * @param array $validation_rules Validation rules for fields
     * @return array|WP_Error Validation result or error
     */
    public static function validate_ajax_request(
        string $action,
        string $nonce_action = 'puntwork_admin_nonce',
        array $required_fields = [],
        array $validation_rules = []
    ) {
        try {
            // Check rate limiting first
            $rate_limit_check = self::check_rate_limit($action);
            if (is_wp_error($rate_limit_check)) {
                PuntWorkLogger::warning("Rate limit exceeded for action: {$action}", PuntWorkLogger::CONTEXT_SECURITY);
                return $rate_limit_check;
            }

            // Verify nonce
            if (!wp_verify_nonce($_POST['nonce'] ?? '', $nonce_action)) {
                PuntWorkLogger::error("Nonce verification failed for action: {$action}", PuntWorkLogger::CONTEXT_SECURITY);
                return new \WP_Error('security', 'Security check failed: invalid nonce');
            }

            // Check user capabilities
            if (!current_user_can('manage_options')) {
                PuntWorkLogger::error("Permission denied for action: {$action}", PuntWorkLogger::CONTEXT_SECURITY);
                return new \WP_Error('permissions', 'Insufficient permissions');
            }

            // Validate required fields
            foreach ($required_fields as $field) {
                if (!isset($_POST[$field]) || $_POST[$field] === '') {
                    PuntWorkLogger::error("Missing required field: {$field} for action: {$action}", PuntWorkLogger::CONTEXT_SECURITY);
                    return new \WP_Error('validation', "Missing required field: {$field}");
                }
            }

            // Apply validation rules
            foreach ($validation_rules as $field => $rules) {
                if (isset($_POST[$field])) {
                    $validation_result = self::validate_field($_POST[$field], $rules, $field);
                    if (is_wp_error($validation_result)) {
                        PuntWorkLogger::error("Field validation failed for {$field}: " . $validation_result->get_error_message(), PuntWorkLogger::CONTEXT_SECURITY);
                        return $validation_result;
                    }
                    // Sanitize the validated value
                    $_POST[$field] = $validation_result;
                }
            }

            PuntWorkLogger::debug("AJAX request validation passed for action: {$action}", PuntWorkLogger::CONTEXT_SECURITY);
            return true;

        } catch (\Exception $e) {
            PuntWorkLogger::error("Exception during AJAX validation for {$action}: " . $e->getMessage(), PuntWorkLogger::CONTEXT_SECURITY);
            return new \WP_Error('exception', 'Validation error: ' . $e->getMessage());
        }
    }

    /**
     * Check rate limiting for AJAX requests
     *
     * @param string $action Action name
     * @param int $max_requests Maximum requests per time window
     * @param int $time_window Time window in seconds
     * @return bool|WP_Error True if allowed, WP_Error if rate limited
     */
    public static function check_rate_limit(string $action, int $max_requests = 10, int $time_window = 60) {
        $user_id = get_current_user_id();
        $key = "rate_limit_{$action}_{$user_id}";

        $current_time = time();
        $requests = get_transient($key);

        if (!$requests) {
            $requests = [];
        }

        // Remove old requests outside the time window
        $requests = array_filter($requests, function($timestamp) use ($current_time, $time_window) {
            return ($current_time - $timestamp) < $time_window;
        });

        // Check if rate limit exceeded
        if (count($requests) >= $max_requests) {
            return new \WP_Error('rate_limit', 'Rate limit exceeded. Please wait before trying again.');
        }

        // Add current request
        $requests[] = $current_time;

        // Store updated requests (with 10% buffer on time window)
        set_transient($key, $requests, $time_window + 6);

        return true;
    }

    /**
     * Validate a single field against rules
     *
     * @param mixed $value Field value
     * @param array $rules Validation rules
     * @param string $field_name Field name for error messages
     * @return mixed|WP_Error Sanitized value or error
     */
    public static function validate_field($value, array $rules, string $field_name) {
        // Type validation
        if (isset($rules['type'])) {
            switch ($rules['type']) {
                case 'int':
                case 'integer':
                    if (!is_numeric($value)) {
                        return new \WP_Error('validation', "{$field_name} must be a number");
                    }
                    $value = intval($value);
                    break;
                case 'float':
                case 'double':
                    if (!is_numeric($value)) {
                        return new \WP_Error('validation', "{$field_name} must be a number");
                    }
                    $value = floatval($value);
                    break;
                case 'bool':
                case 'boolean':
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($value === null) {
                        return new \WP_Error('validation', "{$field_name} must be a boolean");
                    }
                    break;
                case 'string':
                    $value = strval($value);
                    break;
                case 'key':
                    $value = sanitize_key($value);
                    break;
                case 'text':
                    $value = sanitize_text_field($value);
                    break;
                case 'email':
                    $value = sanitize_email($value);
                    if (!is_email($value)) {
                        return new \WP_Error('validation', "{$field_name} must be a valid email address");
                    }
                    break;
                case 'url':
                    $value = esc_url_raw($value);
                    if (!filter_var($value, FILTER_VALIDATE_URL)) {
                        return new \WP_Error('validation', "{$field_name} must be a valid URL");
                    }
                    break;
            }
        }

        // Range validation
        if (isset($rules['min']) && is_numeric($value) && $value < $rules['min']) {
            return new \WP_Error('validation', "{$field_name} must be at least {$rules['min']}");
        }

        if (isset($rules['max']) && is_numeric($value) && $value > $rules['max']) {
            return new \WP_Error('validation', "{$field_name} must be at most {$rules['max']}");
        }

        // Length validation for strings
        if (is_string($value)) {
            if (isset($rules['min_length']) && strlen($value) < $rules['min_length']) {
                return new \WP_Error('validation', "{$field_name} must be at least {$rules['min_length']} characters");
            }

            if (isset($rules['max_length']) && strlen($value) > $rules['max_length']) {
                return new \WP_Error('validation', "{$field_name} must be at most {$rules['max_length']} characters");
            }
        }

        // Enum validation
        if (isset($rules['enum']) && is_array($rules['enum']) && !in_array($value, $rules['enum'])) {
            return new \WP_Error('validation', "{$field_name} must be one of: " . implode(', ', $rules['enum']));
        }

        // Pattern validation
        if (isset($rules['pattern']) && !preg_match($rules['pattern'], $value)) {
            return new \WP_Error('validation', "{$field_name} format is invalid");
        }

        return $value;
    }

    /**
     * Sanitize and validate array of data
     *
     * @param array $data Data to sanitize
     * @param array $rules Validation rules
     * @return array|WP_Error Sanitized data or error
     */
    public static function sanitize_data_array(array $data, array $rules) {
        $sanitized = [];

        foreach ($rules as $field => $field_rules) {
            if (isset($data[$field])) {
                $result = self::validate_field($data[$field], $field_rules, $field);
                if (is_wp_error($result)) {
                    return $result;
                }
                $sanitized[$field] = $result;
            } elseif (isset($field_rules['required']) && $field_rules['required']) {
                return new \WP_Error('validation', "Required field missing: {$field}");
            }
        }

        return $sanitized;
    }

    /**
     * Generate a secure random string
     *
     * @param int $length Length of the string
     * @return string Random string
     */
    public static function generate_secure_token(int $length = 32): string {
        return bin2hex(random_bytes($length / 2));
    }

    /**
     * Log security event
     *
     * @param string $event Event type
     * @param array $data Additional data
     */
    public static function log_security_event(string $event, array $data = []) {
        $log_data = array_merge($data, [
            'user_id' => get_current_user_id(),
            'user_ip' => self::get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'timestamp' => current_time('mysql')
        ]);

        PuntWorkLogger::info("Security event: {$event}", PuntWorkLogger::CONTEXT_SECURITY, $log_data);
    }

    /**
     * Get client IP address
     *
     * @return string Client IP
     */
    public static function get_client_ip(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = trim(explode(',', $_SERVER[$header])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Check if request is from a trusted source (basic implementation)
     *
     * @return bool True if trusted
     */
    public static function is_trusted_request(): bool {
        // Check if request is from same domain
        $referer = wp_get_referer();
        if ($referer) {
            $site_url = get_site_url();
            return strpos($referer, $site_url) === 0;
        }

        return false;
    }
}

/**
 * Enhanced error handling for AJAX responses
 */
class AjaxErrorHandler {

    /**
     * Send JSON error response with proper formatting
     *
     * @param string|WP_Error $error Error message or WP_Error object
     * @param array $additional_data Additional data to include
     */
    public static function send_error($error, array $additional_data = []) {
        $error_data = [
            'success' => false,
            'timestamp' => current_time('mysql')
        ];

        if (is_wp_error($error)) {
            $error_data['error'] = [
                'code' => $error->get_error_code(),
                'message' => $error->get_error_message(),
                'data' => $error->get_error_data()
            ];
        } else {
            $error_data['error'] = [
                'code' => 'general_error',
                'message' => $error
            ];
        }

        $error_data = array_merge($error_data, $additional_data);

        // Log error for security monitoring
        if (is_wp_error($error)) {
            PuntWorkLogger::error('AJAX Error Response: ' . $error->get_error_message(), PuntWorkLogger::CONTEXT_AJAX, [
                'error_code' => $error->get_error_code(),
                'error_data' => $error->get_error_data()
            ]);
        } else {
            PuntWorkLogger::error('AJAX Error Response: ' . $error, PuntWorkLogger::CONTEXT_AJAX);
        }

        wp_send_json($error_data);
    }

    /**
     * Send JSON success response with proper formatting
     *
     * @param mixed $data Response data
     * @param array $additional_data Additional data to include
     */
    public static function send_success($data = null, array $additional_data = []) {
        $response_data = [
            'success' => true,
            'timestamp' => current_time('mysql')
        ];

        if ($data !== null) {
            $response_data['data'] = $data;
        }

        $response_data = array_merge($response_data, $additional_data);

        wp_send_json($response_data);
    }
}