#!/bin/bash

# Production deployment preparation script for puntWork
# This script prepares the codebase for production deployment by:
# 1. Using production composer.json (without dev dependencies)
# 2. Installing only production dependencies
# 3. Optimizing autoloader

echo "🚀 Preparing puntWork for production deployment..."

# Backup original composer.json
if [ -f composer.json ]; then
    cp composer.json composer.json.development
    echo "✅ Backed up development composer.json"
fi

# Use production composer.json
if [ -f composer.json.production ]; then
    cp composer.json.production composer.json
    echo "✅ Switched to production composer.json (no dev dependencies)"
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
echo "- composer.json (production version)"
echo "- vendor/ (production dependencies only)"
echo "- All development files removed"
echo ""
echo "To restore development environment, run: ./restore-dev.sh"