<?php
/**
 * Base test case for puntWork tests
 */

namespace Puntwork;

use WP_UnitTestCase;

/**
 * Base test case class
 */
class TestCase extends WP_UnitTestCase {

    /**
     * Set up test environment
     */
    public function setUp(): void {
        parent::setUp();

        // Load plugin includes for testing
        if (function_exists('Puntwork\\setup_job_import')) {
            \Puntwork\setup_job_import();
        }

        // Clean up any existing test data
        $this->cleanUpTestData();

        // Set up test constants if needed
        if (!defined('PUNTWORK_VERSION')) {
            define('PUNTWORK_VERSION', '1.0.7');
        }
        if (!defined('PUNTWORK_PATH')) {
            define('PUNTWORK_PATH', dirname(dirname(__DIR__)) . '/');
        }
        if (!defined('PUNTWORK_URL')) {
            define('PUNTWORK_URL', 'http://example.com/wp-content/plugins/puntwork/');
        }
        if (!defined('PUNTWORK_LOGS')) {
            define('PUNTWORK_LOGS', PUNTWORK_PATH . 'logs/import.log');
        }
    }

    /**
     * Tear down test environment
     */
    public function tearDown(): void {
        $this->cleanUpTestData();
        parent::tearDown();
    }

    /**
     * Clean up test data
     */
    protected function cleanUpTestData() {
        // Delete test posts
        $test_posts = get_posts([
            'post_type' => ['job', 'job-feed'],
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => '_test_post',
                    'value' => '1',
                    'compare' => '='
                ]
            ]
        ]);

        foreach ($test_posts as $post) {
            wp_delete_post($post->ID, true);
        }

        // Clean up test options
        delete_option('job_import_status');
        delete_option('job_import_progress');
        delete_option('job_import_batch_size');
        delete_option('job_existing_guids');
        delete_option('job_import_processed_guids');
        delete_option('job_import_last_run');

        // Clean up scheduled events
        wp_clear_scheduled_hook('job_import_cron');

        // Clean up transients
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_job_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_job_%'");
    }

    /**
     * Create a test job post
     */
    protected function createTestJob($args = []) {
        $defaults = [
            'post_title' => 'Test Job',
            'post_content' => 'Test job description',
            'post_status' => 'publish',
            'post_type' => 'job',
            'meta_input' => [
                'guid' => 'test-guid-' . uniqid(),
                'company' => 'Test Company',
                'location' => 'Test City',
                '_test_post' => '1'
            ]
        ];

        $args = array_merge($defaults, $args);
        return wp_insert_post($args);
    }

    /**
     * Create a test job feed post
     */
    protected function createTestJobFeed($args = []) {
        $defaults = [
            'post_title' => 'Test Feed',
            'post_content' => 'Test feed description',
            'post_status' => 'publish',
            'post_type' => 'job-feed',
            'meta_input' => [
                'feed_url' => 'http://example.com/feed.xml',
                'feed_format' => 'xml',
                '_test_post' => '1'
            ]
        ];

        $args = array_merge($defaults, $args);
        return wp_insert_post($args);
    }

    /**
     * Create a temporary file with content
     */
    protected function createTempFile($content, $extension = 'jsonl') {
        $temp_file = tempnam(sys_get_temp_dir(), 'puntwork_test_') . '.' . $extension;
        file_put_contents($temp_file, $content);
        $this->tempFiles[] = $temp_file;
        return $temp_file;
    }

    /**
     * Clean up temporary files
     */
    protected function cleanTempFiles() {
        if (!empty($this->tempFiles)) {
            foreach ($this->tempFiles as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
            $this->tempFiles = [];
        }
    }

    private $tempFiles = [];
}