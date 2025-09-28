<?php
/**
 * Fix comparison operators according to WordPress coding standards
 * Change loose comparisons (== !=) to strict comparisons (=== !==) where appropriate
 */

$directory = 'includes/';
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
);

$fixedCount = 0;

foreach ($files as $file) {
    if ($file->getExtension() === 'php') {
        $content = file_get_contents($file->getPathname());
        $originalContent = $content;

        // Change loose comparisons to strict comparisons in appropriate contexts
        // This targets common patterns where strict comparison is preferred

        // Fix comparisons in assignments and return statements
        $content = preg_replace('/(\$[a-zA-Z_][a-zA-Z0-9_]*(?:\[[^\]]*\])?\s*=\s*\([^)]*)\s*==(\s*\$[a-zA-Z_][a-zA-Z0-9_]*(?:\[[^\]]*\])?\s*\))/', '$1===$2', $content);
        $content = preg_replace('/(\$[a-zA-Z_][a-zA-Z0-9_]*(?:\[[^\]]*\])?\s*=\s*\([^)]*)\s*!=\s*(\$[a-zA-Z_][a-zA-Z0-9_]*(?:\[[^\]]*\])?\s*\))/', '$1!==$2', $content);

        // Fix comparisons in if statements and similar control structures
        $content = preg_replace('/(\s+if\s*\([^)]*)\s*==(\s*\$[a-zA-Z_][a-zA-Z0-9_]*(?:\[[^\]]*\])?\s*\))/', '$1===$2', $content);
        $content = preg_replace('/(\s+if\s*\([^)]*)\s*!=\s*(\$[a-zA-Z_][a-zA-Z0-9_]*(?:\[[^\]]*\])?\s*\))/', '$1!==$2', $content);

        // Fix comparisons with function calls
        $content = preg_replace('/(\s+if\s*\([^)]*substr\s*\([^)]*\)\s*==)/', '$1===', $content);
        $content = preg_replace('/(\s+if\s*\([^)]*substr\s*\([^)]*\)\s*!=\s*)/', '$1!==', $content);

        // Fix specific patterns that are clearly wrong
        $content = str_replace('== $bom', '=== $bom', $content);
        $content = str_replace('== $aggregated[\'batches_total\']', '=== $aggregated[\'batches_total\']', $content);

        if ($content !== $originalContent) {
            file_put_contents($file->getPathname(), $content);
            echo "Fixed: " . $file->getPathname() . "\n";
            $fixedCount++;
        }
    }
}

echo "Fixed $fixedCount files with comparison operators.\n";