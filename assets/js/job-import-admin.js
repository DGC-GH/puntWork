/**
 * Job Import Admin - Main Module
 * Main entry point that combines all job import modules
 */

console.log('[PUNTWORK] job-import-admin.js loaded - DEBUG MODE');

(function($, window, document) {
    'use strict';

    // Include dependencies check
    console.log('[PUNTWORK] Checking dependencies...');
    console.log('[PUNTWORK] PuntWorkJSLogger:', typeof PuntWorkJSLogger);
    console.log('[PUNTWORK] JobImportUI:', typeof JobImportUI);
    console.log('[PUNTWORK] JobImportAPI:', typeof JobImportAPI);
    console.log('[PUNTWORK] JobImportLogic:', typeof JobImportLogic);
    console.log('[PUNTWORK] JobImportEvents:', typeof JobImportEvents);
    console.log('[PUNTWORK] JobImportScheduling:', typeof JobImportScheduling);
    console.log('[PUNTWORK] JobImportRealtime:', typeof JobImportRealtime);

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
    if (typeof JobImportRealtime === 'undefined') {
        console.error('[PUNTWORK] Critical Error: JobImportRealtime module not loaded - cannot initialize job import admin');
        return;
    }

    var PuntWorkJobImportAdmin = {
        /**
         * Initialize the job import admin functionality
         */
        init: function() {
            console.log('[PUNTWORK] PuntWorkJobImportAdmin.init() called');
            PuntWorkJSLogger.info('Initializing Job Import Admin...', 'SYSTEM');
            PuntWorkJSLogger.debug('Available modules', 'SYSTEM', {
                logger: typeof PuntWorkJSLogger !== 'undefined',
                ui: typeof JobImportUI !== 'undefined',
                api: typeof JobImportAPI !== 'undefined',
                logic: typeof JobImportLogic !== 'undefined',
                events: typeof JobImportEvents !== 'undefined',
                scheduling: typeof JobImportScheduling !== 'undefined',
                realtime: typeof JobImportRealtime !== 'undefined'
            });

            // Only initialize if not already initialized by inline script
            if (typeof window.jobImportInitialized === 'undefined') {
                console.log('[PUNTWORK] Calling JobImportEvents.init()');
                JobImportEvents.init();
                console.log('[PUNTWORK] Calling JobImportScheduling.init()');
                JobImportScheduling.init();
                window.jobImportInitialized = true;
                console.log('[PUNTWORK] Job Import Admin initialization complete');
                PuntWorkJSLogger.info('Job Import Admin initialization complete', 'SYSTEM');
            } else {
                console.log('[PUNTWORK] Job Import Admin already initialized by inline script');
                PuntWorkJSLogger.info('Job Import Admin already initialized by inline script', 'SYSTEM');
            }
        }
    };

    // Expose to global scope
    window.PuntWorkJobImportAdmin = PuntWorkJobImportAdmin;

    // Auto-initialize when DOM is ready
    console.log('[PUNTWORK] Setting up auto-initialization (DISABLED)');
    // $(document).ready(function() {
    //     console.log('[PUNTWORK] Document ready, calling PuntWorkJobImportAdmin.init()');
    //     PuntWorkJobImportAdmin.init();
    // });

})(jQuery, window, document);