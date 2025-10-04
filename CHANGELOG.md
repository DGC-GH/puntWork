# puntWork Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Pre-commit git hooks for automated PHPCS and PHPUnit validation to prevent regressions
- Validation commands added to prompt.md for consistent development workflow
- GitHub Actions CI/CD pipeline with automated testing, code coverage, and deployment preparation
- Protocol update: Always check debug.log for new errors before running tests
- Enhanced analyze-import-logs.sh script with AI-driven analysis patterns and metrics including AJAX success rates, feed processing efficiency, and AI comprehension metrics
- Comprehensive debug logging in AJAX handlers with memory usage tracking and detailed error context
- **Feed Caching System**: Implemented transient-based caching for downloaded feeds with 1-hour freshness check and 6-hour expiration to reduce redundant downloads and improve performance
- **Conditional Plugin Loading**: Optimized plugin initialization by loading includes only when needed (admin pages, AJAX requests, cron jobs, etc.) reducing from 101 includes per request to 8-50 context-specific includes
- **Enhanced Error Handling and Recovery System**: Implemented comprehensive error recovery framework with automatic retry logic, hierarchical exception system, and system health monitoring. Added ErrorHandler class with recovery strategies for database, network, processing, and validation errors. Enhanced import functions with executeWithRecovery() calls and WordPress compatibility checks.

### Fixed
- Fixed 400 error on /jobs REST API endpoint by adding 'any' to the status parameter enum validation
- **Progress Bar Visibility Issue**: Fixed progress bar not being visible during import by changing initial container width from 0% to 100% and ensuring progress segments use flexbox properly with `width: auto !important`
- **Database Index Creation SQL Syntax Error**: Fixed CREATE INDEX queries with WHERE clauses that are not supported in older MariaDB/MySQL versions by removing WHERE conditions and adding table existence check for performance logs table
- **Undefined Function get_or_create_api_key**: Fixed fatal error by adding proper namespace prefix \Puntwork\ and including rest-api.php in import-setup.php
- **Critical AJAX 500 Error Resolution**: Fixed "Class 'Puntwork\FeedProcessor' not found" error by adding explicit include_once for feed-processor.php in process_feed_ajax handler, preventing fatal errors during feed processing in WordPress AJAX context
- **Social Media Class Loading Dependency**: Fixed "Class 'Puntwork\SocialMedia\SocialMediaManager' not found" error by reordering conditional includes in puntwork.php to load social media platform classes before admin classes, and made admin class instantiations conditional to prevent loading during non-admin requests
- **Restored Missing Conditional Include Sections**: Added back API/AJAX, Batch/Import, and Queue include sections that were accidentally removed during the dependency fix, ensuring all plugin functionality remains available
- **Critical AJAX 500 Error Resolution**: Fixed "Class 'Puntwork\Import\...' not found" errors by adding explicit require_once for ImportAnalytics.php in ajax-import-control.php, preventing class loading failures in WordPress AJAX context
- **Composer Autoload Optimization**: Regenerated autoload files to resolve PSR-4 namespace mapping issues and eliminate class loading warnings
- **AJAX 500 Error Fix**: Removed large logs array from AJAX responses in process_feed and combine_jsonl handlers to prevent JSON encoding failures and memory exhaustion, resolving client-side 500 errors during feed processing
- Added debug logs to SSE endpoint to track data serialization and detect "undefined" responses causing JSON parse errors
- Enhanced import locking mechanism with detailed debug logs to diagnose and prevent "Import already running" false positives
- Changed default feed format detection from XML to JSON to properly handle modern API feeds
- Fixed job board configuration issues for API-based feeds
- Fixed undefined function load_and_prepare_batch_items by adding namespace prefix \Puntwork\
- Optimized instances table creation check with transient caching to eliminate repetitive database queries and logging
- Improved accessibility error handling for non-array menu parameters
- Added missing disable_expensive_plugins() and enable_expensive_plugins() functions to prevent fatal errors during batch processing
- Added missing CacheManager.php include to resolve "Class not found" error for EnhancedCacheManager
- Fixed namespace declaration in JsonlIterator class (batch-loading.php) for PSR-12 compliance
- Reduced verbose logging in instance registration to only log errors and new registrations
- Added nonce verification to AJAX import control handlers for improved security
- Fixed excessive logging in DatabasePerformanceMonitor by making per-query logging conditional on PUNTWORK_DB_DEBUG constant
- Fixed undefined function bulk_get_post_statuses by adding proper function declaration and debug logs
- **Memory Exhaustion Fix**: Increased PHP memory limit from 512MB to 1024MB and added comprehensive memory usage logging to prevent import failures with large datasets (7476+ items)
- **SSE JSON Parse Error Fix**: Added sanitization functions to remove "undefined" values from import status data before JSON serialization, preventing JavaScript parse errors
- **Concurrent Import Prevention**: Implemented transient-based locking mechanism to prevent multiple simultaneous imports that cause "Import already running" errors
- **Status Data Sanitization**: Added sanitize_import_status() function to clean AJAX responses and prevent corrupted status data from breaking real-time updates
- **Enhanced Error Messages**: Replaced generic "Unknown error" messages with specific, detailed error descriptions in feed processing, download, and combination operations for better troubleshooting
- **Code Quality Improvements**: Fixed PHPCS violations including indentation, spacing, inline comments, and WordPress coding standards compliance
- **Import Lock Conflict Resolution**: Fixed import process stalling at processed:0 due to lock conflict between batch import functions
- **Inter-Process Communication Fix**: Fixed broken pipe communication in fork-based timeout protection causing "Failed to open stream" and "Failed to get result from child process" errors

### Performance
- Implemented parallel feed downloading using Symfony HTTP Client to reduce total import time for multiple feeds
- Added HTTP caching support with ETag and Last-Modified headers to skip unchanged feeds
- Optimized feed processing with concurrent downloads (up to 5 simultaneous connections)
- Added feed cache cleanup to prevent disk space issues
- Reduced memory usage by processing downloaded feeds without redundant format detection
- Optimized load balancer initialization by caching table existence checks
- Reduced logging frequency for routine instance updates
- Drag-and-drop functionality in feed configuration by replacing SortableJS with jQuery UI Sortable
- Fatal error in CRM admin class instantiation preventing plugin initialization
- **Database Index Optimization**: Added 9 performance indexes on wp_postmeta and wp_posts tables for critical queries (GUID lookups, import hashes, job status, feed URLs, etc.)
- **Plugin Loading Optimization**: Reduced plugin initialization time by conditionally loading includes based on request context (frontend: 8 includes, admin: 25-30 includes, AJAX: 40-50 includes)
- **Critical AJAX 500 Error Resolution**: Fixed "Class 'Puntwork\FeedProcessor' not found" error by adding explicit include_once for feed-processor.php in process_feed_ajax handler, preventing fatal errors during feed processing in WordPress AJAX context
- **Critical AJAX 500 Error Resolution**: Fixed "Class 'Puntwork\Import\...' not found" errors by adding explicit require_once for ImportAnalytics.php in ajax-import-control.php, preventing class loading failures in WordPress AJAX context
- **Composer Autoload Optimization**: Regenerated autoload files to resolve PSR-4 namespace mapping issues and eliminate class loading warnings
- **AJAX 500 Error Fix**: Removed large logs array from AJAX responses in process_feed and combine_jsonl handlers to prevent JSON encoding failures and memory exhaustion, resolving client-side 500 errors during feed processing
- Added debug logs to SSE endpoint to track data serialization and detect "undefined" responses causing JSON parse errors
- Enhanced import locking mechanism with detailed debug logs to diagnose and prevent "Import already running" false positives
- Changed default feed format detection from XML to JSON to properly handle modern API feeds
- Fixed job board configuration issues for API-based feeds
- Fixed undefined function load_and_prepare_batch_items by adding namespace prefix \Puntwork\
- Optimized instances table creation check with transient caching to eliminate repetitive database queries and logging
- Improved accessibility error handling for non-array menu parameters
- Added missing disable_expensive_plugins() and enable_expensive_plugins() functions to prevent fatal errors during batch processing
- Added missing CacheManager.php include to resolve "Class not found" error for EnhancedCacheManager
- Fixed namespace declaration in JsonlIterator class (batch-loading.php) for PSR-12 compliance
- Reduced verbose logging in instance registration to only log errors and new registrations
- Added nonce verification to AJAX import control handlers for improved security
- Fixed excessive logging in DatabasePerformanceMonitor by making per-query logging conditional on PUNTWORK_DB_DEBUG constant
- Fixed undefined function bulk_get_post_statuses by adding proper function declaration and debug logs
- **Memory Exhaustion Fix**: Increased PHP memory limit from 512MB to 1024MB and added comprehensive memory usage logging to prevent import failures with large datasets (7476+ items)
- **SSE JSON Parse Error Fix**: Added sanitization functions to remove "undefined" values from import status data before JSON serialization, preventing JavaScript parse errors
- **Concurrent Import Prevention**: Implemented transient-based locking mechanism to prevent multiple simultaneous imports that cause "Import already running" errors
- **Status Data Sanitization**: Added sanitize_import_status() function to clean AJAX responses and prevent corrupted status data from breaking real-time updates

## [0.0.5] - 2025-10-02

### Fixed
- **CSS Cache Refresh**: Updated version to 0.0.5 to force browsers to reload updated admin-modern.css with new modern UI styles
- **Plugin Header Version**: Updated plugin header comment version to match constant

## [0.0.4] - 2025-09-26

### Added
- Real-time import progress tracking with Server-Sent Events
- Import analytics dashboard with interactive charts and CSV export
- Automated feed health monitoring with email alerts
- Support for JSON and CSV feed formats with auto-detection
- Advanced job deduplication using fuzzy matching algorithms
- Security enhancements including rate limiting, CSRF protection, and input validation
- PSR-4 autoloading and OpenAPI 3.0 API documentation
- REST API endpoints for real-time progress and enhanced status
- Docker development environment
- Comprehensive PHPUnit test suite with CI/CD integration
- AI-powered content quality scoring and intelligent job categorization
- GraphQL API for flexible data queries and webhook system for real-time notifications
- Machine learning engine with predictive analytics and automated feed optimization
- Multi-site support for network-wide job distribution across WordPress multisite networks
- Advanced reporting engine with automated scheduling, custom dashboards, and multi-format exports
- CRM integrations with HubSpot, Salesforce, Zoho CRM, and Pipedrive including automated workflows
- Horizontal scaling and load balancing for distributed processing across server instances
- Social media integration for automated posting to X/Twitter, Facebook, and TikTok
- Mobile app companion for remote management
- Progressive web app features for mobile access
- Multi-language support (i18n) for international users
- Interactive onboarding wizard for new installations
- Enhanced accessibility with keyboard shortcuts
- Security headers, Content Security Policy, and CORS support
- Redis caching for improved performance
- Async processing for large imports using Action Scheduler
- Progressive loading for admin UI
- Performance benchmarks and monitoring

### Changed
- Modernized architecture with namespaces, dependency injection, and object-oriented design
- Enhanced user interface with real-time updates, responsive design, and improved error messages
- Improved API design with RESTful patterns, better error handling, and comprehensive documentation

### Fixed
- API progress tracking inconsistencies
- Memory leaks in large imports
- Database query optimization issues (N+1 queries)
- Async processing and background job handling
- Stuck import detection and automatic cleanup

### Security
- Comprehensive input validation and sanitization
- Rate limiting and CSRF protection
- Enhanced authentication, audit logging, and secure API key management

---

**Previous Versions:**
- [1.0.x](https://github.com/DGC-GH/puntWork/releases) - Basic job import functionality

---

*For detailed upgrade instructions, see [DEPLOYMENT.md](docs/DEPLOYMENT.md)*