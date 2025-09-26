# How to Configure puntWork API Settings

## Step-by-Step Guide

### 1. Access API Settings in WordPress Admin

After uploading and activating the puntWork plugin:

1. **Log into WordPress Admin Dashboard**
   - Go to `https://your-site.com/wp-admin/`
   - Login with your admin credentials

2. **Navigate to API Settings**
   - In the left sidebar, look for the **".work"** menu (with the custom icon)
   - Click on **".work"** → **"API"** submenu
   - Or directly visit: `https://your-site.com/wp-admin/admin.php?page=puntwork-api-settings`

### 2. Configure the API Key

On the API Settings page:

1. **View Current API Key**
   - The API key is automatically generated when you first visit the page
   - Click **"Show/Hide"** to reveal/hide the key
   - Click **"Copy"** to copy the key to clipboard

2. **Set the API Key from .env**
   - Copy the API key from your `.env` file: `etlBBlm0DdUftcafHbbkrof0EOnQSyZg`
   - **Important**: The plugin automatically generates its own key, but you need to ensure it matches your `.env` file
   - If they don't match, click **"Regenerate API Key"** until you get the correct key, or manually update the WordPress option

3. **Manual API Key Configuration** (if needed)
   - You can manually set the API key using WordPress options
   - Go to **Tools** → **WP-CLI** (if available) or use a custom plugin
   - Or add this code to your theme's `functions.php` temporarily:
   ```php
   update_option('puntwork_api_key', 'etlBBlm0DdUftcafHbbkrof0EOnQSyZg');
   ```

### 3. Verify WordPress REST API is Enabled

WordPress REST API is enabled by default in WordPress 4.7+, but let's verify:

1. **Check REST API Status**
   - Visit: `https://your-site.com/wp-json/`
   - You should see a JSON response with WordPress API information
   - If you get a 404 or error, REST API might be disabled

2. **Enable REST API** (if disabled)
   - **Via wp-config.php**: Add this line to your `wp-config.php`:
     ```php
     define('WP_DEBUG', true); // Already in your .env
     // REST API is enabled by default, but ensure no plugins disable it
     ```
   - **Check for conflicting plugins**: Some security plugins might disable REST API
   - **Permalinks**: Ensure pretty permalinks are enabled
     - Go to **Settings** → **Permalinks**
     - Select any structure except "Plain" (recommended: "Post name")

3. **Test REST API Access**
   ```bash
   curl -X GET "https://your-site.com/wp-json/" -H "Content-Type: application/json"
   ```
   Should return JSON with API endpoints.

### 4. Test the puntWork API

Once configured:

1. **Test Import Status**
   ```bash
   curl -X GET "https://your-site.com/wp-json/puntwork/v1/import-status?api_key=etlBBlm0DdUftcafHbbkrof0EOnQSyZg" \
     -H "Content-Type: application/json"
   ```

2. **Test Trigger Import**
   ```bash
   curl -X POST "https://your-site.com/wp-json/puntwork/v1/trigger-import" \
     -H "Content-Type: application/json" \
     -d '{"api_key":"etlBBlm0DdUftcafHbbkrof0EOnQSyZg","test_mode":true}'
   ```

### 5. Troubleshooting

#### API Key Issues
- **401 Unauthorized**: API key doesn't match
- **403 Forbidden**: API key not configured in WordPress options
- **Solution**: Regenerate key in admin or manually set option

#### REST API Issues
- **404 on /wp-json/**: REST API disabled
- **404 on puntwork endpoints**: Plugin not activated
- **500 errors**: Plugin code issues

#### Plugin Not Showing
- **Menu not visible**: Plugin not activated
- **Page not loading**: Check for PHP errors in logs

### 6. Security Best Practices

1. **Keep API key secure**: Never share it publicly
2. **Use HTTPS**: Always make API calls over HTTPS
3. **Regular rotation**: Change API keys periodically
4. **Monitor logs**: Check WordPress logs for suspicious activity
5. **Rate limiting**: Built-in protection against abuse

### 7. Quick Verification Script

Create a test file to verify everything is working:

```php
<?php
// test-api.php - Upload to WordPress root temporarily
$api_key = 'etlBBlm0DdUftcafHbbkrof0EOnQSyZg';
$site_url = 'https://your-site.com';

// Test WordPress REST API
$wp_api = file_get_contents($site_url . '/wp-json/');
echo "WordPress API: " . (strpos($wp_api, 'routes') ? "WORKING" : "FAILED") . "\n";

// Test puntWork API
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => 'Content-Type: application/json'
    ]
]);
$response = file_get_contents($site_url . '/wp-json/puntwork/v1/import-status?api_key=' . $api_key, false, $context);
echo "puntWork API: " . (strpos($response, 'success') ? "WORKING" : "FAILED") . "\n";
echo "Response: " . substr($response, 0, 200) . "...\n";
```

### 8. Alternative: WP-CLI Commands

If you have WP-CLI access:

```bash
# Check if plugin is active
wp plugin list | grep puntwork

# Get current API key
wp option get puntwork_api_key

# Set API key manually
wp option set puntwork_api_key 'etlBBlm0DdUftcafHbbkrof0EOnQSyZg'

# Test REST API
wp rest list
```

### 9. Common Issues & Solutions

| Issue | Symptom | Solution |
|-------|---------|----------|
| Plugin not visible | No ".work" menu | Activate plugin in Plugins page |
| API 404 | Endpoint not found | Check plugin activation, permalinks |
| API 401/403 | Auth failed | Verify API key matches |
| API 500 | Server error | Check PHP logs, plugin code |
| REST API disabled | 404 on /wp-json/ | Enable permalinks, check security plugins |

### 10. Success Checklist

- [ ] Plugin uploaded and activated
- [ ] ".work" → "API" menu visible
- [ ] API key displayed in admin
- [ ] API key matches .env file
- [ ] /wp-json/ returns JSON
- [ ] Import status endpoint works
- [ ] Trigger import endpoint works
- [ ] Test mode imports work without errors

Once all items are checked, your puntWork API is ready for production use!