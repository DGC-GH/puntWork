/**
 * Social Media Admin JavaScript
 *
 * @package    Puntwork
 * @subpackage Admin
 * @since      2.2.0
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        initializeSocialMediaAdmin();
    });

    function initializeSocialMediaAdmin() {
        // Tab switching
        $('.tab-button').on('click', function() {
            const tabId = $(this).data('tab');

            $('.tab-button').removeClass('active');
            $(this).addClass('active');

            $('.tab-content').removeClass('active');
            $('#' + tabId + '-tab').addClass('active');
        });

        // Platform enable/disable toggle
        $('.platform-enabled').on('change', function() {
            const $card = $(this).closest('.platform-config-card');
            const $config = $card.find('.platform-config');

            if ($(this).is(':checked')) {
                $config.slideDown();
            } else {
                $config.slideUp();
            }
        });

        // Ads enable/disable toggle
        $('.ads-enabled').on('change', function() {
            const $card = $(this).closest('.platform-config-card');
            const $adsConfig = $card.find('.ads-config');

            if ($(this).is(':checked')) {
                $adsConfig.slideDown();
            } else {
                $adsConfig.slideUp();
            }
        });

                // Test platform connection
        $('.test-platform').on('click', function() {
            const $button = $(this);
            const $card = $button.closest('.platform-config-card');
            const $status = $card.find('.platform-status');
            const platformId = $card.data('platform');

            $button.prop('disabled', true).text('Testing...');
            $status.hide();

            const apiKey = '<?php echo esc_js( get_option( 'puntwork_api_key' ) ); ?>';
            const apiUrl = `${window.location.origin}/wp-json/puntwork/v1/social/test-platform?api_key=${encodeURIComponent(apiKey)}`;

            fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    platform_id: platformId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showStatus($status, puntwork_social_ajax.strings.test_success, 'success');
                } else {
                    showStatus($status, data.message || puntwork_social_ajax.strings.test_failed, 'error');
                }
            })
            .catch(error => {
                console.error('Error testing platform:', error);
                showStatus($status, puntwork_social_ajax.strings.test_failed, 'error');
            })
            .finally(() => {
                $button.prop('disabled', false).text('Test Connection');
            });
        });

        // Save platform configuration
        $('.save-platform').on('click', function() {
            const $button = $(this);
            const $card = $button.closest('.platform-config-card');
            const $status = $card.find('.platform-status');
            const platformId = $card.data('platform');

            // Collect config data
            const config = {};
            $card.find('input[name], select[name], textarea[name]').each(function() {
                const name = $(this).attr('name');
                const value = $(this).val();
                if (name && value) {
                    config[name] = value;
                }
            });

            // Add enabled status
            config.enabled = $card.find('.platform-enabled').is(':checked') ? 1 : 0;

            $button.prop('disabled', true).text(puntwork_social_ajax.strings.saving);
            $status.hide();

            const apiKey = '<?php echo esc_js( get_option( 'puntwork_api_key' ) ); ?>';
            const apiUrl = `${window.location.origin}/wp-json/puntwork/v1/social/save-config?api_key=${encodeURIComponent(apiKey)}`;

            fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    platform_id: platformId,
                    config: config
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showStatus($status, puntwork_social_ajax.strings.save_success, 'success');
                } else {
                    showStatus($status, data.message || puntwork_social_ajax.strings.save_failed, 'error');
                }
            })
            .catch(error => {
                console.error('Error saving config:', error);
                showStatus($status, puntwork_social_ajax.strings.save_failed, 'error');
            })
            .finally(() => {
                $button.prop('disabled', false).text('Save Configuration');
            });
        });

        // Save posting settings
        $('#save-posting-settings').on('click', function() {
            const $button = $(this);
            const settings = {
                auto_post_jobs: $('input[name="auto_post_jobs"]').is(':checked') ? 1 : 0,
                default_platforms: [],
                post_template: $('select[name="post_template"]').val()
            };

            $('input[name="default_platforms[]"]:checked').each(function() {
                settings.default_platforms.push($(this).val());
            });

            $button.prop('disabled', true).text(puntwork_social_ajax.strings.saving);

            // Save settings via AJAX calls - these are WordPress core options, keep as AJAX
            const promises = [];

            promises.push($.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'update_option',
                    option_name: 'puntwork_social_auto_post_jobs',
                    option_value: settings.auto_post_jobs
                }
            }));

            promises.push($.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'update_option',
                    option_name: 'puntwork_social_default_platforms',
                    option_value: settings.default_platforms
                }
            }));

            promises.push($.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'update_option',
                    option_name: 'puntwork_social_post_template',
                    option_value: settings.post_template
                }
            }));

            $.when.apply($, promises).then(function() {
                alert(puntwork_social_ajax.strings.save_success);
            }).fail(function() {
                alert(puntwork_social_ajax.strings.save_failed);
            }).always(function() {
                $button.prop('disabled', false).text('Save Settings');
            });
        });

        // Manual posting
        $('#post-manually').on('click', function() {
            const $button = $(this);
            const $status = $('#manual-post-status');
            const content = $('#manual-post-content').val();
            const platforms = [];

            $('.manual-post-platform:checked').each(function() {
                platforms.push($(this).val());
            });

            if (!content.trim()) {
                alert('Please enter content to post.');
                return;
            }

            if (platforms.length === 0) {
                alert('Please select at least one platform.');
                return;
            }

            $button.prop('disabled', true).text(puntwork_social_ajax.strings.posting);
            $status.hide();

            const apiKey = '<?php echo esc_js( get_option( 'puntwork_api_key' ) ); ?>';
            const apiUrl = `${window.location.origin}/wp-json/puntwork/v1/social/post?api_key=${encodeURIComponent(apiKey)}`;

            fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    content: content,
                    platforms: platforms
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showStatus($status, puntwork_social_ajax.strings.post_success, 'success');
                    $('#manual-post-content').val('');
                    $('.manual-post-platform').prop('checked', false);

                    // Show results
                    let resultsHtml = '<br><strong>Results:</strong><br>';
                    $.each(data.data.results, function(platform, result) {
                        const status = result.success ? '✓ Success' : '✗ Failed';
                        resultsHtml += platform + ': ' + status + '<br>';
                    });
                    $status.append(resultsHtml);

                } else {
                    showStatus($status, data.message || puntwork_social_ajax.strings.post_failed, 'error');
                }
            })
            .catch(error => {
                console.error('Error posting:', error);
                showStatus($status, puntwork_social_ajax.strings.post_failed, 'error');
            })
            .finally(() => {
                $button.prop('disabled', false).text('Post Now');
            });
        });

        // Save platform configuration
        $('.save-platform').on('click', function() {
            const $button = $(this);
            const $card = $button.closest('.platform-config-card');
            const $status = $card.find('.platform-status');
            const platformId = $card.data('platform');

            // Collect config data
            const config = {};
            $card.find('input[name], select[name], textarea[name]').each(function() {
                const name = $(this).attr('name');
                const value = $(this).val();
                if (name && value) {
                    config[name] = value;
                }
            });

            // Add enabled status
            config.enabled = $card.find('.platform-enabled').is(':checked') ? 1 : 0;

            $button.prop('disabled', true).text(puntwork_social_ajax.strings.saving);
            $status.hide();

            $.ajax({
                url: puntwork_social_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'puntwork_social_save_config',
                    nonce: puntwork_social_ajax.nonce,
                    platform_id: platformId,
                    config: config
                },
                success: function(response) {
                    if (response.success) {
                        showStatus($status, puntwork_social_ajax.strings.save_success, 'success');
                    } else {
                        showStatus($status, response.data.message || puntwork_social_ajax.strings.save_failed, 'error');
                    }
                },
                error: function() {
                    showStatus($status, puntwork_social_ajax.strings.save_failed, 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Save Configuration');
                }
            });
        });

        // Save posting settings
        $('#save-posting-settings').on('click', function() {
            const $button = $(this);
            const settings = {
                auto_post_jobs: $('input[name="auto_post_jobs"]').is(':checked') ? 1 : 0,
                default_platforms: [],
                post_template: $('select[name="post_template"]').val()
            };

            $('input[name="default_platforms[]"]:checked').each(function() {
                settings.default_platforms.push($(this).val());
            });

            $button.prop('disabled', true).text(puntwork_social_ajax.strings.saving);

            // Save settings via AJAX calls
            const promises = [];

            promises.push($.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'update_option',
                    option_name: 'puntwork_social_auto_post_jobs',
                    option_value: settings.auto_post_jobs
                }
            }));

            promises.push($.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'update_option',
                    option_name: 'puntwork_social_default_platforms',
                    option_value: settings.default_platforms
                }
            }));

            promises.push($.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'update_option',
                    option_name: 'puntwork_social_post_template',
                    option_value: settings.post_template
                }
            }));

            $.when.apply($, promises).then(function() {
                alert(puntwork_social_ajax.strings.save_success);
            }).fail(function() {
                alert(puntwork_social_ajax.strings.save_failed);
            }).always(function() {
                $button.prop('disabled', false).text('Save Settings');
            });
        });

        // Manual posting
        $('#post-manually').on('click', function() {
            const $button = $(this);
            const $status = $('#manual-post-status');
            const content = $('#manual-post-content').val();
            const platforms = [];

            $('.manual-post-platform:checked').each(function() {
                platforms.push($(this).val());
            });

            if (!content.trim()) {
                alert('Please enter content to post.');
                return;
            }

            if (platforms.length === 0) {
                alert('Please select at least one platform.');
                return;
            }

            $button.prop('disabled', true).text(puntwork_social_ajax.strings.posting);
            $status.hide();

            $.ajax({
                url: puntwork_social_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'puntwork_social_post_now',
                    nonce: puntwork_social_ajax.nonce,
                    content: content,
                    platforms: platforms
                },
                success: function(response) {
                    if (response.success) {
                        showStatus($status, puntwork_social_ajax.strings.post_success, 'success');
                        $('#manual-post-content').val('');
                        $('.manual-post-platform').prop('checked', false);

                        // Show results
                        let resultsHtml = '<br><strong>Results:</strong><br>';
                        $.each(response.data.results, function(platform, result) {
                            const status = result.success ? '✓ Success' : '✗ Failed';
                            resultsHtml += platform + ': ' + status + '<br>';
                        });
                        $status.append(resultsHtml);

                    } else {
                        showStatus($status, response.data.message || puntwork_social_ajax.strings.post_failed, 'error');
                    }
                },
                error: function() {
                    showStatus($status, puntwork_social_ajax.strings.post_failed, 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Post Now');
                }
            });
        });
    }

    function showStatus($element, message, type) {
        $element.removeClass('status-success status-error')
                .addClass('status-' + type)
                .html(message)
                .show();
    }

})(jQuery);