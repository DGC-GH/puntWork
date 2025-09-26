# puntWork Plugin Deployment Guide

## Overview
This guide provides step-by-step instructions for deploying the puntWork WordPress plugin to a live site and testing the REST API endpoints.

## Prerequisites
- WordPress site with admin access
- FTP/SFTP access to upload plugin files
- API key from `.env` file: `etlBBlm0DdUftcafHbbkrof0EOnQSyZg`

## Deployment Steps

### 1. Upload Plugin Files
Upload the entire `puntwork.php` plugin directory to your WordPress `wp-content/plugins/` directory:

```
wp-content/plugins/puntwork/
├── puntwork.php
├── includes/
├── assets/
├── cache/
├── docs/
├── scripts/
├── tests/
└── ...
```

### 2. Activate Plugin
1. Log into WordPress admin dashboard
2. Go to **Plugins** → **Installed Plugins**
3. Find "puntWork" in the list
4. Click **Activate**

### 3. Configure API Key
1. Go to **Settings** → **puntWork API Settings** (or wherever you placed the admin menu)
2. Set the API key to: `etlBBlm0DdUftcafHbbkrof0EOnQSyZg`
3. Save settings

### 4. Verify Plugin Activation
Check that the plugin is properly loaded by visiting:
```
https://your-site.com/wp-json/puntwork/v1/import-status?api_key=etlBBlm0DdUftcafHbbkrof0EOnQSyZg
```

You should receive a JSON response instead of a 404 error.

## Quick Verification

Upload the included `api-verify.php` file to your WordPress root directory and access it in your browser:

```
https://your-site.com/api-verify.php
```

This script will automatically test:
- WordPress REST API connectivity
- puntWork plugin activation
- API key configuration
- Import trigger functionality

**⚠️ Delete this file immediately after testing!** It contains your API key.

## Manual Testing with curl

#### Test Import Status
```bash
curl -X GET "https://your-site.com/wp-json/puntwork/v1/import-status?api_key=etlBBlm0DdUftcafHbbkrof0EOnQSyZg" \
  -H "Content-Type: application/json"
```

#### Test Trigger Import (Test Mode)
```bash
curl -X POST "https://your-site.com/wp-json/puntwork/v1/trigger-import" \
  -H "Content-Type: application/json" \
  -d '{
    "api_key": "etlBBlm0DdUftcafHbbkrof0EOnQSyZg",
    "test_mode": true
  }'
```

#### Test Trigger Import (Force Mode)
```bash
curl -X POST "https://your-site.com/wp-json/puntwork/v1/trigger-import" \
  -H "Content-Type: application/json" \
  -d '{
    "api_key": "etlBBlm0DdUftcafHbbkrof0EOnQSyZg",
    "force": true,
    "test_mode": true
  }'
```

### Automated Testing

Run the comprehensive test suite:
```bash
php tests/comprehensive-api-test.php
```

This will test all endpoints and provide a detailed report.

## Expected Responses

### Successful Import Status Response
```json
{
  "success": true,
  "status": {
    "total": 0,
    "processed": 0,
    "published": 0,
    "updated": 0,
    "skipped": 0,
    "duplicates_drafted": 0,
    "time_elapsed": 0,
    "complete": true,
    "success": false,
    "error_message": "",
    "batch_size": 100,
    "inferred_languages": 0,
    "inferred_benefits": 0,
    "schema_generated": 0,
    "start_time": 1735680000.123,
    "end_time": null,
    "last_update": 1735680000,
    "logs": [],
    "is_running": false,
    "last_run": null,
    "next_scheduled": 1735683600
  }
}
```

### Successful Trigger Import Response
```json
{
  "success": true,
  "message": "Import triggered successfully",
  "data": {
    "processed": 0,
    "total": 0
  }
}
```

## Troubleshooting

### 404 Errors
- Plugin not uploaded correctly
- Plugin not activated
- REST API routes not registered

### 401 Unauthorized
- API key not configured in WordPress options
- API key mismatch

### 500 Internal Server Error
- PHP syntax errors in plugin files
- Missing dependencies
- WordPress/PHP version compatibility issues

### Plugin Not Appearing in Admin
- Files not uploaded to correct directory
- Plugin header in `puntwork.php` malformed

## Security Notes

- Never commit API keys to version control
- Use HTTPS for all API calls
- Regularly rotate API keys
- Monitor API usage logs
- Consider IP whitelisting for production

## Performance Testing

For load testing, use tools like Apache Bench or Siege:

```bash
# Test import status endpoint
ab -n 100 -c 10 "https://your-site.com/wp-json/puntwork/v1/import-status?api_key=YOUR_KEY"

# Test trigger import (use test_mode=true)
ab -n 10 -c 2 -p post_data.json -T application/json "https://your-site.com/wp-json/puntwork/v1/trigger-import"
```

## Monitoring

After deployment, monitor:
- WordPress error logs
- Plugin-specific logs in `wp-content/plugins/puntwork/logs/`
- API response times
- Import success/failure rates

## Rollback Plan

If issues occur:
1. Deactivate plugin in WordPress admin
2. Remove plugin files via FTP
3. Restore from backup if necessary
4. Check error logs for root cause