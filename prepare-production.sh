#!/bin/bash

# Production deployment preparation script for puntWork
# This script prepares the codebase for production deployment by:
# 1. Switching composer.json symlink to production version (no dev dependencies)
# 2. Installing only production dependencies
# 3. Optimizing autoloader

echo "🚀 Preparing puntWork for production deployment..."

# Switch composer.json symlink to production version
if [ -f composer.json.production ]; then
    ln -sf composer.json.production composer.json
    echo "✅ Switched composer.json to production version (no dev dependencies)"
else
    echo "❌ composer.json.production not found!"
    exit 1
fi

# Install production dependencies only
echo "📦 Installing production dependencies..."
composer install --no-dev --optimize-autoloader --no-progress --prefer-dist

# Clean up any dev-only files that might have been included
echo "🧹 Cleaning up development files..."
rm -f phpunit.xml
rm -f .phpunit.cache
rm -rf tests/
rm -rf .github/

echo "✅ Production deployment preparation complete!"
echo ""
echo "Ready for deployment. The following files are now production-ready:"
echo "- composer.json (production version - no dev dependencies)"
echo "- vendor/ (production dependencies only)"
echo "- All development files removed"
echo ""
echo "To restore development environment, run: ./restore-dev.sh"