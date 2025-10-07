<?php
/**
 * Simple test to verify PHPUnit setup works
 */

use PHPUnit\Framework\TestCase;

class SimpleTest extends TestCase {

    public function test_basic_assertion() {
        $this->assertTrue(true);
        $this->assertEquals(2, 1 + 1);
    }

    public function test_string_operations() {
        $string = 'puntWork';
        $this->assertStringContains('Work', $string);
        $this->assertStringStartsWith('punt', $string);
    }

    public function test_array_operations() {
        $array = ['a' => 1, 'b' => 2, 'c' => 3];
        $this->assertArrayHasKey('a', $array);
        $this->assertCount(3, $array);
        $this->assertEquals(1, $array['a']);
    }
}