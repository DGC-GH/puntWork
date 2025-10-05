<?php
require_once '/Users/dg/Documents/GitHub/puntWork/wp-load.php';

$status = get_option('job_import_status', array());
echo 'Current import status: ' . json_encode($status, JSON_PRETTY_PRINT) . PHP_EOL;
echo 'Progress: ' . get_option('job_import_progress', 'not set') . PHP_EOL;
echo 'Batch size: ' . get_option('job_import_batch_size', 'not set') . PHP_EOL;
echo 'Processed GUIDs count: ' . count(get_option('job_import_processed_guids', array())) . PHP_EOL;