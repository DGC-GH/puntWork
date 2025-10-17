<?php
/**
 * Item cleaning and sanitization utilities
 *
 * @package    Puntwork
 * @subpackage Utilities
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function clean_item_fields(&$item) {
    // Ensure $item is an object, convert if necessary
    if (!is_object($item)) {
        $item = (object)$item;
    }

    $html_fields = ['description', 'functiondescription', 'offerdescription', 'requirementsdescription', 'companydescription'];
    foreach ($html_fields as $field) {
        if (isset($item->$field) && $item->$field !== null) {
            $content = $item->$field;
            // Handle null values explicitly
            if ($content === null) {
                $content = '';
            }
            $content = (string)$content;
            $content = wp_kses($content, wp_kses_allowed_html('post'));
            $content = preg_replace('/\s*style\s*=\s*["\'][^"\']*["\']/', '', $content);
            $content = preg_replace('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F1E0}-\x{1F1FF}\x{2702}-\x{27B0}\x{24C2}-\x{1F251}\x{1F900}-\x{1F9FF}\x{1FA70}-\x{1FAFF}]/u', '', $content);
            $content = str_replace('&nbsp;', ' ', $content);
            $item->$field = trim($content);
        } else {
            // If field doesn't exist or is null, set to empty string
            $item->$field = '';
        }
    }

    $title_fields = ['functiontitle', 'title'];
    foreach ($title_fields as $field) {
        if (isset($item->$field) && $item->$field !== null) {
            $content = $item->$field;
            // Handle null values explicitly
            if ($content === null) {
                $content = '';
            }
            $content = (string)$content;
            $content = preg_replace('/\s+(m\/v\/x|h\/f\/x|m\/f\/x)$/i', '', $content);
            $item->$field = trim($content);
        } else {
            // If field doesn't exist or is null, set to empty string
            $item->$field = '';
        }
    }

    // Ensure other commonly used fields have default values
    $default_fields = ['guid', 'pubdate', 'location', 'company', 'province', 'city', 'source_feed_slug'];
    foreach ($default_fields as $field) {
        if (!isset($item->$field) || $item->$field === null) {
            $item->$field = '';
        } elseif (!is_string($item->$field)) {
            $item->$field = (string)$item->$field;
        }
    }
}
