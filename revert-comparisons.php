<?php
/**
 * Revert problematic comparison operator changes
 */

$directory = 'includes/';
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($files as $file) {
    if ($file->getExtension() === 'php') {
        $content = file_get_contents($file->getPathname());

        // Revert ==== back to ==
        $content = str_replace('====', '==', $content);
        $content = str_replace('!===', '!==', $content);

        file_put_contents($file->getPathname(), $content);
        echo "Reverted: " . $file->getPathname() . "\n";
    }
}

echo "Reversion completed.\n";