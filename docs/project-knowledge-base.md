# puntWork Project Knowledge Base

## Project Overview
**puntWork** is a WordPress plugin for seamless job i### Key Components (UPDATED)

#### Modular Architecture Overview
- **8## Development Standards (UPDATED)

### PHP Standards (Enhanced)
- **Namespace**: `Puntwork` applied throughout all modules
- **Functions**: CamelCase, private methods prefixed with `_`
- **Security**: `wp_verify_nonce()`, `sanitize_text_field()`, `esc_html()`
- **Error Handling**: Try/catch blocks with structured logging
- **Documentation**: PHPDoc blocks for all functions across modules
- **Modular Design**: Single responsibility per file, clear module boundaries

### JavaScript Standards (Enhanced)
- **Version**: ES6+ with WordPress compatibility
- **Framework**: Vue.js for admin components (future enhancement)
- **Modular Pattern**: IIFE pattern with global exports
- **Linting**: ESLint with WordPress standards
- **Async**: `async/await` for API calls
- **Logging**: Integrated `PuntWorkJSLogger` throughout all modules

### File Organization Standards (NEW)
- **Module Structure**: 8 focused modules with clear responsibilities
- **File Naming**: `module-descriptive-name.php` within each module
- **Asset Organization**: All JS files in `assets/` (moved from `assets/js/`)
- **Documentation**: Centralized in `docs/` (moved from `notes/`)
- **Testing**: Dedicated `tests/` directory for all test files

### General Standards (Enhanced)
- **Indentation**: 4 spaces (no tabs)
- **Line Length**: 100 characters maximum
- **Version Control**: Atomic commits, feature branches
- **Dependencies**: Minimal, prefer WordPress core functions
- **Cross-Module Communication**: Well-defined interfaces and shared utilitiesEach with single responsibility and clear boundaries
- **Cross-Module Communication**: Well-defined interfaces between modules
- **Scalable Structure**: Easy to add new features within appropriate modules
- **Maintainable Codebase**: Clear separation of concerns, easier debugging

#### Module Responsibilities
- **`includes/admin/`**: WordPress admin interface, dashboard, settings pages
- **`includes/api/`**: AJAX endpoints, data validation, API responses
- **`includes/batch/`**: Batch processing logic, memory management, progress tracking
- **`includes/core/`**: Plugin initialization, asset enqueuing, core setup
- **`includes/import/`**: Feed processing, XML/JSON parsing, data transformation
- **`includes/mappings/`**: Field mappings, data normalization, schema handling
- **`includes/scheduling/`**: Cron jobs, time calculations, scheduled imports
- **`includes/utilities/`**: Logging, file operations, helper functions

#### Feed URL System (Enhanced)
- **Storage**: CPT `feed_url` meta field (dynamic and flexible)
- **Retrieval**: `get_post_meta($post_id, 'feed_url', true)`
- **Processing**: `includes/import/download-feed.php` and `includes/core/core-structure-logic.php`
- **Fallback**: Domain fallback for robustness
- **Integration**: Used across `import/`, `batch/`, and `api/` modules

#### JavaScript Architecture (Modular)
- **Pattern**: IIFE (Immediately Invoked Function Expression)
- **Global Object**: `PuntWorkJobImportAdmin` combines all modules
- **Modules**: 7 focused JavaScript modules in `assets/`
  - `job-import-ui.js`: UI updates, progress display
  - `job-import-api.js`: AJAX communications
  - `job-import-logic.js`: Core processing logic
  - `job-import-events.js`: Event handling
  - `job-import-scheduling.js`: Scheduling interface
  - `job-import-admin.js`: Main coordinator
  - `puntwork-logger.js`: Logging utility
- **Dependencies**: Proper loading order maintained
- **Benefits**: Better caching, maintainability, debuggingent. It enables users to pull job listings from external sources (APIs, RSS feeds, CSV/XML files), customize fields, and integrate with WP themes for job boards. Target audience: Small businesses, recruiters, and career sites.

**Version:** 1.0-alpha
**License:** GPL v2
**WordPress Compatibility:** 6.0+
**PHP Version:** 8.0+

## Core Features

### Import Mechanisms
- **File Upload**: CSV, XML, JSON imports
- **API Integration**: REST APIs (Indeed, Google Jobs, LinkedIn)
- **RSS Feeds**: Dynamic feed URLs from CPT fields
- **Bulk Processing**: Up to 1000 jobs/batch with progress indicators

### Data Handling
- **Field Mapping**: Drag-and-drop UI to map external data to WP custom post types
- **Deduplication**: Based on job ID/URL using WP_Query
- **Custom Post Type**: `job_post` with taxonomies (category, type: full-time/part-time)
- **Data Cleaning**: Automatic field normalization and inference

### Admin Interface
- **Dashboard**: Import history, error logs, progress tracking
- **Settings**: API keys, cron schedules, feed URL configuration
- **Export**: Jobs export to CSV
- **Vue.js Integration**: Modern admin UI components

### Frontend Features
- **Shortcode**: `[puntwork_jobs]` for job listings
- **Templates**: Single job template with apply functionality
- **Filtering**: Location, category, type filters

## Technical Architecture

### PHP Structure (UPDATED - Post-Restructuring)
```
puntWork/
├── job-import.php (Main plugin file - updated include paths)
├── uninstall.php (Cleanup on uninstall)
├── includes/ (RESTRUCTURED - 8 focused modules, 47+ files total)
│   ├── admin/ (5 files) - Admin interface, menus, pages
│   │   ├── admin-menu.php
│   │   ├── admin-page-html.php
│   │   ├── admin-ui-debug.php
│   │   ├── admin-ui-main.php
│   │   └── admin-ui-scheduling.php
│   ├── api/ (4 files) - AJAX handlers, data validation
│   │   ├── ajax-feed-processing.php
│   │   ├── ajax-handlers.php
│   │   ├── ajax-import-control.php
│   │   └── ajax-purge.php
│   ├── batch/ (5 files) - Batch processing, memory management
│   │   ├── batch-core.php
│   │   ├── batch-data.php
│   │   ├── batch-processing.php
│   │   ├── batch-size-management.php
│   │   └── batch-utils.php
│   ├── core/ (2 files) - Core functionality, initialization
│   │   ├── core-structure-logic.php
│   │   └── enqueue-scripts-js.php
│   ├── import/ (8 files) - Import operations, feed processing
│   │   ├── combine-jsonl.php
│   │   ├── download-feed.php
│   │   ├── import-batch.php
│   │   ├── import-finalization.php
│   │   ├── import-setup.php
│   │   ├── process-batch-items.php
│   │   ├── process-xml-batch.php
│   │   └── reset-import.php
│   ├── mappings/ (6 files) - Data mappings, transformations
│   │   ├── mappings-constants.php
│   │   ├── mappings-fields.php
│   │   ├── mappings-geographic.php
│   │   ├── mappings-icons.php
│   │   ├── mappings-salary.php
│   │   │   └── mappings-schema.php
│   ├── scheduling/ (4 files) - Cron jobs, time calculations
│   │   ├── scheduling-ajax.php
│   │   ├── scheduling-core.php
│   │   ├── scheduling-history.php
│   │   └── scheduling-triggers.php
│   └── utilities/ (9 files) - Helper functions, logging
│       ├── gzip-file.php
│       ├── handle-duplicates.php
│       ├── heartbeat-control.php
│       ├── item-cleaning.php
│       ├── item-inference.php
│       ├── puntwork-logger.php
│       ├── shortcode.php
│       ├── test-scheduling.php
│       └── utility-helpers.php
├── assets/ (MOVED from assets/js/ - 7 JS files)
│   ├── job-import-admin.js
│   ├── job-import-api.js
│   ├── job-import-events.js
│   ├── job-import-logic.js
│   ├── job-import-scheduling.js
│   ├── job-import-ui.js
│   └── puntwork-logger.js
├── docs/ (MOVED from notes/ - 6 documentation files)
└── tests/ (2 test files)
    ├── ImportTest.php
    └── time-tracking-test.js
```

### Key Components

#### Feed URL System
- **Storage**: CPT `feed_url` meta field
- **Retrieval**: `get_post_meta($post_id, 'feed_url', true)`
- **Processing**: `core-structure-logic.php::get_feeds()`
- **Fallback**: Domain fallback for robustness

#### JavaScript Architecture
- **Pattern**: IIFE (Immediately Invoked Function Expression)
- **Global Object**: `PuntWorkJobImportAdmin`
- **Modules**:
  - `job-import-ui.js`: UI updates and progress
  - `job-import-api.js`: AJAX communications
  - `job-import-logic.js`: Core processing logic
  - `job-import-events.js`: Event handling
- **Dependencies**: Proper loading order maintained

#### Database Schema (Enhanced)
- **Custom Post Type**: `job_post` with comprehensive taxonomies
- **Taxonomies**: `job_category`, `job_type` (full-time/part-time/contract)
- **Meta Fields**: `feed_url`, `job_id`, `salary_range`, `location`, etc.
- **Transients**: Caching for performance across all modules
- **Options**: Plugin settings stored with WordPress options API

#### Logging System (New)
- **PHP Logging**: `PuntWorkLogger` class in `includes/utilities/puntwork-logger.php`
- **JavaScript Logging**: `PuntWorkJSLogger` in `assets/puntwork-logger.js`
- **Features**: Structured logging, performance monitoring, session tracking
- **Integration**: Used across all modules for consistent logging
- **Admin Interface**: Log viewer in WordPress admin
- **Performance Monitoring**: AJAX timing, memory usage alerts, batch metrics

## Development Standards

### PHP Standards
- **Namespace**: `Puntwork`
- **Functions**: CamelCase, private methods prefixed with `_`
- **Security**: `wp_verify_nonce()`, `sanitize_text_field()`, `esc_html()`
- **Error Handling**: WP_DEBUG mode, `error_log()` logging
- **Documentation**: PHPDoc for all functions/classes

### JavaScript Standards
- **Version**: ES6+ with WordPress compatibility
- **Framework**: Vue.js for admin components
- **Linting**: ESLint with WordPress standards
- **Async**: `async/await` for API calls

### General Standards
- **Indentation**: 4 spaces (no tabs)
- **Line Length**: 100 characters maximum
- **Version Control**: Atomic commits, feature branches
- **Dependencies**: Minimal, prefer WordPress core functions

## Performance Optimizations

### Caching Strategy
- **Transients**: `set_transient()` for API responses
- **Object Cache**: WP Object Cache for frequent queries
- **Browser Cache**: External JS/CSS files for better caching

### Database Optimization
- **Indexes**: On frequently queried fields
- **Batch Processing**: Chunked imports to prevent timeouts
- **Query Optimization**: Use `WP_Query` with proper arguments

### Code Optimization
- **File Splitting**: Modular architecture reduces complexity
- **Lazy Loading**: Conditional asset loading
- **Minification**: Production-ready asset optimization

## Security Measures

### Input Validation
- **Sanitization**: All user inputs sanitized
- **Validation**: Server-side validation for all data
- **Escaping**: All output properly escaped

### Access Control
- **Capabilities**: Proper WordPress capability checks
- **Nonces**: All forms protected with nonces
- **Permissions**: Role-based access control

### Data Protection
- **GDPR Compliance**: Data handling follows GDPR guidelines
- **Secure Storage**: Sensitive data properly encrypted
- **Audit Trail**: Import logs for accountability

## Testing Strategy

### Unit Testing
- **Framework**: PHPUnit for PHP logic
- **Coverage**: Core functions and utilities
- **CI/CD**: Automated testing on commits

### Integration Testing
- **Framework**: WordPress test suite
- **API Testing**: External API integrations
- **Database Testing**: Data integrity validation

### End-to-End Testing
- **Framework**: Cypress for UI flows
- **User Journeys**: Complete import workflows
- **Cross-browser**: Compatibility testing

## Deployment & Maintenance

### Build Process
- **Composer**: PHP dependency management
- **NPM**: JavaScript dependency management
- **Build Tools**: Webpack for asset compilation

### Version Control
- **Branching**: Git Flow methodology
- **Releases**: Tagged releases with changelogs
- **Documentation**: Updated with each release

### Monitoring
- **Error Logging**: Centralized error tracking
- **Performance**: Monitoring and optimization
- **User Feedback**: Issue tracking and resolution

## Quick Reference Commands

### Development
```bash
# Install dependencies
composer install
npm install

# Run tests
composer test
npm test

# Build assets
npm run build
```

### Modular Development
```bash
# Find files in specific module
find includes/admin/ -name "*.php"

# Check module file count
ls includes/*/ | wc -l

# Validate module structure
find includes/ -type f -name "*.php" | sort
```

### WordPress Integration
```php
// Enqueue scripts
wp_enqueue_script('puntwork-admin', plugin_dir_url(__FILE__) . 'assets/job-import-admin.js', ['jquery'], '1.0.0', true);

// Add shortcode
add_shortcode('puntwork_jobs', 'puntwork_display_jobs');
```

### Module-Specific Patterns
```php
// Admin module: Add menu page
add_action('admin_menu', function() {
    add_menu_page('Job Import', 'Job Import', 'manage_options', 'puntwork-jobs', 'puntwork_admin_page');
});

// API module: Secure AJAX handler
add_action('wp_ajax_puntwork_import', function() {
    if (!wp_verify_nonce($_POST['nonce'], 'job_import_nonce')) {
        wp_send_json_error(['message' => 'Security check failed']);
    }
    // Process in appropriate module...
});

// Batch module: Memory-safe processing
function process_batch_modular($items, $batchSize = 50) {
    $total = count($items);
    for ($i = 0; $i < $total; $i += $batchSize) {
        $batch = array_slice($items, $i, $batchSize);
        process_batch_chunk($batch); // Delegate to specific module
    }
}

// Logging across modules
use Puntwork\PuntWorkLogger;
PuntWorkLogger::info("Operation started", "MODULE_NAME", $contextData);
```

### Common Patterns
```php
// Security: Nonce verification
if (!wp_verify_nonce($_POST['nonce'], 'puntwork_action')) {
    wp_die('Security check failed');
}

// Sanitization: User input
$feed_url = sanitize_text_field($_POST['feed_url']);

// Database: Safe queries
$jobs = get_posts([
    'post_type' => 'job_post',
    'meta_query' => [['key' => 'feed_url', 'value' => $feed_url]]
]);
```

## Modular Architecture Benefits

### Why This Structure Works
- **Single Responsibility**: Each module has one clear purpose
- **Easy Navigation**: Find functionality instantly by module
- **Reduced Coupling**: Modules communicate through well-defined interfaces
- **Scalable Development**: Add features to appropriate modules without affecting others
- **Better Testing**: Test modules independently
- **Improved Debugging**: Isolate issues to specific modules

### Module Communication Patterns
- **Data Flow**: `import/` → `batch/` → `mappings/` → database
- **UI Flow**: `admin/` → `api/` → business logic modules
- **Scheduling**: `scheduling/` → `batch/` → `utilities/` (logging)
- **Shared Services**: `utilities/` and `core/` used by all modules

### Development Workflow
1. **Identify Module**: Determine which module handles the required functionality
2. **Locate Files**: Navigate to the appropriate `includes/[module]/` directory
3. **Follow Patterns**: Use established patterns within that module
4. **Test Integration**: Validate cross-module communication
5. **Update Documentation**: Keep module interfaces documented