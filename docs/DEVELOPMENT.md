# puntWork Development Environment

This guide covers setting up and using the puntWork development environment with Docker.

## Quick Start

1. **Clone the repository**
   ```bash
   git clone <repository-url>
   cd puntwork
   ```

2. **Run the setup script**
   ```bash
   ./setup-dev.sh
   ```

3. **Access the development environment**
   - WordPress: http://localhost:8080
   - PHPMyAdmin: http://localhost:8081
   - MailHog: http://localhost:8025

## Services

### WordPress (Port 8080)
- WordPress with puntWork plugin pre-installed
- XDebug configured for debugging
- WP-CLI available
- Composer installed

### MySQL (Port 3306)
- MySQL 8.0 with utf8mb4 charset
- Database: `wordpress`
- User: `wordpress` / Password: `wordpress`
- Root password: `root`

### PHPMyAdmin (Port 8081)
- Web interface for MySQL database management
- Pre-configured to connect to the MySQL container

### Redis (Port 6379)
- Redis 7 with persistence enabled
- Used for caching by the puntWork plugin

### MailHog (Ports 1025/8025)
- SMTP server on port 1025
- Web interface on port 8025 for viewing sent emails

## Development Workflow

### Starting the Environment
```bash
# Start all services
docker-compose up -d

# Start with rebuild (after Dockerfile changes)
docker-compose up -d --build
```

### Stopping the Environment
```bash
# Stop all services
docker-compose down

# Stop and remove volumes (⚠️ destroys database data)
docker-compose down -v
```

### Accessing Containers

#### WordPress Container Shell
```bash
docker-compose exec wordpress bash
```

#### WP-CLI Commands
```bash
# Access WP-CLI
docker-compose exec wordpress wp --allow-root

# Example: List users
docker-compose exec wordpress wp user list --allow-root

# Example: Activate plugin
docker-compose exec wordpress wp plugin activate puntwork --allow-root
```

#### Database Access
```bash
# MySQL command line
docker-compose exec db mysql -u wordpress -pwordpress wordpress

# Or use PHPMyAdmin at http://localhost:8081
```

### Running Tests

#### PHPUnit Tests
```bash
# Run all tests
docker-compose exec wordpress ./vendor/bin/phpunit

# Run specific test file
docker-compose exec wordpress ./vendor/bin/phpunit tests/ImportTest.php

# Run with coverage
docker-compose exec wordpress ./vendor/bin/phpunit --coverage-html coverage
```

#### API Tests
```bash
# Run API verification script
docker-compose exec wordpress php api-verify.php

# Run comprehensive API tests
docker-compose exec wordpress php tests/comprehensive-api-test.php
```

### Debugging

#### XDebug Configuration
XDebug is pre-configured for VS Code debugging:

1. Install the PHP Debug extension in VS Code
2. Create a `.vscode/launch.json` file:
   ```json
   {
     "version": "0.2.0",
     "configurations": [
       {
         "name": "Listen for XDebug",
         "type": "php",
         "request": "launch",
         "port": 9003,
         "pathMappings": {
           "/var/www/html/wp-content/plugins/puntwork": "${workspaceFolder}"
         }
       }
     ]
   }
   ```
3. Set breakpoints in your code
4. Start debugging in VS Code
5. Trigger the code (via web request or WP-CLI)

#### Logs

```bash
# View all logs
docker-compose logs -f

# View WordPress logs
docker-compose logs -f wordpress

# View MySQL logs
docker-compose logs -f db

# View Redis logs
docker-compose logs -f redis

# WordPress debug logs
docker-compose exec wordpress tail -f /var/www/html/wp-content/debug.log
```

### Code Changes

The plugin code is mounted as a volume, so changes are reflected immediately:

- Plugin files: `./` → `/var/www/html/wp-content/plugins/puntwork`
- WordPress content: `./wp-content/` → `/var/www/html/wp-content`

### Database Management

#### Creating Database Backups
```bash
# Create backup
docker-compose exec db mysqldump -u wordpress -pwordpress wordpress > backup.sql

# Restore backup
docker-compose exec -T db mysql -u wordpress -pwordpress wordpress < backup.sql
```

#### Resetting the Database
```bash
# Stop WordPress container
docker-compose stop wordpress

# Drop and recreate database
docker-compose exec db mysql -u root -proot -e "DROP DATABASE wordpress; CREATE DATABASE wordpress;"

# Restart WordPress
docker-compose start wordpress

# Reinstall WordPress
docker-compose exec wordpress wp core install \
  --url="http://localhost:8080" \
  --title="puntWork Development" \
  --admin_user="admin" \
  --admin_password="admin123" \
  --admin_email="admin@example.com" \
  --allow-root
```

## Environment Configuration

### .env File
The `.env` file contains development configuration. Key settings:

```bash
# puntWork settings
PUNTWORK_DEBUG=true
PUNTWORK_TEST_MODE=false

# WordPress settings
WP_DEBUG=true
WP_DEBUG_LOG=true
WP_ENV=development
```

### Docker Compose Overrides
Create a `docker-compose.override.yml` file for local customizations:

```yaml
version: '3.8'
services:
  wordpress:
    environment:
      WORDPRESS_DEBUG: 1
      XDEBUG_CONFIG: remote_host=host.docker.internal
```

## Troubleshooting

### Common Issues

#### WordPress Won't Start
```bash
# Check logs
docker-compose logs wordpress

# Check if database is ready
docker-compose ps
docker-compose exec db mysqladmin ping -u wordpress -pwordpress
```

#### Plugin Not Loading
```bash
# Check plugin files
docker-compose exec wordpress ls -la /var/www/html/wp-content/plugins/puntwork/

# Check WordPress error logs
docker-compose exec wordpress tail -f /var/www/html/wp-content/debug.log

# Verify plugin activation
docker-compose exec wordpress wp plugin list --allow-root
```

#### Database Connection Issues
```bash
# Test database connection
docker-compose exec wordpress wp db check --allow-root

# Reset database connection
docker-compose restart wordpress
```

#### Permission Issues
```bash
# Fix file permissions
docker-compose exec wordpress chown -R www-data:www-data /var/www/html/wp-content

# Fix plugin permissions
docker-compose exec wordpress chown -R www-data:www-data /var/www/html/wp-content/plugins/puntwork
```

### Performance Issues

#### Slow Container Startup
- Ensure Docker has enough resources allocated
- Check available disk space
- Clear Docker cache: `docker system prune`

#### Slow WordPress
- Enable Redis caching in WordPress
- Check PHP memory limits
- Monitor resource usage: `docker stats`

## Advanced Configuration

### Custom PHP Configuration
Add custom PHP settings by creating `docker/php.ini`:

```ini
memory_limit = 512M
max_execution_time = 300
upload_max_filesize = 50M
post_max_size = 50M
```

Then mount it in `docker-compose.override.yml`:

```yaml
services:
  wordpress:
    volumes:
      - ./docker/php.ini:/usr/local/etc/php/conf.d/custom.ini
```

### Additional Development Tools

#### Installing Node.js
```bash
docker-compose exec wordpress apt-get update
docker-compose exec wordpress apt-get install -y nodejs npm
```

#### Installing Additional PHP Extensions
```bash
docker-compose exec wordpress docker-php-ext-install <extension-name>
```

### CI/CD Integration

The environment can be used for automated testing:

```yaml
# .github/workflows/test.yml
name: Test
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Build containers
        run: docker-compose up -d --build
      - name: Run tests
        run: docker-compose exec -T wordpress ./vendor/bin/phpunit
```

## Contributing

1. Create a feature branch
2. Make changes
3. Test locally using the Docker environment
4. Run the full test suite
5. Submit a pull request

### Code Standards

- Follow PSR-12 coding standards
- Use type hints and strict typing
- Write comprehensive unit tests
- Update documentation for API changes

## Support

For issues with the development environment:

1. Check the troubleshooting section above
2. Review Docker and container logs
3. Ensure all required ports are available
4. Verify Docker and Docker Compose versions

For plugin-specific issues, refer to the main documentation in `docs/`.