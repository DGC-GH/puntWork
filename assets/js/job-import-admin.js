/**
 * Job Import Admin - Main Module
 * Main entry point that combines all job import modules
 */

(function($, window, document) {
    'use strict';

    // Include dependencies check
    if (typeof JobImportUI === 'undefined') {
        console.error('JobImportUI module not loaded');
        return;
    }
    if (typeof JobImportAPI === 'undefined') {
        console.error('JobImportAPI module not loaded');
        return;
    }
    if (typeof JobImportLogic === 'undefined') {
        console.error('JobImportLogic module not loaded');
        return;
    }
    if (typeof JobImportEvents === 'undefined') {
        console.error('JobImportEvents module not loaded');
        return;
    }

    var PuntWorkJobImportAdmin = {
        /**
         * Initialize the job import admin functionality
         */
        init: function() {
            console.log('Initializing Job Import Admin...');
            JobImportEvents.init();
        }
    };

    // Expose to global scope
    window.PuntWorkJobImportAdmin = PuntWorkJobImportAdmin;

})(jQuery, window, document);