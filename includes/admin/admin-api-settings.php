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
if (! defined('ABSPATH')) {
    exit;
}

/**
 * API Settings page callback
 */
function api_settings_page()
{
    // Handle form submissions
    if (isset($_POST['regenerate_api_key']) && check_admin_referer('puntwork_api_settings')) {
        $new_key = regenerate_api_key();
        echo '<div class="notice notice-success"><p>' . __('API key regenerated successfully!', 'puntwork') . '</p></div>';
    }

    $api_key = get_or_create_api_key();
    $site_url = get_site_url();

    ?>
    <div class="wrap">
        <h1><?php _e('API Settings', 'puntwork'); ?></h1>

        <div class="puntwork-api-settings">
            <div class="puntwork-api-section">
                <h2><?php _e('Remote Import Trigger', 'puntwork'); ?></h2>
                <p><?php _e('Use these endpoints to trigger imports remotely via HTTP requests.', 'puntwork'); ?></p>

                <h3><?php _e('API Key', 'puntwork'); ?></h3>
                <div class="api-key-container">
                    <input type="text" id="api-key-display" value="<?php echo esc_attr($api_key); ?>" readonly class="regular-text">
                    <button type="button" id="toggle-api-key" class="button"><?php _e('Show/Hide', 'puntwork'); ?></button>
                    <button type="button" id="copy-api-key" class="button"><?php _e('Copy', 'puntwork'); ?></button>
                </div>

                <form method="post" style="margin-top: 20px;">
                    <?php wp_nonce_field('puntwork_api_settings'); ?>
                    <input type="submit" name="regenerate_api_key" value="<?php esc_attr_e('Regenerate API Key', 'puntwork'); ?>" class="button button-secondary"
                           onclick="return confirm('<?php esc_js(__('Are you sure? This will invalidate the current API key.', 'puntwork')); ?>');">
                </form>

                <h3><?php _e('API Endpoints', 'puntwork'); ?></h3>
                <div class="endpoint-info">
                    <h4><?php _e('Trigger Import', 'puntwork'); ?></h4>
                    <code>POST <?php echo esc_url($site_url); ?>/wp-json/puntwork/v1/trigger-import</code>

                    <h5><?php _e('Parameters:', 'puntwork'); ?></h5>
                    <ul>
                        <li><code>api_key</code> <?php _e('(required): Your API key', 'puntwork'); ?></li>
                        <li><code>force</code> <?php _e('(optional): Set to', 'puntwork'); ?> <code>true</code> <?php _e('to force import even if one is running', 'puntwork'); ?></li>
                        <li><code>test_mode</code> <?php _e('(optional): Set to', 'puntwork'); ?> <code>true</code> <?php _e('to run in test mode', 'puntwork'); ?></li>
                    </ul>

                    <h5><?php _e('Example cURL:', 'puntwork'); ?></h5>
                    <pre><code>curl -X POST "<?php echo esc_url($site_url); ?>/wp-json/puntwork/v1/trigger-import" \
  -d "api_key=<?php echo esc_attr($api_key); ?>" \
  -d "force=false" \
  -d "test_mode=false"</code></pre>

                    <h4><?php _e('Get Import Status', 'puntwork'); ?></h4>
                    <code>GET <?php echo esc_url($site_url); ?>/wp-json/puntwork/v1/import-status</code>

                    <h5><?php _e('Parameters:', 'puntwork'); ?></h5>
                    <ul>
                        <li><code>api_key</code> <?php _e('(required): Your API key', 'puntwork'); ?></li>
                    </ul>

                    <h5><?php _e('Example cURL:', 'puntwork'); ?></h5>
                    <pre><code>curl "<?php echo esc_url($site_url); ?>/wp-json/puntwork/v1/import-status?api_key=<?php echo esc_attr($api_key); ?>"</code></pre>
                </div>

                <h3><?php _e('Security Notes', 'puntwork'); ?></h3>
                <div class="security-notes">
                    <ul>
                        <li><strong><?php _e('Keep your API key secure', 'puntwork'); ?></strong> - <?php _e('Store it safely and never share it publicly', 'puntwork'); ?></li>
                        <li><strong><?php _e('Use HTTPS', 'puntwork'); ?></strong> - <?php _e('Always use HTTPS when making API requests', 'puntwork'); ?></li>
                        <li><strong><?php _e('Rate limiting', 'puntwork'); ?></strong> - <?php _e('The API includes built-in rate limiting to prevent abuse', 'puntwork'); ?></li>
                        <li><strong><?php _e('Logging', 'puntwork'); ?></strong> - <?php _e('All API requests are logged for security monitoring', 'puntwork'); ?></li>
                        <li><strong><?php _e('Test mode', 'puntwork'); ?></strong> - <?php _e('Use test_mode=true for testing without affecting live data', 'puntwork'); ?></li>
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
            background: #2d3748;
            color: #e2e8f0;
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
                copyBtn.text('<?php echo esc_js(__('Copied!', 'puntwork')); ?>');
                setTimeout(function() {
                    copyBtn.text(originalText);
                }, 2000);
            });
        });
    </script>
    <?php
}