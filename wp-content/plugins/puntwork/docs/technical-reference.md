# puntWork Technical Reference

## PHP Code Patterns

### File Header
```php
<?php
/**
 * File Description
 *
 * @package    Puntwork
 * @subpackage FileCategory
 * @since      1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

namespace Puntwork;
```

### Function Template
```php
/**
 * Function description
 *
 * @param string $param1 Parameter description
 * @param array  $param2 Parameter description
 * @return mixed Return description
 * @throws Exception When something goes wrong
 */
function function_name($param1, $param2 = []) {
    // Input validation
    if (empty($param1)) {
        throw new Exception('Param1 is required');
    }

    try {
        // Function logic
        $result = process_data($param1, $param2);

        // Logging
        error_log("Function completed: " . $param1);

        return $result;
    } catch (Exception $e) {
        error_log("Function error: " . $e->getMessage());
        throw $e;
    }
}
```

### AJAX Handler Pattern
```php
/**
 * Handle AJAX import request
 */
function handle_import_ajax() {
    // Security verification
    if (!wp_verify_nonce($_POST['nonce'], 'job_import_nonce')) {
        wp_send_json_error(['message' => 'Security check failed']);
        return;
    }

    // Permission check
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    // Sanitize inputs
    $feed_key = sanitize_text_field($_POST['feed_key']);
    $batch_size = intval($_POST['batch_size']);

    try {
        $result = process_import_batch($feed_key, $batch_size);
        wp_send_json_success($result);
    } catch (Exception $e) {
        error_log("AJAX import error: " . $e->getMessage());
        wp_send_json_error(['message' => 'Import failed: ' . $e->getMessage()]);
    }
}
add_action('wp_ajax_process_import', __NAMESPACE__ . '\\handle_import_ajax');
```

### Database Query Patterns
```php
// Safe post retrieval
$args = [
    'post_type' => 'job_post',
    'posts_per_page' => 50,
    'meta_query' => [
        [
            'key' => 'feed_url',
            'value' => $feed_url,
            'compare' => '='
        ]
    ]
];
$jobs = get_posts($args);

// Custom query with caching
function get_cached_jobs($feed_url) {
    $cache_key = 'puntwork_jobs_' . md5($feed_url);
    $jobs = get_transient($cache_key);

    if (false === $jobs) {
        $jobs = get_posts([
            'post_type' => 'job_post',
            'meta_query' => [['key' => 'feed_url', 'value' => $feed_url]]
        ]);
        set_transient($cache_key, $jobs, HOUR_IN_SECONDS);
    }

    return $jobs;
}
```

## JavaScript Patterns

### IIFE Module Pattern
```javascript
/**
 * UI Module for puntWork
 */
const PuntWorkJobImportUI = (function($) {
    'use strict';

    /**
     * Update progress bar
     * @param {number} percent - Progress percentage
     * @param {string} message - Progress message
     */
    function updateProgress(percent, message) {
        $('#progress-bar').css('width', percent + '%');
        $('#progress-text').text(message);
    }

    /**
     * Clear progress display
     */
    function clearProgress() {
        $('#progress-bar').css('width', '0%');
        $('#progress-text').text('');
        $('#progress-logs').empty();
    }

    // Public API
    return {
        updateProgress: updateProgress,
        clearProgress: clearProgress
    };
})(jQuery);

// Export to global
window.PuntWorkJobImportAdmin = window.PuntWorkJobImportAdmin || {};
window.PuntWorkJobImportAdmin.UI = PuntWorkJobImportUI;
```

### AJAX Call Pattern
```javascript
/**
 * API Module for puntWork
 */
const PuntWorkJobImportAPI = (function($) {
    'use strict';

    /**
     * Make AJAX call with error handling
     * @param {string} action - AJAX action
     * @param {object} data - Request data
     * @returns {Promise} AJAX promise
     */
    function makeRequest(action, data = {}) {
        return new Promise((resolve, reject) => {
            $.ajax({
                url: jobImportData.ajaxurl,
                type: 'POST',
                data: {
                    action: action,
                    nonce: jobImportData.nonce,
                    ...data
                },
                success: function(response) {
                    if (response.success) {
                        resolve(response.data);
                    } else {
                        reject(new Error(response.data.message || 'Request failed'));
                    }
                },
                error: function(xhr, status, error) {
                    reject(new Error('AJAX Error: ' + error));
                }
            });
        });
    }

    /**
     * Run import batch
     * @param {string} feedKey - Feed identifier
     * @param {number} batchSize - Batch size
     */
    function runImportBatch(feedKey, batchSize) {
        return makeRequest('run_import_batch', {
            feed_key: feedKey,
            batch_size: batchSize
        });
    }

    // Public API
    return {
        runImportBatch: runImportBatch,
        makeRequest: makeRequest
    };
})(jQuery);
```

### Event Handler Pattern
```javascript
/**
 * Events Module for puntWork
 */
const PuntWorkJobImportEvents = (function($) {
    'use strict';

    /**
     * Bind all event handlers
     */
    function bindEvents() {
        // Start import button
        $('#start-import').on('click', handleStartImport);

        // Cancel import button
        $('#cancel-import').on('click', handleCancelImport);

        // Resume import button
        $('#resume-import').on('click', handleResumeImport);
    }

    /**
     * Handle start import
     */
    function handleStartImport() {
        const feedKey = $('#feed-select').val();
        if (!feedKey) {
            alert('Please select a feed');
            return;
        }

        // Disable button to prevent double-clicks
        $(this).prop('disabled', true);

        PuntWorkJobImportAdmin.Logic.handleStartImport(feedKey)
            .catch(function(error) {
                alert('Import failed: ' + error.message);
                $(this).prop('disabled', false);
            });
    }

    // Public API
    return {
        bindEvents: bindEvents,
        handleStartImport: handleStartImport
    };
})(jQuery);
```

## WordPress Integration Patterns

### Shortcode Registration
```php
/**
 * Register job listing shortcode
 */
function register_job_shortcode() {
    add_shortcode('puntwork_jobs', __NAMESPACE__ . '\\display_jobs_shortcode');
}
add_action('init', __NAMESPACE__ . '\\register_job_shortcode');

/**
 * Display jobs shortcode handler
 *
 * @param array $atts Shortcode attributes
 * @return string HTML output
 */
function display_jobs_shortcode($atts) {
    $atts = shortcode_atts([
        'limit' => 10,
        'category' => '',
        'location' => ''
    ], $atts, 'puntwork_jobs');

    ob_start();
    display_jobs_template($atts);
    return ob_get_clean();
}
```

### Admin Menu Setup
```php
/**
 * Add admin menu
 */
function add_admin_menu() {
    add_menu_page(
        'Job Import Dashboard',
        'Job Import',
        'manage_options',
        'job-import-dashboard',
        __NAMESPACE__ . '\\display_admin_page',
        'dashicons-upload',
        30
    );
}
add_action('admin_menu', __NAMESPACE__ . '\\add_admin_menu');
```

### Asset Enqueuing
```php
/**
 * Enqueue admin assets
 */
function enqueue_admin_assets($hook) {
    if ('toplevel_page_job-import-dashboard' !== $hook) {
        return;
    }

    // Enqueue CSS
    wp_enqueue_style(
        'puntwork-admin-css',
        JOB_IMPORT_URL . 'assets/css/admin.css',
        [],
        JOB_IMPORT_VERSION
    );

    // Enqueue JS with dependencies
    wp_enqueue_script(
        'puntwork-admin-js',
        JOB_IMPORT_URL . 'assets/js/job-import-admin.js',
        ['jquery', 'wp-util'],
        JOB_IMPORT_VERSION,
        true
    );

    // Localize script
    wp_localize_script('puntwork-admin-js', 'jobImportData', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('job_import_nonce'),
        'feeds' => get_feed_options()
    ]);
}
add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_admin_assets');
```

## Error Handling Patterns

### Try-Catch with Logging
```php
try {
    $result = risky_operation($data);
    if (!$result) {
        throw new Exception('Operation failed');
    }
} catch (Exception $e) {
    error_log('Error in ' . __FUNCTION__ . ': ' . $e->getMessage());

    // User-friendly message
    add_settings_error(
        'puntwork_messages',
        'operation_failed',
        'Operation failed. Please check the logs.',
        'error'
    );

    return false;
}
```

### WordPress Error Handling
```php
// Check for WordPress errors
$response = wp_remote_get($url);
if (is_wp_error($response)) {
    $error_message = $response->get_error_message();
    error_log("HTTP Error: $error_message");

    return new WP_Error('http_error', $error_message);
}

// Check response code
$code = wp_remote_retrieve_response_code($response);
if ($code !== 200) {
    error_log("HTTP $code Error for URL: $url");
    return new WP_Error('http_error', "HTTP $code response");
}
```

## Security Patterns

### Input Sanitization
```php
// Text input
$name = sanitize_text_field($_POST['name']);

// Email
$email = sanitize_email($_POST['email']);

// URL
$url = esc_url_raw($_POST['url']);

// Integer
$count = intval($_POST['count']);

// Array of text
$tags = array_map('sanitize_text_field', $_POST['tags']);
```

### Output Escaping
```php
// HTML output
echo '<h2>' . esc_html($title) . '</h2>';

// URL in HTML
echo '<a href="' . esc_url($url) . '">' . esc_html($text) . '</a>';

// HTML attributes
echo '<div class="' . esc_attr($class) . '">';

// JavaScript variables
echo '<script>var data = ' . wp_json_encode($data) . ';</script>';
```

### Nonce Verification
```php
// Form nonce
wp_nonce_field('my_action', 'my_nonce');

// AJAX nonce
if (!wp_verify_nonce($_POST['nonce'], 'my_action')) {
    wp_die('Security check failed');
}

// URL nonce
$nonce_url = wp_nonce_url($url, 'my_action', 'my_nonce');
```

## Performance Patterns

### Transient Caching
```php
function get_cached_data($key) {
    $cache_key = 'puntwork_' . md5($key);
    $data = get_transient($cache_key);

    if (false === $data) {
        $data = expensive_operation($key);
        set_transient($cache_key, $data, HOUR_IN_SECONDS);
    }

    return $data;
}
```

### Batch Processing
```php
function process_batch($items, $batch_size = 50) {
    $total = count($items);
    $processed = 0;

    while ($processed < $total) {
        $batch = array_slice($items, $processed, $batch_size);

        foreach ($batch as $item) {
            process_item($item);
        }

        $processed += $batch_size;

        // Prevent timeout
        if ($processed % 100 === 0) {
            sleep(1);
        }
    }
}
```

### Query Optimization
```php
// Use WP_Query instead of get_posts for complex queries
$query = new WP_Query([
    'post_type' => 'job_post',
    'posts_per_page' => 100,
    'no_found_rows' => true, // Performance optimization
    'update_post_meta_cache' => false,
    'update_post_term_cache' => false,
    'meta_query' => [
        'relation' => 'AND',
        [
            'key' => 'feed_url',
            'value' => $feed_url
        ],
        [
            'key' => 'status',
            'value' => 'active'
        ]
    ]
]);
```

## Logging Patterns

### PHP Logging with PuntWorkLogger
```php
use Puntwork\PuntWorkLogger;

/**
 * Example function with comprehensive logging
 */
function process_import_batch($feed_key, $batch_size = 50) {
    PuntWorkLogger::info("Starting batch processing", "IMPORT", [
        'feed_key' => $feed_key,
        'batch_size' => $batch_size
    ]);

    try {
        // Process batch
        $result = perform_batch_operation($feed_key, $batch_size);

        PuntWorkLogger::info("Batch processing completed", "IMPORT", [
            'feed_key' => $feed_key,
            'processed' => $result['processed'],
            'duration' => $result['duration']
        ]);

        return $result;
    } catch (Exception $e) {
        PuntWorkLogger::error("Batch processing failed", "IMPORT", [
            'feed_key' => $feed_key,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        throw $e;
    }
}
```

### JavaScript Logging with PuntWorkJSLogger
```javascript
/**
 * Example function with structured logging
 */
function handleImport() {
    PuntWorkJSLogger.info('Starting import process', 'LOGIC');

    try {
        // Start performance monitoring
        PuntWorkJSLogger.startPerformanceSession('import-session');

        const result = await processImportBatch();
        PuntWorkJSLogger.info('Import completed successfully', 'LOGIC', result);

        // End performance session
        PuntWorkJSLogger.endPerformanceSession();

        return result;
    } catch (error) {
        PuntWorkJSLogger.error('Import failed', 'LOGIC', error);
        PuntWorkJSLogger.endPerformanceSession();
        throw error;
    }
}
```

### AJAX Request Logging
```javascript
/**
 * AJAX request with performance monitoring
 */
function makeAjaxRequest(action, data) {
    PuntWorkJSLogger.logAjaxRequest(action, data);

    return $.ajax({
        url: jobImportData.ajaxurl,
        type: 'POST',
        data: { action: action, ...data },
        success: function(response) {
            PuntWorkJSLogger.logAjaxResponse(action, response, true);
        },
        error: function(xhr, status, error) {
            PuntWorkJSLogger.logAjaxResponse(action, { error: error }, false);
        }
    });
}
```

## Performance Monitoring Patterns

### Batch Processing Performance
```javascript
/**
 * Monitor batch processing performance
 */
function processBatchWithMonitoring(items, batchSize) {
    return PuntWorkJSLogger.monitorBatchPerformance(
        'batch-processing',
        batchSize,
        items.length,
        async function() {
            let processed = 0;
            for (let i = 0; i < items.length; i += batchSize) {
                const batch = items.slice(i, i + batchSize);
                await processBatch(batch);
                processed += batch.length;

                PuntWorkJSLogger.logBatchProgress(processed, items.length, batchSize);
            }
            return { processed: items.length };
        }
    );
}
```

### Memory Usage Monitoring
```javascript
/**
 * Check memory usage and log warnings
 */
function checkMemoryUsage() {
    PuntWorkJSLogger.logMemoryUsage();

    // Additional custom memory checks
    if (performance.memory) {
        const memUsage = performance.memory.usedJSHeapSize / performance.memory.totalJSHeapSize;
        if (memUsage > 0.8) {
            PuntWorkJSLogger.warn('High memory usage detected', 'PERF', {
                usagePercent: Math.round(memUsage * 100),
                usedMB: Math.round(performance.memory.usedJSHeapSize / 1024 / 1024)
            });
        }
    }
}
```

### Performance Session Management
```javascript
/**
 * Complete operation with performance monitoring
 */
function performComplexOperation() {
    // Start monitoring
    PuntWorkJSLogger.startPerformanceSession('complex-operation');

    try {
        // Perform operation steps
        step1();
        PuntWorkJSLogger.logMemoryUsage();

        step2();
        PuntWorkJSLogger.logMemoryUsage();

        step3();
        PuntWorkJSLogger.logMemoryUsage();

        const result = finalizeOperation();

        PuntWorkJSLogger.info('Complex operation completed', 'SYSTEM');
        return result;

    } finally {
        // Always end session
        PuntWorkJSLogger.endPerformanceSession();
    }
}
```

### Developer Tools Usage
```javascript
// In browser console during development
pwLog.perf.start('debug-session')    // Start performance monitoring
pwLog.perf.memory()                  // Check current memory usage
pwLog.perf.system()                  // Log system information
pwLog.perf.timer('operation')        // Time a specific operation
pwLog.perf.end()                     // End monitoring session

pwLog.history()                      // View recent logs
pwLog.clear()                        // Clear log history
pwLog.level('DEBUG')                 // Change log level
```

## Common WordPress Hooks

### Action Hooks
```php
// Plugin activation
register_activation_hook(__FILE__, __NAMESPACE__ . '\\activate_plugin');

// Plugin deactivation
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\deactivate_plugin');

// Admin init
add_action('admin_init', __NAMESPACE__ . '\\admin_init_handler');

// Save post
add_action('save_post', __NAMESPACE__ . '\\save_post_handler', 10, 2);
```

### Filter Hooks
```php
// Modify post content
add_filter('the_content', __NAMESPACE__ . '\\modify_content');

// Add custom query vars
add_filter('query_vars', __NAMESPACE__ . '\\add_query_vars');

// Modify admin menu
add_filter('admin_menu', __NAMESPACE__ . '\\modify_admin_menu');
```

### AJAX Hooks
```php
// Logged-in users
add_action('wp_ajax_my_action', __NAMESPACE__ . '\\ajax_handler');

// Non-logged-in users
add_action('wp_ajax_nopriv_my_action', __NAMESPACE__ . '\\ajax_handler');
```

## File Organization

### Plugin Structure
```
puntwork/
├── puntwork.php          # Main plugin file
├── uninstall.php         # Uninstall handler
├── includes/             # PHP includes
│   ├── admin/           # Admin-specific code
│   ├── frontend/        # Frontend code
│   ├── api/            # API integrations
│   └── utilities/      # Helper functions
├── assets/              # CSS, JS, images
│   ├── css/
│   ├── js/
│   └── images/
├── templates/           # Template files
├── languages/           # Translation files
└── tests/               # Test files
```

### Naming Conventions
- **Files**: `kebab-case.php`
- **Classes**: `PascalCase`
- **Functions**: `snake_case` or `camelCase`
- **Constants**: `UPPER_SNAKE_CASE`
- **Namespaces**: `PascalCase`

This reference serves as a quick lookup for common patterns and best practices in puntWork development.