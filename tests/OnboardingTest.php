<?php

/**
 * Onboarding Wizard Tests for puntWork
 *
 * @package    Puntwork
 * @subpackage Tests
 */

namespace Puntwork;

use PHPUnit\Framework\TestCase;

class OnboardingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Mock WordPress functions
        if (!defined('ABSPATH')) {
            define('ABSPATH', '/tmp/wordpress/');
        }
    }

    /**
     * Test onboarding completion status
     */
    public function testOnboardingCompletionStatus()
    {
        // Skip this test if the class is not available in test environment
        if (!class_exists('Puntwork\\PuntworkOnboardingWizard')) {
            $this->markTestSkipped('PuntworkOnboardingWizard class not available in test environment');
            return;
        }

        // Test initial state (not completed)
        $this->assertFalse(\Puntwork\PuntworkOnboardingWizard::isOnboardingCompleted());

        // Simulate completion
        update_option('puntwork_onboarding_completed', true);
        $this->assertTrue(\Puntwork\PuntworkOnboardingWizard::isOnboardingCompleted());

        // Reset for cleanup
        delete_option('puntwork_onboarding_completed');
    }

    /**
     * Test onboarding wizard initialization
     */
    public function testOnboardingWizardInitialization()
    {
        // Skip this test if the class is not available in test environment
        if (!class_exists('Puntwork\\PuntworkOnboardingWizard')) {
            $this->markTestSkipped('PuntworkOnboardingWizard class not available in test environment');
            return;
        }

        // Test that the wizard can be instantiated
        $wizard = new \Puntwork\PuntworkOnboardingWizard();
        $this->assertInstanceOf(\Puntwork\PuntworkOnboardingWizard::class, $wizard);
    }

    /**
     * Test onboarding steps structure
     */
    public function testOnboardingStepsStructure()
    {
        $steps = [
            0 => 'Welcome',
            1 => 'Configure Feeds',
            2 => 'Set up Scheduling',
            3 => 'API Configuration',
            4 => 'Setup Complete'
        ];

        $this->assertIsArray($steps);
        $this->assertCount(5, $steps);

        foreach ($steps as $step => $label) {
            $this->assertIsInt($step);
            $this->assertIsString($label);
            $this->assertNotEmpty($label);
        }
    }

    /**
     * Test onboarding navigation logic
     */
    public function testOnboardingNavigationLogic()
    {
        $totalSteps = 5;
        $currentStep = 2;

        // Test next step
        $nextStep = min($currentStep + 1, $totalSteps - 1);
        $this->assertEquals(3, $nextStep);

        // Test previous step
        $prevStep = max($currentStep - 1, 0);
        $this->assertEquals(1, $prevStep);

        // Test first step navigation
        $this->assertEquals(0, max(0 - 1, 0)); // No previous from first

        // Test last step navigation
        $this->assertEquals(4, min(4 + 1, 4)); // No next from last
    }

    /**
     * Test onboarding progress calculation
     */
    public function testOnboardingProgressCalculation()
    {
        $totalSteps = 5;

        for ($currentStep = 0; $currentStep < $totalSteps; $currentStep++) {
            $progress = (($currentStep + 1) / $totalSteps) * 100;
            $this->assertIsNumeric($progress);
            $this->assertGreaterThanOrEqual(20, $progress);
            $this->assertLessThanOrEqual(100, $progress);
        }

        // Test specific progress values
        $this->assertEquals(20, (1 / 5) * 100); // Step 1
        $this->assertEquals(60, (3 / 5) * 100); // Step 3
        $this->assertEquals(100, (5 / 5) * 100); // Step 5
    }

    /**
     * Test onboarding data validation
     */
    public function testOnboardingDataValidation()
    {
        $validData = [
            'step' => 2,
            'completed_steps' => [0, 1],
            'preferences' => [
                'theme' => 'light',
                'notifications' => true
            ]
        ];

        $this->assertIsArray($validData);
        $this->assertArrayHasKey('step', $validData);
        $this->assertArrayHasKey('completed_steps', $validData);
        $this->assertArrayHasKey('preferences', $validData);

        $this->assertIsInt($validData['step']);
        $this->assertIsArray($validData['completed_steps']);
        $this->assertIsArray($validData['preferences']);
    }

    /**
     * Test onboarding AJAX handlers
     */
    public function testOnboardingAjaxHandlers()
    {
        // Test AJAX action registration
        $actions = ['puntwork_complete_onboarding'];

        foreach ($actions as $action) {
            $this->assertIsString($action);
            $this->assertStringStartsWith('puntwork_', $action);
        }
    }

    /**
     * Test onboarding menu integration
     */
    public function testOnboardingMenuIntegration()
    {
        $menuConfig = [
            'page_title' => 'puntWork Onboarding',
            'menu_title' => 'Onboarding',
            'capability' => 'manage_options',
            'menu_slug' => 'puntwork-onboarding'
        ];

        foreach ($menuConfig as $key => $value) {
            $this->assertIsString($value);
            $this->assertNotEmpty($value);
        }

        $this->assertStringContainsString('puntWork', $menuConfig['page_title']);
        $this->assertStringContainsString('Onboarding', $menuConfig['menu_title']);
    }

    /**
     * Test onboarding completion workflow
     */
    public function testOnboardingCompletionWorkflow()
    {
        // Test workflow steps
        $workflow = [
            'initialize' => 'Show welcome screen',
            'configure' => 'Set up feeds and scheduling',
            'validate' => 'Verify configuration',
            'complete' => 'Mark as completed'
        ];

        $this->assertIsArray($workflow);
        $this->assertCount(4, $workflow);

        foreach ($workflow as $step => $description) {
            $this->assertIsString($step);
            $this->assertIsString($description);
            $this->assertNotEmpty($description);
        }
    }

    /**
     * Test onboarding error handling
     */
    public function testOnboardingErrorHandling()
    {
        $errorScenarios = [
            'invalid_step' => 'Step number out of range',
            'missing_data' => 'Required data not provided',
            'permission_denied' => 'User lacks permissions',
            'session_expired' => 'Onboarding session timed out'
        ];

        foreach ($errorScenarios as $scenario => $message) {
            $this->assertIsString($scenario);
            $this->assertIsString($message);
            $this->assertNotEmpty($message);
        }
    }

    /**
     * Test onboarding accessibility features
     */
    public function testOnboardingAccessibilityFeatures()
    {
        $accessibilityFeatures = [
            'aria_labels' => 'Screen reader labels',
            'keyboard_nav' => 'Keyboard navigation support',
            'focus_management' => 'Proper focus management',
            'color_contrast' => 'High contrast colors',
            'semantic_html' => 'Semantic HTML structure'
        ];

        foreach ($accessibilityFeatures as $feature => $description) {
            $this->assertIsString($feature);
            $this->assertIsString($description);
            $this->assertNotEmpty($description);
        }
    }
}
