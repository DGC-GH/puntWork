<?php

/**
 * Social Media Admin Interface
 *
 * @package    Puntwork
 * @subpackage Admin
 * @since      2.2.0
 */

namespace Puntwork;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Social Media Admin Class
 */
class PuntworkSocialMediaAdmin
{
    /**
     * Social Media Manager instance
     */
    private $social_manager;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->social_manager = new \Puntwork\SocialMedia\SocialMediaManager();

        add_action('admin_menu', array( $this, 'addSocialMediaMenu' ));
        add_action('admin_enqueueScripts', array( $this, 'enqueueScripts' ));
        add_action('wp_ajax_puntwork_social_test_platform', array( $this, 'ajaxTestPlatform' ));
        add_action('wp_ajax_puntwork_social_save_config', array( $this, 'ajaxSaveConfig' ));
        add_action('wp_ajax_puntwork_social_post_now', array( $this, 'ajaxPostNow' ));
    }

    /**
     * Add social media menu to admin
     */
    public function addSocialMediaMenu(): void
    {
        add_submenu_page(
            'puntwork-admin',
            __('Social Media Integration', 'puntwork'),
            __('Social Media', 'puntwork'),
            'manage_options',
            'puntwork-social-media',
            array( $this, 'renderSocialMediaPage' )
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueueScripts($hook): void
    {
        if ($hook !== 'puntwork_page_puntwork-social-media') {
            return;
        }

        wp_enqueue_script(
            'puntwork-social-media-admin',
            plugins_url('assets/js/social-media-admin.js', dirname(__DIR__, 1)),
            array( 'jquery' ),
            PUNTWORK_VERSION,
            true
        );

        wp_enqueue_style(
            'puntwork-social-media-admin',
            plugins_url('assets/js/social-media-admin.css', dirname(__DIR__, 1)),
            array(),
            PUNTWORK_VERSION
        );

        wp_localize_script(
            'puntwork-social-media-admin',
            'puntwork_social_ajax',
            array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('puntwork_social_nonce'),
            'strings'  => array(
            'testing'      => __('Testing connection...', 'puntwork'),
            'test_success' => __('Connection successful!', 'puntwork'),
            'test_failed'  => __('Connection failed!', 'puntwork'),
            'saving'       => __('Saving...', 'puntwork'),
            'save_success' => __('Configuration saved!', 'puntwork'),
            'save_failed'  => __('Save failed!', 'puntwork'),
            'posting'      => __('Posting...', 'puntwork'),
            'post_success' => __('Posted successfully!', 'puntwork'),
            'post_failed'  => __('Post failed!', 'puntwork'),
            ),
            )
        );
    }

    /**
     * Render social media admin page
     */
    public function renderSocialMediaPage(): void
    {
        $available_platforms = \Puntwork\SocialMedia\SocialMediaManager::getAvailablePlatforms();
        $platform_configs    = \Puntwork\SocialMedia\SocialMediaManager::getAllPlatformConfigs();

        ?>
        <div class="wrap">
            <h1><?php _e('Social Media Integration', 'puntwork'); ?></h1>

            <div class="puntwork-social-media-container">
                <div class="puntwork-social-tabs">
                    <button class="tab-button active" data-tab="platforms">
        <?php _e('Platform Configuration', 'puntwork'); ?>
                    </button>
                    <button class="tab-button" data-tab="posting">
        <?php _e('Posting Settings', 'puntwork'); ?>
                    </button>
                    <button class="tab-button" data-tab="logs">
        <?php _e('Activity Logs', 'puntwork'); ?>
                    </button>
                </div>

                <!-- Platform Configuration Tab -->
                <div id="platforms-tab" class="tab-content active">
                    <h2><?php _e('Configure Social Media Platforms', 'puntwork'); ?></h2>

        <?php foreach ($available_platforms as $platform_id => $platform_info) : ?>
                        <div class="platform-config-card" data-platform="<?php echo esc_attr($platform_id); ?>">
                            <div class="platform-header">
                                <h3><?php echo esc_html($platform_info['name']); ?></h3>
                                <div class="platform-toggles">
                                    <label class="platform-toggle">
                                        <input type="checkbox"
                                                class="platform-enabled"
                                                <?php checked(isset($platform_configs[ $platform_id ]['enabled']) && $platform_configs[ $platform_id ]['enabled']); ?>>
            <?php _e('Enable', 'puntwork'); ?>
                                    </label>
                                    <label class="ads-toggle" style="margin-left: 15px;">
                                        <input type="checkbox"
                                                class="ads-enabled"
                                                <?php checked(isset($platform_configs[ $platform_id ]['ads_enabled']) && $platform_configs[ $platform_id ]['ads_enabled']); ?>>
            <?php _e('Enable Ads', 'puntwork'); ?>
                                    </label>
                                </div>
                            </div>

                            <div class="platform-config" style="display: <?php echo ( isset($platform_configs[ $platform_id ]['enabled']) && $platform_configs[ $platform_id ]['enabled'] ) ? 'block' : 'none'; ?>;">
            <?php $this->renderPlatformConfig($platform_id, $platform_configs[ $platform_id ] ?? array()); ?>

                                <div class="ads-config" style="display: <?php echo ( isset($platform_configs[ $platform_id ]['ads_enabled']) && $platform_configs[ $platform_id ]['ads_enabled'] ) ? 'block' : 'none'; ?>;">
                                    <h4><?php _e('Ads Configuration', 'puntwork'); ?></h4>
            <?php $this->renderAdsConfig($platform_id, $platform_configs[ $platform_id ] ?? array()); ?>
                                </div>

                                <div class="platform-actions">
                                    <button class="puntwork-btn puntwork-btn--secondary test-platform">
            <?php _e('Test Connection', 'puntwork'); ?>
                                    </button>
                                    <button class="puntwork-btn puntwork-btn--primary save-platform">
            <?php _e('Save Configuration', 'puntwork'); ?>
                                    </button>
                                </div>

                                <div class="platform-status" style="display: none;"></div>
                            </div>
                        </div>
        <?php endforeach; ?>
                </div>

                <!-- Posting Settings Tab -->
                <div id="posting-tab" class="tab-content">
                    <h2><?php _e('Posting Settings', 'puntwork'); ?></h2>

                    <div class="posting-settings">
                        <h3><?php _e('Default Posting Options', 'puntwork'); ?></h3>

                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Auto-post New Jobs', 'puntwork'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="auto_post_jobs" value="1"
                                                <?php checked(get_option('puntwork_social_auto_post_jobs', false)); ?>>
                                        <?php _e('Automatically post new jobs to configured platforms', 'puntwork'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Default Platforms', 'puntwork'); ?></th>
                                <td>
                                    <?php
                                    $default_platforms = get_option('puntwork_social_default_platforms', array());
                                    foreach ($available_platforms as $platform_id => $platform_info) :
                                        ?>
                                        <label style="display: block; margin-bottom: 5px;">
                                            <input type="checkbox"
                                                    name="default_platforms[]"
                                                    value="<?php echo esc_attr($platform_id); ?>"
                                        <?php checked(in_array($platform_id, $default_platforms)); ?>>
                                        <?php echo esc_html($platform_info['name']); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Post Template', 'puntwork'); ?></th>
                                <td>
                                    <select name="post_template">
                                        <option value="default" <?php selected(get_option('puntwork_social_post_template', 'default'), 'default'); ?>>
              <?php _e('Default', 'puntwork'); ?>
                                        </option>
                                        <option value="concise" <?php selected(get_option('puntwork_social_post_template', 'default'), 'concise'); ?>>
              <?php _e('Concise', 'puntwork'); ?>
                                        </option>
                                        <option value="detailed" <?php selected(get_option('puntwork_social_post_template', 'default'), 'detailed'); ?>>
              <?php _e('Detailed', 'puntwork'); ?>
                                        </option>
                                    </select>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="button" class="puntwork-btn puntwork-btn--primary" id="save-posting-settings">
           <?php _e('Save Settings', 'puntwork'); ?>
                            </button>
                        </p>
                    </div>

                    <div class="manual-posting">
                        <h3><?php _e('Manual Posting', 'puntwork'); ?></h3>
                        <p><?php _e('Post content manually to configured platforms.', 'puntwork'); ?></p>

                        <textarea id="manual-post-content" rows="4" placeholder="<?php esc_attr_e('Enter your post content here...', 'puntwork'); ?>"></textarea>

                        <div class="manual-post-platforms">
          <?php foreach ($available_platforms as $platform_id => $platform_info) : ?>
                                <label>
                                    <input type="checkbox" class="manual-post-platform" value="<?php echo esc_attr($platform_id); ?>">
                <?php echo esc_html($platform_info['name']); ?>
                                </label>
          <?php endforeach; ?>
                        </div>

                        <button type="button" class="puntwork-btn puntwork-btn--primary" id="post-manually">
          <?php _e('Post Now', 'puntwork'); ?>
                        </button>

                        <div id="manual-post-status" style="display: none;"></div>
                    </div>
                </div>

                <!-- Activity Logs Tab -->
                <div id="logs-tab" class="tab-content">
                    <h2><?php _e('Social Media Activity Logs', 'puntwork'); ?></h2>
                    <div id="activity-logs-container">
         <?php $this->renderActivityLogs(); ?>
                    </div>
                </div>
            </div>
        </div>

        <style>
            .puntwork-social-media-container {
                margin-top: 20px;
            }

            .puntwork-social-tabs {
                display: flex;
                border-bottom: 1px solid #ccc;
                margin-bottom: 20px;
            }

            .tab-button {
                background: none;
                border: none;
                padding: 10px 20px;
                cursor: pointer;
                border-bottom: 2px solid transparent;
            }

            .tab-button.active {
                border-bottom-color: #007cba;
                font-weight: bold;
            }

            .tab-content {
                display: none;
            }

            .tab-content.active {
                display: block;
            }

            .platform-config-card {
                border: 1px solid #ddd;
                border-radius: 5px;
                margin-bottom: 20px;
                padding: 15px;
            }

            .platform-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 15px;
            }

            .platform-toggle {
                font-weight: normal;
            }

            .platform-config {
                margin-top: 15px;
            }

            .platform-actions {
                margin-top: 15px;
                padding-top: 15px;
                border-top: 1px solid #eee;
            }

            .platform-status {
                margin-top: 10px;
                padding: 10px;
                border-radius: 3px;
            }

            .status-success {
                background-color: #d4edda;
                border-color: #c3e6cb;
                color: #155724;
            }

            .status-error {
                background-color: #f8d7da;
                border-color: #f5c6cb;
                color: #721c24;
            }

            .posting-settings, .manual-posting {
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 5px;
                padding: 20px;
                margin-bottom: 20px;
            }

            .manual-post-platforms {
                margin: 10px 0;
            }

            .manual-post-platforms label {
                display: inline-block;
                margin-right: 15px;
            }
        </style>
        <?php
    }

    /**
     * Render ads-specific configuration
     */
    private function renderAdsConfig(string $platform_id, array $config): void
    {
        switch ($platform_id) {
            case 'twitter':
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Ads Account ID', 'puntwork'); ?></th>
                        <td>
                            <input type="text" name="ads_account_id" value="<?php echo esc_attr($config['ads_account_id'] ?? ''); ?>" class="regular-text">
                            <p class="description"><?php _e('Your Twitter Ads account ID', 'puntwork'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Bearer Token', 'puntwork'); ?></th>
                        <td>
                            <input type="password" name="bearer_token" value="<?php echo esc_attr($config['bearer_token'] ?? ''); ?>" class="regular-text">
                            <p class="description"><?php _e('Twitter API Bearer Token for ads access', 'puntwork'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Default Campaign Budget', 'puntwork'); ?></th>
                        <td>
                            <input type="number" name="default_budget" value="<?php echo esc_attr($config['default_budget'] ?? '50'); ?>" step="0.01" min="1">
                            <p class="description"><?php _e('Default daily budget in USD for ads campaigns', 'puntwork'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Campaign Objective', 'puntwork'); ?></th>
                        <td>
                            <select name="campaign_objective">
                                <option value="ENGAGEMENT" <?php selected(( $config['campaign_objective'] ?? 'ENGAGEMENT' ), 'ENGAGEMENT'); ?>><?php _e('Engagement', 'puntwork'); ?></option>
                                <option value="AWARENESS" <?php selected(( $config['campaign_objective'] ?? 'ENGAGEMENT' ), 'AWARENESS'); ?>><?php _e('Awareness', 'puntwork'); ?></option>
                                <option value="TRAFFIC" <?php selected(( $config['campaign_objective'] ?? 'ENGAGEMENT' ), 'TRAFFIC'); ?>><?php _e('Traffic', 'puntwork'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php
                break;
            case 'facebook':
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Ads Account ID', 'puntwork'); ?></th>
                        <td>
                            <input type="text" name="ad_account_id" value="<?php echo esc_attr($config['ad_account_id'] ?? ''); ?>" class="regular-text">
                            <p class="description"><?php _e('Your Facebook Ads account ID (format: act_123456789)', 'puntwork'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Default Campaign Budget', 'puntwork'); ?></th>
                        <td>
                            <input type="number" name="default_budget" value="<?php echo esc_attr($config['default_budget'] ?? '50'); ?>" step="0.01" min="1">
                            <p class="description"><?php _e('Default daily budget in USD for ads campaigns', 'puntwork'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Campaign Objective', 'puntwork'); ?></th>
                        <td>
                            <select name="campaign_objective">
                                <option value="CONVERSIONS" <?php selected(( $config['campaign_objective'] ?? 'TRAFFIC' ), 'CONVERSIONS'); ?>><?php _e('Conversions', 'puntwork'); ?></option>
                                <option value="TRAFFIC" <?php selected(( $config['campaign_objective'] ?? 'TRAFFIC' ), 'TRAFFIC'); ?>><?php _e('Traffic', 'puntwork'); ?></option>
                                <option value="ENGAGEMENT" <?php selected(( $config['campaign_objective'] ?? 'TRAFFIC' ), 'ENGAGEMENT'); ?>><?php _e('Engagement', 'puntwork'); ?></option>
                                <option value="REACH" <?php selected(( $config['campaign_objective'] ?? 'TRAFFIC' ), 'REACH'); ?>><?php _e('Reach', 'puntwork'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Optimization Goal', 'puntwork'); ?></th>
                        <td>
                            <select name="optimization_goal">
                                <option value="REACH" <?php selected(( $config['optimization_goal'] ?? 'REACH' ), 'REACH'); ?>><?php _e('Reach', 'puntwork'); ?></option>
                                <option value="IMPRESSIONS" <?php selected(( $config['optimization_goal'] ?? 'REACH' ), 'IMPRESSIONS'); ?>><?php _e('Impressions', 'puntwork'); ?></option>
                                <option value="LINK_CLICKS" <?php selected(( $config['optimization_goal'] ?? 'REACH' ), 'LINK_CLICKS'); ?>><?php _e('Link Clicks', 'puntwork'); ?></option>
                                <option value="LANDING_PAGE_VIEWS" <?php selected(( $config['optimization_goal'] ?? 'REACH' ), 'LANDING_PAGE_VIEWS'); ?>><?php _e('Landing Page Views', 'puntwork'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php
                break;
            case 'tiktok':
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Advertiser ID', 'puntwork'); ?></th>
                        <td>
                            <input type="text" name="advertiser_id" value="<?php echo esc_attr($config['advertiser_id'] ?? ''); ?>" class="regular-text">
                            <p class="description"><?php _e('Your TikTok Advertiser ID', 'puntwork'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Default Campaign Budget', 'puntwork'); ?></th>
                        <td>
                            <input type="number" name="default_budget" value="<?php echo esc_attr($config['default_budget'] ?? '50'); ?>" step="0.01" min="1">
                            <p class="description"><?php _e('Default daily budget in USD for ads campaigns', 'puntwork'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Campaign Objective', 'puntwork'); ?></th>
                        <td>
                            <select name="campaign_objective">
                                <option value="REACH" <?php selected(( $config['campaign_objective'] ?? 'REACH' ), 'REACH'); ?>><?php _e('Reach', 'puntwork'); ?></option>
                                <option value="TRAFFIC" <?php selected(( $config['campaign_objective'] ?? 'REACH' ), 'TRAFFIC'); ?>><?php _e('Traffic', 'puntwork'); ?></option>
                                <option value="APP_INSTALLS" <?php selected(( $config['campaign_objective'] ?? 'REACH' ), 'APP_INSTALLS'); ?>><?php _e('App Installs', 'puntwork'); ?></option>
                                <option value="LEAD_GENERATION" <?php selected(( $config['campaign_objective'] ?? 'REACH' ), 'LEAD_GENERATION'); ?>><?php _e('Lead Generation', 'puntwork'); ?></option>
                                <option value="VIDEO_VIEWS" <?php selected(( $config['campaign_objective'] ?? 'REACH' ), 'VIDEO_VIEWS'); ?>><?php _e('Video Views', 'puntwork'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Optimization Goal', 'puntwork'); ?></th>
                        <td>
                            <select name="optimization_goal">
                                <option value="REACH" <?php selected(( $config['optimization_goal'] ?? 'REACH' ), 'REACH'); ?>><?php _e('Reach', 'puntwork'); ?></option>
                                <option value="CLICK" <?php selected(( $config['optimization_goal'] ?? 'REACH' ), 'CLICK'); ?>><?php _e('Clicks', 'puntwork'); ?></option>
                                <option value="IMPRESSION" <?php selected(( $config['optimization_goal'] ?? 'REACH' ), 'IMPRESSION'); ?>><?php _e('Impressions', 'puntwork'); ?></option>
                                <option value="APP_INSTALL" <?php selected(( $config['optimization_goal'] ?? 'REACH' ), 'APP_INSTALL'); ?>><?php _e('App Installs', 'puntwork'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php
                break;
        }
    }
    /**
     * Render platform-specific configuration
     */
    private function renderPlatformConfig(string $platform_id, array $config): void
    {
        switch ($platform_id) {
            case 'twitter':
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('API Key', 'puntwork'); ?></th>
                        <td>
                            <input type="text" name="api_key" value="<?php echo esc_attr($config['api_key'] ?? ''); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('API Secret', 'puntwork'); ?></th>
                        <td>
                            <input type="password" name="api_secret" value="<?php echo esc_attr($config['api_secret'] ?? ''); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Access Token', 'puntwork'); ?></th>
                        <td>
                            <input type="text" name="access_token" value="<?php echo esc_attr($config['access_token'] ?? ''); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Access Token Secret', 'puntwork'); ?></th>
                        <td>
                            <input type="password" name="access_token_secret" value="<?php echo esc_attr($config['access_token_secret'] ?? ''); ?>" class="regular-text">
                        </td>
                    </tr>
                </table>
                <?php
                break;
            case 'facebook':
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('App ID', 'puntwork'); ?></th>
                        <td>
                            <input type="text" name="app_id" value="<?php echo esc_attr($config['app_id'] ?? ''); ?>" class="regular-text">
                            <p class="description"><?php _e('Your Facebook App ID', 'puntwork'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('App Secret', 'puntwork'); ?></th>
                        <td>
                            <input type="password" name="app_secret" value="<?php echo esc_attr($config['app_secret'] ?? ''); ?>" class="regular-text">
                            <p class="description"><?php _e('Your Facebook App Secret', 'puntwork'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Access Token', 'puntwork'); ?></th>
                        <td>
                            <input type="text" name="access_token" value="<?php echo esc_attr($config['access_token'] ?? ''); ?>" class="regular-text">
                            <p class="description"><?php _e('Facebook Page Access Token with publish permissions', 'puntwork'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Page ID', 'puntwork'); ?></th>
                        <td>
                            <input type="text" name="page_id" value="<?php echo esc_attr($config['page_id'] ?? ''); ?>" class="regular-text">
                            <p class="description"><?php _e('Your Facebook Page ID for posting', 'puntwork'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php
                break;
            case 'tiktok':
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('App ID', 'puntwork'); ?></th>
                        <td>
                            <input type="text" name="app_id" value="<?php echo esc_attr($config['app_id'] ?? ''); ?>" class="regular-text">
                            <p class="description"><?php _e('Your TikTok App ID', 'puntwork'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('App Secret', 'puntwork'); ?></th>
                        <td>
                            <input type="password" name="app_secret" value="<?php echo esc_attr($config['app_secret'] ?? ''); ?>" class="regular-text">
                            <p class="description"><?php _e('Your TikTok App Secret', 'puntwork'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Access Token', 'puntwork'); ?></th>
                        <td>
                            <input type="text" name="access_token" value="<?php echo esc_attr($config['access_token'] ?? ''); ?>" class="regular-text">
                            <p class="description"><?php _e('TikTok Access Token with publish permissions', 'puntwork'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Open ID', 'puntwork'); ?></th>
                        <td>
                            <input type="text" name="open_id" value="<?php echo esc_attr($config['open_id'] ?? ''); ?>" class="regular-text">
                            <p class="description"><?php _e('Your TikTok Open ID for the account', 'puntwork'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php
                break;
        }
    }

    /**
     * Render activity logs
     */
    private function renderActivityLogs(): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'puntwork_social_posts';

        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 50"
            ),
            ARRAY_A
        );

        if (empty($logs)) {
            echo '<p>' . __('No activity logs found.', 'puntwork') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . __('Date', 'puntwork') . '</th>';
        echo '<th>' . __('Platforms', 'puntwork') . '</th>';
        echo '<th>' . __('Status', 'puntwork') . '</th>';
        echo '<th>' . __('Results', 'puntwork') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($logs as $log) {
            $post_data = json_decode($log['post_data'], true);
            $results   = json_decode($log['results'] ?? '[]', true);

            echo '<tr>';
            echo '<td>' . esc_html($log['created_at']) . '</td>';
            echo '<td>' . esc_html(implode(', ', $post_data['platforms'] ?? array())) . '</td>';
            echo '<td>' . esc_html(ucfirst($log['status'])) . '</td>';
            echo '<td>';

            if (! empty($results)) {
                foreach ($results as $platform => $result) {
                    $status = $result['success'] ? '✓' : '✗';
                    echo esc_html($platform) . ': ' . $status . ' ';
                }
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * AJAX handler for testing platform connection
     */
    public function ajaxTestPlatform(): void
    {
        check_ajax_referer('puntwork_social_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'puntwork'));
        }

        $platform_id = sanitize_text_field($_POST['platform_id'] ?? '');

        if (empty($platform_id)) {
            wp_send_json_error(array( 'message' => __('Platform ID required', 'puntwork') ));
        }

        $result = $this->social_manager->testPlatform($platform_id);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX handler for saving platform configuration
     */
    public function ajaxSaveConfig(): void
    {
        check_ajax_referer('puntwork_social_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'puntwork'));
        }

        $platform_id = sanitize_text_field($_POST['platform_id'] ?? '');
        $config      = $_POST['config'] ?? array();

        if (empty($platform_id)) {
            wp_send_json_error(array( 'message' => __('Platform ID required', 'puntwork') ));
        }

        // Sanitize config data
        $sanitized_config = array();
        foreach ($config as $key => $value) {
            $sanitized_config[ $key ] = sanitize_text_field($value);
        }

        $success = \Puntwork\SocialMedia\SocialMediaManager::configurePlatform($platform_id, $sanitized_config);

        if ($success) {
            wp_send_json_success(array( 'message' => __('Configuration saved successfully', 'puntwork') ));
        } else {
            wp_send_json_error(array( 'message' => __('Failed to save configuration', 'puntwork') ));
        }
    }

    /**
     * AJAX handler for manual posting
     */
    public function ajaxPostNow(): void
    {
        check_ajax_referer('puntwork_social_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'puntwork'));
        }

        $content   = sanitize_textarea_field($_POST['content'] ?? '');
        $platforms = $_POST['platforms'] ?? array();

        if (empty($content)) {
            wp_send_json_error(array( 'message' => __('Content is required', 'puntwork') ));
        }

        if (empty($platforms)) {
            wp_send_json_error(array( 'message' => __('At least one platform must be selected', 'puntwork') ));
        }

        $platforms = array_map('sanitize_text_field', $platforms);

        $results = $this->social_manager->postToPlatforms(
            array( 'text' => $content ),
            $platforms
        );

        wp_send_json_success(
            array(
            'message' => __('Post sent to platforms', 'puntwork'),
            'results' => $results,
            )
        );
    }
}

// Initialize admin interface
new PuntworkSocialMediaAdmin();