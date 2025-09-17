jQuery(document).ready(function($) {
    console.log('Job Import JS loaded - ready for interactions');
    const startBtn = $('#start-import');
    const resumeBtn = $('#resume-import');
    const cancelBtn = $('#cancel-import');
    const resetBtn = $('#reset-import');
    const progressDiv = $('#import-progress');
    const logDiv = $('#import-log');
    const logContent = $('#log-content');
    let intervalId;
    let isRunning = false;

    function updateProgress() {
        console.log('updateProgress called - polling status');
        $.post(ajaxurl, {
            action: 'get_job_import_status',
            nonce: jobImportData.nonce
        })
        .done(function(response) {
            console.log('Status poll response:', response);
            if (response.success) {
                const data = response.data;
                const percent = data.total > 0 ? Math.round((data.processed / data.total) * 100) : 0;
                $('#progress-percent').text(percent + '%');
                $('#progress-bar #progress-fill').css('width', percent + '%');
                $('#total-items').text(data.total);
                $('#processed-items').text(data.processed);
                $('#created-items').text(data.created);
                $('#updated-items').text(data.updated);
                $('#skipped-items').text(data.skipped);
                $('#duplicates-drafted').text(data.duplicates_drafted);
                $('#drafted-old').text(data.drafted_old);
                $('#items-left').text(data.total - data.processed);
                $('#status-message').text(data.message || 'Processing...');
                logContent.html(data.logs ? data.logs.join('<br>') : 'No logs yet');
                if (data.complete) {
                    console.log('Import complete - stopping poll');
                    clearInterval(intervalId);
                    isRunning = false;
                    startBtn.show();
                    resumeBtn.hide();
                    cancelBtn.hide();
                }
            } else {
                console.error('Status poll failed:', response);
            }
        })
        .fail(function(jqXHR, textStatus, error) {
            console.error('Status poll AJAX error:', textStatus, error, jqXHR);
        });
    }

    startBtn.click(function() {
        console.log('Start button clicked - initiating import');
        isRunning = true;
        startBtn.hide();
        resumeBtn.hide();
        cancelBtn.show();
        progressDiv.show();
        logDiv.show();
        console.log('Sending AJAX to run_job_import_batch with nonce:', jobImportData.nonce);
        $.post(ajaxurl, {
            action: 'run_job_import_batch',
            nonce: jobImportData.nonce,
            start: 0
        })
        .done(function(response) {
            console.log('Batch start response:', response);
            if (response.success) {
                console.log('Batch started successfully - polling every 2s');
                intervalId = setInterval(updateProgress, 2000);
                updateProgress();  // Initial poll
            } else {
                console.error('Batch start failed:', response);
                // Revert UI
                startBtn.show();
                cancelBtn.hide();
                progressDiv.hide();
                logDiv.hide();
            }
        })
        .fail(function(jqXHR, textStatus, error) {
            console.error('Batch start AJAX error:', textStatus, error, jqXHR.responseText);
            // Revert UI
            startBtn.show();
            cancelBtn.hide();
            progressDiv.hide();
            logDiv.hide();
        });
    });

    cancelBtn.click(function() {
        console.log('Cancel clicked - sending cancel AJAX');
        $.post(ajaxurl, {
            action: 'cancel_job_import',
            nonce: jobImportData.nonce
        })
        .done(function(response) {
            console.log('Cancel response:', response);
            clearInterval(intervalId);
            isRunning = false;
            startBtn.show();
            cancelBtn.hide();
            progressDiv.hide();
            logDiv.hide();
        })
        .fail(function(jqXHR, textStatus, error) {
            console.error('Cancel AJAX error:', textStatus, error);
        });
    });

    resetBtn.click(function() {
        console.log('Reset clicked - sending reset AJAX');
        $.post(ajaxurl, {
            action: 'reset_job_import',
            nonce: jobImportData.nonce
        })
        .done(function(response) {
            console.log('Reset response:', response);
            if (response.success) {
                location.reload();
            }
        })
        .fail(function(jqXHR, textStatus, error) {
            console.error('Reset AJAX error:', textStatus, error);
        });
    });

    // Initial status check on load
    console.log('Initial status poll on page load');
    updateProgress();

    // Resume logic (from snippet context)
    resumeBtn.click(function() {
        console.log('Resume clicked');
        startBtn.click();  // Reuse start logic
    });
});
