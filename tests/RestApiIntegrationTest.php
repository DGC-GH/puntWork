<?php

/**
 * Integration Tests for puntWork REST API Endpoints
 * Tests API endpoints with realistic data and scenarios
 */

namespace Puntwork;

use PHPUnit\Framework\TestCase;

/**
 * REST API Integration Test Suite
 * Tests all REST API endpoints with comprehensive scenarios
 */
class RestApiIntegrationTest extends TestCase
{

    private $api_key;
    private $test_job_id;

    protected function setUp(): void
    {
        parent::setUp();

        // Skip tests if not in WordPress environment
        if (! $this->isWordpressEnvironment() ) {
            $this->markTestSkipped('Skipping API integration tests - not in WordPress environment');
            return;
        }

        // Clear any existing rate limiting to ensure test isolation
        $client_ip      = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $rate_limit_key = 'api_key_attempts_' . $client_ip;
        delete_transient($rate_limit_key);

        // Set up test API key
        $this->api_key = 'test_api_key_integration_' . time();

        // Create a test job post for testing
        $this->test_job_id = wp_insert_post(
            array(
            'post_type'    => 'job',
            'post_title'   => 'Test Integration Job',
            'post_content' => 'This is a test job for API integration testing',
            'post_status'  => 'publish',
            'meta_input'   => array(
                    'guid'     => 'test-integration-guid-' . time(),
                    'company'  => 'Test Company',
                    'location' => 'Test City, Test State',
                    'salary'   => '$50,000 - $70,000',
            ),
            )
        );

        $this->assertIsInt($this->test_job_id, 'Failed to create test job post');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up test data
        if ($this->test_job_id && $this->isWordpressEnvironment() ) {
            wp_delete_post($this->test_job_id, true);
        }
    }

    /**
     * Check if we're in a proper WordPress environment
     */
    private function isWordpressEnvironment()
    {
        global $wpdb;
        return isset($wpdb) && $wpdb instanceof \wpdb;
    }

    /**
     * Test API key verification with valid key
     */
    public function testApiKeyVerificationValid()
    {
        $request = new \WP_REST_Request('GET', '/puntwork/v1/analytics');
        $request->set_param('api_key', $this->api_key);

        // Mock the stored API key
        update_option('puntwork_api_key', $this->api_key);

        $result = verify_api_key($request);

        $this->assertTrue($result, 'Valid API key should be accepted');
    }

    /**
     * Test API key verification with invalid key
     */
    public function testApiKeyVerificationInvalid()
    {
        $request = new \WP_REST_Request('GET', '/puntwork/v1/analytics');
        $request->set_param('api_key', 'invalid_key');

        update_option('puntwork_api_key', $this->api_key);

        $result = verify_api_key($request);

        $this->assertInstanceOf(\WP_Error::class, $result, 'Invalid API key should return WP_Error');
        $this->assertEquals('invalid_api_key', $result->get_error_code());
    }

    /**
     * Test API key verification with missing key
     */
    public function testApiKeyVerificationMissing()
    {
        $request = new \WP_REST_Request('GET', '/puntwork/v1/analytics');

        $result = verify_api_key($request);

        $this->assertInstanceOf(\WP_Error::class, $result, 'Missing API key should return WP_Error');
        $this->assertEquals('missing_api_key', $result->get_error_code());
    }

    /**
     * Test analytics endpoint
     */
    public function testAnalyticsEndpoint()
    {
        update_option('puntwork_api_key', $this->api_key);

        $request = new \WP_REST_Request('GET', '/puntwork/v1/analytics');
        $request->set_param('api_key', $this->api_key);
        $request->set_param('period', '7days');

        $response = handle_get_analytics($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('period', $data);
        $this->assertEquals('7days', $data['period']);
    }

    /**
     * Test feeds endpoint
     */
    public function testFeedsEndpoint()
    {
        update_option('puntwork_api_key', $this->api_key);

        $request = new \WP_REST_Request('GET', '/puntwork/v1/feeds');
        $request->set_param('api_key', $this->api_key);

        $response = handle_get_feeds($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertIsArray($data['data']);
    }

    /**
     * Test feed details endpoint with valid feed
     */
    public function testFeedDetailsEndpointValid()
    {
        update_option('puntwork_api_key', $this->api_key);

        // Get first available feed key
        $feeds    = get_feeds();
        $feed_key = ! empty($feeds) ? key($feeds) : 'test_feed';

        $request = new \WP_REST_Request('GET', '/puntwork/v1/feeds/' . $feed_key);
        $request->set_param('api_key', $this->api_key);
        $request->set_param('feed_key', $feed_key);

        $response = handle_get_feed_details($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);

        if (! empty($feeds) ) {
            $this->assertEquals(200, $response->get_status());
            $data = $response->get_data();
            $this->assertTrue($data['success']);
            $this->assertArrayHasKey('data', $data);
            $this->assertEquals($feed_key, $data['data']['key']);
        } else {
            // If no feeds configured, should return 404
            $this->assertEquals(404, $response->get_status());
        }
    }

    /**
     * Test feed details endpoint with invalid feed
     */
    public function testFeedDetailsEndpointInvalid()
    {
        update_option('puntwork_api_key', $this->api_key);

        $request = new \WP_REST_Request('GET', '/puntwork/v1/feeds/nonexistent_feed');
        $request->set_param('api_key', $this->api_key);
        $request->set_param('feed_key', 'nonexistent_feed');

        $response = handle_get_feed_details($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertEquals(404, $response->get_status());

        $data = $response->get_data();
        $this->assertFalse($data['success']);
        $this->assertEquals('Feed not found', $data['message']);
    }

    /**
     * Test performance endpoint
     */
    public function testPerformanceEndpoint()
    {
        update_option('puntwork_api_key', $this->api_key);

        $request = new \WP_REST_Request('GET', '/puntwork/v1/performance');
        $request->set_param('api_key', $this->api_key);
        $request->set_param('period', '7days');

        $response = handle_get_performance($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('current_snapshot', $data);
    }

    /**
     * Test jobs endpoint
     */
    public function testJobsEndpoint()
    {
        update_option('puntwork_api_key', $this->api_key);

        $request = new \WP_REST_Request('GET', '/puntwork/v1/jobs');
        $request->set_param('api_key', $this->api_key);
        $request->set_param('per_page', 10);
        $request->set_param('page', 1);

        $response = handle_get_jobs($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('pagination', $data);
        $this->assertIsArray($data['data']);
    }

    /**
     * Test jobs endpoint with search
     */
    public function testJobsEndpointWithSearch()
    {
        update_option('puntwork_api_key', $this->api_key);

        $request = new \WP_REST_Request('GET', '/puntwork/v1/jobs');
        $request->set_param('api_key', $this->api_key);
        $request->set_param('search', 'Integration');

        $response = handle_get_jobs($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertIsArray($data['data']);
    }

    /**
     * Test individual job endpoint
     */
    public function testJobEndpoint()
    {
        update_option('puntwork_api_key', $this->api_key);

        $request = new \WP_REST_Request('GET', '/puntwork/v1/jobs/' . $this->test_job_id);
        $request->set_param('api_key', $this->api_key);
        $request->set_param('id', $this->test_job_id);

        $response = handle_get_job($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertEquals($this->test_job_id, $data['data']['id']);
        $this->assertEquals('Test Integration Job', $data['data']['title']);
    }

    /**
     * Test individual job endpoint with invalid ID
     */
    public function testJobEndpointInvalidId()
    {
        update_option('puntwork_api_key', $this->api_key);

        $request = new \WP_REST_Request('GET', '/puntwork/v1/jobs/999999');
        $request->set_param('api_key', $this->api_key);
        $request->set_param('id', 999999);

        $response = handle_get_job($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertEquals(404, $response->get_status());

        $data = $response->get_data();
        $this->assertFalse($data['success']);
        $this->assertEquals('Job not found', $data['message']);
    }

    /**
     * Test bulk operations endpoint - publish operation
     */
    public function testBulkOperationsPublish()
    {
        update_option('puntwork_api_key', $this->api_key);

        // Create a draft job for testing
        $draft_job_id = wp_insert_post(
            array(
            'post_type'   => 'job',
            'post_title'  => 'Draft Test Job',
            'post_status' => 'draft',
            )
        );

        $request = new \WP_REST_Request('POST', '/puntwork/v1/bulk-operations');
        $request->set_param('api_key', $this->api_key);
        $request->set_param('operation', 'publish');
        $request->set_param('job_ids', array( $draft_job_id ));

        $response = handle_bulk_operations($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertEquals(1, $data['data']['successful']);
        $this->assertEquals(0, $data['data']['failed']);

        // Verify job was published
        $post = get_post($draft_job_id);
        $this->assertEquals('publish', $post->post_status);

        // Clean up
        wp_delete_post($draft_job_id, true);
    }

    /**
     * Test bulk operations endpoint - unpublish operation
     */
    public function testBulkOperationsUnpublish()
    {
        update_option('puntwork_api_key', $this->api_key);

        $request = new \WP_REST_Request('POST', '/puntwork/v1/bulk-operations');
        $request->set_param('api_key', $this->api_key);
        $request->set_param('operation', 'unpublish');
        $request->set_param('job_ids', array( $this->test_job_id ));

        $response = handle_bulk_operations($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertEquals(1, $data['data']['successful']);

        // Verify job was unpublished
        $post = get_post($this->test_job_id);
        $this->assertEquals('draft', $post->post_status);
    }

    /**
     * Test bulk operations endpoint - update status operation
     */
    public function testBulkOperationsUpdateStatus()
    {
        update_option('puntwork_api_key', $this->api_key);

        $request = new \WP_REST_Request('POST', '/puntwork/v1/bulk-operations');
        $request->set_param('api_key', $this->api_key);
        $request->set_param('operation', 'update_status');
        $request->set_param('job_ids', array( $this->test_job_id ));
        $request->set_param('status', 'pending');

        $response = handle_bulk_operations($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertEquals(1, $data['data']['successful']);

        // Verify job status was updated
        $post = get_post($this->test_job_id);
        $this->assertEquals('pending', $post->post_status);
    }

    /**
     * Test bulk operations endpoint with invalid operation
     */
    public function testBulkOperationsInvalidOperation()
    {
        update_option('puntwork_api_key', $this->api_key);

        $request = new \WP_REST_Request('POST', '/puntwork/v1/bulk-operations');
        $request->set_param('api_key', $this->api_key);
        $request->set_param('operation', 'invalid_operation');
        $request->set_param('job_ids', array( $this->test_job_id ));

        $response = handle_bulk_operations($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertEquals(0, $data['data']['successful']);
        $this->assertEquals(1, $data['data']['failed']);
    }

    /**
     * Test bulk operations endpoint with empty job IDs
     */
    public function testBulkOperationsEmptyJobIds()
    {
        update_option('puntwork_api_key', $this->api_key);

        $request = new \WP_REST_Request('POST', '/puntwork/v1/bulk-operations');
        $request->set_param('api_key', $this->api_key);
        $request->set_param('operation', 'publish');
        $request->set_param('job_ids', array());

        $response = handle_bulk_operations($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertEquals(400, $response->get_status());

        $data = $response->get_data();
        $this->assertFalse($data['success']);
        $this->assertEquals('Job IDs array is required', $data['message']);
    }

    /**
     * Test health status endpoint
     */
    public function testHealthStatusEndpoint()
    {
        update_option('puntwork_api_key', $this->api_key);

        $request = new \WP_REST_Request('GET', '/puntwork/v1/health');
        $request->set_param('api_key', $this->api_key);

        $response = handle_get_health_status($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('overall_status', $data['data']);
        $this->assertArrayHasKey('summary', $data['data']);
        $this->assertArrayHasKey('feeds', $data['data']);
        $this->assertArrayHasKey('timestamp', $data['data']);
    }

    /**
     * Test import status endpoint
     */
    public function testImportStatusEndpoint()
    {
        update_option('puntwork_api_key', $this->api_key);

        $request = new \WP_REST_Request('GET', '/puntwork/v1/import-status');
        $request->set_param('api_key', $this->api_key);

        $response = handle_get_import_status($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertEquals(200, $response->get_status());

        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('data', $data);
    }

    /**
     * Test trigger import endpoint in test mode
     */
    public function testTriggerImportTestMode()
    {
        update_option('puntwork_api_key', $this->api_key);

        $request = new \WP_REST_Request('POST', '/puntwork/v1/trigger-import');
        $request->set_param('api_key', $this->api_key);
        $request->set_param('test_mode', true);

        $response = handle_trigger_import($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);

        // Response could be 200 (success) or 409 (already running)
        $this->assertContains($response->get_status(), array( 200, 409 ));

        $data = $response->get_data();
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('message', $data);

        if ($response->get_status() === 200 ) {
            $this->assertTrue($data['success']);
        }
    }

    /**
     * Test rate limiting for API key attempts
     */
    public function testApiKeyRateLimiting()
    {
        // Make multiple invalid API key attempts
        for ( $i = 0; $i < 6; $i++ ) {
            $request = new \WP_REST_Request('GET', '/puntwork/v1/analytics');
            $request->set_param('api_key', 'invalid_key_' . $i);

            update_option('puntwork_api_key', $this->api_key);
            $result = verify_api_key($request);
        }

        // Next attempt should be rate limited
        $request = new \WP_REST_Request('GET', '/puntwork/v1/analytics');
        $request->set_param('api_key', 'another_invalid_key');

        $result = verify_api_key($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('rate_limit_exceeded', $result->get_error_code());
    }

    /**
     * Test API key generation functions
     */
    public function testApiKeyGeneration()
    {
        $key1 = generate_api_key();
        $key2 = generate_api_key();

        $this->assertIsString($key1);
        $this->assertEquals(32, strlen($key1));
        $this->assertNotEquals($key1, $key2); // Keys should be unique

        // Test get_or_create_api_key
        delete_option('puntwork_api_key');
        $created_key = get_or_create_api_key();
        $this->assertIsString($created_key);
        $this->assertEquals(32, strlen($created_key));

        // Test that it returns existing key
        $existing_key = get_or_create_api_key();
        $this->assertEquals($created_key, $existing_key);

        // Test regeneration
        $new_key = regenerate_api_key();
        $this->assertIsString($new_key);
        $this->assertEquals(32, strlen($new_key));
        $this->assertNotEquals($created_key, $new_key);
    }
}
