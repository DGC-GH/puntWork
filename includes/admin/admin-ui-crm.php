<?php

/**
 * CRM Admin Interface
 *
 * @package    Puntwork
 * @subpackage Admin
 * @since      0.0.4
 */

namespace Puntwork\Admin;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

/**
 * CRM Admin Interface Class
 */
class PuntworkCrmAdmin
{
    /**
     * CRM Manager instance
     */
    private $crm_manager;

    /**
     * Constructor
     */
    public function __construct()
    {
        add_action('admin_menu', array( $this, 'addAdminMenu' ));
        add_action('admin_enqueueScripts', array( $this, 'enqueueScripts' ));
        add_action('wp_ajax_puntwork_crm_test_connection', array( $this, 'ajaxTestConnection' ));
        add_action('wp_ajax_puntwork_crm_save_config', array( $this, 'ajaxSaveConfig' ));
        add_action('wp_ajax_puntwork_crm_sync_test', array( $this, 'ajaxSyncTest' ));

        // Initialize CRM manager
        $this->crm_manager = new \Puntwork\CRM\CRMManager();
    }

    /**
     * Add CRM admin menu
     */
    public function addAdminMenu(): void
    {
        add_submenu_page(
            'puntwork-admin',
            __('CRM Integration', 'puntwork'),
            __('CRM Integration', 'puntwork'),
            'manage_options',
            'puntwork-crm',
            array( $this, 'renderAdminPage' )
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueueScripts(string $hook): void
    {
        if ($hook !== 'puntwork_page_puntwork-crm') {
            return;
        }

        wp_enqueue_style('puntwork-crm-admin', plugin_dir_url(__FILE__) . '../../assets/css/crm-admin.css', array(), '1.0.0');
        wp_enqueue_script('puntwork-crm-admin', plugin_dir_url(__FILE__) . '../../assets/js/crm-admin.js', array( 'jquery' ), '1.0.0', true);

        wp_localize_script(
            'puntwork-crm-admin',
            'puntwork_crm_ajax',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('puntwork_crm_nonce'),
                'strings'  => array(
                    'testing_connection'    => __('Testing connection...', 'puntwork'),
                    'connection_successful' => __('Connection successful!', 'puntwork'),
                    'connection_failed'     => __('Connection failed!', 'puntwork'),
                    'saving_config'         => __('Saving configuration...', 'puntwork'),
                    'config_saved'          => __('Configuration saved!', 'puntwork'),
                    'config_save_failed'    => __('Failed to save configuration!', 'puntwork'),
                    'sync_test_started'     => __('Running sync test...', 'puntwork'),
                    'sync_test_completed'   => __('Sync test completed!', 'puntwork'),
                    'sync_test_failed'      => __('Sync test failed!', 'puntwork'),
                ),
            )
        );
    }

    /**
     * Render admin page
     */
    public function renderAdminPage(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $available_platforms  = $this->crm_manager->getAvailablePlatforms();
        $configured_platforms = $this->crm_manager->getConfiguredPlatforms();
        $statistics           = $this->crm_manager->getStatistics();

        ?>
        <div class="wrap">
            <h1><?php _e('CRM Integration', 'puntwork'); ?></h1>

            <div class="puntwork-crm-container">
                <!-- Statistics Overview -->
                <div class="crm-stats-card">
                    <h3><?php _e('Sync Statistics (Last 30 Days)', 'puntwork'); ?></h3>
                    <div class="stats-grid">
                        <div class="stat-item">
                            <span class="stat-number"><?php echo esc_html($statistics['total_syncs']); ?></span>
                            <span class="stat-label"><?php _e('Total Syncs', 'puntwork'); ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number success"><?php echo esc_html($statistics['successful_syncs']); ?></span>
                            <span class="stat-label"><?php _e('Successful', 'puntwork'); ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number error"><?php echo esc_html($statistics['failed_syncs']); ?></span>
                            <span class="stat-label"><?php _e('Failed', 'puntwork'); ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number"><?php echo $statistics['last_sync'] ? esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($statistics['last_sync']))) : __('Never', 'puntwork'); ?></span>
                            <span class="stat-label"><?php _e('Last Sync', 'puntwork'); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Platform Configuration -->
                <div class="crm-platforms-section">
                    <h2><?php _e('CRM Platform Configuration', 'puntwork'); ?></h2>

                    <?php foreach ($available_platforms as $platform_id => $platform_info) : ?>
                        <div class="crm-platform-card" data-platform="<?php echo esc_attr($platform_id); ?>">
                            <div class="platform-header">
                                <h3><?php echo esc_html($platform_info['name']); ?></h3>
                                <div class="platform-status">
                                    <?php if (in_array($platform_id, $configured_platforms)) : ?>
                                        <span class="status-badge status-active"><?php _e('Active', 'puntwork'); ?></span>
                                    <?php else : ?>
                                        <span class="status-badge status-inactive"><?php _e('Not Configured', 'puntwork'); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="platform-content">
                                <form class="crm-config-form" data-platform="<?php echo esc_attr($platform_id); ?>">
                                    <?php
                                    $current_config = \Puntwork\CRM\CRMManager::getPlatformConfig($platform_id) ?: array();
                                    $this->renderPlatformConfigForm($platform_id, $platform_info['required_config'], $current_config);
                                    ?>
                                </form>

                                <div class="platform-actions">
                                    <button type="button" class="puntwork-btn puntwork-btn--secondary test-connection" data-platform="<?php echo esc_attr($platform_id); ?>">
                                        <?php _e('Test Connection', 'puntwork'); ?>
                                    </button>
                                    <button type="button" class="puntwork-btn puntwork-btn--primary save-config" data-platform="<?php echo esc_attr($platform_id); ?>">
                                        <?php _e('Save Configuration', 'puntwork'); ?>
                                    </button>
                                    <?php if (in_array($platform_id, $configured_platforms)) : ?>
                                        <button type="button" class="puntwork-btn puntwork-btn--secondary sync-test" data-platform="<?php echo esc_attr($platform_id); ?>">
                                            <?php _e('Test Sync', 'puntwork'); ?>
                                        </button>
                                    <?php endif; ?>
                                </div>

                                <div class="platform-messages" data-platform="<?php echo esc_attr($platform_id); ?>"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Sync Settings -->
                <div class="crm-settings-section">
                    <h2><?php _e('Sync Settings', 'puntwork'); ?></h2>

                    <form method="post" action="options.php">
                        <?php settings_fields('puntwork_crm_settings'); ?>
                        <?php do_settings_sections('puntwork_crm_settings'); ?>

                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Auto-sync Job Applications', 'puntwork'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="puntwork_crm_auto_sync" value="1" <?php checked(get_option('puntwork_crm_auto_sync', '1'), '1'); ?> />
                                        <?php _e('Automatically sync job applications to configured CRM platforms', 'puntwork'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Sync Platforms', 'puntwork'); ?></th>
                                <td>
                                    <?php
                                    $sync_platforms = get_option('puntwork_crm_sync_platforms', array());
                                    foreach ($available_platforms as $platform_id => $platform_info) :
                                        ?>
                                        <label style="display: block; margin-bottom: 5px;">
                                            <input type="checkbox" name="puntwork_crm_sync_platforms[]" value="<?php echo esc_attr($platform_id); ?>" <?php checked(in_array($platform_id, $sync_platforms)); ?> />
                                            <?php echo esc_html($platform_info['name']); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Sync Delay', 'puntwork'); ?></th>
                                <td>
                                    <select name="puntwork_crm_sync_delay">
                                        <option value="0" <?php selected(get_option('puntwork_crm_sync_delay', '0'), '0'); ?>><?php _e('Immediate', 'puntwork'); ?></option>
                                        <option value="300" <?php selected(get_option('puntwork_crm_sync_delay', '0'), '300'); ?>><?php _e('5 minutes', 'puntwork'); ?></option>
                                        <option value="1800" <?php selected(get_option('puntwork_crm_sync_delay', '0'), '1800'); ?>><?php _e('30 minutes', 'puntwork'); ?></option>
                                        <option value="3600" <?php selected(get_option('puntwork_crm_sync_delay', '0'), '3600'); ?>><?php _e('1 hour', 'puntwork'); ?></option>
                                    </select>
                                    <p class="description"><?php _e('Delay before syncing data to CRM platforms', 'puntwork'); ?></p>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button(__('Save Settings', 'puntwork')); ?>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render platform configuration form
     */
    private function renderPlatformConfigForm(string $platform_id, array $required_config, array $current_config): void
    {
        ?>
        <div class="config-fields">
            <?php foreach ($required_config as $field => $field_config) : ?>
                <div class="config-field">
                    <label for="<?php echo esc_attr("{$platform_id}_{$field}"); ?>">
                        <?php echo esc_html($field_config['label']); ?>
                        <?php if (! empty($field_config['required'])) : ?>
                            <span class="required">*</span>
                        <?php endif; ?>
                    </label>

                    <?php if ($field_config['type'] === 'password') : ?>
                        <input type="password"
                                id="<?php echo esc_attr("{$platform_id}_{$field}"); ?>"
                                name="<?php echo esc_attr($field); ?>"
                                value="<?php echo esc_attr($current_config[ $field ] ?? ''); ?>"
                                class="regular-text"
                                <?php echo ! empty($field_config['required']) ? 'required' : ''; ?> />
                    <?php elseif ($field_config['type'] === 'textarea') : ?>
                        <textarea id="<?php echo esc_attr("{$platform_id}_{$field}"); ?>"
                                    name="<?php echo esc_attr($field); ?>"
                                    class="large-text"
                                    rows="3"
                                    <?php echo ! empty($field_config['required']) ? 'required' : ''; ?>><?php echo esc_textarea($current_config[ $field ] ?? ''); ?></textarea>
                    <?php else : ?>
                        <input type="text"
                                id="<?php echo esc_attr("{$platform_id}_{$field}"); ?>"
                                name="<?php echo esc_attr($field); ?>"
                                value="<?php echo esc_attr($current_config[ $field ] ?? ''); ?>"
                                class="regular-text"
                                <?php echo ! empty($field_config['required']) ? 'required' : ''; ?> />
                    <?php endif; ?>

                    <?php if (! empty($field_config['description'])) : ?>
                        <p class="description"><?php echo esc_html($field_config['description']); ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <div class="config-field">
                <label>
                    <input type="checkbox" name="enabled" value="1" <?php checked(! empty($current_config['enabled'])); ?> />
                    <?php _e('Enable this CRM integration', 'puntwork'); ?>
                </label>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler for testing connection
     */
    public function ajaxTestConnection(): void
    {
        check_ajax_referer('puntwork_crm_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => __('Insufficient permissions', 'puntwork') ));
        }

        $platform_id = sanitize_text_field($_POST['platform_id'] ?? '');
        $config      = $_POST['config'] ?? array();

        if (empty($platform_id)) {
            wp_send_json_error(array( 'message' => __('Platform ID is required', 'puntwork') ));
        }

        // Sanitize config data
        $sanitized_config = array();
        foreach ($config as $key => $value) {
            $sanitized_config[ $key ] = sanitize_text_field($value);
        }

        $result = $this->crm_manager->testConnection($platform_id, $sanitized_config);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX handler for saving configuration
     */
    public function ajaxSaveConfig(): void
    {
        check_ajax_referer('puntwork_crm_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => __('Insufficient permissions', 'puntwork') ));
        }

        $platform_id = sanitize_text_field($_POST['platform_id'] ?? '');
        $config      = $_POST['config'] ?? array();

        if (empty($platform_id)) {
            wp_send_json_error(array( 'message' => __('Platform ID is required', 'puntwork') ));
        }

        // Sanitize config data
        $sanitized_config = array();
        foreach ($config as $key => $value) {
            $sanitized_config[ $key ] = sanitize_text_field($value);
        }

        $success = \Puntwork\CRM\CRMManager::configurePlatform($platform_id, $sanitized_config);

        if ($success) {
            wp_send_json_success(array( 'message' => __('Configuration saved successfully', 'puntwork') ));
        } else {
            wp_send_json_error(array( 'message' => __('Failed to save configuration', 'puntwork') ));
        }
    }

    /**
     * AJAX handler for sync test
     */
    public function ajaxSyncTest(): void
    {
        check_ajax_referer('puntwork_crm_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => __('Insufficient permissions', 'puntwork') ));
        }

        $platform_id = sanitize_text_field($_POST['platform_id'] ?? '');

        if (empty($platform_id)) {
            wp_send_json_error(array( 'message' => __('Platform ID is required', 'puntwork') ));
        }

        // Create test application data
        $test_application = array(
            'id'               => 'test_' . time(),
            'first_name'       => 'Test',
            'last_name'        => 'User',
            'email'            => 'test@example.com',
            'phone'            => '+1-555-0123',
            'current_company'  => 'Test Company',
            'current_position' => 'Test Position',
            'job_title'        => 'Software Developer',
            'application_date' => date('Y-m-d'),
            'source'           => 'puntwork_test',
        );

        $result = $this->crm_manager->syncJobApplication($test_application, array( $platform_id ));

        if (! empty($result[ $platform_id ]) && $result[ $platform_id ]['success']) {
            wp_send_json_success(
                array(
                    'message' => __('Sync test completed successfully', 'puntwork'),
                    'data'    => $result[ $platform_id ],
                )
            );
        } else {
            wp_send_json_error(
                array(
                    'message' => __('Sync test failed', 'puntwork'),
                    'error'   => $result[ $platform_id ]['error'] ?? __('Unknown error', 'puntwork'),
                )
            );
        }
    }
}

// Initialize CRM Admin
new PuntworkCrmAdmin();