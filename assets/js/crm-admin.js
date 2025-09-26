/**
 * CRM Admin JavaScript
 *
 * @package    Puntwork
 * @subpackage Admin
 * @since      2.3.0
 */

(function($) {
    'use strict';

    /**
     * CRM Admin Manager
     */
    const CRMAdmin = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Test connection buttons
            $(document).on('click', '.test-connection', this.testConnection.bind(this));

            // Save configuration buttons
            $(document).on('click', '.save-config', this.saveConfig.bind(this));

            // Sync test buttons
            $(document).on('click', '.sync-test', this.syncTest.bind(this));
        },

        testConnection: function(e) {
            e.preventDefault();

            const $button = $(e.target);
            const platformId = $button.data('platform');
            const $form = $(`.crm-config-form[data-platform="${platformId}"]`);
            const $messages = $(`.platform-messages[data-platform="${platformId}"]`);

            // Get form data
            const config = this.getFormData($form);

            // Show loading state
            this.setButtonLoading($button, true);
            $messages.empty();

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
                    this.setButtonLoading($button, false);

                    if (response.success) {
                        this.showMessage($messages, puntwork_crm_ajax.strings.connection_successful, 'success');
                    } else {
                        this.showMessage($messages, response.data.message || puntwork_crm_ajax.strings.connection_failed, 'error');
                    }
                },
                error: (xhr, status, error) => {
                    this.setButtonLoading($button, false);
                    this.showMessage($messages, puntwork_crm_ajax.strings.connection_failed + ': ' + error, 'error');
                }
            });
        },

        saveConfig: function(e) {
            e.preventDefault();

            const $button = $(e.target);
            const platformId = $button.data('platform');
            const $form = $(`.crm-config-form[data-platform="${platformId}"]`);
            const $messages = $(`.platform-messages[data-platform="${platformId}"]`);

            // Get form data
            const config = this.getFormData($form);

            // Validate required fields
            if (!this.validateConfig(platformId, config)) {
                this.showMessage($messages, 'Please fill in all required fields.', 'error');
                return;
            }

            // Show loading state
            this.setButtonLoading($button, true);
            $messages.empty();

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
                    this.setButtonLoading($button, false);

                    if (response.success) {
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
                        this.showMessage($messages, response.data.message || puntwork_crm_ajax.strings.config_save_failed, 'error');
                    }
                },
                error: (xhr, status, error) => {
                    this.setButtonLoading($button, false);
                    this.showMessage($messages, puntwork_crm_ajax.strings.config_save_failed + ': ' + error, 'error');
                }
            });
        },

        syncTest: function(e) {
            e.preventDefault();

            const $button = $(e.target);
            const platformId = $button.data('platform');
            const $messages = $(`.platform-messages[data-platform="${platformId}"]`);

            // Show loading state
            this.setButtonLoading($button, true);
            $messages.empty();

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
                    this.setButtonLoading($button, false);

                    if (response.success) {
                        this.showMessage($messages, puntwork_crm_ajax.strings.sync_test_completed, 'success');

                        // Show sync details
                        if (response.data && response.data.contact_id) {
                            const details = `<br><small>Contact ID: ${response.data.contact_id}${response.data.deal_id ? ', Deal ID: ' + response.data.deal_id : ''}</small>`;
                            $messages.find('.message').append(details);
                        }
                    } else {
                        this.showMessage($messages, response.data.message || puntwork_crm_ajax.strings.sync_test_failed, 'error');
                    }
                },
                error: (xhr, status, error) => {
                    this.setButtonLoading($button, false);
                    this.showMessage($messages, puntwork_crm_ajax.strings.sync_test_failed + ': ' + error, 'error');
                }
            });
        },

        getFormData: function($form) {
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
            return data;
        },

        validateConfig: function(platformId, config) {
            const requiredFields = this.getRequiredFields(platformId);

            for (const field of requiredFields) {
                if (!config[field] || config[field].trim() === '') {
                    return false;
                }
            }

            return true;
        },

        getRequiredFields: function(platformId) {
            // Define required fields for each platform
            const requiredFieldsMap = {
                hubspot: ['access_token'],
                salesforce: ['client_id', 'client_secret', 'username', 'password'],
                zoho: ['client_id', 'client_secret', 'refresh_token'],
                pipedrive: ['api_token']
            };

            return requiredFieldsMap[platformId] || [];
        },

        setButtonLoading: function($button, loading) {
            if (loading) {
                $button.addClass('loading').prop('disabled', true);
                const originalText = $button.text();
                $button.data('original-text', originalText);

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
                }
            }
        },

        showMessage: function($container, message, type) {
            const $message = $('<div class="message"></div>')
                .addClass(type)
                .html(message);

            $container.html($message);

            // Auto-hide success messages after 5 seconds
            if (type === 'success') {
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
        CRMAdmin.init();
    });

})(jQuery);