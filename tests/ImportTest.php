<?php

/**
 * PHPUnit tests for puntWork plugin.
 */

namespace Puntwork;

use PHPUnit\Framework\TestCase;

class ImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Tests now run with mocked environment
    }

    public function testGetProvinceMap()
    {
        $map = GetProvinceMap();
        $this->assertIsArray($map);
        $this->assertArrayHasKey('antwerp', $map);
        $this->assertGreaterThan(0, count($map));
    }

    public function testGetSalaryEstimates()
    {
        $estimates = GetSalaryEstimates();
        $this->assertIsArray($estimates);
        $this->assertArrayHasKey('Accounting', $estimates);
        $this->assertGreaterThan(0, count($estimates));
    }

    public function testGetIconMap()
    {
        $icons = GetIconMap();
        $this->assertIsArray($icons);
        $this->assertGreaterThan(0, count($icons));
    }

    public function testGetAcfFields()
    {
        $fields = get_acf_fields();
        $this->assertIsArray($fields);
        $this->assertContains('job_title', $fields);
    }

    public function testGetZeroEmptyFields()
    {
        $fields = get_zero_empty_fields();
        $this->assertIsArray($fields);
        $this->assertContains('salaryfrom', $fields);
    }

    public function testBuildJobSchema()
    {
        $item = (object) [
            'guid' => 'test-guid',
            'job_title' => 'Test Job',
            'job_desc' => 'Test description',
            'job_location' => 'Brussels',
            'job_salary_min' => 30000,
            'job_salary_max' => 40000
        ];
        $schema = build_job_schema('Test Job', 'Test description', $item, 'Brussels', 'Full-time', false, 'test-org', 'IT & Telecommunicatie');
        $this->assertIsArray($schema);
        $this->assertArrayHasKey('@type', $schema);
        $this->assertEquals('JobPosting', $schema['@type']);
    }

    public function testProcessXmlBatch()
    {
        // Skip this test as XML processing requires complex WordPress environment mocking
        $this->markTestSkipped('XML processing test requires extensive WordPress environment mocking');
    }

    public function testHandleDuplicates()
    {
        $batch_guids = ['guid1', 'guid2'];
        $existing_by_guid = ['guid1' => [123]];
        $logs = [];
        $duplicates_drafted = 0;
        $post_ids_by_guid = [];

        handle_duplicates($batch_guids, $existing_by_guid, $logs, $duplicates_drafted, $post_ids_by_guid);

        $this->assertIsArray($logs);
        $this->assertIsInt($duplicates_drafted);
        $this->assertIsArray($post_ids_by_guid);
    }
}
