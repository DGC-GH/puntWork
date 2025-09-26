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

### 📝 **Contributing**
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

## 📊 Performance

### ⚡ **Optimization Features**
- **Memory Management**: Streaming JSONL processing
- **Database Indexing**: Optimized queries with proper indexing
- **Caching**: Redis/object caching for mappings
- **Batch Processing**: Dynamic batch sizing based on performance
- **Async Processing**: Background jobs for large imports

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
