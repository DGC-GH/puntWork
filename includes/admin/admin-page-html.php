<?php
/**
 * Admin page HTML for job import plugin
 * Main entry point that loads all admin UI components
 *
 * @package    Puntwork
 * @subpackage Admin
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Load admin UI components
require_once __DIR__ . '/admin-ui-main.php';
require_once __DIR__ . '/admin-ui-scheduling.php';
require_once __DIR__ . '/admin-feed-config.php';
require_once __DIR__ . '/onboarding-wizard.php';
require_once __DIR__ . '/accessibility.php';

// Load queue management components
require_once __DIR__ . '/../queue/queue-manager.php';
require_once __DIR__ . '/../queue/queue-ajax.php';

function feeds_dashboard_page() {
    // Remove debug logging for security
    wp_enqueue_script('jquery');

    // Render main import UI
    render_main_import_ui();

    // Render scheduling UI
    render_scheduling_ui();

    // Render import history UI
    render_import_history_ui();

    // Render JavaScript initialization
    render_javascript_init();
}

/**
 * Feed Configuration page callback
 */
function feed_config_page() {
    // Enqueue Sortable library for drag-and-drop
    wp_enqueue_script('jquery-ui-sortable');

    // Render feed configuration UI
    render_feed_config_ui();
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

/**
 * Render JavaScript initialization for the admin page
 */
function render_javascript_init() {
    ?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            console.log('[PUNTWORK] Inline script: Document ready, checking modules...');
            console.log('[PUNTWORK] Inline script: JobImportEvents available:', typeof JobImportEvents);
            console.log('[PUNTWORK] Inline script: JobImportUI available:', typeof JobImportUI);
            console.log('[PUNTWORK] Inline script: JobImportAPI available:', typeof JobImportAPI);
            console.log('[PUNTWORK] Inline script: JobImportLogic available:', typeof JobImportLogic);
            console.log('[PUNTWORK] Inline script: jobImportInitialized:', typeof window.jobImportInitialized);

            // Check if buttons exist
            console.log('[PUNTWORK] Inline script: cleanup-duplicates button exists:', $('#cleanup-duplicates').length);

            // Add a simple test function to global scope
            window.testButtons = function() {
                console.log('[PUNTWORK] Testing buttons...');
                console.log('Cleanup button found:', $('#cleanup-duplicates').length);

                if ($('#cleanup-duplicates').length > 0) {
                    console.log('Cleanup button HTML:', $('#cleanup-duplicates')[0].outerHTML);
                }

                // Test click events
                $('#cleanup-duplicates').trigger('click');
            };

            console.log('[PUNTWORK] Run testButtons() in console to test button functionality');

            // Only initialize if not already initialized
            if (typeof window.jobImportInitialized === 'undefined') {
                console.log('[PUNTWORK] Inline script: Initializing job import system...');

                // Initialize the job import system
                if (typeof JobImportEvents !== 'undefined') {
                    console.log('[PUNTWORK] Inline script: Calling JobImportEvents.init()');
                    JobImportEvents.init();
                } else {
                    console.error('[PUNTWORK] Inline script: JobImportEvents not available!');
                }

                // Initialize UI components
                if (typeof JobImportUI !== 'undefined') {
                    console.log('[PUNTWORK] Inline script: Calling JobImportUI.clearProgress()');
                    JobImportUI.clearProgress();
                }

                // Note: Import status checking is now handled by JobImportEvents.checkInitialStatus()
                // to avoid duplicate checks and potential race conditions

                // Initialize scheduling if available
                if (typeof JobImportScheduling !== 'undefined') {
                    console.log('[PUNTWORK] Inline script: Calling JobImportScheduling.init()');
                    JobImportScheduling.init();
                }

                // Bind refresh button for main import history section
                $('#refresh-history-main').on('click', function(e) {
                    e.preventDefault();
                    console.log('[PUNTWORK] Main history refresh clicked');
                    if (typeof JobImportScheduling !== 'undefined' && typeof JobImportScheduling.loadRunHistory === 'function') {
                        $(this).addClass('manual-refresh');
                        JobImportScheduling.loadRunHistory('#refresh-history-main');
                    }
                });

                // Load import history on page load
                if (typeof JobImportScheduling !== 'undefined' && typeof JobImportScheduling.loadRunHistory === 'function') {
                    console.log('[PUNTWORK] Loading import history on page load');
                    JobImportScheduling.loadRunHistory('#refresh-history-main');
                }

                // Mark as initialized to prevent double initialization
                window.jobImportInitialized = true;
                console.log('[PUNTWORK] Inline script: Admin page JavaScript initialized');
            } else {
                console.log('[PUNTWORK] Inline script: Job import already initialized, skipping...');
            }
        });
    </script>
    <?php
}

function jobs_dashboard_page() {
    error_log('[PUNTWORK] jobs_dashboard_page() called');
    wp_enqueue_script('jquery');

    // Render jobs dashboard UI
    render_jobs_dashboard_ui();

    // Render JavaScript initialization for jobs dashboard
    render_jobs_javascript_init();
}

/**
 * Render the main puntWork dashboard page
 */
function puntwork_dashboard_page() {
    ?>
    <div class="wrap" style="max-width: 1200px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #1d1d1f; padding: 0 20px;">
        <h1 style="font-size: 34px; font-weight: 600; text-align: center; margin: 40px 0 20px;"><?php _e('puntWork Dashboard', 'puntwork'); ?></h1>
        <p style="font-size: 16px; color: #8e8e93; text-align: center; margin-bottom: 40px;"><?php _e('Manage your job feeds and content with ease', 'puntwork'); ?></p>

        <!-- PWA Status Indicator -->
        <div id="pwa-status-indicator" style="display: none; background: linear-gradient(135deg, #007aff 0%, #0056cc 100%); color: white; padding: 12px 20px; border-radius: 12px; text-align: center; margin-bottom: 24px; font-size: 14px; font-weight: 500; box-shadow: 0 2px 8px rgba(0,122,255,0.2);">
            <i class="fas fa-mobile-alt" style="margin-right: 8px;"></i>
            <?php _e('puntWork Admin is now available as a Progressive Web App!', 'puntwork'); ?>
            <button id="pwa-install-btn" style="background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3); color: white; padding: 4px 12px; border-radius: 6px; font-size: 12px; margin-left: 12px; cursor: pointer; transition: background 0.2s ease;" aria-label="<?php esc_attr_e('Install puntWork Admin PWA', 'puntwork'); ?>">
                <?php _e('Install', 'puntwork'); ?>
            </button>
        </div>

        <!-- Overview Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px; margin-bottom: 40px;">
            <!-- Feeds Card -->
            <div style="background-color: white; border-radius: 16px; padding: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); transition: transform 0.2s ease, box-shadow 0.2s ease; cursor: pointer;" onclick="window.location.href='admin.php?page=job-feed-dashboard'">
                <div style="display: flex; align-items: center; margin-bottom: 16px;">
                    <div style="width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg, #007aff, #5856d6); display: flex; align-items: center; justify-content: center; margin-right: 16px;">
                        <span style="font-size: 24px; color: white;">📡</span>
                    </div>
                    <div>
                        <h3 style="font-size: 20px; font-weight: 600; margin: 0;"><?php _e('Job Feeds', 'puntwork'); ?></h3>
                        <p style="font-size: 14px; color: #8e8e93; margin: 4px 0 0;"><?php _e('Import and manage job feeds', 'puntwork'); ?></p>
                    </div>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 14px; color: #8e8e93;"><?php _e('Manage feeds →', 'puntwork'); ?></span>
                    <span style="font-size: 18px; color: #007aff;">→</span>
                </div>
            </div>

            <!-- Jobs Card -->
            <div style="background-color: white; border-radius: 16px; padding: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); transition: transform 0.2s ease, box-shadow 0.2s ease; cursor: pointer;" onclick="window.location.href='admin.php?page=jobs-dashboard'">
                <div style="display: flex; align-items: center; margin-bottom: 16px;">
                    <div style="width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg, #34c759, #30d158); display: flex; align-items: center; justify-content: center; margin-right: 16px;">
                        <span style="font-size: 24px; color: white;">💼</span>
                    </div>
                    <div>
                        <h3 style="font-size: 20px; font-weight: 600; margin: 0;"><?php _e('Jobs', 'puntwork'); ?></h3>
                        <p style="font-size: 14px; color: #8e8e93; margin: 4px 0 0;"><?php _e('View and manage job posts', 'puntwork'); ?></p>
                    </div>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 14px; color: #34c759;"><?php _e('Browse jobs →', 'puntwork'); ?></span>
                    <span style="font-size: 18px; color: #34c759;">→</span>
                </div>
            </div>

            <!-- Feed Config Card -->
            <div style="background-color: white; border-radius: 16px; padding: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); transition: transform 0.2s ease, box-shadow 0.2s ease; cursor: pointer;" onclick="window.location.href='admin.php?page=puntwork-feed-config'">
                <div style="display: flex; align-items: center; margin-bottom: 16px;">
                    <div style="width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg, #ff9500, #ff9f0a); display: flex; align-items: center; justify-content: center; margin-right: 16px;">
                        <span style="font-size: 24px; color: white;">⚙️</span>
                    </div>
                    <div>
                        <h3 style="font-size: 20px; font-weight: 600; margin: 0;"><?php _e('Feed Config', 'puntwork'); ?></h3>
                        <p style="font-size: 14px; color: #8e8e93; margin: 4px 0 0;"><?php _e('Configure and reorder feeds', 'puntwork'); ?></p>
                    </div>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 14px; color: #8e8e93;"><?php _e('Configure feeds →', 'puntwork'); ?></span>
                    <span style="font-size: 18px; color: #ff9500;">→</span>
                </div>
            </div>

            <!-- Scheduling Card -->
            <div style="background-color: white; border-radius: 16px; padding: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); transition: transform 0.2s ease, box-shadow 0.2s ease; cursor: pointer;" onclick="window.location.href='admin.php?page=job-feed-dashboard'">
                <div style="display: flex; align-items: center; margin-bottom: 16px;">
                    <div style="width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg, #af52de, #c25ae7); display: flex; align-items: center; justify-content: center; margin-right: 16px;">
                        <span style="font-size: 24px; color: white;">⏰</span>
                    </div>
                    <div>
                        <h3 style="font-size: 20px; font-weight: 600; margin: 0;"><?php _e('Scheduling', 'puntwork'); ?></h3>
                        <p style="font-size: 14px; color: #8e8e93; margin: 4px 0 0;"><?php _e('Automated import schedules', 'puntwork'); ?></p>
                    </div>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 14px; color: #8e8e93;"><?php _e('Configure schedules →', 'puntwork'); ?></span>
                    <span style="font-size: 18px; color: #af52de;">→</span>
                </div>
            </div>
        </div>

        <!-- Quick Stats -->
        <div style="background-color: white; border-radius: 16px; padding: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 40px;">
            <h3 style="font-size: 24px; font-weight: 600; margin: 0 0 20px;"><?php _e('Quick Overview', 'puntwork'); ?></h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 20px;">
                <div style="text-align: center;">
                    <div style="font-size: 32px; font-weight: 700; color: #007aff; margin-bottom: 8px;">0</div>
                    <div style="font-size: 14px; color: #86868b;"><?php _e('Active Feeds', 'puntwork'); ?></div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 32px; font-weight: 700; color: #34c759; margin-bottom: 8px;">0</div>
                    <div style="font-size: 14px; color: #86868b;"><?php _e('Total Jobs', 'puntwork'); ?></div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 32px; font-weight: 700; color: #ff9500; margin-bottom: 8px;">0</div>
                    <div style="font-size: 14px; color: #86868b;"><?php _e('Scheduled Imports', 'puntwork'); ?></div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 32px; font-weight: 700; color: #ff3b30; margin-bottom: 8px;">0</div>
                    <div style="font-size: 14px; color: #86868b;"><?php _e('Failed Imports', 'puntwork'); ?></div>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div style="background-color: white; border-radius: 16px; padding: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <h3 style="font-size: 24px; font-weight: 600; margin: 0 0 20px;"><?php _e('Recent Activity', 'puntwork'); ?></h3>
            <div style="text-align: center; padding: 40px 20px; color: #8e8e93;">
                <div style="font-size: 48px; margin-bottom: 16px;">📊</div>
                <p style="font-size: 16px; margin: 0;"><?php _e('Activity feed will appear here once you start importing jobs', 'puntwork'); ?></p>
            </div>
        </div>
    </div>

    <!-- Onboarding Modal -->
    <?php render_onboarding_modal(); ?>

    <script>
        // PWA Status Indicator
        document.addEventListener('DOMContentLoaded', function() {
            const pwaIndicator = document.getElementById('pwa-status-indicator');
            const installBtn = document.getElementById('pwa-install-btn');

            // Check if PWA is supported and not already installed
            if ('serviceWorker' in navigator && 'BeforeInstallPromptEvent' in window) {
                // Check if not running in standalone mode
                if (!window.matchMedia('(display-mode: standalone)').matches) {
                    pwaIndicator.style.display = 'block';

                    // Handle install button click
                    installBtn.addEventListener('click', function() {
                        // The PWA manager will handle the install prompt
                        if (window.PuntworkPWAManager && window.PuntworkPWAManager.deferredPrompt) {
                            window.PuntworkPWAManager.installPWA();
                        } else {
                            // Fallback: try to trigger install prompt
                            if ('serviceWorker' in navigator) {
                                navigator.serviceWorker.getRegistrations().then(registrations => {
                                    if (registrations.length > 0) {
                                        // PWA is registered, show manual install instructions
                                        alert('<?php echo esc_js(__("To install puntWork Admin as a PWA, click the install icon in your browser\'s address bar or use the browser menu.", "puntwork")); ?>');
                                    }
                                });
                            }
                        }
                    });
                }
            }
        });
    </script>

    <style>
        .wrap > div:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
    </style>
    <?php
}

/**
 * Render the onboarding modal HTML
 */
function render_onboarding_modal() {
    // Check if onboarding has been completed
    $onboarding_completed = get_option('puntwork_onboarding_completed', false);

    // Only show if not completed
    if ($onboarding_completed) {
        return;
    }

    ?>
    <!-- Onboarding Modal Overlay -->
    <div class="onboarding-overlay" id="onboarding-overlay" style="display: none;"></div>

    <!-- Onboarding Modal -->
    <div class="puntwork-onboarding-modal" id="puntwork-onboarding-modal" role="dialog" aria-modal="true" aria-labelledby="onboarding-title" aria-hidden="true" style="display: none;">
        <!-- Modal Header -->
        <div class="onboarding-header">
            <button type="button" id="onboarding-close" class="onboarding-close-btn" aria-label="<?php esc_attr_e('Close onboarding', 'puntwork'); ?>">
                <i class="fas fa-times"></i>
            </button>
            <button type="button" id="onboarding-skip" class="onboarding-skip-btn" aria-label="<?php esc_attr_e('Skip onboarding', 'puntwork'); ?>">
                <?php _e('Skip', 'puntwork'); ?>
            </button>
        </div>

        <!-- Progress Bar -->
        <div class="onboarding-progress">
            <div class="onboarding-progress-fill" id="onboarding-progress-fill"></div>
        </div>

        <!-- Step Indicators -->
        <div class="step-indicators">
            <div class="step-indicator active" data-step="0" aria-label="<?php esc_attr_e('Welcome step', 'puntwork'); ?>"></div>
            <div class="step-indicator" data-step="1" aria-label="<?php esc_attr_e('Configure feeds step', 'puntwork'); ?>"></div>
            <div class="step-indicator" data-step="2" aria-label="<?php esc_attr_e('Set up scheduling step', 'puntwork'); ?>"></div>
            <div class="step-indicator" data-step="3" aria-label="<?php esc_attr_e('API configuration step', 'puntwork'); ?>"></div>
            <div class="step-indicator" data-step="4" aria-label="<?php esc_attr_e('Setup complete step', 'puntwork'); ?>"></div>
        </div>

        <!-- Step Content -->
        <div class="onboarding-content">
            <div id="onboarding-step-content" class="step-content">
                <!-- Content will be populated by JavaScript -->
            </div>
        </div>

        <!-- Navigation Buttons -->
        <div class="onboarding-navigation">
            <button type="button" id="onboarding-prev" class="onboarding-nav-btn prev-btn" style="display: none;" aria-label="<?php esc_attr_e('Previous step', 'puntwork'); ?>">
                <i class="fas fa-arrow-left"></i> <?php _e('Previous', 'puntwork'); ?>
            </button>
            <button type="button" id="onboarding-next" class="onboarding-nav-btn next-btn" aria-label="<?php esc_attr_e('Next step', 'puntwork'); ?>">
                <?php _e('Next', 'puntwork'); ?> <i class="fas fa-arrow-right"></i>
            </button>
        </div>
    </div>
    <?php
}

/**
 * Render import history UI section
 */
function render_import_history_ui() {
    ?>
    <!-- Import History Section -->
    <div class="wrap" style="max-width: 900px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'SF Pro Text', 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #1d1d1f; padding: 0 24px; background-color: #f5f5f7;">
        <div id="import-history" style="max-width: 900px; margin: 0 auto; margin-top: 40px; background-color: #ffffff; border-radius: 16px; padding: 32px; box-shadow: 0 2px 10px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04); position: relative; overflow: hidden;">

            <!-- Header Section -->
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 32px; padding-bottom: 24px; border-bottom: 1px solid #e5e5e7;">
                <div>
                    <h2 style="font-size: 28px; font-weight: 700; margin: 0 0 4px 0; color: #1d1d1f; letter-spacing: -0.02em;"><?php _e('Import History', 'puntwork'); ?></h2>
                    <p style="font-size: 15px; color: #86868b; margin: 0; font-weight: 400;"><?php _e('View all import runs including manual, scheduled, and API-triggered imports', 'puntwork'); ?></p>
                </div>
                <button id="refresh-history-main" class="secondary-button" style="border-radius: 8px; padding: 8px 16px; font-size: 14px; font-weight: 500; background-color: #f2f2f7; border: 1px solid #d1d1d6; color: #424245; transition: all 0.2s ease; cursor: pointer;" aria-label="<?php esc_attr_e('Refresh import history', 'puntwork'); ?>">
                    <i class="fas fa-sync-alt" style="margin-right: 6px;"></i><?php _e('Refresh', 'puntwork'); ?>
                </button>
            </div>

            <!-- Import History Content -->
            <div id="run-history-list" style="max-height: 600px; overflow-y: auto; font-size: 14px; border-radius: 8px; background-color: #fafbfc; padding: 20px;">
                <div style="color: #86868b; text-align: center; padding: 24px; font-style: italic;"><?php _e('Loading history...', 'puntwork'); ?></div>
            </div>
        </div>
    </div>

    <style>
        /* Import History specific styles */
        #import-history .secondary-button:hover {
            background-color: #e5e5e7;
            border-color: #007aff;
        }

        /* Loading animation for refresh button */
        #import-history #refresh-history-main.loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Responsive design */
        @media (max-width: 768px) {
            #import-history {
                margin: 20px 16px;
                padding: 24px 20px;
            }

            #import-history h2 {
                font-size: 24px;
            }
        }
    </style>
    <?php
}

/**
 * Render JavaScript initialization for the jobs dashboard page
 */
function render_jobs_javascript_init() {
    ?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            console.log('[PUNTWORK] Jobs Dashboard: Document ready, checking modules...');

            // Check if buttons exist
            console.log('[PUNTWORK] Jobs Dashboard: cleanup-duplicates button exists:', $('#cleanup-duplicates').length);

            // Add a simple test function to global scope
            window.testJobsButtons = function() {
                console.log('[PUNTWORK] Testing jobs buttons...');
                console.log('Cleanup button found:', $('#cleanup-duplicates').length);

                if ($('#cleanup-duplicates').length > 0) {
                    console.log('Cleanup button HTML:', $('#cleanup-duplicates')[0].outerHTML);
                }

                // Test click events
                $('#cleanup-duplicates').trigger('click');
            };

            console.log('[PUNTWORK] Run testJobsButtons() in console to test button functionality');

            // Initialize jobs dashboard
            if (typeof JobImportEvents !== 'undefined') {
                console.log('[PUNTWORK] Jobs Dashboard: Initializing events...');
                // Only bind cleanup events, not the full import system
                JobImportEvents.bindCleanupEvents();
            } else {
                console.error('[PUNTWORK] Jobs Dashboard: JobImportEvents not available!');
            }

            // Initialize UI components
            if (typeof JobImportUI !== 'undefined') {
                console.log('[PUNTWORK] Jobs Dashboard: Clearing cleanup progress...');
                JobImportUI.clearCleanupProgress();
            }
        });
    </script>
    <?php
}
