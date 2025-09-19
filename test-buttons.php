<?php
/**
 * Test script to verify cleanup and purge button functionality
 *
 * @package    Puntwork
 * @subpackage Test
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Test cleanup duplicates functionality
 */
function test_cleanup_duplicates() {
    global $wpdb;

    echo "<h2>Testing Cleanup Duplicates Functionality</h2>";

    // Check if there are any draft jobs
    $draft_jobs = $wpdb->get_results("
        SELECT p.ID, p.post_title, p.post_status, pm.meta_value AS guid
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'guid'
        WHERE p.post_type = 'job'
        AND p.post_status = 'draft'
        LIMIT 10
    ");

    echo "<h3>Draft Jobs Found:</h3>";
    if (empty($draft_jobs)) {
        echo "<p>No draft jobs found.</p>";
    } else {
        echo "<ul>";
        foreach ($draft_jobs as $job) {
            echo "<li>ID: {$job->ID}, Title: {$job->post_title}, GUID: {$job->guid}</li>";
        }
        echo "</ul>";
    }

    // Check for duplicate GUIDs
    $duplicate_guids = $wpdb->get_results("
        SELECT pm.meta_value AS guid, COUNT(*) as count
        FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'guid'
        WHERE p.post_type = 'job'
        AND p.post_status IN ('publish', 'draft')
        GROUP BY pm.meta_value
        HAVING COUNT(*) > 1
        LIMIT 10
    ");

    echo "<h3>Duplicate GUIDs Found:</h3>";
    if (empty($duplicate_guids)) {
        echo "<p>No duplicate GUIDs found.</p>";
    } else {
        echo "<ul>";
        foreach ($duplicate_guids as $dup) {
            echo "<li>GUID: {$dup->guid}, Count: {$dup->count}</li>";
        }
        echo "</ul>";
    }

    // Check import status
    $import_status = get_option('job_import_status');
    echo "<h3>Import Status:</h3>";
    echo "<pre>" . print_r($import_status, true) . "</pre>";
}

/**
 * Test purge functionality
 */
function test_purge_functionality() {
    global $wpdb;

    echo "<h2>Testing Purge Functionality</h2>";

    // Check processed GUIDs
    $processed_guids = get_option('job_import_processed_guids', []);
    echo "<h3>Processed GUIDs Count:</h3>";
    echo "<p>" . count($processed_guids) . " GUIDs processed in last import</p>";

    // Check existing GUIDs
    $existing_guids = get_option('job_existing_guids', []);
    echo "<h3>Existing GUIDs Count:</h3>";
    echo "<p>" . count($existing_guids) . " existing GUIDs</p>";

    // Check total jobs
    $total_jobs = $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->posts} p
        WHERE p.post_type = 'job'
    ");
    echo "<h3>Total Jobs:</h3>";
    echo "<p>{$total_jobs} jobs in database</p>";

    // Check jobs with GUIDs
    $jobs_with_guids = $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'guid'
        WHERE p.post_type = 'job'
    ");
    echo "<h3>Jobs with GUIDs:</h3>";
    echo "<p>{$jobs_with_guids} jobs have GUIDs</p>";

    // Check import status
    $import_status = get_option('job_import_status');
    echo "<h3>Import Status:</h3>";
    echo "<pre>" . print_r($import_status, true) . "</pre>";

    // Check if purge would be blocked
    if ($import_status) {
        $processed = $import_status['processed'] ?? 0;
        $total = $import_status['total'] ?? 0;
        $complete = $import_status['complete'] ?? false;

        echo "<h3>Purge Block Check:</h3>";
        if ($processed < $total || $total == 0) {
            echo "<p style='color: red;'>❌ Purge would be BLOCKED: Import not complete (processed: {$processed}, total: {$total})</p>";
        } else {
            echo "<p style='color: green;'>✅ Purge would be ALLOWED: Import complete</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ Purge would be BLOCKED: No import status found</p>";
    }
}

/**
 * Manual cleanup function for testing
 */
function manual_cleanup_duplicates() {
    global $wpdb;

    echo "<h2>Manual Cleanup Test</h2>";

    $deleted_count = 0;
    $logs = [];

    // Get all jobs grouped by GUID
    $jobs_by_guid = $wpdb->get_results("
        SELECT p.ID, p.post_status, p.post_modified, pm.meta_value AS guid
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'guid'
        WHERE p.post_type = 'job'
        AND p.post_status IN ('publish', 'draft')
        AND pm.meta_value IS NOT NULL
        ORDER BY pm.meta_value, p.post_modified DESC
    ");

    $guid_groups = [];
    foreach ($jobs_by_guid as $job) {
        $guid_groups[$job->guid][] = $job;
    }

    foreach ($guid_groups as $guid => $jobs) {
        if (count($jobs) > 1) {
            echo "<h4>Processing GUID: {$guid}</h4>";
            echo "<ul>";

            // Sort by modification date (newest first)
            usort($jobs, function($a, $b) {
                return strtotime($b->post_modified) - strtotime($a->post_modified);
            });

            // Keep the first (newest) job as published
            $keep_job = $jobs[0];
            if ($keep_job->post_status !== 'publish') {
                $wpdb->update($wpdb->posts, ['post_status' => 'publish'], ['ID' => $keep_job->ID]);
                echo "<li>✅ Republished newest job ID: {$keep_job->ID}</li>";
            } else {
                echo "<li>✅ Keeping published job ID: {$keep_job->ID}</li>";
            }

            // Delete all others
            for ($i = 1; $i < count($jobs); $i++) {
                $delete_job = $jobs[$i];
                wp_delete_post($delete_job->ID, true); // Force delete
                $deleted_count++;
                echo "<li>🗑️ Deleted duplicate job ID: {$delete_job->ID}</li>";
                $logs[] = "Deleted duplicate job ID: {$delete_job->ID} GUID: {$guid}";
            }
            echo "</ul>";
        }
    }

    echo "<h3>Summary:</h3>";
    echo "<p>Deleted {$deleted_count} duplicate jobs</p>";

    if (!empty($logs)) {
        echo "<h4>Logs:</h4>";
        echo "<ul>";
        foreach ($logs as $log) {
            echo "<li>{$log}</li>";
        }
        echo "</ul>";
    }
}

// Run tests if this file is accessed directly
if (isset($_GET['test'])) {
    $test_type = $_GET['test'];

    switch ($test_type) {
        case 'cleanup':
            test_cleanup_duplicates();
            break;
        case 'purge':
            test_purge_functionality();
            break;
        case 'manual_cleanup':
            manual_cleanup_duplicates();
            break;
        default:
            echo "<h2>Available Tests:</h2>";
            echo "<ul>";
            echo "<li><a href='?test=cleanup'>Test Cleanup Duplicates</a></li>";
            echo "<li><a href='?test=purge'>Test Purge Functionality</a></li>";
            echo "<li><a href='?test=manual_cleanup'>Manual Cleanup Test</a></li>";
            echo "</ul>";
    }
} else {
    echo "<h1>Button Functionality Test</h1>";
    echo "<p><a href='?test=cleanup'>Test Cleanup Duplicates</a></p>";
    echo "<p><a href='?test=purge'>Test Purge Functionality</a></p>";
    echo "<p><a href='?test=manual_cleanup'>Manual Cleanup Test</a></p>";
}