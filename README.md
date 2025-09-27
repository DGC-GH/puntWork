# puntWork - Advanced Job Import Plugin for WordPress

[![Version](https://img.shields.io/badge/version-2.0.0-blue.svg)](https://github.com/DGC-GH/puntWork)
[![PHP](https://img.shields.io/badge/PHP-7.4+-8892BF.svg)](https://php.net)
[![WordPress](https://img.shields.io/badge/WordPress-5.0+-21759B.svg)](https://wordpress.org)
[![License](https://img.shields.io/badge/license-GPL--2.0+-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

A comprehensive, enterprise-grade WordPress plugin for importing and managing job listings from multiple feed formats with advanced analytics, real-time monitoring, and developer-friendly APIs.

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

### ⚡ **AI-Powered Features & Advanced APIs (v2.1.0)**
- **Content Quality Scoring**: AI-powered analysis of job descriptions with improvement recommendations
- **Intelligent Categorization**: Automatic job classification using keyword-based ML algorithms
- **Advanced Scheduling**: Dependency management and conditional execution for complex workflows
- **GraphQL API**: Flexible query language for advanced data retrieval and filtering
- **Webhook System**: Real-time notifications for import events, failures, and job changes
- **Enhanced REST API**: Comprehensive endpoints with OpenAPI documentation
- **Automated Feed Optimization**: Machine learning-powered feed configuration optimization using predictive analytics

## 📋 Requirements

- **WordPress**: 5.0 or higher
- **PHP**: 7.4 or higher
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
- **[API Documentation](docs/API-DOCUMENTATION.md)**: Comprehensive API guide with examples
- **[OpenAPI Specification](docs/openapi-spec.json)**: Interactive API documentation
- **[Development Guide](docs/DEVELOPMENT.md)**: Docker setup and development workflow
- **[Deployment Guide](docs/DEPLOYMENT.md)**: Production deployment instructions

### 🔧 **Configuration**

#### API Settings
Navigate to **WordPress Admin > puntWork > API Settings** to:
- Generate/Configure API keys
- Set rate limiting parameters
- Configure security options

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

#### REST API Endpoints
- `POST /wp-json/puntwork/v1/trigger-import` - Trigger manual import
- `GET /wp-json/puntwork/v1/import-status` - Get import status
- `GET /wp-json/puntwork/v1/import-progress` - Real-time progress (SSE)

#### AJAX Endpoints
- `run_job_import_batch` - Process import batches
- `get_job_import_status` - Get current status
- `cancel_job_import` - Cancel running import

## 🏗️ Architecture

### 📁 **File Structure**
```
puntwork/
├── puntwork.php              # Main plugin file
├── composer.json             # PHP dependencies
├── docker-compose.yml        # Development environment
├── Dockerfile               # Docker configuration
├── assets/                  # Frontend assets
│   ├── js/                  # JavaScript modules
│   └── images/              # Icons and assets
├── includes/                # Core functionality
│   ├── api/                 # REST and AJAX handlers
│   ├── batch/               # Batch processing
│   ├── core/                # Core utilities
│   ├── import/              # Import logic
│   ├── mappings/            # Data mappings
│   ├── scheduling/          # Cron scheduling
│   └── utilities/           # Helper functions
├── docs/                    # Documentation
├── scripts/                 # Utility scripts
└── tests/                   # Test suite
```

### 🏛️ **Core Components**

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
The plugin includes a complete Docker development environment:

```bash
# Quick setup
./setup-dev.sh

# Manual control
docker-compose up -d    # Start services
docker-compose down     # Stop services
docker-compose logs -f  # View logs
```

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

### 🐛 **Issue Reporting**
- **GitHub Issues**: [Report bugs and request features](https://github.com/DGC-GH/puntWork/issues)
- **Documentation**: Check [docs/](docs/) for detailed guides
- **API Reference**: Interactive docs at `/docs/api-docs.html`

### 📖 **Resources**
- **[API Documentation](docs/API-DOCUMENTATION.md)**
- **[Development Guide](docs/DEVELOPMENT.md)**
- **[Deployment Guide](docs/DEPLOYMENT.md)**
- **[OpenAPI Spec](docs/openapi-spec.json)**

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

**Version 2.0.0** - Enterprise-grade job import solution with real-time analytics, multi-format support, and comprehensive API integration.
