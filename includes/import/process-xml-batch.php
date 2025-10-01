<?php

/**
 * XML batch processing utilities.
 *
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function process_xml_batch( $xml_path, $handle, $feed_key, $output_dir, $fallback_domain, $batch_size, &$total_items, &$logs ) {
	$feed_item_count = 0;
	$batch           = array();

	try {
		$reader = new \XMLReader();
		if ( ! $reader->open( $xml_path ) ) {
			throw new \Exception( 'Invalid XML' );
		}

		// Possible job element names in feeds
		$job_element_names = array( 'item', 'job', 'vacancy', 'position', 'entry', 'listing' );

		while ( $reader->read() ) {
			if ( $reader->nodeType === \XMLReader::ELEMENT && in_array( strtolower( $reader->name ), $job_element_names ) ) {
				try {
					$item         = new \stdClass();
					$element_name = strtolower( $reader->name );

					// Use expand() to get the full element as DOMElement for safer parsing
					$dom_element = $reader->expand();
					if ( $dom_element ) {
						// Extract all child elements and text content
						foreach ( $dom_element->childNodes as $child ) {
							if ( $child->nodeType === XML_ELEMENT_NODE ) {
								$name = strtolower( preg_replace( '/^.*:/', '', $child->tagName ) );
								// Get text content only, ignore nested XML structures
								$value = '';
								foreach ( $child->childNodes as $text_node ) {
									if ( $text_node->nodeType === XML_TEXT_NODE || $text_node->nodeType === XML_CDATA_SECTION_NODE ) {
										$value .= $text_node->textContent;
									}
								}
								$item->$name = trim( $value );
							} elseif ( $child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE ) {
								// Handle text content at job element level
								if ( ! isset( $item->description ) ) {
									$item->description = trim( $child->textContent );
								}
							}
						}

						// Also try to get attributes
						foreach ( $dom_element->attributes as $attr ) {
							$name           = strtolower( preg_replace( '/^.*:/', '', $attr->name ) );
							$item->$name = $attr->value;
						}
					} else {
						// Fallback to the old method if expand() fails
						$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key: Using fallback XML parsing for item";
						while ( $reader->read() && ! ( $reader->nodeType === \XMLReader::END_ELEMENT && strtolower( $reader->name ) === $element_name ) ) {
							if ( $reader->nodeType === \XMLReader::ELEMENT ) {
								$name = strtolower( preg_replace( '/^.*:/', '', $reader->name ) );
								if ( $reader->isEmptyElement ) {
									$item->$name = '';
								} else {
									try {
										$value       = $reader->readInnerXML();
										$item->$name = $value;
									} catch ( \Exception $xml_error ) {
										$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key: XML parsing error for field $name: " . $xml_error->getMessage();
										$item->$name = '';
									}
								}
							}
						}
					}

					// If item is empty or failed to collect fields, skip and log
					if ( empty( (array) $item ) ) {
						$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key item skipped: No fields collected";
						continue;
					}

					clean_item_fields( $item );

					// Generate GUID if missing
					if ( ! isset( $item->guid ) || empty( $item->guid ) ) {
						// Generate GUID from title, company, and location if available
						$guid_source = '';
						if ( isset( $item->functiontitle ) ) {
							$guid_source .= (string) $item->functiontitle;
						}
						if ( isset( $item->companydescription ) ) {
							$guid_source .= (string) $item->companydescription;
						}
						if ( isset( $item->city ) ) {
							$guid_source .= (string) $item->city;
						}
						if ( isset( $item->applylink ) ) {
							$guid_source .= (string) $item->applylink;
						}

						if ( ! empty( $guid_source ) ) {
							$item->guid = md5( $guid_source );
							$logs[]     = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key: Generated GUID for item: " . $item->guid;
						} else {
							$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key: Skipping item - no unique fields for GUID generation";
							continue;
						}
					}

					$lang = isset( $item->languagecode ) ? strtolower( (string) $item->languagecode ) : 'en';
					if ( strpos( $lang, 'fr' ) !== false ) {
						$lang = 'fr';
					} elseif ( strpos( $lang, 'nl' ) !== false ) {
						$lang = 'nl';
					} else {
						$lang = 'en';
					}

					$job_obj = json_decode( json_encode( $item ), true );
					infer_item_details( $item, $fallback_domain, $lang, $job_obj );

					// Validate JSON encoding before adding to batch
					$json_line = json_encode( $job_obj, JSON_UNESCAPED_UNICODE );
					if ( $json_line === false ) {
						$json_error = json_last_error_msg();
						$logs[]     = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key: JSON encoding failed for item with GUID {$item->guid}: $json_error";
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( "$feed_key: JSON encoding failed: $json_error" );
						}
						continue; // Skip this item
					}

					$batch[] = $json_line . "\n";
					++$feed_item_count;

					if ( $feed_item_count % 100 === 0 ) {
						$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "Processed $feed_item_count items so far for $feed_key";
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( "Processed $feed_item_count items so far for $feed_key" );
						}
					}

					if ( count( $batch ) >= $batch_size ) {
						fwrite( $handle, implode( '', $batch ) );
						$batch        = array();
						$total_items += $batch_size;
					}

					unset( $item, $job_obj, $dom_element );

				} catch ( \Exception $e ) {
					$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "$feed_key: Error processing XML item: " . $e->getMessage();
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( "$feed_key: Error processing XML item: " . $e->getMessage() );
					}
					// Continue with next item
				}
			}
		}

		if ( ! empty( $batch ) ) {
			fwrite( $handle, implode( '', $batch ) );
			$total_items += count( $batch );
		}

		$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "Processed $feed_item_count items for $feed_key";
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( "Processed $feed_item_count items for $feed_key" );
		}

		$reader->close();

	} catch ( \Exception $e ) {
		$logs[] = '[' . date( 'd-M-Y H:i:s' ) . ' UTC] ' . "Processing error for $feed_key: " . $e->getMessage();
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( "Processing error for $feed_key: " . $e->getMessage() );
		}
	}

	return $feed_item_count;
}
