<?php

/**
 * Simple test to check if process_single_item works
 */

require_once 'puntwork.php';
require_once 'includes/import/process-batch-items.php';

// Test data
$test_guid = 'TEST_GUID_' . time();
$test_item = array(
    'guid' => $test_guid,
    'functiontitle' => 'Test Job Title',
    'company' => 'Test Company',
    'functiondescription' => 'Test description',
    'updated' => date('Y-m-d H:i:s'),
    'validfrom' => date('Y-m-d H:i:s'),
    'city' => 'Test City'
);

$batch_items = array($test_guid => array('item' => $test_item));
$post_ids_by_guid = array();
$last_updates = array();
$post_statuses = array();
$all_hashes_by_post = array();
$item_counter = 1;

echo 'Testing process_single_item function...' . PHP_EOL;
$result = process_single_item($test_guid, $batch_items, $post_ids_by_guid, $last_updates, $post_statuses, $all_hashes_by_post, $item_counter);
echo 'Result: ' . json_encode($result) . PHP_EOL;