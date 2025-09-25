# PuntWork Features

## Core Import Functionality
- **XML Feed Processing**: Parses XML job feeds and converts to structured JSONL format with gzip compression for efficient storage
- **Batch Processing**: Handles large-scale imports with dynamic batch sizing based on memory usage and processing time
- **Duplicate Detection**: Identifies and handles duplicate job posts using GUID-based matching and content hashing
- **Data Mapping**: Transforms feed data to WordPress custom fields with geographic, salary, and icon mappings

## Scheduling & Automation
- **Cron Scheduling**: Automated imports with flexible intervals (hourly to 24-hour cycles)
- **Manual Triggers**: On-demand import execution with real-time progress monitoring
- **Test Mode**: Safe testing of import processes without affecting live data
- **History Tracking**: Comprehensive logging of all import runs with success/failure metrics

## Admin Interface
- **Dashboard**: Centralized management interface for feeds, jobs, and import status
- **Real-time Monitoring**: Live progress updates during imports with detailed metrics
- **Feed Management**: Admin UI for configuring and managing job feed sources
- **Import Controls**: Start, pause, resume, and cancel import operations

## Data Processing Features
- **Schema Generation**: Automatic JSON-LD structured data creation for SEO
- **Language Inference**: Intelligent detection of job posting languages
- **Benefit Extraction**: Parsing of job benefits (remote work, meal vouchers, etc.)
- **Geographic Mapping**: Province and location normalization for Belgian job market

## Performance & Reliability
- **Memory Management**: Adaptive batch sizing to prevent memory exhaustion
- **Error Recovery**: Robust error handling with detailed logging and recovery mechanisms
- **Caching**: Transient-based caching for feed data and mappings
- **Async Processing**: Background processing for long-running imports

## Integration Features
- **WordPress CPT**: Seamless integration with custom post types for jobs and feeds
- **ACF Compatibility**: Full support for Advanced Custom Fields data structures
- **AJAX API**: RESTful API endpoints for programmatic access
- **Logging System**: Structured logging with configurable levels

## Utility Functions
- **Data Cleaning**: Automated cleaning and normalization of job data
- **Item Inference**: Smart inference of missing job attributes
- **Gzip Handling**: Efficient compression/decompression of data files
- **Shortcode Support**: Frontend display shortcodes for job listings