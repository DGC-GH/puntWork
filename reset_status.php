<?php
require_once(dirname(__FILE__) . "/wp-load.php");
delete_option("job_import_status");
delete_option("job_import_progress");
delete_option("job_import_processed_guids");
delete_option("job_import_start_time");
echo "Import status reset
";
?>
