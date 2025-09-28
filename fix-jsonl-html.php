<?php

/**
 * Standalone script to fix HTML encoding in JSONL file
 */
function fix_jsonl_html_encoding_standalone( $jsonl_path )
{
    if (! file_exists($jsonl_path) ) {
        die('JSONL file not found: ' . $jsonl_path . PHP_EOL);
    }

    $temp_path   = $jsonl_path . '.fixed';
    $handle      = fopen($jsonl_path, 'r');
    $temp_handle = fopen($temp_path, 'w');

    if (! $handle || ! $temp_handle ) {
        die('Could not open files for processing' . PHP_EOL);
    }

    $processed = 0;
    $errors    = 0;

    echo "Starting HTML encoding fix...\n";

    while ( ( $line = fgets($handle) ) !== false ) {
        $line = trim($line);
        if (empty($line) ) {
            continue;
        }

        try {
            $job = json_decode($line, true);
            if ($job === null ) {
                ++$errors;
                continue;
            }

            // Fix HTML encoding in description fields
            $html_fields = array( 'description', 'functiondescription', 'offerdescription', 'requirementsdescription', 'companydescription' );
            foreach ( $html_fields as $field ) {
                if (isset($job[ $field ]) ) {
                    $content = $job[ $field ];
                    // Decode HTML entities (handle double-encoding)
                    $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5);
                    $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5);
                    // Basic HTML sanitization (remove dangerous tags)
                    $content = strip_tags($content, '<p><br><strong><em><ul><ol><li><table><tr><td><th>');
                    // Clean up styling
                    $content = preg_replace('/\s*style\s*=\s*["\'][^"\']*["\']/', '', $content);
                    // Remove emoji and special characters
                    $content       = preg_replace('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F1E0}-\x{1F1FF}\x{2702}-\x{27B0}\x{24C2}-\x{1F251}\x{1F900}-\x{1F9FF}\x{1FA70}-\x{1FAFF}]/u', '', $content);
                    $content       = str_replace('&nbsp;', ' ', $content);
                    $job[ $field ] = trim($content);
                }
            }

            // Fix HTML encoding in title fields
            $title_fields = array( 'functiontitle', 'title' );
            foreach ( $title_fields as $field ) {
                if (isset($job[ $field ]) ) {
                    $content       = $job[ $field ];
                    $content       = html_entity_decode($content, ENT_QUOTES | ENT_HTML5);
                    $content       = html_entity_decode($content, ENT_QUOTES | ENT_HTML5);
                    $content       = preg_replace('/\s+(m\/v\/x|h\/f\/x|m\/f\/x)$/i', '', $content);
                    $job[ $field ] = trim($content);
                }
            }

            // Write the fixed job back to temp file
            fwrite($temp_handle, json_encode($job, JSON_UNESCAPED_UNICODE) . "\n");
            ++$processed;

            if ($processed % 500 == 0 ) {
                echo "Fixed $processed jobs so far...\n";
            }
        } catch ( Exception $e ) {
            ++$errors;
            echo 'Error processing job: ' . $e->getMessage() . "\n";
        }
    }

    fclose($handle);
    fclose($temp_handle);

    // Replace original file with fixed version
    if (rename($temp_path, $jsonl_path) ) {
        echo "SUCCESS: Fixed HTML encoding for $processed jobs with $errors errors\n";
        return true;
    } else {
        echo "ERROR: Could not replace original file with fixed version\n";
        return false;
    }
}

// Run the fix
$jsonl_path = __DIR__ . '/feeds/combined-jobs.jsonl';
fix_jsonl_html_encoding_standalone($jsonl_path);
