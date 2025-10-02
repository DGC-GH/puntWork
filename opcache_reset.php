<?php
// Force OPcache reset
if (function_exists("opcache_reset")) {
    opcache_reset();
    echo "OPcache reset successfully
";
} else {
    echo "OPcache reset function not available
";
}

// Check OPcache status
if (function_exists("opcache_get_status")) {
    $status = opcache_get_status();
    echo "OPcache enabled: " . ($status["opcache_enabled"] ? "Yes" : "No") . "
";
    if (isset($status["cache_full"])) {
        echo "Cache full: " . ($status["cache_full"] ? "Yes" : "No") . "
";
    }
}
?>
