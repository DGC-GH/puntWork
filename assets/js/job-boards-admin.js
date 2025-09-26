/**
 * Job Board Admin JavaScript
 *
 * @package    Puntwork
 * @subpackage Admin
 * @since      2.2.0
 */

(function($) {
    'use strict';

    const JobBoardsAdmin = {

        init: function() {
            this.bindEvents();
            this.initializeToggles();
        },

        bindEvents: function() {
            const self = this;

            // Board enable/disable toggles
            $(document).on('change', '.puntwork-board-enabled', function() {
                const $card = $(this).closest('.puntwork-job-board-card');
                const $content = $card.find('.puntwork-job-board-content');
                const boardId = $card.data('board-id');
                const isEnabled = $(this).is(':checked');

                if (isEnabled) {
                    $card.removeClass('disabled').addClass('enabled');
                    $content.slideDown();
                } else {
                    $card.removeClass('enabled').addClass('disabled');
                    $content.slideUp();
                }
            });

            // Test connection buttons
            $(document).on('click', '.puntwork-test-connection', function(e) {
                e.preventDefault();
                const boardId = $(this).closest('.puntwork-job-board-card').data('board-id');
                self.testConnection(boardId, $(this));
            });

            // Save configuration buttons
            $(document).on('click', '.puntwork-save-config', function(e) {
                e.preventDefault();
                const boardId = $(this).closest('.puntwork-job-board-card').data('board-id');
                self.saveConfiguration(boardId, $(this));
            });
        },

        initializeToggles: function() {
            $('.puntwork-job-board-card').each(function() {
                const $card = $(this);
                const $toggle = $card.find('.puntwork-board-enabled');
                const $content = $card.find('.puntwork-job-board-content');

                if ($toggle.is(':checked')) {
                    $card.addClass('enabled');
                    $content.show();
                } else {
                    $card.addClass('disabled');
                    $content.hide();
                }
            });
        },

        testConnection: function(boardId, $button) {
            const self = this;
            const $card = $button.closest('.puntwork-job-board-card');
            const $form = $card.find('.puntwork-board-config-form');

            // Get form data
            const formData = this.getFormData($form);

            // Show loading state
            $button.addClass('puntwork-loading').text(puntworkJobBoards.strings.testing);

            // Remove any existing notices
            $card.find('.puntwork-notice').remove();

            $.ajax({
                url: puntworkJobBoards.ajax_url,
                type: 'POST',
                data: {
                    action: 'puntwork_test_job_board',
                    nonce: puntworkJobBoards.nonce,
                    board_id: boardId,
                    config: formData
                },
                success: function(response) {
                    self.showNotice($card, response.success ? 'success' : 'error',
                        response.success ? puntworkJobBoards.strings.test_success : puntworkJobBoards.strings.test_failed);

                    if (response.data) {
                        const message = response.data.message || '';
                        if (response.data.job_count !== undefined) {
                            self.showNotice($card, 'success', sprintf('Found %d test jobs', response.data.job_count));
                        }
                    }
                },
                error: function() {
                    self.showNotice($card, 'error', 'Connection test failed');
                },
                complete: function() {
                    $button.removeClass('puntwork-loading').text('Test Connection');
                }
            });
        },

        saveConfiguration: function(boardId, $button) {
            const self = this;
            const $card = $button.closest('.puntwork-job-board-card');
            const $form = $card.find('.puntwork-board-config-form');
            const $toggle = $card.find('.puntwork-board-enabled');

            // Get form data
            const formData = this.getFormData($form);
            formData.enabled = $toggle.is(':checked');

            // Show loading state
            $button.addClass('puntwork-loading').text(puntworkJobBoards.strings.saving);

            // Remove any existing notices
            $card.find('.puntwork-notice').remove();

            $.ajax({
                url: puntworkJobBoards.ajax_url,
                type: 'POST',
                data: {
                    action: 'puntwork_save_job_board',
                    nonce: puntworkJobBoards.nonce,
                    board_id: boardId,
                    config: formData,
                    enabled: formData.enabled
                },
                success: function(response) {
                    if (response.success) {
                        self.showNotice($card, 'success', puntworkJobBoards.strings.save_success);
                    } else {
                        self.showNotice($card, 'error', response.data?.message || puntworkJobBoards.strings.save_failed);
                    }
                },
                error: function() {
                    self.showNotice($card, 'error', puntworkJobBoards.strings.save_failed);
                },
                complete: function() {
                    $button.removeClass('puntwork-loading').text('Save Settings');
                }
            });
        },

        getFormData: function($form) {
            const data = {};

            $form.find('input, select, textarea').each(function() {
                const $field = $(this);
                const name = $field.attr('name');
                const value = $field.val();

                if (name) {
                    data[name] = value;
                }
            });

            return data;
        },

        showNotice: function($card, type, message) {
            const $notice = $('<div class="puntwork-notice ' + type + '">' + message + '</div>');
            $card.find('.puntwork-job-board-content').prepend($notice);

            // Auto-hide success notices after 5 seconds
            if (type === 'success') {
                setTimeout(function() {
                    $notice.fadeOut(function() {
                        $(this).remove();
                    });
                }, 5000);
            }
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        JobBoardsAdmin.init();
    });

})(jQuery);