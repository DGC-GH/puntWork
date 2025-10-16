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

        <h2>REST API token (optional)</h2>
        <p>Set a token to allow non-interactive access to <code>/wp-json/puntwork/v1/diagnostics</code>. Leave empty to require admin session.</p>
        <div style="display:flex; gap:8px; align-items:center; margin-bottom:12px;">
            <input id="puntwork-rest-token" type="text" style="width:420px;" placeholder="Enter or generate token" />
            <button id="puntwork-generate-token" class="button">Generate</button>
            <button id="puntwork-save-token" class="button button-primary">Save</button>
            <button id="puntwork-test-token" class="button">Test</button>
        </div>
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

        // Token UI logic
        const tokenInput = document.getElementById('puntwork-rest-token');
        const genBtn = document.getElementById('puntwork-generate-token');
        const saveBtn = document.getElementById('puntwork-save-token');
        const testBtn = document.getElementById('puntwork-test-token');

        genBtn.addEventListener('click', function(){
            // simple random token
            const token = Array.from(crypto.getRandomValues(new Uint8Array(24))).map(b => b.toString(16).padStart(2, '0')).join('');
            tokenInput.value = token;
        });

        saveBtn.addEventListener('click', async function(){
            const token = tokenInput.value.trim();
            show('Saving token...');
            try {
                const form = new FormData();
                form.append('action', 'puntwork_save_rest_token');
                form.append('token', token);
                // include nonce if available
                if (typeof jobImportData !== 'undefined' && jobImportData.nonce) {
                    form.append('nonce', jobImportData.nonce);
                }

                // determine ajax URL with fallbacks
                const ajaxEndpoint = (typeof jobImportData !== 'undefined' && jobImportData.ajaxurl) ? jobImportData.ajaxurl : (window.ajaxurl || '/wp-admin/admin-ajax.php');

                const res = await fetch(ajaxEndpoint, { method: 'POST', credentials: 'same-origin', body: form });
                const json = await res.json();
                if (json && json.success) {
                    show(JSON.stringify({ status: res.status, body: json }, null, 2));
                    // update input with saved token (in case server normalizes it)
                    tokenInput.value = token;
                } else {
                    show(JSON.stringify({ status: res.status, body: json }, null, 2));
                }
            } catch (e) {
                show('Save failed: ' + e.message);
            }
        });

        testBtn.addEventListener('click', async function(){
            const token = tokenInput.value.trim();
            if (!token) { show('Enter a token or save one before testing.'); return; }
            show('Testing REST endpoint...');
            try {
                const res = await fetch('/wp-json/puntwork/v1/diagnostics', { method: 'GET', headers: { 'X-PUNTWORK-TOKEN': token } });
                const text = await res.text();
                let parsed; try { parsed = JSON.parse(text); } catch (e) { parsed = text; }
                show(JSON.stringify({ status: res.status, body: parsed }, null, 2));
            } catch (e) {
                show('Test failed: ' + e.message);
            }
        });

        // initialize input with saved value via admin-ajax getter (admin session required)
        (async function initToken() {
            try {
                const ajaxEndpoint = (typeof jobImportData !== 'undefined' && jobImportData.ajaxurl) ? jobImportData.ajaxurl : (window.ajaxurl || '/wp-admin/admin-ajax.php');
                const form = new FormData();
                form.append('action', 'puntwork_get_rest_token');
                if (typeof jobImportData !== 'undefined' && jobImportData.nonce) {
                    form.append('nonce', jobImportData.nonce);
                }
                const res = await fetch(ajaxEndpoint, { method: 'POST', credentials: 'same-origin', body: form });
                const json = await res.json();
                if (json && json.success && json.data && typeof json.data.token !== 'undefined') {
                    tokenInput.value = json.data.token;
                }
            } catch (e) {
                // ignore — best-effort
            }
        })();
    })();
    </script>
    <?php
}
