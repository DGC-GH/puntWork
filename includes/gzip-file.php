<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

namespace Puntwork;

function gzip_file($source_path, $gz_path) {
    // Validate file paths
    $real_source_dir = realpath(dirname($source_path));
    $real_gz_dir = realpath(dirname($gz_path));
    if ($real_source_dir === false || $real_gz_dir === false || $real_source_dir !== $real_gz_dir) {
        throw new Exception('Invalid file paths for gzip');
    }
    $gz = gzopen($gz_path, 'w9');
    gzwrite($gz, file_get_contents($source_path));
    gzclose($gz);
    error_log("Gzipped: $gz_path");
}
