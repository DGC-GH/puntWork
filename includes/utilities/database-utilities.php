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
    // Validate inputs at the very beginning before any array access
    if (!is_array($acf_fields)) {
        $error_message = 'acf_fields must be an array, got: ' . gettype($acf_fields);
        return new \WP_Error('invalid_acf_fields', $error_message);
    }
    if (!is_array($item)) {
        $error_message = 'item must be an array, got: ' . gettype($item) . ' with value: ' . substr((string)$item, 0, 100);
        return new \WP_Error('invalid_item', $error_message);
    }
    if (!is_array($zero_empty_fields)) {
        $error_message = 'zero_empty_fields must be an array, got: ' . gettype($zero_empty_fields);
        return new \WP_Error('invalid_zero_empty_fields', $error_message);
    }

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
        'post_author' => '',
    ];

    $post_id = retry_database_operation(function() use ($post_data) {
        return wp_insert_post($post_data);
    }, [$post_data], [
        'logger_context' => PuntWorkLogger::CONTEXT_IMPORT,
        'operation' => 'create_new_post',
        'guid' => $guid,
        'title' => $xml_title
    ]);

    if (is_wp_error($post_id)) {
        PuntWorkLogger::error('Failed to create new post', PuntWorkLogger::CONTEXT_IMPORT, [
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
            'logger_context' => PuntWorkLogger::CONTEXT_IMPORT,
            'operation' => 'set_last_import_meta_new',
            'post_id' => $post_id,
            'guid' => $guid
        ]);

        // Set GUID meta
        retry_database_operation(function() use ($post_id, $guid) {
            return update_post_meta($post_id, 'guid', $guid);
        }, [$post_id, $guid], [
            'logger_context' => PuntWorkLogger::CONTEXT_IMPORT,
            'operation' => 'set_guid_meta_new',
            'post_id' => $post_id,
            'guid' => $guid
        ]);

        // Set import hash
        $item_hash = md5(json_encode($item));
        retry_database_operation(function() use ($post_id, $item_hash) {
            return update_post_meta($post_id, '_import_hash', $item_hash);
        }, [$post_id, $item_hash], [
            'logger_context' => PuntWorkLogger::CONTEXT_IMPORT,
            'operation' => 'set_import_hash_meta_new',
            'post_id' => $post_id,
            'guid' => $guid
        ]);

        // Set ACF fields
        foreach ($acf_fields as $field) {
            $value = $item[$field] ?? '';
            $is_special = in_array($field, $zero_empty_fields);
            $set_value = $is_special && $value === '0' ? '' : $value;

            // Serialize arrays if needed
            // Note: Removed serialization for ACF fields, let ACF handle it
            // if (is_array($set_value)) {
            //     $set_value = serialize($set_value);
            // }

            if (!function_exists('update_field')) {
                PuntWorkLogger::debug('update_field not available in create, skipping ACF field update', PuntWorkLogger::CONTEXT_IMPORT, [
                    'post_id' => $post_id,
                    'guid' => $guid,
                    'field' => $field
                ]);
                continue;
            } else {
                retry_database_operation(function() use ($post_id, $field, $set_value) {
                    return update_field($field, $set_value, $post_id);
                }, [$post_id, $field, $set_value], [
                    'logger_context' => PuntWorkLogger::CONTEXT_IMPORT,
                    'operation' => 'set_acf_field_meta_new',
                    'post_id' => $post_id,
                    'field' => $field,
                    'guid' => $guid
                ]);
            }
        }

        if (!is_array($logs)) {
            PuntWorkLogger::error('logs is not array in create_job_post, resetting', PuntWorkLogger::CONTEXT_IMPORT, [
                'post_id' => $post_id,
                'guid' => $guid,
                'logs_type' => gettype($logs),
                'logs_value' => is_scalar($logs) ? substr((string)$logs, 0, 100) : 'non-scalar'
            ]);
            $logs = [];
        }

        array_push($logs, '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Published ID: ' . $post_id . ' GUID: ' . $guid);
        return $post_id;

    } catch (\Exception $e) {
        PuntWorkLogger::error('Failed to set post meta for new post', PuntWorkLogger::CONTEXT_IMPORT, [
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
 * Updates an existing job post with new data and metadata
 *
 * @param int $post_id The post ID to update
 * @param array $item The job data item
 * @param array $acf_fields ACF fields to set
 * @param array $zero_empty_fields Fields that should be empty when value is '0'
 * @param array &$logs Reference to logs array for recording operations
 * @param string &$error_message Reference to error message variable
 * @return int|WP_Error Post ID on success, WP_Error on failure
 */
function update_job_post($post_id, $guid, $item, $acf_fields, $zero_empty_fields, &$logs, &$error_message) {
    // Validate inputs at the very beginning before any array access
    if (!is_array($acf_fields)) {
        $error_message = 'acf_fields must be an array, got: ' . gettype($acf_fields);
        return new \WP_Error('invalid_acf_fields', $error_message);
    }
    if (!is_array($item)) {
        $error_message = 'item must be an array, got: ' . gettype($item) . ' with value: ' . substr((string)$item, 0, 100);
        return new \WP_Error('invalid_item', $error_message);
    }
    if (!is_array($zero_empty_fields)) {
        $error_message = 'zero_empty_fields must be an array, got: ' . gettype($zero_empty_fields);
        return new \WP_Error('invalid_zero_empty_fields', $error_message);
    }

    $guid = $item['guid'] ?? '';
    $xml_title = $item['title'] ?? $item['functiontitle'] ?? '';
    $xml_validfrom = $item['validfrom'] ?? '';
    $xml_updated = $item['updated'] ?? null;
    $post_modified = $xml_updated ?: current_time('mysql');

    // Check if detailed job update debugging is enabled
    $debug_job_updates = defined('PUNTWORK_DEBUG_JOB_UPDATES') && PUNTWORK_DEBUG_JOB_UPDATES;

    if ($debug_job_updates) {
        PuntWorkLogger::debug('update_job_post validations passed', PuntWorkLogger::CONTEXT_IMPORT, [
            'post_id' => $post_id,
            'guid' => $guid,
            'logs_type' => gettype($logs)
        ]);

        PuntWorkLogger::debug('About to update wp_update_post', PuntWorkLogger::CONTEXT_IMPORT, [
            'post_id' => $post_id,
            'guid' => $guid
        ]);
    }

    $post_data = [
        'ID' => $post_id,
        'post_title' => $xml_title,
        'post_name' => sanitize_title($xml_title . '-' . $guid),
        'post_status' => 'publish',
        'post_date' => $xml_validfrom,
        'post_modified' => $post_modified,
    ];

    try {
        $update_result = retry_database_operation(function() use ($post_data) {
            return wp_update_post($post_data);
        }, [$post_data], [
            'logger_context' => PuntWorkLogger::CONTEXT_IMPORT,
            'operation' => 'update_existing_post',
            'post_id' => $post_id,
            'guid' => $guid
        ]);
    } catch (\Exception $e) {
        PuntWorkLogger::error('Exception during wp_update_post', PuntWorkLogger::CONTEXT_IMPORT, [
            'post_id' => $post_id,
            'guid' => $guid,
            'error' => $e->getMessage()
        ]);
        $error_message = 'Failed to update ID: ' . $post_id . ' GUID: ' . $guid . ' - ' . $e->getErrorMessage();
        return new \WP_Error('update_failed', $error_message);
    } catch (\Throwable $t) {
        PuntWorkLogger::error('Fatal error during wp_update_post', PuntWorkLogger::CONTEXT_IMPORT, [
            'post_id' => $post_id,
            'guid' => $guid,
            'error' => $t->getMessage()
        ]);
        $error_message = 'Fatal error updating ID: ' . $post_id . ' GUID: ' . $guid . ' - ' . $t->getMessage();
        return new \WP_Error('fatal_update_error', $error_message);
    }

    if (is_wp_error($update_result)) {
        PuntWorkLogger::error('Failed to update existing post', PuntWorkLogger::CONTEXT_IMPORT, [
            'post_id' => $post_id,
            'guid' => $guid,
            'error' => $update_result->get_error_message()
        ]);
        $error_message = 'Failed to update ID: ' . $post_id . ' GUID: ' . $guid . ' - ' . $update_result->get_error_message();
        return $update_result;
    }

    if ($debug_job_updates) {
        PuntWorkLogger::debug('wp_update_post completed', PuntWorkLogger::CONTEXT_IMPORT, [
            'post_id' => $post_id,
            'guid' => $guid
        ]);

        PuntWorkLogger::debug('About to update last import meta', PuntWorkLogger::CONTEXT_IMPORT, [
            'post_id' => $post_id,
            'guid' => $guid
        ]);
    }

    try {
        $current_time = current_time('mysql');

        // Update last import update meta
        retry_database_operation(function() use ($post_id, $current_time) {
            return update_post_meta($post_id, '_last_import_update', $current_time);
        }, [$post_id, $current_time], [
            'logger_context' => PuntWorkLogger::CONTEXT_IMPORT,
            'operation' => 'update_last_import_meta',
            'post_id' => $post_id,
            'guid' => $guid
        ]);

        // NOTE: To enable item processing debug logs, define PUNTWORK_DEBUG_ITEM_PROCESSING as true in wp-config.php
        if (defined('PUNTWORK_DEBUG_ITEM_PROCESSING') && PUNTWORK_DEBUG_ITEM_PROCESSING) {
            PuntWorkLogger::debug('last import meta updated', PuntWorkLogger::CONTEXT_IMPORT, [
                'post_id' => $post_id,
                'guid' => $guid
            ]);
        }

        if ($debug_job_updates) {
            PuntWorkLogger::debug('About to update import hash', PuntWorkLogger::CONTEXT_IMPORT, [
                'post_id' => $post_id,
                'guid' => $guid
            ]);
        }

        // Update import hash
        $item_hash = md5(json_encode($item));
        retry_database_operation(function() use ($post_id, $item_hash) {
            return update_post_meta($post_id, '_import_hash', $item_hash);
        }, [$post_id, $item_hash], [
            'logger_context' => PuntWorkLogger::CONTEXT_IMPORT,
            'operation' => 'update_import_hash_meta',
            'post_id' => $post_id,
            'guid' => $guid
        ]);

        if ($debug_job_updates) {
            PuntWorkLogger::debug('import hash updated', PuntWorkLogger::CONTEXT_IMPORT, [
                'post_id' => $post_id,
                'guid' => $guid
            ]);

            PuntWorkLogger::debug('About to start ACF fields loop', PuntWorkLogger::CONTEXT_IMPORT, [
                'post_id' => $post_id,
                'guid' => $guid
            ]);

            // Update ACF fields
            PuntWorkLogger::debug('Starting ACF field updates', PuntWorkLogger::CONTEXT_IMPORT, [
                'post_id' => $post_id,
                'guid' => $guid,
                'acf_fields_count' => count($acf_fields),
                'item_type' => gettype($item),
                'acf_fields_type' => gettype($acf_fields)
            ]);
        }
        foreach ($acf_fields as $field_index => $field) {
            try {
                $value = $item[$field] ?? '';
                $is_special = in_array($field, $zero_empty_fields);
                $set_value = $is_special && $value === '0' ? '' : $value;

                // Serialize arrays if needed
                // Note: Removed serialization for ACF fields, let ACF handle it
                // if (is_array($set_value)) {
                //     $set_value = serialize($set_value);
                // }

                if (!function_exists('update_field')) {
                    if ($debug_job_updates) {
                        PuntWorkLogger::debug('update_field not available, skipping ACF field update', PuntWorkLogger::CONTEXT_IMPORT, [
                            'post_id' => $post_id,
                            'guid' => $guid,
                            'field' => $field
                        ]);
                    }
                    continue;
                } else {
                    retry_database_operation(function() use ($post_id, $field, $set_value) {
                        return update_field($field, $set_value, $post_id);
                    }, [$post_id, $field, $set_value], [
                        'logger_context' => PuntWorkLogger::CONTEXT_IMPORT,
                        'operation' => 'update_acf_field_meta',
                        'post_id' => $post_id,
                        'field' => $field,
                        'guid' => $guid
                    ]);
                }
            } catch (\Exception $e) {
                PuntWorkLogger::error('Failed to update ACF field', PuntWorkLogger::CONTEXT_IMPORT, [
                    'post_id' => $post_id,
                    'guid' => $guid,
                    'field' => $field,
                    'error' => $e->getMessage()
                ]);
                // Continue with other fields instead of failing completely
                continue;
            } catch (\Throwable $t) {
                error_log("DEBUG: Caught Throwable in ACF field processing: " . $t->getMessage() . " for field $field, post_id $post_id, guid $guid");
                PuntWorkLogger::error('Fatal error updating ACF field', PuntWorkLogger::CONTEXT_IMPORT, [
                    'post_id' => $post_id,
                    'guid' => $guid,
                    'field' => $field,
                    'field_index' => $field_index,
                    'error' => $t->getMessage(),
                    'trace' => $t->getTraceAsString()
                ]);
                // Continue with other fields
                continue;
            }
        }

        if ($debug_job_updates) {
            PuntWorkLogger::debug('ACF fields loop completed', PuntWorkLogger::CONTEXT_IMPORT, [
                'post_id' => $post_id,
                'guid' => $guid
            ]);

            PuntWorkLogger::debug('About to add to logs', PuntWorkLogger::CONTEXT_IMPORT, [
                'post_id' => $post_id,
                'guid' => $guid
            ]);
        }

        // Ensure logs is an array before appending
        if (!is_array($logs)) {
            PuntWorkLogger::error('logs is not array in update_job_post before append, resetting', PuntWorkLogger::CONTEXT_IMPORT, [
                'post_id' => $post_id,
                'guid' => $guid,
                'logs_type' => gettype($logs),
                'logs_value' => is_scalar($logs) ? substr((string)$logs, 0, 100) : 'non-scalar'
            ]);
            $logs = [];
        }

        array_push($logs, '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Updated ID: ' . $post_id . ' GUID: ' . $guid);

        if ($debug_job_updates) {
            PuntWorkLogger::debug('Added to logs', PuntWorkLogger::CONTEXT_IMPORT, [
                'post_id' => $post_id,
                'guid' => $guid
            ]);
        }

    } catch (\Exception $e) {
        PuntWorkLogger::error('Failed to update post meta for existing post', PuntWorkLogger::CONTEXT_IMPORT, [
            'post_id' => $post_id,
            'guid' => $guid,
            'error' => $e->getMessage()
        ]);
        $error_message = 'Meta update failed for ID: ' . $post_id . ' GUID: ' . $guid . ' - ' . $e->getMessage();
        return new \WP_Error('meta_update_failed', $error_message);
    } catch (\Throwable $t) {
        error_log("DEBUG: Caught Throwable in outer catch: " . $t->getMessage() . " for post_id $post_id, guid $guid");
        PuntWorkLogger::error('Fatal error updating post meta for existing post', PuntWorkLogger::CONTEXT_IMPORT, [
            'post_id' => $post_id,
            'guid' => $guid,
            'error' => $t->getMessage()
        ]);
        $error_message = 'Fatal meta update error for ID: ' . $post_id . ' GUID: ' . $guid . ' - ' . $t->getMessage();
        return new \WP_Error('fatal_meta_update_error', $error_message);
    }
}

/**
 * Get jobs by post status with optional meta filtering
 *
 * @param string $status Post status ('publish', 'draft', 'any', etc.)
 * @param int $limit Maximum number of posts to return
 * @param int $offset Offset for pagination
 * @param array $meta_filters Optional array of meta key => value filters
 * @return array Array of post objects
 */
function get_jobs_by_status($status = 'publish', $limit = -1, $offset = 0, $meta_filters = []) {
    $args = [
        'post_type' => 'job',
        'post_status' => $status,
        'posts_per_page' => $limit,
        'offset' => $offset,
        'fields' => 'ids',
    ];

    if (!empty($meta_filters)) {
        $args['meta_query'] = [];
        foreach ($meta_filters as $key => $value) {
            $args['meta_query'][] = [
                'key' => $key,
                'value' => $value,
                'compare' => '='
            ];
        }
    }

    return get_posts($args);
}

/**
 * Get jobs with specific meta key (like GUID)
 *
 * @param string $meta_key Meta key to filter by
 * @param int $limit Maximum number of posts to return
 * @param int $offset Offset for pagination
 * @return array Array of objects with ID and meta_value
 */
function get_jobs_with_meta($meta_key, $limit = -1, $offset = 0) {
    global $wpdb;

    return $wpdb->get_results($wpdb->prepare("
        SELECT p.ID, pm.meta_value
        FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
        WHERE p.post_type = 'job'
        ORDER BY p.ID
        LIMIT %d OFFSET %d
    ", $meta_key, $limit, $offset));
}

/**
 * Delete jobs by IDs with retry logic
 *
 * @param array $post_ids Array of post IDs to delete
 * @param bool $force_delete Whether to force delete (skip trash)
 * @return array Array with 'success' count and 'failed' array
 */
function delete_jobs_by_ids($post_ids, $force_delete = true) {
    $results = ['success' => 0, 'failed' => []];

    foreach ($post_ids as $post_id) {
        $result = retry_database_operation(function() use ($post_id, $force_delete) {
            return wp_delete_post($post_id, $force_delete);
        }, [$post_id, $force_delete], [
            'logger_context' => PuntWorkLogger::CONTEXT_IMPORT,
            'operation' => 'delete_job_post',
            'post_id' => $post_id
        ]);

        if ($result) {
            $results['success']++;
        } else {
            $results['failed'][] = $post_id;
        }
    }

    return $results;
}

/**
 * Get total count of jobs with optional meta filtering
 *
 * @param array $meta_filters Optional array of meta key => value filters
 * @return int Total count
 */
function get_jobs_count($meta_filters = []) {
    global $wpdb;

    $query = "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type = 'job'";
    $params = [];

    if (!empty($meta_filters)) {
        $query .= " JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id";
        $conditions = [];
        foreach ($meta_filters as $key => $value) {
            $conditions[] = "pm.meta_key = %s AND pm.meta_value = %s";
            $params[] = $key;
            $params[] = $value;
        }
        $query .= " AND (" . implode(" OR ", $conditions) . ")";
    }

    if (!empty($params)) {
        return $wpdb->get_var($wpdb->prepare($query, $params));
    } else {
        return $wpdb->get_var($query);
    }
}