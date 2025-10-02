<?php

/**
 * Heartbeat control for admin interface.
 *
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Disable WordPress heartbeat globally to prevent unnecessary automatic requests
add_action(
	'init',
	function () {
		// Disable heartbeat entirely for this plugin
		wp_deregister_script( 'heartbeat' );
		
		// Remove heartbeat actions to prevent any processing
		remove_action( 'wp_ajax_heartbeat', 'wp_ajax_heartbeat' );
		remove_action( 'wp_ajax_nopriv_heartbeat', 'wp_ajax_nopriv_heartbeat' );
	}
);

// Filter to disable heartbeat settings entirely
add_filter( 'heartbeat_settings', function( $settings ) {
	$settings['autostart'] = false;
	$settings['interval'] = 0;
	return $settings;
} );

// Additional admin-specific deregistration (fallback)
add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		wp_deregister_script( 'heartbeat' );
	}
);
