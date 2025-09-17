?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function download_feed($url, $xml_path, $output_dir, &$logs) {
    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $fp = fopen($xml_path, 'w');
            if (!$fp) throw new Exception("Can't open $xml_path for write");
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'WordPress Job Importer');
            $success = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fp);
            if (!$success || $http_code !== 200 || filesize($xml_path) < 1000) {
                throw new Exception("cURL download failed (HTTP $http_code, size: " . filesize($xml_path) . ")");
            }
        } else {
            $response = wp_remote_get($url, ['timeout' => 300]);
            if (is_wp_error($response)) throw new Exception($response->get_error_message());
            $body = wp_remote_retrieve_body($response);
            if (empty($body) || strlen($body) < 1000) throw new Exception('Empty or small response');
            file_put_contents($xml_path, $body);
        }
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Downloaded XML: " . filesize($xml_path) . " bytes";
        error_log("Downloaded XML: " . filesize($xml_path) . " bytes");
        @chmod($xml_path, 0644);
    } catch (Exception $e) {
        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . "Download error: " . $e->getMessage();
        return false;
    }
    return true;
}
