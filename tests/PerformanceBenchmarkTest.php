<?php
/**
 * Performance Benchmark Tests for puntWork plugin.
 *
 * @package    Puntwork
 * @subpackage Tests
 */

namespace Puntwork;

use PHPUnit\Framework\TestCase;

class PerformanceBenchmarkTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        // Mock WordPress functions
        if (!defined('ABSPATH')) {
            define('ABSPATH', '/tmp/wordpress/');
        }
    }

    /**
     * Benchmark array mapping performance
     */
    public function testArrayMappingPerformance() {
        $startTime = microtime(true);

        // Test GetProvinceMap performance
        $provinceMap = GetProvinceMap();
        $provinceTime = microtime(true) - $startTime;

        $this->assertLessThan(0.1, $provinceTime, 'Province mapping should complete in under 100ms');
        $this->assertIsArray($provinceMap);

        // Test GetSalaryEstimates performance
        $startTime = microtime(true);
        $salaryEstimates = GetSalaryEstimates();
        $salaryTime = microtime(true) - $startTime;

        $this->assertLessThan(0.1, $salaryTime, 'Salary estimates mapping should complete in under 100ms');
        $this->assertIsArray($salaryEstimates);

        // Test GetIconMap performance
        $startTime = microtime(true);
        $iconMap = GetIconMap();
        $iconTime = microtime(true) - $startTime;

        $this->assertLessThan(0.1, $iconTime, 'Icon mapping should complete in under 100ms');
        $this->assertIsArray($iconMap);
    }

    /**
     * Benchmark job schema building performance
     */
    public function testJobSchemaBuildingPerformance() {
        $item = (object) [
            'guid' => 'test-guid-' . rand(),
            'job_title' => 'Test Job Title',
            'job_desc' => 'Test job description with some content',
            'job_location' => 'Brussels',
            'job_salary_min' => rand(30000, 50000),
            'job_salary_max' => rand(50000, 70000)
        ];

        $iterations = 100;
        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $schema = build_job_schema('Test Job', 'Test description', $item, 'Brussels', 'Full-time', false, 'test-org', 'IT & Telecommunicatie');
        }

        $totalTime = microtime(true) - $startTime;
        $avgTime = $totalTime / $iterations;

        $this->assertLessThan(0.01, $avgTime, 'Job schema building should average under 10ms per operation');
        $this->assertIsArray($schema);
    }

    /**
     * Benchmark duplicate handling performance
     */
    public function testDuplicateHandlingPerformance() {
        $batchGuids = array_map(function($i) { return "guid-{$i}"; }, range(1, 1000));
        $existingByGuid = array_combine(
            array_map(function($i) { return "guid-{$i}"; }, range(1, 500)),
            array_map(function($i) { return [123 + $i]; }, range(1, 500))
        );

        $startTime = microtime(true);

        $logs = [];
        $duplicatesDrafted = 0;
        $postIdsByGuid = [];

        handle_duplicates($batchGuids, $existingByGuid, $logs, $duplicatesDrafted, $postIdsByGuid);

        $processingTime = microtime(true) - $startTime;

        $this->assertLessThan(0.5, $processingTime, 'Duplicate handling for 1000 items should complete in under 500ms');
        $this->assertIsArray($logs);
        $this->assertIsInt($duplicatesDrafted);
        $this->assertIsArray($postIdsByGuid);
    }

    /**
     * Memory usage benchmark
     */
    public function testMemoryUsageBenchmark() {
        $initialMemory = memory_get_usage();

        // Load mappings
        $provinceMap = GetProvinceMap();
        $salaryEstimates = GetSalaryEstimates();
        $iconMap = GetIconMap();

        $loadedMemory = memory_get_usage();
        $memoryIncrease = $loadedMemory - $initialMemory;

        $this->assertLessThan(5 * 1024 * 1024, $memoryIncrease, 'Memory increase should be under 5MB for loading mappings');
    }
}