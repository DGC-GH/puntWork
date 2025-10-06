/**
 * CRM Admin JavaScript
 *
 * @package    Puntwork
 * @subpackage Admin
 * @since      0.0.7
 */

console.log('[PUNTWORK] crm-admin.js loaded - DEBUG MODE');
console.log('[PUNTWORK] Current timestamp:', new Date().toISOString());
console.log('[PUNTWORK] Browser info:', navigator.userAgent);
console.log('[PUNTWORK] Window location:', window.location.href);
console.log('[PUNTWORK] jQuery version:', $.fn.jquery);

(function($) {
    'use strict';

    /**
     * CRM Admin Manager
     */
    const CRMAdmin = {
        init: function() {
            console.log('[PUNTWORK] [CRM-INIT] CRMAdmin.init() called');
            console.log('[PUNTWORK] [CRM-INIT] Document ready state:', document.readyState);
            console.log('[PUNTWORK] [CRM-INIT] puntwork_crm_ajax available:', typeof puntwork_crm_ajax);
            if (typeof puntwork_crm_ajax !== 'undefined') {
                console.log('[PUNTWORK] [CRM-INIT] AJAX URL:', puntwork_crm_ajax.ajax_url);
                console.log('[PUNTWORK] [CRM-INIT] Nonce:', puntwork_crm_ajax.nonce);
            }

            this.bindEvents();
            console.log('[PUNTWORK] [CRM-INIT] CRMAdmin initialization completed');
        },

        bindEvents: function() {
            console.log('[PUNTWORK] [CRM-EVENTS] Binding CRM admin events');

            // Test connection buttons
            $(document).on('click', '.test-connection', this.testConnection.bind(this));
            console.log('[PUNTWORK] [CRM-EVENTS] Test connection event bound');

            // Save configuration buttons
            $(document).on('click', '.save-config', this.saveConfig.bind(this));
            console.log('[PUNTWORK] [CRM-EVENTS] Save config event bound');

            // Sync test buttons
            $(document).on('click', '.sync-test', this.syncTest.bind(this));
            console.log('[PUNTWORK] [CRM-EVENTS] Sync test event bound');

            console.log('[PUNTWORK] [CRM-EVENTS] All CRM events bound successfully');
        },

        testConnection: function(e) {
            console.log('[PUNTWORK] [CRM-TEST] testConnection called');
            e.preventDefault();

            const $button = $(e.target);
            const platformId = $button.data('platform');
            console.log('[PUNTWORK] [CRM-TEST] Testing connection for platform:', platformId);

            const $form = $(`.crm-config-form[data-platform="${platformId}"]`);
            const $messages = $(`.platform-messages[data-platform="${platformId}"]`);

            console.log('[PUNTWORK] [CRM-TEST] Form found:', $form.length > 0);
            console.log('[PUNTWORK] [CRM-TEST] Messages container found:', $messages.length > 0);

            // Get form data
            const config = this.getFormData($form);
            console.log('[PUNTWORK] [CRM-TEST] Form data retrieved:', Object.keys(config));

            // Show loading state
            this.setButtonLoading($button, true);
            $messages.empty();

            console.log('[PUNTWORK] [CRM-TEST] Making AJAX request to test connection');

            // Make AJAX request
            $.ajax({
                url: puntwork_crm_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'puntwork_crm_test_connection',
                    nonce: puntwork_crm_ajax.nonce,
                    platform_id: platformId,
                    config: config
                },
                success: (response) => {
                    console.log('[PUNTWORK] [CRM-TEST] AJAX success response received');
                    console.log('[PUNTWORK] [CRM-TEST] Response success:', response.success);
                    console.log('[PUNTWORK] [CRM-TEST] Response data:', response.data);
                    this.setButtonLoading($button, false);

                    if (response.success) {
                        console.log('[PUNTWORK] [CRM-TEST] Connection test successful');
                        this.showMessage($messages, puntwork_crm_ajax.strings.connection_successful, 'success');
                    } else {
                        console.error('[PUNTWORK] [CRM-TEST] Connection test failed:', response.data.message);
                        this.showMessage($messages, response.data.message || puntwork_crm_ajax.strings.connection_failed, 'error');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('[PUNTWORK] [CRM-TEST] AJAX error occurred');
                    console.error('[PUNTWORK] [CRM-TEST] Status:', status);
                    console.error('[PUNTWORK] [CRM-TEST] Error:', error);
                    console.error('[PUNTWORK] [CRM-TEST] Response text:', xhr.responseText);
                    this.setButtonLoading($button, false);
                    this.showMessage($messages, puntwork_crm_ajax.strings.connection_failed + ': ' + error, 'error');
                }
            });
        },

        saveConfig: function(e) {
            console.log('[PUNTWORK] [CRM-SAVE] saveConfig called');
            e.preventDefault();

            const $button = $(e.target);
            const platformId = $button.data('platform');
            console.log('[PUNTWORK] [CRM-SAVE] Saving config for platform:', platformId);

            const $form = $(`.crm-config-form[data-platform="${platformId}"]`);
            const $messages = $(`.platform-messages[data-platform="${platformId}"]`);

            console.log('[PUNTWORK] [CRM-SAVE] Form found:', $form.length > 0);
            console.log('[PUNTWORK] [CRM-SAVE] Messages container found:', $messages.length > 0);

            // Get form data
            const config = this.getFormData($form);
            console.log('[PUNTWORK] [CRM-SAVE] Form data retrieved:', Object.keys(config));

            // Validate required fields
            if (!this.validateConfig(platformId, config)) {
                console.error('[PUNTWORK] [CRM-SAVE] Config validation failed - missing required fields');
                this.showMessage($messages, 'Please fill in all required fields.', 'error');
                return;
            }
            console.log('[PUNTWORK] [CRM-SAVE] Config validation passed');

            // Show loading state
            this.setButtonLoading($button, true);
            $messages.empty();

            console.log('[PUNTWORK] [CRM-SAVE] Making AJAX request to save config');

            // Make AJAX request
            $.ajax({
                url: puntwork_crm_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'puntwork_crm_save_config',
                    nonce: puntwork_crm_ajax.nonce,
                    platform_id: platformId,
                    config: config
                },
                success: (response) => {
                    console.log('[PUNTWORK] [CRM-SAVE] AJAX success response received');
                    console.log('[PUNTWORK] [CRM-SAVE] Response success:', response.success);
                    console.log('[PUNTWORK] [CRM-SAVE] Response data:', response.data);
                    this.setButtonLoading($button, false);

                    if (response.success) {
                        console.log('[PUNTWORK] [CRM-SAVE] Config saved successfully');
                        this.showMessage($messages, puntwork_crm_ajax.strings.config_saved, 'success');

                        // Update status badge
                        const $statusBadge = $button.closest('.crm-platform-card').find('.status-badge');
                        if (config.enabled) {
                            $statusBadge.removeClass('status-inactive').addClass('status-active').text('Active');
                        } else {
                            $statusBadge.removeClass('status-active').addClass('status-inactive').text('Not Configured');
                        }

                        // Reload page after short delay to show sync test button
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    } else {
                        console.error('[PUNTWORK] [CRM-SAVE] Config save failed:', response.data.message);
                        this.showMessage($messages, response.data.message || puntwork_crm_ajax.strings.config_save_failed, 'error');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('[PUNTWORK] [CRM-SAVE] AJAX error occurred');
                    console.error('[PUNTWORK] [CRM-SAVE] Status:', status);
                    console.error('[PUNTWORK] [CRM-SAVE] Error:', error);
                    console.error('[PUNTWORK] [CRM-SAVE] Response text:', xhr.responseText);
                    this.setButtonLoading($button, false);
                    this.showMessage($messages, puntwork_crm_ajax.strings.config_save_failed + ': ' + error, 'error');
                }
            });
        },

        syncTest: function(e) {
            console.log('[PUNTWORK] [CRM-SYNC] syncTest called');
            e.preventDefault();

            const $button = $(e.target);
            const platformId = $button.data('platform');
            console.log('[PUNTWORK] [CRM-SYNC] Running sync test for platform:', platformId);

            const $messages = $(`.platform-messages[data-platform="${platformId}"]`);
            console.log('[PUNTWORK] [CRM-SYNC] Messages container found:', $messages.length > 0);

            // Show loading state
            this.setButtonLoading($button, true);
            $messages.empty();

            console.log('[PUNTWORK] [CRM-SYNC] Making AJAX request for sync test');

            // Make AJAX request
            $.ajax({
                url: puntwork_crm_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'puntwork_crm_sync_test',
                    nonce: puntwork_crm_ajax.nonce,
                    platform_id: platformId
                },
                success: (response) => {
                    console.log('[PUNTWORK] [CRM-SYNC] AJAX success response received');
                    console.log('[PUNTWORK] [CRM-SYNC] Response success:', response.success);
                    console.log('[PUNTWORK] [CRM-SYNC] Response data:', response.data);
                    this.setButtonLoading($button, false);

                    if (response.success) {
                        console.log('[PUNTWORK] [CRM-SYNC] Sync test completed successfully');
                        this.showMessage($messages, puntwork_crm_ajax.strings.sync_test_completed, 'success');

                        // Show sync details
                        if (response.data && response.data.contact_id) {
                            const details = `<br><small>Contact ID: ${response.data.contact_id}${response.data.deal_id ? ', Deal ID: ' + response.data.deal_id : ''}</small>`;
                            $messages.find('.message').append(details);
                        }
                    } else {
                        console.error('[PUNTWORK] [CRM-SYNC] Sync test failed:', response.data.message);
                        this.showMessage($messages, response.data.message || puntwork_crm_ajax.strings.sync_test_failed, 'error');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('[PUNTWORK] [CRM-SYNC] AJAX error occurred');
                    console.error('[PUNTWORK] [CRM-SYNC] Status:', status);
                    console.error('[PUNTWORK] [CRM-SYNC] Error:', error);
                    console.error('[PUNTWORK] [CRM-SYNC] Response text:', xhr.responseText);
                    this.setButtonLoading($button, false);
                    this.showMessage($messages, puntwork_crm_ajax.strings.sync_test_failed + ': ' + error, 'error');
                }
            });
        },

        getFormData: function($form) {
            console.log('[PUNTWORK] [CRM-UTILS] getFormData called');
            const data = {};
            $form.find('input, textarea, select').each(function() {
                const $field = $(this);
                const name = $field.attr('name');
                let value = $field.val();

                if ($field.attr('type') === 'checkbox') {
                    value = $field.is(':checked') ? '1' : '0';
                }

                if (name) {
                    data[name] = value;
                }
            });
            console.log('[PUNTWORK] [CRM-UTILS] Form data collected:', Object.keys(data).length, 'fields');
            return data;
        },

        validateConfig: function(platformId, config) {
            console.log('[PUNTWORK] [CRM-VALIDATE] validateConfig called for platform:', platformId);
            const requiredFields = this.getRequiredFields(platformId);
            console.log('[PUNTWORK] [CRM-VALIDATE] Required fields for', platformId, ':', requiredFields);

            for (const field of requiredFields) {
                if (!config[field] || config[field].trim() === '') {
                    console.error('[PUNTWORK] [CRM-VALIDATE] Validation failed - missing field:', field);
                    return false;
                }
            }
            console.log('[PUNTWORK] [CRM-VALIDATE] Config validation passed');
            return true;
        },

        getRequiredFields: function(platformId) {
            console.log('[PUNTWORK] [CRM-UTILS] getRequiredFields called for platform:', platformId);
            // Define required fields for each platform
            const requiredFieldsMap = {
                hubspot: ['access_token'],
                salesforce: ['client_id', 'client_secret', 'username', 'password'],
                zoho: ['client_id', 'client_secret', 'refresh_token'],
                pipedrive: ['api_token']
            };

            const fields = requiredFieldsMap[platformId] || [];
            console.log('[PUNTWORK] [CRM-UTILS] Required fields returned:', fields);
            return fields;
        },

        setButtonLoading: function($button, loading) {
            console.log('[PUNTWORK] [CRM-UTILS] setButtonLoading called, loading:', loading);
            if (loading) {
                $button.addClass('loading').prop('disabled', true);
                const originalText = $button.text();
                $button.data('original-text', originalText);
                console.log('[PUNTWORK] [CRM-UTILS] Button set to loading state, original text:', originalText);

                // Update button text based on action
                if ($button.hasClass('test-connection')) {
                    $button.text(puntwork_crm_ajax.strings.testing_connection);
                } else if ($button.hasClass('save-config')) {
                    $button.text(puntwork_crm_ajax.strings.saving_config);
                } else if ($button.hasClass('sync-test')) {
                    $button.text(puntwork_crm_ajax.strings.sync_test_started);
                }
            } else {
                $button.removeClass('loading').prop('disabled', false);
                const originalText = $button.data('original-text');
                if (originalText) {
                    $button.text(originalText);
                    console.log('[PUNTWORK] [CRM-UTILS] Button restored to original text:', originalText);
                }
            }
        },

        showMessage: function($container, message, type) {
            console.log('[PUNTWORK] [CRM-UTILS] showMessage called, type:', type, 'message length:', message.length);
            const $message = $('<div class="message"></div>')
                .addClass(type)
                .html(message);

            $container.html($message);
            console.log('[PUNTWORK] [CRM-UTILS] Message displayed in container');

            // Auto-hide success messages after 5 seconds
            if (type === 'success') {
                console.log('[PUNTWORK] [CRM-UTILS] Success message will auto-hide in 5 seconds');
                setTimeout(() => {
                    $message.fadeOut(300, function() {
                        $(this).remove();
                    });
                }, 5000);
            }
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        console.log('[PUNTWORK] [CRM-INIT] Document ready, initializing CRMAdmin');
        CRMAdmin.init();
        console.log('[PUNTWORK] [CRM-INIT] CRMAdmin initialization completed');
    });

})(jQuery);