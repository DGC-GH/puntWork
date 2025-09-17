<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function log_to_plugin($message) {
    $log_entry = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents(JOB_IMPORT_LOGS, $log_entry, FILE_APPEND | LOCK_EX);
    error_log('[JobImport Processor] ' . $message);
}

if (get_transient('import_cancel')) {
    log_to_plugin('Import cancelled - aborting batch');
    return ['complete' => true, 'message' => 'Cancelled'];
}

// From 1.6 - Item Cleaning.php
if (!function_exists('clean_item_fields')) {
    function clean_item_fields(&$item) {
        log_to_plugin('Cleaning item fields');
        $html_fields = ['description', 'functiondescription', 'offerdescription', 'requirementsdescription', 'companydescription'];
        foreach ($html_fields as $field) {
            if (isset($item->$field)) {
                $content = (string)$item->$field;
                $content = wp_kses($content, wp_kses_allowed_html('post'));
                $content = preg_replace('/\s*styles*=\s*["\'][^"\']*["\']/', '', $content);
                $content = preg_replace('/[\x{1F600}-\x{1F64F}]/u', '', $content);
                $content = str_replace('&nbsp;', ' ', $content);
                $item->$field = trim($content);
            }
        }
        $title_fields = ['functiontitle'];
        foreach ($title_fields as $field) {
            if (isset($item->$field)) {
                $content = (string)$item->$field;
                $content = preg_replace('/\s+(m\/v\/x|h\/f\/x)/i', '', $content);
                $item->$field = trim($content);
            }
        }
        log_to_plugin('Item cleaning complete');
    }
}

// From 1.7 - Item Inference.php (full from partial fetch)
function infer_item_details(&$item, $fallback_domain, $lang, &$job_obj) {
    log_to_plugin('Inferring item details for lang: ' . $lang);
    $province = strtolower(trim(isset($item->province) ? (string)$item->province : ''));
    $norm_province = get_province_map()[$province] ?? $fallback_domain;
    $title = isset($item->functiontitle) ? (string)$item->functiontitle : '';
    $enhanced_title = $title;
    if (isset($item->city)) $enhanced_title .= ' in ' . (string)$item->city;
    if (isset($item->province)) $enhanced_title .= ', ' . (string)$item->province;
    $enhanced_title = trim($enhanced_title);
    $slug = sanitize_title($enhanced_title . '-' . (string)$item->guid);
    $job_link = 'https://' . $norm_province . '/job/' . $slug;
    $fg = strtolower(trim(isset($item->functiongroup) ? (string)$item->functiongroup : ''));
    $estimate_key = array_reduce(array_keys(get_salary_estimates()), function($carry, $key) use ($fg) {
        return strpos($fg, strtolower($key)) !== false ? $key : $carry;
    }, null);
    $salary_text = '';
    if (isset($item->salaryfrom) && $item->salaryfrom != '0' && isset($item->salaryto) && $item->salaryto != '0') {
        $salary_text = '€' . (string)$item->salaryfrom . ' - €' . (string)$item->salaryto;
    } elseif (isset($item->salaryfrom) && $item->salaryfrom != '0') {
        $salary_text = '€' . (string)$item->salaryfrom;
    } else {
        $est_prefix = ($lang == 'nl' ? 'Geschat ' : ($lang == 'fr' ? 'Estimé ' : 'Est. '));
        if ($estimate_key) {
            $low = get_salary_estimates()[$estimate_key]['low'];
            $high = get_salary_estimates()[$estimate_key]['high'];
            $salary_text = $est_prefix . '€' . $low . ' - €' . $high;
        } else {
            $salary_text = '€3000 - €4500';
        }
    }
    $apply_link = isset($item->applylink) ? (string)$item->applylink : '';
    if ($apply_link) $apply_link .= '?utm_source=puntwork&utm_term=' . (string)$item->guid;
    $icon_key = array_reduce(array_keys(get_icon_map()), function($carry, $key) use ($fg) {
        return strpos($fg, strtolower($key)) !== false ? $key : $carry;
    }, null);
    $icon = $icon_key ? get_icon_map()[$icon_key] : get_icon_map()['default'];
    $all_text = strtolower(implode(' ', [(string)$item->functiontitle, (string)$item->description, (string)$item->functiondescription, (string)$item->offerdescription, (string)$item->requirementsdescription, (string)$item->companydescription]));
    $job_car = (bool)preg_match('/bedrijfs(wagen|auto)|firmawagen|voiture de société|company car/i', $all_text);
    $job_remote = (bool)preg_match('/thuiswerk|télétravail|remote work|home office/i', $all_text);
    $job_meal_vouchers = (bool)preg_match('/maaltijdcheques|chèques repas|meal vouchers/i', $all_text);
    $job_flex_hours = (bool)preg_match('/flexibele uren|heures flexibles|flexible hours/i', $all_text);
    $job_skills = [];
    if (preg_match('/\bexcel\b|\bmicrosoft excel\b|\bms excel\b/i', $all_text)) $job_skills[] = 'Excel';
    if (preg_match('/\bwinbooks\b/i', $all_text)) $job_skills[] = 'WinBooks';
    $parttime = isset($item->parttime) && (string)$item->parttime == 'true';
    $job_time = $parttime ? ($lang == 'nl' ? 'Deeltijds' : ($lang == 'fr' ? 'Temps partiel' : 'Part-time')) : ($lang == 'nl' ? 'Voltijds' : ($lang == 'fr' ? 'Temps plein' : 'Full-time'));
    $job_desc = ($lang == 'nl' ? 'Vacature' : ($lang == 'fr' ? 'Emploi' : 'Job')) . ': ' . $enhanced_title . '. ' . (isset($item->functiondescription) ? (string)$item->functiondescription : '') . ($lang == 'nl' ? ' Bij ' : ($lang == 'fr' ? ' Chez ' : ' At ')) . (isset($item->companyname) ? (string)$item->companyname : 'Unknown Company') . '. ' . $salary_text . '. ' . $job_time . ' position.';
    // Update item with inferred fields
    $item->job_link = $job_link;
    $item->salary_text = $salary_text;
    $item->apply_link = $apply_link;
    $item->icon = $icon;
    $item->job_car = $job_car ? 'yes' : 'no';
    $item->job_remote = $job_remote ? 'yes' : 'no';
    $item->job_meal_vouchers = $job_meal_vouchers ? 'yes' : 'no';
    $item->job_flex_hours = $job_flex_hours ? 'yes' : 'no';
    $item->job_skills = implode(', ', $job_skills);
    $item->job_desc = $job_desc;
    log_to_plugin('Inference complete for item GUID: ' . (string)$item->guid);
}

// From 1.9 - Process XML Batch.php
function process_xml_batch($xml_path, $handle, $feed_key, $output_dir, $fallback_domain, $batch_size, &$total_items, &$logs) {
    log_to_plugin("Starting XML batch process for $feed_key - path: $xml_path");
    $feed_item_count = 0;
    $batch = [];
    try {
        $reader = new XMLReader();
        if (!$reader->open($xml_path)) throw new Exception('Invalid XML');
        while ($reader->read()) {
            if ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'item') {
                $item = new stdClass();
                // Traverse child elements of <item>
                while ($reader->read() && !($reader->nodeType == XMLReader::END_ELEMENT && $reader->name == 'item')) {
                    if ($reader->nodeType == XMLReader::ELEMENT) {
                        $name = strtolower(preg_replace('/^.*:/', '', $reader->name));
                        if ($reader->isEmptyElement) {
                            $item->$name = '';
                        } else {
                            $value = $reader->readInnerXML();
                            $item->$name = $value;
                        }
                    }
                }
                // If item is empty or failed to collect fields, skip and log
                if (empty((array)$item)) {
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "$feed_key item skipped: No fields collected";
                    continue;
                }
                clean_item_fields($item);
                infer_item_details($item, $fallback_domain, 'en', $job_obj); // Lang detection partial
                $batch[] = $item;
                if (count($batch) >= $batch_size) {
                    foreach ($batch as $item) {
                        fwrite($handle, json_encode((array)$item) . "\n");
                        $total_items++;
                    }
                    $batch = [];
                }
                $feed_item_count++;
            }
        }
        // Write remaining batch
        foreach ($batch as $item) {
            fwrite($handle, json_encode((array)$item) . "\n");
            $total_items++;
        }
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Processed $feed_item_count items from $feed_key";
        log_to_plugin("XML batch complete for $feed_key - $feed_item_count items");
    } catch (Exception $e) {
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "XML process error for $feed_key: " . $e->getMessage();
        log_to_plugin("XML error for $feed_key: " . $e->getMessage());
    }
    return $feed_item_count;
}

// From 2.3 - Import Batch.php (full integration)
if (!function_exists('import_jobs_from_json')) {
    function import_jobs_from_json($is_batch = false, $batch_start = 0) {
        global $job_import_batch_limit;
        log_to_plugin("import_jobs_from_json called - batch: " . ($is_batch ? 'yes' : 'no') . ", start: $batch_start, limit: " . ($job_import_batch_limit ?? 50));
        
        $json_path = ABSPATH . 'feeds/combined-jobs.json';
        if (!file_exists($json_path)) {
            $error_msg = "JSON file missing: $json_path - run fetch first";
            log_to_plugin($error_msg);
            return ['error' => $error_msg, 'complete' => true];
        }
        
        $total = get_json_item_count($json_path);
        log_to_plugin("Total items in JSON: $total");
        
        $status = get_option('job_import_status', []);
        $status['total'] = $total;
        $status['start_time'] = $status['start_time'] ?? microtime(true);
        $batch_size = $is_batch ? ($job_import_batch_limit ?? 50) : $total;
        $end = min($batch_start + $batch_size, $total);
        
        $batch_items = load_json_batch($json_path, $batch_start, $batch_size);
        log_to_plugin("Loaded batch: " . count($batch_items) . " items from $batch_start to " . ($end - 1));
        
        if (empty($batch_items)) {
            $status['complete'] = true;
            $status['end_time'] = microtime(true);
            $status['message'] = 'No more items';
            update_option('job_import_status', $status);
            log_to_plugin('Batch empty - import complete');
            return $status;
        }
        
        // Get existing GUIDs/hashes for duplicates
        $existing_by_guid = get_option('job_existing_guids', []);
        $all_hashes_by_post = get_option('job_import_hashes', []);
        $last_updates = get_posts(['post_type' => 'job', 'posts_per_page' => -1, 'fields' => 'ids']);
        log_to_plugin('Existing GUIDs count: ' . count($existing_by_guid) . ', last updates count: ' . count($last_updates));
        
        // ACF fields prep
        $acf_fields = ['job_link', 'salary_text', 'apply_link', 'icon', 'job_car', 'job_remote', 'job_meal_vouchers', 'job_flex_hours', 'job_skills', 'job_desc'];
        $zero_empty_fields = true;  // Set empty to 0 if needed
        
        $batch_guids = [];
        foreach ($batch_items as $item) {
            $batch_guids[] = $item['guid'] ?? uniqid();
        }
        $duplicates_drafted = 0;
        $post_ids_by_guid = [];
        $logs = [];  // Local logs for this batch
        handle_duplicates($batch_guids, $existing_by_guid, $logs, $duplicates_drafted, $post_ids_by_guid);
        log_to_plugin("Duplicates drafted: $duplicates_drafted, logs: " . implode(', ', $logs));
        
        $updated = $created = $skipped = $processed_count = 0;
        process_batch_items($batch_guids, $batch_items, $last_updates, $all_hashes_by_post, $acf_fields, $zero_empty_fields, $post_ids_by_guid, $logs, $updated, $created, $skipped, $processed_count);
        log_to_plugin("Batch results: created=$created, updated=$updated, skipped=$skipped, processed=$processed_count");
        
        $status['processed'] += $processed_count;
        $status['created'] += $created;
        $status['updated'] += $updated;
        $status['skipped'] += $skipped;
        $status['duplicates_drafted'] += $duplicates_drafted;
        $status['time_elapsed'] += (microtime(true) - ($status['start_time'] ?? microtime(true)));
        $status['last_update'] = time();
        $status['logs'] = array_merge($status['logs'] ?? [], $logs);
        $status['message'] = "Processed batch $batch_start-$end/$total";
        if ($status['processed'] >= $total) {
            $status['complete'] = true;
            $status['end_time'] = microtime(true);
        }
        
        update_option('job_import_status', $status);
        update_option('job_existing_guids', array_merge($existing_by_guid, $post_ids_by_guid));
        update_option('job_import_hashes', array_merge($all_hashes_by_post, $post_ids_by_guid));  // Simplified
        
        if (!$is_batch || $status['complete']) {
            log_to_plugin('Full import complete - total processed: ' . $status['processed']);
        } else {
            log_to_plugin('Batch done - next start: ' . $end);
        }
        
        return $status;
    }
}

// From 2.4 - Handle Duplicates.php
function handle_duplicates($batch_guids, $existing_by_guid, &$logs, &$duplicates_drafted, &$post_ids_by_guid) {
    log_to_plugin('Handling duplicates for ' . count($batch_guids) . ' GUIDs');
    $drafted_old = 0;
    foreach ($batch_guids as $guid) {
        if (isset($existing_by_guid[$guid])) {
            $post_id = $existing_by_guid[$guid];
            $update_result = wp_update_post([
                'ID' => $post_id,
                'post_status' => 'draft'
            ]);
            if (!is_wp_error($update_result)) {
                $duplicates_drafted++;
                $drafted_old++;
                $logs[] = "Drafted existing post ID $post_id for duplicate GUID $guid";
                log_to_plugin("Drafted duplicate post ID $post_id for GUID $guid");
            } else {
                $logs[] = "Failed to draft post ID $post_id for GUID $guid: " . $update_result->get_error_message();
            }
        }
    }
    log_to_plugin("Duplicates handled - drafted: $duplicates_drafted, old unpublished: $drafted_old");
}

// From 2.5 - Process Batch Items.php
if (!function_exists('process_batch_items')) {
    function process_batch_items($batch_guids, $batch_items, $last_updates, $all_hashes_by_post, $acf_fields, $zero_empty_fields, $post_ids_by_guid, &$logs, &$updated, &$created, &$skipped, &$processed_count) {
        log_to_plugin('Processing ' . count($batch_items) . ' items in batch');
        foreach ($batch_items as $index => $item) {
            $guid = $item['guid'] ?? $batch_guids[$index];
            $hash = md5(serialize($item));  // Detect changes
            $post_id = $post_ids_by_guid[$guid] ?? 0;
            
            $post_data = [
                'post_title' => $item['functiontitle'] ?? 'Untitled Job',
                'post_content' => $item['job_desc'] ?? $item['description'] ?? '',
                'post_type' => 'job',
                'post_status' => 'publish',
                'meta_input' => [],
            ];
            
            if ($post_id && isset($all_hashes_by_post[$post_id]) && $all_hashes_by_post[$post_id] === $hash) {
                // No changes
                $skipped++;
                $logs[] = "Skipped unchanged post ID $post_id for GUID $guid";
                $processed_count++;
                continue;
            }
            
            if ($post_id) {
                // Update
                $post_data['ID'] = $post_id;
                $result = wp_update_post($post_data);
                if ($result && !is_wp_error($result)) {
                    $updated++;
                    foreach ($acf_fields as $field) {
                        $value = $item[$field] ?? '';
                        if ($zero_empty_fields && $value === '') $value = 0;
                        update_field($field, $value, $post_id);
                    }
                    $all_hashes_by_post[$post_id] = $hash;
                    $logs[] = "Updated post ID $post_id for GUID $guid";
                    log_to_plugin("Updated post ID $post_id");
                } else {
                    $logs[] = "Failed to update post ID $post_id: " . ($result ? $result->get_error_message() : 'Unknown error');
                }
            } else {
                // Create new
                $result = wp_insert_post($post_data);
                if ($result && !is_wp_error($result)) {
                    $post_ids_by_guid[$guid] = $result;
                    foreach ($acf_fields as $field) {
                        $value = $item[$field] ?? '';
                        if ($zero_empty_fields && $value === '') $value = 0;
                        update_field($field, $value, $result);
                    }
                    $all_hashes_by_post[$result] = $hash;
                    $created++;
                    $logs[] = "Created new post ID $result for GUID $guid";
                    log_to_plugin("Created post ID $result");
                } else {
                    $logs[] = "Failed to create post for GUID $guid: " . ($result ? $result->get_error_message() : 'Unknown error');
                }
            }
            $processed_count++;
        }
        log_to_plugin('Batch items processing complete - updated: ' . $updated . ', created: ' . $created . ', skipped: ' . $skipped);
    }
}
