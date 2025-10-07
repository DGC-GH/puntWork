<?php
/**
 * Tests for utilities functionality
 */

namespace Puntwork;

use Puntwork\TestCase;

/**
 * Utilities test class
 */
class UtilitiesTest extends TestCase {

    /**
     * Test utility functions exist
     */
    public function test_utility_functions_exist() {
        $this->assertTrue(function_exists('Puntwork\\handle_duplicates'));
        $this->assertTrue(function_exists('Puntwork\\clean_job_data'));
        $this->assertTrue(function_exists('Puntwork\\generate_unique_guid'));
        $this->assertTrue(function_exists('Puntwork\\gzip_compress_file'));
        $this->assertTrue(function_exists('Puntwork\\gzip_decompress_file'));
    }

    /**
     * Test handle_duplicates with existing job
     */
    public function test_handle_duplicates_existing() {
        // Create existing job
        $existing_job_id = $this->createTestJob([
            'meta_input' => [
                'guid' => 'duplicate-test-guid',
                'company' => 'Original Company'
            ]
        ]);

        $new_data = [
            'guid' => 'duplicate-test-guid',
            'title' => 'Updated Title',
            'company' => 'Updated Company'
        ];

        $result = handle_duplicates($new_data);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('action', $result);
        $this->assertArrayHasKey('existing_post_id', $result);
        $this->assertEquals('update', $result['action']);
        $this->assertEquals($existing_job_id, $result['existing_post_id']);

        // Clean up
        wp_delete_post($existing_job_id, true);
    }

    /**
     * Test handle_duplicates with new job
     */
    public function test_handle_duplicates_new() {
        $new_data = [
            'guid' => 'new-unique-guid-' . uniqid(),
            'title' => 'New Job Title',
            'company' => 'New Company'
        ];

        $result = handle_duplicates($new_data);

        $this->assertIsArray($result);
        $this->assertEquals('create', $result['action']);
        $this->assertFalse($result['existing_post_id']);
    }

    /**
     * Test clean_job_data
     */
    public function test_clean_job_data() {
        $dirty_data = [
            'title' => '  Test Job Title  ',
            'company' => 'Test Company  ',
            'description' => '<p>Test description</p><script>alert("xss")</script>',
            'salary' => '50,000 - 60,000 EUR',
            'email' => 'test@example.com'
        ];

        $clean_data = clean_job_data($dirty_data);

        $this->assertIsArray($clean_data);
        $this->assertEquals('Test Job Title', $clean_data['title']);
        $this->assertEquals('Test Company', $clean_data['company']);
        $this->assertStringNotContainsString('<script>', $clean_data['description']);
        $this->assertStringContainsString('Test description', $clean_data['description']);
        $this->assertEquals('50000 - 60000 EUR', $clean_data['salary']);
    }

    /**
     * Test generate_unique_guid
     */
    public function test_generate_unique_guid() {
        $guid1 = generate_unique_guid();
        $guid2 = generate_unique_guid();

        $this->assertIsString($guid1);
        $this->assertIsString($guid2);
        $this->assertNotEquals($guid1, $guid2);
        $this->assertGreaterThan(10, strlen($guid1)); // Should be reasonably long
    }

    /**
     * Test gzip compression and decompression
     */
    public function test_gzip_compression() {
        $test_content = 'This is test content for gzip compression and decompression.';
        $temp_file = $this->createTempFile($test_content);
        $compressed_file = $temp_file . '.gz';

        // Test compression
        $compress_result = gzip_compress_file($temp_file, $compressed_file);
        $this->assertTrue($compress_result);
        $this->assertFileExists($compressed_file);

        // Test decompression
        $decompressed_file = $temp_file . '.decompressed';
        $decompress_result = gzip_decompress_file($compressed_file, $decompressed_file);
        $this->assertTrue($decompress_result);
        $this->assertFileExists($decompressed_file);

        // Verify content
        $decompressed_content = file_get_contents($decompressed_file);
        $this->assertEquals($test_content, $decompressed_content);

        // Clean up
        unlink($compressed_file);
        unlink($decompressed_file);
    }

    /**
     * Test item inference functions
     */
    public function test_item_inference() {
        $job_data = [
            'title' => 'Senior PHP Developer',
            'description' => 'We are looking for a senior PHP developer with 5+ years experience. Must have knowledge of WordPress, MySQL, and JavaScript.'
        ];

        // Test language inference
        $languages = infer_item_languages($job_data);
        $this->assertIsArray($languages);
        $this->assertContains('php', $languages);

        // Test skill inference
        $skills = infer_item_skills($job_data);
        $this->assertIsArray($skills);
        $this->assertContains('wordpress', $skills);
        $this->assertContains('mysql', $skills);
        $this->assertContains('javascript', $skills);

        // Test experience inference
        $experience = infer_item_experience($job_data);
        $this->assertIsArray($experience);
        $this->assertArrayHasKey('level', $experience);
        $this->assertEquals('senior', $experience['level']);
    }

    /**
     * Test shortcode functionality
     */
    public function test_shortcode_functionality() {
        // Test that shortcode is registered
        global $shortcode_tags;
        $this->assertArrayHasKey('puntwork_jobs', $shortcode_tags);

        // Test shortcode output
        $output = do_shortcode('[puntwork_jobs limit="5"]');
        $this->assertIsString($output);
        // Should contain some HTML structure
        $this->assertStringContainsString('<div', $output);
    }

    /**
     * Test heartbeat control
     */
    public function test_heartbeat_control() {
        // Test heartbeat settings
        $settings = get_heartbeat_settings();

        $this->assertIsArray($settings);
        $this->assertArrayHasKey('enabled', $settings);
        $this->assertArrayHasKey('frequency', $settings);

        // Test updating heartbeat settings
        $result = update_heartbeat_settings(['enabled' => false, 'frequency' => 60]);
        $this->assertTrue($result);

        $updated_settings = get_heartbeat_settings();
        $this->assertFalse($updated_settings['enabled']);
        $this->assertEquals(60, $updated_settings['frequency']);
    }

    /**
     * Test utility helpers
     */
    public function test_utility_helpers() {
        // Test array functions
        $array = ['a' => 1, 'b' => 2, 'c' => null, 'd' => ''];
        $filtered = filter_empty_values($array);
        $this->assertEquals(['a' => 1, 'b' => 2], $filtered);

        // Test string functions
        $this->assertTrue(is_valid_email('test@example.com'));
        $this->assertFalse(is_valid_email('invalid-email'));

        // Test URL validation
        $this->assertTrue(is_valid_url('https://example.com'));
        $this->assertFalse(is_valid_url('not-a-url'));

        // Test date formatting
        $timestamp = strtotime('2023-01-01');
        $formatted = format_date_for_display($timestamp);
        $this->assertIsString($formatted);
        $this->assertStringContains('2023', $formatted);
    }

    /**
     * Test logger functionality
     */
    public function test_logger_functionality() {
        // Test logger class exists
        $this->assertTrue(class_exists('Puntwork\\PuntWorkLogger'));

        // Test logging
        PuntWorkLogger::info('Test info message', ['context' => 'test']);
        PuntWorkLogger::error('Test error message', ['context' => 'test']);

        // Check that log file exists
        $this->assertFileExists(PUNTWORK_LOGS);
        $log_content = file_get_contents(PUNTWORK_LOGS);
        $this->assertStringContains('Test info message', $log_content);
        $this->assertStringContains('Test error message', $log_content);
    }
}