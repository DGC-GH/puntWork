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

### Fixed
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

### Performance
- Optimized load balancer initialization by caching table existence checks
- Reduced logging frequency for routine instance updates
- Drag-and-drop functionality in feed configuration by replacing SortableJS with jQuery UI Sortable
- Fatal error in CRM admin class instantiation preventing plugin initialization
- PHPCS error: Renamed Puntwork_CRM_Admin class to PuntworkCrmAdmin for PascalCase compliance
- Reverted PuntworkCrmAdmin class back to Puntwork_CRM_Admin to resolve server-side class not found error
- Restored Puntwork_CRM_Admin class to PuntworkCrmAdmin following project PascalCase naming conventions
- Reverted PuntworkCrmAdmin class back to Puntwork_CRM_Admin to resolve persistent server-side class not found error
- Reverted PuntworkCrmAdmin class back to Puntwork_CRM_Admin to resolve persistent server-side class not found error
- Line length violations in crm-admin.php for PSR-12 compliance
- Fixed CRM integration loading order to resolve class dependency issues
- Renamed Puntwork_CRM_Admin class back to PuntworkCrmAdmin for final PascalCase compliance
- Optimized batch import performance with exponential batch size growth (1.2x multiplier)
- Fixed progress UI stuck in feed-processing phase by adding phase transition logic for large totals
- Reduced logging frequency in batch processing to every 100 items to improve performance
- Ensured all code changes including PHPCBF formatting fixes are committed in single amended commit

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