# puntWork Project Familiarization for Grok Code Fast

## Project Overview
- **Name**: puntWork
- **Type**: WordPress plugin for advanced job import from XML/JSON/CSV feeds
- **Version**: 0.0.4
- **Author**: DGC-GH
- **License**: GPL v2+
- **Requirements**: WordPress 5.0+, PHP 8.1+, MySQL 5.7+
- **Features**: M### Production Deployment (Hostinger)
Hostinger automatically runs `composer install` during deployment. To prevent dev dependencies from being installed in production:

**The repository is configured so that:**
- `composer.json` is a symlink pointing to `composer.json.development` (with dev dependencies)
- `composer.json.production` contains only production dependencies (no dev section)
- Deployment scripts switch the symlink for clean production builds

**For Production Deployment:**
```bash
# This switches composer.json to production version and installs only prod deps
./prepare-production.sh

# Deploy to Hostinger - it will now install only production dependencies
# (Hostinger runs: composer install --no-dev --optimize-autoloader)
```

**After Deployment (restore dev environment):**
```bash
./restore-dev.sh
```

**What This Solves:**
- ✅ **Hostinger deployments are clean** - no dev dependencies (PHPCS, PHPUnit) in production
- ✅ **Faster deployments** - smaller vendor directory, better performance
- ✅ **Local development intact** - full dev tools available via symlink
- ✅ **Zero configuration** - works out-of-the-box with Hostinger's deployment processds, real-time analytics, AI-powered categorization, CRM integrations (HubSpot, Salesforce, etc.), multi-site support, horizontal scaling, GraphQL API, webhooks, mobile app (React Native)

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
- **includes/api/ajax-import-control.php**: AJAX handlers for import operations with enhanced debug logging and class loading fixes
- **includes/batch/batch-core.php**: Batch processing core logic
- **includes/import/import-batch.php**: Import processing
- **includes/import/feed-processor.php**: Multi-format feed processing (XML/JSON/CSV) with batch optimization
- **includes/utilities/PuntWorkLogger.php**: Logging class with contextual logging
- **includes/utilities/ImportAnalytics.php**: Analytics tracking for import operations
- **includes/ai/job-categorizer.php**: AI job categorization
- **analyze-import-logs.sh**: Enhanced AI-driven log analysis script with performance metrics and recommendations
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

## Self-Improving Protocol System

### Overview
puntWork includes an advanced evolutionary algorithm that automatically improves the maintenance protocol through continuous learning and optimization. The system analyzes execution metrics, generates protocol variations, and applies improvements that demonstrate measurable fitness gains.

### Key Components
- **ProtocolEvolutionEngine**: Core evolution engine with analysis, variation generation, and fitness scoring
- **run-protocol.php**: Self-improving protocol runner that integrates evolution into each execution
- **run-protocol.sh**: Shell wrapper for easy protocol execution
- **protocol-evolution-data.json**: Evolution data storage and analytics

### How It Works
1. **Execution Tracking**: Each protocol step records success, duration, and performance metrics
2. **Analysis**: Evolution engine analyzes patterns, bottlenecks, and improvement opportunities
3. **Variation Generation**: Creates protocol variations with different step orders, optimizations, and automation
4. **Fitness Scoring**: Evaluates variations based on execution time, success rate, and resource usage
5. **Selective Application**: Applies only variations that improve fitness by >10%

### Usage

#### Run the Self-Improving Protocol
```bash
# Quick execution
./run-protocol.sh

# Or directly with PHP
php run-protocol.php
```

#### Manual Evolution Control
```bash
# Analyze current performance and suggest improvements
php -r "
require_once 'includes/utilities/ProtocolEvolutionEngine.php';
use Puntwork\ProtocolEvolution\ProtocolEvolutionEngine;
$analysis = ProtocolEvolutionEngine::analyzeAndSuggestImprovements();
echo json_encode($analysis, JSON_PRETTY_PRINT);
"

# Apply the best available improvement
php -r "
require_once 'includes/utilities/ProtocolEvolutionEngine.php';
use Puntwork\ProtocolEvolution\ProtocolEvolutionEngine;
$analysis = ProtocolEvolutionEngine::analyzeAndSuggestImprovements();
if (!empty($analysis['protocol_variations'])) {
    $best = $analysis['protocol_variations'][0];
    $applied = ProtocolEvolutionEngine::applyProtocolVariation($best);
    echo 'Improvement applied: ' . ($applied ? 'SUCCESS' : 'FAILED') . PHP_EOL;
}
"

# View evolution analytics
php -r "
require_once 'includes/utilities/ProtocolEvolutionEngine.php';
use Puntwork\ProtocolEvolution\ProtocolEvolutionEngine;
$analytics = ProtocolEvolutionEngine::getEvolutionAnalytics();
echo json_encode($analytics, JSON_PRETTY_PRINT);
"
```

### Evolution Metrics
The system tracks comprehensive metrics for continuous improvement:
- **Execution Time**: Per-step and total protocol duration
- **Success Rate**: Step completion and overall protocol success
- **Resource Usage**: Memory, CPU, and I/O patterns
- **Error Patterns**: Common failure modes and recovery effectiveness
- **Bottleneck Identification**: Steps that consistently slow execution
- **Optimization Potential**: Calculated improvement opportunities

### Safety Features
- **Gradual Rollout**: Only applies improvements with proven fitness gains
- **Rollback Capability**: Can revert to previous protocol versions
- **Human Oversight**: Major changes require manual approval
- **Validation Gates**: Ensures critical functionality remains intact
- **Backup Creation**: All protocol changes are versioned and backed up

### Evolution Triggers
- **Automatic**: After each protocol execution
- **Scheduled**: Daily analysis, weekly improvements, monthly optimizations
- **Manual**: On-demand evolution via command line
- **Critical Events**: Immediate analysis after failures or performance degradation

### Benefits
- **Continuous Improvement**: Protocol efficiency increases with each execution
- **Automated Optimization**: No manual tuning required
- **Data-Driven Decisions**: Improvements based on real performance metrics
- **Adaptive Learning**: System learns from successes and failures
- **Predictive Optimization**: Anticipates and prevents common issues

### Evolution Data
Evolution data is stored in `protocol-evolution-data.json` and includes:
- Historical execution metrics
- Applied improvements and their impact
- Fitness scores over time
- Protocol variation success rates
- Performance trends and predictions

### Integration
The evolution system is fully integrated into the development workflow:
- Pre-commit hooks validate protocol changes
- CI/CD pipeline includes evolution testing
- Documentation automatically updates with improvements
- Analytics dashboard shows evolution progress

### Advanced Features
- **Multi-Objective Optimization**: Balances speed, reliability, and resource usage
- **Context-Aware Evolution**: Adapts to different environments and workloads
- **Collaborative Learning**: Can share improvements across similar projects
- **Predictive Maintenance**: Anticipates and prevents protocol failures
- **A/B Testing**: Tests multiple variations simultaneously

### Monitoring Evolution
```bash
# Check evolution status
php -r "
require_once 'includes/utilities/ProtocolEvolutionEngine.php';
use Puntwork\ProtocolEvolution\ProtocolEvolutionEngine;
$status = ProtocolEvolutionEngine::getEvolutionStatus();
echo 'Evolution Status: ' . $status['status'] . PHP_EOL;
echo 'Current Fitness: ' . $status['current_fitness'] . PHP_EOL;
echo 'Improvements Applied: ' . $status['improvements_applied'] . PHP_EOL;
"
```

The self-improving protocol system ensures puntWork's maintenance processes continuously evolve and optimize, providing exponential improvements in development efficiency and code quality.

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

**Important Note:** API keys must be passed as query parameters (`?api_key=YOUR_KEY`), not in the Authorization header. Example:
```
GET /wp-json/puntwork/v1/import-status?api_key=YOUR_API_KEY
```

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

### Committing Changes
The repository includes pre-commit hooks that run PHPCS, PHP-CS-Fixer, and PHPUnit to maintain code quality. However, you can bypass these checks for manual commits via VS Code or GitHub UI:

#### Quick Bypass Methods:
1. **Use the commit script**:
   ```bash
   ./commit.sh "Your commit message"
   ```

2. **Set environment variable**:
   ```bash
   SKIP_PRECOMMIT_CHECKS=true git commit -m "Your message"
   ```

3. **Use commit message patterns** (for manual commits):
   ```bash
   git commit -m "WIP: Your message"  # Contains "WIP"
   git commit -m "fixup: Your message" # Contains "fixup"
   git commit -m "skip-checks: Your message" # Contains "skip-checks"
   ```

4. **Standard Git bypass**:
   ```bash
   git commit --no-verify -m "Your message"
   ```

#### When to Bypass Checks:
- **Manual commits** via VS Code/GitHub UI
- **Work-in-progress** commits that will be rebased
- **Documentation-only** changes
- **Configuration** changes
- **Emergency fixes** when checks are failing

#### When to Keep Checks:
- **Feature completion** commits
- **Bug fixes** that should pass all tests
- **Code refactoring** that changes logic
- **Before pull requests**

The pre-commit hooks ensure development quality while allowing flexibility for manual commits.

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
