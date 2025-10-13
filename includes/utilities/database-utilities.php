<?php
/**
 * Database Utilities for PuntWork Plugin
 *
 * Centralized functions for database operations, particularly job post creation and management.
 */

namespace Puntwork;

/**
 * Create a new job post with all required meta fields
 *
 * @param array $item The job data item
 * @param array $acf_fields ACF fields to set
 * @param array $zero_empty_fields Fields that should be empty when value is '0'
 * @param int $user_id The user ID for post_author
 * @param array &$logs Reference to logs array for recording operations
 * @param string &$error_message Reference to error message variable
 * @return int|WP_Error Post ID on success, WP_Error on failure
 */
function create_job_post($item, $acf_fields, $zero_empty_fields, $user_id, &$logs, &$error_message) {
    $guid = $item['guid'] ?? '';
    $xml_title = $item['title'] ?? '';
    $xml_validfrom = $item['validfrom'] ?? current_time('mysql');
    $xml_updated = $item['updated'] ?? null;

    $post_modified = $xml_updated ?: current_time('mysql');

    $post_data = [
        'post_type' => 'job',
        'post_title' => $xml_title,
        'post_name' => sanitize_title($xml_title . '-' . $guid),
        'post_status' => 'publish',
        'post_date' => $xml_validfrom,
        'post_modified' => $post_modified,
        'comment_status' => 'closed',
        'post_author' => $user_id,
    ];

    $post_id = retry_database_operation(function() use ($post_data) {
        return wp_insert_post($post_data);
    }, [$post_data], [
        'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
        'operation' => 'create_new_post',
        'guid' => $guid,
        'title' => $xml_title
    ]);

    if (is_wp_error($post_id)) {
        PuntWorkLogger::error('Failed to create new post', PuntWorkLogger::CONTEXT_BATCH, [
            'guid' => $guid,
            'error' => $post_id->get_error_message()
        ]);
        $error_message = 'Create failed GUID: ' . $guid . ' - ' . $post_id->get_error_message();
        return $post_id;
    }

    try {
        $current_time = current_time('mysql');

        // Set last import update meta
        retry_database_operation(function() use ($post_id, $current_time) {
            return update_post_meta($post_id, '_last_import_update', $current_time);
        }, [$post_id, $current_time], [
            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
            'operation' => 'set_last_import_meta_new',
            'post_id' => $post_id,
            'guid' => $guid
        ]);

        // Set GUID meta
        retry_database_operation(function() use ($post_id, $guid) {
            return update_post_meta($post_id, 'guid', $guid);
        }, [$post_id, $guid], [
            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
            'operation' => 'set_guid_meta_new',
            'post_id' => $post_id,
            'guid' => $guid
        ]);

        // Set import hash
        $item_hash = md5(json_encode($item));
        retry_database_operation(function() use ($post_id, $item_hash) {
            return update_post_meta($post_id, '_import_hash', $item_hash);
        }, [$post_id, $item_hash], [
            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
            'operation' => 'set_import_hash_meta_new',
            'post_id' => $post_id,
            'guid' => $guid
        ]);

        // Set ACF fields
        foreach ($acf_fields as $field) {
            $value = $item[$field] ?? '';
            $is_special = in_array($field, $zero_empty_fields);
            $set_value = $is_special && $value === '0' ? '' : $value;
            retry_database_operation(function() use ($post_id, $field, $set_value) {
                return update_post_meta($post_id, $field, $set_value);
            }, [$post_id, $field, $set_value], [
                'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                'operation' => 'set_acf_field_meta_new',
                'post_id' => $post_id,
                'field' => $field,
                'guid' => $guid
            ]);
        }

        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Published ID: ' . $post_id . ' GUID: ' . $guid;
        return $post_id;

    } catch (\Exception $e) {
        PuntWorkLogger::error('Failed to set post meta for new post', PuntWorkLogger::CONTEXT_BATCH, [
            'post_id' => $post_id,
            'guid' => $guid,
            'error' => $e->getMessage()
        ]);
        $error_message = 'Meta setup failed for new post ID: ' . $post_id . ' GUID: ' . $guid . ' - ' . $e->getMessage();
        // Try to delete the post since meta setup failed
        wp_delete_post($post_id, true);
        return new \WP_Error('meta_setup_failed', $error_message);
    }
}

/**
 * Update an existing job post with new data
 *
 * @param int $post_id The post ID to update
 * @param array $item The job data item
 * @param array $acf_fields ACF fields to update
 * @param array $zero_empty_fields Fields that should be empty when value is '0'
 * @param array &$logs Reference to logs array for recording operations
 * @param string &$error_message Reference to error message variable
 * @return bool True on success, false on failure
 */
function update_job_post($post_id, $item, $acf_fields, $zero_empty_fields, &$logs, &$error_message) {
    $guid = $item['guid'] ?? '';
    $xml_title = $item['title'] ?? '';
    $xml_updated = $item['updated'] ?? null;

    try {
        $current_time = current_time('mysql');

        // Update last import time
        retry_database_operation(function() use ($post_id, $current_time) {
            return update_post_meta($post_id, '_last_import_update', $current_time);
        }, [$post_id, $current_time], [
            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
            'operation' => 'set_last_import_meta_update',
            'post_id' => $post_id,
            'guid' => $guid
        ]);

        // Update post title and modified date if needed
        if ($xml_updated) {
            $update_data = [
                'ID' => $post_id,
                'post_title' => $xml_title,
                'post_modified' => $xml_updated
            ];
            retry_database_operation(function() use ($update_data) {
                return wp_update_post($update_data);
            }, [$update_data], [
                'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                'operation' => 'update_post_data',
                'post_id' => $post_id,
                'guid' => $guid
            ]);
        }

        // Update import hash
        $item_hash = md5(json_encode($item));
        retry_database_operation(function() use ($post_id, $item_hash) {
            return update_post_meta($post_id, '_import_hash', $item_hash);
        }, [$post_id, $item_hash], [
            'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
            'operation' => 'set_import_hash_meta_update',
            'post_id' => $post_id,
            'guid' => $guid
        ]);

        // Update ACF fields
        foreach ($acf_fields as $field) {
            $value = $item[$field] ?? '';
            $is_special = in_array($field, $zero_empty_fields);
            $set_value = $is_special && $value === '0' ? '' : $value;
            retry_database_operation(function() use ($post_id, $field, $set_value) {
                return update_post_meta($post_id, $field, $set_value);
            }, [$post_id, $field, $set_value], [
                'logger_context' => PuntWorkLogger::CONTEXT_BATCH,
                'operation' => 'set_acf_field_meta_update',
                'post_id' => $post_id,
                'field' => $field,
                'guid' => $guid
            ]);
        }

        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Updated ID: ' . $post_id . ' GUID: ' . $guid;
        return true;

    } catch (\Exception $e) {
        PuntWorkLogger::error('Failed to update post', PuntWorkLogger::CONTEXT_BATCH, [
            'post_id' => $post_id,
            'guid' => $guid,
            'error' => $e->getMessage()
        ]);
        $error_message = 'Update failed for post ID: ' . $post_id . ' GUID: ' . $guid . ' - ' . $e->getMessage();
        return false;
    }
}