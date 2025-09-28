<?php

/**
 * AJAX handlers for import control operations
 * Handles batch processing, cancellation, and status retrieval.
 *
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX handlers for import control operations
 * Handles batch processing, cancellation, and status retrieval.
 */

// Explicitly load required utility classes for AJAX context
require_once __DIR__ . '/../utilities/SecurityUtils.php';
require_once __DIR__ . '/../utilities/AjaxErrorHandler.php';
require_once __DIR__ . '/../utilities/PuntWorkLogger.php';

/*
 * AJAX handlers for import control operations
 * Handles batch processing, cancellation, and status retrieval
 */

add_action('wp_ajax_run_job_import_batch', __NAMESPACE__ . '\\run_job_import_batch_ajax');
function run_job_import_batch_ajax()
{
    // Verify nonce for security
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'job_import_nonce')) {
        wp_send_json_error(['message' => 'Security check failed']);

        return;
    }

    // Log that we entered the function
    error_log('[PUNTWORK] AJAX: run_job_import_batch_ajax function called');

    try {
        PuntWorkLogger::logAjaxRequest('run_job_import_batch', $_POST);

        // Ensure required functions are loaded for AJAX calls
        if (!function_exists('import_jobs_from_json')) {
            error_log('[PUNTWORK] AJAX: import_jobs_from_json function not found, attempting to load import files');

            // Explicitly load required import files for AJAX calls
            $import_files = [
                __DIR__ . '/../batch/batch-size-management.php',
                __DIR__ . '/../import/import-setup.php',
                __DIR__ . '/../batch/batch-processing.php',
                __DIR__ . '/../import/import-finalization.php',
                __DIR__ . '/../import/import-batch.php',
            ];

            foreach ($import_files as $file) {
                if (file_exists($file)) {
                    error_log('[PUNTWORK] AJAX: Attempting to load file: ' . basename($file));

                    try {
                        $load_result = include_once $file;
                        error_log('[PUNTWORK] AJAX: Loaded import file: ' . basename($file) . ', result: ' . ($load_result ? 'true' : 'false'));
                    } catch (\Exception $e) {
                        error_log('[PUNTWORK] AJAX: Exception loading ' . basename($file) . ': ' . $e->getMessage());
                    } catch (\Error $e) {
                        error_log('[PUNTWORK] AJAX: Fatal error loading ' . basename($file) . ': ' . $e->getMessage());
                    }
                } else {
                    error_log('[PUNTWORK] AJAX: Import file not found: ' . $file);
                }
            }

            // Check again after loading
            if (!function_exists('import_jobs_from_json')) {
                error_log('[PUNTWORK] AJAX: import_jobs_from_json function still not found after loading files');
                // List all functions that start with 'import_' to see what's available
                $all_functions = get_defined_functions();
                $import_functions = array_filter(
                    $all_functions['user'],
                    function ($func) {
                        return strpos($func, 'import_') === 0;
                    }
                );
                error_log('[PUNTWORK] AJAX: Available import functions: ' . implode(', ', $import_functions));
                AjaxErrorHandler::sendError('Import function not available - files could not be loaded');

                return;
            }

            error_log('[PUNTWORK] AJAX: import_jobs_from_json function now available after loading files');
        }

        // Use comprehensive security validation with field validation
        error_log('[PUNTWORK] AJAX: About to validate AJAX request');
        $validation = SecurityUtils::validateAjaxRequest(
            'run_job_import_batch',
            'job_import_nonce',
            ['start'], // required fields
            [
                'start' => [
                    'type' => 'int',
                    'min' => 0,
                    'max' => 1000000,
                ], // validation rules
            ]
        );
        error_log('[PUNTWORK] AJAX: Security validation completed');

        if (is_wp_error($validation)) {
            error_log('[PUNTWORK] AJAX: Security validation failed: ' . $validation->get_error_message());
            AjaxErrorHandler::sendError($validation);

            return;
        }
        error_log('[PUNTWORK] AJAX: Security validation passed');

        // Check for concurrent import lock
        if (get_transient('puntwork_import_lock')) {
            error_log('[PUNTWORK] AJAX: Import already running, rejecting request');
            AjaxErrorHandler::sendError('Import already running');

            return;
        }
        error_log('[PUNTWORK] AJAX: No import lock found, proceeding');

        try {
            $start = $_POST['start'];
            PuntWorkLogger::info("Starting batch import at index: {$start}", PuntWorkLogger::CONTEXT_BATCH);

            // Add detailed logging before calling import_jobs_from_json
            PuntWorkLogger::debug("About to call import_jobs_from_json with start={$start}", PuntWorkLogger::CONTEXT_BATCH);
            error_log('[PUNTWORK] AJAX: About to call import_jobs_from_json with start=' . $start);

            // Check if required functions exist before calling
            if (!function_exists('prepare_import_setup')) {
                error_log('[PUNTWORK] AJAX: prepare_import_setup function not found');
                AjaxErrorHandler::sendError('prepare_import_setup function not available');

                return;
            }
            if (!function_exists('process_batch_items_logic')) {
                error_log('[PUNTWORK] AJAX: process_batch_items_logic function not found');
                AjaxErrorHandler::sendError('process_batch_items_logic function not available');

                return;
            }
            if (!function_exists('finalize_batch_import')) {
                error_log('[PUNTWORK] AJAX: finalize_batch_import function not found');
                AjaxErrorHandler::sendError('finalize_batch_import function not available');

                return;
            }

            error_log('[PUNTWORK] AJAX: All required functions are available');

            try {
                error_log('[PUNTWORK] AJAX: Starting manual import process...');
                error_log('[PUNTWORK] AJAX: Batch start parameter: ' . ($start ?? 'null'));
                error_log('[PUNTWORK] AJAX: Current user ID: ' . get_current_user_id());
                error_log('[PUNTWORK] AJAX: Current user capabilities: ' . (current_user_can('manage_options') ? 'admin' : 'non-admin'));

                error_log('[PUNTWORK] AJAX: Calling import_jobs_from_json...');
                $result = import_jobs_from_json(true, $start);
                error_log('[PUNTWORK] AJAX: import_jobs_from_json returned successfully');
                error_log('[PUNTWORK] AJAX: import_jobs_from_json result keys: ' . implode(', ', array_keys($result)));
                error_log('[PUNTWORK] AJAX: import_jobs_from_json result: ' . json_encode($result));
            } catch (\Exception $e) {
                error_log('[PUNTWORK] AJAX: Exception in import_jobs_from_json: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
                error_log('[PUNTWORK] AJAX: Stack trace: ' . $e->getTraceAsString());
                AjaxErrorHandler::sendError('Import failed with exception: ' . $e->getMessage());

                return;
            } catch (\Throwable $e) {
                error_log('[PUNTWORK] AJAX: Fatal error in import_jobs_from_json: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
                error_log('[PUNTWORK] AJAX: Stack trace: ' . $e->getTraceAsString());
                AjaxErrorHandler::sendError('Import failed with fatal error: ' . $e->getMessage());

                return;
            }        // Log summary instead of full result to prevent large debug logs
            $log_summary = [
                'success' => isset($result['success']) && $result['success'],
                'processed' => $result['processed'] ?? 0,
                'total' => $result['total'] ?? 0,
                'published' => $result['published'] ?? 0,
                'updated' => $result['updated'] ?? 0,
                'skipped' => $result['skipped'] ?? 0,
                'complete' => $result['complete'] ?? false,
                'logs_count' => isset($result['logs']) && is_array($result['logs']) ? count($result['logs']) : 0,
                'has_error' => !empty($result['message']),
            ];

            PuntWorkLogger::logAjaxResponse('run_job_import_batch', $log_summary, isset($result['success']) && $result['success']);
            AjaxErrorHandler::sendSuccess($result);
        } catch (\Exception $e) {
            PuntWorkLogger::error('Batch import error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX);
            error_log('[PUNTWORK] AJAX: Batch import exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            error_log('[PUNTWORK] AJAX: Stack trace: ' . $e->getTraceAsString());
            AjaxErrorHandler::sendError('Batch import failed: ' . $e->getMessage());
        }
    } catch (\Throwable $e) {
        error_log('[PUNTWORK] AJAX: Fatal error in run_job_import_batch_ajax: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        error_log('[PUNTWORK] AJAX: Stack trace: ' . $e->getTraceAsString());
        wp_die('Internal server error', '500 Internal Server Error', ['response' => 500]);
    }
}

add_action('wp_ajax_cancel_job_import', __NAMESPACE__ . '\\cancel_job_import_ajax');
function cancel_job_import_ajax()
{
    PuntWorkLogger::logAjaxRequest('cancel_job_import', $_POST);

    // Use comprehensive security validation
    $validation = SecurityUtils::validateAjaxRequest('cancel_job_import', 'job_import_nonce');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::sendError($validation);

        return;
    }

    try {
        set_transient('import_cancel', true, 3600);
        // Also clear the import status to reset the UI
        delete_option('job_import_status');
        delete_option('job_import_batch_size');
        PuntWorkLogger::info('Import cancelled and status cleared', PuntWorkLogger::CONTEXT_BATCH);

        PuntWorkLogger::logAjaxResponse('cancel_job_import', ['message' => 'Import cancelled']);
        AjaxErrorHandler::sendSuccess(null, ['message' => 'Import cancelled']);
    } catch (\Exception $e) {
        PuntWorkLogger::error('Cancel import error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX);
        AjaxErrorHandler::sendError('Failed to cancel import: ' . $e->getMessage());
    }
}

add_action('wp_ajax_clear_import_cancel', __NAMESPACE__ . '\\clear_import_cancel_ajax');
function clear_import_cancel_ajax()
{
    PuntWorkLogger::logAjaxRequest('clear_import_cancel', $_POST);

    // Use comprehensive security validation
    $validation = SecurityUtils::validateAjaxRequest('clear_import_cancel', 'job_import_nonce');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::sendError($validation);

        return;
    }

    try {
        delete_transient('import_cancel');
        PuntWorkLogger::info('Import cancellation flag cleared', PuntWorkLogger::CONTEXT_BATCH);

        PuntWorkLogger::logAjaxResponse('clear_import_cancel', ['message' => 'Cancellation cleared']);
        AjaxErrorHandler::sendSuccess(null, ['message' => 'Cancellation cleared']);
    } catch (\Exception $e) {
        PuntWorkLogger::error('Clear import cancel error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX);
        AjaxErrorHandler::sendError('Failed to clear cancellation: ' . $e->getMessage());
    }
}

add_action('wp_ajax_reset_job_import_status', __NAMESPACE__ . '\\reset_job_import_status_ajax');
add_action('wp_ajax_reset_job_import', __NAMESPACE__ . '\\reset_job_import_status_ajax'); // Alias for compatibility
function reset_job_import_status_ajax()
{
    PuntWorkLogger::logAjaxRequest('reset_job_import_status', $_POST);

    // Use comprehensive security validation
    $validation = SecurityUtils::validateAjaxRequest('reset_job_import_status', 'job_import_nonce');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::sendError($validation);

        return;
    }

    try {
        // Clear only the import status, not other options
        delete_option('job_import_status');
        // Also reset progress and related options for complete reset
        delete_option('job_import_progress');
        delete_option('job_import_processed_guids');
        delete_option('job_import_last_batch_time');
        delete_option('job_import_last_batch_processed');
        delete_option('job_import_batch_size');
        delete_option('job_import_consecutive_small_batches');
        delete_transient('import_cancel');

        PuntWorkLogger::info('Import status and progress completely reset', PuntWorkLogger::CONTEXT_BATCH);

        PuntWorkLogger::logAjaxResponse('reset_job_import_status', ['message' => 'Import status reset']);
        AjaxErrorHandler::sendSuccess(null, ['message' => 'Import status reset']);
    } catch (\Exception $e) {
        PuntWorkLogger::error('Reset import status error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX);
        AjaxErrorHandler::sendError('Failed to reset import status: ' . $e->getMessage());
    }
}

add_action('wp_ajax_get_job_import_status', __NAMESPACE__ . '\\get_job_import_status_ajax');
add_action('wp_ajax_get_import_status', __NAMESPACE__ . '\\get_job_import_status_ajax'); // Alias for compatibility
function get_job_import_status_ajax()
{
    PuntWorkLogger::logAjaxRequest('get_job_import_status', $_POST);

    // Use comprehensive security validation
    $validation = SecurityUtils::validateAjaxRequest('get_job_import_status', 'job_import_nonce');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::sendError($validation);

        return;
    }

    try {
        $progress = get_option('job_import_status') ?: [
            'total' => 0,
            'processed' => 0,
            'published' => 0,
            'updated' => 0,
            'skipped' => 0,
            'duplicates_drafted' => 0,
            'time_elapsed' => 0,
            'complete' => true, // Fresh state is complete
            'success' => false, // Add success status
            'error_message' => '', // Add error message for failures
            'batch_size' => 10,
            'inferred_languages' => 0,
            'inferred_benefits' => 0,
            'schema_generated' => 0,
            'start_time' => microtime(true),
            'end_time' => null,
            'last_update' => time(),
            'logs' => [],
        ];

        PuntWorkLogger::debug(
            'Retrieved import status',
            PuntWorkLogger::CONTEXT_BATCH,
            [
                'total' => $progress['total'],
                'processed' => $progress['processed'],
                'complete' => $progress['complete'] ?? null,
            ]
        );

        // Check for stuck or stale imports and clear them
        if (isset($progress['complete']) && !$progress['complete'] && isset($progress['total']) && $progress['total'] > 0) {
            $current_time = time();
            $time_elapsed = 0;
            $last_update = isset($progress['last_update']) ? $progress['last_update'] : 0;
            $time_since_last_update = $current_time - $last_update;

            if (isset($progress['start_time']) && $progress['start_time'] > 0) {
                $time_elapsed = microtime(true) - $progress['start_time'];
            } elseif (isset($progress['time_elapsed'])) {
                $time_elapsed = $progress['time_elapsed'];
            }

            // Detect stuck imports with multiple criteria:
            // 1. No progress for 5+ minutes (300 seconds)
            // 2. Import running for more than 2 hours without completion (7200 seconds)
            // 3. No status update for 10+ minutes (600 seconds)
            $is_stuck = false;
            $stuck_reason = '';

            if ($progress['processed'] === 0 && $time_elapsed > 300) {
                $is_stuck = true;
                $stuck_reason = 'no progress for 5+ minutes';
            } elseif ($time_elapsed > 7200) { // 2 hours
                $is_stuck = true;
                $stuck_reason = 'running for more than 2 hours';
            } elseif ($time_since_last_update > 600) { // 10 minutes since last update
                $is_stuck = true;
                $stuck_reason = 'no status update for 10+ minutes';
            }

            if ($is_stuck) {
                PuntWorkLogger::info(
                    'Detected stuck import in status check, clearing status',
                    PuntWorkLogger::CONTEXT_BATCH,
                    [
                        'processed' => $progress['processed'],
                        'total' => $progress['total'],
                        'time_elapsed' => $time_elapsed,
                        'time_since_last_update' => $time_since_last_update,
                        'reason' => $stuck_reason,
                    ]
                );
                delete_option('job_import_status');
                delete_option('job_import_progress');
                delete_option('job_import_processed_guids');
                delete_option('job_import_last_batch_time');
                delete_option('job_import_last_batch_processed');
                delete_option('job_import_batch_size');
                delete_option('job_import_consecutive_small_batches');
                delete_transient('import_cancel');

                // Return fresh status
                $progress = [
                    'total' => 0,
                    'processed' => 0,
                    'published' => 0,
                    'updated' => 0,
                    'skipped' => 0,
                    'duplicates_drafted' => 0,
                    'time_elapsed' => 0,
                    'complete' => true, // Fresh state is complete
                    'success' => false,
                    'error_message' => '',
                    'batch_size' => 10,
                    'inferred_languages' => 0,
                    'inferred_benefits' => 0,
                    'schema_generated' => 0,
                    'start_time' => microtime(true),
                    'end_time' => null,
                    'last_update' => time(),
                    'logs' => [],
                ];
            }
        }

        if (!isset($progress['start_time'])) {
            $progress['start_time'] = microtime(true);
        }
        // Calculate elapsed time properly - if we have a start time, use it
        if (isset($progress['start_time']) && $progress['start_time'] > 0) {
            $current_time = microtime(true);
            $progress['time_elapsed'] = $current_time - $progress['start_time'];
        } else {
            $progress['time_elapsed'] = $progress['time_elapsed'] ?? 0;
        }
        // Only recalculate complete status if it's not already marked as complete
        if (!isset($progress['complete']) || !$progress['complete']) {
            $progress['complete'] = ($progress['processed'] >= $progress['total'] && $progress['total'] > 0);
        }

        // Add resume_progress for JavaScript
        $progress['resume_progress'] = (int)get_option('job_import_progress', 0);

        // Track job importing start time
        if ($progress['total'] > 1 && !isset($progress['job_import_start_time'])) {
            $progress['job_import_start_time'] = microtime(true);
            update_option('job_import_status', $progress);
        }

        // Calculate job importing elapsed time
        $progress['job_importing_time_elapsed'] = isset($progress['job_import_start_time']) ? microtime(true) - $progress['job_import_start_time'] : $progress['time_elapsed'];

        // Add batch timing data for accurate time calculations
        $progress['batch_time'] = (float)get_option('job_import_last_batch_time', 0);
        $progress['batch_processed'] = (int)get_option('job_import_last_batch_processed', 0);

        // Add estimated time remaining calculation from PHP
        $progress['estimated_time_remaining'] = calculate_estimated_time_remaining($progress);

        // Log response summary instead of full data to prevent large debug logs
        $log_summary = [
            'total' => $progress['total'],
            'processed' => $progress['processed'],
            'published' => $progress['published'],
            'updated' => $progress['updated'],
            'skipped' => $progress['skipped'],
            'complete' => $progress['complete'],
            'success' => $progress['success'],
            'time_elapsed' => $progress['time_elapsed'],
            'job_importing_time_elapsed' => $progress['job_importing_time_elapsed'],
            'estimated_time_remaining' => $progress['estimated_time_remaining'],
            'batch_time' => $progress['batch_time'],
            'batch_processed' => $progress['batch_processed'],
            'logs_count' => is_array($progress['logs']) ? count($progress['logs']) : 0,
            'has_error' => !empty($progress['error_message']),
        ];

        PuntWorkLogger::logAjaxResponse('get_job_import_status', $log_summary);
        AjaxErrorHandler::sendSuccess($progress);
    } catch (\Exception $e) {
        PuntWorkLogger::error('Get import status error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX);
        AjaxErrorHandler::sendError('Failed to get import status: ' . $e->getMessage());
    }
}

add_action('wp_ajax_log_manual_import_run', __NAMESPACE__ . '\\log_manual_import_run_ajax');
function log_manual_import_run_ajax()
{
    PuntWorkLogger::logAjaxRequest('log_manual_import_run', $_POST);

    // Use comprehensive security validation with field validation
    $validation = SecurityUtils::validateAjaxRequest(
        'log_manual_import_run',
        'job_import_nonce',
        ['timestamp', 'duration', 'success', 'processed', 'total', 'published', 'updated', 'skipped'], // required fields
        [
            'timestamp' => [
                'type' => 'int',
                'min' => 0,
            ],
            'duration' => [
                'type' => 'float',
                'min' => 0,
            ],
            'success' => ['type' => 'string'],
            'processed' => [
                'type' => 'int',
                'min' => 0,
            ],
            'total' => [
                'type' => 'int',
                'min' => 0,
            ],
            'published' => [
                'type' => 'int',
                'min' => 0,
            ],
            'updated' => [
                'type' => 'int',
                'min' => 0,
            ],
            'skipped' => [
                'type' => 'int',
                'min' => 0,
            ],
            'error_message' => [
                'type' => 'text',
                'max_length' => 1000,
            ],
        ]
    );

    if (is_wp_error($validation)) {
        AjaxErrorHandler::sendError($validation);

        return;
    }

    try {
        $details = [
            'timestamp' => $_POST['timestamp'],
            'duration' => $_POST['duration'],
            'success' => filter_var($_POST['success'], FILTER_VALIDATE_BOOLEAN),
            'processed' => $_POST['processed'],
            'total' => $_POST['total'],
            'published' => $_POST['published'],
            'updated' => $_POST['updated'],
            'skipped' => $_POST['skipped'],
            'error_message' => $_POST['error_message'] ?? '',
        ];

        // Include the scheduling history functions
        include_once __DIR__ . '/../scheduling/scheduling-history.php';

        // Log the manual import run
        log_manual_import_run($details);

        PuntWorkLogger::info(
            'Manual import run logged to history',
            PuntWorkLogger::CONTEXT_AJAX,
            [
                'success' => $details['success'],
                'processed' => $details['processed'],
                'total' => $details['total'],
                'duration' => $details['duration'],
            ]
        );

        PuntWorkLogger::logAjaxResponse('log_manual_import_run', ['message' => 'Manual import run logged']);
        AjaxErrorHandler::sendSuccess(null, ['message' => 'Manual import run logged to history']);
    } catch (\Exception $e) {
        PuntWorkLogger::error('Log manual import run error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX);
        AjaxErrorHandler::sendError('Failed to log manual import run: ' . $e->getMessage());
    }
}

add_action('wp_ajax_test_single_job_import', __NAMESPACE__ . '\\test_single_job_import_ajax');
function test_single_job_import_ajax()
{
    PuntWorkLogger::logAjaxRequest('test_single_job_import', $_POST);

    // Use comprehensive security validation
    $validation = SecurityUtils::validateAjaxRequest('test_single_job_import', 'job_import_nonce');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::sendError($validation);

        return;
    }

    try {
        // Test job data - extracted from JSONL and modified
        $test_job = [
            'guid' => 'TEST_JOB_001',
            'author' => '<a10:name xmlns:a10="http://www.w3.org/2005/Atom">Test Company</a10:name>',
            'name' => 'Test Company',
            'category' => 'Test Category',
            'title' => 'TEST', // Modified title for testing
            'description' => '<p>This is a test job to verify the import functionality works correctly.</p>',
            'pubdate' => date('D, d M Y H:i:s O'),
            'updated' => date('Y-m-d\TH:i:sP'),
            'link' => 'https://test.com/job/test',
            'applylink' => 'https://test.com/apply/test',
            'magiclink' => '',
            'branche' => 'Test',
            'postalcode' => '1000',
            'city' => 'TEST CITY',
            'province' => 'Test Province',
            'provincecode' => 'TEST',
            'country' => 'BE',
            'validfrom' => date('Y-m-d\TH:i:s'),
            'validtill' => date('Y-m-d\TH:i:s', strtotime('+30 days')),
            'channeltype' => '29998',
            'functiongroup' => 'Test Services',
            'functiongroup2' => '',
            'functiongroup3' => '',
            'functiongroupid' => '1',
            'functiongroupid2' => '0',
            'functiongroupid3' => '0',
            'function' => 'Test Function',
            'function2' => '',
            'function3' => '',
            'functionid' => '1',
            'functionid2' => '0',
            'functionid3' => '0',
            'functiontitle' => 'TEST',
            'functiondescription' => '<p>This is a test job function.</p>',
            'education' => 'Bachelor',
            'education2' => 'Bachelor',
            'education3' => 'Bachelor',
            'educationid' => '1',
            'educationid2' => '1',
            'educationid3' => '1',
            'educationgroup' => 'Bachelor',
            'educationgroup2' => 'Bachelor',
            'educationgroup3' => 'Bachelor',
            'educationgroupcode' => '001',
            'educationgroupcode2' => '001',
            'educationgroupcode3' => '001',
            'jobtype' => 'Full-time',
            'jobtypecode' => 'FULL',
            'jobtypegroup' => 'Permanent Contract',
            'jobtypegroupcode' => '001',
            'contracttype' => 'Employee',
            'contracttype2' => '',
            'contracttype3' => '',
            'contracttypecode' => '20',
            'contracttypecode2' => '',
            'contracttypecode3' => '',
            'experience' => 'No experience required',
            'experiencecode' => '001',
            'brand' => 'Test Company',
            'accountid' => '123456',
            'internal' => 'false',
            'payrollid' => '123456',
            'payroll' => 'Test Payroll',
            'brancheid' => '1',
            'label' => 'Test Label',
            'labelid' => '1',
            'language' => 'English',
            'language2' => '',
            'language3' => '',
            'languagecode' => '1',
            'languagecode2' => '',
            'languagecode3' => '',
            'languagelevel' => 'Good',
            'languagelevel2' => '',
            'languagelevel3' => '',
            'languagelevelcode' => '3',
            'languagelevelcode2' => '',
            'languagelevelcode3' => '',
            'office' => 'Test Office',
            'officeid' => '1',
            'officestreet' => 'Test Street',
            'officehousenumber' => '1',
            'officeaddition' => '',
            'officepostalcode' => '1000',
            'officecity' => 'TEST CITY',
            'officetelephone' => '+32 123 456 789',
            'officeemail' => 'test@test.com',
            'hours' => '40',
            'salaryfrom' => '30000',
            'salaryto' => '40000',
            'salarytype' => 'per year',
            'salarytypecode' => '1',
            'parttime' => 'false',
            'offerdescription' => '<p>Test job offer description.</p>',
            'requirementsdescription' => '<p>Test job requirements.</p>',
            'reference' => 'TEST001',
            'shift' => 'Day shift',
            'shiftcode' => '1',
            'driverslicense' => '',
            'driverslicenseid' => '0',
            'publicationlanguage' => 'EN',
            'companydescription' => '<p>Test company description.</p>',
            'job_title' => 'TEST',
            'job_slug' => 'test-job',
            'job_link' => 'https://test.com/job/test',
            'job_salary' => '€30000 - €40000',
            'job_apply' => 'https://test.com/apply/test',
            'job_icon' => '<i class="fas fa-briefcase"></i>',
            'job_car' => '',
            'job_time' => 'Full-time',
            'job_description' => 'Test job description',
            'job_remote' => '',
            'job_meal_vouchers' => '',
            'job_flex_hours' => '',
            'job_skills' => [],
            'job_posting' => '{}',
            'job_ecommerce' => '{}',
            'job_languages' => '<ul><li>English: Good (3/5)</li></ul>',
            'job_category' => 'Test',
            'job_quality_score' => 50.0,
            'job_quality_level' => 'Average',
            'job_quality_factors' => '{}',
            'job_quality_recommendations' => '[]',
        ];

        PuntWorkLogger::info(
            'Starting test single job import',
            PuntWorkLogger::CONTEXT_AJAX,
            [
                'guid' => $test_job['guid'],
                'title' => $test_job['title'],
            ]
        );

        // Check if job already exists
        $existing_post = get_posts(
            [
                'post_type' => 'job_listing',
                'meta_key' => '_guid',
                'meta_value' => $test_job['guid'],
                'posts_per_page' => 1,
            ]
        );

        if (!empty($existing_post)) {
            PuntWorkLogger::warn(
                'Test job already exists',
                PuntWorkLogger::CONTEXT_AJAX,
                [
                    'guid' => $test_job['guid'],
                    'existing_post_id' => $existing_post[0]->ID,
                ]
            );
            AjaxErrorHandler::sendError('Test job already exists with GUID: ' . $test_job['guid']);

            return;
        }

        // Prepare job data
        $job_data = [
            'post_title' => $test_job['title'] ?? 'Untitled Job',
            'post_content' => $test_job['description'] ?? '',
            'post_status' => 'publish',
            'post_type' => 'job_listing',
            'post_author' => get_current_user_id(),
        ];

        // Insert the job post
        $post_id = wp_insert_post($job_data);

        if (is_wp_error($post_id)) {
            PuntWorkLogger::error(
                'Failed to create test job post',
                PuntWorkLogger::CONTEXT_AJAX,
                [
                    'error' => $post_id->get_error_message(),
                ]
            );
            AjaxErrorHandler::sendError('Failed to create test job: ' . $post_id->get_error_message());

            return;
        }

        PuntWorkLogger::info(
            'Test job post created',
            PuntWorkLogger::CONTEXT_AJAX,
            [
                'post_id' => $post_id,
                'title' => $job_data['post_title'],
            ]
        );

        // Add job metadata
        update_post_meta($post_id, '_guid', $test_job['guid']);
        update_post_meta($post_id, '_job_location', $test_job['city'] ?? '');
        update_post_meta($post_id, '_job_salary', $test_job['job_salary'] ?? '');
        update_post_meta($post_id, '_job_type', $test_job['jobtype'] ?? '');
        update_post_meta($post_id, '_company_name', $test_job['name'] ?? '');
        update_post_meta($post_id, '_company_website', $test_job['link'] ?? '');
        update_post_meta($post_id, '_job_expires', $test_job['validtill'] ?? '');

        // Add ACF fields if available
        if (function_exists('update_field')) {
            update_field('job_description', $test_job['job_description'] ?? '', $post_id);
            update_field('job_requirements', $test_job['requirementsdescription'] ?? '', $post_id);
            update_field('company_description', $test_job['companydescription'] ?? '', $post_id);
            update_field('application_link', $test_job['job_apply'] ?? '', $post_id);
        }

        // Verify the job was created and has all metadata
        $verify_post = get_post($post_id);
        if (!$verify_post) {
            PuntWorkLogger::error(
                'Test job creation verification failed',
                PuntWorkLogger::CONTEXT_AJAX,
                [
                    'post_id' => $post_id,
                ]
            );
            AjaxErrorHandler::sendError('Test job creation verification failed');

            return;
        }

        // Verify metadata was added correctly
        $verification_logs = [];
        $verification_logs[] = '✅ Test job created successfully';
        $verification_logs[] = '📝 Post ID: ' . $post_id;
        $verification_logs[] = '🏷️  Title: ' . $verify_post->post_title;
        $verification_logs[] = '📊 Status: ' . $verify_post->post_status;
        $verification_logs[] = '🔗 GUID: ' . $test_job['guid'];

        // Check metadata
        $guid_meta = get_post_meta($post_id, '_guid', true);
        $location_meta = get_post_meta($post_id, '_job_location', true);
        $salary_meta = get_post_meta($post_id, '_job_salary', true);
        $type_meta = get_post_meta($post_id, '_job_type', true);
        $company_meta = get_post_meta($post_id, '_company_name', true);

        $verification_logs[] = '� Metadata verification:';
        $verification_logs[] = '  • GUID: ' . ($guid_meta === $test_job['guid'] ? '✅' : '❌') . ' (' . $guid_meta . ')';
        $verification_logs[] = '  • Location: ' . (!empty($location_meta) ? '✅' : '❌') . ' (' . $location_meta . ')';
        $verification_logs[] = '  • Salary: ' . (!empty($salary_meta) ? '✅' : '❌') . ' (' . $salary_meta . ')';
        $verification_logs[] = '  • Job Type: ' . (!empty($type_meta) ? '✅' : '❌') . ' (' . $type_meta . ')';
        $verification_logs[] = '  • Company: ' . (!empty($company_meta) ? '✅' : '❌') . ' (' . $company_meta . ')';

        // Check ACF fields if available
        if (function_exists('get_field')) {
            $acf_description = get_field('job_description', $post_id);
            $acf_requirements = get_field('job_requirements', $post_id);
            $acf_company_desc = get_field('company_description', $post_id);
            $acf_apply_link = get_field('application_link', $post_id);

            $verification_logs[] = '🔧 ACF Fields verification:';
            $verification_logs[] = '  • Job Description: ' . (!empty($acf_description) ? '✅' : '❌');
            $verification_logs[] = '  • Requirements: ' . (!empty($acf_requirements) ? '✅' : '❌');
            $verification_logs[] = '  • Company Description: ' . (!empty($acf_company_desc) ? '✅' : '❌');
            $verification_logs[] = '  • Application Link: ' . (!empty($acf_apply_link) ? '✅' : '❌');
        } else {
            $verification_logs[] = '⚠️  ACF not available - skipping ACF field verification';
        }

        // Final verification - check if post exists in database
        $final_check = get_posts(
            [
                'post_type' => 'job_listing',
                'p' => $post_id,
                'posts_per_page' => 1,
            ]
        );

        if (empty($final_check)) {
            PuntWorkLogger::error(
                'Final verification failed - job not found in database',
                PuntWorkLogger::CONTEXT_AJAX,
                [
                    'post_id' => $post_id,
                ]
            );
            AjaxErrorHandler::sendError('Final verification failed - job not found in database');

            return;
        }

        $verification_logs[] = '🎯 Final verification: Job exists in database ✅';

        PuntWorkLogger::info(
            'Test single job import completed with full verification',
            PuntWorkLogger::CONTEXT_AJAX,
            [
                'post_id' => $post_id,
                'title' => $verify_post->post_title,
                'status' => $verify_post->post_status,
                'metadata_verified' => true,
            ]
        );

        PuntWorkLogger::logAjaxResponse(
            'test_single_job_import',
            [
                'post_id' => $post_id,
                'post_title' => $verify_post->post_title,
                'post_status' => $verify_post->post_status,
                'logs' => $verification_logs,
                'verification_complete' => true,
            ]
        );

        AjaxErrorHandler::sendSuccess(
            [
                'post_id' => $post_id,
                'post_title' => $verify_post->post_title,
                'post_status' => $verify_post->post_status,
                'logs' => $verification_logs,
                'verification_complete' => true,
            ]
        );
    } catch (\Exception $e) {
        PuntWorkLogger::error('Test single job import error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX);
        AjaxErrorHandler::sendError('Test single job import failed: ' . $e->getMessage());
    }
}

add_action('wp_ajax_clear_rate_limits', __NAMESPACE__ . '\\clear_rate_limits_ajax');
add_action('wp_ajax_clear_rate_limits_ajax', __NAMESPACE__ . '\\clear_rate_limits_ajax'); // Alias for compatibility
function clear_rate_limits_ajax()
{
    PuntWorkLogger::logAjaxRequest('clear_rate_limits', $_POST);

    // Use comprehensive security validation
    $validation = SecurityUtils::validateAjaxRequest('clear_rate_limits', 'job_import_nonce');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::sendError($validation);

        return;
    }

    try {
        // Include SecurityUtils
        include_once __DIR__ . '/../utilities/SecurityUtils.php';

        $cleared = SecurityUtils::clearAllRateLimits();

        PuntWorkLogger::info('Rate limits cleared', PuntWorkLogger::CONTEXT_AJAX, ['cleared_count' => $cleared]);

        PuntWorkLogger::logAjaxResponse(
            'clear_rate_limits',
            [
                'message' => 'Rate limits cleared',
                'cleared_count' => $cleared,
            ]
        );
        AjaxErrorHandler::sendSuccess(null, ['message' => "Cleared {$cleared} rate limit entries"]);
    } catch (\Exception $e) {
        PuntWorkLogger::error('Clear rate limits error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX);
        AjaxErrorHandler::sendError('Failed to clear rate limits: ' . $e->getMessage());
    }
}

add_action('wp_ajax_get_rate_limit_status', __NAMESPACE__ . '\\get_rate_limit_status_ajax');
function get_rate_limit_status_ajax()
{
    PuntWorkLogger::logAjaxRequest('get_rate_limit_status', $_POST);

    // Use comprehensive security validation
    $validation = SecurityUtils::validateAjaxRequest('get_rate_limit_status', 'puntwork_rate_limits');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::sendError($validation);

        return;
    }

    try {
        // Use transient caching to reduce database load (cache for 15 seconds)
        $cache_key = 'puntwork_rate_limit_status_cache_' . get_current_user_id();
        $cached_status = get_transient($cache_key);

        if ($cached_status !== false) {
            PuntWorkLogger::logAjaxResponse('get_rate_limit_status', ['status_count' => count($cached_status), 'cached' => true]);
            AjaxErrorHandler::sendSuccess($cached_status);

            return;
        }

        $user_id = get_current_user_id();
        $configs = SecurityUtils::getAllRateLimitConfigs();
        $status = [];

        foreach ($configs as $action => $config) {
            $key = "rate_limit_{$action}_{$user_id}";
            $requests = get_transient($key);

            if (!$requests) {
                $requests = [];
            }

            // Clean old requests
            $current_time = time();
            $requests = array_filter(
                $requests,
                function ($timestamp) use ($current_time, $config) {
                    return ($current_time - $timestamp) < $config['time_window'];
                }
            );

            $status[$action] = [
                'requests' => count($requests),
                'limit' => $config['max_requests'],
                'window' => $config['time_window'],
            ];
        }

        // Cache the result for 15 seconds
        set_transient($cache_key, $status, 15);

        PuntWorkLogger::logAjaxResponse('get_rate_limit_status', ['status_count' => count($status)]);
        AjaxErrorHandler::sendSuccess($status);
    } catch (\Exception $e) {
        PuntWorkLogger::error('Get rate limit status error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX);
        AjaxErrorHandler::sendError('Failed to get rate limit status: ' . $e->getMessage());
    }
}

add_action('wp_ajax_get_dynamic_rate_status', __NAMESPACE__ . '\\get_dynamic_rate_status_ajax');
function get_dynamic_rate_status_ajax()
{
    PuntWorkLogger::logAjaxRequest('get_dynamic_rate_status', $_POST);

    // Use comprehensive security validation
    $validation = SecurityUtils::validateAjaxRequest('get_dynamic_rate_status', 'puntwork_dynamic_rate_limits');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::sendError($validation);

        return;
    }

    try {
        // Use transient caching to reduce database load (cache for 30 seconds)
        $cache_key = 'puntwork_dynamic_rate_status_cache';
        $cached_status = get_transient($cache_key);

        if ($cached_status !== false) {
            PuntWorkLogger::logAjaxResponse('get_dynamic_rate_status', [
                'enabled' => $cached_status['enabled'],
                'total_metrics' => $cached_status['total_metrics'],
                'cached' => true,
            ]);
            AjaxErrorHandler::sendSuccess($cached_status);

            return;
        }

        $status = \Puntwork\DynamicRateLimiter::getStatus();

        // Cache the result for 30 seconds
        set_transient($cache_key, $status, 30);

        PuntWorkLogger::logAjaxResponse('get_dynamic_rate_status', [
            'enabled' => $status['enabled'],
            'total_metrics' => $status['total_metrics'],
        ]);
        AjaxErrorHandler::sendSuccess($status);
    } catch (\Exception $e) {
        PuntWorkLogger::error('Get dynamic rate status error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX);
        AjaxErrorHandler::sendError('Failed to get dynamic rate limiting status: ' . $e->getMessage());
    }
}

add_action('wp_ajax_get_api_key', __NAMESPACE__ . '\\get_api_key_ajax');
function get_api_key_ajax()
{
    PuntWorkLogger::logAjaxRequest('get_api_key', $_POST);

    // Use comprehensive security validation
    $validation = SecurityUtils::validateAjaxRequest('get_api_key', 'job_import_nonce');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::sendError($validation);

        return;
    }

    try {
        // Generate or retrieve API key for real-time updates
        $api_key = get_option('puntwork_api_key', '');
        if (empty($api_key)) {
            $api_key = wp_generate_password(32, false);
            update_option('puntwork_api_key', $api_key);
        }

        PuntWorkLogger::logAjaxResponse('get_api_key', ['key_generated' => empty(get_option('puntwork_api_key', ''))]);
        AjaxErrorHandler::sendSuccess(['api_key' => $api_key]);
    } catch (\Exception $e) {
        PuntWorkLogger::error('Get API key error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX);
        AjaxErrorHandler::sendError('Failed to get API key: ' . $e->getMessage());
    }
}

add_action('wp_ajax_get_async_status', __NAMESPACE__ . '\\get_async_status_ajax');
function get_async_status_ajax()
{
    PuntWorkLogger::logAjaxRequest('get_async_status', $_POST);

    // Use comprehensive security validation
    $validation = SecurityUtils::validateAjaxRequest('get_async_status', 'job_import_nonce');
    if (is_wp_error($validation)) {
        AjaxErrorHandler::sendError($validation);

        return;
    }

    try {
        // Include async processing utilities
        require_once __DIR__ . '/../utilities/async-processing.php';

        $status = get_async_processing_status();

        PuntWorkLogger::logAjaxResponse('get_async_status', [
            'enabled' => $status['enabled'],
            'available' => $status['available'],
        ]);
        AjaxErrorHandler::sendSuccess($status);
    } catch (\Exception $e) {
        PuntWorkLogger::error('Get async status error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX);
        AjaxErrorHandler::sendError('Failed to get async status: ' . $e->getMessage());
    }
}

add_action('wp_ajax_save_async_settings', __NAMESPACE__ . '\\save_async_settings_ajax');
function save_async_settings_ajax()
{
    PuntWorkLogger::logAjaxRequest('save_async_settings', $_POST);

    // Use comprehensive security validation with field validation
    $validation = SecurityUtils::validateAjaxRequest(
        'save_async_settings',
        'job_import_nonce',
        ['enabled'], // required fields
        [
            'enabled' => [
                'type' => 'boolean',
            ],
        ]
    );

    if (is_wp_error($validation)) {
        AjaxErrorHandler::sendError($validation);

        return;
    }

    try {
        $enabled = filter_var($_POST['enabled'], FILTER_VALIDATE_BOOLEAN);

        // Save async processing setting
        update_option('puntwork_async_enabled', $enabled);

        PuntWorkLogger::info(
            'Async processing settings updated',
            PuntWorkLogger::CONTEXT_AJAX,
            ['enabled' => $enabled]
        );

        PuntWorkLogger::logAjaxResponse('save_async_settings', ['enabled' => $enabled]);
        AjaxErrorHandler::sendSuccess(null, ['message' => 'Async settings saved successfully']);
    } catch (\Exception $e) {
        PuntWorkLogger::error('Save async settings error: ' . $e->getMessage(), PuntWorkLogger::CONTEXT_AJAX);
        AjaxErrorHandler::sendError('Failed to save async settings: ' . $e->getMessage());
    }
}
