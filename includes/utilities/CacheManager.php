<?php

/**
 * Enhanced caching utility with Redis support
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
 * Enhanced caching utility with Redis support
 */
class CacheManager
{

    /**
     * Cache group for mappings
     */
    public const GROUP_MAPPINGS = 'puntwork_mappings';

    /**
     * Cache group for analytics
     */
    public const GROUP_ANALYTICS = 'puntwork_analytics';

    /**
     * Check if Redis/Object Cache is available
     *
     * @return bool True if Redis/Object Cache is available
     */
    public static function isRedisAvailable(): bool
    {
        static $redis_available = null;

        if ($redis_available === null ) {
            $redis_available = function_exists('wp_cache_get') && function_exists('wp_cache_set');
            if ($redis_available ) {
                // Test if cache is actually working
                try {
                    $test_result = wp_cache_set('puntwork_cache_test', 'test_value', 'puntwork_test', 60);
                    if ($test_result ) {
                        $redis_available = ( wp_cache_get('puntwork_cache_test', 'puntwork_test') === 'test_value' );
                        wp_cache_delete('puntwork_cache_test', 'puntwork_test');
                    } else {
                        $redis_available = false;
                    }
                } catch ( \Exception $e ) {
                    $redis_available = false;
                }
            }
        }

        return $redis_available;
    }

    /**
     * Get cached data with Redis support
     *
     * @param  string $key   Cache key
     * @param  string $group Cache group
     * @return mixed Cached data or false
     */
    public static function get( string $key, string $group = '' )
    {
        // Try Redis/Object Cache first
        if (self::isRedisAvailable() ) {
            $cached = wp_cache_get($key, $group);
            if ($cached !== false ) {
                return $cached;
            }
        }

        // Fallback to transients
        $transient_key = $group ? $group . '_' . $key : $key;
        return get_transient($transient_key);
    }

    /**
     * Set cached data with Redis support
     *
     * @param  string $key        Cache key
     * @param  mixed  $data       Data to cache
     * @param  string $group      Cache group
     * @param  int    $expiration Expiration time in seconds
     * @return bool True on success
     */
    public static function set( string $key, $data, string $group = '', int $expiration = 3600 ): bool
    {
        // Try Redis/Object Cache first
        if (self::isRedisAvailable() ) {
            $result = wp_cache_set($key, $data, $group, $expiration);
            if ($result ) {
                return true;
            }
        }

        // Fallback to transients
        $transient_key = $group ? $group . '_' . $key : $key;
        return set_transient($transient_key, $data, $expiration);
    }

    /**
     * Delete cached data
     *
     * @param  string $key   Cache key
     * @param  string $group Cache group
     * @return bool True on success
     */
    public static function delete( string $key, string $group = '' ): bool
    {
        // Try Redis/Object Cache first
        if (self::isRedisAvailable() ) {
            wp_cache_delete($key, $group);
        }

        // Also clear transients
        $transient_key = $group ? $group . '_' . $key : $key;
        return delete_transient($transient_key);
    }

    /**
     * Clear all cache in a group
     *
     * @param  string $group Cache group
     * @return bool True on success
     */
    public static function clearGroup( string $group ): bool
    {
        if (self::isRedisAvailable() ) {
            // For Redis, we can't easily clear a group, so we'll flush the entire cache
            // This is a limitation of the WordPress object cache API
            wp_cache_flush();
        }

        // Clear transients with group prefix
        global $wpdb;
        $transient_prefix = '_transient_' . $group . '_';
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $transient_prefix . '%'
            )
        );

        return true;
    }

    /**
     * Get cache stats
     *
     * @return array Cache statistics
     */
    public static function getStats(): array
    {
        return array(
        'redis_available'          => self::isRedisAvailable(),
        'cache_groups'             => array( self::GROUP_MAPPINGS, self::GROUP_ANALYTICS ),
        'wp_cache_supports_groups' => function_exists('wp_cache_supports') ? wp_cache_supports('groups') : false,
        );
    }
}
