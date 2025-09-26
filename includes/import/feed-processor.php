<?php
/**
 * Multi-format feed processing utilities
 *
 * @package    Puntwork
 * @subpackage Processing
 * @since      1.0.13
 */

namespace Puntwork;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Feed format detection and processing
 */
class FeedProcessor {

    const FORMAT_XML = 'xml';
    const FORMAT_JSON = 'json';
    const FORMAT_CSV = 'csv';

    /**
     * Detect feed format from URL or content
     *
     * @param string $url Feed URL
     * @param string $content Optional content to analyze
     * @return string Detected format
     */
    public static function detect_format($url, $content = null) {
        // Check URL extension first
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        switch ($extension) {
            case 'xml':
                return self::FORMAT_XML;
            case 'json':
                return self::FORMAT_JSON;
            case 'csv':
                return self::FORMAT_CSV;
        }

        // If no extension or unknown, try content analysis
        if ($content !== null) {
            $content = trim($content);

            // Check for XML
            if (strpos($content, '<?xml') === 0 || strpos($content, '<') === 0) {
                return self::FORMAT_XML;
            }

            // Check for JSON
            if ((strpos($content, '{') === 0 || strpos($content, '[') === 0)) {
                json_decode($content);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return self::FORMAT_JSON;
                }
            }

            // Check for CSV (look for comma-separated values with headers)
            $lines = explode("\n", $content);
            if (count($lines) > 1) {
                $first_line = trim($lines[0]);
                $second_line = trim($lines[1] ?? '');

                // Simple heuristic: if first line has commas and second line exists
                if (strpos($first_line, ',') !== false && !empty($second_line)) {
                    return self::FORMAT_CSV;
                }
            }
        }

        // Default to XML for backward compatibility
        return self::FORMAT_XML;
    }

    /**
     * Process feed based on detected format
     *
     * @param string $feed_path Path to downloaded feed file
     * @param string $format Feed format
     * @param string $handle Feed handle/key
     * @param string $output_dir Output directory
     * @param string $fallback_domain Fallback domain
     * @param int $batch_size Batch size
     * @param int &$total_items Total items counter
     * @param array &$logs Logs array
     * @return array Processed batch data
     */
    public static function process_feed($feed_path, $format, $handle, $output_dir, $fallback_domain, $batch_size, &$total_items, &$logs) {
        switch ($format) {
            case self::FORMAT_XML:
                return self::process_xml_feed($feed_path, $handle, $output_dir, $fallback_domain, $batch_size, $total_items, $logs);
            case self::FORMAT_JSON:
                return self::process_json_feed($feed_path, $handle, $output_dir, $fallback_domain, $batch_size, $total_items, $logs);
            case self::FORMAT_CSV:
                return self::process_csv_feed($feed_path, $handle, $output_dir, $fallback_domain, $batch_size, $total_items, $logs);
            default:
                throw new \Exception("Unsupported feed format: $format");
        }
    }

    /**
     * Process XML feed (existing functionality)
     */
    private static function process_xml_feed($xml_path, $handle, $output_dir, $fallback_domain, $batch_size, &$total_items, &$logs) {
        return process_xml_batch($xml_path, null, $handle, $output_dir, $fallback_domain, $batch_size, $total_items, $logs);
    }

    /**
     * Process JSON feed
     */
    private static function process_json_feed($json_path, $handle, $output_dir, $fallback_domain, $batch_size, &$total_items, &$logs) {
        $feed_item_count = 0;
        $batch = [];

        try {
            $content = file_get_contents($json_path);
            if ($content === false) {
                throw new \Exception("Could not read JSON file: $json_path");
            }

            $data = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("Invalid JSON: " . json_last_error_msg());
            }

            // Handle different JSON structures
            $items = self::extract_json_items($data);

            foreach ($items as $item_data) {
                if (!is_array($item_data) && !is_object($item_data)) {
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$handle item skipped: Invalid item structure";
                    continue;
                }

                // Convert to object for consistent processing
                $item = is_object($item_data) ? $item_data : (object) $item_data;

                // Normalize field names to lowercase
                $normalized_item = new \stdClass();
                foreach ($item as $key => $value) {
                    $normalized_key = strtolower($key);
                    $normalized_item->$normalized_key = $value;
                }
                $item = $normalized_item;

                // Skip empty items
                if (empty((array)$item)) {
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$handle item skipped: No fields collected";
                    continue;
                }

                clean_item_fields($item);

                // Language detection
                $lang = isset($item->languagecode) ? strtolower((string)$item->languagecode) : 'en';
                if (strpos($lang, 'fr') !== false) $lang = 'fr';
                elseif (strpos($lang, 'nl') !== false) $lang = 'nl';
                else $lang = 'en';

                $job_obj = json_decode(json_encode($item), true);
                infer_item_details($item, $fallback_domain, $lang, $job_obj);

                $batch[] = json_encode($job_obj, JSON_UNESCAPED_UNICODE) . "\n";
                $feed_item_count++;

                // Process in batches
                if ($feed_item_count >= $batch_size) {
                    $total_items += $feed_item_count;
                    return $batch;
                }
            }

            $total_items += $feed_item_count;
            return $batch;

        } catch (\Exception $e) {
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$handle JSON processing error: " . $e->getMessage();
            throw $e;
        }
    }

    /**
     * Process CSV feed
     */
    private static function process_csv_feed($csv_path, $handle, $output_dir, $fallback_domain, $batch_size, &$total_items, &$logs) {
        $feed_item_count = 0;
        $batch = [];

        try {
            if (!file_exists($csv_path)) {
                throw new \Exception("CSV file not found: $csv_path");
            }

            $handle_resource = fopen($csv_path, 'r');
            if ($handle_resource === false) {
                throw new \Exception("Could not open CSV file: $csv_path");
            }

            // Detect delimiter and read headers
            $delimiter = self::detect_csv_delimiter($csv_path);
            $headers = fgetcsv($handle_resource, 0, $delimiter);

            if (!$headers || count($headers) < 2) {
                throw new \Exception("Invalid CSV format or no headers found");
            }

            // Normalize headers to lowercase
            $headers = array_map('strtolower', $headers);

            while (($row = fgetcsv($handle_resource, 0, $delimiter)) !== false) {
                if (count($row) !== count($headers)) {
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$handle row skipped: Column count mismatch";
                    continue;
                }

                // Convert row to object
                $item = new \stdClass();
                foreach ($headers as $index => $header) {
                    $item->$header = $row[$index] ?? '';
                }

                // Skip empty items
                if (empty((array)$item)) {
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$handle item skipped: No fields collected";
                    continue;
                }

                clean_item_fields($item);

                // Language detection
                $lang = isset($item->languagecode) ? strtolower((string)$item->languagecode) : 'en';
                if (strpos($lang, 'fr') !== false) $lang = 'fr';
                elseif (strpos($lang, 'nl') !== false) $lang = 'nl';
                else $lang = 'en';

                $job_obj = json_decode(json_encode($item), true);
                infer_item_details($item, $fallback_domain, $lang, $job_obj);

                $batch[] = json_encode($job_obj, JSON_UNESCAPED_UNICODE) . "\n";
                $feed_item_count++;

                // Process in batches
                if ($feed_item_count >= $batch_size) {
                    $total_items += $feed_item_count;
                    fclose($handle_resource);
                    return $batch;
                }
            }

            fclose($handle_resource);
            $total_items += $feed_item_count;
            return $batch;

        } catch (\Exception $e) {
            if (isset($handle_resource) && is_resource($handle_resource)) {
                fclose($handle_resource);
            }
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$handle CSV processing error: " . $e->getMessage();
            throw $e;
        }
    }

    /**
     * Extract items from various JSON structures
     */
    private static function extract_json_items($data) {
        // If it's an array of objects, return as is
        if (is_array($data) && !empty($data) && (is_array($data[0]) || is_object($data[0]))) {
            return $data;
        }

        // If it's an object with a common items array
        if (is_object($data) || is_array($data)) {
            $possible_keys = ['jobs', 'items', 'data', 'results', 'feed', 'entries'];

            foreach ($possible_keys as $key) {
                if (isset($data[$key]) && is_array($data[$key])) {
                    return $data[$key];
                }
            }
        }

        // If it's a single object, wrap in array
        if (is_object($data) || is_array($data)) {
            return [$data];
        }

        return [];
    }

    /**
     * Detect CSV delimiter by analyzing the first few lines
     */
    private static function detect_csv_delimiter($file_path) {
        $handle = fopen($file_path, 'r');
        $delimiters = [',', ';', '\t', '|'];
        $counts = array_fill_keys($delimiters, 0);

        // Read first 5 lines to analyze
        for ($i = 0; $i < 5 && ($line = fgets($handle)); $i++) {
            foreach ($delimiters as $delimiter) {
                $counts[$delimiter] += substr_count($line, $delimiter);
            }
        }

        fclose($handle);

        // Return delimiter with highest count
        arsort($counts);
        return key($counts);
    }
}