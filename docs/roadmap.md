# PuntWork Development Roadmap

## Phase 1: Code Quality & Testing (Priority: High)
- [x] **CRITICAL BUG**: Fix API import progress tracking - API calls show "0 / X items" instead of actual progress while manual/scheduled imports work correctly
  - **Root Cause**: Status preservation logic conflict between API and batch processing
  - **Impact**: API imports appear broken to external systems
  - **Status**: Code fix implemented, awaiting deployment
- [x] Set up PHPUnit testing framework with composer.json
- [x] Add comprehensive unit tests for all core functions (mappings, batch processing, scheduling)
- [x] Implement integration tests for import workflows
- [x] Add code coverage reporting
- [x] Set up GitHub Actions CI/CD pipeline
- [x] Fix duplicate function declarations causing fatal errors

## Phase 2: Performance Optimization (Priority: High)
- [ ] Implement Redis/object caching for feed data and mappings
- [ ] Optimize database queries with proper indexing
- [ ] Add async processing for large imports using Action Scheduler
- [ ] Implement progressive loading for admin UI
- [ ] Add performance benchmarks and monitoring
- [ ] Refactor long functions (e.g., process_batch_items_logic) into smaller units
- [ ] Optimize memory usage in batch processing (avoid loading large arrays)

## Phase 3: Security & Reliability (Priority: Medium)
- [ ] Add input validation and sanitization for all AJAX endpoints
- [ ] Implement rate limiting for API calls
- [ ] Add CSRF protection beyond nonces
- [ ] Implement proper error handling and logging levels
- [ ] Add data backup/restore functionality
- [ ] Add type hints and strict typing throughout codebase

## Phase 4: Feature Enhancements (Priority: Medium)
- [ ] Add real-time import progress via WebSockets
- [ ] Implement feed health monitoring and alerts
- [ ] Add import analytics and reporting dashboard
- [ ] Support for additional feed formats (JSON, CSV)
- [ ] Add job deduplication algorithms

## Phase 5: Developer Experience (Priority: Low)
- [ ] Refactor long functions into smaller, testable units
- [ ] Add PHP type hints and strict typing
- [ ] Implement PSR-4 autoloading
- [ ] Add API documentation with OpenAPI spec
- [ ] Create development Docker environment

## Completed Tasks
- [x] Initial plugin architecture and core functionality
- [x] Basic batch processing with dynamic sizing
- [x] Admin UI with scheduling and monitoring
- [x] XML feed processing and job import
- [x] Duplicate handling and data mapping
- [x] Set up PHPUnit testing framework with composer.json
- [x] Add comprehensive unit tests for core functions
- [x] Refactor long functions into smaller units
- [x] Add PHP type hints for better code quality
- [x] Implement caching for mapping arrays
- [x] Reduce excessive logging with WP_DEBUG checks
- [x] Add input sanitization to AJAX endpoints
- [x] Set up GitHub Actions CI/CD pipeline
- [x] Implement REST API for remote import triggering
- [x] Fix duplicate function declarations

## Current Status
- **Last Updated**: September 26, 2025
- **Version**: 1.0.8
- **Next Priority**: Performance optimization and code refactoring (Phase 2)
- **Blockers**: Auto-deployment system not working - code fixes committed but not deployed
- **Critical Issues**: Resolved duplicate function declarations; API progress tracking bug needs verification
- **Infrastructure Issues**: Git push auto-deployment to WordPress plugin folder not functioning