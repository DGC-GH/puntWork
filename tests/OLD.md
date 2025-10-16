# puntWork Test Suite

This directory contains comprehensive tests for the puntWork WordPress plugin. The test suite covers all major functionalities to ensure code changes don't break existing features.

## Test Structure

### Core Test Files

- `TestCase.php` - Base test case class with common utilities
- `bootstrap.php` - WordPress test environment setup
- `test-main-plugin.php` - Tests for main plugin functionality
- `test-import-setup.php` - Tests for import setup and validation
- `test-batch-processing.php` - Tests for batch processing logic
- `test-scheduling.php` - Tests for automated scheduling
- `test-mappings.php` - Tests for data mapping functions
- `test-utilities.php` - Tests for utility functions
- `test-admin.php` - Tests for admin interface
- `test-api.php` - Tests for AJAX and REST API endpoints

## Setup Instructions

### 1. Install WordPress Test Suite

```bash
# Clone WordPress develop repository
git clone https://github.com/WordPress/wordpress-develop.git /tmp/wordpress-tests-lib

# Or set custom path
export WP_TESTS_DIR=/path/to/wordpress-develop/tests/phpunit
```

### 2. Configure Test Database

Create a test database and configure `wp-tests-config.php`:

```php
<?php
define('DB_NAME', 'puntwork_test');
define('DB_USER', 'your_db_user');
define('DB_PASSWORD', 'your_db_password');
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');

define('WP_TESTS_DOMAIN', 'example.com');
define('WP_TESTS_EMAIL', 'admin@example.com');
define('WP_TESTS_TITLE', 'Test Blog');

define('WP_PHP_BINARY', 'php');
```

### 3. Install Dependencies

```bash
# If using Composer
composer install

# Install PHPUnit if not already available
# (Usually included with WordPress test suite)
```

## Running Tests

### Method 1: Using PHPUnit Directly

```bash
# From plugin root directory
phpunit

# Run specific test file
phpunit tests/test-import-setup.php

# Run specific test class
phpunit --filter ImportSetupTest

# Run with coverage
phpunit --coverage-html coverage/
```

### Method 2: Using Test Runner Script

```bash
php test-runner.php
```

### Method 3: Using Makefile (if available)

```bash
make test
```

## Test Categories

### 1. Main Plugin Tests (`test-main-plugin.php`)
- Plugin activation/deactivation
- Custom cron schedules
- Constants and file loading
- Admin menu setup

### 2. Import Setup Tests (`test-import-setup.php`)
- JSONL file validation
- Import preparation
- GUID caching
- Memory and time limit setup

### 3. Batch Processing Tests (`test-batch-processing.php`)
- Batch size management
- Data validation
- Duplicate handling
- Progress tracking

### 4. Scheduling Tests (`test-scheduling.php`)
- Cron job management
- Schedule configuration
- History tracking
- Automated imports

### 5. Mapping Tests (`test-mappings.php`)
- Field mapping (ACF integration)
- Geographic data parsing
- Salary data formatting
- Schema.org generation
- Language and benefits inference

### 6. Utilities Tests (`test-utilities.php`)
- Duplicate handling
- Data cleaning
- Gzip compression/decompression
- Logger functionality
- Shortcode processing

### 7. Admin Tests (`test-admin.php`)
- Admin menu rendering
- Dashboard page output
- UI component loading
- JavaScript initialization

### 8. API Tests (`test-api.php`)
- REST API endpoints
- AJAX handlers
- Import control
- Feed processing
- Data purging

## Writing New Tests

### Basic Test Structure

```php
<?php
namespace Puntwork;

use Puntwork\TestCase;

class MyFeatureTest extends TestCase {

    public function test_my_feature() {
        // Arrange
        $input = 'test data';

        // Act
        $result = my_function($input);

        // Assert
        $this->assertEquals('expected result', $result);
    }
}
```

### Test Utilities

The `TestCase` class provides helpful methods:

- `createTestJob($args)` - Create a test job post
- `createTestJobFeed($args)` - Create a test job feed post
- `createTempFile($content, $extension)` - Create temporary files
- `cleanUpTestData()` - Clean up after tests

### Mocking WordPress Functions

For functions that interact with external services:

```php
// Mock HTTP requests
add_filter('pre_http_request', function($response, $args, $url) {
    return [
        'body' => 'mock response',
        'response' => ['code' => 200]
    ];
}, 10, 3);
```

## Continuous Integration

### GitHub Actions Example

```yaml
name: Tests
on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
    - uses: actions/checkout@v2
    - name: Set up PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: '7.4'
    - name: Install WordPress test suite
      run: |
        git clone https://github.com/WordPress/wordpress-develop.git /tmp/wordpress-tests-lib
    - name: Run tests
      run: phpunit
```

## Test Coverage

Current test coverage includes:

- ✅ Plugin lifecycle (activation/deactivation)
- ✅ Import process (setup, batch processing)
- ✅ Scheduling system
- ✅ Data mapping and transformation
- ✅ Utility functions
- ✅ Admin interface
- ✅ API endpoints
- ✅ Error handling
- ✅ Edge cases

## Troubleshooting

### Common Issues

1. **Database connection errors**
   - Ensure test database exists and credentials are correct
   - Check `wp-tests-config.php` configuration

2. **Missing WordPress test library**
   - Install WordPress develop repository
   - Set `WP_TESTS_DIR` environment variable

3. **Permission errors**
   - Ensure web server can write to test directories
   - Check file permissions for logs and temp files

4. **Memory/time limits**
   - Tests may require increased PHP limits
   - Configure in `php.ini` or test bootstrap

### Debug Mode

Enable debug output in tests:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

## Contributing

When adding new features:

1. Write tests first (TDD approach)
2. Ensure all tests pass
3. Add tests for edge cases
4. Update this README if needed

When modifying existing code:

1. Run full test suite
2. Fix any failing tests
3. Add regression tests if needed
4. Ensure no new test failures

## Performance Testing

For performance-critical code, consider adding benchmark tests:

```php
public function test_import_performance() {
    $start = microtime(true);
    // Run import process
    $end = microtime(true);

    $this->assertLessThan(30, $end - $start, 'Import should complete within 30 seconds');
}
```

---

*Run `phpunit --testdox` for a human-readable test output.*