<?php
/**
 * Job Board Admin Configuration
 *
 * @package    Puntwork
 * @subpackage Admin
 * @since      2.2.0
 */

namespace Puntwork\Admin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Job Board configuration admin page
 */
class JobBoardAdmin {

    /**
     * Initialize the admin page
     */
    public static function init(): void {
        add_action('admin_menu', [self::class, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_scripts']);
        add_action('wp_ajax_puntwork_test_job_board', [self::class, 'ajax_test_job_board']);
        add_action('wp_ajax_puntwork_save_job_board', [self::class, 'ajax_save_job_board']);
    }

    /**
     * Add admin menu
     */
    public static function add_admin_menu(): void {
        add_submenu_page(
            'puntwork-admin',
            __('Job Boards', 'puntwork'),
            __('Job Boards', 'puntwork'),
            'manage_options',
            'puntwork-job-boards',
            [self::class, 'render_admin_page']
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public static function enqueue_scripts($hook): void {
        if ($hook !== 'puntwork_page_puntwork-job-boards') {
            return;
        }

        wp_enqueue_style('puntwork-job-boards', plugin_dir_url(__FILE__) . '../../assets/css/job-boards-admin.css', [], PUNTWORK_VERSION);
        wp_enqueue_script('puntwork-job-boards', plugin_dir_url(__FILE__) . '../../assets/js/job-boards-admin.js', ['jquery'], PUNTWORK_VERSION, true);

        wp_localize_script('puntwork-job-boards', 'puntworkJobBoards', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('puntwork_job_boards'),
            'strings' => [
                'testing' => __('Testing connection...', 'puntwork'),
                'test_success' => __('Connection successful!', 'puntwork'),
                'test_failed' => __('Connection failed!', 'puntwork'),
                'saving' => __('Saving...', 'puntwork'),
                'save_success' => __('Settings saved!', 'puntwork'),
                'save_failed' => __('Save failed!', 'puntwork')
            ]
        ]);
    }

    /**
     * Render the admin page
     */
    public static function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Include the JobBoardManager
        require_once plugin_dir_path(dirname(__FILE__, 2)) . 'jobboards/jobboard-manager.php';

        $available_boards = \Puntwork\JobBoards\JobBoardManager::getAvailableBoards();
        $board_configs = \Puntwork\JobBoards\JobBoardManager::getAllBoardConfigs();

        ?>
        <div class="wrap">
            <h1><?php _e('Job Board Integrations', 'puntwork'); ?></h1>

            <div class="puntwork-job-boards-container">
                <div class="puntwork-job-boards-intro">
                    <p><?php _e('Configure integrations with popular job boards to expand your job import sources. Each job board requires API credentials which you can obtain from their developer portals.', 'puntwork'); ?></p>
                </div>

                <div class="puntwork-job-boards-grid">
                    <?php foreach ($available_boards as $board_id => $board_info): ?>
                        <?php
                        $config = $board_configs[$board_id] ?? [];
                        $is_enabled = isset($config['enabled']) && $config['enabled'];
                        ?>
                        <div class="puntwork-job-board-card <?php echo $is_enabled ? 'enabled' : 'disabled'; ?>" data-board-id="<?php echo esc_attr($board_id); ?>">
                            <div class="puntwork-job-board-header">
                                <h3><?php echo esc_html($board_info['name']); ?></h3>
                                <label class="puntwork-toggle">
                                    <input type="checkbox" class="puntwork-board-enabled" <?php checked($is_enabled); ?>>
                                    <span class="puntwork-toggle-slider"></span>
                                </label>
                            </div>

                            <div class="puntwork-job-board-content">
                                <form class="puntwork-board-config-form" style="<?php echo $is_enabled ? '' : 'display: none;'; ?>">
                                    <?php self::render_board_config_fields($board_id, $config); ?>
                                    <div class="puntwork-form-actions">
                                        <button type="button" class="button puntwork-test-connection">
                                            <?php _e('Test Connection', 'puntwork'); ?>
                                        </button>
                                        <button type="button" class="button button-primary puntwork-save-config">
                                            <?php _e('Save Settings', 'puntwork'); ?>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="puntwork-job-boards-help">
                    <h3><?php _e('Getting API Credentials', 'puntwork'); ?></h3>
                    <ul>
                        <li><strong>Indeed:</strong> <?php _e('Sign up for a publisher account at indeed.com/publisher', 'puntwork'); ?></li>
                        <li><strong>LinkedIn:</strong> <?php _e('Create an app at developers.linkedin.com and get OAuth 2.0 credentials', 'puntwork'); ?></li>
                        <li><strong>Glassdoor:</strong> <?php _e('Apply for API access at glassdoor.com/developer/index.htm', 'puntwork'); ?></li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render configuration fields for a specific job board
     */
    private static function render_board_config_fields(string $board_id, array $config): void {
        switch ($board_id) {
            case 'indeed':
                ?>
                <div class="puntwork-form-field">
                    <label for="indeed_publisher_id"><?php _e('Publisher ID', 'puntwork'); ?></label>
                    <input type="text" id="indeed_publisher_id" name="publisher_id" value="<?php echo esc_attr($config['publisher_id'] ?? ''); ?>" required>
                    <p class="description"><?php _e('Your Indeed Publisher ID', 'puntwork'); ?></p>
                </div>
                <?php
                break;

            case 'linkedin':
                ?>
                <div class="puntwork-form-field">
                    <label for="linkedin_access_token"><?php _e('Access Token', 'puntwork'); ?></label>
                    <input type="password" id="linkedin_access_token" name="access_token" value="<?php echo esc_attr($config['access_token'] ?? ''); ?>" required>
                    <p class="description"><?php _e('LinkedIn OAuth 2.0 access token', 'puntwork'); ?></p>
                </div>
                <?php
                break;

            case 'glassdoor':
                ?>
                <div class="puntwork-form-field">
                    <label for="glassdoor_partner_id"><?php _e('Partner ID', 'puntwork'); ?></label>
                    <input type="text" id="glassdoor_partner_id" name="partner_id" value="<?php echo esc_attr($config['partner_id'] ?? ''); ?>" required>
                </div>
                <div class="puntwork-form-field">
                    <label for="glassdoor_partner_key"><?php _e('Partner Key', 'puntwork'); ?></label>
                    <input type="password" id="glassdoor_partner_key" name="partner_key" value="<?php echo esc_attr($config['partner_key'] ?? ''); ?>" required>
                    <p class="description"><?php _e('Your Glassdoor API credentials', 'puntwork'); ?></p>
                </div>
                <?php
                break;
        }
    }

    /**
     * AJAX handler for testing job board connections
     */
    public static function ajax_test_job_board(): void {
        check_ajax_referer('puntwork_job_boards', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions'));
        }

        $board_id = sanitize_text_field($_POST['board_id'] ?? '');
        $config = $_POST['config'] ?? [];

        if (empty($board_id)) {
            wp_send_json_error(['message' => 'Board ID is required']);
        }

        // Sanitize config
        $config = self::sanitize_board_config($board_id, $config);

        try {
            require_once plugin_dir_path(dirname(__FILE__, 2)) . 'jobboards/jobboard-manager.php';

            // Create a temporary board instance for testing
            $available_boards = \Puntwork\JobBoards\JobBoardManager::getAvailableBoards();

            if (!isset($available_boards[$board_id])) {
                wp_send_json_error(['message' => 'Invalid board ID']);
            }

            $board_class = $available_boards[$board_id]['class'];
            $board = new $board_class($config);

            $test_result = [
                'board_id' => $board_id,
                'configured' => $board->isConfigured()
            ];

            if ($board->isConfigured()) {
                // Try a test request
                $test_jobs = $board->fetchJobs(['limit' => 1]);
                $test_result['success'] = true;
                $test_result['job_count'] = count($test_jobs);
                $test_result['message'] = sprintf(__('Connection successful! Found %d test jobs.', 'puntwork'), count($test_jobs));
            } else {
                $test_result['success'] = false;
                $test_result['message'] = __('Board is not properly configured.', 'puntwork');
            }

            wp_send_json_success($test_result);

        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
                'board_id' => $board_id
            ]);
        }
    }

    /**
     * AJAX handler for saving job board configurations
     */
    public static function ajax_save_job_board(): void {
        check_ajax_referer('puntwork_job_boards', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions'));
        }

        $board_id = sanitize_text_field($_POST['board_id'] ?? '');
        $config = $_POST['config'] ?? [];
        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === 'true';

        if (empty($board_id)) {
            wp_send_json_error(['message' => 'Board ID is required']);
        }

        // Sanitize config
        $config = self::sanitize_board_config($board_id, $config);
        $config['enabled'] = $enabled;

        try {
            require_once plugin_dir_path(dirname(__FILE__, 2)) . 'jobboards/jobboard-manager.php';

            $success = \Puntwork\JobBoards\JobBoardManager::configureBoard($board_id, $config);

            if ($success) {
                wp_send_json_success([
                    'message' => __('Settings saved successfully!', 'puntwork'),
                    'board_id' => $board_id
                ]);
            } else {
                wp_send_json_error(['message' => __('Failed to save settings.', 'puntwork')]);
            }

        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
                'board_id' => $board_id
            ]);
        }
    }

    /**
     * Sanitize board configuration data
     */
    private static function sanitize_board_config(string $board_id, array $config): array {
        $sanitized = [];

        foreach ($config as $key => $value) {
            if (is_string($value)) {
                $sanitized[$key] = sanitize_text_field($value);
            } elseif (is_array($value)) {
                $sanitized[$key] = array_map('sanitize_text_field', $value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}

// Initialize the admin page
JobBoardAdmin::init();