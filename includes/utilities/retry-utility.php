<?php
/**
 * Retry and error recovery utilities
 *
 * @package    Puntwork
 * @subpackage Utilities
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Retry utility class for handling transient failures with exponential backoff
 */
class RetryUtility {

    const MAX_RETRIES = 3;
    const BASE_DELAY_MS = 100;
    const MAX_DELAY_MS = 5000;
    const CIRCUIT_BREAKER_THRESHOLD = 5;
    const CIRCUIT_BREAKER_TIMEOUT = 300; // 5 minutes

    private static $circuit_breakers = [];

    /**
     * Execute a function with retry logic and exponential backoff
     *
     * @param callable $function The function to execute
     * @param array $args Arguments to pass to the function
     * @param array $options Retry options
     * @return mixed Result of the function call
     * @throws \Exception Last exception if all retries fail
     */
    public static function executeWithRetry($function, $args = [], $options = []) {
        $maxRetries = $options['max_retries'] ?? self::MAX_RETRIES;
        $baseDelay = $options['base_delay_ms'] ?? self::BASE_DELAY_MS;
        $maxDelay = $options['max_delay_ms'] ?? self::MAX_DELAY_MS;
        $operationKey = $options['operation_key'] ?? 'default';
        $retryableExceptions = $options['retryable_exceptions'] ?? ['Exception'];
        $context = $options['context'] ?? [];

        // Check circuit breaker
        if (self::isCircuitBreakerOpen($operationKey)) {
            PuntWorkLogger::warning('Circuit breaker open, skipping operation', $context['logger_context'] ?? PuntWorkLogger::CONTEXT_GENERAL, [
                'operation_key' => $operationKey,
                'remaining_timeout' => self::getCircuitBreakerRemainingTime($operationKey)
            ]);
            throw new \Exception("Circuit breaker is open for operation: $operationKey");
        }

        $lastException = null;
        $attempt = 0;

        while ($attempt <= $maxRetries) {
            try {
                $result = call_user_func_array($function, $args);

                // Success - reset circuit breaker
                self::resetCircuitBreaker($operationKey);

                return $result;

            } catch (\Exception $e) {
                $lastException = $e;
                $attempt++;

                // Check if this exception type should be retried
                $shouldRetry = self::shouldRetryException($e, $retryableExceptions);

                if (!$shouldRetry || $attempt > $maxRetries) {
                    // Record failure for circuit breaker
                    self::recordFailure($operationKey);

                    PuntWorkLogger::error('Operation failed after retries', $context['logger_context'] ?? PuntWorkLogger::CONTEXT_GENERAL, [
                        'operation_key' => $operationKey,
                        'attempts' => $attempt,
                        'error' => $e->getMessage(),
                        'should_retry' => $shouldRetry,
                        'max_retries' => $maxRetries
                    ]);

                    throw $e;
                }

                // Calculate delay with exponential backoff and jitter
                $delay = self::calculateDelay($attempt, $baseDelay, $maxDelay);

                PuntWorkLogger::warning('Operation failed, retrying', $context['logger_context'] ?? PuntWorkLogger::CONTEXT_GENERAL, [
                    'operation_key' => $operationKey,
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'delay_ms' => $delay,
                    'error' => $e->getMessage()
                ]);

                // Sleep before retry
                usleep($delay * 1000); // Convert to microseconds

            } catch (\Throwable $t) {
                // Handle fatal errors
                $lastException = new \Exception($t->getMessage(), 0, $t);
                $attempt++;

                self::recordFailure($operationKey);

                if ($attempt > $maxRetries) {
                    PuntWorkLogger::error('Fatal error in operation', $context['logger_context'] ?? PuntWorkLogger::CONTEXT_GENERAL, [
                        'operation_key' => $operationKey,
                        'attempts' => $attempt,
                        'error' => $t->getMessage()
                    ]);
                    throw $lastException;
                }

                $delay = self::calculateDelay($attempt, $baseDelay, $maxDelay);
                usleep($delay * 1000);
            }
        }

        throw $lastException;
    }

    /**
     * Execute a database operation with retry logic optimized for DB errors
     *
     * @param callable $function Database function to execute
     * @param array $args Arguments for the function
     * @param array $context Logging context
     * @return mixed Result of the database operation
     */
    public static function executeDatabaseOperation($function, $args = [], $context = []) {
        $dbOptions = [
            'max_retries' => 2, // Fewer retries for DB operations
            'base_delay_ms' => 200, // Slightly longer base delay
            'operation_key' => 'database_operation',
            'retryable_exceptions' => ['Exception'], // Most DB exceptions are retryable
            'context' => $context
        ];

        return self::executeWithRetry($function, $args, $dbOptions);
    }

    /**
     * Execute a file operation with retry logic
     *
     * @param callable $function File operation function
     * @param array $args Arguments for the function
     * @param array $context Logging context
     * @return mixed Result of the file operation
     */
    public static function executeFileOperation($function, $args = [], $context = []) {
        $fileOptions = [
            'max_retries' => 1, // File operations usually don't benefit from many retries
            'base_delay_ms' => 500, // Longer delay for file operations
            'operation_key' => 'file_operation',
            'retryable_exceptions' => ['Exception'],
            'context' => $context
        ];

        return self::executeWithRetry($function, $args, $fileOptions);
    }

    /**
     * Execute a WordPress option operation with retry logic
     *
     * @param callable $function Option operation function
     * @param array $args Arguments for the function
     * @param array $context Logging context
     * @return mixed Result of the option operation
     */
    public static function executeOptionOperation($function, $args = [], $context = []) {
        $optionOptions = [
            'max_retries' => 2,
            'base_delay_ms' => 100,
            'operation_key' => 'option_operation',
            'retryable_exceptions' => ['Exception'],
            'context' => $context
        ];

        return self::executeWithRetry($function, $args, $optionOptions);
    }

    /**
     * Determine if an exception should trigger a retry
     *
     * @param \Exception $exception The exception to check
     * @param array $retryableExceptions List of exception class names that are retryable
     * @return bool True if the exception should be retried
     */
    private static function shouldRetryException(\Exception $exception, $retryableExceptions) {
        $exceptionClass = get_class($exception);

        // Check if exception class is in retryable list
        foreach ($retryableExceptions as $retryableClass) {
            if ($exceptionClass === $retryableClass || is_subclass_of($exceptionClass, $retryableClass)) {
                return true;
            }
        }

        // Check for specific error messages that indicate transient failures
        $errorMessage = strtolower($exception->getMessage());

        $transientIndicators = [
            'deadlock',
            'lock wait timeout',
            'connection lost',
            'server has gone away',
            'too many connections',
            'temporary failure',
            'network',
            'timeout',
            'temporary',
            'transient'
        ];

        foreach ($transientIndicators as $indicator) {
            if (strpos($errorMessage, $indicator) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate delay with exponential backoff and jitter
     *
     * @param int $attempt Current attempt number (1-based)
     * @param int $baseDelay Base delay in milliseconds
     * @param int $maxDelay Maximum delay in milliseconds
     * @return int Delay in milliseconds
     */
    private static function calculateDelay($attempt, $baseDelay, $maxDelay) {
        // Exponential backoff: baseDelay * 2^(attempt-1)
        $delay = $baseDelay * pow(2, $attempt - 1);

        // Add jitter (±25% randomization)
        $jitter = $delay * 0.25 * (mt_rand(-100, 100) / 100);
        $delay += $jitter;

        // Cap at maximum delay
        $delay = min($delay, $maxDelay);

        return (int)max($delay, 10); // Minimum 10ms delay
    }

    /**
     * Check if circuit breaker is open for an operation
     *
     * @param string $operationKey Unique key for the operation
     * @return bool True if circuit breaker is open
     */
    private static function isCircuitBreakerOpen($operationKey) {
        $circuitBreaker = self::$circuit_breakers[$operationKey] ?? null;

        if (!$circuitBreaker) {
            return false;
        }

        $now = time();

        // Check if circuit breaker has expired
        if ($now > $circuitBreaker['expires_at']) {
            unset(self::$circuit_breakers[$operationKey]);
            return false;
        }

        return $circuitBreaker['failure_count'] >= self::CIRCUIT_BREAKER_THRESHOLD;
    }

    /**
     * Get remaining time for circuit breaker timeout
     *
     * @param string $operationKey Operation key
     * @return int Remaining seconds
     */
    private static function getCircuitBreakerRemainingTime($operationKey) {
        $circuitBreaker = self::$circuit_breakers[$operationKey] ?? null;

        if (!$circuitBreaker) {
            return 0;
        }

        return max(0, $circuitBreaker['expires_at'] - time());
    }

    /**
     * Record a failure for circuit breaker tracking
     *
     * @param string $operationKey Operation key
     */
    private static function recordFailure($operationKey) {
        $now = time();

        if (!isset(self::$circuit_breakers[$operationKey])) {
            self::$circuit_breakers[$operationKey] = [
                'failure_count' => 0,
                'expires_at' => 0
            ];
        }

        $circuitBreaker = &self::$circuit_breakers[$operationKey];
        $circuitBreaker['failure_count']++;

        // Set timeout if threshold reached
        if ($circuitBreaker['failure_count'] >= self::CIRCUIT_BREAKER_THRESHOLD) {
            $circuitBreaker['expires_at'] = $now + self::CIRCUIT_BREAKER_TIMEOUT;
        }
    }

    /**
     * Reset circuit breaker on successful operation
     *
     * @param string $operationKey Operation key
     */
    private static function resetCircuitBreaker($operationKey) {
        if (isset(self::$circuit_breakers[$operationKey])) {
            self::$circuit_breakers[$operationKey]['failure_count'] = 0;
        }
    }

    /**
     * Get circuit breaker status for monitoring
     *
     * @return array Circuit breaker statuses
     */
    public static function getCircuitBreakerStatus() {
        $status = [];

        foreach (self::$circuit_breakers as $key => $breaker) {
            $status[$key] = [
                'failure_count' => $breaker['failure_count'],
                'is_open' => $breaker['failure_count'] >= self::CIRCUIT_BREAKER_THRESHOLD,
                'remaining_timeout' => max(0, $breaker['expires_at'] - time()),
                'expires_at' => $breaker['expires_at']
            ];
        }

        return $status;
    }
}

/**
 * Convenience function for database operations with retry
 *
 * @param callable $function Function to execute
 * @param array $args Arguments
 * @param array $context Logging context
 * @return mixed Result
 */
function retry_database_operation($function, $args = [], $context = []) {
    return RetryUtility::executeDatabaseOperation($function, $args, $context);
}

/**
 * Convenience function for file operations with retry
 *
 * @param callable $function Function to execute
 * @param array $args Arguments
 * @param array $context Logging context
 * @return mixed Result
 */
function retry_file_operation($function, $args = [], $context = []) {
    return RetryUtility::executeFileOperation($function, $args, $context);
}

/**
 * Convenience function for WordPress option operations with retry
 *
 * @param callable $function Function to execute
 * @param array $args Arguments
 * @param array $context Logging context
 * @return mixed Result
 */
function retry_option_operation($function, $args = [], $context = []) {
    return RetryUtility::executeOptionOperation($function, $args, $context);
}