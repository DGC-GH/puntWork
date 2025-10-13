<?php
/**
 * File Utilities for PuntWork Plugin
 *
 * Centralized functions for file operations, particularly JSONL file handling.
 */

namespace Puntwork;

/**
 * Read a JSONL file and return an array of decoded JSON objects
 *
 * @param string $path The file path to read
 * @return array Array of decoded JSON objects, empty array on error
 */
function read_jsonl_file($path) {
    $items = [];
    if (($handle = fopen($path, "r")) !== false) {
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (!empty($line)) {
                $item = json_decode($line, true);
                if ($item !== null) {
                    $items[] = $item;
                }
            }
        }
        fclose($handle);
    }
    return $items;
}

/**
 * Count the number of valid JSON lines in a JSONL file
 *
 * @param string $path The file path to read
 * @return int Number of valid JSON items
 */
function count_jsonl_items($path) {
    $count = 0;
    if (($handle = fopen($path, "r")) !== false) {
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (!empty($line)) {
                $item = json_decode($line, true);
                if ($item !== null) {
                    $count++;
                }
            }
        }
        fclose($handle);
    }
    return $count;
}

/**
 * Write an array of items to a JSONL file
 *
 * @param string $path The file path to write
 * @param array $items Array of data to encode as JSON lines
 * @return bool True on success, false on failure
 */
function write_jsonl_file($path, $items) {
    if (($handle = fopen($path, 'w')) === false) {
        return false;
    }

    foreach ($items as $item) {
        $json_line = json_encode($item) . PHP_EOL;
        if (fwrite($handle, $json_line) === false) {
            fclose($handle);
            return false;
        }
    }

    fclose($handle);
    return true;
}

/**
 * Open a file handle for reading (legacy compatibility)
 *
 * @param string $path The file path
 * @return resource|false File handle or false on failure
 */
function open_jsonl_file($path) {
    return fopen($path, "r");
}