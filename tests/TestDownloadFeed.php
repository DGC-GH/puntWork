<?php
use PHPUnit\Framework\TestCase;

class TestDownloadFeed extends TestCase {
    private $tmp_dir;

    public function setUp(): void {
        $this->tmp_dir = sys_get_temp_dir() . '/puntwork_test_feeds/';
        if (!is_dir($this->tmp_dir)) mkdir($this->tmp_dir, 0777, true);
    }

    public function tearDown(): void {
        // Cleanup created files
        $files = glob($this->tmp_dir . '*');
        if ($files) {
            foreach ($files as $f) @unlink($f);
        }
        @rmdir($this->tmp_dir);
    }

    public function test_wp_remote_get_success_writes_file() {
        // Simulate a successful wp_remote_get response with large body
        $large_body = str_repeat('a', 2000);

        require_once __DIR__ . '/../includes/import/download-feed.php';

        $xml_path = $this->tmp_dir . 'feed1.xml';
        $logs = [];
        $http_callable = function($url, $args = []) use ($large_body) {
            return ['body' => $large_body, 'response' => ['code' => 200]];
        };
        $result = \Puntwork\download_feed('http://example.test/feed', $xml_path, $this->tmp_dir, $logs, true, $http_callable);

        $this->assertTrue($result, 'download_feed should return true on large body');
        $this->assertFileExists($xml_path, 'File should be created');
        $this->assertGreaterThan(1000, filesize($xml_path), 'File size should exceed 1000 bytes');
    }

    public function test_wp_remote_get_small_body_returns_false() {
        $small_body = 'short';
        require_once __DIR__ . '/../includes/import/download-feed.php';

        $xml_path = $this->tmp_dir . 'feed2.xml';
        $logs = [];
        $http_callable = function($url, $args = []) use ($small_body) {
            return ['body' => $small_body, 'response' => ['code' => 200]];
        };
        $result = \Puntwork\download_feed('http://example.test/feed', $xml_path, $this->tmp_dir, $logs, true, $http_callable);

        $this->assertFalse($result, 'download_feed should return false on small/empty body');
        $this->assertFileNotExists($xml_path, 'File should not be created or should be empty');
    }
}
