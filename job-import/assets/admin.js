// assets/admin.js (Enhanced: Added AJAX call with nonce, error handling for debug.)

jQuery(document).ready(function($) {
    $('#trigger-import').on('click', function(e) {
        e.preventDefault();
        var nonce = $('[name="job_import_ajax_nonce"]').val(); // Assume added in admin.php
        $.post(ajaxurl, {
            action: 'trigger_import',
            nonce: nonce
        }, function(response) {
            if (response.success) {
                alert('Import triggered! Check logs.');
                location.reload();
            } else {
                alert('Error: ' + response.data);
                console.error('Import AJAX Error:', response);
            }
        }).fail(function() {
            console.error('AJAX Request Failed');
        });
    });
});

jQuery(document).ready(function($) {
    'use strict';

    var isImporting = false;
    var pollInterval;

    // Start import button click handler
    $('#start-import').on('click', function(e) {
        e.preventDefault();

        if (isImporting) {
            return; // Prevent multiple starts
        }

        var $button = $(this);
        var $progressBar = $('#progress-bar');
        var $progress = $('#progress');
        var $status = $('#status');

        // Disable button and show spinner
        $button.prop('disabled', true).append(' <span class="spinner is-active"></span>');

        // Start AJAX import
        $.post(jobImportAjax.ajaxurl, {
            action: 'job_import_start',
            nonce: jobImportAjax.nonce
        }, function(response) {
            if (response.success) {
                isImporting = true;
                $status.text('Import started...').removeClass('error success').addClass('processing'); // Custom class if needed in CSS
                $progressBar.addClass('show').fadeIn(200);
                startPolling();
            } else {
                $status.text('Error starting import: ' + (response.data || 'Unknown error')).addClass('error');
                $button.prop('disabled', false).find('.spinner').remove();
            }
        }).fail(function() {
            $status.text('Connection error. Please try again.').addClass('error');
            $button.prop('disabled', false).find('.spinner').remove();
        });
    });

    // Function to start polling for progress
    function startPolling() {
        pollInterval = setInterval(function() {
            $.post(jobImportAjax.ajaxurl, {
                action: 'job_import_progress',
                nonce: jobImportAjax.nonce
            }, function(response) {
                if (response.success && response.data) {
                    var data = response.data;
                    $progress.css('width', data.progress + '%').text(Math.round(data.progress) + '%');
                    $status.text(data.status || 'Processing...');

                    // Check if done
                    if (data.done || data.progress >= 100) {
                        stopPolling();
                        $status.removeClass('processing').addClass('success').text('Import completed!');
                        $('#start-import').prop('disabled', false).find('.spinner').remove();
                        isImporting = false;
                    }
                } else {
                    $status.text('Progress check failed.').addClass('error');
                    stopPolling();
                }
            }).fail(function() {
                $status.text('Connection lost during import.').addClass('error');
                stopPolling();
            });
        }, 1000); // Poll every second
    }

    // Stop polling
    function stopPolling() {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
    }

    // Optional: Handle form submission for settings (if needed)
    $('form').on('submit', function(e) {
        // Add any custom validation here
        // e.g., Check if feed URL is valid
    });

    // Clean up on page unload
    $(window).on('beforeunload', function() {
        stopPolling();
    });
});
