<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Heartbeat control from snippets 1.4/1.5 (merged: progress monitoring during import)
add_action('heartbeat_tick', 'job_import_heartbeat_tick');
function job_import_heartbeat_tick($response) {
    $progress = get_transient('job_import_progress');
    if ($progress) {
        $response['job_import'] = [
            'progress' => $progress['current'] / $progress['total'] * 100,
            'status' => $progress['status'],
        ];
        // Clear if done
        if ($progress['done']) {
            delete_transient('job_import_progress');
        }
    }
    return $response;
}

// Update progress during batch (call from processor)
function job_import_update_progress($current, $total, $status = 'processing') {
    set_transient('job_import_progress', [
        'current' => $current,
        'total' => $total,
        'status' => $status,
        'done' => $current >= $total,
    ], 60); // 1 min expiry
}
?>
