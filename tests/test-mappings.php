<?php
/**
 * Tests for mappings functionality
 */

namespace Puntwork;

use Puntwork\TestCase;

/**
 * Mappings test class
 */
class MappingsTest extends TestCase {

    /**
     * Test mapping functions exist
     */
    public function test_mapping_functions_exist() {
        $this->assertTrue(function_exists('Puntwork\\get_acf_fields'));
        $this->assertTrue(function_exists('Puntwork\\get_zero_empty_fields'));
        $this->assertTrue(function_exists('Puntwork\\map_job_data'));
        $this->assertTrue(function_exists('Puntwork\\map_geographic_data'));
        $this->assertTrue(function_exists('Puntwork\\map_salary_data'));
    }

    /**
     * Test get_acf_fields returns array
     */
    public function test_get_acf_fields() {
        $fields = get_acf_fields();

        $this->assertIsArray($fields);
        $this->assertNotEmpty($fields);

        // Check for common expected fields
        $this->assertContains('company', $fields);
        $this->assertContains('location', $fields);
    }

    /**
     * Test get_zero_empty_fields returns array
     */
    public function test_get_zero_empty_fields() {
        $fields = get_zero_empty_fields();

        $this->assertIsArray($fields);
        // May be empty, that's okay
    }

    /**
     * Test map_job_data with basic data
     */
    public function test_map_job_data_basic() {
        $input_data = [
            'guid' => 'test-guid-123',
            'title' => 'Test Job Title',
            'company' => 'Test Company',
            'location' => 'Test City, Test Country',
            'description' => 'Test job description',
            'salary' => '50000',
            'contract_type' => 'full-time'
        ];

        $mapped_data = map_job_data($input_data);

        $this->assertIsArray($mapped_data);
        $this->assertArrayHasKey('post_title', $mapped_data);
        $this->assertArrayHasKey('post_content', $mapped_data);
        $this->assertArrayHasKey('meta_input', $mapped_data);

        $this->assertEquals('Test Job Title', $mapped_data['post_title']);
        $this->assertEquals('Test job description', $mapped_data['post_content']);
        $this->assertEquals('test-guid-123', $mapped_data['meta_input']['guid']);
        $this->assertEquals('Test Company', $mapped_data['meta_input']['company']);
    }

    /**
     * Test map_geographic_data
     */
    public function test_map_geographic_data() {
        $test_cases = [
            [
                'input' => 'Brussels, Belgium',
                'expected_city' => 'Brussels',
                'expected_country' => 'Belgium'
            ],
            [
                'input' => 'New York, NY, USA',
                'expected_city' => 'New York',
                'expected_country' => 'USA'
            ],
            [
                'input' => 'London',
                'expected_city' => 'London',
                'expected_country' => ''
            ]
        ];

        foreach ($test_cases as $test_case) {
            $result = map_geographic_data($test_case['input']);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('city', $result);
            $this->assertArrayHasKey('province', $result);
            $this->assertArrayHasKey('country', $result);

            $this->assertEquals($test_case['expected_city'], $result['city']);
            $this->assertEquals($test_case['expected_country'], $result['country']);
        }
    }

    /**
     * Test map_salary_data
     */
    public function test_map_salary_data() {
        $test_cases = [
            [
                'input' => '50000',
                'expected' => [
                    'salary_min' => 50000,
                    'salary_max' => 50000,
                    'salary_currency' => 'EUR',
                    'salary_period' => 'year'
                ]
            ],
            [
                'input' => '25-30 per hour',
                'expected' => [
                    'salary_min' => 25,
                    'salary_max' => 30,
                    'salary_currency' => 'EUR',
                    'salary_period' => 'hour'
                ]
            ],
            [
                'input' => 'Competitive',
                'expected' => [
                    'salary_min' => 0,
                    'salary_max' => 0,
                    'salary_currency' => 'EUR',
                    'salary_period' => 'year'
                ]
            ]
        ];

        foreach ($test_cases as $test_case) {
            $result = map_salary_data($test_case['input']);

            $this->assertIsArray($result);
            foreach ($test_case['expected'] as $key => $value) {
                $this->assertArrayHasKey($key, $result);
                $this->assertEquals($value, $result[$key]);
            }
        }
    }

    /**
     * Test map_job_data with geographic data
     */
    public function test_map_job_data_geographic() {
        $input_data = [
            'guid' => 'test-guid-geo',
            'title' => 'Test Job',
            'location' => 'Brussels, Belgium'
        ];

        $mapped_data = map_job_data($input_data);

        $this->assertIsArray($mapped_data);
        $this->assertArrayHasKey('meta_input', $mapped_data);

        $meta = $mapped_data['meta_input'];
        $this->assertEquals('Brussels', $meta['job_city']);
        $this->assertEquals('Belgium', $meta['job_country']);
    }

    /**
     * Test map_job_data with salary data
     */
    public function test_map_job_data_salary() {
        $input_data = [
            'guid' => 'test-guid-salary',
            'title' => 'Test Job',
            'salary' => '60000-80000'
        ];

        $mapped_data = map_job_data($input_data);

        $this->assertIsArray($mapped_data);
        $this->assertArrayHasKey('meta_input', $mapped_data);

        $meta = $mapped_data['meta_input'];
        $this->assertEquals(60000, $meta['salary_min']);
        $this->assertEquals(80000, $meta['salary_max']);
    }

    /**
     * Test icon mapping
     */
    public function test_icon_mapping() {
        $test_cases = [
            'IT' => 'computer',
            'Marketing' => 'bullhorn',
            'Sales' => 'chart-line',
            'Unknown' => 'briefcase'
        ];

        foreach ($test_cases as $input => $expected) {
            $result = map_job_icon($input);
            $this->assertIsString($result);
            if ($input !== 'Unknown') {
                $this->assertStringContains($expected, $result);
            }
        }
    }

    /**
     * Test schema generation
     */
    public function test_schema_generation() {
        $job_data = [
            'guid' => 'test-schema',
            'title' => 'Test Job',
            'company' => 'Test Company',
            'location' => 'Test City',
            'description' => 'Test description',
            'salary' => '50000'
        ];

        $schema = generate_job_schema($job_data);

        $this->assertIsString($schema);
        $this->assertStringContains('"@type": "JobPosting"', $schema);
        $this->assertStringContains('Test Job', $schema);
        $this->assertStringContains('Test Company', $schema);
    }

    /**
     * Test language inference
     */
    public function test_language_inference() {
        $test_cases = [
            'English job description' => ['en'],
            'Description en français' => ['fr'],
            'Engelse beschrijving' => ['nl'],
            'Mixed English and français' => ['en', 'fr']
        ];

        foreach ($test_cases as $description => $expected) {
            $result = infer_job_languages($description);
            $this->assertIsArray($result);

            // Check that expected languages are present
            foreach ($expected as $lang) {
                $this->assertContains($lang, $result);
            }
        }
    }

    /**
     * Test benefits inference
     */
    public function test_benefits_inference() {
        $descriptions = [
            'Health insurance, 401k, paid vacation',
            'Flexible hours, remote work, stock options',
            'No benefits mentioned'
        ];

        foreach ($descriptions as $description) {
            $result = infer_job_benefits($description);
            $this->assertIsArray($result);
        }
    }
}