#!/bin/bash
# puntWork Test Runner Script
# This script sets up and runs the test suite

set -e

echo "=== puntWork Test Suite ==="
echo

# Check if we're in the right directory
if [ ! -f "puntwork.php" ]; then
    echo "Error: Please run this script from the puntWork plugin root directory"
    exit 1
fi

# Check for phpunit
if ! command -v phpunit &> /dev/null; then
    echo "PHPUnit not found. Installing..."
    if command -v composer &> /dev/null; then
        composer require --dev phpunit/phpunit
    else
        echo "Please install PHPUnit manually or via Composer"
        exit 1
    fi
fi

# Set up WordPress test environment if not exists
WP_TESTS_DIR=${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}
if [ ! -d "$WP_TESTS_DIR" ]; then
    echo "Setting up WordPress test environment..."
    git clone https://github.com/WordPress/wordpress-develop.git "$WP_TESTS_DIR"
fi

# Check for test config
if [ ! -f "wp-tests-config.php" ]; then
    echo "Creating wp-tests-config.php..."
    cat > wp-tests-config.php << 'EOF'
<?php
/* Test database configuration */
define('DB_NAME', getenv('WP_TEST_DB_NAME') ?: 'puntwork_test');
define('DB_USER', getenv('WP_TEST_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('WP_TEST_DB_PASSWORD') ?: '');
define('DB_HOST', getenv('WP_TEST_DB_HOST') ?: 'localhost');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');

define('WP_TESTS_DOMAIN', 'example.com');
define('WP_TESTS_EMAIL', 'admin@example.com');
define('WP_TESTS_TITLE', 'Test Blog');

define('WP_PHP_BINARY', 'php');

define('WPLANG', '');
EOF
    echo "Please edit wp-tests-config.php with your database credentials"
fi

# Create test database if it doesn't exist
echo "Checking test database..."
DB_NAME=$(grep "DB_NAME" wp-tests-config.php | cut -d"'" -f4)
if ! mysql -e "USE $DB_NAME" 2>/dev/null; then
    echo "Creating test database: $DB_NAME"
    mysql -e "CREATE DATABASE $DB_NAME"
fi

# Run tests
echo "Running tests..."
./vendor/bin/phpunit --verbose

echo
echo "=== Test run complete ==="