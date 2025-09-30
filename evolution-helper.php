<?php
/**
 * Protocol Evolution Helper
 *
 * Simple script to run evolution analysis and apply improvements
 * Use this with your Grok Code Fast 1 copilot agent workflow
 */

require_once 'includes/utilities/ProtocolEvolutionEngine.php';

use Puntwork\ProtocolEvolution\ProtocolEvolutionEngine;

echo "🧬 Protocol Evolution Helper\n";
echo "===========================\n\n";

$command = $argv[1] ?? 'help';

switch ($command) {
    case 'analyze':
        echo "📊 Running evolution analysis...\n\n";
        $analysis = ProtocolEvolutionEngine::analyzeAndSuggestImprovements();

        if (isset($analysis['message'])) {
            echo "Result: {$analysis['message']}\n";
        } else {
            echo "Current protocol score: " . round($analysis['current_protocol_score'] ?? 0, 3) . "\n";
            echo "Protocol variations generated: " . count($analysis['protocol_variations'] ?? []) . "\n";
            echo "Bottlenecks identified: " . count($analysis['bottlenecks'] ?? []) . "\n";
            echo "Optimization suggestions: " . count($analysis['optimization_opportunities'] ?? []) . "\n";

            if (!empty($analysis['protocol_variations'])) {
                echo "\nBest variation score: " . round($analysis['protocol_variations'][0]['score'], 3) . "\n";
                echo "Improvements: " . implode(', ', $analysis['protocol_variations'][0]['improvements'] ?? []) . "\n";
            }
        }
        break;

    case 'apply':
        echo "🔧 Applying best evolution improvement...\n\n";
        $analysis = ProtocolEvolutionEngine::analyzeAndSuggestImprovements();

        if (!empty($analysis['protocol_variations'])) {
            $bestVariation = $analysis['protocol_variations'][0];
            $applied = ProtocolEvolutionEngine::applyProtocolVariation($bestVariation);

            if ($applied) {
                echo "✅ Protocol improvement applied successfully!\n";
                echo "   - New score: " . round($bestVariation['score'], 3) . "\n";
                echo "   - Improvements: " . implode(', ', $bestVariation['improvements'] ?? []) . "\n";
            } else {
                echo "❌ Failed to apply protocol improvement\n";
            }
        } else {
            echo "📋 No improvements available to apply\n";
        }
        break;

    case 'record':
        if (empty($argv[2])) {
            echo "❌ Usage: php evolution-helper.php record <step_id> [success=true] [duration=0]\n";
            exit(1);
        }

        $stepId = $argv[2];
        $success = ($argv[3] ?? 'true') === 'true';
        $duration = (float) ($argv[4] ?? 0);

        ProtocolEvolutionEngine::recordStepExecution($stepId, $success, $duration, [
            'ai_context_provided' => true,
            'code_comprehension_score' => 0.8,
            'ai_suggestions_accepted' => 1,
        ]);

        echo "✅ Recorded execution: $stepId (success: " . ($success ? 'yes' : 'no') . ", duration: {$duration}s)\n";
        break;

    case 'status':
        echo "📈 Evolution Status\n";
        echo "==================\n";

        $data = json_decode(file_get_contents('protocol-evolution-data.json'), true) ?: ['executions' => [], 'applied_variations' => []];

        echo "Total executions recorded: " . count($data['executions']) . "\n";
        echo "Applied variations: " . count($data['applied_variations']) . "\n";

        if (!empty($data['executions'])) {
            $latest = end($data['executions']);
            echo "Last execution: " . date('Y-m-d H:i:s', $latest['timestamp']) . "\n";
            echo "Protocol version: " . ($latest['protocol_version'] ?? 'unknown') . "\n";
        }
        break;

    default:
        echo "Protocol Evolution Helper Commands:\n";
        echo "===================================\n";
        echo "analyze          - Run evolution analysis and show suggestions\n";
        echo "apply           - Apply the best available protocol improvement\n";
        echo "record <step>   - Record a protocol step execution\n";
        echo "status          - Show evolution status and statistics\n";
        echo "help            - Show this help message\n\n";
        echo "Examples:\n";
        echo "  php evolution-helper.php analyze\n";
        echo "  php evolution-helper.php apply\n";
        echo "  php evolution-helper.php record analyze_codebase true 45.2\n";
        echo "  php evolution-helper.php status\n";
        break;
}

echo "\n🎯 Evolution helper complete.\n";
?>