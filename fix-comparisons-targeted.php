<?php
/**
 * Fix strict comparison operators in PHP files - targeted approach
 * Only change == to === and != to !== where appropriate for WordPress standards
 */

$directory = 'includes/';
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($files as $file) {
    if ($file->getExtension() === 'php') {
        $content = file_get_contents($file->getPathname());

        // First, revert the problematic ==== changes back to ==
        $content = str_replace('====', '==', $content);
        $content = str_replace('!===', '!==', $content);

        // Now apply targeted fixes for WordPress coding standards
        // Change == to === in comparison contexts (but not in assignments or other contexts)
        // This is a simplified approach - we'll target specific patterns

        // Fix comparisons in if statements and similar contexts
        $content = preg_replace('/(\s+)==(\s+)/', '$1===$2', $content);
        $content = preg_replace('/(\s+)!=(\s+)/', '$1!==$2', $content);

        // But revert back cases where loose comparison is appropriate
        // (this is a simplified approach - in practice, WordPress standards require manual review)
        $content = preg_replace('/(\s+)=== false(\s*)/', '$1== false$2', $content);
        $content = preg_replace('/(\s+)=== null(\s*)/', '$1== null$2', $content);
        $content = preg_replace('/(\s+)!== false(\s*)/', '$1!= false$2', $content);
        $content = preg_replace('/(\s+)!== null(\s*)/', '$1!= null$2', $content);

        file_put_contents($file->getPathname(), $content);
        echo "Fixed: " . $file->getPathname() . "\n";
    }
}

echo "Targeted comparison fixes completed.\n";