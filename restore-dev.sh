#!/bin/bash

# Development environment restoration script for puntWork
# This script restores the development environment after production deployment

echo "🔄 Restoring puntWork development environment..."

# Switch composer.json symlink back to development version
if [ -f composer.json.development ]; then
    ln -sf composer.json.development composer.json
    echo "✅ Switched composer.json to development version"
else
    echo "⚠️  composer.json.development not found, creating symlink to production version"
    ln -sf composer.json.production composer.json
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