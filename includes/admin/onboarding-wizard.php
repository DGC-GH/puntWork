<?php

/**
 * Interactive Onboarding Wizard for puntWork
 * Guides new users through initial setup and configuration
 */

namespace Puntwork;

class PuntworkOnboardingWizard
{
    public function __construct()
    {
        $this->init();
    }

    public function init()
    {
        // Add AJAX handlers
        add_action('wp_ajax_puntwork_complete_onboarding', array( $this, 'completeOnboarding' ));

        // Add menu item to restart onboarding
        add_action('admin_menu', array( $this, 'addOnboardingMenuItem' ), 100);
    }

    public function addOnboardingMenuItem()
    {
        add_submenu_page(
            null, // Hidden page
            __('puntWork Onboarding', 'puntwork'),
            __('Onboarding', 'puntwork'),
            'manage_options',
            'puntwork-onboarding',
            array( $this, 'renderOnboardingPage' )
        );
    }

    public function renderOnboardingPage()
    {
        // Reset onboarding and redirect to dashboard
        delete_option('puntwork_onboarding_completed');
        wp_redirect(admin_url('admin.php?page=puntwork-dashboard&show_onboarding=1'));
        exit;
    }

    public function completeOnboarding()
    {
        // Verify nonce
        if (! wp_verify_nonce($_POST['nonce'] ?? '', 'puntwork_onboarding_nonce')) {
            wp_send_json_error(array( 'message' => __('Security check failed.', 'puntwork') ));
            return;
        }

        // Mark onboarding as completed
        update_option('puntwork_onboarding_completed', true);

        wp_send_json_success(
            array(
                'message' => __('Onboarding completed successfully!', 'puntwork'),
            )
        );
    }

    public static function isOnboardingCompleted()
    {
        return get_option('puntwork_onboarding_completed', false);
    }
}

// Initialize onboarding wizard only when WordPress is loaded and not in testing
if (! defined('PHPUNIT_RUNNING') && function_exists('add_action')) {
    add_action(
        'init',
        function () {
            if (class_exists('PuntworkOnboardingWizard')) {
                new PuntworkOnboardingWizard();
            }
        }
    );
}
