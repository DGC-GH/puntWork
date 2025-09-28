<?php

/**
 * Batch loading and preparation utilities
 *
 * @package    Puntwork
 * @subpackage Batch
 * @since      1.0.0
 */

declare(strict_types=1);

// Prevent direct access
if (! defined('ABSPATH') ) {
    exit;
}

class JsonlIterator implements \Iterator
{

    private string $filePath;
    private int $startIndex;
    private int $batchSize;
    private $handle;
    private int $currentIndex = 0;
    private int $loadedCount  = 0;
    private $currentItem      = null;
    private int $key          = 0;

    public function __construct( string $filePath, int $startIndex, int $batchSize )
    {
        $this->filePath   = $filePath;
        $this->startIndex = $startIndex;
        $this->batchSize  = $batchSize;
    }

    public function rewind(): void
    {
        if ($this->handle ) {
            fclose($this->handle);
        }
        $this->handle       = fopen($this->filePath, 'r');
        $this->currentIndex = 0;
        $this->loadedCount  = 0;
        $this->key          = 0;
        $this->currentItem  = null;
        $this->skipToStart();
    }

    private function skipToStart(): void
    {
        while ( $this->currentIndex < $this->startIndex && ( $line = fgets($this->handle) ) !== false ) {
            ++$this->currentIndex;
        }
    }

    #[\ReturnTypeWillChange]
    public function current()
    {
        return $this->currentItem;
    }

    public function key(): int
    {
        return $this->key;
    }

    public function next(): void
    {
        ++$this->key;
        $this->currentItem = null;

        if ($this->loadedCount >= $this->batchSize ) {
            return;
        }

        while ( ( $line = fgets($this->handle) ) !== false ) {
            ++$this->currentIndex;
            $line = trim($line);
            if (! empty($line) ) {
                $item = json_decode($line, true);
                if ($item !== null ) {
                    $this->currentItem = $item;
                    ++$this->loadedCount;
                    return;
                }
            }
        }
    }

    public function valid(): bool
    {
        return $this->currentItem !== null && $this->loadedCount <= $this->batchSize;
    }

    public function __destruct()
    {
        if ($this->handle ) {
            fclose($this->handle);
        }
    }
}

/**
 * Load and prepare batch items from JSONL.
 *
 * @param  string $json_path   Path to JSONL file.
 * @param  int    $start_index Start index.
 * @param  int    $batch_size  Batch size.
 * @param  int    $threshold   Memory threshold.
 * @param  array  &$logs       Logs array.
 * @return array Prepared batch data.
 */
function load_and_prepare_batch_items( string $json_path, int $start_index, int $batch_size, float $threshold, array &$logs ): array
{
    error_log(
        '[PUNTWORK] load_and_prepare_batch_items called with: ' . json_encode(
            array(
                'json_path'   => basename($json_path),
                'start_index' => $start_index,
                'batch_size'  => $batch_size,
                'file_exists' => file_exists($json_path),
                'file_size'   => file_exists($json_path) ? filesize($json_path) : 'N/A',
                'is_readable' => is_readable($json_path),
            )
        )
    );

    $batch_json_result = load_json_batch($json_path, $start_index, $batch_size);
    $batch_json_items = $batch_json_result['items'] ?? $batch_json_result; // fallback for array
    $lines_read = $batch_json_result['lines_read'] ?? count($batch_json_items);
    error_log('[PUNTWORK] load_and_prepare_batch_items: load_json_batch returned ' . count($batch_json_items) . ' items, lines_read=' . $lines_read);

    $batch_items  = array();
    $batch_guids  = array();
    $loaded_count = count($batch_json_items);

    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Loaded $loaded_count items from JSONL (batch size: $batch_size)";

    if ($loaded_count === 0 ) {
        error_log('[PUNTWORK] load_and_prepare_batch_items: NO ITEMS LOADED FROM JSONL! This is the root cause of 0 processed items.');
        error_log('[PUNTWORK] load_and_prepare_batch_items: json_path=' . $json_path . ', start_index=' . $start_index . ', batch_size=' . $batch_size);
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'WARNING: No items loaded from JSONL file - check file integrity';
        return array(
        'batch_items' => $batch_items,
        'batch_guids' => $batch_guids,
        'cancelled'   => false,
        'lines_read'  => $lines_read,
    );
    }

    $valid_items   = 0;
    $skipped_items = 0;
    $missing_guids = 0;

    $total_items = count($batch_json_items);
    for ( $i = 0; $i < $total_items; $i++ ) {
        $current_index = $start_index + $i;

        if (get_transient('import_cancel') === true ) {
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Import cancelled at #' . ( $current_index + 1 );
            update_option('job_import_progress', $current_index, false);
            return array(
            'cancelled' => true,
            'logs'      => $logs,
            'lines_read' => 0,
            );
        }

        $item = $batch_json_items[ $i ];
        $guid = $item['guid'] ?? '';

        if (empty($guid) ) {
            ++$missing_guids;
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Skipped #' . ( $current_index + 1 ) . ': Empty GUID - Item keys: ' . implode(', ', array_keys($item));
            continue;
        }

        $batch_guids[]        = $guid;
        $logs[]               = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Processing #' . ( $current_index + 1 ) . ' GUID: ' . $guid;
        $batch_items[ $guid ] = array(
        'item'  => $item,
        'index' => $current_index,
        );
        ++$valid_items;

        // Enhanced memory management
        $memory_status = check_batch_memory_usage($current_index, $threshold * 0.8); // More aggressive threshold
        if (! empty($memory_status['actions_taken']) ) {
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Memory management: ' . implode(', ', $memory_status['actions_taken']);
        }

        // Memory check
        if (memory_get_usage(true) > $threshold ) {
            $batch_size = max(1, (int) ( $batch_size * 0.8 ));
            update_option('job_import_batch_size', $batch_size, false);
            $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Memory high, reduced batch to ' . $batch_size;
        }

        if ($i % 5 === 0 ) {
            ob_flush();
            flush();
        }
        unset($batch_json_items[ $i ]);
    }
    unset($batch_json_items);

    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Prepared $valid_items valid items for processing (skipped $skipped_items items, $missing_guids missing GUIDs)";

    error_log('[PUNTWORK] Prepared ' . $valid_items . ' valid items for processing (skipped: ' . $skipped_items . ', missing GUIDs: ' . $missing_guids . ')');
        return array(
        'batch_items' => $batch_items,
        'batch_guids' => $batch_guids,
        'cancelled'   => false,
        'lines_read'  => $lines_read,
    );
}

/**
 * Load a batch of items from JSONL file with improved performance.
 *
 * @param  string $json_path   Path to JSONL file.
 * @param  int    $start_index Starting index.
 * @param  int    $batch_size  Batch size.
 * @return array Array of JSON items.
 */
function load_json_batch( $json_path, $start_index, $batch_size )
{
    // Ensure batch_size is at least 1
    $batch_size = max(1, (int) $batch_size);

    error_log('[PUNTWORK] load_json_batch called with: path=' . basename($json_path) . ', start_index=' . $start_index . ', batch_size=' . $batch_size);
    error_log('[PUNTWORK] load_json_batch: file exists: ' . ( file_exists($json_path) ? 'yes' : 'no' ));

    if (file_exists($json_path) ) {
        error_log('[PUNTWORK] load_json_batch: file size: ' . filesize($json_path) . ' bytes');
        error_log('[PUNTWORK] load_json_batch: file readable: ' . ( is_readable($json_path) ? 'yes' : 'no' ));
    } else {
        error_log('[PUNTWORK] load_json_batch: FILE DOES NOT EXIST: ' . $json_path);
        return array();
    }

    $items         = array();
    $count         = 0;
    $current_index = 0;
    $lines_read    = 0;
    $empty_lines   = 0;
    $invalid_json  = 0;
    $bom           = "\xef\xbb\xbf";

    try {
        $handle = fopen($json_path, 'r');
        if ($handle === false ) {
            error_log('[PUNTWORK] load_json_batch: Cannot open file: ' . $json_path . ', error: ' . ( error_get_last()['message'] ?? 'unknown' ));
            return array();
        }

        error_log('[PUNTWORK] load_json_batch: File opened successfully, skipping to start_index=' . $start_index);

        // Skip to start_index
        while ( $current_index < $start_index && ( $line = fgets($handle) ) !== false ) {
            ++$current_index;
        }

        error_log('[PUNTWORK] load_json_batch: Skipped to index ' . $current_index . ', now reading batch_size=' . $batch_size . ' items');

        // Read batch_size items
        while ( $count < $batch_size && ( $line = fgets($handle) ) !== false ) {
            ++$lines_read;
            $line = trim($line);
            // Remove BOM if present
            if (substr($line, 0, 3) === $bom ) {
                $line = substr($line, 3);
            }
            if (! empty($line) ) {
                $item = json_decode($line, true);
                if ($item !== null ) {
                    $items[] = $item;
                    ++$count;
                    error_log('[PUNTWORK] load_json_batch: Successfully decoded item ' . $count . ' at file position ' . ( $start_index + $lines_read ) . ' with GUID: ' . ( $item['guid'] ?? 'MISSING' ));
                } else {
                    ++$invalid_json;
                    error_log('[PUNTWORK] load_json_batch: Failed to decode JSON at line ' . ( $start_index + $lines_read ) . ': ' . json_last_error_msg() . ' - Line length: ' . strlen($line) . ' - Line start: ' . substr($line, 0, 100));
                }
            } else {
                ++$empty_lines;
                error_log('[PUNTWORK] load_json_batch: Empty line at ' . ( $start_index + $lines_read ));
            }
            ++$current_index;
        }

        fclose($handle);
    } catch ( \Exception $e ) {
        error_log('[PUNTWORK] load_json_batch: Exception: ' . $e->getMessage());
        if (isset($handle) && $handle ) {
            fclose($handle);
        }
        return array();
    }

    error_log('[PUNTWORK] load_json_batch: returning ' . count($items) . ' items (read ' . $lines_read . ' lines, empty: ' . $empty_lines . ', invalid JSON: ' . $invalid_json . ', start_index: ' . $start_index . ', batch_size: ' . $batch_size . ')');
    if (empty($items) ) {
        error_log('[PUNTWORK] load_json_batch: WARNING - NO ITEMS LOADED! This will cause 0 processed items.');
        error_log('[PUNTWORK] load_json_batch: DEBUG - start_index=' . $start_index . ', batch_size=' . $batch_size . ', lines_read=' . $lines_read . ', empty_lines=' . $empty_lines . ', invalid_json=' . $invalid_json);
        // Try to read first line manually
        $debug_handle = fopen($json_path, 'r');
        if ($debug_handle ) {
            $first_line = fgets($debug_handle);
            error_log('[PUNTWORK] load_json_batch: DEBUG - First line: ' . substr(trim($first_line), 0, 100));
            fclose($debug_handle);
        }
    }
    return array(
        'items'      => $items,
        'lines_read' => $lines_read,
    );
}
