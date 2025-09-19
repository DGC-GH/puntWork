/**
 * Job Import Admin - Main Module
 * Main entry point that combines all job import modules
 */

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
                events: typeof JobImportEvents !== 'undefined'
            });

            JobImportEvents.init();
            PuntWorkJSLogger.info('Job Import Admin initialization complete', 'SYSTEM');
        }
    };

    // Expose to global scope
    window.PuntWorkJobImportAdmin = PuntWorkJobImportAdmin;

})(jQuery, window, document);