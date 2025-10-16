# puntWork Copilot Instructions

## Architecture Overview
puntWork is a WordPress plugin that imports jobs from XML feeds using a custom post type 'job-feed' for feed URLs. It processes feeds in parallel, converts XML to JSONL format, and handles batch processing for large-scale imports. Key architectural decisions include modular organization under `includes/` and parallel feed downloads for performance.

Custom post types are already created using ACF Pro:
- **Job Feed Post Type** (`job-feed`): Stores feed URLs and metadata
- **Job Post Type** (`job`): Stores imported job listings

## Key Components
- **Admin** (`includes/admin/`): Dashboard pages, menu setup, UI components
- **API** (`includes/api/`): AJAX handlers for feed processing, import control, and data purging
- **Batch** (`includes/batch/`): Core batch logic, data management, and processing utilities
- **Import** (`includes/import/`): Feed downloading, XML/JSONL processing, and import finalization
- **Mappings** (`includes/mappings/`): Field mappings, geographic data, salary processing, and schema definitions
- **Scheduling** (`includes/scheduling/`): Cron job management and scheduling triggers
- **Utilities** (`includes/utilities/`): Logger, retry mechanisms, file handling, and helper functions

## Data Flow
1. Retrieve feed URLs from 'job-feed' custom posts or options (`core-structure-logic.php::get_feeds()`)
2. Download feeds in parallel using multi-curl (`core-structure-logic.php::download_feeds_in_parallel()`)
3. Process XML to JSONL batches (`import/process-xml-batch.php`)
4. Combine JSONL files (`import/combine-jsonl.php`)
5. Batch process items for import (`batch/batch-processing.php`)
6. Finalize and clean up (`import/import-finalization.php`)

## Development Workflow
- **Testing**: Run `./run-tests.sh` to set up WordPress test environment and execute PHPUnit tests
- **Dependencies**: Use Composer for PHP dependencies; run `composer require --dev` for test packages
- **Debugging**: Check `wp-content/debug.log` on the server via SFTP extension for detailed logs; use `PuntWorkLogger` class for structured logging
- **Cron Scheduling**: Custom intervals defined in `puntwork.php`; auto-cron disabled, use manual triggers

## Project Conventions
- **Namespace**: All code under `Puntwork` namespace
- **File Naming**: Prefix files by category (e.g., `ajax-handlers.php`, `admin-menu.php`)
- **Hooks**: Standard WordPress hooks; activation/deactivation in main file
- **Constants**: Use `PUNTWORK_*` constants for paths, version, logs
- **Error Handling**: Try/catch blocks with logging; libxml errors suppressed for XML processing
- **AJAX**: All admin interactions via AJAX handlers in `api/` directory
- **Batch Processing**: Handle large datasets with configurable batch sizes (default 100)

## Code Examples
- **Adding new mapping**: Extend `mappings/mappings-fields.php` with new field definitions
- **Custom scheduling**: Add intervals in `puntwork.php::register_custom_cron_schedules()`
- **AJAX endpoint**: Create handler in `api/ajax-*.php` and enqueue in `core/enqueue-scripts-js.php`
- **Logging**: Use `PuntWorkLogger::info/error()` with context constants like `CONTEXT_FEED`

## Integration Points
- WordPress core APIs (WP_Query, post_meta, transients)
- ACF plugin for custom fields (fallback to post_meta)
- External XML/JSONL feeds with gzip compression
- Database storage via WordPress options and custom posts

## API Endpoints (discovered)
This plugin exposes a mix of WP REST routes and admin-ajax actions. Use these for diagnostics and automation. Be careful with destructive actions — most admin-ajax endpoints require authentication.

REST routes:
- GET /wp-json/puntwork/v1/diagnostics — plugin diagnostics snapshot. If the `puntwork_rest_token` option is set, send it in the `X-PUNTWORK-TOKEN` header; otherwise the route requires an authenticated user with `manage_options`.

Common admin-ajax actions (admin-ajax.php?action=...):
- get_job_import_status — Read-only: returns current import progress and status.
- puntwork_import_diagnostics — Diagnostics fetch used by admin UI (may be blocked by host WAF for POST requests; the diagnostics page now uses GET to improve reliability).
- run_job_import_batch — Triggers an import batch run (destructive/long-running; authenticated only).
- continue_paused_import_ajax — Attempt to continue a paused import (authenticated only).
- manually_resume_stuck_import — Admin-initiated resume for stuck imports.
- cancel_job_import — Cancel an active import run.
- clear_import_cancel — Clear a previously-set cancel flag.
- reset_job_import — Reset import state (destructive; authenticated only).
- get_active_scheduled_imports — Lists scheduled WP-Cron / Action Scheduler tasks related to imports.
- puntwork_save_rest_token — Save the REST diagnostics token from the admin UI (authenticated).
- puntwork_get_rest_token — Retrieve the saved REST diagnostics token for the admin UI (authenticated).

Notes & troubleshooting:
- On some shared hosts (observed Hostinger), POSTs to `admin-ajax.php` may be blocked or have body/nonces stripped by host WAFs. If admin-ajax POSTs fail with HTTP 400 and body "0", use the REST diagnostics endpoint with the saved token as a reliable alternative.
- For programmatic automation, the REST diagnostics endpoint is recommended. Consider adding a webhook or WP-CLI resume command if the host prevents loopback requests or scheduled events from firing reliably.