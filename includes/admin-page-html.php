<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function job_import_admin_page() {
    wp_enqueue_script('jquery');
    ?>
    <div class="wrap" style="max-width: 800px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #1d1d1f; padding: 0 20px;">
        <h1 style="font-size: 34px; font-weight: 600; text-align: center; margin: 40px 0 20px;">Job Import</h1>
        <div style="display: flex; justify-content: center; gap: 12px; margin-bottom: 32px;">
            <button id="start-import" class="button button-primary" style="border-radius: 8px; padding: 12px 24px; font-size: 16px; font-weight: 500; background-color: #007aff; border: none; color: white;">Start</button>
            <button id="resume-import" class="button button-secondary" style="display:none; border-radius: 8px; padding: 12px 24px; font-size: 16px; font-weight: 500; background-color: #f2f2f7; border: none; color: #007aff;">Continue</button>
            <button id="cancel-import" class="button button-secondary" style="display:none; border-radius: 8px; padding: 12px 24px; font-size: 16px; font-weight: 500; background-color: #ff3b30; border: none; color: white;">Stop</button>
        </div>
        <div id="import-progress" style="background-color: white; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); display: none;">
            <h2 id="progress-percent" style="font-size: 48px; font-weight: 600; text-align: center; margin: 0 0 16px; color: #007aff;">0%</h2>
            <div id="progress-bar" style="width: 100%; height: 6px; border-radius: 3px; background-color: #f2f2f7; display: flex; overflow: hidden;"></div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin: 16px 0;">
                <span id="time-elapsed" style="font-size: 16px; color: #8e8e93;">0s</span>
                <p id="status-message" style="font-size: 16px; color: #8e8e93; margin: 0;">Ready to start.</p>
                <span id="time-left" style="font-size: 16px; color: #8e8e93;">Calculating...</span>
            </div>
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; font-size: 14px;">
                <p style="margin: 0;">Total: <span id="total-items" style="font-weight: 500;">0</span></p>
                <p style="margin: 0;">Processed: <span id="processed-items" style="font-weight: 500;">0</span></p>
                <p style="margin: 0;">Created: <span id="created-items" style="font-weight: 500;">0</span></p>
                <p style="margin: 0;">Updated: <span id="updated-items" style="font-weight: 500;">0</span></p>
                <p style="margin: 0;">Skipped: <span id="skipped-items" style="font-weight: 500;">0</span></p>
                <p style="margin: 0;">Duplicated: <span id="duplicates-drafted" style="font-weight: 500;">0</span></p>
                <p style="margin: 0;">Unpublished: <span id="drafted-old" style="font-weight: 500;">0</span></p>
                <p style="margin: 0;">Left: <span id="items-left" style="font-weight: 500;">0</span></p>
            </div>
        </div>
        <div id="import-log" style="margin-top: 32px; background-color: white; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); display: none;">
            <h2 style="font-size: 20px; font-weight: 600; margin: 0 0 16px;">Import Log</h2>
            <textarea id="log-textarea" rows="10" style="width: 100%; border: 1px solid #d1d1d6; border-radius: 8px; padding: 12px; font-family: SFMono-Regular, monospace; font-size: 13px; background-color: #f9f9f9; resize: vertical;" readonly></textarea>
        </div>
    </div>
    <script>
        jQuery(document).ready(function($) {
    var isImporting = false;
    var segmentsCreated = false;

    function clearProgress() {
        segmentsCreated = false;
        $('#progress-bar').empty();
        $('#progress-percent').text('0%');
        $('#total-items').text(0);
        $('#processed-items').text(0);
        $('#created-items').text(0);
        $('#updated-items').text(0);
        $('#skipped-items').text(0);
        $('#duplicates-drafted').text(0);
        $('#drafted-old').text(0);
        $('#items-left').text(0);
        $('#log-textarea').val('');
        $('#status-message').text('Ready to start.');
        $('#time-elapsed').text('0s');
        $('#time-left').text('Calculating...');
    }

    function appendLogs(logs) {
        var logArea = $('#log-textarea');
        logs.forEach(function(log) {
            logArea.val(logArea.val() + log + '\n');
        });
        logArea.scrollTop(logArea[0].scrollHeight);
    }

    function formatTime(seconds) {
        var days = Math.floor(seconds / (3600 * 24));
        seconds -= days * 3600 * 24;
        var hours = Math.floor(seconds / 3600);
        seconds -= hours * 3600;
        var minutes = Math.floor(seconds / 60);
        seconds = Math.floor(seconds % 60);
        var formatted = '';
        if (days > 0) formatted += days + 'd ';
        if (hours > 0 || days > 0) formatted += hours + 'h ';
        if (minutes > 0 || hours > 0 || days > 0) formatted += minutes + 'm ';
        formatted += seconds + 's';
        return formatted;
    }

    function updateProgress(data) {
        console.log('Updating progress with data:', data);
        var percent = data.total > 0 ? Math.floor((data.processed / data.total) * 100) : 0;
        $('#progress-percent').text(percent + '%');
        if (!segmentsCreated && data.total > 0) {
            var container = $('#progress-bar');
            container.empty();
            for (var i = 0; i < 100; i++) {
                $('<div>').css({
                    width: '1%',
                    backgroundColor: '#f2f2f7',
                    borderRight: i < 99 ? '1px solid #d1d1d6' : 'none'
                }).appendTo(container);
            }
            segmentsCreated = true;
        }
        $('#progress-bar div:lt(' + percent + ')').css('backgroundColor', '#007aff');
        $('#total-items').text(data.total);
        $('#processed-items').text(data.processed);
        $('#created-items').text(data.created);
        $('#updated-items').text(data.updated);
        $('#skipped-items').text(data.skipped);
        $('#duplicates-drafted').text(data.duplicates_drafted);
        $('#drafted-old').text(data.drafted_old);
        var itemsLeft = data.total - data.processed;
        $('#items-left').text(isNaN(itemsLeft) ? 0 : itemsLeft);
        $('#status-message').text('Importing...');
        $('#time-elapsed').text(formatTime(data.time_elapsed));
        var timePerItem = 0;
        if (data.batch_processed && data.batch_time && data.batch_processed > 0) {
            timePerItem = data.batch_time / data.batch_processed;
        } else if (data.processed > 0 && data.time_elapsed > 0) {
            timePerItem = data.time_elapsed / data.processed;
        }
        if (timePerItem > 0) {
            var timeLeftSeconds = timePerItem * itemsLeft;
            var timeLeftFormatted = formatTime(timeLeftSeconds);
            $('#time-left').text(timeLeftFormatted);
        } else {
            $('#time-left').text('Calculating...');
        }
    }

    function runImportBatch(start) {
        console.log('Running import batch at start:', start);
        return $.ajax({
            url: ajaxurl,
            type: 'POST',
            timeout: 0,
            data: { action: 'run_job_import_batch', start: start, nonce: '<?php echo wp_create_nonce('job_import_nonce'); ?>' }
        });
    }

    async function handleImport(initialStart) {
        console.log('Handling import starting at:', initialStart);
        let response;
        try {
            response = await runImportBatch(initialStart);
            console.log('Import batch response:', response);
            if (response.success) {
                updateProgress(response.data);
                appendLogs(response.data.logs || []);
                let total = response.data.total;
                let current = response.data.processed;
                console.log('Initial current:', current, 'total:', total);
                while (current < total && isImporting) {
                    console.log('Continuing to next batch, current:' + current + ', total:' + total);
                    response = await runImportBatch(current);
                    console.log('Next batch response:', response);
                    if (response.success) {
                        updateProgress(response.data);
                        appendLogs(response.data.logs || []);
                        current = response.data.processed;
                    } else {
                        appendLogs(['Import batch error: ' + (response.message || 'Unknown')]);
                        $('#status-message').text('Error: ' + (response.message || 'Unknown'));
                        resetButtons();
                        break;
                    }
                }
                if (isImporting && current >= total) {
                    appendLogs(['Import complete, starting purge...']);
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: { action: 'job_import_purge', nonce: '<?php echo wp_create_nonce('job_import_nonce'); ?>' },
                        success: function(purgeResponse) {
                            console.log('Purge response:', purgeResponse);
                            appendLogs(['Purge completed']);
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: { action: 'get_job_import_status', nonce: '<?php echo wp_create_nonce('job_import_nonce'); ?>' },
                                success: function(finalResponse) {
                                    console.log('Final status response:', finalResponse);
                                    if (finalResponse.success) {
                                        updateProgress(finalResponse);
                                        appendLogs(finalResponse.logs || []);
                                    }
                                },
                                error: function(xhr, status, error) {
                                    console.error('Final status AJAX error:', error);
                                    appendLogs(['Final status AJAX error: ' + error]);
                                }
                            });
                            $('#status-message').text('Import Complete');
                            resetButtons();
                        },
                        error: function(xhr, status, error) {
                            console.error('Purge AJAX error:', error);
                            appendLogs(['Purge AJAX error: ' + error]);
                        }
                    });
                }
            } else {
                appendLogs(['Initial import batch error: ' + (response.message || 'Unknown')]);
                $('#status-message').text('Error: ' + (response.message || 'Unknown'));
                resetButtons();
            }
        } catch (e) {
            console.error('Handle import error:', e);
            appendLogs(['Handle import error: ' + e.message]);
            $('#status-message').text('Error: ' + e.message);
            resetButtons();
        }
    }

    function resetButtons() {
        isImporting = false;
        $('#cancel-import').hide();
        $('#resume-import').hide();
        $('#start-import').show();
    }

    function clearImportCancel() {
        return $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: { action: 'clear_import_cancel', nonce: '<?php echo wp_create_nonce('job_import_nonce'); ?>' },
            success: function(response) {
                console.log('Clear cancel response:', response);
            },
            error: function(xhr, status, error) {
                console.error('Clear cancel error:', error);
                appendLogs(['Clear cancel AJAX error: ' + error]);
            }
        });
    }

    $('#start-import').on('click', async function() {
        console.log('Start Import clicked');
        if (isImporting) return;
        isImporting = true;
        try {
            clearProgress();
            $('#start-import').hide();
            $('#cancel-import').show();
            $('#import-progress').show();
            $('#import-log').show();
            appendLogs(['Starting feed processing...']);
            $('#status-message').text('Processing feeds...');
            await $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: { action: 'reset_job_import', nonce: '<?php echo wp_create_nonce('job_import_nonce'); ?>' },
                success: function(resetResponse) {
                    if (resetResponse.success) {
                        appendLogs(['Import reset for fresh start']);
                    }
                }
            });
            const feeds = ['startpeople', 'internationalrecruitment', 'unique', 'expressmedical'];
            let total_items = 0;
            for (let feed of feeds) {
                $('#status-message').text(`Processing feed: ${feed}`);
                let response = await $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: { action: 'process_feed', feed_key: feed, nonce: '<?php echo wp_create_nonce('job_import_nonce'); ?>' }
                });
                console.log(`Process feed ${feed} response:`, response);
                if (response.success) {
                    appendLogs(response.data.logs || []);
                    total_items += response.data.item_count;
                } else {
                    throw new Error(`Processing feed ${feed} failed: ` + (response.message || 'Unknown error'));
                }
            }
            $('#status-message').text('Combining JSONL files...');
            let combineResponse = await $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: { action: 'combine_jsonl', total_items: total_items, nonce: '<?php echo wp_create_nonce('job_import_nonce'); ?>' }
            });
            console.log('Combine JSONL response:', combineResponse);
            if (combineResponse.success) {
                appendLogs(combineResponse.data.logs || []);
            } else {
                throw new Error('Combining JSONL failed: ' + (combineResponse.message || 'Unknown error'));
            }
            $('#status-message').text('Starting import...');
            await clearImportCancel();
            await handleImport(0);
        } catch (error) {
            console.error('Start import error:', error);
            appendLogs([error.message]);
            $('#status-message').text('Error: ' + error.message);
            resetButtons();
        }
    });

    $('#resume-import').on('click', async function() {
        console.log('Resume Import clicked');
        if (isImporting) return;
        isImporting = true;
        $('#start-import').hide();
        $('#resume-import').hide();
        $('#cancel-import').show();
        $('#import-progress').show();
        $('#import-log').show();
        await clearImportCancel();
        await handleImport(<?php echo (int) get_option('job_import_progress'); ?>);
    });

    $('#cancel-import').on('click', function() {
        console.log('Cancel Import clicked');
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: { action: 'cancel_job_import', nonce: '<?php echo wp_create_nonce('job_import_nonce'); ?>' },
            success: function(response) {
                console.log('Cancel response:', response);
                if (response.success) {
                    appendLogs(['Import cancelled']);
                    $('#status-message').text('Import Cancelled');
                    resetButtons();
                    $('#resume-import').show();
                    $('#start-import').text('Restart').show();
                }
            },
            error: function(xhr, status, error) {
                console.error('Cancel AJAX error:', error);
                appendLogs(['Cancel AJAX error: ' + error]);
            }
        });
    });

    // Initial status check (unchanged)
    $.ajax({
        url: ajaxurl,
        type: 'POST',
        data: { action: 'get_job_import_status', nonce: '<?php echo wp_create_nonce('job_import_nonce'); ?>' },
        success: function(response) {
            console.log('Initial status response:', response);
            if (response.success && response.processed > 0 && !response.complete) {
                updateProgress(response);
                appendLogs(response.logs);
                $('#resume-import').show();
                $('#start-import').text('Restart').on('click', async function() {
                    console.log('Restart clicked - resetting and starting over');
                    await $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: { action: 'reset_job_import', nonce: '<?php echo wp_create_nonce('job_import_nonce'); ?>' },
                        success: function(resetResponse) {
                            if (resetResponse.success) {
                                appendLogs(['Import reset for restart']);
                            }
                        }
                    });
                    // Proceed with start logic
                    $('#start-import').trigger('click'); // Reuse start handler after reset
                });
                $('#import-progress').show();
                $('#import-log').show();
                $('#status-message').text('Previous import interrupted. Continue?');
            } else {
                $('#resume-import').hide();
                $('#import-progress').hide();
                $('#import-log').hide();
            }
        },
        error: function(xhr, status, error) {
            console.error('Initial status AJAX error:', error);
            appendLogs(['Initial status AJAX error: ' + error]);
        }
    });
});
    </script>
    <?php
}
