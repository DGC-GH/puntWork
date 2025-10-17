<?php
/**
 * Debug UI components for job import plugin
 * Contains debug information and testing tools
 *
 * @package    Puntwork
 * @subpackage Admin
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Render debug UI section (only in development)
 */
function render_debug_ui() {
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        return;
    }
    // Debug UI section removed
}
