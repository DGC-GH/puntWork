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
    const FORMAT_JOB_BOARD = 'job_board';

    /**
     * Detect feed format from URL or content
     *
     * @param string $url Feed URL
     * @param string|null $content Optional content to analyze
     * @return string Detected format (xml, json, csv, or job_board)
     */
    public static function detect_format(string $url, ?string $content = null): string {
        // Check if it's a job board URL
        if (self::is_job_board_url($url)) {
            return self::FORMAT_JOB_BOARD;
        }

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
     * Check if URL is a job board URL
     *
     * @param string $url URL to check
     * @return bool True if it's a job board URL
     */
    private static function is_job_board_url(string $url): bool {
        $job_board_patterns = [
            'job_board://',  // Custom protocol for job boards
            'indeed://',
            'linkedin://',
            'glassdoor://'
        ];

        foreach ($job_board_patterns as $pattern) {
            if (strpos($url, $pattern) === 0) {
                return true;
            }
        }

        return false;
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
    public static function process_feed(string $feed_path, string $format, string $handle, string $output_dir, string $fallback_domain, int $batch_size, int &$total_items, array &$logs): array {
        switch ($format) {
            case self::FORMAT_XML:
                return self::process_xml_feed($feed_path, $handle, $output_dir, $fallback_domain, $batch_size, $total_items, $logs);
            case self::FORMAT_JSON:
                return self::process_json_feed($feed_path, $handle, $output_dir, $fallback_domain, $batch_size, $total_items, $logs);
            case self::FORMAT_CSV:
                return self::process_csv_feed($feed_path, $handle, $output_dir, $fallback_domain, $batch_size, $total_items, $logs);
            case self::FORMAT_JOB_BOARD:
                return self::process_job_board_feed($feed_path, $handle, $output_dir, $fallback_domain, $batch_size, $total_items, $logs);
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
     * Detect language from item
     *
     * @param object $item Job item object
     * @return string Detected language code (en, fr, nl)
     */
    private static function detect_language(object $item): string {
        $lang = isset($item->languagecode) ? strtolower((string)$item->languagecode) : 'en';
        if (strpos($lang, 'fr') !== false) return 'fr';
        elseif (strpos($lang, 'nl') !== false) return 'nl';
        return 'en';
    }

    /**
     * Process JSON feed
     *
     * @param string $json_path Path to JSON file
     * @param string $handle Feed handle/key
     * @param string $output_dir Output directory
     * @param string $fallback_domain Fallback domain
     * @param int $batch_size Batch size
     * @param int &$total_items Total items counter
     * @param array &$logs Logs array
     * @return array Processed batch data
     * @throws \Exception If JSON processing fails
     */
    private static function process_json_feed(string $json_path, string $handle, string $output_dir, string $fallback_domain, int $batch_size, int &$total_items, array &$logs): array {
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
                $lang = self::detect_language($item);

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
     *
     * @param string $csv_path Path to CSV file
     * @param string $handle Feed handle/key
     * @param string $output_dir Output directory
     * @param string $fallback_domain Fallback domain
     * @param int $batch_size Batch size
     * @param int &$total_items Total items counter
     * @param array &$logs Logs array
     * @return array Processed batch data
     * @throws \Exception If CSV processing fails
     */
    private static function process_csv_feed(string $csv_path, string $handle, string $output_dir, string $fallback_domain, int $batch_size, int &$total_items, array &$logs): array {
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
                $lang = self::detect_language($item);

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
     * Process job board feed
     *
     * @param string $feed_path Job board URL (job_board://board_id?params)
     * @param string $handle Feed handle/key
     * @param string $output_dir Output directory
     * @param string $fallback_domain Fallback domain
     * @param int $batch_size Batch size
     * @param int &$total_items Total items counter
     * @param array &$logs Logs array
     * @return array Processed batch data
     * @throws \Exception If job board processing fails
     */
    private static function process_job_board_feed(string $feed_path, string $handle, string $output_dir, string $fallback_domain, int $batch_size, int &$total_items, array &$logs): array {
        $feed_item_count = 0;
        $batch = [];

        try {
            // Parse job board URL: job_board://board_id?param1=value1&param2=value2
            $url_parts = parse_url($feed_path);
            $board_id = str_replace('job_board://', '', $url_parts['scheme'] . '://' . $url_parts['host']);

            // Parse query parameters
            $params = [];
            if (isset($url_parts['query'])) {
                parse_str($url_parts['query'], $params);
            }

            // Include the JobBoardManager
            require_once plugin_dir_path(dirname(__FILE__, 2)) . 'jobboards/jobboard-manager.php';

            $board_manager = new \Puntwork\JobBoards\JobBoardManager();

            if (!$board_manager->isBoardConfigured($board_id)) {
                throw new \Exception("Job board '$board_id' is not configured");
            }

            // Fetch jobs from the job board
            $jobs = $board_manager->fetchAllJobs($params, [$board_id]);

            foreach ($jobs as $job_data) {
                // Convert job board data to standard job format
                $item = self::convert_job_board_data_to_item($job_data, $board_id);

                // Skip empty items
                if (empty((array)$item)) {
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$handle job skipped: No fields collected";
                    continue;
                }

                clean_item_fields($item);

                // Language detection
                $lang = self::detect_language($item);

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
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$handle job board processing error: " . $e->getMessage();
            throw $e;
        }
    }

    /**
     * Convert job board data to standard job item format
     *
     * @param array $job_data Job data from board API
     * @param string $board_id Job board identifier
     * @return object Standardized job item
     */
    private static function convert_job_board_data_to_item(array $job_data, string $board_id): object {
        $item = new \stdClass();

        // Map common fields
        $item->title = $job_data['title'] ?? '';
        $item->description = $job_data['description'] ?? '';
        $item->company = $job_data['company'] ?? '';
        $item->location = $job_data['location'] ?? '';
        $item->salary = $job_data['salary'] ?? '';
        $item->jobtype = $job_data['job_type'] ?? 'fulltime';
        $item->category = $job_data['category'] ?? '';
        $item->url = $job_data['url'] ?? '';
        $item->date = $job_data['date_posted'] ?? date('Y-m-d');
        $item->requirements = $job_data['requirements'] ?? '';
        $item->benefits = $job_data['benefits'] ?? '';

        // Add source information
        $item->source = $board_id;
        $item->source_type = 'job_board';

        // Add any additional fields from raw data
        if (isset($job_data['raw_data']) && is_array($job_data['raw_data'])) {
            foreach ($job_data['raw_data'] as $key => $value) {
                if (!isset($item->$key)) {
                    $item->$key = $value;
                }
            }
        }

        return $item;
    }

    /**
     * Extract items from various JSON structures
     *
     * @param mixed $data JSON data structure
     * @return array Extracted items array
     */
    private static function extract_json_items($data): array {
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
     *
     * @param string $file_path Path to CSV file
     * @return string Detected delimiter character
     */
    private static function detect_csv_delimiter(string $file_path): string {
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