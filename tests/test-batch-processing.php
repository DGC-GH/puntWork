<?php
/**
 * Tests for batch processing functionality
 */

namespace Puntwork;

use Puntwork\TestCase;

/**
 * Batch processing test class
 */
class BatchProcessingTest extends TestCase {

    /**
     * Test batch processing core functions exist
     */
    public function test_batch_functions_exist() {
        $this->assertTrue(function_exists('Puntwork\\process_batch'));
        $this->assertTrue(function_exists('Puntwork\\get_batch_size'));
        $this->assertTrue(function_exists('Puntwork\\validate_batch_data'));
    }

    /**
     * Test get_batch_size function
     */
    public function test_get_batch_size() {
        // Test default batch size
        $this->assertEquals(20, get_batch_size());

        // Test with option set
        update_option('job_import_batch_size', 100);
        $this->assertEquals(100, get_batch_size());

        // Clean up
        delete_option('job_import_batch_size');
    }

    /**
     * Test validate_batch_data with valid data
     */
    public function test_validate_batch_data_valid() {
        $valid_data = [
            'guid' => 'test-guid-123',
            'title' => 'Test Job Title',
            'company' => 'Test Company',
            'location' => 'Test City'
        ];

        $result = validate_batch_data($valid_data);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /**
     * Test validate_batch_data with invalid data
     */
    public function test_validate_batch_data_invalid() {
        $invalid_data = [
            'title' => '', // Empty title
            'company' => 'Test Company',
            // Missing required fields
        ];

        $result = validate_batch_data($invalid_data);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertContains('Missing required field: guid', $result['errors']);
        $this->assertContains('Missing required field: title', $result['errors']);
    }

    /**
     * Test process_batch with empty data
     */
    public function test_process_batch_empty() {
        $setup = [
            'acf_fields' => [],
            'zero_empty_fields' => [],
            'start_time' => microtime(true),
            'json_path' => '/nonexistent/file.jsonl',
            'total' => 0,
            'processed_guids' => [],
            'start_index' => 0
        ];

        $result = process_batch($setup, 0, 10);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['processed']);
    }

    /**
     * Test process_batch with mock data
     */
    public function test_process_batch_with_data() {
        // Create test JSONL file
        $test_data = [
            [
                'guid' => 'test-job-1',
                'title' => 'Test Job 1',
                'company' => 'Test Company 1',
                'location' => 'Test City 1',
                'description' => 'Test description 1'
            ],
            [
                'guid' => 'test-job-2',
                'title' => 'Test Job 2',
                'company' => 'Test Company 2',
                'location' => 'Test City 2',
                'description' => 'Test description 2'
            ]
        ];

        $jsonl_content = '';
        foreach ($test_data as $item) {
            $jsonl_content .= json_encode($item) . "\n";
        }

        $temp_file = $this->createTempFile($jsonl_content);
        $original_path = PUNTWORK_PATH . 'feeds/combined-jobs.jsonl';

        $feeds_dir = dirname($original_path);
        if (!file_exists($feeds_dir)) {
            mkdir($feeds_dir, 0755, true);
        }
        copy($temp_file, $original_path);

        $setup = [
            'acf_fields' => ['company', 'location'],
            'zero_empty_fields' => [],
            'start_time' => microtime(true),
            'json_path' => $original_path,
            'total' => 2,
            'processed_guids' => [],
            'start_index' => 0
        ];

        $result = process_batch($setup, 0, 2);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['processed']);

        // Check that jobs were created
        $jobs = get_posts([
            'post_type' => 'job',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'guid',
                    'value' => ['test-job-1', 'test-job-2'],
                    'compare' => 'IN'
                ]
            ]
        ]);

        $this->assertCount(2, $jobs);

        // Clean up
        unlink($original_path);
        foreach ($jobs as $job) {
            wp_delete_post($job->ID, true);
        }
    }

    /**
     * Test batch processing handles duplicates
     */
    public function test_process_batch_duplicates() {
        // Create existing job
        $existing_job_id = $this->createTestJob([
            'meta_input' => [
                'guid' => 'duplicate-guid',
                'company' => 'Original Company'
            ]
        ]);

        // Create JSONL with duplicate
        $test_data = [
            [
                'guid' => 'duplicate-guid',
                'title' => 'Updated Job Title',
                'company' => 'Updated Company',
                'location' => 'Updated City'
            ]
        ];

        $jsonl_content = json_encode($test_data[0]) . "\n";
        $temp_file = $this->createTempFile($jsonl_content);
        $original_path = PUNTWORK_PATH . 'feeds/combined-jobs.jsonl';

        $feeds_dir = dirname($original_path);
        if (!file_exists($feeds_dir)) {
            mkdir($feeds_dir, 0755, true);
        }
        copy($temp_file, $original_path);

        $setup = [
            'acf_fields' => ['company', 'location'],
            'zero_empty_fields' => [],
            'start_time' => microtime(true),
            'json_path' => $original_path,
            'total' => 1,
            'processed_guids' => [],
            'start_index' => 0
        ];

        $result = process_batch($setup, 0, 1);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['processed']);

        // Check that job was updated, not duplicated
        $jobs = get_posts([
            'post_type' => 'job',
            'meta_query' => [
                [
                    'key' => 'guid',
                    'value' => 'duplicate-guid',
                    'compare' => '='
                ]
            ]
        ]);

        $this->assertCount(1, $jobs);
        $this->assertEquals($existing_job_id, $jobs[0]->ID);

        // Check updated data
        $this->assertEquals('Updated Job Title', $jobs[0]->post_title);
        $this->assertEquals('Updated Company', get_post_meta($jobs[0]->ID, 'company', true));

        // Clean up
        unlink($original_path);
        wp_delete_post($existing_job_id, true);
    }
}