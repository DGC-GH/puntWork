<?php
/**
 * Tests for API functionality
 */

namespace Puntwork;

use Puntwork\TestCase;
use WP_REST_Request;

/**
 * API test class
 */
class ApiTest extends TestCase {

    /**
     * Test API functions exist
     */
    public function test_api_functions_exist() {
        $this->assertTrue(function_exists('Puntwork\\handle_feed_processing'));
        $this->assertTrue(function_exists('Puntwork\\handle_import_control'));
        $this->assertTrue(function_exists('Puntwork\\handle_purge_request'));
        $this->assertTrue(function_exists('Puntwork\\handle_ajax_feed_processing'));
        $this->assertTrue(function_exists('Puntwork\\handle_ajax_import_control'));
    }

    /**
     * Test AJAX handlers are hooked
     */
    public function test_ajax_handlers_hooked() {
        // Simulate wp_ajax hooks
        do_action('wp_ajax_puntwork_feed_processing');
        do_action('wp_ajax_puntwork_import_control');
        do_action('wp_ajax_puntwork_purge');

        // Check that actions are hooked (this is more of a structure test)
        $this->assertTrue(has_action('wp_ajax_puntwork_feed_processing'));
        $this->assertTrue(has_action('wp_ajax_puntwork_import_control'));
        $this->assertTrue(has_action('wp_ajax_puntwork_purge'));
    }

    /**
     * Test handle_import_control with start action
     */
    public function test_handle_import_control_start() {
        // Clear any existing status
        delete_option('job_import_status');

        $request = new WP_REST_Request('POST', '/puntwork/v1/import-control');
        $request->set_param('action', 'start');

        $response = handle_import_control($request);

        $this->assertInstanceOf('WP_REST_Response', $response);
        $data = $response->get_data();

        $this->assertArrayHasKey('success', $data);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('message', $data);
        $this->assertStringContains('started', $data['message']);
    }

    /**
     * Test handle_import_control with cancel action
     */
    public function test_handle_import_control_cancel() {
        // Set up a running import
        update_option('job_import_status', [
            'start_time' => time(),
            'processed' => 10,
            'total' => 100,
            'complete' => false
        ]);

        $request = new WP_REST_Request('POST', '/puntwork/v1/import-control');
        $request->set_param('action', 'cancel');

        $response = handle_import_control($request);

        $this->assertInstanceOf('WP_REST_Response', $response);
        $data = $response->get_data();

        $this->assertArrayHasKey('success', $data);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('message', $data);
        $this->assertStringContains('cancelled', $data['message']);
    }

    /**
     * Test handle_import_control with invalid action
     */
    public function test_handle_import_control_invalid_action() {
        $request = new WP_REST_Request('POST', '/puntwork/v1/import-control');
        $request->set_param('action', 'invalid_action');

        $response = handle_import_control($request);

        $this->assertInstanceOf('WP_Error', $response);
        $this->assertEquals('invalid_action', $response->get_error_code());
    }

    /**
     * Test handle_feed_processing
     */
    public function test_handle_feed_processing() {
        $request = new WP_REST_Request('POST', '/puntwork/v1/feed-processing');
        $request->set_param('feed_url', 'http://example.com/feed.xml');
        $request->set_param('feed_format', 'xml');

        $response = handle_feed_processing($request);

        $this->assertInstanceOf('WP_REST_Response', $response);
        $data = $response->get_data();

        $this->assertArrayHasKey('success', $data);
        // May succeed or fail depending on feed availability
        $this->assertArrayHasKey('message', $data);
    }

    /**
     * Test handle_purge_request
     */
    public function test_handle_purge_request() {
        // Create some test jobs
        $job_ids = [];
        for ($i = 0; $i < 3; $i++) {
            $job_ids[] = $this->createTestJob();
        }

        $request = new WP_REST_Request('POST', '/puntwork/v1/purge');
        $request->set_param('confirm', 'yes');

        $response = handle_purge_request($request);

        $this->assertInstanceOf('WP_REST_Response', $response);
        $data = $response->get_data();

        $this->assertArrayHasKey('success', $data);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('message', $data);
        $this->assertStringContains('purged', $data['message']);

        // Clean up
        foreach ($job_ids as $job_id) {
            wp_delete_post($job_id, true);
        }
    }

    /**
     * Test handle_purge_request without confirmation
     */
    public function test_handle_purge_request_no_confirm() {
        $request = new WP_REST_Request('POST', '/puntwork/v1/purge');
        // No confirm parameter

        $response = handle_purge_request($request);

        $this->assertInstanceOf('WP_Error', $response);
        $this->assertEquals('confirmation_required', $response->get_error_code());
    }

    /**
     * Test AJAX handlers return proper responses
     */
    public function test_ajax_handlers_response() {
        // Mock AJAX request
        $_POST['action'] = 'puntwork_import_control';
        $_POST['import_action'] = 'status';

        // Capture output
        ob_start();
        handle_ajax_import_control();
        $output = ob_get_clean();

        // Should output JSON
        $this->assertJson($output);
        $data = json_decode($output, true);

        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('status', $data);
    }

    /**
     * Test feed processing with invalid URL
     */
    public function test_feed_processing_invalid_url() {
        $request = new WP_REST_Request('POST', '/puntwork/v1/feed-processing');
        $request->set_param('feed_url', 'not-a-valid-url');
        $request->set_param('feed_format', 'xml');

        $response = handle_feed_processing($request);

        $this->assertInstanceOf('WP_REST_Response', $response);
        $data = $response->get_data();

        $this->assertArrayHasKey('success', $data);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('message', $data);
    }

    /**
     * Test import status retrieval
     */
    public function test_import_status_retrieval() {
        // Set up test status
        $test_status = [
            'total' => 100,
            'processed' => 50,
            'published' => 40,
            'updated' => 5,
            'skipped' => 5,
            'complete' => false,
            'start_time' => time() - 300,
            'last_update' => time()
        ];
        update_option('job_import_status', $test_status);

        $request = new WP_REST_Request('GET', '/puntwork/v1/import-status');

        // Assuming there's a status endpoint
        if (function_exists('handle_import_status')) {
            $response = handle_import_status($request);

            $this->assertInstanceOf('WP_REST_Response', $response);
            $data = $response->get_data();

            $this->assertArrayHasKey('status', $data);
            $this->assertEquals(50, $data['status']['processed']);
        }
    }

    /**
     * Test API endpoints are registered
     */
    public function test_api_endpoints_registered() {
        // This would require REST API to be initialized
        // For now, just check that the registration functions exist
        $this->assertTrue(function_exists('Puntwork\\register_api_routes'));
    }

    /**
     * Test batch processing API
     */
    public function test_batch_processing_api() {
        $request = new WP_REST_Request('POST', '/puntwork/v1/batch-process');
        $request->set_param('batch_size', 10);
        $request->set_param('start_index', 0);

        if (function_exists('handle_batch_process')) {
            $response = handle_batch_process($request);

            $this->assertInstanceOf('WP_REST_Response', $response);
            $data = $response->get_data();

            $this->assertArrayHasKey('success', $data);
        }
    }
}