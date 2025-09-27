# puntWork - Advanced Job Import Plugin for WordPress

[![Version](https://img.shields.io/badge/version-2.0.0-blue.svg)](https://github.com/DGC-GH/puntWork)
[![PHP](https://img.shields.io/badge/PHP-8.1+-8892BF.svg)](https://php.net)
[![WordPress](https://img.shields.io/badge/WordPress-5.0+-21759B.svg)](https://wordpress.org)
[![License](https://img.shields.io/badge/license-GPL--2.0+-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

A comprehensive, enterprise-grade WordPress plugin for importing and managing job listings from multiple feed formats with advanced analytics, real-time monitoring, AI-powered features, and developer-friendly APIs.

## ✨ Features

### 🚀 **Advanced Import Processing**
- **Multi-Format Support**: XML, JSON, and CSV feed processing with automatic format detection
- **Real-Time Progress**: Live import tracking via Server-Sent Events (SSE)
- **Batch Processing**: Dynamic batch sizing with memory optimization and streaming JSONL processing
- **Async Operations**: Background processing for large imports using WordPress async tasks
- **Duplicate Detection**: Advanced similarity algorithms with fuzzy matching and configurable thresholds

### 📊 **Analytics & Monitoring**
- **Import Analytics Dashboard**: Comprehensive metrics and performance tracking with database storage
- **Feed Health Monitoring**: Automatic health checks with response time tracking and error monitoring
- **Performance Metrics**: Detailed timing and throughput analysis with memory usage tracking
- **CSV Export**: Analytics data export for reporting and trend analysis
- **Historical Tracking**: 90-day retention with cleanup scheduling

### 🔒 **Security & Reliability**
- **Input Validation**: Comprehensive field validation, sanitization, and URL validation with FILTER_VALIDATE_URL
- **Rate Limiting**: API rate limiting to prevent abuse with configurable thresholds
- **CSRF Protection**: Advanced security measures beyond WordPress nonces using custom validation
- **Error Handling**: Structured error responses, logging, and graceful failure handling
- **Audit Logging**: Complete activity tracking with PuntWorkLogger class
- **Security Utils**: Dedicated security utilities for authentication and access control

### 🛠️ **Developer Experience**
- **REST API**: Full REST API with OpenAPI 3.0 specification and comprehensive endpoints
- **Interactive Documentation**: Swagger UI for API exploration at `/docs/api-docs.html`
- **PSR-4 Autoloading**: Modern PHP architecture with namespaces and class autoloading
- **Docker Environment**: Complete development setup with Docker Compose and development scripts
- **Comprehensive Testing**: PHPUnit test suite with performance benchmarks and security tests
- **Code Quality**: Type hints, PHPDoc documentation, and structured error handling

### ⚙️ **Advanced Features**
- **Scheduling System**: Flexible cron-based import scheduling with custom intervals (2-24 hours)
- **Field Mapping**: Intelligent data transformation with geographic, salary, and icon mappings
- **Geographic Processing**: Location-based data enhancement with province and region mapping
- **Salary Parsing**: Advanced salary data extraction and formatting with estimation algorithms
- **Content Inference**: Automatic content categorization and tagging
- **Caching System**: WordPress object cache integration for improved performance
- **Database Optimization**: Indexed tables and query optimization for large datasets

### ⚡ **AI-Powered Features & Advanced APIs (v2.1.0+)**
- **Content Quality Scoring**: AI-powered analysis of job descriptions with improvement recommendations
- **Intelligent Categorization**: Automatic job classification using keyword-based ML algorithms
- **Advanced Duplicate Detection**: Machine learning-powered similarity detection
- **Feed Optimization**: Automated feed configuration optimization using predictive analytics
- **GraphQL API**: Flexible query language for advanced data retrieval and filtering
- **Webhook System**: Real-time notifications for import events, failures, and job changes
- **Enhanced REST API**: Comprehensive endpoints with OpenAPI documentation

### 🏢 **CRM Integration (v2.0.0)**
- **Multi-Platform Support**: HubSpot, Salesforce, Zoho CRM, and Pipedrive integration
- **Automated Contact Sync**: Bidirectional synchronization of candidate data
- **Lead Generation**: Automatic lead creation from job applications
- **Pipeline Management**: Integration with CRM sales pipelines and workflows
- **Custom Field Mapping**: Flexible field mapping between job applications and CRM records
- **Duplicate Prevention**: Intelligent deduplication across systems

### 🌐 **Multi-Site Support (v2.2.0)**
- **Network-Wide Management**: Centralized job import across WordPress multisite networks
- **Site-Specific Configuration**: Individual settings per subsite with global overrides
- **Shared Resources**: Common feed sources and mappings across sites
- **Cross-Site Synchronization**: Job data sharing and duplication prevention
- **Admin Interface**: Network admin dashboard for multisite management

### ⚖️ **Horizontal Scaling (v2.2.0)**
- **Distributed Processing**: Load balancing across multiple server instances
- **Instance Management**: Automatic health monitoring and failover
- **Queue Distribution**: Intelligent job distribution across processing nodes
- **Resource Optimization**: Dynamic scaling based on workload demands
- **High Availability**: Redundant processing with automatic recovery

### 📱 **Mobile Companion App (v2.1.0)**
- **React Native Application**: Cross-platform mobile app for iOS and Android
- **Job Browsing**: Mobile-optimized job listings with advanced filtering
- **Application Management**: Track and manage job applications on-the-go
- **Offline Support**: Local caching for offline job browsing
- **Push Notifications**: Real-time alerts for new jobs and application updates
- **Profile Management**: Mobile access to user profiles and preferences

#### Mobile App Architecture
- **Framework**: React Native with Expo support
- **Navigation**: React Navigation v6 with stack navigator
- **State Management**: React Context for authentication state
- **API Integration**: Axios for HTTP requests with JWT authentication
- **Data Persistence**: AsyncStorage for offline data and user sessions
- **UI Components**: Custom styled components with responsive design

#### Mobile App Features
- **Authentication System**: AuthContext for global authentication state with JWT tokens and auto-login
- **Screen Components**: LoginScreen, JobListScreen, JobDetailScreen, ApplicationFormScreen, ProfileScreen, DashboardScreen
- **API Integration**: REST API endpoints for authentication, jobs, applications, and dashboard data
- **Development Standards**: ESLint with React Native standards, feature-based component structure
- **Build Process**: iOS Xcode build with CocoaPods, Android Gradle build with Android SDK

### 📢 **Social Media Integration (v2.1.0)**
- **Multi-Platform Posting**: Automated posting to Twitter, Facebook, TikTok, and LinkedIn
- **Content Scheduling**: Intelligent scheduling based on audience engagement
- **Performance Analytics**: Track social media performance and engagement metrics
- **Ad Management**: Integrated advertising campaign management
- **Content Optimization**: AI-powered content suggestions and optimization

## 📋 Requirements

- **WordPress**: 5.0 or higher
- **PHP**: 8.1 or higher
- **MySQL**: 5.7 or higher (8.0 recommended)
- **Memory**: 256MB minimum (512MB recommended)
- **Storage**: Sufficient space for feed processing and caching

## 🚀 Quick Start

### Installation

1. **Download** the plugin files
2. **Upload** to `/wp-content/plugins/puntwork/`
3. **Activate** through WordPress admin
4. **Configure** API settings and feed sources

### Basic Usage

```php
// Trigger import programmatically
$result = puntwork_trigger_import([
    'test_mode' => false,
    'force' => false
]);

// Get import status
$status = puntwork_get_import_status();
```

### REST API Usage

```bash
# Trigger import via API
curl -X POST "/wp-json/puntwork/v1/trigger-import" \
  -d "api_key=YOUR_API_KEY"

# Get real-time progress
curl "/wp-json/puntwork/v1/import-progress?api_key=YOUR_API_KEY"
```

## 📖 Documentation

### 📚 **Complete Documentation**
- **[Technical Reference](docs/technical-reference.md)**: Comprehensive technical reference for developers
- **[Mobile App Guide](mobile/README.md)**: React Native companion app documentation

### 🔧 **Configuration**

#### API Settings
Navigate to **WordPress Admin > puntWork > API Settings** to:
- Generate/Configure API keys (ensure matches .env file: REMOVED_API_KEY)
- Set rate limiting parameters
- Configure security options
- Verify WordPress REST API is enabled (/wp-json/ returns JSON)
- Test endpoints: import-status and trigger-import

#### Feed Management
Create job feeds via **WordPress Admin > Job Feeds**:
- Add XML/JSON/CSV feed URLs
- Configure field mappings
- Set import schedules

#### Scheduling
Configure automated imports via **WordPress Admin > puntWork > Scheduling**:
- Set import frequencies (hourly to monthly)
- Configure retry policies
- Monitor scheduled tasks

### 🎯 **Key Endpoints**

#### Authentication
All REST API endpoints require WordPress authentication via Application Passwords or Basic Auth. Admin AJAX endpoints use WordPress nonces.

#### REST API Endpoints
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

#### AJAX Endpoints
- `puntwork_import_control` - Start/stop import, get status (commands: start/stop/status)
- `puntwork_db_optimize` - Run database optimization
- `puntwork_feed_health` - Get/update feed health alerts
- `run_job_import_batch` - Process import batches
- `get_job_import_status` - Get current status
- `cancel_job_import` - Cancel running import

#### Error Handling
Common HTTP status codes: 200 (success), 400 (bad request), 403 (forbidden), 409 (conflict), 500 (server error). Rate limiting: 100 requests/hour per IP for REST API.

## 🏗️ Architecture

### 📁 **File Structure**
```
puntwork/
├── puntwork.php              # Main plugin file
├── composer.json             # PHP dependencies
├── docker-compose.yml        # Development environment
├── Dockerfile               # Docker configuration
├── assets/                  # Frontend assets (JavaScript modules)
│   ├── js/                  # JavaScript modules
│   └── images/              # Icons and assets
├── includes/                # Core functionality (modular structure)
│   ├── admin/               # Admin interface (5 files)
│   ├── api/                 # AJAX handlers (4 files)
│   ├── batch/               # Batch processing (5 files)
│   ├── core/                # Core utilities (2 files)
│   ├── crm/                 # CRM integrations
│   ├── database/            # Database operations
│   ├── import/              # Import operations (8 files)
│   ├── jobboards/           # Job board integrations
│   ├── mappings/            # Data mappings (6 files)
│   ├── multisite/           # Multi-site support
│   ├── queue/               # Queue management
│   ├── reporting/           # Analytics and reporting
│   ├── scheduling/          # Scheduling system (4 files)
│   ├── socialmedia/         # Social media features
│   └── utilities/           # Utility functions (9 files)
├── mobile/                  # React Native companion app
├── docs/                    # Documentation
├── scripts/                 # Utility scripts
└── tests/                   # Test suite
```

#### Key Files
- `puntwork.php` - Main plugin loader with include paths
- `includes/batch/batch-core.php` - Main batch processing logic
- `includes/scheduling/scheduling-core.php` - Scheduling calculations and cron management
- `includes/api/ajax-handlers.php` - Primary AJAX endpoint handler
- `assets/job-import-api.js` - JavaScript API communication layer

### 🏛️ **Core Components**

#### Module Responsibilities
- **`includes/admin/`**: WordPress admin interface, dashboard, settings pages
- **`includes/api/`**: AJAX endpoints, data validation, API responses
- **`includes/batch/`**: Batch processing logic, memory management, progress tracking
- **`includes/core/`**: Plugin initialization, asset enqueuing, core setup
- **`includes/import/`**: Feed processing, XML/JSON parsing, data transformation
- **`includes/mappings/`**: Field mappings, data normalization, schema handling
- **`includes/scheduling/`**: Cron jobs, time calculations, scheduled imports
- **`includes/utilities/`**: Logging, file operations, helper functions

#### AI-Powered Components
- **ContentQualityScorer**: Linguistic analysis and quality assessment of job descriptions
- **JobCategorizer**: Intelligent job classification using ML algorithms
- **DuplicateDetector**: Advanced similarity detection and deduplication
- **FeedOptimizer**: Predictive analytics for feed configuration optimization

#### API Components
- **GraphQLAPI**: Flexible query interface for advanced data operations
- **WebhookManager**: Real-time event notifications and integrations
- **RestApi**: Enhanced REST endpoints with comprehensive documentation

#### CRM Integration
- **CRMManager**: Multi-platform CRM orchestration and data synchronization
- **HubSpotIntegration**: HubSpot CRM bidirectional sync
- **SalesforceIntegration**: Salesforce CRM connectivity
- **ZohoIntegration**: Zoho CRM integration
- **PipedriveIntegration**: Pipedrive CRM pipeline management

#### Multi-Site & Scaling
- **MultiSiteManager**: Network-wide job management across WordPress multisite
- **HorizontalScalingManager**: Distributed processing across multiple instances

#### ImportAnalytics Class
- Tracks import metrics and performance
- Generates analytics dashboard
- Handles data retention and export

#### FeedHealthMonitor Class
- Monitors feed availability and performance
- Sends automated alerts for issues
- Tracks feed response times and errors

#### JobDeduplicator Class
- Advanced duplicate detection algorithms
- Fuzzy matching with configurable thresholds
- Performance-optimized similarity calculations

#### SecurityUtils Class
- Input validation and sanitization
- Rate limiting and abuse prevention
- CSRF protection and authentication

## 🧪 Testing

### PHPUnit Tests
```bash
# Run test suite
./vendor/bin/phpunit

# Run specific tests
./vendor/bin/phpunit tests/ImportTest.php

# Run performance benchmarks
./vendor/bin/phpunit tests/PerformanceBenchmarkTest.php

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

## 🔧 Development

### 🐳 **Docker Development**
The plugin includes a complete Docker development environment with WordPress, MySQL, PHPMyAdmin, Redis, and MailHog services.

#### Services
- **WordPress** (Port 8080): WordPress with puntWork plugin pre-installed, XDebug configured, WP-CLI available, Composer installed.
- **MySQL** (Port 3306): MySQL 8.0 with utf8mb4 charset. Database: `wordpress`, User: `wordpress` / Password: `wordpress`, Root password: `root`.
- **PHPMyAdmin** (Port 8081): Web interface for MySQL database management, pre-configured to connect to the MySQL container.
- **Redis** (Port 6379): Redis 7 with persistence enabled, used for caching by the puntWork plugin.
- **MailHog** (Ports 1025/8025): SMTP server on port 1025, web interface on port 8025 for viewing sent emails.

#### Quick Setup
```bash
./setup-dev.sh
```

#### Manual Control
```bash
docker-compose up -d    # Start services
docker-compose down     # Stop services
docker-compose logs -f  # View logs
```

#### Accessing Containers
- **WordPress Container Shell**: `docker-compose exec wordpress bash`
- **WP-CLI Commands**: `docker-compose exec wordpress wp --allow-root` (e.g., `wp user list --allow-root`, `wp plugin activate puntwork --allow-root`)
- **Database Access**: `docker-compose exec db mysql -u wordpress -pwordpress wordpress` or use PHPMyAdmin at http://localhost:8081

#### Running Tests
- **PHPUnit Tests**: `docker-compose exec wordpress ./vendor/bin/phpunit` (all tests), `./vendor/bin/phpunit tests/ImportTest.php` (specific), `--coverage-html coverage` (with coverage)
- **API Tests**: `docker-compose exec wordpress php tests/comprehensive-api-test.php`

#### Debugging
- XDebug is pre-configured for VS Code debugging (port 9003). Create `.vscode/launch.json` with path mappings to `/var/www/html/wp-content/plugins/puntwork`.
- WordPress debug logs: `docker-compose exec wordpress tail -f /var/www/html/wp-content/debug.log`

#### Code Changes
Plugin code is mounted as a volume, so changes are reflected immediately:
- Plugin files: `./` → `/var/www/html/wp-content/plugins/puntwork`
- WordPress content: `./wp-content/` → `/var/www/html/wp-content`

#### Database Management
- **Backup**: `docker-compose exec db mysqldump -u wordpress -pwordpress wordpress > backup.sql`
- **Restore**: `docker-compose exec -T db mysql -u wordpress -pwordpress wordpress < backup.sql`
- **Reset Database**: Stop WordPress, drop/recreate database, restart WordPress, reinstall WordPress via WP-CLI.

#### Environment Configuration
- **.env File**: Contains development settings like `PUNTWORK_DEBUG=true`, `WP_DEBUG=true`.
- **Docker Compose Overrides**: Create `docker-compose.override.yml` for local customizations (e.g., custom environment variables).

#### Troubleshooting
- **WordPress Won't Start**: Check logs with `docker-compose logs wordpress`, verify database readiness.
- **Plugin Not Loading**: Check plugin files, WordPress error logs, plugin activation status.
- **Database Connection Issues**: Test with `wp db check --allow-root`, restart containers.
- **Permission Issues**: Fix with `docker-compose exec wordpress chown -R www-data:www-data /var/www/html/wp-content`
- **Performance Issues**: Ensure Docker resources, enable Redis caching, monitor with `docker stats`.

#### Advanced Configuration
- **Custom PHP Configuration**: Add `docker/php.ini` and mount in `docker-compose.override.yml`.
- **Additional Tools**: Install Node.js or PHP extensions in containers if needed.
- **CI/CD Integration**: Use for automated testing with GitHub Actions.

### � **Live Server Access via SFTP**

For debugging and validation purposes, you can connect directly to your live server using VS Code's SFTP extension. This allows you to inspect real-time files such as import logs, feed data, or configuration files without needing to download them manually.

#### Setup Steps:
1. Install the "SFTP" extension by liximomo in VS Code.
2. Create a `.vscode/sftp.json` configuration file in your project root with your server credentials (e.g., host, username, password, and remote path pointing to the plugin directory).
3. Right-click in the VS Code Explorer and select "SFTP: Open SSH in Terminal" to browse and view files.

**Note for Grok Code Fast 1:** The path to the puntWork plugin on the server is `/public_html/wp-content/plugins/puntwork`.

This is particularly useful for verifying live feed data, checking error logs (e.g., WordPress debug.log at `/public_html/wp-content/debug.log`), or comparing local changes against production.

### �📝 **Contributing**
1. Fork the repository
2. Create a feature branch
3. Make changes with tests
4. Submit a pull request

### 🏷️ **Coding Standards**
- PSR-4 autoloading for classes
- PSR-12 coding standards
- Comprehensive type hints
- WordPress coding standards
- Comprehensive documentation

## 🏆 Code Quality & Best Practices

### 📏 **PSR-12 Coding Standards**
This project follows PSR-12 coding standards. Key requirements:

#### Method Naming
- **Use camelCase** for method names (e.g., `validateAjaxRequest`, `getClientIp`)
- ❌ Avoid snake_case in method names (e.g., `validate_ajax_request`, `get_client_ip`)
- Class names should be in PascalCase
- Constants should be in UPPER_SNAKE_CASE

#### Code Formatting
- **Line Length**: Maximum 120 characters per line
- **Indentation**: Use 4 spaces (no tabs)
- **Braces**: Opening brace on same line for control structures
- **Spacing**: Consistent spacing around operators and keywords

#### File Structure
- One class per file
- Files should declare symbols (classes, functions) or execute logic, not both
- Use meaningful namespaces following PSR-4

### 🛠️ **Code Quality Tools**

#### PHP CodeSniffer (PHPCS)
Automatically check and fix coding standards:

```bash
# Check coding standards
./vendor/bin/phpcs includes/ tests/ puntwork.php

# Auto-fix issues (where possible)
./vendor/bin/phpcbf includes/ tests/ puntwork.php
```

#### PHPUnit Testing
Run comprehensive test suite:

```bash
# Run all tests
./vendor/bin/phpunit

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage

# Run specific test class
./vendor/bin/phpunit tests/SecurityTest.php
```

### ✅ **Pre-Commit Quality Checks**

Before committing code, ensure:

1. **Tests Pass**: All PHPUnit tests must pass
2. **Coding Standards**: No PHPCS errors (warnings acceptable for legacy code)
3. **Syntax Check**: PHP files must have valid syntax
4. **Documentation**: New methods/classes should have PHPDoc comments

### 🔧 **Development Workflow**

#### Code Changes
1. Create feature branch from `main`
2. Make changes following coding standards
3. Add/update tests for new functionality
4. Run full test suite: `./vendor/bin/phpunit`
5. Check coding standards: `./vendor/bin/phpcs includes/`
6. Fix any auto-fixable issues: `./vendor/bin/phpcbf includes/`
7. Commit with descriptive message

#### Pull Request Checklist
- [ ] Tests pass (`./vendor/bin/phpunit`)
- [ ] Coding standards met (`./vendor/bin/phpcs`)
- [ ] No syntax errors (`php -l`)
- [ ] Documentation updated
- [ ] Security implications reviewed
- [ ] Performance impact assessed

### 🚨 **Common Issues to Avoid**

#### Method Naming Violations
```php
// ❌ Wrong - snake_case
public function validate_ajax_request() { }

// ✅ Correct - camelCase
public function validateAjaxRequest() { }
```

#### Line Length Issues
```php
// ❌ Wrong - too long
$very_long_variable_name_that_exceeds_the_limit = "This line is way too long and should be broken up";

// ✅ Correct - broken into multiple lines
$very_long_variable_name = "This line is properly formatted " .
    "and broken into multiple lines for readability";
```

#### File Structure Issues
```php
// ❌ Wrong - mixing declarations and logic
class MyClass {
    public function method() { }
}
// Some execution code here

// ✅ Correct - separate files for logic
// In class file
class MyClass {
    public function method() { }
}

// In separate execution file
$instance = new MyClass();
$instance->method();
```

### 📊 **Quality Metrics**

Track these metrics to maintain code quality:

- **Test Coverage**: Aim for >80% code coverage
- **PHPCS Compliance**: 0 errors (warnings tracked separately)
- **Cyclomatic Complexity**: Keep methods under 10 complexity
- **Documentation**: 100% PHPDoc coverage for public methods

### 🔄 **Continuous Improvement**

Regular code quality reviews should include:
- Refactoring legacy code to meet standards
- Updating deprecated patterns
- Improving test coverage
- Performance optimizations
- Security enhancements

#### Successful Development Patterns

**PHP AJAX Handler:**
```php
// Security-first AJAX handler
function secure_ajax_handler() {
    if (!wp_verify_nonce($_POST['nonce'], 'action_nonce')) {
        wp_send_json_error(['message' => 'Security check failed']);
        return;
    }
    // Process request...
}
```

**JavaScript Module:**
```javascript
// IIFE Module with global export
const ModuleName = (function($) {
    function publicMethod() { /* ... */ }
    return { publicMethod: publicMethod };
})(jQuery);
window.GlobalObject = window.GlobalObject || {};
window.GlobalObject.Module = ModuleName;
```

**Cached Database Query:**
```php
// Cached database query
function get_cached_posts($args) {
    $cache_key = 'prefix_' . md5(serialize($args));
    $posts = get_transient($cache_key);
    if (false === $posts) {
        $posts = get_posts($args);
        set_transient($cache_key, $posts, HOUR_IN_SECONDS);
    }
    return $posts;
}
```

**WordPress Asset Enqueuing:**
```php
// Enqueue admin assets
wp_enqueue_script(
    'puntwork-admin-js',
    plugin_dir_url(__FILE__) . 'assets/job-import-admin.js',
    ['jquery', 'wp-util'],
    '1.0.0',
    true
);
wp_localize_script('puntwork-admin-js', 'jobImportData', [
    'ajaxurl' => admin_url('admin-ajax.php'),
    'nonce' => wp_create_nonce('job_import_nonce')
]);
```

**Error Handling with Logging:**
```php
try {
    $result = risky_operation($data);
} catch (Exception $e) {
    error_log('Error in ' . __FUNCTION__ . ': ' . $e->getMessage());
    wp_send_json_error(['message' => 'Operation failed']);
}
```

By following these practices, we maintain high code quality and prevent the accumulation of technical debt.

## 📊 Performance

### ⚡ **Optimization Features**
- **Memory Management**: Streaming JSONL processing
- **Database Indexing**: Optimized queries with proper indexing
- **Caching**: Redis/object caching for mappings
- **Batch Processing**: Dynamic batch sizing based on performance
- **Async Processing**: Background jobs for large imports

### ⚡ **Performance Optimizations (v2.0.2)**
- **Async Analytics**: Non-blocking analytics updates for faster imports
- **Code Refactoring**: Modular batch processing functions for better maintainability
- **JSONL Optimization**: Efficient file reading with direct index skipping
- **Enhanced Caching**: Batch metadata caching for reduced database queries
- **Memory Management**: Advanced garbage collection and memory pressure handling
- **Database Monitoring**: Query performance tracking and slow query detection
- **Circuit Breaker**: Feed failure prevention with automatic recovery
- **Security Validation**: Enhanced input sanitization and URL security checks

### 📈 **Benchmarks**
- **Import Speed**: 1000+ jobs per minute (depending on server)
- **Memory Usage**: < 50MB for typical imports
- **Database Load**: Optimized queries with minimal locking
- **API Response**: < 100ms for status endpoints

## 🔒 Security

### 🛡️ **Security Features**
- **API Key Authentication**: Secure API access control
- **Rate Limiting**: Prevents API abuse
- **Input Validation**: Comprehensive data sanitization
- **CSRF Protection**: Advanced security measures
- **Audit Logging**: Complete activity tracking

### 🔐 **Best Practices**
- Use HTTPS for all API communications
- Regularly rotate API keys
- Monitor access logs
- Keep WordPress and plugins updated
- Use strong, unique API keys

## 📞 Support


## 📄 License

This plugin is licensed under the GPL v2 or later.

```
Copyright (C) 2024 DGC-GH

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
```

## 🙏 Acknowledgments

- Built with WordPress, PHP, and modern web technologies
- Uses Chart.js for analytics visualization
- Docker environment for consistent development
- Comprehensive testing with PHPUnit
- REST API design following WordPress standards

---

**Version 2.0.0** - Enterprise-grade job import solution with AI-powered features, CRM integrations, multi-site support, horizontal scaling, GraphQL API, webhooks, mobile app, and comprehensive API integration.
