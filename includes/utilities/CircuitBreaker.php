<?php

/**
 * Circuit breaker pattern for feed processing reliability
 *
 * @package    Puntwork
 * @subpackage Utilities
 * @since      1.0.9
 */

namespace Puntwork\Utilities;

// Prevent direct access
if (! defined('ABSPATH') ) {
    exit;
}

/**
 * Circuit breaker pattern for feed processing reliability
 */
class CircuitBreaker
{

    private static array $circuits = array();
    public const STATE_CLOSED      = 'closed';     // Normal operation
    public const STATE_OPEN        = 'open';         // Failing, reject requests
    public const STATE_HALF_OPEN   = 'half_open'; // Testing if service recovered

    /**
     * Check if circuit is closed (allow request)
     *
     * @param  string $circuit_name Circuit identifier
     * @return bool True if request should proceed
     */
    public static function canProceed( string $circuit_name ): bool
    {
        $circuit = self::getCircuitState($circuit_name);

        switch ( $circuit['state'] ) {
        case self::STATE_CLOSED:
            return true;
        case self::STATE_OPEN:
            // Check if timeout has passed
            if (time() - $circuit['last_failure'] > $circuit['timeout'] ) {
                self::$circuits[ $circuit_name ]['state'] = self::STATE_HALF_OPEN;
                return true; // Allow one test request
            }
            return false;
        case self::STATE_HALF_OPEN:
            return true; // Allow test request
        default:
            return true;
        }
    }

    /**
     * Record successful operation
     *
     * @param string $circuit_name Circuit identifier
     */
    public static function recordSuccess( string $circuit_name ): void
    {
        if (! isset(self::$circuits[ $circuit_name ]) ) {
            self::initCircuit($circuit_name);
        }

        $circuit = &self::$circuits[ $circuit_name ];

        if ($circuit['state'] === self::STATE_HALF_OPEN ) {
            // Service recovered, close circuit
            $circuit['state']         = self::STATE_CLOSED;
            $circuit['failure_count'] = 0;
        }
    }

    /**
     * Record failed operation
     *
     * @param string $circuit_name Circuit identifier
     */
    public static function recordFailure( string $circuit_name ): void
    {
        if (! isset(self::$circuits[ $circuit_name ]) ) {
            self::initCircuit($circuit_name);
        }

        $circuit = &self::$circuits[ $circuit_name ];
        ++$circuit['failure_count'];
        $circuit['last_failure'] = time();

        // Open circuit if failure threshold reached
        if ($circuit['failure_count'] >= $circuit['failure_threshold'] ) {
            $circuit['state'] = self::STATE_OPEN;
        }
    }

    /**
     * Get circuit state
     *
     * @param  string $circuit_name Circuit identifier
     * @return array Circuit state data
     */
    private static function getCircuitState( string $circuit_name ): array
    {
        if (! isset(self::$circuits[ $circuit_name ]) ) {
            self::initCircuit($circuit_name);
        }
        return self::$circuits[ $circuit_name ];
    }

    /**
     * Initialize circuit state
     *
     * @param string $circuit_name Circuit identifier
     */
    private static function initCircuit( string $circuit_name ): void
    {
        self::$circuits[ $circuit_name ] = array(
        'state'             => self::STATE_CLOSED,
        'failure_count'     => 0,
        'failure_threshold' => 5, // Open after 5 failures
        'timeout'           => 300, // 5 minutes timeout
        'last_failure'      => 0,
        );
    }

    /**
     * Get all circuit states (for monitoring)
     *
     * @return array All circuit states
     */
    public static function getAllStates(): array
    {
        return self::$circuits;
    }
}
