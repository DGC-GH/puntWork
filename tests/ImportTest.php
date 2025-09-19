<?php
/**
 * PHPUnit tests for puntWork plugin.
 */

namespace Puntwork;

use PHPUnit\Framework\TestCase;

class ImportTest extends TestCase {
    public function testGetProvinceMap() {
        $map = GetProvinceMap();
        $this->assertIsArray($map);
        $this->assertArrayHasKey('antwerp', $map);
    }

    public function testGetSalaryEstimates() {
        $estimates = GetSalaryEstimates();
        $this->assertIsArray($estimates);
        $this->assertArrayHasKey('Accounting', $estimates);
    }
}