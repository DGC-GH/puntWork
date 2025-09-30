<?php

/**
 * Self-Improving Protocol Evolution System
 *
 * Implements evolutionary algorithms to continuously improve the maintenance protocol
 * through analysis, variation, selection, and iteration.
 */

namespace Puntwork\ProtocolEvolution;

class ProtocolEvolutionEngine
{
    private const EVOLUTION_DATA_FILE = __DIR__ . '/../../protocol-evolution-data.json';
    private const MAX_VARIATIONS = 10;
    private const TOP_PERFORMERS = 3;
    private const MUTATION_RATE = 0.1;

    /**
     * Fitness weights for protocol evaluation
     */
    private const FITNESS_WEIGHTS = [
        'execution_time' => -0.5,  // Negative because faster is better
        'success_rate' => 0.3,
        'error_reduction' => 0.2,
        'maintainability' => 0.1
    ];

    /**
     * Record execution metrics for a protocol step
     */
    public static function recordStepExecution(string $stepId, bool $success, float $duration, array $data = []): void
    {
        $data = self::loadEvolutionData();
        $execution = [
            'step_id' => $stepId,
            'timestamp' => time(),
            'metrics' => [
                'success' => $success,
                'duration' => $duration,
                'data' => $data
            ],
            'protocol_version' => self::getCurrentProtocolVersion()
        ];

        $data['executions'][] = $execution;

        // Keep only last 1000 executions to prevent file bloat
        if (count($data['executions']) > 1000) {
            $data['executions'] = array_slice($data['executions'], -1000);
        }

        self::saveEvolutionData($data);
    }

    /**
     * Analyze historical execution data and suggest improvements
     */
    public static function analyzeAndSuggestImprovements(): array
    {
        $data = self::loadEvolutionData();
        $executions = $data['executions'] ?? [];

        if (empty($executions)) {
            return ['message' => 'Insufficient execution data for analysis'];
        }

        $analysis = [
            'bottlenecks' => self::identifyBottlenecks($executions),
            'success_patterns' => self::findSuccessPatterns($executions),
            'failure_patterns' => self::findFailurePatterns($executions),
            'optimization_opportunities' => self::suggestOptimizations($executions),
            'generated_at' => time()
        ];

        // Generate protocol variations
        $currentProtocol = self::getCurrentProtocol();
        $variations = self::generateProtocolVariations($currentProtocol, $analysis);

        // Score variations
        $scoredVariations = [];
        foreach ($variations as $variation) {
            $score = self::calculateFitnessScore($variation, $executions);
            $scoredVariations[] = [
                'variation' => $variation,
                'score' => $score,
                'improvements' => self::getVariationImprovements($variation, $currentProtocol)
            ];
        }

        // Sort by score (highest first)
        usort($scoredVariations, fn($a, $b) => $b['score'] <=> $a['score']);

        $analysis['protocol_variations'] = array_slice($scoredVariations, 0, self::TOP_PERFORMERS);
        $analysis['current_protocol_score'] = self::calculateFitnessScore($currentProtocol, $executions);

        return $analysis;
    }

    /**
     * Apply a successful protocol variation
     */
    public static function applyProtocolVariation(array $variation): bool
    {
        try {
            // Backup current protocol
            $backupPath = __DIR__ . '/../../protocol.md.backup.' . time();
            if (!copy(__DIR__ . '/../../protocol.md', $backupPath)) {
                throw new \Exception('Failed to create protocol backup');
            }

            // Apply variation
            $newProtocol = self::formatProtocolForFile($variation['variation']);
            if (file_put_contents(__DIR__ . '/../../protocol.md', $newProtocol) === false) {
                throw new \Exception('Failed to write new protocol');
            }

            // Record the change
            $data = self::loadEvolutionData();
            $data['applied_variations'][] = [
                'variation' => $variation,
                'applied_at' => time(),
                'backup_file' => basename($backupPath)
            ];
            self::saveEvolutionData($data);

            return true;
        } catch (\Exception $e) {
            error_log('[PROTOCOL-EVOLUTION] Failed to apply variation: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Identify bottleneck steps from execution data
     */
    private static function identifyBottlenecks(array $executions): array
    {
        $stepStats = [];
        foreach ($executions as $execution) {
            $stepId = $execution['step_id'];
            $duration = $execution['metrics']['duration'] ?? 0;

            if (!isset($stepStats[$stepId])) {
                $stepStats[$stepId] = ['durations' => [], 'count' => 0];
            }

            $stepStats[$stepId]['durations'][] = $duration;
            $stepStats[$stepId]['count']++;
        }

        $bottlenecks = [];
        foreach ($stepStats as $stepId => $stats) {
            $avgDuration = array_sum($stats['durations']) / count($stats['durations']);
            $maxDuration = max($stats['durations']);

            // Consider bottleneck if average > 300 seconds or max > 600 seconds
            if ($avgDuration > 300 || $maxDuration > 600) {
                $bottlenecks[$stepId] = [
                    'average_duration' => round($avgDuration, 2),
                    'max_duration' => $maxDuration,
                    'execution_count' => $stats['count']
                ];
            }
        }

        return $bottlenecks;
    }

    /**
     * Find patterns in successful executions
     */
    private static function findSuccessPatterns(array $executions): array
    {
        $successfulSteps = array_filter($executions, fn($e) => $e['metrics']['success'] ?? false);
        $stepSuccess = [];

        foreach ($successfulSteps as $execution) {
            $stepId = $execution['step_id'];
            $stepSuccess[$stepId] = ($stepSuccess[$stepId] ?? 0) + 1;
        }

        arsort($stepSuccess);
        return $stepSuccess;
    }

    /**
     * Find patterns in failed executions
     */
    private static function findFailurePatterns(array $executions): array
    {
        $failedSteps = array_filter($executions, fn($e) => !($e['metrics']['success'] ?? true));
        $stepFailures = [];

        foreach ($failedSteps as $execution) {
            $stepId = $execution['step_id'];
            $error = $execution['metrics']['data']['error'] ?? 'unknown';

            if (!isset($stepFailures[$stepId])) {
                $stepFailures[$stepId] = [];
            }

            $stepFailures[$stepId][$error] = ($stepFailures[$stepId][$error] ?? 0) + 1;
        }

        return $stepFailures;
    }

    /**
     * Suggest optimizations based on execution data
     */
    private static function suggestOptimizations(array $executions): array
    {
        $suggestions = [];

        $bottlenecks = self::identifyBottlenecks($executions);
        foreach ($bottlenecks as $stepId => $data) {
            $suggestions[] = [
                'type' => 'parallel_processing',
                'step' => $stepId,
                'reason' => "High execution time (avg: {$data['average_duration']}s)",
                'suggestion' => 'Consider parallel processing or caching'
            ];
        }

        $failurePatterns = self::findFailurePatterns($executions);
        foreach ($failurePatterns as $stepId => $errors) {
            arsort($errors);
            $topError = key($errors);
            $suggestions[] = [
                'type' => 'error_handling',
                'step' => $stepId,
                'reason' => "Frequent error: $topError",
                'suggestion' => 'Add better error handling or validation'
            ];
        }

        return $suggestions;
    }

    /**
     * Generate variations of the protocol
     */
    private static function generateProtocolVariations(array $currentProtocol, array $analysis): array
    {
        $variations = [];

        for ($i = 0; $i < self::MAX_VARIATIONS; $i++) {
            $variation = self::mutateProtocol($currentProtocol, $analysis);
            $variations[] = $variation;
        }

        return $variations;
    }

    /**
     * Mutate a protocol based on analysis
     */
    private static function mutateProtocol(array $protocol, array $analysis): array
    {
        $mutated = $protocol;

        // Apply random mutations based on MUTATION_RATE
        if (mt_rand(0, 100) / 100 < self::MUTATION_RATE) {
            $mutationType = mt_rand(0, 3);

            switch ($mutationType) {
                case 0: // Reorder steps
                    shuffle($mutated);
                    break;
                case 1: // Add parallel execution hint
                    $randomIndex = array_rand($mutated);
                    $mutated[$randomIndex] .= ' (consider parallel execution)';
                    break;
                case 2: // Add automation suggestion
                    $randomIndex = array_rand($mutated);
                    $mutated[$randomIndex] .= ' (automate if possible)';
                    break;
                case 3: // Add monitoring
                    $randomIndex = array_rand($mutated);
                    $mutated[$randomIndex] .= ' (add metrics collection)';
                    break;
            }
        }

        // Apply analysis-based improvements
        if (!empty($analysis['bottlenecks'])) {
            $bottleneckSteps = array_keys($analysis['bottlenecks']);
            foreach ($bottleneckSteps as $step) {
                if (in_array($step, $mutated)) {
                    $index = array_search($step, $mutated);
                    $mutated[$index] .= ' [OPTIMIZE: High execution time]';
                }
            }
        }

        return $mutated;
    }

    /**
     * Calculate fitness score for a protocol variation
     */
    private static function calculateFitnessScore(array $protocol, array $executions): float
    {
        // Simulate execution with this protocol
        $simulatedMetrics = self::simulateProtocolExecution($protocol, $executions);

        $score = 0;
        foreach (self::FITNESS_WEIGHTS as $metric => $weight) {
            $value = $simulatedMetrics[$metric] ?? 0;
            $score += $value * $weight;
        }

        return $score;
    }

    /**
     * Simulate protocol execution to estimate metrics
     */
    private static function simulateProtocolExecution(array $protocol, array $executions): array
    {
        $stepMetrics = [];
        foreach ($executions as $execution) {
            $stepId = $execution['step_id'];
            if (!isset($stepMetrics[$stepId])) {
                $stepMetrics[$stepId] = [];
            }
            $stepMetrics[$stepId][] = $execution['metrics'];
        }

        $totalTime = 0;
        $successCount = 0;
        $totalCount = 0;
        $errorCount = 0;

        foreach ($protocol as $step) {
            // Extract step ID from step description
            $stepId = self::extractStepId($step);
            $metrics = $stepMetrics[$stepId] ?? [];

            if (!empty($metrics)) {
                $avgDuration = array_sum(array_column($metrics, 'duration')) / count($metrics);
                $avgSuccess = array_sum(array_column($metrics, 'success')) / count($metrics);
                $totalErrors = count(array_filter($metrics, fn($m) => !empty($m['data']['error'])));

                $totalTime += $avgDuration;
                $successCount += $avgSuccess;
                $totalCount += 1;
                $errorCount += $totalErrors / count($metrics);
            }
        }

        return [
            'execution_time' => $totalTime,
            'success_rate' => $totalCount > 0 ? $successCount / $totalCount : 0,
            'error_reduction' => max(0, 1 - ($errorCount / max(1, $totalCount))),
            'maintainability' => self::calculateMaintainabilityScore($protocol)
        ];
    }

    /**
     * Calculate maintainability score based on protocol structure
     */
    private static function calculateMaintainabilityScore(array $protocol): float
    {
        $score = 1.0;

        // Penalize very long protocols
        if (count($protocol) > 20) {
            $score -= 0.1;
        }

        // Reward clear, descriptive steps
        foreach ($protocol as $step) {
            if (strlen($step) > 50) {
                $score += 0.05; // Detailed steps are better
            }
            if (strpos($step, '[OPTIMIZE]') !== false) {
                $score += 0.1; // Optimization hints are good
            }
        }

        return max(0, min(1, $score));
    }

    /**
     * Extract step ID from step description
     */
    private static function extractStepId(string $step): string
    {
        // Convert step description to ID
        $id = strtolower(str_replace([' ', '-', '.', '[', ']', '(', ')'], '_', $step));
        $id = preg_replace('/_+/', '_', $id);
        $id = trim($id, '_');

        // Map common variations
        $mapping = [
            'redownload_current_version_of_debug_log_from_the_server' => 'download_debug_log',
            'read_debug_log' => 'read_debug_log',
            'read_console_txt' => 'read_console_log',
            'identify_problems' => 'identify_problems',
            'debug_issues' => 'debug_issues',
            'analyze_code_base' => 'analyze_codebase',
            'fix_errors' => 'fix_errors',
            'optimize_and_enhance_features' => 'optimize_features',
            'commit' => 'commit_changes',
            'output_summary' => 'output_summary',
            'ask_to_push' => 'ask_to_push',
        ];

        return $mapping[$id] ?? $id;
    }

    /**
     * Get current protocol as array
     */
    private static function getCurrentProtocol(): array
    {
        $protocolFile = __DIR__ . '/../../protocol.md';
        if (!file_exists($protocolFile)) {
            return [];
        }

        $content = file_get_contents($protocolFile);
        $lines = explode("\n", $content);

        $protocol = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, '- ') === 0) {
                $protocol[] = substr($line, 2);
            }
        }

        return $protocol;
    }

    /**
     * Get current protocol version (timestamp-based)
     */
    private static function getCurrentProtocolVersion(): string
    {
        $protocolFile = __DIR__ . '/../../protocol.md';
        return file_exists($protocolFile) ? filemtime($protocolFile) : 'unknown';
    }

    /**
     * Format protocol array for file writing
     */
    private static function formatProtocolForFile(array $protocol): string
    {
        $content = "# Maintenance Protocol\n\n";
        foreach ($protocol as $step) {
            $content .= "- $step\n";
        }
        $content .= "\n<!-- Generated by Protocol Evolution Engine on " . date('Y-m-d H:i:s') . " -->\n";
        return $content;
    }

    /**
     * Get improvements made by a variation
     */
    private static function getVariationImprovements(array $variation, array $current): array
    {
        $improvements = [];
        $currentSteps = array_map('strtolower', $current);
        $variationSteps = array_map('strtolower', $variation);

        // Check for new optimization hints
        foreach ($variation as $i => $step) {
            if (isset($current[$i])) {
                $currentStep = strtolower($current[$i]);
                $variationStep = strtolower($step);

                if (strpos($variationStep, '[optimize') !== false && strpos($currentStep, '[optimize') === false) {
                    $improvements[] = 'Added optimization hint to: ' . $current[$i];
                }
                if (strpos($variationStep, 'parallel') !== false && strpos($currentStep, 'parallel') === false) {
                    $improvements[] = 'Added parallel processing suggestion to: ' . $current[$i];
                }
                if (strpos($variationStep, 'automate') !== false && strpos($currentStep, 'automate') === false) {
                    $improvements[] = 'Added automation suggestion to: ' . $current[$i];
                }
            }
        }

        return $improvements;
    }

    /**
     * Load evolution data from file
     */
    private static function loadEvolutionData(): array
    {
        if (!file_exists(self::EVOLUTION_DATA_FILE)) {
            return ['executions' => [], 'applied_variations' => []];
        }

        $data = json_decode(file_get_contents(self::EVOLUTION_DATA_FILE), true);
        return $data ?: ['executions' => [], 'applied_variations' => []];
    }

    /**
     * Save evolution data to file
     */
    private static function saveEvolutionData(array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT);
        file_put_contents(self::EVOLUTION_DATA_FILE, $json);
    }

    /**
     * Run the evolution cycle
     */
    public static function runEvolutionCycle(): array
    {
        $startTime = microtime(true);

        // Analyze current performance
        $analysis = self::analyzeAndSuggestImprovements();

        // Generate and test variations
        $currentProtocol = self::getCurrentProtocol();
        $variations = self::generateProtocolVariations($currentProtocol, $analysis);

        // Score and select best
        $scoredVariations = [];
        foreach ($variations as $variation) {
            $score = self::calculateFitnessScore($variation, self::loadEvolutionData()['executions']);
            $scoredVariations[] = ['variation' => $variation, 'score' => $score];
        }

        usort($scoredVariations, fn($a, $b) => $b['score'] <=> $a['score']);
        $bestVariation = $scoredVariations[0] ?? null;

        $result = [
            'analysis' => $analysis,
            'best_variation' => $bestVariation,
            'evolution_time' => microtime(true) - $startTime,
            'improvement_potential' => $bestVariation ? $bestVariation['score'] - ($analysis['current_protocol_score'] ?? 0) : 0
        ];

        return $result;
    }
}