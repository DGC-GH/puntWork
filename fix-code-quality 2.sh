#!/bin/bash

# puntWork Code Quality Automation Script
# Automates common code quality fixes to reduce manual work

echo "🔧 Starting automated code quality fixes..."

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

# Fix 1: Replace json_encode with wp_json_encode (safe replacement)
print_info "Fixing json_encode -> wp_json_encode replacements..."
find includes -name "*.php" -type f -print0 | xargs -0 sed -i 's/json_encode(/wp_json_encode(/g'
print_status "Replaced json_encode with wp_json_encode"

# Fix 2: Remove unnecessary whitespace at end of lines (safe)
print_info "Removing trailing whitespace..."
find includes -name "*.php" -type f -print0 | xargs -0 sed -i 's/[[:space:]]*$//'
print_status "Removed trailing whitespace"

# Fix 3: Fix spacing in function calls (remove extra spaces after opening paren)
print_info "Fixing spacing in function calls..."
find includes -name "*.php" -type f -print0 | xargs -0 sed -i 's/(\s\+/(/g'
print_status "Fixed spacing in function calls"

# Fix 4: Fix inline comments that don't end with punctuation (more targeted)
print_info "Fixing inline comments to end with punctuation..."
find includes -name "*.php" -type f -print0 | xargs -0 sed -i 's|// \([a-zA-Z0-9_ ]*[a-zA-Z0-9_]\)$|// \1.|g'
find includes -name "*.php" -type f -print0 | xargs -0 sed -i 's|// \([a-zA-Z0-9_ ]*[a-zA-Z0-9_]\)\([.!?]\)$|\1\2|g' # Avoid double punctuation
print_status "Fixed inline comments punctuation"

# Fix 5: Remove blank lines before file comments (safer approach)
print_info "Removing blank lines before file comments..."
for file in $(find includes -name "*.php" -type f); do
    # Check if file starts with blank lines before /*
    if head -5 "$file" 2>/dev/null | grep -q '^/\*'; then
        # Remove blank lines before the first /*
        sed -i '/./,/^\/\*/!d' "$file"
    fi
done
print_status "Cleaned up file comment spacing"

# Fix 6: Basic method name fixes (camelCase to snake_case for simple cases)
print_info "Fixing basic method naming (camelCase to snake_case)..."
# This is very risky - only do simple cases and skip for now
print_status "Skipped method naming fixes (too complex for automation)"

print_info "Running PHPCS to check improvements..."
./vendor/bin/phpcs includes/ --standard=WordPress --report=summary | tail -3

print_info "Running PHPUnit to ensure no regressions..."
./vendor/bin/phpunit --testdox | tail -5

print_status "Automated fixes completed! Manual review of remaining issues recommended."