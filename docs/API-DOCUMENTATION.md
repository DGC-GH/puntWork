# puntWork API Documentation

## Overview

puntWork is a comprehensive WordPress plugin for importing jobs from XML, JSON, and CSV feeds. This API documentation provides complete reference for all REST API endpoints, AJAX endpoints, and integration methods.

## Features

- **Multi-format Feed Support**: XML, JSON, and CSV feeds
- **Advanced Analytics**: Comprehensive import tracking and reporting
- **Feed Health Monitoring**: Real-time monitoring and alerting
- **Smart Deduplication**: Advanced algorithms to prevent duplicate jobs
- **Security**: Comprehensive validation and rate limiting
- **Scheduling**: Automated imports with cron support

## Authentication

### WordPress REST API

All REST API endpoints require WordPress authentication:

```bash
# Using Application Passwords (recommended)
curl -X GET "https://your-site.com/wp-json/puntwork/v1/import/status" \
  -u "username:application_password"

# Using Basic Auth (development only)
curl -X GET "https://your-site.com/wp-json/puntwork/v1/import/status" \
  -u "admin:password"
```

### WordPress Nonces (Admin AJAX)

For admin AJAX endpoints, include the WordPress nonce:

```javascript
const data = {
    action: 'puntwork_import_control',
    nonce: wpApiSettings.nonce, // WordPress provides this
    command: 'start'
};

fetch(ajaxurl, {
    method: 'POST',
    headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: new URLSearchParams(data)
});
```

## REST API Endpoints

### Import Management

#### Trigger Import
```http
POST /wp-json/puntwork/v1/import/trigger
```

Trigger a new job import process.

**Request Body:**
```json
{
  "feed_key": "indeed-jobs",    // Optional: specific feed
  "force": false                // Force import even if running
}
```

**Response:**
```json
{
  "success": true,
  "message": "Import triggered successfully",
  "import_id": "import_123456",
  "timestamp": "2024-12-15T10:30:00Z"
}
```

#### Get Import Status
```http
GET /wp-json/puntwork/v1/import/status
```

Get current import progress and statistics.

**Response:**
```json
{
  "status": "running",
  "progress": {
    "current": 150,
    "total": 500,
    "percentage": 30.0
  },
  "statistics": {
    "published": 120,
    "updated": 25,
    "skipped": 5,
    "duplicates": 3
  },
  "current_feed": "indeed-jobs",
  "start_time": "2024-12-15T10:30:00Z",
  "estimated_completion": "2024-12-15T10:45:00Z"
}
```

### Feed Management

#### List Feeds
```http
GET /wp-json/puntwork/v1/feeds
```

Get all configured feed URLs and settings.

**Response:**
```json
{
  "feeds": {
    "indeed-jobs": "https://api.indeed.com/rss/jobs?q=developer",
    "monster-jobs": "https://www.monster.com/rss/jobs.xml",
    "json-feed": "https://api.example.com/jobs.json"
  }
}
```

### Analytics

#### Get Analytics Summary
```http
GET /wp-json/puntwork/v1/analytics/summary?period=30days
```

Get import analytics for the specified period.

**Parameters:**
- `period`: `7days`, `30days`, `90days` (default: `30days`)

**Response:**
```json
{
  "period": "30days",
  "overview": {
    "total_imports": 45,
    "total_processed": 1250,
    "avg_success_rate": 94.2,
    "avg_duration": 45.8
  },
  "performance": [
    {
      "trigger_type": "scheduled",
      "count": 30,
      "avg_duration": 42.5,
      "avg_success_rate": 96.1
    }
  ]
}
```

#### Export Analytics
```http
GET /wp-json/puntwork/v1/analytics/export?period=30days
```

Download analytics data as CSV file.

### Health Monitoring

#### Get Feed Health
```http
GET /wp-json/puntwork/v1/health/feeds
```

Get health status of all feeds.

**Response:**
```json
{
  "last_check": "2024-12-15T10:00:00Z",
  "feeds": {
    "indeed-jobs": {
      "status": "healthy",
      "response_time": 1.2,
      "last_successful": "2024-12-15T10:00:00Z",
      "error_count": 0,
      "items_found": 150
    }
  }
}
```

## AJAX Endpoints

### Import Control
```javascript
// Start import
wp.ajax.post('puntwork_import_control', {
    command: 'start',
    feed_key: 'optional-feed-key'
});

// Stop import
wp.ajax.post('puntwork_import_control', {
    command: 'stop'
});

// Get status
wp.ajax.post('puntwork_import_control', {
    command: 'status'
});
```

### Database Optimization
```javascript
// Run optimization
wp.ajax.post('puntwork_db_optimize', {
    operations: ['indexes', 'cleanup']
});
```

### Feed Health
```javascript
// Get health status
wp.ajax.post('puntwork_feed_health', {
    action: 'get_status'
});

// Configure alerts
wp.ajax.post('puntwork_feed_health', {
    action: 'update_alerts',
    email: 'admin@example.com',
    alerts: ['down', 'slow', 'empty']
});
```

## Integration Examples

### PHP Integration

```php
// Trigger import programmatically
$import_result = wp_remote_post(
    rest_url('puntwork/v1/import/trigger'),
    [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode('username:password')
        ],
        'body' => json_encode([
            'feed_key' => 'indeed-jobs'
        ])
    ]
);

// Get analytics data
$analytics = wp_remote_get(
    rest_url('puntwork/v1/analytics/summary?period=7days'),
    [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode('username:password')
        ]
    ]
);
```

### JavaScript Integration

```javascript
// Using fetch with WordPress nonce
const triggerImport = async (feedKey = null) => {
    const response = await fetch('/wp-json/puntwork/v1/import/trigger', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': wpApiSettings.nonce
        },
        body: JSON.stringify({
            feed_key: feedKey,
            force: false
        })
    });

    const result = await response.json();
    console.log('Import triggered:', result);
};

// Monitor import progress
const monitorImport = () => {
    setInterval(async () => {
        const response = await fetch('/wp-json/puntwork/v1/import/status');
        const status = await response.json();

        updateProgressUI(status);
    }, 2000); // Check every 2 seconds
};
```

### Cron Job Integration

```bash
# Daily import via WP-CLI
wp puntwork import trigger

# Import specific feed
wp puntwork import trigger --feed=indeed-jobs

# Check import status
wp puntwork import status
```

## Feed Configuration

### Supported Formats

#### XML Feeds
```xml
<?xml version="1.0" encoding="UTF-8"?>
<jobs>
  <item>
    <title>Software Developer</title>
    <company>Tech Corp</company>
    <location>New York, NY</location>
    <description>Great opportunity...</description>
  </item>
</jobs>
```

#### JSON Feeds
```json
{
  "jobs": [
    {
      "title": "Software Developer",
      "company": "Tech Corp",
      "location": "New York, NY",
      "description": "Great opportunity..."
    }
  ]
}
```

#### CSV Feeds
```csv
title,company,location,description
"Software Developer","Tech Corp","New York, NY","Great opportunity..."
"Product Manager","Biz Inc","San Francisco, CA","Exciting role..."
```

### Feed Settings

Configure feeds in WordPress admin under **puntWork > API Settings**:

```php
// Add feeds programmatically
update_option('job_feeds', [
    'indeed-jobs' => 'https://api.indeed.com/rss/jobs?q=developer',
    'custom-json' => 'https://api.example.com/jobs.json'
]);
```

## Error Handling

### Common HTTP Status Codes

- `200`: Success
- `400`: Bad request (invalid parameters)
- `403`: Forbidden (insufficient permissions)
- `409`: Conflict (import already running)
- `500`: Internal server error

### Error Response Format

```json
{
  "code": "import_already_running",
  "message": "An import process is already running",
  "data": {
    "status": "running",
    "started_at": "2024-12-15T10:30:00Z"
  }
}
```

## Rate Limiting

- REST API: 100 requests per hour per IP
- Admin AJAX: 500 requests per hour per user
- Import triggers: 10 per hour to prevent abuse

## Webhooks

Configure webhooks for import events:

```php
add_action('puntwork_import_completed', function($stats) {
    // Send webhook notification
    wp_remote_post('https://your-webhook-url.com', [
        'body' => json_encode([
            'event' => 'import_completed',
            'stats' => $stats
        ])
    ]);
});
```

## Support

- **Documentation**: [GitHub Repository](https://github.com/DGC-GH/puntWork)
- **Issues**: [GitHub Issues](https://github.com/DGC-GH/puntWork/issues)
- **Discussions**: [GitHub Discussions](https://github.com/DGC-GH/puntWork/discussions)

## Changelog

### Version 1.0.15
- Added PSR-4 autoloading
- Enhanced API documentation
- Improved error handling

### Version 1.0.14
- Added advanced job deduplication
- Implemented fuzzy matching algorithms

### Version 1.0.13
- Added JSON and CSV feed support
- Multi-format feed processing

### Version 1.0.12
- Import analytics dashboard
- Performance monitoring

### Version 1.0.11
- Feed health monitoring
- Email alerts system

### Version 1.0.10
- Security enhancements
- Input validation
- Rate limiting