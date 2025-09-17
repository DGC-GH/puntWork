if (!function_exists('get_memory_limit_bytes')) {
    function get_memory_limit_bytes() {
        $memory_limit = ini_get('memory_limit');
        if ($memory_limit == '-1') return PHP_INT_MAX;
        $number = (int) preg_replace('/[^0-9]/', '', $memory_limit);
        $suffix = preg_replace('/[0-9]/', '', $memory_limit);
        switch (strtoupper($suffix)) {
            case 'G': return $number * 1024 * 1024 * 1024;
            case 'M': return $number * 1024 * 1024;
            case 'K': return $number * 1024;
            default: return $number;
        }
    }
}

if (!function_exists('get_json_item_count')) {
    function get_json_item_count($json_path) {
        if (false !== ($cached_count = get_option('job_json_total_count'))) {
            return $cached_count;
        }
        $count = count(file($json_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        update_option('job_json_total_count', $count, false);
        return $count;
    }
}

if (!function_exists('load_json_batch')) {
    function load_json_batch($json_path, $start, $batch_size) {
        $file = new SplFileObject($json_path);
        $file->seek($start);
        $batch = [];
        for ($i = 0; $i < $batch_size && !$file->eof(); $i++) {
            $line = $file->fgets();
            if (trim($line)) {
                $item = json_decode($line, true);
                if ($item) $batch[] = $item;
            }
        }
        unset($file);
        return $batch;
    }
}

function gzip_file($source_path, $gz_path) {
    $gz = gzopen($gz_path, 'w9');
    gzwrite($gz, file_get_contents($source_path));
    gzclose($gz);
    error_log("Gzipped: $gz_path");
}

function clean_item_fields(&$item) {
    $html_fields = ['description', 'functiondescription', 'offerdescription', 'requirementsdescription', 'companydescription'];
    foreach ($html_fields as $field) {
        if (isset($item->$field)) {
            $content = (string)$item->$field;
            $content = wp_kses($content, wp_kses_allowed_html('post'));
            $content = preg_replace('/\s*styles*=\s*["\'][^"\']*["\']/', '', $content);
            $content = preg_replace('/[\x{1F600}-\x{1F64F}]/u', '', $content);
            $content = str_replace('&nbsp;', ' ', $content);
            $item->$field = trim($content);
        }
    }
    $title_fields = ['functiontitle'];
    foreach ($title_fields as $field) {
        if (isset($item->$field)) {
            $content = (string)$item->$field;
            $content = preg_replace('/\s+(m\/v\/x|h\/f\/x)/i', '', $content);
            $item->$field = trim($content);
        }
    }
}

// Partial inference (full in processor.php for context)
