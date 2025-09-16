function gzip_file($source_path, $gz_path) {
    $gz = gzopen($gz_path, 'w9');
    gzwrite($gz, file_get_contents($source_path));
    gzclose($gz);
    error_log("Gzipped: $gz_path");
}
