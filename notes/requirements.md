# Job Import Plugin Requirements

## Functional Requirements

### Core Import Functionality
- [x] Download XML/JSON feeds from external sources (e.g., via wp_remote_get with gzip support). Integrated into processor.php.
- [x] Parse XML batches using SimpleXML and process into WordPress 'job' custom post type. Handled in job_process_xml_batch().
- [x] Support batch processing with configurable size (default 50) to handle large feeds efficiently. Uses array_chunk in processor.php.
- [x] Clean and sanitize imported items (titles, descriptions, etc.) to prevent XSS/SQL injection. Via job_import_clean_item() in helpers.php.
- [x] Infer missing data like categories based on keywords in title/description. Implemented in job_import_infer_item() in processor.php.
- [x] Detect and skip duplicates using MD5 hash of title + description, checking recent posts. In job_import_handle_duplicate() in processor.php.

### Scheduling and Automation
- [x] Schedule hourly cron jobs for automated imports via wp_schedule_event. Set up in scheduler.php and main job-import.php.
- [x] Trigger imports on events like post saves for 'job' type. Via save_post_job hook in scheduler.php.

### Admin Interface
- [x] Admin settings page with form for feed URL, batch size, and manual start button. In admin.php with options.php integration.
- [x] Real-time progress bar and status updates during imports using AJAX polling. Handled in ajax.php and admin.js.
- [x] Logging to /logs/ directory for debugging (info/error levels). Via job_import_log() in helpers.php.

### Utilities and Extras
- [x] Handle GZIP-compressed feeds. In job_import_handle_gzip() in helpers.php.
- [x] Combine multiple JSONL files if needed. In job_import_combine_jsonl() in helpers.php.
- [x] Heartbeat API integration for progress monitoring (merged from duplicates). In heartbeat.php.
- [x] Shortcode [job_import_status] for displaying job count and last run status. In shortcode.php.

### Post-Refactor Requirements
- [ ] Add unit tests for key functions (e.g., batch processing, duplicate detection) using WP-CLI or PHPUnit.
- [ ] Implement error recovery (e.g., resume failed batches).
- [ ] Support multiple feed sources via array in settings.
- [ ] Add export functionality for imported jobs.

## Non-Functional Requirements
- Compatibility: WordPress 5.0+, PHP 7.4+.
- Performance: Handle 1000+ items without timeout (batch + transients).
- Security: Use WP nonces in AJAX, sanitize all inputs.
- Accessibility: Admin UI follows WCAG basics (e.g., ARIA labels in JS).

## Field Mappings (Updated to Match constants.php)
- XML/JSON 'job_title' → WP post_title
- 'job_description' → post_content
- 'job_location' → meta 'job_location'
- 'job_salary' → meta 'job_salary'
- 'job_category' → meta 'job_category' (inferred if missing)
- Add more as per feed schema.
