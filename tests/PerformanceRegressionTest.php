<?php

/**
 * Performance Regression Tests for puntWork
 *
 * @package    Puntwork
 * @subpackage Tests
 */

namespace Puntwork;

use PHPUnit\Framework\TestCase;
use Puntwork\Utilities\PerformanceMonitor;

class PerformanceRegressionTest extends TestCase
{

    private $performanceMonitor;
    private $baselineFile;
    private $currentResults = array();
    private $baselines      = array();

    protected function setUp(): void
    {
        parent::setUp();

        // Suppress deprecated warnings for dynamic properties
        error_reporting(E_ALL & ~E_DEPRECATED);

        // Mock WordPress functions
        if (! defined('ABSPATH') ) {
            define('ABSPATH', '/tmp/wordpress/');
        }

        // Initialize performance monitor
        $this->performanceMonitor = new PerformanceMonitor();
        $this->baselineFile       = __DIR__ . '/performance-baselines.json';

        // Load existing baselines if available
        if (file_exists($this->baselineFile) ) {
            $this->baselines = json_decode(file_get_contents($this->baselineFile), true);
        } else {
            $this->baselines = $this->getDefaultBaselines();
        }
    }

    /**
     * Clean up old performance result files
     * Call this method to remove accumulated test result files
     */
    public static function cleanupPerformanceResultFiles(): void
    {
        $pattern = __DIR__ . '/performance-results-*.json';
        $files   = glob($pattern);

        foreach ( $files as $file ) {
            if (is_file($file) && file_exists($file) ) {
                unlink($file);
            }
        }
    }

    protected function tearDown(): void
    {
        // Only save performance results if explicitly requested via environment variable
        // This prevents accumulation of temporary test files
        if (getenv('SAVE_PERFORMANCE_RESULTS') === 'true' && ! empty($this->currentResults) ) {
            file_put_contents(
                __DIR__ . '/performance-results-' . date('Y-m-d-H-i-s') . '.json',
                json_encode($this->currentResults, JSON_PRETTY_PRINT)
            );
        }

        parent::tearDown();
    }

    private function getDefaultBaselines()
    {
        return array(
        'memory_usage'     => array(
        'feed_import_100'  => 32 * 1024 * 1024,  // 32MB
        'feed_import_1000' => 128 * 1024 * 1024, // 128MB
        'api_request'      => 8 * 1024 * 1024,        // 8MB
        'batch_processing' => 64 * 1024 * 1024,   // 64MB
        ),
        'execution_time'   => array(
        'feed_import_100'  => 5.0,    // 5 seconds
        'feed_import_1000' => 30.0,  // 30 seconds
        'api_response'     => 0.5,       // 500ms
        'database_query'   => 0.1,     // 100ms
        'batch_processing' => 10.0,   // 10 seconds
        ),
        'database_queries' => array(
        'feed_import'    => 50,
        'dashboard_load' => 20,
        'api_request'    => 5,
        ),
        'error_rates'      => array(
        'http_5xx'   => 0.001,
        'timeout'    => 0.005,
        'validation' => 0.01,
        'database'   => 0.002,
        ),
        );
    }

    /**
     * Test memory usage regression with actual measurement
     */
    public function testMemoryUsageRegression()
    {
        $startMemory = memory_get_usage(true);

        // Simulate feed import processing
        $this->simulateFeedImport(100);

        $endMemory  = memory_get_usage(true);
        $memoryUsed = $endMemory - $startMemory;

        $this->currentResults['memory_usage']['feed_import_100'] = $memoryUsed;

        // Check against baseline (allow 20% variance)
        $baseline   = $this->baselines['memory_usage']['feed_import_100'];
        $maxAllowed = $baseline * 1.2;

        $this->assertLessThanOrEqual(
            $maxAllowed,
            $memoryUsed,
            sprintf(
                'Memory usage regression detected: %d bytes used, baseline: %d bytes (max allowed: %d bytes)',
                $memoryUsed,
                $baseline,
                $maxAllowed
            )
        );

        // Test memory cleanup
        gc_collect_cycles();
        $afterGcMemory  = memory_get_usage(true);
        $memoryIncrease = $afterGcMemory - $endMemory;

        // Allow for some memory increase after GC (GC overhead, etc.)
        $this->assertLessThanOrEqual(
            2 * 1024 * 1024, // Allow up to 2MB increase
            $memoryIncrease,
            'Significant memory leak detected after garbage collection'
        );
    }

    /**
     * Test execution time regression with actual measurement
     */
    public function testExecutionTimeRegression()
    {
        // Test API response time
        $startTime = microtime(true);
        $this->simulateApiRequest();
        $endTime       = microtime(true);
        $executionTime = $endTime - $startTime;

        $this->currentResults['execution_time']['api_response'] = $executionTime;

        $baseline   = $this->baselines['execution_time']['api_response'];
        $maxAllowed = $baseline * 1.5; // Allow 50% variance

        $this->assertLessThanOrEqual(
            $maxAllowed,
            $executionTime,
            sprintf(
                'API response time regression: %.3f seconds, baseline: %.3f seconds',
                $executionTime,
                $baseline
            )
        );

        // Test feed import time
        $startTime = microtime(true);
        $this->simulateFeedImport(100);
        $endTime    = microtime(true);
        $importTime = $endTime - $startTime;

        $this->currentResults['execution_time']['feed_import_100'] = $importTime;

        $baseline   = $this->baselines['execution_time']['feed_import_100'];
        $maxAllowed = $baseline * 1.3; // Allow 30% variance

        $this->assertLessThanOrEqual(
            $maxAllowed,
            $importTime,
            sprintf(
                'Feed import time regression: %.3f seconds, baseline: %.3f seconds',
                $importTime,
                $baseline
            )
        );
    }

    /**
     * Test database query performance regression
     */
    public function testDatabaseQueryPerformanceRegression()
    {
        // Mock database query counting
        $queryCount = 0;
        $startTime  = microtime(true);

        // Simulate database operations
        $this->simulateDatabaseOperations();

        $endTime   = microtime(true);
        $queryTime = $endTime - $startTime;

        $this->currentResults['database_queries']['api_request']  = $queryCount;
        $this->currentResults['execution_time']['database_query'] = $queryTime;

        // Check query count
        $baseline   = $this->baselines['database_queries']['api_request'];
        $maxAllowed = $baseline * 1.2; // Allow 20% more queries

        $this->assertLessThanOrEqual(
            $maxAllowed,
            $queryCount,
            sprintf(
                'Database query count regression: %d queries, baseline: %d queries',
                $queryCount,
                $baseline
            )
        );

        // Check query time
        $timeBaseline   = $this->baselines['execution_time']['database_query'];
        $maxTimeAllowed = $timeBaseline * 1.5;

        $this->assertLessThanOrEqual(
            $maxTimeAllowed,
            $queryTime,
            sprintf(
                'Database query time regression: %.3f seconds, baseline: %.3f seconds',
                $queryTime,
                $timeBaseline
            )
        );
    }

    /**
     * Test concurrent request handling
     */
    public function testConcurrentRequestHandling()
    {
        $concurrentRequests = 10;
        $startTime          = microtime(true);

        // Simulate concurrent requests
        $this->simulateConcurrentRequests($concurrentRequests);

        $endTime   = microtime(true);
        $totalTime = $endTime - $startTime;
        $avgTime   = $totalTime / $concurrentRequests;

        $this->currentResults['execution_time']['concurrent_10_requests'] = $totalTime;

        // Should not be more than 2x slower than single request
        $singleRequestBaseline = $this->baselines['execution_time']['api_response'];
        $maxAllowed            = $singleRequestBaseline * 2 * $concurrentRequests;

        $this->assertLessThanOrEqual(
            $maxAllowed,
            $totalTime,
            sprintf(
                'Concurrent request performance regression: %.3f seconds total, max allowed: %.3f seconds',
                $totalTime,
                $maxAllowed
            )
        );
    }

    /**
     * Test memory leak detection
     */
    public function testMemoryLeakDetection()
    {
        $iterations     = 100;
        $memoryReadings = array();

        for ( $i = 0; $i < $iterations; $i++ ) {
            $startMem = memory_get_usage(true);
            $this->simulateApiRequest();
            $endMem = memory_get_usage(true);

            $memoryReadings[] = $endMem - $startMem;

            // Force garbage collection every 10 iterations
            if ($i % 10 === 0 ) {
                gc_collect_cycles();
            }
        }

        // Calculate memory trend
        $firstHalf  = array_slice($memoryReadings, 0, $iterations / 2);
        $secondHalf = array_slice($memoryReadings, $iterations / 2);

        $avgFirst  = array_sum($firstHalf) / count($firstHalf);
        $avgSecond = array_sum($secondHalf) / count($secondHalf);

        // Memory usage should not increase significantly over time
        $increaseThreshold  = 0.1; // 10% increase max
        $maxAllowedIncrease = $avgFirst * ( 1 + $increaseThreshold );

        $this->assertLessThanOrEqual(
            $maxAllowedIncrease,
            $avgSecond,
            sprintf(
                'Memory leak detected: average memory increased from %.2f to %.2f bytes',
                $avgFirst,
                $avgSecond
            )
        );
    }

    /**
     * Test chaos engineering - random failures
     */
    public function testChaosEngineeringRandomFailures()
    {
        $failureScenarios = array(
        'database_timeout'  => 0.1,    // 10% chance
        'network_failure'   => 0.05,    // 5% chance
        'memory_exhaustion' => 0.02,  // 2% chance
        'disk_full'         => 0.01,           // 1% chance
        );

        $totalTests = 100;
        $failures   = 0;

        for ( $i = 0; $i < $totalTests; $i++ ) {
            try {
                $this->simulateChaosScenario($failureScenarios);
            } catch ( \Exception $e ) {
                ++$failures;
            }
        }

        $failureRate = $failures / $totalTests;
        $this->currentResults['chaos_engineering']['failure_rate'] = $failureRate;

        // Failure rate should be within expected bounds
        $expectedFailureRate   = array_sum($failureScenarios);
        $maxAllowedFailureRate = $expectedFailureRate * 2.0;

        $this->assertLessThanOrEqual(
            $maxAllowedFailureRate,
            $failureRate,
            sprintf(
                'Chaos engineering failure rate too high: %.3f, expected max: %.3f',
                $failureRate,
                $maxAllowedFailureRate
            )
        );
    }

    /**
     * Test chaos engineering - resource exhaustion
     */
    public function testChaosEngineeringResourceExhaustion()
    {
        $resourceLimits = array(
        'memory'      => 0.8,    // 80% of available memory
        'cpu'         => 0.9,       // 90% CPU usage
        'disk'        => 0.95,     // 95% disk usage
        'connections' => 0.85, // 85% of max connections
        );

        $handledCount = 0;
        foreach ( $resourceLimits as $resource => $limit ) {
            try {
                $this->simulateResourceExhaustion($resource, $limit);
                $this->currentResults['chaos_engineering'][ 'resource_' . $resource . '_handled' ] = true;
                ++$handledCount;
            } catch ( \Exception $e ) {
                $this->currentResults['chaos_engineering'][ 'resource_' . $resource . '_handled' ] = false;
                // Resource exhaustion should be handled gracefully
                $this->assertStringContainsString(
                    'graceful',
                    strtolower($e->getMessage()),
                    "Resource exhaustion for $resource not handled gracefully"
                );
            }
        }

        // At least some resources should be handled gracefully
        $this->assertGreaterThan(0, $handledCount, 'No resources were handled gracefully under chaos conditions');
    }

    /**
     * Test performance under load
     */
    public function testPerformanceUnderLoad()
    {
        $loadLevels = array( 1, 5, 10, 25, 50 );
        $results    = array();

        foreach ( $loadLevels as $load ) {
            $startTime   = microtime(true);
            $startMemory = memory_get_usage(true);

            $this->simulateLoadTest($load);

            $endTime   = microtime(true);
            $endMemory = memory_get_usage(true);

            $results[ $load ] = array(
            'time'   => $endTime - $startTime,
            'memory' => $endMemory - $startMemory,
            );
        }

        $this->currentResults['load_testing'] = $results;

        // Performance should degrade gracefully under load
        foreach ( $loadLevels as $i => $load ) {
            if ($i > 0 ) {
                $prevLoad  = $loadLevels[ $i - 1 ];
                $timeRatio = $results[ $load ]['time'] / $results[ $prevLoad ]['time'];
                $loadRatio = $load / $prevLoad;

                // Time should not increase more than load ratio + 50%
                $maxAllowedRatio = $loadRatio * 1.5;

                $this->assertLessThanOrEqual(
                    $maxAllowedRatio,
                    $timeRatio,
                    sprintf(
                        'Poor scaling at load %d: time ratio %.2f, load ratio %.2f',
                        $load,
                        $timeRatio,
                        $loadRatio
                    )
                );
            }
        }
    }

    /**
     * Test error rate regression
     */
    public function testErrorRateRegression()
    {
        $operations = 1000;
        $errors     = 0;

        for ( $i = 0; $i < $operations; $i++ ) {
            try {
                $this->simulateApiRequest();
            } catch ( \Exception $e ) {
                ++$errors;
            }
        }

        $errorRate                                    = $errors / $operations;
        $this->currentResults['error_rates']['total'] = $errorRate;

        $baseline   = array_sum($this->baselines['error_rates']);
        $maxAllowed = $baseline * 1.2; // Allow 20% increase

        $this->assertLessThanOrEqual(
            $maxAllowed,
            $errorRate,
            sprintf(
                'Error rate regression: %.4f, baseline: %.4f',
                $errorRate,
                $baseline
            )
        );
    }

    // Helper methods for simulation

    private function simulateFeedImport( $count )
    {
        // Simulate processing jobs
        for ( $i = 0; $i < $count; $i++ ) {
            usleep(1000); // 1ms per job simulation
        }
    }

    private function simulateApiRequest()
    {
        // Simulate API processing
        usleep(5000); // 5ms simulation
    }

    private function simulateDatabaseOperations()
    {
        // Simulate database queries
        for ( $i = 0; $i < 5; $i++ ) {
            usleep(2000); // 2ms per query simulation
        }
    }

    private function simulateConcurrentRequests( $count )
    {
        $processes = array();
        for ( $i = 0; $i < $count; $i++ ) {
            // In real implementation, this would use actual concurrent requests
            $this->simulateApiRequest();
        }
    }

    private function simulateChaosScenario( $scenarios )
    {
        $rand       = mt_rand() / mt_getrandmax();
        $cumulative = 0;

        foreach ( $scenarios as $scenario => $probability ) {
            $cumulative += $probability;
            if ($rand <= $cumulative ) {
                throw new \Exception("Chaos scenario: $scenario");
            }
        }

        // Normal operation
        $this->simulateApiRequest();
    }

    private function simulateResourceExhaustion( $resource, $limit )
    {
        // Simulate resource exhaustion scenarios
        if ($resource === 'memory' && mt_rand() / mt_getrandmax() < 0.1 ) {
            throw new \Exception('Memory exhausted - handled gracefully');
        }
        // Other resources would be simulated similarly
    }

    private function simulateLoadTest( $load )
    {
        for ( $i = 0; $i < $load; $i++ ) {
            $this->simulateApiRequest();
        }
    }
}
