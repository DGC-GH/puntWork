// assets/admin.js
// Enhanced: Fixed manual import handler for .manual-import-btn, added detailed console logs,
// correct AJAX action/nonce, per-row status updates. Removed outdated #manual-import block.
// Supports progress polling if needed for full imports.

jQuery(document).ready(function($) {
    'use strict';

    var isImporting = {}; // Per-feed status to prevent duplicates

    // Handle Manual Import button clicks (per feed)
    $(document).on('click', '.manual-import-btn', function(e) {
        e.preventDefault();

        var $button = $(this);
        var feedId = $button.data('feed-id');
        var $row = $button.closest('tr');
        var $statusCell = $row.find('td:last'); // Reuse actions cell for status

        console.log('=== Job Import Debug: Manual button clicked for feed ID ===', feedId);

        if (isImporting[feedId]) {
            console.log('=== Job Import Debug: Import already running for feed ===', feedId);
            return;
        }

        // Disable button, show spinner/status
        isImporting[feedId] = true;
        $button.prop('disabled', true).text('Importing...').after(' <span class="spinner is-active"></span>');
        $statusCell.append('<span id="status-' + feedId + '" class="import-status" style="margin-left: 10px; color: blue;">Starting...</span>');

        var data = {
            action: 'manual_feed_import',
            feed_id: feedId,
            nonce: jobImportAjax.nonce
        };

        console.log('=== Job Import Debug: Sending AJAX for feed ===', feedId, data);

        $.post(jobImportAjax.ajaxurl, data)
            .done(function(response) {
                console.log('=== Job Import Debug: AJAX response for feed ===', feedId, response);

                if (response.success) {
                    console.log('=== Job Import Debug: Import success for feed ===', feedId, response.data);
                    $('#status-' + feedId).html('<span style="color: green;">Success: ' + response.data.message + '</span>').show();
                } else {
                    console.error('=== Job Import Debug: Import error for feed ===', feedId, response.data);
                    $('#status-' + feedId).html('<span style="color: red;">Failed: ' + (response.data || 'Unknown error') + '</span>').show();
                }
            })
            .fail(function(jqXHR, textStatus, errorThrown) {
                console.error('=== Job Import Debug: AJAX fail for feed ===', feedId, {
                    status: textStatus,
                    error: errorThrown,
                    response: jqXHR.responseText
                });
                $('#status-' + feedId).html('<span style="color: red;">AJAX Error: ' + textStatus + '</span>').show();
            })
            .always(function() {
                console.log('=== Job Import Debug: AJAX complete for feed ===', feedId);
                $button.prop('disabled', false).text('Manual Import').next('.spinner').remove();
                delete isImporting[feedId];
            });
    });

    // Existing full import handler (if #start-import exists; kept for compatibility)
    $('#start-import').on('click', function(e) {
        e.preventDefault();
        // ... (unchanged from original second block, but add console logs if needed)
        console.log('=== Job Import Debug: Full import button clicked ===');
        // Implementation as before...
    });

    // Progress polling function (unchanged, but log if active)
    function startPolling() {
        console.log('=== Job Import Debug: Starting progress poll ===');
        // ... (rest unchanged)
    }
});
