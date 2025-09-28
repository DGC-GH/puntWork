<?php

/**
 * Accessibility and Keyboard Shortcuts Handler for puntWork
 * Provides server-side support for accessibility features
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle AJAX request to clear cache
 */
function handle_clear_cache() {
	// Verify nonce
	if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'puntwork_accessibility_nonce' ) ) {
		wp_send_json_error( array( 'message' => __( 'Security check failed.', 'puntwork' ) ) );
		return;
	}

	// Clear various caches
	try {
		// Clear WordPress object cache
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		// Clear transients
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'" );

		// Clear puntWork specific caches
		delete_option( 'puntwork_feed_cache' );
		delete_option( 'puntwork_analytics_cache' );
		delete_option( 'puntwork_performance_cache' );

		// Clear any cached feed data
		$feed_cache_keys = get_option( 'puntwork_feed_cache_keys', array() );
		foreach ( $feed_cache_keys as $key ) {
			delete_transient( $key );
		}
		delete_option( 'puntwork_feed_cache_keys' );

		wp_send_json_success(
			array(
				'message' => __( 'Cache cleared successfully. Page will refresh.', 'puntwork' ),
			)
		);
	} catch ( Exception $e ) {
		wp_send_json_error(
			array(
				'message' => __( 'Error clearing cache: ', 'puntwork' ) . $e->getMessage(),
			)
		);
	}
}
add_action( 'wp_ajax_puntwork_clear_cache', __NAMESPACE__ . '\\handle_clear_cache' );

/**
 * Add accessibility meta tags and headers
 */
function add_accessibility_headers() {
	if ( is_admin() && isset( $_GET['page'] ) && strpos( $_GET['page'], 'puntwork' ) == 0 ) {
		// Add viewport meta tag for proper mobile accessibility
		add_action(
			'admin_head',
			function () {
				echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n";
			}
		);

		// Add accessibility-related meta tags
		add_action(
			'admin_head',
			function () {
				echo '<meta name="application-name" content="puntWork Admin">' . "\n";
				echo '<meta name="theme-color" content="#007aff">' . "\n";
			}
		);
	}
}
add_action( 'admin_init', __NAMESPACE__ . '\\add_accessibility_headers' );

/**
 * Add accessibility attributes to admin menu items
 */
function enhance_admin_menu_accessibility( $menu ) {
	if ( is_admin() && is_array( $menu ) ) {
		foreach ( $menu as &$item ) {
			if ( isset( $item[2] ) && strpos( $item[2], 'puntwork' ) == 0 ) {
				// Add aria-label for better screen reader support
				$item[4] = ( $item[4] ?? '' ) . ' aria-label="' . esc_attr( $item[0] ) . '"';
			}
		}
	} elseif ( is_admin() && ! is_array( $menu ) ) {
		error_log( '[PUNTWORK] enhance_admin_menu_accessibility received non-array menu parameter: ' . gettype( $menu ) . ' - returning unchanged' );
	}
	return $menu;
}
add_filter( 'admin_menu', __NAMESPACE__ . '\\enhance_admin_menu_accessibility' );

/**
 * Add keyboard shortcuts help to admin bar
 */
function add_keyboard_shortcuts_help( $wp_admin_bar ) {
	if ( is_admin() && isset( $_GET['page'] ) && strpos( $_GET['page'], 'puntwork' ) == 0 ) {
		$wp_admin_bar->add_node(
			array(
				'id'    => 'puntwork-keyboard-help',
				'title' => '<span class="ab-icon dashicons dashicons-editor-help"></span> ' . __( 'Keyboard Shortcuts', 'puntwork' ),
				'href'  => '#',
				'meta'  => array(
					'onclick' => 'if(window.puntworkAccessibility){window.puntworkAccessibility.showKeyboardShortcutsHelp();} return false;',
					'title'   => __( 'Show keyboard shortcuts help (Ctrl+H)', 'puntwork' ),
				),
			)
		);
	}
}
add_action( 'admin_bar_menu', __NAMESPACE__ . '\\add_keyboard_shortcuts_help', 100 );

/**
 * Add accessibility notices for screen readers
 */
function add_accessibility_notices() {
	if ( is_admin() && isset( $_GET['page'] ) && strpos( $_GET['page'], 'puntwork' ) == 0 ) {
		// Add live region for dynamic content updates
		add_action(
			'admin_footer',
			function () {
				echo '<div id="puntwork-live-region" aria-live="polite" aria-atomic="true" class="screen-reader-only"></div>' . "\n";
			}
		);

		// Add main content landmark
		add_action(
			'admin_footer',
			function () {
				echo '<div id="main-content" role="main" aria-label="' . esc_attr__( 'puntWork Main Content', 'puntwork' ) . '"></div>' . "\n";
			}
		);
	}
}
add_action( 'admin_init', __NAMESPACE__ . '\\add_accessibility_notices' );

/**
 * Enhance form accessibility
 */
function enhance_form_accessibility() {
	if ( is_admin() && isset( $_GET['page'] ) && strpos( $_GET['page'], 'puntwork' ) == 0 ) {
		// Add required field indicators
		add_action(
			'admin_footer',
			function () {
				?>
			<script>
				document.addEventListener('DOMContentLoaded', function() {
					// Add aria-required to required fields
					document.querySelectorAll('input[required], select[required], textarea[required]').forEach(function(field) {
						field.setAttribute('aria-required', 'true');
					});

					// Add aria-describedby for fields with help text
					document.querySelectorAll('.description, .help-text').forEach(function(help) {
						const field = help.previousElementSibling;
						if (field && (field.tagName == 'INPUT' || field.tagName == 'SELECT' || field.tagName == 'TEXTAREA')) {
							const helpId = 'help-' + Math.random().toString(36).substr(2, 9);
							help.id = helpId;
							field.setAttribute('aria-describedby', helpId);
						}
					});

					// Enhance error messages
					document.querySelectorAll('.error, .notice-error').forEach(function(error) {
						error.setAttribute('role', 'alert');
						error.setAttribute('aria-live', 'assertive');
					});

					// Enhance success messages
					document.querySelectorAll('.success, .notice-success, .updated').forEach(function(success) {
						success.setAttribute('role', 'status');
						success.setAttribute('aria-live', 'polite');
					});
				});
			</script>
				<?php
			}
		);
	}
}
add_action( 'admin_init', __NAMESPACE__ . '\\enhance_form_accessibility' );

/**
 * Add accessibility testing utilities (for development)
 */
function add_accessibility_testing() {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG && is_admin() && isset( $_GET['page'] ) && strpos( $_GET['page'], 'puntwork' ) == 0 ) {
		add_action(
			'admin_footer',
			function () {
				?>
			<script>
				// Add accessibility testing utilities
				window.puntworkAccessibilityTest = {
					checkAltText: function() {
						const images = document.querySelectorAll('img');
						const missingAlt = [];
						images.forEach(img => {
							if (!img.getAttribute('alt') && !img.getAttribute('aria-hidden')) {
								missingAlt.push(img);
							}
						});
						console.log('Images missing alt text:', missingAlt.length);
						return missingAlt;
					},

					checkAriaLabels: function() {
						const interactive = document.querySelectorAll('button, [role="button"], input, select, textarea');
						const missingLabels = [];
						interactive.forEach(el => {
							if (!el.getAttribute('aria-label') && !el.getAttribute('aria-labelledby') && !el.textContent.trim()) {
								missingLabels.push(el);
							}
						});
						console.log('Interactive elements missing labels:', missingLabels.length);
						return missingLabels;
					},

					checkHeadingStructure: function() {
						const headings = document.querySelectorAll('h1, h2, h3, h4, h5, h6');
						const levels = [];
						headings.forEach(h => {
							levels.push(parseInt(h.tagName.charAt(1)));
						});
						console.log('Heading structure:', levels);
						return levels;
					},

					runAllChecks: function() {
						console.group('🔍 puntWork Accessibility Tests');
						this.checkAltText();
						this.checkAriaLabels();
						this.checkHeadingStructure();
						console.groupEnd();
					}
				};

				// Add keyboard shortcut to run tests (Ctrl+Shift+T)
				document.addEventListener('keydown', function(e) {
					if (e.ctrlKey && e.shiftKey && e.key == 'T') {
						e.preventDefault();
						window.puntworkAccessibilityTest.runAllChecks();
					}
				});
			</script>
				<?php
			}
		);
	}
}
add_action( 'admin_init', __NAMESPACE__ . '\\add_accessibility_testing' );