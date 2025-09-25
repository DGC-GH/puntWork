# Grok Bible - PuntWork Plugin Knowledge Base

## Overview
This document serves as a critical knowledge base for the PuntWork WordPress plugin. It contains verified information about the plugin's architecture, custom post types, and operational details to prevent mistakes during development sessions. All information has been validated through code analysis and live API verification.

## Custom Post Types

### Verified Existing Post Types
Both custom post types were manually created using ACF Pro plugin and are active on the live site (https://belgiumjobs.work/).

#### Job Post Type (`job`)
- **Status**: Active and verified
- **Slug**: `job`
- **Name**: Jobs
- **Archive**: `jobs-search`
- **Taxonomies**: job-brand, job-category, job-city, job-citypostal, job-contract, job-education, job-experience, job-function, job-hour, job-language, job-license, job-location, job-postal-code, job-province, job-salary, job-sector, job-shift, job-type
- **REST API**: Available at `/wp-json/wp/v2/job`
- **Icon**: dashicons-nametag
- **Hierarchical**: false

#### Job Feed Post Type (`job-feed`)
- **Status**: Active and verified
- **Slug**: `job-feed`
- **Name**: Job Feeds
- **Archive**: false
- **Taxonomies**: None
- **REST API**: Available at `/wp-json/wp/v2/job-feed`
- **Icon**: dashicons-admin-post
- **Hierarchical**: false

### Critical Notes
- **DO NOT** register these post types in code using `register_post_type()`
- They are managed exclusively through ACF Pro admin interface
- Any code changes must respect existing ACF field configurations
- These post types exist in the database and are accessible via WordPress APIs

## Verification Methods

### WordPress REST API
Endpoint: `GET {site_url}/wp-json/wp/v2/types`
- Returns JSON object containing all registered post types
- Confirmed presence of `job` and `job-feed` keys on live site

### WordPress PHP API
```php
if (post_type_exists('job')) {
    // Job post type exists
}
if (post_type_exists('job-feed')) {
    // Job-feed post type exists
}
```

### Database Verification
Query `wp_posts` table for `post_type` column:
```sql
SELECT DISTINCT post_type FROM wp_posts WHERE post_type IN ('job', 'job-feed');
```

### Admin Interface
- WordPress Admin > Posts > Filter by post type
- ACF Pro interface shows custom post types

## Plugin Architecture

### File Structure
- **Main Plugin File**: `job-import.php`
- **Assets**: JavaScript and CSS files in `/assets/`
- **Includes**: Core functionality in `/includes/` subdirectories
- **Scripts**: Additional scripts in `/scripts/`
- **Tests**: Unit tests in `/tests/`

### Key Components
- **Admin Interface**: Admin menus, pages, and UI components
- **API Handlers**: AJAX endpoints for import control and processing
- **Batch Processing**: Large-scale data import with size management
- **Scheduling**: Cron-based automated imports
- **Mappings**: Field mappings for job data transformation
- **Utilities**: Helper functions and data cleaning tools

## Development Guidelines

### Code Standards
- Follow WordPress coding standards
- Use proper namespacing and hooks
- Validate and sanitize all inputs
- Use WordPress APIs instead of direct database queries when possible

### ACF Pro Integration
- Respect ACF field configurations
- Do not modify ACF-generated database tables directly
- Use ACF functions for field access: `get_field()`, `update_field()`

### Error Handling
- Implement proper try-catch blocks
- Log errors using the built-in logger
- Provide user-friendly error messages

### Performance Considerations
- Use batch processing for large imports
- Implement proper caching where appropriate
- Monitor memory usage during operations

## Session Preservation

### Purpose
This document preserves critical knowledge across "Grok Code Fast 1" sessions to:
- Prevent accidental re-registration of existing post types
- Maintain consistency with ACF Pro configurations
- Avoid conflicts with live site data
- Ensure proper integration with existing functionality

### Update Protocol
- Update this document whenever new critical information is discovered
- Verify all claims through code analysis or live testing
- Include verification methods for all stated facts

## Emergency Contacts
- **Live Site**: https://belgiumjobs.work/
- **Repository**: https://github.com/DGC-GH/puntWork
- **WordPress Version**: Latest stable
- **ACF Pro Version**: Active and configured

---

*Last Updated: September 25, 2025*
*Verified: REST API check on live site confirmed post types exist*
