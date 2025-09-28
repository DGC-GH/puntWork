<?php
/**
 * Fix comparison operators in modified PHP files
 * Convert loose comparisons to strict where appropriate
 */

$filesToFix = [
    'includes/admin/admin-ui-feed-health.php',
    'includes/admin/admin-ui-performance.php',
    'includes/ai/duplicate-detector.php',
    'includes/ai/feed-optimizer.php',
    'includes/api/ajax-import-control.php',
    'includes/core/core-structure-logic.php',
    'includes/import/feed-processor.php',
    'includes/import/import-setup.php',
    'includes/import/process-batch-items.php',
    'includes/scheduling/scheduling-ajax.php',
    'includes/utilities/CircuitBreaker.php',
    'includes/utilities/ImportAnalytics.php',
    'includes/utilities/JobDeduplicator.php',
    'includes/utilities/PuntWorkLogger.php',
    'includes/utilities/PuntworkLoadBalancer.php'
];

$fixesApplied = 0;

foreach ($filesToFix as $file) {
    if (!file_exists($file)) {
        echo "⚠️  File not found: $file\n";
        continue;
    }

    $content = file_get_contents($file);
    $originalContent = $content;

    // Fix loose comparisons to strict, but be careful about specific cases
    // Pattern 1: General loose comparison == (but not == followed by space and specific values)
    $content = preg_replace('/(\s+)==(\s+)(?!false|null|true|\d+|[\'"])/', '$1===$2', $content);

    // Pattern 2: Loose comparison != (but not != followed by space and specific values)
    $content = preg_replace('/(\s+)!=(\s+)(?!false|null|true|\d+|[\'"])/', '$1!==$2', $content);

    // Special handling for specific cases that might need manual review
    // These are cases where loose comparison might be acceptable but we'll convert anyway
    // as per WordPress standards preference for strict comparisons

    if ($content !== $originalContent) {
        file_put_contents($file, $content);
        $fixesApplied++;
        echo "✅ Fixed comparison operators in: $file\n";
    }
}

echo "\nSummary: Applied fixes to $fixesApplied files\n";

// Run PHP syntax check on all modified files
echo "\nRunning PHP syntax validation...\n";
foreach ($filesToFix as $file) {
    if (!file_exists($file)) continue;

    $output = shell_exec("php -l \"$file\" 2>&1");
    if (strpos($output, 'No syntax errors detected') === false) {
        echo "❌ Syntax error in $file:\n$output\n";
    } else {
        echo "✅ Syntax OK: $file\n";
    }
}
?>