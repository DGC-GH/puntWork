<?php
/**
 * API Settings admin page
 *
 * @package    Puntwork
 * @subpackage Admin
 * @since      1.0.7
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * API Settings page callback
 */
function api_settings_page() {
    // Handle form submissions
    if (isset($_POST['regenerate_api_key']) && check_admin_referer('puntwork_api_settings')) {
        $new_key = regenerate_api_key();
        echo '<div class="notice notice-success"><p>API key regenerated successfully!</p></div>';
    }

    $api_key = get_or_create_api_key();
    $site_url = get_site_url();

    ?>
    <div class="wrap">
        <h1>API Settings</h1>

        <div class="puntwork-api-settings">
            <div class="puntwork-api-section">
                <h2>Remote Import Trigger</h2>
                <p>Use these endpoints to trigger imports remotely via HTTP requests.</p>

                <h3>API Key</h3>
                <div class="api-key-container">
                    <input type="text" id="api-key-display" value="<?php echo esc_attr($api_key); ?>" readonly class="regular-text">
                    <button type="button" id="toggle-api-key" class="button">Show/Hide</button>
                    <button type="button" id="copy-api-key" class="button">Copy</button>
                </div>

                <form method="post" style="margin-top: 20px;">
                    <?php wp_nonce_field('puntwork_api_settings'); ?>
                    <input type="submit" name="regenerate_api_key" value="Regenerate API Key" class="button button-secondary"
                           onclick="return confirm('Are you sure? This will invalidate the current API key.');">
                </form>

                <h3>API Endpoints</h3>
                <div class="endpoint-info">
                    <h4>Trigger Import</h4>
                    <code>POST <?php echo esc_url($site_url); ?>/wp-json/puntwork/v1/trigger-import</code>

                    <h5>Parameters:</h5>
                    <ul>
                        <li><code>api_key</code> (required): Your API key</li>
                        <li><code>force</code> (optional): Set to <code>true</code> to force import even if one is running</li>
                        <li><code>test_mode</code> (optional): Set to <code>true</code> to run in test mode</li>
                    </ul>

                    <h5>Example cURL:</h5>
                    <pre><code>curl -X POST "<?php echo esc_url($site_url); ?>/wp-json/puntwork/v1/trigger-import" \
  -d "api_key=<?php echo esc_attr($api_key); ?>" \
  -d "force=false" \
  -d "test_mode=false"</code></pre>

                    <h4>Get Import Status</h4>
                    <code>GET <?php echo esc_url($site_url); ?>/wp-json/puntwork/v1/import-status</code>

                    <h5>Parameters:</h5>
                    <ul>
                        <li><code>api_key</code> (required): Your API key</li>
                    </ul>

                    <h5>Example cURL:</h5>
                    <pre><code>curl "<?php echo esc_url($site_url); ?>/wp-json/puntwork/v1/import-status?api_key=<?php echo esc_attr($api_key); ?>"</code></pre>
                </div>

                <h3>Security Notes</h3>
                <div class="security-notes">
                    <ul>
                        <li><strong>Keep your API key secure</strong> - Store it safely and never share it publicly</li>
                        <li><strong>Use HTTPS</strong> - Always use HTTPS when making API requests</li>
                        <li><strong>Rate limiting</strong> - The API includes built-in rate limiting to prevent abuse</li>
                        <li><strong>Logging</strong> - All API requests are logged for security monitoring</li>
                        <li><strong>Test mode</strong> - Use test_mode=true for testing without affecting live data</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <style>
        .puntwork-api-settings {
            max-width: 800px;
        }
        .api-key-container {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 20px;
        }
        .api-key-container input {
            flex: 1;
        }
        .endpoint-info {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .endpoint-info h4 {
            margin-top: 0;
            color: #23282d;
        }
        .endpoint-info code {
            background: #e1e1e1;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }
        .endpoint-info pre {
            background: #2d3748;
            color: #e2e8f0;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
        }
        .endpoint-info ul {
            margin: 10px 0;
        }
        .security-notes {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            padding: 15px;
            border-radius: 5px;
        }
        .security-notes ul {
            margin: 0;
        }
    </style>

    <script>
        jQuery(document).ready(function($) {
            const apiKeyInput = $('#api-key-display');
            const toggleBtn = $('#toggle-api-key');
            const copyBtn = $('#copy-api-key');

            // Initially hide the API key
            apiKeyInput.attr('type', 'password');

            toggleBtn.on('click', function() {
                const isPassword = apiKeyInput.attr('type') === 'password';
                apiKeyInput.attr('type', isPassword ? 'text' : 'password');
            });

            copyBtn.on('click', function() {
                apiKeyInput.select();
                document.execCommand('copy');

                const originalText = copyBtn.text();
                copyBtn.text('Copied!');
                setTimeout(function() {
                    copyBtn.text(originalText);
                }, 2000);
            });
        });
    </script>
    <?php
}