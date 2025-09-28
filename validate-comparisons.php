<?php
/**
 * Validate comparison operators in modified PHP files
 * Check for correct use of === vs == according to WordPress standards
 */

$modifiedFiles = [
    'includes/admin/accessibility.php',
    'includes/admin/admin-api-settings.php',
    'includes/admin/admin-feed-config.php',
    'includes/admin/admin-menu.php',
    'includes/admin/admin-page-html.php',
    'includes/admin/admin-ui-analytics.php',
    'includes/admin/admin-ui-crm.php',
    'includes/admin/admin-ui-feed-health.php',
    'includes/admin/admin-ui-main.php',
    'includes/admin/admin-ui-monitoring.php',
    'includes/admin/admin-ui-multisite.php',
    'includes/admin/admin-ui-performance.php',
    'includes/admin/admin-ui-reporting.php',
    'includes/admin/admin-ui-scheduling.php',
    'includes/admin/job-board-admin.php',
    'includes/ai/content-quality-scorer.php',
    'includes/ai/duplicate-detector.php',
    'includes/ai/feed-optimizer.php',
    'includes/ai/machine-learning-engine.php',
    'includes/ai/predictive-analytics.php',
    'includes/api/ajax-feed-processing.php',
    'includes/api/ajax-handlers.php',
    'includes/api/ajax-import-control.php',
    'includes/api/ajax-purge.php',
    'includes/api/rest-api.php',
    'includes/api/sse-import-progress.php',
    'includes/batch/batch-loading.php',
    'includes/batch/batch-processing-core.php',
    'includes/batch/batch-size-management.php',
    'includes/core/core-structure-logic.php',
    'includes/crm/crm-integration.php',
    'includes/crm/hubspot-integration.php',
    'includes/crm/pipedrive-integration.php',
    'includes/crm/zoho-integration.php',
    'includes/import/combine-jsonl.php',
    'includes/import/download-feed.php',
    'includes/import/feed-processor.php',
    'includes/import/import-batch.php',
    'includes/import/import-finalization.php',
    'includes/import/import-setup.php',
    'includes/import/process-batch-items.php',
    'includes/import/process-xml-batch.php',
    'includes/jobboards/jobboard.php',
    'includes/jobboards/linkedin-board.php',
    'includes/mappings/mappings-constants.php',
    'includes/mappings/mappings-fields.php',
    'includes/queue/queue-manager.php',
    'includes/reporting/reporting-engine.php',
    'includes/scheduling/scheduling-ajax.php',
    'includes/scheduling/scheduling-core.php',
    'includes/scheduling/scheduling-history.php',
    'includes/socialmedia/facebook-ads-manager.php',
    'includes/socialmedia/facebook-platform.php',
    'includes/socialmedia/tiktok-ads-manager.php',
    'includes/socialmedia/tiktok-platform.php',
    'includes/socialmedia/twitter-ads-manager.php',
    'includes/utilities/AdvancedMemoryManager.php',
    'includes/utilities/CacheManager.php',
    'includes/utilities/CircuitBreaker.php',
    'includes/utilities/DatabasePerformanceMonitor.php',
    'includes/utilities/EnhancedCacheManager.php',
    'includes/utilities/FeedHealthMonitor.php',
    'includes/utilities/ImportAnalytics.php',
    'includes/utilities/JobDeduplicator.php',
    'includes/utilities/PuntWorkLogger.php',
    'includes/utilities/PuntworkHorizontalScalingManager.php',
    'includes/utilities/PuntworkLoadBalancer.php',
    'includes/utilities/PuntworkTracing.php',
    'includes/utilities/SecurityUtils.php',
    'includes/utilities/fix-jsonl-html.php',
    'includes/utilities/handle-duplicates.php',
    'includes/utilities/heartbeat-control.php',
    'includes/utilities/item-inference.php',
    'includes/utilities/utility-helpers.php'
];

$issues = [];

foreach ($modifiedFiles as $file) {
    if (!file_exists($file)) {
        continue;
    }

    $content = file_get_contents($file);
    $lines = explode("\n", $content);

    foreach ($lines as $lineNum => $line) {
        $lineNumber = $lineNum + 1;

        // Check for loose comparisons that should be strict
        if (preg_match('/\s+==\s+/', $line) && !preg_match('/\s+==\s+(false|null|true|\d+|[\'"])/', $line)) {
            // Skip cases where loose comparison might be acceptable
            if (!preg_match('/(== false|== null|== true|== \d+|== \'|== ")/', $line)) {
                $issues[] = [
                    'file' => $file,
                    'line' => $lineNumber,
                    'type' => 'loose_comparison',
                    'content' => trim($line),
                    'operator' => '=='
                ];
            }
        }

        if (preg_match('/\s+!=\s+/', $line) && !preg_match('/\s+!=\s+(false|null|true|\d+|[\'"])/', $line)) {
            // Skip cases where loose comparison might be acceptable
            if (!preg_match('/(!= false|!= null|!= true|!= \d+|!= \'|!= ")/', $line)) {
                $issues[] = [
                    'file' => $file,
                    'line' => $lineNumber,
                    'type' => 'loose_comparison',
                    'content' => trim($line),
                    'operator' => '!='
                ];
            }
        }

        // Check for potential issues with strict comparisons
        if (preg_match('/\s+===\s+(false|null)/', $line)) {
            $issues[] = [
                'file' => $file,
                'line' => $lineNumber,
                'type' => 'strict_vs_loose',
                'content' => trim($line),
                'message' => 'Comparing with false/null using strict comparison - consider loose comparison'
            ];
        }
    }
}

echo "Comparison Operator Validation Results:\n";
echo "======================================\n\n";

if (empty($issues)) {
    echo "✅ All comparison operators in modified files follow WordPress standards!\n";
} else {
    echo "⚠️  Found " . count($issues) . " potential issues:\n\n";

    foreach ($issues as $issue) {
        echo "📁 " . $issue['file'] . ":" . $issue['line'] . "\n";
        echo "   " . $issue['content'] . "\n";
        if (isset($issue['operator'])) {
            echo "   ❌ Uses loose comparison '" . $issue['operator'] . "' - should use strict comparison\n";
        }
        if (isset($issue['message'])) {
            echo "   ⚠️  " . $issue['message'] . "\n";
        }
        echo "\n";
    }
}

echo "\nSummary:\n";
$looseCount = count(array_filter($issues, fn($i) => $i['type'] === 'loose_comparison'));
$strictIssues = count(array_filter($issues, fn($i) => $i['type'] === 'strict_vs_loose'));

echo "- Loose comparisons that should be strict: $looseCount\n";
echo "- Strict comparisons that might be better as loose: $strictIssues\n";
?>