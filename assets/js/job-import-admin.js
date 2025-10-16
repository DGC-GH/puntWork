/**
 * Job Import Admin - Main Module
 * Main entry point that combines all job import modules
 */

console.log('[PUNTWORK] job-import-admin.js loaded');

(function($, window, document) {
    'use strict';

    // Check critical dependencies
    if (typeof PuntWorkJSLogger === 'undefined' ||
        typeof JobImportUI === 'undefined' ||
        typeof JobImportAPI === 'undefined' ||
        typeof JobImportLogic === 'undefined' ||
        typeof JobImportEvents === 'undefined' ||
        typeof JobImportScheduling === 'undefined') {
        console.error('[PUNTWORK] Critical Error: Required modules not loaded');
        return;
    }

    var PuntWorkJobImportAdmin = {
        /**
         * Initialize the job import admin functionality
         */
        init: function() {
            PuntWorkJSLogger.info('Initializing Job Import Admin...', 'SYSTEM');

            // Only initialize if not already initialized by inline script
            if (typeof window.jobImportInitialized === 'undefined') {
                JobImportEvents.init();
                JobImportScheduling.init();
                window.jobImportInitialized = true;
                PuntWorkJSLogger.info('Job Import Admin initialization complete', 'SYSTEM');
            } else {
                PuntWorkJSLogger.info('Job Import Admin already initialized by inline script', 'SYSTEM');
            }
        }
    };

    // Expose to global scope
    window.PuntWorkJobImportAdmin = PuntWorkJobImportAdmin;

})(jQuery, window, document);