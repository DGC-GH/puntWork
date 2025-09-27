# puntWork Project Familiarization for Grok Code Fast

## Project Overview
- **Name**: puntWork
- **Type**: WordPress plugin for advanced job import from XML/JSON/CSV feeds
- **Version**: 0.0.4
- **Author**: DGC-GH
- **License**: GPL v2+
- **Requirements**: WordPress 5.0+, PHP 8.1+, MySQL 5.7+
- **Features**: Multi-format feeds, real-time analytics, AI-powered categorization, CRM integrations (HubSpot, Salesforce, etc.), multi-site support, horizontal scaling, GraphQL API, webhooks, mobile app (React Native)

## Technologies
- **Backend**: PHP 8.1+, WordPress API, MySQL
- **Frontend**: JavaScript (jQuery, custom modules), CSS
- **Mobile**: React Native (Expo), Axios, AsyncStorage
- **DevOps**: Docker, Docker Compose, PHPUnit, PHPCS, WP-CLI
- **Libraries**: OpenTelemetry, Guzzle Promises, HTTP Httplug
- **APIs**: REST API, GraphQL, Webhooks, SSE for real-time progress

## Project Structure
```
puntwork/
├── puntwork.php              # Main plugin file: hooks, includes loader, constants
├── composer.json             # Dependencies, PSR-4 autoload (Puntwork\ in includes/)
├── composer.lock             # Composer lock file
├── docker-compose.yml        # Dev env (WP, MySQL, Redis, etc.)
├── Dockerfile                # Docker configuration
├── setup-dev.sh              # Development setup script
├── uninstall.php             # Uninstall script
├── assets/                   # JS/CSS/images
│   ├── css/                  # Stylesheets (admin-modern.css, etc.)
│   ├── images/               # Icons and logos
│   └── js/                   # JS modules (admin, import, etc.)
├── cache/                    # Cache directory
├── includes/                 # Core code (functions + classes)
│   ├── admin/                # Admin UI (menus, pages, AJAX handlers)
│   ├── ai/                   # AI-powered features
│   ├── api/                  # REST API, AJAX endpoints, SSE
│   ├── batch/                # Batch processing logic
│   ├── core/                 # Init, enqueues, core setup
│   ├── crm/                  # CRM integrations (classes)
│   ├── database/             # DB operations
│   ├── import/               # Feed processing, XML/JSON parsing
│   ├── jobboards/            # Job board integrations
│   ├── mappings/             # Data mappings (geographic, salary, etc.)
│   ├── multisite/            # Multi-site support
│   ├── queue/                # Queue management
│   ├── reporting/            # Analytics, reporting
│   ├── scheduling/           # Cron jobs, scheduling
│   ├── socialmedia/          # Social media posting (classes)
│   └── utilities/            # Helpers, logging, security, async
├── languages/                # Translation files
│   └── puntwork.pot          # POT file
├── mobile/                   # React Native app (App.js, src/, package.json)
├── tests/                    # PHPUnit tests (ImportTest.php, etc.)
├── vendor/                   # Composer deps
└── wp-content/               # WordPress content (plugins/, etc.)
```

## Key Files & Purposes
- **puntwork.php**: Entry point, activation/deactivation hooks, cron schedules, includes loader, security headers
- **includes/core/core-structure-logic.php**: Core setup functions
- **includes/admin/admin-menu.php**: Admin menu registration
- **includes/api/rest-api.php**: REST API setup
- **includes/batch/batch-core.php**: Batch processing core logic
- **includes/import/import-batch.php**: Import processing
- **includes/utilities/PuntWorkLogger.php**: Logging class
- **includes/ai/job-categorizer.php**: AI job categorization
- **mobile/App.js**: React Native app entry
- **tests/ImportTest.php**: PHPUnit import tests
- **docker-compose.yml**: Docker services configuration
- **composer.json**: PHP dependencies and autoload

## Architecture Patterns
- **Namespace**: Puntwork\
- **Autoload**: PSR-4 for classes, manual require for functions
- **Hooks**: WordPress actions/filters (init, admin_menu, etc.)
- **AJAX**: wp_ajax_ prefixed actions, nonce verification
- **REST**: /wp-json/puntwork/v1/ endpoints
- **Cron**: Custom schedules (puntwork_hourly, etc.)
- **Async**: Background processing with wp_async_task
- **Caching**: WordPress transients, Redis integration
- **Security**: Nonces, input sanitization, rate limiting

## Coding Conventions
- **Classes**: PascalCase (e.g., `PuntworkCrmAdmin`, not `puntwork_crm_admin` or `Puntwork_CRM_Admin`)
- **Methods**: camelCase (e.g., `validateAjaxRequest`)
- **Constants**: UPPER_SNAKE_CASE (e.g., `PUNTWORK_VERSION`)
- **Files**: kebab-case.php (e.g., `crm-admin.php`)
- **Indent**: 4 spaces
- **Line Length**: 120 chars max
- **Standards**: PSR-12, WordPress coding standards
- **Docs**: PHPDoc for classes/methods
- **Error Handling**: Try/catch, wp_send_json_error
- **Logging**: PuntWorkLogger class

## Key Classes (Autoloaded)
- **ImportAnalytics**: Analytics tracking
- **FeedHealthMonitor**: Feed monitoring
- **JobDeduplicator**: Duplicate detection
- **SecurityUtils**: Security functions
- **CRMManager**: CRM orchestration
- **SocialMediaManager**: Social posting
- **GraphQLAPI**: GraphQL endpoint
- **WebhookManager**: Webhook handling
- **MultiSiteManager**: Multi-site support
- **HorizontalScalingManager**: Distributed processing
- **ContentQualityScorer**: AI content analysis
- **JobCategorizer**: AI categorization

## Key Functions (Manual Include)
- **puntwork_trigger_import()**: Start import
- **puntwork_get_import_status()**: Get status
- **process_batch_items()**: Process import batches
- **validateAjaxRequest()**: AJAX security
- **log_message()**: Logging

## Dependencies (composer.json)
- **Runtime**: open-telemetry/opentelemetry, guzzlehttp/promises, php-http/httplug
- **Dev**: phpunit/phpunit, wp-cli/wp-cli, squizlabs/php_codesniffer

## Testing
- **Framework**: PHPUnit
- **Location**: tests/ (e.g., ImportTest.php, SecurityTest.php)
- **Coverage**: Aim >80%
- **Run**: ./vendor/bin/phpunit

## Development Setup
- **Docker**: docker-compose up -d (WP:8080, MySQL:3306, PHPMyAdmin:8081, Redis:6379, MailHog:8025)
- **Scripts**: setup-dev.sh
- **Debug**: XDebug port 9003
- **WP-CLI**: docker-compose exec wordpress wp --allow-root

## Mobile App
- **Framework**: React Native + Expo
- **Screens**: Login, JobList, JobDetail, ApplicationForm, Profile, Dashboard
- **State**: React Context (AuthContext)
- **API**: Axios with JWT
- **Storage**: AsyncStorage

## Important Notes
- **Entry Point**: puntwork.php loads all includes on 'init' hook
- **AJAX Actions**: puntwork_import_control, run_job_import_batch, etc.
- **REST Endpoints**: /wp-json/puntwork/v1/trigger-import, /status, etc.
- **Cron Hooks**: job_import_cron, puntwork_social_cron
- **Constants**: PUNTWORK_VERSION, PUNTWORK_PATH, etc.
- **Security**: Always verify nonces, sanitize inputs
- **Performance**: Streaming JSONL, batch processing, caching
- **Multi-site**: Check is_multisite() for network features
- **AI Features**: ContentQualityScorer, JobCategorizer, DuplicateDetector
- **Scaling**: HorizontalScalingManager for distributed processing

## Quick Reference
- **Trigger Import**: puntwork_trigger_import(['test_mode' => false])
- **Get Status**: puntwork_get_import_status()
- **Log**: PuntWorkLogger::log('message', 'INFO')
- **Cache**: set_transient('key', $data, HOUR_IN_SECONDS)
- **AJAX Response**: wp_send_json_success($data) or wp_send_json_error($msg)
- **REST Response**: return new WP_REST_Response($data, 200)
- **Enqueue Script**: wp_enqueue_script('handle', url, deps, ver, true); wp_localize_script('handle', 'var', data)

## API Endpoints
### REST API Endpoints
- `POST /wp-json/puntwork/v1/import/trigger` - Trigger import (body: {feed_key, force})
- `GET /wp-json/puntwork/v1/import/status` - Get import progress and statistics
- `GET /wp-json/puntwork/v1/feeds` - List configured feed URLs
- `GET /wp-json/puntwork/v1/analytics/summary?period=30days` - Get analytics (period: 7days/30days/90days)
- `GET /wp-json/puntwork/v1/analytics/export?period=30days` - Export analytics as CSV
- `GET /wp-json/puntwork/v1/health/feeds` - Get feed health status
- `POST /wp-json/puntwork/v1/trigger-import` - Trigger manual import
- `GET /wp-json/puntwork/v1/import-status` - Get import status
- `GET /wp-json/puntwork/v1/import-progress` - Real-time progress (SSE)
- `POST /wp-json/puntwork/v1/graphql` - GraphQL query endpoint
- `GET /wp-json/puntwork/v1/webhooks` - List configured webhooks
- `POST /wp-json/puntwork/v1/webhooks` - Register new webhook

### AJAX Endpoints
- `puntwork_import_control` - Start/stop import, get status (commands: start/stop/status)
- `puntwork_db_optimize` - Run database optimization
- `puntwork_feed_health` - Get/update feed health alerts
- `run_job_import_batch` - Process import batches
- `get_job_import_status` - Get current status
- `cancel_job_import` - Cancel running import

### Authentication
All REST API endpoints require WordPress authentication via Application Passwords or Basic Auth. Admin AJAX endpoints use WordPress nonces.

## Configuration
### API Settings
Navigate to **WordPress Admin > puntWork > API Settings** to:
- Generate/Configure API keys (ensure matches .env file)
- Set rate limiting parameters
- Configure security options
- Verify WordPress REST API is enabled (/wp-json/ returns JSON)
- Test endpoints: import-status and trigger-import

### Feed Management
Create job feeds via **WordPress Admin > Job Feeds**:
- Add XML/JSON/CSV feed URLs
- Configure field mappings
- Set import schedules

### Scheduling
Configure automated imports via **WordPress Admin > puntWork > Scheduling**:
- Set import frequencies (hourly to monthly)
- Configure retry policies
- Monitor scheduled tasks

## Development Workflow
### Code Changes
1. Create feature branch from `main`
2. Make changes following coding standards
3. Add/update tests for new functionality
4. Run full test suite: `./vendor/bin/phpunit`
5. Check coding standards: `./vendor/bin/phpcs includes/`
6. Fix any auto-fixable issues: `./vendor/bin/phpcbf includes/`
7. Commit with descriptive message

### Pre-Commit Quality Checks
Before committing code, ensure:
1. **Tests Pass**: All PHPUnit tests must pass
2. **Coding Standards**: No PHPCS errors (warnings acceptable for legacy code)
3. **Syntax Check**: PHP files must have valid syntax
4. **Documentation**: New methods/classes should have PHPDoc comments

### Pull Request Checklist
- [ ] Tests pass (`./vendor/bin/phpunit`)
- [ ] Coding standards met (`./vendor/bin/phpcs`)
- [ ] No syntax errors (`php -l`)
- [ ] Documentation updated
- [ ] Security implications reviewed
- [ ] Performance impact assessed

## Git and Version Control Best Practices
- **Branch and Tag Naming**: Avoid creating branches or tags with spaces in their names, as Git refs should not contain spaces. Use hyphens or underscores instead (e.g., `feature/new-import` instead of `feature/new import`).
- **Handling Corrupted Refs**: If you encounter errors like "bad object" or "ignoring ref with broken name", check for and delete corrupted local refs using `git update-ref -d 'refs/remotes/origin/broken-ref'`. Then, run `git fetch --prune` to clean up.
- **Regular Maintenance**: Periodically run `git remote prune origin` to remove stale remote-tracking branches and `git gc` to optimize the repository.

## Performance & Security
### Performance Optimizations
- **Memory Management**: Streaming JSONL processing
- **Database Indexing**: Optimized queries with proper indexing
- **Caching**: Redis/object caching for mappings
- **Batch Processing**: Dynamic batch sizing based on performance
- **Async Processing**: Background jobs for large imports

### Benchmarks
- **Import Speed**: 1000+ jobs per minute (depending on server)
- **Memory Usage**: < 50MB for typical imports
- **Database Load**: Optimized queries with minimal locking
- **API Response**: < 100ms for status endpoints

### Security Features
- **API Key Authentication**: Secure API access control
- **Rate Limiting**: Prevents API abuse
- **Input Validation**: Comprehensive data sanitization
- **CSRF Protection**: Advanced security measures
- **Audit Logging**: Complete activity tracking

### Best Practices
- Use HTTPS for all API communications
- Regularly rotate API keys
- Monitor access logs
- Keep WordPress and plugins updated
- Use strong, unique API keys

## Testing Details
### PHPUnit Tests
```bash
# Run test suite
./vendor/bin/phpunit

# Run specific tests
./vendor/bin/phpunit tests/ImportTest.php

# Run performance benchmarks
./vendor/bin/phpunit tests/PerformanceRegressionTest.php

# Run performance tests with result saving (creates result files)
SAVE_PERFORMANCE_RESULTS=true ./vendor/bin/phpunit tests/PerformanceRegressionTest.php

# Clean up performance result files
php -r "require 'tests/PerformanceRegressionTest.php'; Puntwork\PerformanceRegressionTest::cleanupPerformanceResultFiles();"

# Run security tests
./vendor/bin/phpunit tests/SecurityTest.php

# Generate coverage report
./vendor/bin/phpunit --coverage-html coverage
```

### API Testing
```bash
# Run comprehensive API tests
php tests/comprehensive-api-test.php

# Test specific endpoints
php tests/rest-api-test.php
```

### Development Environment
```bash
# Start Docker environment
./setup-dev.sh

# Access services
# WordPress: http://localhost:8080
# PHPMyAdmin: http://localhost:8081
# MailHog: http://localhost:8025
```

## Deployment and Server Access
### Production Deployment
For production deployment (especially Hostinger), use the deployment scripts to ensure dev dependencies are not installed:

```bash
# Prepare for production deployment
./prepare-production.sh

# This will:
# - Switch to production composer.json (no dev dependencies)
# - Install only production dependencies
# - Remove development files (tests, CI configs, etc.)

# After deployment, restore development environment
./restore-dev.sh
```

### Manual Production Setup
If you need to manually prepare for production:

1. **Remove dev dependencies** from `composer.json`:
   ```json
   {
       "require-dev": {
           "squizlabs/php_codesniffer": "^3.7",
           "phpunit/phpunit": "^10.5"
       }
   }
   ```
   Remove the entire `"require-dev"` section.

2. **Run production install**:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

3. **Remove dev files**:
   ```bash
   rm -f phpunit.xml
   rm -rf tests/
   rm -rf .github/
   ```

### FTP File Access
Server files are accessible via FTP using credentials from .env file:
- **Debug Log**: ftp://$FTP_USER:$FTP_PASS@$FTP_HOST/wp-content/debug.log
- **Plugin Files**: ftp://$FTP_USER:$FTP_PASS@$FTP_HOST/wp-content/plugins/puntWork/

### Admin Page URLs
- **Main Dashboard**: https://belgiumjobs.work/wp-admin/admin.php?page=puntwork-dashboard
- **Job Feed Dashboard**: https://belgiumjobs.work/wp-admin/admin.php?page=job-feed-dashboard
- **Feed Configuration**: https://belgiumjobs.work/wp-admin/admin.php?page=puntwork-feed-config

### Deployment Process
1. Commit changes to main branch
2. GitHub webhook automatically deploys to Hostinger
3. Use FTP to check wp-content/debug.log for errors
4. Open admin URLs in VS Code Simple Browser to verify functionality
5. Clean debug.log via FTP if it becomes too large

This file provides optimal context for rapid codebase understanding and efficient task execution for Grok Code Fast.
