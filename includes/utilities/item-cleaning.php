<?php

/**
 * Item cleaning and sanitization utilities.
 *
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function clean_item_fields( &$item ) {
	$html_fields = array( 'description', 'functiondescription', 'offerdescription', 'requirementsdescription', 'companydescription' );
	foreach ( $html_fields as $field ) {
		if ( isset( $item->$field ) ) {
			$content = (string) $item->$field;
			// Decode HTML entities first (handle double-encoding)
			$content      = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5 );
			$content      = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5 ); // Decode again for double-encoded content
			$content      = wp_kses( $content, wp_kses_allowed_html( 'post' ) );
			$content      = preg_replace( '/\s*style\s*=\s*["\'][^"\']*["\']/', '', $content );
			$content      = preg_replace( '/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F1E0}-\x{1F1FF}\x{2702}-\x{27B0}\x{24C2}-\x{1F251}\x{1F900}-\x{1F9FF}\x{1FA70}-\x{1FAFF}]/u', '', $content );
			$content      = str_replace( '&nbsp;', ' ', $content );
			$item->$field = trim( $content );
		}
	}
	$title_fields = array( 'functiontitle' );
	foreach ( $title_fields as $field ) {
		if ( isset( $item->$field ) ) {
			$content = (string) $item->$field;
			// Also decode HTML entities in titles
			$content      = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5 );
			$content      = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5 );
			$content      = preg_replace( '/\s+(m\/v\/x|h\/f\/x|m\/f\/x)$/i', '', $content );
			$item->$field = trim( $content );
		}
	}
}
