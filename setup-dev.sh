#!/bin/bash

# puntWork Development Environment Setup
# This script sets up the complete development environment using Docker

set -e

echo "🚀 Setting up puntWork Development Environment"
echo "=============================================="

# Check if Docker is installed
if ! command -v docker &> /dev/null; then
    echo "❌ Docker is not installed. Please install Docker first."
    echo "   Visit: https://docs.docker.com/get-docker/"
    exit 1
fi

# Check if Docker Compose is installed
if ! command -v docker-compose &> /dev/null && ! docker compose version &> /dev/null; then
    echo "❌ Docker Compose is not installed. Please install Docker Compose first."
    echo "   Visit: https://docs.docker.com/compose/install/"
    exit 1
fi

# Create .env file if it doesn't exist
if [ ! -f .env ]; then
    echo "📝 Creating .env file from .env.example..."
    cp .env.example .env
    echo "✅ .env file created. Please edit it with your development settings."
else
    echo "✅ .env file already exists."
fi

# Create necessary directories
echo "📁 Creating development directories..."
mkdir -p wp-content/uploads
mkdir -p wp-content/plugins
mkdir -p cache
mkdir -p docker/mysql/init

# Set proper permissions
echo "🔐 Setting directory permissions..."
chmod 755 wp-content/uploads
chmod 755 cache

# Build and start containers
echo "🐳 Building and starting Docker containers..."
if command -v docker-compose &> /dev/null; then
    docker-compose up -d --build
else
    docker compose up -d --build
fi

# Wait for services to be ready
echo "⏳ Waiting for services to start..."
sleep 10

# Install WordPress if not already installed
echo "📦 Installing WordPress..."
if command -v docker-compose &> /dev/null; then
    docker-compose exec wordpress wp core install \
        --url="http://localhost:8080" \
        --title="puntWork Development" \
        --admin_user="admin" \
        --admin_password="admin123" \
        --admin_email="admin@example.com" \
        --skip-email \
        --allow-root || echo "WordPress may already be installed."
else
    docker compose exec wordpress wp core install \
        --url="http://localhost:8080" \
        --title="puntWork Development" \
        --admin_user="admin" \
        --admin_password="admin123" \
        --admin_email="admin@example.com" \
        --skip-email \
        --allow-root || echo "WordPress may already be installed."
fi

# Activate puntWork plugin
echo "🔌 Activating puntWork plugin..."
if command -v docker-compose &> /dev/null; then
    docker-compose exec wordpress wp plugin activate puntwork --allow-root || echo "Plugin may already be activated."
else
    docker compose exec wordpress wp plugin activate puntwork --allow-root || echo "Plugin may already be activated."
fi

# Install Composer dependencies
echo "📚 Installing Composer dependencies..."
if command -v docker-compose &> /dev/null; then
    docker-compose exec wordpress composer install --no-interaction
else
    docker compose exec wordpress composer install --no-interaction
fi

echo ""
echo "🎉 Development environment setup complete!"
echo "=========================================="
echo ""
echo "🌐 Services available at:"
echo "   WordPress:     http://localhost:8080"
echo "   PHPMyAdmin:    http://localhost:8081"
echo "   MailHog:       http://localhost:8025"
echo "   Redis:         localhost:6379"
echo ""
echo "👤 WordPress Admin:"
echo "   Username: admin"
echo "   Password: admin123"
echo "   Email:    admin@example.com"
echo ""
echo "🛠️  Development Commands:"
echo "   Start:  docker-compose up -d"
echo "   Stop:   docker-compose down"
echo "   Logs:   docker-compose logs -f wordpress"
echo "   Shell:  docker-compose exec wordpress bash"
echo "   WP-CLI: docker-compose exec wordpress wp --allow-root"
echo ""
echo "📖 Next steps:"
echo "   1. Visit http://localhost:8080 to access WordPress"
echo "   2. Visit http://localhost:8081 to access PHPMyAdmin"
echo "   3. Check http://localhost:8025 for email testing"
echo "   4. Run tests: docker-compose exec wordpress ./vendor/bin/phpunit"
echo ""
echo "🔧 For debugging:"
echo "   - XDebug is configured for VS Code"
echo "   - Redis is available for caching"
echo "   - All logs are available via docker-compose logs"
echo ""