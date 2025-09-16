<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Core constants from snippet 1.1
define('JOB_IMPORT_FEED_URL', 'https://example.com/jobs.xml'); // Replace with actual
define('JOB_IMPORT_BATCH_SIZE', 50);
define('JOB_IMPORT_CHECK_INTERVAL', 3600); // 1 hour
define('JOB_IMPORT_MAX_DUPLICATES', 5); // Hash check window
define('JOB_IMPORT_LOG_LEVEL', 'info'); // debug/info/error

// Field mappings: XML/JSON to WP post fields (from snippet 1.1)
$job_import_mappings = [
    'title' => 'job_title',
    'description' => 'job_description',
    'location' => 'job_location',
    'salary' => 'job_salary',
    'category' => 'job_category',
    'date_posted' => 'job_date_posted',
    'url' => 'job_url',
    // Add more as per feed
];

// Category inference keywords (from snippet 1.7)
$job_import_categories = [
    'tech' => ['developer', 'engineer', 'programmer'],
    'marketing' => ['digital', 'content', 'seo'],
    // Expand as needed
];
?>
