<?php

/**
 * Distributed Tracing Utility for puntWork
 * Implements OpenTelemetry tracing for monitoring operations across the plugin
 */

namespace Puntwork;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

class PuntworkTracing
{
    private static ?object $tracerProvider       = null;
    private static ?object $tracer               = null;
    private static bool $opentelemetry_available = false;

    /**
     * Check if OpenTelemetry is available
     */
    private static function isOpenTelemetryAvailable(): bool
    {
        if (! self::$opentelemetry_available) {
            self::$opentelemetry_available = class_exists('OpenTelemetry\API\Trace\TracerInterface') &&
                class_exists('OpenTelemetry\SDK\Trace\TracerProvider') &&
                class_exists('OpenTelemetry\API\Trace\NoopTracer');
        }
        return self::$opentelemetry_available;
    }

    /**
     * Initialize the tracing system
     */
    public static function init(): void
    {
        if (self::$tracerProvider !== null) {
            return; // Already initialized
        }

        if (! self::isOpenTelemetryAvailable()) {
            // OpenTelemetry not available, disable tracing
            self::$tracerProvider = null;
            self::$tracer         = null;
            return;
        }

        try {
            // Create console exporter for development
            $transportFactory = new \OpenTelemetry\SDK\Common\Export\Stream\StreamTransportFactory();
            $transport        = $transportFactory->create('php://stdout', 'application/json');
            $exporter         = new \OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporter($transport);

            // Create span processor
            $spanProcessor = new \OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor($exporter);

            // Create tracer provider
            self::$tracerProvider = new \OpenTelemetry\SDK\Trace\TracerProvider($spanProcessor);

            // Get tracer
            self::$tracer = self::$tracerProvider->getTracer('puntwork', '1.0.0');
        } catch (\Throwable $e) {
            // Disable tracing if initialization fails
            self::$tracerProvider = null;
            self::$tracer         = null;
            error_log('PuntworkTracing initialization failed: ' . $e->getMessage());
        }
    }

    /**
     * Get the tracer instance
     */
    public static function getTracer(): object
    {
        if (self::$tracer === null) {
            self::init();
        }
        if (self::$tracer === null) {
            // Return a no-op tracer if initialization failed
            if (self::isOpenTelemetryAvailable()) {
                return \OpenTelemetry\API\Trace\NoopTracer::getInstance();
            }
            // Return a dummy object if OpenTelemetry is not available
            return new class () {
                public function spanBuilder($name)
                {
                    return new class () {
                        public function startSpan()
                        {
                            return new class () {
                                public function setAttribute($key, $value)
                                {
                                    return $this;
                                }
                                public function activate()
                                {
                                    return null;
                                }
                                public function end()
                                {
                                }
                                public function recordException($e)
                                {
                                }
                                public function setStatus($code, $message = '')
                                {
                                }
                            };
                        }
                    };
                }
            };
        }
        return self::$tracer;
    }

    /**
     * Start a new span
     */
    public static function startSpan(string $name, array $attributes = array()): object
    {
        $tracer = self::getTracer();
        $span   = $tracer->spanBuilder($name)->startSpan();

        foreach ($attributes as $key => $value) {
            $span->setAttribute($key, $value);
        }

        return $span;
    }

    /**
     * Start an active span (automatically makes it current)
     */
    public static function startActiveSpan(string $name, array $attributes = array()): object
    {
        $tracer = self::getTracer();
        $span   = $tracer->spanBuilder($name)->startSpan();

        foreach ($attributes as $key => $value) {
            $span->setAttribute($key, $value);
        }

        // Make span active
        $scope = $span->activate();

        return $span;
    }

    /**
     * Create a child span
     */
    public static function createChildSpan(string $name, array $attributes = array()): object
    {
        $tracer = self::getTracer();
        $span   = $tracer->spanBuilder($name)->startSpan();

        foreach ($attributes as $key => $value) {
            $span->setAttribute($key, $value);
        }

        return $span;
    }

    /**
     * Shutdown tracing (flush remaining spans)
     */
    public static function shutdown(): void
    {
        if (self::$tracerProvider !== null && self::isOpenTelemetryAvailable()) {
            self::$tracerProvider->shutdown();
        }
    }
}

// Initialize tracing on plugin load
add_action('init', __NAMESPACE__ . '\\PuntworkTracing::init');

// Shutdown tracing on plugin deactivation
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\PuntworkTracing::shutdown');
