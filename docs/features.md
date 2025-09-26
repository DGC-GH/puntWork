# PuntWork Features

## Core Import Functionality
- **XML Feed Processing**: Parses XML job feeds and converts to structured JSONL format with gzip compression for efficient storage (process_xml_batch, download_feed, combine_jsonl.php)
- **Batch Processing**: Handles large-scale imports with dynamic batch sizing based on memory usage and processing time (process_batch_items_logic, validate_and_adjust_batch_size, load_and_prepare_batch_items)
- **Duplicate Detection**: Identifies and handles duplicate job posts using GUID-based matching and content hashing (handle_duplicates)
- **Data Mapping**: Transforms feed data to WordPress custom fields with geographic, salary, and icon mappings (mappings-*.php files)

## Scheduling & Automation
- **Cron Scheduling**: Automated imports with flexible intervals (hourly to 24-hour cycles) (register_custom_cron_schedules, scheduling-core.php)
- **Manual Triggers**: On-demand import execution with real-time progress monitoring (ajax-import-control.php)
- **Test Mode**: Safe testing of import processes without affecting live data (test-scheduling.php)
- **History Tracking**: Comprehensive logging of all import runs with success/failure metrics (scheduling-history.php)

## Admin Interface
- **Dashboard**: Centralized management interface for feeds, jobs, and import status (admin-*.php files)
- **Real-time Monitoring**: Live progress updates during imports with detailed metrics (ajax-feed-processing.php)
- **Feed Management**: Admin UI for configuring and managing job feed sources (admin-ui-main.php)
- **Import Controls**: Start, pause, resume, and cancel import operations (ajax-import-control.php)

## Data Processing Features
- **Schema Generation**: Automatic JSON-LD structured data creation for SEO (build_job_schema)
- **Language Inference**: Intelligent detection of job posting languages (item-inference.php)
- **Benefit Extraction**: Parsing of job benefits (remote work, meal vouchers, etc.) (item-inference.php)
- **Geographic Mapping**: Province and location normalization for Belgian job market (mappings-geographic.php)

## Performance & Reliability
- **Memory Management**: Adaptive batch sizing to prevent memory exhaustion (validate_and_adjust_batch_size)
- **Error Recovery**: Robust error handling with detailed logging and recovery mechanisms (puntwork-logger.php)
- **Caching**: Transient-based caching for feed data and mappings (get_feeds with transients)
- **Async Processing**: Background processing for long-running imports (heartbeat-control.php)

## Integration Features
- **WordPress CPT**: Seamless integration with custom post types for jobs and feeds (core-structure-logic.php)
- **ACF Compatibility**: Full support for Advanced Custom Fields data structures (get_acf_fields)
- **AJAX API**: RESTful API endpoints for programmatic access (ajax-*.php files)
- **REST API**: Remote import triggering and status monitoring via HTTP (rest-api.php)
- **Logging System**: Structured logging with configurable levels (puntwork-logger.php)

## Utility Functions
- **Data Cleaning**: Automated cleaning and normalization of job data (item-cleaning.php)
- **Item Inference**: Smart inference of missing job attributes (item-inference.php)
- **Gzip Handling**: Efficient compression/decompression of data files (gzip-file.php)
- **Shortcode Support**: Frontend display shortcodes for job listings (shortcode.php)