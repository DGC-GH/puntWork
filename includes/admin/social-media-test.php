<?php

/**
 * Social Media Integration Test
 *
 * @package    Puntwork
 * @subpackage Tests
 * @since      2.2.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Test social media platform configurations and posting
 */
function test_social_media_integration() {
	echo '<h2>Social Media Integration Test</h2>';

	if ( ! class_exists( '\\Puntwork\\SocialMedia\\SocialMediaManager' ) ) {
		echo "<p style='color: red;'>❌ Social Media classes not loaded</p>";
		return;
	}

	$social_manager = new \Puntwork\SocialMedia\SocialMediaManager();

	echo '<h3>Available Platforms:</h3>';
	$available_platforms = \Puntwork\SocialMedia\SocialMediaManager::getAvailablePlatforms();

	if ( empty( $available_platforms ) ) {
		echo "<p style='color: orange;'>⚠️ No platforms available</p>";
	} else {
		echo '<ul>';
		foreach ( $available_platforms as $platform_id => $platform_info ) {
			echo "<li><strong>{$platform_info['name']}</strong> ({$platform_id})</li>";
		}
		echo '</ul>';
	}

	echo '<h3>Configured Platforms:</h3>';
	$configured_platforms = $social_manager->getConfiguredPlatforms();

	if ( empty( $configured_platforms ) ) {
		echo "<p style='color: orange;'>⚠️ No platforms configured</p>";
		echo '<p><em>Configure platforms in the Social Media admin section first.</em></p>';
	} else {
		echo '<ul>';
		foreach ( $configured_platforms as $platform_id ) {
			$test_result = $social_manager->testPlatform( $platform_id );
			$status      = $test_result['success'] ? '✅ Connected' : '❌ Failed';
			$color       = $test_result['success'] ? 'green' : 'red';
			echo "<li><strong>{$platform_id}</strong>: <span style='color: {$color};'>{$status}</span>";
			if ( ! $test_result['success'] ) {
				echo ' - ' . esc_html( $test_result['message'] );
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	echo '<h3>Test Posting (Manual):</h3>';
	if ( ! empty( $configured_platforms ) ) {
		echo '<p>Manual posting test:</p>';
		echo "<form method='post'>";
		echo "<textarea name='test_content' rows='3' cols='50' placeholder='Enter test content...'>🚀 Testing puntWork social media integration!</textarea><br><br>";
		echo "<input type='submit' name='test_post' value='Test Post' class='puntwork-btn puntwork-btn--primary'>";
		echo '</form>';

		if ( isset( $_POST['test_post'] ) && ! empty( $_POST['test_content'] ) ) {
			$content = sanitize_textarea_field( $_POST['test_content'] );
			$results = $social_manager->postToPlatforms( array( 'text' => $content ), $configured_platforms );

			echo '<h4>Test Results:</h4>';
			echo '<ul>';
			foreach ( $results as $platform_id => $result ) {
				$status = $result['success'] ? '✅ Posted' : '❌ Failed';
				$color  = $result['success'] ? 'green' : 'red';
				echo "<li><strong>{$platform_id}</strong>: <span style='color: {$color};'>{$status}</span>";
				if ( ! $result['success'] ) {
					echo ' - ' . esc_html( $result['error'] );
				}
				if ( $result['success'] && isset( $result['post_id'] ) ) {
					echo " (ID: {$result['post_id']})";
				}
				echo '</li>';
			}
			echo '</ul>';
		}
	}

	echo '<h3>Auto-Posting Settings:</h3>';
	$auto_post         = get_option( 'puntwork_social_auto_post_jobs', false );
	$default_platforms = get_option( 'puntwork_social_default_platforms', array() );
	$post_template     = get_option( 'puntwork_social_post_template', 'default' );

	echo '<ul>';
	echo '<li>Auto-post new jobs: ' . ( $auto_post ? '✅ Enabled' : '❌ Disabled' ) . '</li>';
	echo '<li>Default platforms: ' . ( empty( $default_platforms ) ? 'None' : implode( ', ', $default_platforms ) ) . '</li>';
	echo "<li>Post template: {$post_template}</li>";
	echo '</ul>';
}

// Add to admin page for testing
add_action(
	'admin_menu',
	function () {
		add_submenu_page(
			'puntwork-admin',
			__( 'Social Media Test', 'puntwork' ),
			__( 'Social Media Test', 'puntwork' ),
			'manage_options',
			'puntwork-social-test',
			function () {
				echo '<div class="wrap">';
				test_social_media_integration();
				echo '</div>';
			}
		);
	}
);
