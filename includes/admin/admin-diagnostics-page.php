<?php
/**
 * Admin diagnostics page for PuntWork
 */
namespace Puntwork;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

function puntwork_diagnostics_page() {
    ?>
    <div class="wrap">
        <h1>puntWork — Import Diagnostics</h1>
        <p>Click "Refresh" to call the diagnostics endpoint and display the latest import diagnostics snapshot.</p>
        <p>
            <button id="puntwork-diagnostics-refresh" class="button button-primary">Refresh</button>
        </p>
        <pre id="puntwork-diagnostics-output" style="white-space:pre-wrap; background:#fff; padding:12px; border:1px solid #ddd; max-height:60vh; overflow:auto;"></pre>
    </div>
    <script>
    (function(){
        function show(msg){ document.getElementById('puntwork-diagnostics-output').textContent = msg; }
        document.getElementById('puntwork-diagnostics-refresh').addEventListener('click', async function(){
            if (typeof jobImportData === 'undefined') { show('jobImportData not found — open the import admin page to ensure scripts are enqueued.'); return; }
            show('Fetching...');
            try {
                const form = new URLSearchParams();
                form.append('action', 'puntwork_import_diagnostics');
                form.append('nonce', jobImportData.nonce);
                const res = await fetch(jobImportData.ajaxurl, { method: 'POST', body: form, credentials: 'same-origin' });
                const text = await res.text();
                let parsed;
                try { parsed = JSON.parse(text); } catch(e) { parsed = text; }
                show(JSON.stringify(parsed, null, 2));
            } catch (e) {
                show('Fetch failed: ' + e.message);
            }
        });
    })();
    </script>
    <?php
}
