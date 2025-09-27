#!/bin/bash

# Development environment restoration script for puntWork
# This script restores the development environment after production deployment

echo "🔄 Restoring puntWork development environment..."

# Restore original composer.json
if [ -f composer.json.development ]; then
    cp composer.json.development composer.json
    rm composer.json.development
    echo "✅ Restored development composer.json"
else
    echo "⚠️  composer.json.development not found, composer.json may already be correct"
fi

# Install all dependencies (including dev)
echo "📦 Installing all dependencies (including dev)..."
composer install --optimize-autoloader --no-progress --prefer-dist

echo "✅ Development environment restored!"
echo ""
echo "Development environment is ready with:"
echo "- composer.json (with dev dependencies)"
echo "- vendor/ (all dependencies)"
echo "- All development files available"