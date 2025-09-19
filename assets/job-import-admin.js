/**
 * Job Import Admin - Main Module
 * Main entry point that combines all job import modules
 */

console.log('[PUNTWORK] job-import-admin.js loaded');

(function($, window, document) {
    'use strict';

    // Include dependencies check
    if (typeof PuntWorkJSLogger === 'undefined') {
        console.error('[PUNTWORK] Critical Error: PuntWorkJSLogger module not loaded - cannot initialize job import admin');
        return;
    }
    if (typeof JobImportUI === 'undefined') {
        console.error('[PUNTWORK] Critical Error: JobImportUI module not loaded - cannot initialize job import admin');
        return;
    }
    if (typeof JobImportAPI === 'undefined') {
        console.error('[PUNTWORK] Critical Error: JobImportAPI module not loaded - cannot initialize job import admin');
        return;
    }
    if (typeof JobImportLogic === 'undefined') {
        console.error('[PUNTWORK] Critical Error: JobImportLogic module not loaded - cannot initialize job import admin');
        return;
    }
    if (typeof JobImportEvents === 'undefined') {
        console.error('[PUNTWORK] Critical Error: JobImportEvents module not loaded - cannot initialize job import admin');
        return;
    }
    if (typeof JobImportScheduling === 'undefined') {
        console.error('[PUNTWORK] Critical Error: JobImportScheduling module not loaded - cannot initialize job import admin');
        return;
    }

    var PuntWorkJobImportAdmin = {
        /**
         * Initialize the job import admin functionality
         */
        init: function() {
            PuntWorkJSLogger.info('Initializing Job Import Admin...', 'SYSTEM');
            PuntWorkJSLogger.debug('Available modules', 'SYSTEM', {
                logger: typeof PuntWorkJSLogger !== 'undefined',
                ui: typeof JobImportUI !== 'undefined',
                api: typeof JobImportAPI !== 'undefined',
                logic: typeof JobImportLogic !== 'undefined',
                events: typeof JobImportEvents !== 'undefined',
                scheduling: typeof JobImportScheduling !== 'undefined'
            });

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