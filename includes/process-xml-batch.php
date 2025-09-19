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
        $reader = new XMLReader();
        if (!$reader->open($xml_path)) throw new Exception('Invalid XML');
        while ($reader->read()) {
            if ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'item') {
                $item = new stdClass();
                // Traverse child elements of <item>
                while ($reader->read() && !($reader->nodeType == XMLReader::END_ELEMENT && $reader->name == 'item')) {
                    if ($reader->nodeType == XMLReader::ELEMENT) {
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
                clean_item_fields($item);
                $lang = isset($item->languagecode) ? strtolower((string)$item->languagecode) : 'en';
                if (strpos($lang, 'fr') !== false) $lang = 'fr';
                else if (strpos($lang, 'nl') !== false) $lang = 'nl';
                else $lang = 'en';
                $job_obj = json_decode(json_encode($item), true);
                infer_item_details($item, $fallback_domain, $lang, $job_obj);

                $batch[] = json_encode($job_obj, JSON_UNESCAPED_UNICODE) . "\n";
                $feed_item_count++;
                if ($feed_item_count % 100 == 0) {
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Processed $feed_item_count items so far for $feed_key";
                    error_log("Processed $feed_item_count items so far for $feed_key");
                }
                if (count($batch) >= $batch_size) {
                    fwrite($handle, implode('', $batch));
                    $batch = [];
                    $total_items += $batch_size;
                }
                unset($item, $job_obj);
            }
        }
        if (!empty($batch)) {
            fwrite($handle, implode('', $batch));
            $total_items += count($batch);
        }
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Processed $feed_item_count items for $feed_key";
        error_log("Processed $feed_item_count items for $feed_key");
        $reader->close();
    } catch (Exception $e) {
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Processing error for $feed_key: " . $e->getMessage();
        error_log("Processing error for $feed_key: " . $e->getMessage());
    }
    return $feed_item_count;
}
