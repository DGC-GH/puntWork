# Project Structure

## Overall Repo Tree

puntWork/
├── job-import/                  # Main WP plugin folder
│   ├── job-import.php           # Plugin entry: Headers, hooks, includes
│   ├── includes/                # Modular PHP files
│   │   ├── constants.php        # Mappings, URLs, batch sizes
│   │   ├── helpers.php          # Utilities: Sanitize, gzip, JSONL, logging
│   │   ├── processor.php        # Core: Download, parse XML, batch import, duplicates, inference
│   │   ├── scheduler.php        # Cron scheduling, event triggers
│   │   ├── heartbeat.php        # Progress monitoring via transients
│   │   ├── admin.php            # Admin menu, settings form, HTML
│   │   ├── enqueue.php          # Enqueue JS/CSS for admin
│   │   ├── ajax.php             # AJAX handlers for start/progress
│   │   └── shortcode.php        # Status shortcode
│   ├── assets/                  # Static files
│   │   ├── admin.js             # AJAX polling, button handlers
│   │   └── admin.css            # Progress bar, form styling
│   ├── logs/                    # Runtime logs (auto-created)
│   └── .DS_Store                # Ignore
├── notes/                       # Documentation (this folder)
└── ...                          # Other folders

## File Descriptions

### job-import.php
- Initializes plugin: Defines paths, requires includes, activation/deactivation hooks.
- Registers 'job' CPT, cron event, admin menu, shortcode, AJAX actions.

### includes/constants.php
- Global defines: FEED_URL, BATCH_SIZE (50), CHECK_INTERVAL (1hr).
- Arrays: $job_import_mappings (XML to WP fields), $job_import_categories (keyword inference).

### includes/helpers.php
- Reusable functions: sanitize_string(), format_date(), clean_item(), handle_gzip(), combine_jsonl(), log().

### includes/processor.php
- Download feed, process XML batch, import batch (with cleaning/inference/dupe check), infer_item(), handle_duplicate().

### includes/scheduler.php
- Hooks for cron setup, triggers on post save.

### includes/heartbeat.php
- Heartbeat tick for progress, update_progress() using transients.

### includes/admin.php
- Add menu page, render HTML form, register settings.

### includes/enqueue.php
- Enqueue admin JS/CSS on plugin page, localize AJAX vars.

### includes/ajax.php
- Handlers: start import (schedule event), get progress (transient).

### includes/shortcode.php
- [job_import_status] outputs job count, last run.

## Migration Notes
- All /snippets code refactored into /includes; no losses.
- Removed redundancy (e.g., merged two heartbeat files).
- Next: Add tests/ folder for PHPUnit.
