# PuntWork Feeds Import Process - Complete Chronological Flow
## Every Code Component That Must Execute Successfully

**NOTE: Import Function Variants**
- `import_jobs_from_json()` - Processes a single batch (used by AJAX calls)
- `import_all_jobs_from_json()` - Processes all batches sequentially (used by scheduled imports)
- Both call the same core processing logic but handle batch looping differently

---

## **STEP 1: WordPress Admin Page Load**
**File: `includes/admin/admin-ui-main.php`**

DEBUG
- debug.log:
- console:

1. **Admin Menu Registration**
   - Anonymous function hooked to `admin_menu` executes
   - `add_menu_page()` creates main puntWork menu
   - `add_submenu_page()` creates submenu items
   - `add_action(`admin_enqueue_scripts`, `enqueue_job_import_scripts`)` loads scripts/styles
   - CSS/JS assets enqueued successfully
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [ADMIN-MENU] Admin menu registered successfully - Timestamp: {timestamp}, User: {user_id}`
       - `[PUNTWORK] [ADMIN-MENU] Menu registration completed - Main menu and submenu items added`
     - console:

2. **UI Rendering**
   - `render_main_import_ui()` called
   - HTML elements render: `#start-import` button, progress bars, status displays
   - AJAX polling mechanism initialized
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [UI-RENDER] render_main_import_ui() called - Rendering main import interface`
       - `[PUNTWORK] [UI-RENDER] UI rendering completed - Import button, progress bars, and status displays ready`
     - console:

3. **JavaScript Event Binding**
   - `$(`#start-import`).click()` handler bound
   - AJAX status polling starts with `setInterval`
   - DEBUG
     - debug.log:
     - console:
       - `[PUNTWORK] JobImportLogic initialized`

---

## **STEP 2: User Clicks Import Button**
**File: `assets/js/job-import-admin.js`**

DEBUG
- debug.log:
- console:

1. **Click Event Triggered**
   - User clicks `#start-import` button
   - JavaScript event handler executes
   - DEBUG
     - debug.log:
     - console:
       - `[PUNTWORK] [UI-CLICK] Import button clicked by user {user_id}`

2. **AJAX Request Sent**
   - AJAX POST request sent to `wp_ajax_run_job_import_batch`
   - Includes nonce for security: `job_import_nonce`
   - Request data: `{action: `run_job_import_batch`, nonce: `...`, start: 0}`
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [AJAX-REQUEST] AJAX request sent - User: {user_id}, Data: {action: run_job_import_batch, start: 0}`
     - console:
       - `PuntWork: Import button clicked, sending AJAX request`
       - `PuntWork: AJAX request data: {action: `run_job_import_batch`, start: 0}`

---

## **STEP 3: AJAX Request Received - Security Validation**
**File: `includes/api/ajax-import-control.php`**

DEBUG
- debug.log:
- console:

1. **Request Logging**
   - `PuntWorkLogger::logAjaxRequest(`run_job_import_batch`, $_POST)` logs request
   - Memory usage logged: `memory_get_usage(true)`
   - PHP/WordPress version checks logged
   - DEBUG
     - debug.log:
     - console:

2. **Nonce Verification**
   - `wp_verify_nonce($_POST[`nonce`], `job_import_nonce`)` validates
   - Returns 403 error if nonce fails
   - DEBUG
     - debug.log:
     - console:

3. **User Permission Check**
   - `current_user_can(`manage_options`)` validates
   - Returns 403 error if permission denied
   - DEBUG
     - debug.log:
     - console:

4. **Comprehensive Security Validation**
   - `SecurityUtils::validateAjaxRequest()` executes with field rules
   - Required fields validated: `[`start`]`
   - Field types validated: `start` must be integer, min 0, max 1000000
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [AJAX-RECEIVED] Import request received - User: {user_id}, IP: {ip_address}, Memory: {memory_usage}MB`
       - `[PUNTWORK] [AJAX-SECURITY] Nonce verification: {passed|failed}; User permissions check: {passed|failed} for user {user_id}; Field validation: start={value}, type={valid|invalid}`
     - console:
       - `PuntWork: AJAX request received and validated`

---

## **STEP 4: Function Loading & Import Lock Check**
**File: `includes/api/ajax-import-control.php`**

DEBUG
- debug.log:
- console:

1. **Required Includes Loading**
   - Explicit `require_once` statements load utility classes:
     - `SecurityUtils.php`, `PuntWorkLogger.php`, `CacheManager.php`, `AjaxErrorHandler.php`
     - `DynamicRateLimiter.php`, `core-structure-logic.php`, `feed-processor.php`
     - `download-feed.php`, `gzip-file.php`, `combine-jsonl.php`
     - Jobboard classes: `jobboard.php`, `jobboard-manager.php`, etc.
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [FUNCTION-LOAD] Loading core classes: SecurityUtils, PuntWorkLogger, CacheManager...`
       - `[PUNTWORK] [FUNCTION-LOAD] All required classes loaded successfully`
     - console:

2. **Concurrent Import Lock Check**
   - `get_transient(`puntwork_import_lock`)` checked
   - Returns error if import already running
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [LOCK-CHECK] Import lock status: {locked|unlocked}, Lock expires: {timestamp}`
     - console:

3. **Function Existence Validation**
   - `function_exists(`prepare_import_setup`)` checked
   - `function_exists(`process_batch_items_logic`)` checked
   - `function_exists(`finalize_batch_import`)` checked
   - Returns error if any function missing
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [FUNCTION-CHECK] Function validation: prepare_import_setup={exists}, process_batch_items_logic={exists}, finalize_batch_import={exists}`
     - console:
       - `PuntWork: Function loading and lock check completed`

---

## **STEP 5: Main Import Function Execution**
**File: `includes/import/import-batch.php`**

DEBUG
- debug.log:
- console:

1. **Import Lock Set**
   - `set_transient(`puntwork_import_lock`, true, 3600)` prevents concurrency
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [IMPORT-START] Import lock set for 3600 seconds`
     - console:

2. **Import Entry Point**
   - `import_jobs_from_json(true, $start)` called
   - Debug logging: memory usage, PHP version, user ID
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [IMPORT-START] Starting import - User: {user_id}, PHP: {version}, Memory: {usage}MB, Start: {start_index}`
     - console:

3. **Stale Lock Detection**
   - `get_option(`job_import_status`)` checked for existing imports
   - Stale locks cleared if import complete or >30 minutes old
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [STALE-LOCK] Checking for stale imports: Found {status}, Age: {minutes} minutes`
     - console:

4. **Setup Phase Execution**
   - `prepare_import_setup()` called within `import_jobs_from_json`
   - Feed configuration loaded
   - File system validation
   - Batch size optimization
   - GUID caching system initialized
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [SETUP-START] Executing prepare_import_setup()`
       - `[PUNTWORK] [SETUP-COMPLETE] Setup completed - Feeds: {count}, Batch size: {size}, GUID cache: {initialized}`
     - console:
       - `PuntWork: Main import function started, setup phase completed`

---

## **STEP 6: Feed Processing (Download & Convert) - PRE-IMPORT PHASE**
**Files: `includes/api/ajax-import-control.php` (process_feed, combine_jsonl handlers)**

DEBUG
- debug.log:
- console:

1. **Feed URL Retrieval**
   - `get_feeds()` queries `job-feed` custom post type
   - Returns array of feed URLs keyed by feed slug
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [FEED-RETRIEVAL] Retrieved {count} feeds from database`
     - console:

2. **Individual Feed Processing Loop**
   - `process_one_feed()` called for each feed via separate AJAX calls
   - HTTP requests made to feed URLs
   - XML/JSON parsing and conversion to JSONL
   - Files stored as `{feed_key}.jsonl` in `/feeds/` directory
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [FEED-PROCESS] Processing feed: {feed_slug} - URL: {feed_url}`
       - `[PUNTWORK] [FEED-DOWNLOAD] HTTP request to {url} - Status: {status_code}, Size: {bytes} bytes`
       - `[PUNTWORK] [FEED-CONVERT] Converting {feed_slug} - Items parsed: {count}, Format: {xml|json}`
       - `[PUNTWORK] [FEED-SAVE] Saved {feed_slug}.jsonl - Items: {count}, Size: {bytes} bytes`
     - console:

3. **Feed Combination**
   - `combine_jsonl_files()` called via separate AJAX call
   - Merges all individual JSONL files
   - Creates `combined-jobs.jsonl` with deduplication
   - Automatic import scheduling: `wp_schedule_single_event(time() + 5, `puntwork_start_scheduled_import`)`
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [FEED-COMBINE] Combining {count} feed files into combined-jobs.jsonl`
       - `[PUNTWORK] [FEED-COMBINE] Combined file created - Total items: {count}, Duplicates removed: {count}`
       - `[PUNTWORK] [SCHEDULER] Scheduled automatic import in 5 seconds`
     - console:
       - `PuntWork: Feed processing completed, combined file ready`

---

## **STEP 7: Batch Processing Loop Initialization**
**File: `includes/batch/batch-processing-core.php`**

DEBUG
- debug.log:
- console:

1. **Performance Monitoring Start**
   - `start_performance_monitoring($perf_id)` initializes
   - Memory limits increased: `ini_set(`memory_limit`, $original_memory_limit * 2)`
   - Expensive plugins disabled: `disable_expensive_plugins()`
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [PERF-MONITOR] Performance monitoring started - ID: {perf_id}`
       - `[PUNTWORK] [MEMORY-LIMIT] Memory limit increased from {original} to {new} MB`
       - `[PUNTWORK] [PLUGIN-DISABLE] Disabled {count} expensive plugins: {plugin_list}`
     - console:

2. **Setup Validation**
   - `prepare_import_setup()` called again to validate combined JSONL file
   - `validate_jsonl_file()` checks file integrity
   - `get_json_item_count()` counts total items
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [FILE-VALIDATION] Validating combined-jobs.jsonl - Exists: {yes|no}, Readable: {yes|no}`
       - `[PUNTWORK] [ITEM-COUNT] Total items in feed: {count}`
     - console:

3. **Main Processing Loop Start**
   - `process_batch_items_logic()` begins batch loop
   - Progress tracking initialized
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [BATCH-INIT] Starting batch processing loop - Batch size: {size}, Start index: {index}`
     - console:
       - `PuntWork: Batch processing initialized, performance monitoring active`

---

## **STEP 8: Individual Batch Processing**
**File: `includes/batch/batch-processing-core.php`**

DEBUG
- debug.log:
- console:

1. **Batch Loading**
   - `load_and_prepare_batch_items()` loads current batch
   - `file_exists($json_path)` and `is_readable($json_path)` validated
   - `load_json_batch()` reads JSONL lines with `fopen()` and `fgets()`
   - JSON decoding with `json_decode()` and error handling
   - GUID validation: `if (empty($guid))` checks
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [BATCH-LOAD] Loading batch {batch_number} - Start: {start_index}, Size: {batch_size}`
       - `[PUNTWORK] [BATCH-LOAD] File opened successfully: {json_path}`
       - `[PUNTWORK] [BATCH-LOAD] Loaded {count} items from JSONL file`
       - `[PUNTWORK] [JSON-PARSE] JSON decoding: Valid={valid_count}, Invalid={invalid_count}, Empty_GUID={empty_count}`
     - console:

2. **Memory Management**
   - `check_batch_memory_usage()` monitors usage
   - Batch size reduction: `if (memory_get_usage(true) > $threshold)`
   - Output buffering: `ob_flush()` and `flush()` every 5 iterations
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [MEMORY-CHECK] Memory usage: {current}MB / {limit}MB ({percentage}%)`
       - `[PUNTWORK] [BATCH-ADJUST] Memory high, reduced batch size from {old} to {new}`
       - `[PUNTWORK] [OUTPUT-FLUSH] Output buffer flushed at iteration {iteration}`
     - console:

3. **Cancellation Checks**
   - `get_transient(`import_cancel`)` checked each iteration
   - Import cancelled if transient exists
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [CANCEL-CHECK] Cancellation check: {not_cancelled|cancelled}`
     - console:
       - `PuntWork: Batch loaded, processing items...`

---

## **STEP 9: Database Operations & Duplicate Handling**
**File: `includes/batch/batch-processing-core.php`**

DEBUG
- debug.log:
- console:

1. **Existing Posts Query**
   - `get_posts_by_guids_with_status($batch_guids)` queries database
   - GUID-to-post-ID mapping created
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [DB-QUERY] Querying existing posts for {count} GUIDs`
       - `[PUNTWORK] [DB-RESULT] Found {existing_count} existing posts, {new_count} new items`
     - console:

2. **Duplicate Detection**
   - `handle_batch_duplicates()` identifies existing jobs
   - Drafts created for duplicates based on configuration
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [DUPLICATE-HANDLE] Duplicate handling: {draft|skip|update} mode active`
     - console:

3. **Metadata Preparation**
   - `prepare_batch_metadata()` loads last update timestamps
   - Content hashes calculated for change detection
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [METADATA-PREPARE] Loading metadata for batch - Last updates: {timestamp_range}`
       - `[PUNTWORK] [HASH-CALC] Content hash calculated: {hash_value}`
     - console:

4. **Change Detection Optimization**
   - `check_batch_for_changes()` compares batch hash vs stored hash
   - Entire batch skipped if no changes detected
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [CHANGE-DETECT] Change detection: Batch hash {matches|differs} stored hash`
       - `[PUNTWORK] [BATCH-SKIP] Batch {batch_number} skipped - no changes detected`
     - console:
       - `PuntWork: Database operations completed, duplicates handled`

---

## **STEP 10: Individual Item Processing Loop**
**File: `includes/batch/batch-processing.php`**

DEBUG
- debug.log:
- console:

1. **Item Processing Loop**
   - `process_batch_items_with_metadata()` executes
   - Each item processed individually
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [ITEM-PROCESS] Processing item {item_number}/{total} - GUID: {guid}`
     - console:

2. **Post Existence Check**
   - GUID lookup: `get_posts([`meta_key` => `_guid`, `meta_value` => $guid])`
   - Post status determination (`publish`, `draft`, etc.)
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [POST-LOOKUP] Post lookup result: {found|not_found}, Post ID: {id}, Status: {status}`
     - console:

3. **Update vs Create Decision**
   - Timestamp comparison: existing post vs feed item
   - Content hash comparison for change detection
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [UPDATE-DECISION] Decision: {create|update|skip} - Feed date: {feed_date}, Post date: {post_date}`
     - console:

4. **Post Creation/Update**
   - `wp_insert_post($job_data)` for new posts
   - Post meta updates: `update_post_meta($post_id, `_guid`, $guid)`
   - Taxonomy assignments: `wp_set_post_terms()`
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [POST-CREATE] Created new post - ID: {post_id}, Title: {title}`
       - `[PUNTWORK] [POST-UPDATE] Updated existing post - ID: {post_id}, Changes: {field_list}`
       - `[PUNTWORK] [META-UPDATE] Post meta updated: _guid, _source, _last_updated`
       - `[PUNTWORK] [TAXONOMY-SET] Taxonomy terms set: {categories}, {tags}`
     - console:

5. **ACF Field Processing**
   - Field mapping and validation
   - `update_field()` calls for custom fields
   - Error handling for ACF operations
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [ACF-FIELDS] ACF fields updated: {field_count} fields processed`
       - `[PUNTWORK] [ACF-ERROR] ACF field error: {field_name} - {error_message}`
     - console:
       - `PuntWork: Item processed - Created: {count}, Updated: {count}, Skipped: {count}`

---

## **STEP 11: Batch Completion & Status Updates**
**File: `includes/batch/batch-processing-core.php`**

DEBUG
- debug.log:
- console:

1. **Batch Finalization**
   - `finalize_batch_import($result)` called
   - Progress counters accumulated: published, updated, skipped
   - Time elapsed calculated: `microtime(true) - $status[`start_time`]`
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [BATCH-FINALIZE] Finalizing batch {batch_number} - Processed: {processed}, Time: {elapsed} seconds`
       - `[PUNTWORK] [COUNTERS] Batch counters - Published: {published}, Updated: {updated}, Skipped: {skipped}, Failed: {failed}`
     - console:

2. **Status Updates**
   - `update_option(`job_import_status`, $status)` saves progress
   - Completion detection: `($end_index >= $total)`
   - Success/failure status set
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [STATUS-UPDATE] Status updated - Progress: {current}/{total} ({percentage}%), Complete: {yes|no}`
     - console:

3. **Performance Metrics**
   - `end_performance_monitoring($perf_id)` completes monitoring
   - Database performance monitoring ended
   - Batch timing data stored
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [PERF-END] Performance monitoring ended - ID: {perf_id}, Duration: {duration} seconds`
       - `[PUNTWORK] [DB-PERF] Database operations: {query_count} queries, {time} seconds`
     - console:
       - `PuntWork: Batch completed, status updated`

---

## **STEP 12: Loop Continuation or Completion**
**File: `includes/import/import-batch.php`**

DEBUG
- debug.log:
- console:

1. **Completion Check**
   - `($end_index >= $total)` determines if import complete
   - Loop continues if more batches needed
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [COMPLETION-CHECK] Import completion check - Current: {current}, Total: {total}, Complete: {yes|no}`
     - console:

2. **Time/Memory Limit Checks**
   - `import_time_exceeded()` checks 600-second limit
   - `import_memory_exceeded()` checks 90% memory usage
   - `should_continue_batch_processing()` returns false if limits exceeded
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [TIME-CHECK] Time limit check - Elapsed: {elapsed} seconds, Limit: 600, Exceeded: {yes|no}`
       - `[PUNTWORK] [MEMORY-CHECK] Memory limit check - Usage: {usage}MB, Limit: {limit}MB, Exceeded: {yes|no}`
       - `[PUNTWORK] [CONTINUE-DECISION] Continue processing: {yes|no} - Next batch: {start_index}`
     - console:

3. **Progress Persistence**
   - `update_option(`job_import_progress`, $end_index)` saves current position
   - Resume capability maintained for interrupted imports
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [PROGRESS-SAVE] Progress saved - Position: {position}, Can resume: {yes}`
     - console:
       - `PuntWork: Import loop check - Continuing: {yes|no}, Progress: {percentage}%`

---

## **STEP 13: Import Completion & Finalization**
**File: `includes/import/import-finalization.php`**

DEBUG
- debug.log:
- console:

1. **Final Status Update**
   - `finalize_batch_import()` sets `complete: true`
   - Final statistics calculated and stored
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [IMPORT-COMPLETE] Import completed successfully - Total time: {total_time} seconds`
       - `[PUNTWORK] [FINAL-STATS] Final statistics - Published: {published}, Updated: {updated}, Skipped: {skipped}, Failed: {failed}`
     - console:

2. **History Logging**
   - `log_manual_import_run()` or `log_scheduled_run()` records completion
   - Success/failure status, duration, item counts logged
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [HISTORY-LOG] Import logged to history - Type: {manual|scheduled}, Duration: {duration}`
     - console:

3. **Social Media Integration**
   - `get_option(`puntwork_social_auto_post_jobs`, false)` checked
   - `post_new_jobs_to_social_media()` executes if enabled
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [SOCIAL-CHECK] Social media posting: {enabled|disabled}`
       - `[PUNTWORK] [SOCIAL-POST] Posted {count} new jobs to social media`
     - console:

4. **Cleanup Operations**
   - `cleanup_import_data()` removes transients and temporary options
   - `delete_transient(`import_cancel`)` clears cancellation flags
   - Import lock removed
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [CLEANUP] Cleanup operations - Transients cleared, temp files removed`
       - `[PUNTWORK] [LOCK-REMOVE] Import lock removed`
     - console:
       - `PuntWork: Import completed successfully!`

---

## **STEP 14: UI Status Updates & User Feedback**
**File: `includes/api/ajax-import-control.php`**

DEBUG
- debug.log:
- console:

1. **AJAX Status Polling**
   - Frontend `setInterval` polls `get_job_import_status_ajax`
   - `safe_get_option(`job_import_status`)` retrieves current status
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [STATUS-POLL] Status polling request received`
       - `[PUNTWORK] [STATUS-RESPONSE] Status response - Progress: {percentage}%, Complete: {yes|no}, Time: {elapsed}`
     - console:

2. **Stale Import Detection**
   - Completion status checked
   - Time since last update validated
   - Stale imports automatically cleaned up
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [STALE-DETECT] Stale import detection - Age: {minutes}, Cleaned: {yes|no}`
     - console:

3. **Progress Display Updates**
   - Progress bars updated with current percentages
   - Status messages displayed to user
   - Time elapsed and ETA calculations shown
   - DEBUG
     - debug.log:
       - `[PUNTWORK] [UI-UPDATE] UI update sent - Counters: Published:{p}, Updated:{u}, Skipped:{s}`
     - console:
       - `PuntWork: Status updated - Progress: {percentage}%, Complete: {yes|no}`

4. **Completion UI Updates**
   - Success/failure states reflected in interface
   - Import buttons re-enabled
   - Final statistics displayed
   - DEBUG
     - debug.log:
     - console:
       - `PuntWork: UI updated with final statistics`

---

## **CRITICAL VALIDATION CHECKS (Executed Throughout)**

### Security Validations (Every AJAX Request)
```php
if (!wp_verify_nonce($_POST[`nonce`], `job_import_nonce`)) {
    wp_send_json_error([`message` => `Security check failed`]);
    return;
}
if (!current_user_can(`manage_options`)) {
    wp_send_json_error([`message` => `Insufficient permissions`]);
    return;
}
```

### File System Validations
```php
if (!file_exists($json_path)) {
    $logs[] = `ERROR: JSON file not found`;
    return [`batch_items` => [], `batch_guids` => []];
}
```

### JSON Processing Validations
```php
$item = json_decode($line, true);
if ($item === null) {
    $invalid_json++;
    continue;
}
$guid = $item[`guid`] ?? ``;
if (empty($guid)) {
    $missing_guids++;
    continue;
}
```

### Memory & Time Management
```php
if (memory_get_usage(true) > $threshold) {
    $batch_size = max(1, (int)($batch_size * 0.8));
}
if (import_time_exceeded()) {
    return false; // Pause processing
}
```

### Database Operations
```php
$existing_posts = get_posts([
    `post_type` => `job_listing`,
    `meta_key` => `_guid`,
    `meta_value` => $guid
]);
if (!empty($existing_posts)) {
    $post_id = $existing_posts[0]->ID;
    // Update existing
} else {
    $post_id = wp_insert_post($job_data);
    // Create new
}
```

---

## **ERROR HANDLING & RECOVERY POINTS**

- **AJAX Security Failures** → 403 error returned
- **Function Loading Failures** → Error message with missing function name
- **File System Errors** → Logged and processing continues with next item
- **JSON Parsing Errors** → Item skipped, processing continues
- **Memory Limit Exceeded** → Batch size reduced, processing continues
- **Time Limit Exceeded** → Import paused, can resume later
- **Database Connection Errors** → Exception thrown, import fails
- **Post Creation Failures** → Logged, processing continues with next item

---

## **PERFORMANCE OPTIMIZATIONS**

- **Batch Processing** prevents memory exhaustion
- **GUID Caching** reduces database queries
- **Change Detection** skips unchanged batches
- **Plugin Disabling** during processing
- **Memory Limit Increases** during import
- **Output Buffering** for real-time UI updates
- **Transient Caching** for status polling

---

## **DEBUG CONFIGURATION & MODES**

### Debug Levels
- **Level 0**: Production - Minimal logging
- **Level 1**: Basic - Standard flow logging  
- **Level 2**: Detailed - Query-level logging
- **Level 3**: Verbose - Memory/CPU profiling

### Configuration Options
```php
define('PUNTWORK_DEBUG_LEVEL', 2); // Set via wp-config.php
define('PUNTWORK_PERF_MONITORING', true);
define('PUNTWORK_QUERY_LOGGING', true);
define('PUNTWORK_MEMORY_PROFILING', true);
```

### Debug Output Destinations
- File: `/wp-content/debug.log`
- Database: `wp_puntwork_debug_logs` table
- External: Integration with monitoring services

---

## **ENHANCED PERFORMANCE METRICS**

### Additional Performance Metrics to Track:
- **Database Query Times**: Log individual query execution times
- **Memory Peaks**: Track memory usage at each major step
- **CPU Usage**: Monitor CPU utilization during processing
- **Disk I/O**: Track file read/write operations
- **Network Latency**: Log feed download response times
- **Batch Processing Times**: Individual batch duration tracking

---

## **ERROR CLASSIFICATION & AUTOMATED RECOVERY**

### Error Types
- **Critical**: Database connection failures, file system errors
- **Recoverable**: Memory limits, timeouts, network failures  
- **Ignorable**: Individual item parsing errors

### Automated Recovery Actions
- **Memory Exceeded**: Reduce batch size by 50%, retry
- **Timeout**: Save progress, schedule continuation
- **Network Failure**: Retry with exponential backoff
- **Database Error**: Log, skip batch, continue

### Error Reporting
- Email alerts for critical errors
- Dashboard notifications for recoverable errors
- Automatic retry mechanisms with limits

---

## **DATABASE OPTIMIZATION**

### Indexing Strategy
```sql
-- Ensure these indexes exist
CREATE INDEX idx_guid ON wp_posts(meta_value(50)) WHERE meta_key = '_guid';
CREATE INDEX idx_last_updated ON wp_postmeta(meta_value(20)) WHERE meta_key = '_last_updated';
CREATE INDEX idx_source ON wp_postmeta(meta_value(50)) WHERE meta_key = '_source';
```

### Query Optimization
- Use `WP_Query` with proper caching
- Implement prepared statements for bulk operations
- Add database connection pooling
- Consider read replicas for status polling

---

## **ADVANCED CACHING STRATEGIES**

### Multi-Level Caching
1. **Memory Cache**: APCu/Redis for GUID lookups
2. **Database Cache**: WordPress transients for batch data
3. **File Cache**: JSONL chunks for large feeds
4. **CDN Cache**: For static feed assets

### Cache Invalidation
- Time-based expiration (1 hour for feeds)
- Change-based invalidation (hash comparison)
- Manual flush capabilities

---

## **PARALLEL PROCESSING OPTIMIZATION**

### Potential Parallelization Points
- **Feed Downloads**: Download multiple feeds simultaneously
- **Batch Processing**: Process independent batches in parallel workers
- **Image Processing**: Handle job images concurrently
- **Social Media Posting**: Post to multiple platforms in parallel

### Implementation Options
- WordPress cron with multiple workers
- Background processing with Action Scheduler
- External queue systems (Redis Queue, Beanstalkd)

---

## **REAL-TIME MONITORING & ALERTING**

### Dashboard Metrics
- Current import progress (% complete)
- Processing speed (items/second)
- Memory usage trends
- Error rate over time
- Queue depth for pending operations

### Alert Conditions
- Import stalled (>5 minutes no progress)
- Memory usage >90%
- Error rate >5%
- Processing speed <10 items/minute

---

## **LOG ANALYSIS & DEBUGGING TOOLS**

### Automated Log Analysis
```bash
# Extract performance bottlenecks
grep "PERF-MONITOR" debug.log | awk '{print $3, $5}' | sort -k2 -nr | head -10

# Find error patterns
grep "ERROR\|FAILED" debug.log | cut -d' ' -f3- | sort | uniq -c | sort -nr

# Memory usage trends
grep "MEMORY" debug.log | grep -o "[0-9]*MB" | sed 's/MB//' | awk '{sum+=$1; count++} END {print "Average:", sum/count, "MB"}'
```

### Debug Scripts
- `debug-import-performance.php`: Performance analysis tool
- `debug-database-queries.php`: Query profiling script
- `debug-memory-usage.php`: Memory leak detection

---

## **COMPREHENSIVE TESTING FRAMEWORK**

### Unit Tests (PHPUnit)
- Individual function testing
- Mock external dependencies
- Performance regression tests

### Integration Tests
- End-to-end import simulation
- Database state validation
- API endpoint testing

### Load Testing
- Simulate high-volume imports
- Memory leak testing
- Concurrent user testing

### Chaos Engineering Tests
- Random network failures
- Database connection drops
- Memory limit simulations

---

## **CONFIGURATION TUNING**

### Batch Size Optimization
```php
// Dynamic batch sizing based on server resources
$batch_size = calculate_optimal_batch_size(
    memory_limit: ini_get('memory_limit'),
    time_limit: ini_get('max_execution_time'),
    server_load: sys_getloadavg()[0]
);
```

### Server Resource Tuning
- PHP memory_limit: 512M-1G
- max_execution_time: 600-1800 seconds
- MySQL max_connections: 100+
- WordPress max_memory_limit: 256M

---

## **TESTING VALIDATION**

Run these test files to validate components:
- `test_import_file.php` - File operations
- `test_import_debug.php` - Import flow
- `test_performance_optimizations.php` - Performance
- `test-async-import.php` - Async processing
- `test_single_job.php` - Individual job processing

### Debug and Analysis Tools
- `debug-import-performance.php` - Performance analysis tool (admin interface)
- `debug-database-queries.php` - Database query profiling (admin interface)
- `debug-memory-usage.php` - Memory usage monitoring and leak detection (admin interface)
- `analyze-import-logs.sh` - Automated log analysis script (run with `./analyze-import-logs.sh`)

This chronological flow shows every code component that must execute successfully from dashboard load through 100% import completion.