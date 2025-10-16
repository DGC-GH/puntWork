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
                // Use GET query string to avoid some WAFs/mod_security rules that block POST bodies
                const url = new URL(jobImportData.ajaxurl, location.href);
                url.searchParams.append('action', 'puntwork_import_diagnostics');
                url.searchParams.append('nonce', jobImportData.nonce);
                const res = await fetch(url.toString(), { method: 'GET', credentials: 'same-origin' });

                // Collect status, statusText and a small subset of headers for debugging
                const statusInfo = {
                    status: res.status,
                    statusText: res.statusText,
                    headers: {
                        'content-type': res.headers.get('content-type'),
                        'x-wp-total': res.headers.get('x-wp-total'),
                        'x-wp-totalpages': res.headers.get('x-wp-totalpages')
                    }
                };

                const text = await res.text();
                let parsed;
                try { parsed = JSON.parse(text); } catch(e) { parsed = text; }

                show(JSON.stringify({ status: statusInfo, body: parsed }, null, 2));
            } catch (e) {
                show('Fetch failed: ' + e.message);
            }
        });
    })();
    </script>
    <?php
}
