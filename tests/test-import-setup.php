<?php
/**
 * Tests for import setup functionality
 */

namespace Puntwork;

use Puntwork\TestCase;

/**
 * Import setup test class
 */
class ImportSetupTest extends TestCase {

    /**
     * Test prepare_import_setup with no file
     */
    public function test_prepare_import_setup_no_file() {
        $result = prepare_import_setup();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertEquals('JSONL file not found', $result['message']);
    }

    /**
     * Test prepare_import_setup with empty file
     */
    public function test_prepare_import_setup_empty_file() {
        // Create empty temp file
        $temp_file = $this->createTempFile('');
        $original_path = PUNTWORK_PATH . 'feeds/combined-jobs.jsonl';

        // Temporarily replace the path
        if (!defined('PUNTWORK_PATH')) {
            define('PUNTWORK_PATH', dirname(dirname(__DIR__)) . '/');
        }

        // Create the feeds directory if it doesn't exist
        $feeds_dir = dirname($original_path);
        if (!file_exists($feeds_dir)) {
            mkdir($feeds_dir, 0755, true);
        }

        // Copy temp file to expected location
        copy($temp_file, $original_path);

        $result = prepare_import_setup();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['total']);
        $this->assertTrue($result['complete']);

        // Clean up
        unlink($original_path);
    }

    /**
     * Test prepare_import_setup with valid data
     */
    public function test_prepare_import_setup_valid_data() {
        // Create test JSONL content
        $jsonl_content = json_encode(['guid' => 'test-1', 'title' => 'Test Job 1']) . "\n" .
                        json_encode(['guid' => 'test-2', 'title' => 'Test Job 2']) . "\n";

        $temp_file = $this->createTempFile($jsonl_content);
        $original_path = PUNTWORK_PATH . 'feeds/combined-jobs.jsonl';

        // Create directory and copy file
        $feeds_dir = dirname($original_path);
        if (!file_exists($feeds_dir)) {
            mkdir($feeds_dir, 0755, true);
        }
        copy($temp_file, $original_path);

        $result = prepare_import_setup();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('json_path', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('start_index', $result);
        $this->assertEquals(2, $result['total']);
        $this->assertEquals($original_path, $result['json_path']);

        // Clean up
        unlink($original_path);
    }

    /**
     * Test get_json_item_count
     */
    public function test_get_json_item_count() {
        // Test empty file
        $temp_file = $this->createTempFile('');
        $this->assertEquals(0, get_json_item_count($temp_file));

        // Test with valid JSONL
        $jsonl_content = json_encode(['test' => 'data1']) . "\n" .
                        json_encode(['test' => 'data2']) . "\n" .
                        "invalid json line\n" .
                        json_encode(['test' => 'data3']) . "\n";

        $temp_file = $this->createTempFile($jsonl_content);
        $this->assertEquals(3, get_json_item_count($temp_file)); // Should skip invalid line
    }

    /**
     * Test prepare_import_setup with batch start
     */
    public function test_prepare_import_setup_batch_start() {
        // Create test data
        $jsonl_content = json_encode(['guid' => 'test-1']) . "\n" .
                        json_encode(['guid' => 'test-2']) . "\n" .
                        json_encode(['guid' => 'test-3']) . "\n";

        $temp_file = $this->createTempFile($jsonl_content);
        $original_path = PUNTWORK_PATH . 'feeds/combined-jobs.jsonl';

        $feeds_dir = dirname($original_path);
        if (!file_exists($feeds_dir)) {
            mkdir($feeds_dir, 0755, true);
        }
        copy($temp_file, $original_path);

        // Test with batch start
        $result = prepare_import_setup(1);

        $this->assertIsArray($result);
        $this->assertEquals(1, $result['start_index']);

        // Clean up
        unlink($original_path);
    }

    /**
     * Test prepare_import_setup caches existing GUIDs
     */
    public function test_prepare_import_setup_caches_guids() {
        // Create test job
        $job_id = $this->createTestJob(['meta_input' => ['guid' => 'existing-guid']]);

        // Create test JSONL
        $jsonl_content = json_encode(['guid' => 'new-guid']) . "\n";
        $temp_file = $this->createTempFile($jsonl_content);
        $original_path = PUNTWORK_PATH . 'feeds/combined-jobs.jsonl';

        $feeds_dir = dirname($original_path);
        if (!file_exists($feeds_dir)) {
            mkdir($feeds_dir, 0755, true);
        }
        copy($temp_file, $original_path);

        // Clear existing cache
        delete_option('job_existing_guids');

        $result = prepare_import_setup();

        // Check that GUIDs are cached
        $cached_guids = get_option('job_existing_guids');
        $this->assertNotFalse($cached_guids);
        $this->assertIsArray($cached_guids);

        // Clean up
        unlink($original_path);
        wp_delete_post($job_id, true);
    }
}