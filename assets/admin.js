jQuery(document).ready(function($) {
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
        $.post(ajaxurl, {
            action: 'get_job_import_status',
            nonce: jobImportData.nonce
        }, function(response) {
            if (response.success) {
                const data = response.data;
                const percent = data.total > 0 ? Math.round((data.processed / data.total) * 100) : 0;
                $('#progress-percent').text(percent + '%');
                $('#progress-bar .progress-fill').css('width', percent + '%');
                $('#total-items').text(data.total);
                $('#processed-items').text(data.processed);
                $('#created-items').text(data.created);
                $('#updated-items').text(data.updated);
                $('#skipped-items').text(data.skipped);
                $('#duplicates-drafted').text(data.duplicates_drafted);
                $('#drafted-old').text(data.drafted_old);
                $('#items-left').text(data.total - data.processed);
                $('#status-message').text(data.message || 'Processing...');
                logContent.html(data.logs.join('<br>'));
                if (data.complete) {
                    clearInterval(intervalId);
                    isRunning = false;
                    startBtn.show();
                    resumeBtn.hide();
                    cancelBtn.hide();
                }
            }
        });
    }

    startBtn.click(function() {
        isRunning = true;
        startBtn.hide();
        resumeBtn.hide();
        cancelBtn.show();
        progressDiv.show();
        logDiv.show();
        $.post(ajaxurl, {
            action: 'run_job_import_batch',
            nonce: jobImportData.nonce,
            start: 0
        }, function(response) {
            if (response.success) {
                intervalId = setInterval(updateProgress, 2000);
            }
        });
    });

    cancelBtn.click(function() {
        $.post(ajaxurl, {
            action: 'cancel_job_import',
            nonce: jobImportData.nonce
        }, function() {
            clearInterval(intervalId);
            isRunning = false;
            startBtn.show();
            cancelBtn.hide();
        });
    });

    resetBtn.click(function() {
        $.post(ajaxurl, {
            action: 'reset_job_import',
            nonce: jobImportData.nonce
        }, function(response) {
            if (response.success) {
                location.reload();
            }
        });
    });

    // Resume logic, purge, etc. (full from snippets)
});
