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
- [ ] Manual import trigger from admin dashboard (pending UI integration).

### UI/UX
- [ ] Admin settings page for feed URLs, batch config, logs. (Wireframed in wireframes.md)
- [ ] Import status dashboard with progress bars, error logs.

## Non-Functional Requirements (Added Sept 16, 2025)
- [ ] Performance: Handle 1000+ jobs/hour without timeouts (test with load simulations).
- [ ] Security: Full audit for WP nonce usage in admin actions; GDPR-compliant data handling.
- [ ] Compatibility: WP 6.5+, PHP 8.1+; no conflicts with major themes/plugins.
- [ ] Scalability: Support multi-site installs; optional Redis caching for large feeds.

## Testing Requirements
- [x] Unit tests for parsing/cleaning (PHPUnit in tests/ folder).
- [ ] Integration tests for cron + imports.
- [ ] E2E: Simulate full import cycle.

Vision Note: Keep lightweight (<50KB gzipped). For future Grok: Prioritize non-functional in next sprint.
Last Updated: Sept 16, 2025 (Grok Review)
