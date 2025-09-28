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

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( $hook == 'puntwork-dashboard_page_job-feed-dashboard' ) {
			wp_deregister_script( 'heartbeat' );
		}
	}
);
