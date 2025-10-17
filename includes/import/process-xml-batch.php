<?php
/**
 * XML batch processing utilities
 *
 * @package    Puntwork
 * @subpackage Processing
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function process_xml_batch($xml_path, $handle, $feed_key, $output_dir, $fallback_domain, $batch_size, &$total_items, &$logs) {
    $feed_item_count = 0;
    $batch = [];

    try {
        $reader = new \XMLReader();
        if (!$reader->open($xml_path)) {
            throw new \Exception('Invalid XML file: ' . $xml_path);
        }

        while ($reader->read()) {
            if ($reader->nodeType == \XMLReader::ELEMENT && $reader->name == 'item') {
                $item = new \stdClass();

                // Traverse child elements of <item>
                while ($reader->read() && !($reader->nodeType == \XMLReader::END_ELEMENT && $reader->name == 'item')) {
                    if ($reader->nodeType == \XMLReader::ELEMENT) {
                        $name = strtolower(preg_replace('/^.*:/', '', $reader->name));
                        if ($reader->isEmptyElement) {
                            $item->$name = '';
                        } else {
                            $value = $reader->readInnerXML();
                            $item->$name = $value;
                        }
                    }
                }

                // If item is empty or failed to collect fields, skip and log
                if (empty((array)$item)) {
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$feed_key item skipped: No fields collected";
                    continue;
                }

                // Clean and validate item fields
                $cleaned_item = clean_item_fields($item);

                // Convert to array and infer additional details
                $job_obj = json_decode(json_encode($cleaned_item), true);
                $lang = isset($cleaned_item->languagecode) ? strtolower((string)$cleaned_item->languagecode) : 'en';
                if (strpos($lang, 'fr') !== false) $lang = 'fr';
                else if (strpos($lang, 'nl') !== false) $lang = 'nl';
                else $lang = 'en';

                infer_item_details($cleaned_item, $fallback_domain, $lang, $job_obj);

                // Encode to JSON for JSONL output
                $batch[] = json_encode($job_obj, JSON_UNESCAPED_UNICODE) . "\n";
                $feed_item_count++;

                // Progress logging every 500 items to reduce overhead
                if ($feed_item_count % 500 == 0) {
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Processed $feed_item_count items for $feed_key";
                }

                // Process batch when it reaches batch_size
                if (count($batch) >= $batch_size) {
                    fwrite($handle, implode('', $batch));
                    $batch = [];
                    $total_items += $batch_size;
                }

                // Clean up memory
                unset($item, $cleaned_item, $job_obj);
            }
        }

        // Write remaining items in batch
        if (!empty($batch)) {
            fwrite($handle, implode('', $batch));
            $total_items += count($batch);
        }

        $reader->close();

        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "XML processing complete for $feed_key: $feed_item_count items processed";

    } catch (\Exception $e) {
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Processing error for $feed_key: " . $e->getMessage();
        error_log("XML processing error for $feed_key: " . $e->getMessage());
        // Continue processing even if this feed fails
        return $feed_item_count;
    }

    return $feed_item_count;
}
