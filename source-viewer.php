<?php
// Force plain output for all UAs
if (strpos($_SERVER['HTTP_USER_AGENT'] ?? '', 'bot') !== false || empty($_SERVER['HTTP_USER_AGENT'])) {
    header('X-Robots-Tag: noindex, nofollow');
}

// Temporary source viewer - DELETE AFTER USE! Restrict access if needed (e.g., via IP in .htaccess).
if (!isset($_GET['file']) || !preg_match('/^[a-zA-Z0-9\/\-\._]+$/', $_GET['file'])) {
    http_response_code(400);
    echo 'Invalid or missing file parameter.';
    exit;
}

$filePath = __DIR__ . '/' . $_GET['file'];
if (!file_exists($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    echo 'File not found or inaccessible.';
    exit;
}

// Optional: Add basic auth or token check here for extra security, e.g.:
// if ($_GET['token'] !== 'your-secret-token') { http_response_code(403); exit; }

// Output raw file content with PHP highlighting disabled for clean fetch
header('Content-Type: text/plain; charset=utf-8');
readfile($filePath);
?>
