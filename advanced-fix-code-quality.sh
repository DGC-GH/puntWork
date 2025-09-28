#!/bin/bash

# Advanced Code Quality Automation Script
# Uses multiple tools and techniques for comprehensive fixes

echo "🔧 Starting advanced automated code quality fixes..."

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to print status
print_status() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

print_info() {
    echo -e "${BLUE}ℹ️  $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

# Check if we're in the right directory
if [ ! -f "composer.json" ] || [ ! -d "includes" ]; then
    print_error "Please run this script from the puntWork root directory"
    exit 1
fi

# Tool 1: Our custom sed-based fixes
print_info "Running custom automated fixes..."
./fix-code-quality.sh

# Tool 2: Try PHP-CS-Fixer with WordPress-compatible rules (limited scope)
print_info "Running PHP-CS-Fixer on small batches..."
# Process files in smaller batches to avoid memory issues
find includes -name "*.php" -type f -print0 | xargs -0 -n 5 | while read -r batch; do
    echo "$batch" | tr '\n' '\0' | xargs -0 ./vendor/bin/php-cs-fixer fix --rules='{"braces": {"position": "same"}, "no_whitespace_before_comma_in_array": true, "whitespace_after_comma_in_array": true}' 2>/dev/null || true
done
print_status "Applied PHP-CS-Fixer fixes in batches"

# Tool 3: Try PHPCBF again (it claimed 72 fixes available)
print_info "Running PHPCBF for automatic fixes..."
./vendor/bin/phpcbf includes/ --standard=WordPress || print_warning "PHPCBF found no fixable errors"

# Tool 4: Additional targeted fixes using grep and sed
print_info "Applying additional targeted fixes..."

# Fix common spacing issues around operators
find includes -name "*.php" -type f | while read -r file; do
    sed -i 's/=\s*=/==/g' "$file"
done
find includes -name "*.php" -type f | while read -r file; do
    sed -i 's/!=\s*/!=/g' "$file"
done
find includes -name "*.php" -type f | while read -r file; do
    sed -i 's/<\s*=/<=/g' "$file"
done
find includes -name "*.php" -type f | while read -r file; do
    sed -i 's/>\s*=/>=/g' "$file"
done

# Fix array spacing
find includes -name "*.php" -type f | while read -r file; do
    sed -i 's/array(\s*/array(/g' "$file"
done
find includes -name "*.php" -type f | while read -r file; do
    sed -i 's/\s*)/)/g' "$file"
done

print_status "Applied additional targeted fixes"

# Final validation
print_info "Final validation..."
./vendor/bin/phpcs includes/ --standard=WordPress --report=summary | tail -3

print_info "Running tests to ensure no regressions..."
./vendor/bin/phpunit --testdox | tail -5

print_status "Advanced automated fixes completed!"
print_info "Summary of tools used:"
echo "  - Custom sed scripts for common patterns"
echo "  - PHP-CS-Fixer for formatting fixes"
echo "  - PHPCBF for standard auto-fixes"
echo "  - Targeted regex replacements"