<?php
/**
 * Fix strict comparison operators in PHP files
 * Changes == to === and != to !==
 */

$directory = 'includes/';
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($files as $file) {
    if ($file->getExtension() === 'php') {
        $content = file_get_contents($file->getPathname());

        // Replace loose comparisons with strict comparisons
        $content = preg_replace('/== /', '=== ', $content);
        $content = preg_replace('/!= /', '!== ', $content);
        $content = preg_replace('/ == /', ' === ', $content);
        $content = preg_replace('/ != /', ' !== ', $content);

        file_put_contents($file->getPathname(), $content);
        echo "Fixed: " . $file->getPathname() . "\n";
    }
}

echo "Strict comparison fixes completed.\n";