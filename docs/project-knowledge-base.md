# puntWork Project Knowledge Base

## Project Overview
**puntWork** is a WordPress plugin for seamless job import and management. It enables users to pull job listings from external sources (APIs, RSS feeds, CSV/XML files), customize fields, and integrate with WP themes for job boards. Target audience: Small businesses, recruiters, and career sites.

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

### PHP Structure
```
puntWork/
├── job-import.php (Main plugin file)
├── uninstall.php (Cleanup on uninstall)
├── includes/
│   ├── admin-menu.php (Admin menu setup)
│   ├── admin-page-html.php (Admin dashboard HTML)
│   ├── ajax-*.php (Split AJAX handlers)
│   ├── core-structure-logic.php (Feed processing logic)
│   ├── mappings-*.php (Split mapping files)
│   ├── import-batch.php (Main import orchestration)
│   ├── enqueue-scripts-js.php (Asset enqueuing)
│   └── utility-*.php (Helper functions)
├── assets/js/
│   ├── job-import-admin.js (Main JS entry point)
│   ├── job-import-*.js (Modular JS components)
│   └── (CSS files)
└── notes/ (Documentation)
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

#### Database Schema
- **Custom Post Type**: `job_post`
- **Taxonomies**: `job_category`, `job_type`
- **Meta Fields**: `feed_url`, `job_id`, `salary_range`, etc.
- **Transients**: Caching for performance

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

### WordPress Integration
```php
// Enqueue scripts
wp_enqueue_script('puntwork-admin', plugin_dir_url(__FILE__) . 'assets/js/admin.js', ['jquery'], '1.0.0', true);

// Add shortcode
add_shortcode('puntwork_jobs', 'puntwork_display_jobs');
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