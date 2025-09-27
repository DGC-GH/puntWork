<?php

/**
 * Distributed Tracing Utility for puntWork
 * Implements OpenTelemetry tracing for monitoring operations across the plugin
 */

namespace Puntwork;

use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Common\Export\Stream\StreamTransportFactory;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PuntworkTracing
{
    private static ?TracerProviderInterface $tracerProvider = null;
    private static ?TracerInterface $tracer = null;

    /**
     * Initialize the tracing system
     */
    public static function init(): void
    {
        if (self::$tracerProvider !== null) {
            return; // Already initialized
        }

        // Create console exporter for development
        $transportFactory = new StreamTransportFactory();
        $transport = $transportFactory->create('php://stdout', 'application/json');
        $exporter = new ConsoleSpanExporter($transport);

        // Create span processor
        $spanProcessor = new SimpleSpanProcessor($exporter);

        // Create tracer provider
        self::$tracerProvider = new TracerProvider($spanProcessor);

        // Get tracer
        self::$tracer = self::$tracerProvider->getTracer('puntwork', '1.0.0');
    }

    /**
     * Get the tracer instance
     */
    public static function getTracer(): TracerInterface
    {
        if (self::$tracer === null) {
            self::init();
        }
        return self::$tracer;
    }

    /**
     * Start a new span
     */
    public static function startSpan(string $name, array $attributes = []): \OpenTelemetry\API\Trace\SpanInterface
    {
        $tracer = self::getTracer();
        $span = $tracer->spanBuilder($name)->startSpan();

        foreach ($attributes as $key => $value) {
            $span->setAttribute($key, $value);
        }

        return $span;
    }

    /**
     * Start an active span (automatically makes it current)
     */
    public static function startActiveSpan(string $name, array $attributes = []): \OpenTelemetry\API\Trace\SpanInterface
    {
        $tracer = self::getTracer();
        $span = $tracer->spanBuilder($name)->startSpan();

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
    public static function createChildSpan(string $name, array $attributes = []): \OpenTelemetry\API\Trace\SpanInterface
    {
        $tracer = self::getTracer();
        $span = $tracer->spanBuilder($name)->startSpan();

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
        if (self::$tracerProvider !== null) {
            self::$tracerProvider->shutdown();
        }
    }
}

// Initialize tracing on plugin load
add_action('init', __NAMESPACE__ . '\\PuntworkTracing::init');

// Shutdown tracing on plugin deactivation
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\PuntworkTracing::shutdown');
