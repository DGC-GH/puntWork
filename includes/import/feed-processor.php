<?php

/**
 * Multi-format feed processing utilities.
 *
 * @since      1.0.13
 */

namespace Puntwork;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Feed format detection and processing.
 */
class FeedProcessor
{
    public const FORMAT_XML = 'xml';
    public const FORMAT_JSON = 'json';
    public const FORMAT_CSV = 'csv';
    public const FORMAT_JOB_BOARD = 'job_board';

    /**
     * Detect feed format from URL or content.
     *
     * @param  string      $url     Feed URL
     * @param  string|null $content Optional content to analyze
     * @return string Detected format (xml, json, csv, or job_board)
     */
    public static function detectFormat(string $url, ?string $content = null): string
    {
        // Check if it's a job board URL
        if (self::isJobBoardUrl($url)) {
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
                error_log(
                    '[PUNTWORK] detectFormat: Detected XML from content starting with: ' .
                    substr($content, 0, 50)
                );

                return self::FORMAT_XML;
            }

            // Check for JSON
            if ((strpos($content, '{') === 0 || strpos($content, '[') === 0)) {
                json_decode($content);
                if (json_last_error() === JSON_ERROR_NONE) {
                    error_log(
                        '[PUNTWORK] detectFormat: Detected JSON from content starting with: ' .
                        substr($content, 0, 50)
                    );

                    return self::FORMAT_JSON;
                } else {
                    error_log(
                        '[PUNTWORK] detectFormat: Content starts with { or [, but invalid JSON: ' .
                        json_last_error_msg()
                    );
                }
            }

            // Check for CSV (look for comma-separated values with headers)
            $lines = explode("\n", $content);
            if (count($lines) > 1) {
                $first_line = trim($lines[0]);
                $second_line = trim($lines[1] ?? '');

                // Simple heuristic: if first line has commas and second line exists
                if (strpos($first_line, ',') !== false && !empty($second_line)) {
                    error_log('[PUNTWORK] detectFormat: Detected CSV from content');

                    return self::FORMAT_CSV;
                }
            }
        }

        // Default to JSON for modern feeds
        error_log('[PUNTWORK] detectFormat: Defaulting to JSON for URL: ' . $url);

        return self::FORMAT_JSON;
    }

    /**
     * Check if URL is a job board URL.
     *
     * @param  string $url URL to check
     * @return bool True if it's a job board URL
     */
    private static function isJobBoardUrl(string $url): bool
    {
        $job_board_patterns = [
            'job_board://',  // Custom protocol for job boards
        ];

        foreach ($job_board_patterns as $pattern) {
            if (strpos($url, $pattern) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Process feed based on detected format.
     *
     * @param  string $feed_path       Path to downloaded feed file
     * @param  string $format          Feed format
     * @param  string $handle          Feed handle/key
     * @param  string $output_dir      Output directory
     * @param  string $fallback_domain Fallback domain
     * @param  int    $batch_size      Batch size
     * @param  int    &$total_items    Total items counter
     * @param  array  &$logs           Logs array
     * @return array Processed batch data
     */
    public static function processFeed(
        string $feed_path,
        string $format,
        $handle,
        string $feed_key,
        string $output_dir,
        string $fallback_domain,
        int $batch_size,
        int &$total_items,
        array &$logs
    ): int {
        $debug_mode = defined('WP_DEBUG') && WP_DEBUG;

        if ($debug_mode) {
            error_log('[PUNTWORK] [FEED-PROCESS-START] ===== PROCESS_FEED START =====');
            error_log('[PUNTWORK] [FEED-PROCESS-START] feed_path: ' . $feed_path);
            error_log('[PUNTWORK] [FEED-PROCESS-START] format: ' . $format);
            error_log('[PUNTWORK] [FEED-PROCESS-START] feed_key: ' . $feed_key);
            error_log('[PUNTWORK] [FEED-PROCESS-START] output_dir: ' . $output_dir);
            error_log('[PUNTWORK] [FEED-PROCESS-START] batch_size: ' . $batch_size);
            error_log('[PUNTWORK] [FEED-PROCESS-START] total_items before: ' . $total_items);
            error_log('[PUNTWORK] [FEED-PROCESS-START] Memory usage at start: ' . memory_get_usage(true) . ' bytes');
        }

        try {
            switch ($format) {
                case self::FORMAT_XML:
                    if ($debug_mode) {
                        error_log('[PUNTWORK] [FEED-PROCESS-DEBUG] Processing XML feed');
                    }
                    return self::processXmlFeed($feed_path, $handle, $feed_key, $output_dir, $fallback_domain, $batch_size, $total_items, $logs);
                case self::FORMAT_JSON:
                    if ($debug_mode) {
                        error_log('[PUNTWORK] [FEED-PROCESS-DEBUG] Processing JSON feed');
                    }
                    return self::processJsonFeed($feed_path, $handle, $feed_key, $output_dir, $fallback_domain, $batch_size, $total_items, $logs);
                case self::FORMAT_CSV:
                    if ($debug_mode) {
                        error_log('[PUNTWORK] [FEED-PROCESS-DEBUG] Processing CSV feed');
                    }
                    return self::processCsvFeed($feed_path, $handle, $feed_key, $output_dir, $fallback_domain, $batch_size, $total_items, $logs);
                case self::FORMAT_JOB_BOARD:
                    if ($debug_mode) {
                        error_log('[PUNTWORK] [FEED-PROCESS-DEBUG] Processing job board feed');
                    }
                    return self::processJobBoardFeed($feed_path, $handle, $feed_key, $output_dir, $fallback_domain, $batch_size, $total_items, $logs);
                default:
                    error_log('[PUNTWORK] [FEED-PROCESS-ERROR] Unsupported feed format: ' . $format);
                    throw new \Exception("Unsupported feed format: $format");
            }
        } catch (\Exception $e) {
            error_log('[PUNTWORK] [FEED-PROCESS-ERROR] processFeed exception: ' . $e->getMessage());
            if ($debug_mode) {
                error_log('[PUNTWORK] [FEED-PROCESS-END] ===== PROCESS_FEED END (ERROR) =====');
            }
            throw $e;
        }
    }

    /**
     * Process XML feed (existing functionality).
     */
    private static function processXmlFeed(
        $xml_path,
        $handle,
        $feed_key,
        $output_dir,
        $fallback_domain,
        $batch_size,
        &$total_items,
        &$logs
    ) {
        return process_xml_batch($xml_path, $handle, $feed_key, $output_dir, $fallback_domain, $batch_size, $total_items, $logs);
    }

    /**
     * Detect language from item.
     *
     * @param  object $item Job item object
     * @return string Detected language code (en, fr, nl)
     */
    private static function detectLanguage(object $item): string
    {
        $lang = isset($item->languagecode) ? strtolower((string)$item->languagecode) : 'en';
        if (strpos($lang, 'fr') !== false) {
            return 'fr';
        } elseif (strpos($lang, 'nl') !== false) {
            return 'nl';
        }

        return 'en';
    }

    /**
     * Process JSON feed.
     *
     * @param  string   $json_path       Path to JSON file
     * @param  resource $handle          File handle for writing
     * @param  string   $feed_key        Feed handle/key
     * @param  string   $output_dir      Output directory
     * @param  string   $fallback_domain Fallback domain
     * @param  int      $batch_size      Batch size
     * @param  int      &$total_items    Total items counter
     * @param  array    &$logs           Logs array
     * @return int Number of items processed
     * @throws \Exception If JSON processing fails
     */
    private static function processJsonFeed(
        string $json_path,
        $handle,
        string $feed_key,
        string $output_dir,
        string $fallback_domain,
        int $batch_size,
        int &$total_items,
        array &$logs
    ): int {
        $feed_item_count = 0;
        $batch = [];

        try {
            $content = file_get_contents($json_path);
            if ($content == false) {
                throw new \Exception("Could not read JSON file: $json_path");
            }

            $data = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON: ' . json_last_error_msg());
            }

            // Handle different JSON structures
            $items = self::extractJsonItems($data);

            foreach ($items as $item_data) {
                try {
                    if (!is_array($item_data) && !is_object($item_data)) {
                        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$feed_key item skipped: Invalid item structure";

                        continue;
                    }

                    // Convert to object for consistent processing
                    $item = is_object($item_data) ? $item_data : (object)$item_data;

                    // Normalize field names to lowercase
                    $normalized_item = new \stdClass();
                    foreach ($item as $key => $value) {
                        $normalized_key = strtolower($key);
                        $normalized_item->$normalized_key = $value;
                    }
                    $item = $normalized_item;

                    // Generate GUID if missing
                    if (!isset($item->guid) || empty($item->guid)) {
                        // Generate GUID from title, company, and location if available
                        $guid_source = '';
                        if (isset($item->functiontitle)) {
                            $guid_source .= (string)$item->functiontitle;
                        }
                        if (isset($item->company)) {
                            $guid_source .= (string)$item->company;
                        }
                        if (isset($item->location)) {
                            $guid_source .= (string)$item->location;
                        }
                        if (isset($item->url)) {
                            $guid_source .= (string)$item->url;
                        }

                        if (!empty($guid_source)) {
                            $item->guid = md5($guid_source);
                            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$feed_key: Generated GUID for item: " . $item->guid;
                        } else {
                            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$feed_key: Skipping item - no unique fields for GUID generation";

                            continue;
                        }
                    }

                    // Skip empty items
                    if (empty((array)$item)) {
                        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$feed_key item skipped: No fields collected";

                        continue;
                    }

                    // Log item fields for debugging
                    $item_fields = array_keys((array)$item);
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$feed_key: Processing item with GUID {$item->guid}, fields: " . implode(', ', $item_fields);

                    clean_item_fields($item);

                    // Language detection
                    $lang = self::detectLanguage($item);

                    $job_obj = json_decode(json_encode($item), true);
                    infer_item_details($item, $fallback_domain, $lang, $job_obj);

                    $batch[] = json_encode($job_obj, JSON_UNESCAPED_UNICODE) . "\n";
                    $feed_item_count++;

                    // Process in batches
                    if (count($batch) >= $batch_size) {
                        fwrite($handle, implode('', $batch));
                        $batch = [];
                        $total_items += $batch_size;
                    }
                } catch (\Exception $e) {
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$feed_key: Error processing JSON item: " . $e->getMessage();
                    error_log("$feed_key: Error processing JSON item: " . $e->getMessage());
                    // Continue with next item
                }
            }

            // Write remaining items
            if (!empty($batch)) {
                fwrite($handle, implode('', $batch));
                $total_items += count($batch);
            }

            return $feed_item_count;
        } catch (\Exception $e) {
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$feed_key JSON processing error: " . $e->getMessage();

            throw $e;
        }
    }

    /**
     * Process CSV feed.
     *
     * @param  string   $csv_path        Path to CSV file
     * @param  resource $handle          File handle for writing
     * @param  string   $feed_key        Feed handle/key
     * @param  string   $output_dir      Output directory
     * @param  string   $fallback_domain Fallback domain
     * @param  int      $batch_size      Batch size
     * @param  int      &$total_items    Total items counter
     * @param  array    &$logs           Logs array
     * @return int Number of items processed
     * @throws \Exception If CSV processing fails
     */
    private static function processCsvFeed(
        string $csv_path,
        $handle,
        string $feed_key,
        string $output_dir,
        string $fallback_domain,
        int $batch_size,
        int &$total_items,
        array &$logs
    ): int {
        $feed_item_count = 0;
        $batch = [];

        try {
            if (!file_exists($csv_path)) {
                throw new \Exception("CSV file not found: $csv_path");
            }

            $handle_resource = fopen($csv_path, 'r');
            if ($handle_resource == false) {
                throw new \Exception("Could not open CSV file: $csv_path");
            }

            // Detect delimiter and read headers
            $delimiter = self::detectCsvDelimiter($csv_path);
            $headers = fgetcsv($handle_resource, 0, $delimiter);

            if (!$headers || count($headers) < 2) {
                throw new \Exception('Invalid CSV format or no headers found');
            }

            // Normalize headers to lowercase
            $headers = array_map('strtolower', $headers);

            while (($row = fgetcsv($handle_resource, 0, $delimiter)) !== false) {
                try {
                    if (count($row) !== count($headers)) {
                        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$feed_key row skipped: Column count mismatch";

                        continue;
                    }

                    // Convert row to object
                    $item = new \stdClass();
                    foreach ($headers as $index => $header) {
                        $item->$header = $row[$index] ?? '';
                    }

                    // Skip empty items
                    if (empty((array)$item)) {
                        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$feed_key item skipped: No fields collected";

                        continue;
                    }

                    clean_item_fields($item);

                    // Generate GUID if missing
                    if (!isset($item->guid) || empty($item->guid)) {
                        // Generate GUID from title, company, and location if available
                        $guid_source = '';
                        if (isset($item->functiontitle)) {
                            $guid_source .= (string)$item->functiontitle;
                        }
                        if (isset($item->company)) {
                            $guid_source .= (string)$item->company;
                        }
                        if (isset($item->location)) {
                            $guid_source .= (string)$item->location;
                        }
                        if (isset($item->url)) {
                            $guid_source .= (string)$item->url;
                        }

                        if (!empty($guid_source)) {
                            $item->guid = md5($guid_source);
                            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$feed_key: Generated GUID for item: " . $item->guid;
                        } else {
                            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$feed_key: Skipping item - no unique fields for GUID generation";

                            continue;
                        }
                    }

                    // Language detection
                    $lang = self::detectLanguage($item);

                    $job_obj = json_decode(json_encode($item), true);
                    infer_item_details($item, $fallback_domain, $lang, $job_obj);

                    $batch[] = json_encode($job_obj, JSON_UNESCAPED_UNICODE) . "\n";
                    $feed_item_count++;

                    // Process in batches
                    if (count($batch) >= $batch_size) {
                        fwrite($handle, implode('', $batch));
                        $batch = [];
                        $total_items += $batch_size;
                    }
                } catch (\Exception $e) {
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$feed_key: Error processing CSV row: " . $e->getMessage();
                    error_log("$feed_key: Error processing CSV row: " . $e->getMessage());
                    // Continue with next row
                }
            }

            // Write remaining items
            if (!empty($batch)) {
                fwrite($handle, implode('', $batch));
                $total_items += count($batch);
            }

            fclose($handle_resource);

            return $feed_item_count;
        } catch (\Exception $e) {
            if (isset($handle_resource) && is_resource($handle_resource)) {
                fclose($handle_resource);
            }
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$feed_key CSV processing error: " . $e->getMessage();

            throw $e;
        }
    }

    /**
     * Process job board feed.
     *
     * @param  string   $feed_path       Job board URL (job_board://board_id?params)
     * @param  resource $handle          File handle for writing
     * @param  string   $feed_key        Feed handle/key
     * @param  string   $output_dir      Output directory
     * @param  string   $fallback_domain Fallback domain
     * @param  int      $batch_size      Batch size
     * @param  int      &$total_items    Total items counter
     * @param  array    &$logs           Logs array
     * @return int Number of items processed
     * @throws \Exception If job board processing fails
     */
    private static function processJobBoardFeed(
        string $feed_path,
        $handle,
        string $feed_key,
        string $output_dir,
        string $fallback_domain,
        int $batch_size,
        int &$total_items,
        array &$logs
    ): int {
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
            include_once dirname(dirname(__DIR__)) . '/includes/jobboards/jobboard-manager.php';

            $board_manager = new JobBoards\JobBoardManager();

            if (!$board_manager->isBoardConfigured($board_id)) {
                throw new \Exception("Job board '$board_id' is not configured");
            }

            // Fetch jobs from the job board
            $jobs = $board_manager->fetchAllJobs($params, [$board_id]);

            foreach ($jobs as $job_data) {
                try {
                    // Convert job board data to standard job format
                    $item = self::convertJobBoardDataToItem($job_data, $board_id);

                    // Skip empty items
                    if (empty((array)$item)) {
                        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$feed_key job skipped: No fields collected";

                        continue;
                    }

                    clean_item_fields($item);

                    // Generate GUID if missing
                    if (!isset($item->guid) || empty($item->guid)) {
                        // Generate GUID from title, company, and location if available
                        $guid_source = '';
                        if (isset($item->title)) {
                            $guid_source .= (string)$item->title;
                        }
                        if (isset($item->company)) {
                            $guid_source .= (string)$item->company;
                        }
                        if (isset($item->location)) {
                            $guid_source .= (string)$item->location;
                        }
                        if (isset($item->url)) {
                            $guid_source .= (string)$item->url;
                        }

                        if (!empty($guid_source)) {
                            $item->guid = md5($guid_source);
                            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$feed_key: Generated GUID for job: " . $item->guid;
                        } else {
                            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$feed_key: Skipping job - no unique fields for GUID generation";

                            continue;
                        }
                    }

                    // Language detection
                    $lang = self::detectLanguage($item);

                    $job_obj = json_decode(json_encode($item), true);
                    infer_item_details($item, $fallback_domain, $lang, $job_obj);

                    $batch[] = json_encode($job_obj, JSON_UNESCAPED_UNICODE) . "\n";
                    $feed_item_count++;

                    // Process in batches
                    if (count($batch) >= $batch_size) {
                        fwrite($handle, implode('', $batch));
                        $batch = [];
                        $total_items += $batch_size;
                    }
                } catch (\Exception $e) {
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$feed_key: Error processing job board item: " . $e->getMessage();
                    error_log("$feed_key: Error processing job board item: " . $e->getMessage());
                    // Continue with next job
                }
            }

            // Write remaining items
            if (!empty($batch)) {
                fwrite($handle, implode('', $batch));
                $total_items += count($batch);
            }

            return $feed_item_count;
        } catch (\Exception $e) {
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$feed_key job board processing error: " . $e->getMessage();

            throw $e;
        }
    }

    /**
     * Convert job board data to standard job item format.
     *
     * @param  array  $job_data Job data from board API
     * @param  string $board_id Job board identifier
     * @return object Standardized job item
     */
    private static function convertJobBoardDataToItem(array $job_data, string $board_id): object
    {
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
     * Extract items from various JSON structures.
     *
     * @param  mixed $data JSON data structure
     * @return array Extracted items array
     */
    private static function extractJsonItems($data): array
    {
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
     * Detect CSV delimiter by analyzing the first few lines.
     *
     * @param  string $file_path Path to CSV file
     * @return string Detected delimiter character
     */
    private static function detectCsvDelimiter(string $file_path): string
    {
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
