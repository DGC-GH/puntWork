<?php

/**
 * Self-Improving Protocol Evolution System.
 *
 * Implements evolutionary algorithms to continuously improve the maintenance protocol
 * through analysis, variation, selection, and iteration.
 */

namespace Puntwork\ProtocolEvolution;

class ProtocolEvolutionEngine {

	private const EVOLUTION_DATA_FILE = __DIR__ . '/../../protocol-evolution-data.json';
	private const MAX_VARIATIONS      = 10;
	private const TOP_PERFORMERS      = 3;
	private const MUTATION_RATE       = 0.1;

	/**
	 * Fitness weights for protocol evaluation (AI-optimized).
	 */
	private const FITNESS_WEIGHTS = array(
		'execution_time'   => -0.4,  // Negative because faster is better
		'success_rate'     => 0.25,
		'error_reduction'  => 0.15,
		'maintainability'  => 0.1,
		'ai_comprehension' => 0.05,  // New: AI code understanding score
		'code_quality'     => 0.05,  // New: Code clarity and structure
	);

	/**
	 * Record execution metrics for a protocol step.
	 */
	public static function recordStepExecution( string $stepId, bool $success, float $duration, array $data = array() ): void {
		$dataFile = self::loadEvolutionData();

		// Only store essential metrics to prevent memory issues
		$execution = array(
			'step_id'          => $stepId,
			'timestamp'        => time(),
			'metrics'          => array(
				'success'                  => $success,
				'duration'                 => $duration,
				// Store only essential data, not full result arrays
				'data_size'                => isset( $data ) ? count( $data ) : 0,
				'has_error'                => isset( $data['error'] ),
				// AI-specific metrics
				'ai_context_provided'      => $data['ai_context_provided'] ?? false,
				'code_comprehension_score' => $data['code_comprehension_score'] ?? 0,
				'ai_suggestions_accepted'  => $data['ai_suggestions_accepted'] ?? 0,
			),
			'protocol_version' => self::getCurrentProtocolVersion(),
		);

		$dataFile['executions'][] = $execution;

		// Limit stored executions to prevent file bloat (keep last 500)
		if ( count( $dataFile['executions'] ) > 500 ) {
			$dataFile['executions'] = array_slice( $dataFile['executions'], -500 );
		}

		self::saveEvolutionData( $dataFile );
	}

	/**
	 * Analyze historical execution data and suggest improvements.
	 */
	public static function analyzeAndSuggestImprovements(): array {
		$data       = self::loadEvolutionData();
		$executions = $data['executions'] ?? array();

		// Limit analysis to last 500 executions to prevent memory issues
		if ( count( $executions ) > 500 ) {
			$executions = array_slice( $executions, -500 );
		}

		if ( empty( $executions ) ) {
			return array( 'message' => 'Insufficient execution data for analysis' );
		}

		$analysis = array(
			'bottlenecks'                 => self::identifyBottlenecks( $executions ),
			'success_patterns'            => self::findSuccessPatterns( $executions ),
			'failure_patterns'            => self::findFailurePatterns( $executions ),
			'optimization_opportunities'  => self::suggestOptimizations( $executions ),
			// AI-specific analysis
			'ai_performance'              => self::analyzeAIPerformance( $executions ),
			'code_comprehension_metrics'  => self::analyzeCodeComprehension( $executions ),
			'ai_optimization_suggestions' => self::suggestAIOptimizations( $executions ),
			'generated_at'                => time(),
		);

		// Generate protocol variations
		$currentProtocol = self::getCurrentProtocol();
		$variations      = self::generateProtocolVariations( $currentProtocol, $analysis );

		// Score variations
		$scoredVariations = array();
		foreach ( $variations as $variation ) {
			$score              = self::calculateFitnessScore( $variation, $executions );
			$scoredVariations[] = array(
				'variation'    => $variation,
				'score'        => $score,
				'improvements' => self::getVariationImprovements( $variation, $currentProtocol ),
			);
		}

		// Sort by score (highest first)
		usort( $scoredVariations, fn ( $a, $b ) => $b['score'] <=> $a['score'] );

		$analysis['protocol_variations']    = array_slice( $scoredVariations, 0, self::TOP_PERFORMERS );
		$analysis['current_protocol_score'] = self::calculateFitnessScore( $currentProtocol, $executions );

		return $analysis;
	}

	/**
	 * Apply a successful protocol variation.
	 */
	public static function applyProtocolVariation( array $variation ): bool {
		try {
			// Backup current protocol
			$backupPath = __DIR__ . '/../../protocol.md.backup.' . time();
			if ( ! copy( __DIR__ . '/../../protocol.md', $backupPath ) ) {
				throw new \Exception( 'Failed to create protocol backup' );
			}

			// Apply variation
			$newProtocol = self::formatProtocolForFile( $variation['variation'] );
			if ( file_put_contents( __DIR__ . '/../../protocol.md', $newProtocol ) === false ) {
				throw new \Exception( 'Failed to write new protocol' );
			}

			// Record the change
			$data                         = self::loadEvolutionData();
			$data['applied_variations'][] = array(
				'variation'   => $variation,
				'applied_at'  => time(),
				'backup_file' => basename( $backupPath ),
			);
			self::saveEvolutionData( $data );

			return true;
		} catch ( \Exception $e ) {
			error_log( '[PROTOCOL-EVOLUTION] Failed to apply variation: ' . $e->getMessage() );

			return false;
		}
	}

	/**
	 * Identify bottleneck steps from execution data.
	 */
	private static function identifyBottlenecks( array $executions ): array {
		$stepStats = array();
		foreach ( $executions as $execution ) {
			$stepId   = $execution['step_id'];
			$duration = $execution['metrics']['duration'] ?? 0;

			if ( ! isset( $stepStats[ $stepId ] ) ) {
				$stepStats[ $stepId ] = array(
					'durations' => array(),
					'count'     => 0,
				);
			}

			$stepStats[ $stepId ]['durations'][] = $duration;
			++$stepStats[ $stepId ]['count'];
		}

		$bottlenecks = array();
		foreach ( $stepStats as $stepId => $stats ) {
			$avgDuration = array_sum( $stats['durations'] ) / count( $stats['durations'] );
			$maxDuration = max( $stats['durations'] );

			// Consider bottleneck if average > 300 seconds or max > 600 seconds
			if ( $avgDuration > 300 || $maxDuration > 600 ) {
				$bottlenecks[ $stepId ] = array(
					'average_duration' => round( $avgDuration, 2 ),
					'max_duration'     => $maxDuration,
					'execution_count'  => $stats['count'],
				);
			}
		}

		return $bottlenecks;
	}

	/**
	 * Find patterns in successful executions.
	 */
	private static function findSuccessPatterns( array $executions ): array {
		$successfulSteps = array_filter( $executions, fn ( $e ) => $e['metrics']['success'] ?? false );
		$stepSuccess     = array();

		foreach ( $successfulSteps as $execution ) {
			$stepId                 = $execution['step_id'];
			$stepSuccess[ $stepId ] = ( $stepSuccess[ $stepId ] ?? 0 ) + 1;
		}

		arsort( $stepSuccess );

		return $stepSuccess;
	}

	/**
	 * Find patterns in failed executions.
	 */
	private static function findFailurePatterns( array $executions ): array {
		$failedSteps  = array_filter( $executions, fn ( $e ) => ! ( $e['metrics']['success'] ?? true ) );
		$stepFailures = array();

		foreach ( $failedSteps as $execution ) {
			$stepId = $execution['step_id'];
			$error  = $execution['metrics']['data']['error'] ?? 'unknown';

			if ( ! isset( $stepFailures[ $stepId ] ) ) {
				$stepFailures[ $stepId ] = array();
			}

			$stepFailures[ $stepId ][ $error ] = ( $stepFailures[ $stepId ][ $error ] ?? 0 ) + 1;
		}

		return $stepFailures;
	}

	/**
	 * Suggest optimizations based on execution data.
	 */
	private static function suggestOptimizations( array $executions ): array {
		$suggestions = array();

		$bottlenecks = self::identifyBottlenecks( $executions );
		foreach ( $bottlenecks as $stepId => $data ) {
			$suggestions[] = array(
				'type'       => 'parallel_processing',
				'step'       => $stepId,
				'reason'     => "High execution time (avg: {$data['average_duration']}s)",
				'suggestion' => 'Consider parallel processing or caching',
			);
		}

		$failurePatterns = self::findFailurePatterns( $executions );
		foreach ( $failurePatterns as $stepId => $errors ) {
			arsort( $errors );
			$topError      = key( $errors );
			$suggestions[] = array(
				'type'       => 'error_handling',
				'step'       => $stepId,
				'reason'     => "Frequent error: $topError",
				'suggestion' => 'Add better error handling or validation',
			);
		}

		return $suggestions;
	}

	/**
	 * Analyze AI agent performance metrics.
	 */
	private static function analyzeAIPerformance( array $executions ): array {
		$aiMetrics = array(
			'context_provision_rate'      => 0,
			'average_comprehension_score' => 0,
			'suggestion_acceptance_rate'  => 0,
			'ai_interaction_count'        => 0,
		);

		$totalExecutions          = count( $executions );
		$aiInteractions           = 0;
		$totalComprehensionScore  = 0;
		$totalSuggestionsAccepted = 0;
		$contextProvided          = 0;

		foreach ( $executions as $execution ) {
			$metrics = $execution['metrics'];
			if ( isset( $metrics['ai_context_provided'] ) && $metrics['ai_context_provided'] ) {
				++$contextProvided;
			}
			if ( isset( $metrics['code_comprehension_score'] ) && $metrics['code_comprehension_score'] > 0 ) {
				++$aiInteractions;
				$totalComprehensionScore  += $metrics['code_comprehension_score'];
				$totalSuggestionsAccepted += $metrics['ai_suggestions_accepted'] ?? 0;
			}
		}

		if ( $totalExecutions > 0 ) {
			$aiMetrics['context_provision_rate'] = $contextProvided / $totalExecutions;
		}
		if ( $aiInteractions > 0 ) {
			$aiMetrics['average_comprehension_score'] = $totalComprehensionScore / $aiInteractions;
			$aiMetrics['suggestion_acceptance_rate']  = $totalSuggestionsAccepted / $aiInteractions;
		}
		$aiMetrics['ai_interaction_count'] = $aiInteractions;

		return $aiMetrics;
	}

	/**
	 * Analyze code comprehension effectiveness.
	 */
	private static function analyzeCodeComprehension( array $executions ): array {
		$comprehensionMetrics = array(
			'average_score'      => 0,
			'improvement_trend'  => 0,
			'high_impact_steps'  => array(),
			'comprehension_gaps' => array(),
		);

		$scores     = array();
		$stepScores = array();

		foreach ( $executions as $execution ) {
			$score = $execution['metrics']['code_comprehension_score'] ?? 0;
			if ( $score > 0 ) {
				$scores[] = $score;
				$stepId   = $execution['step_id'];
				if ( ! isset( $stepScores[ $stepId ] ) ) {
					$stepScores[ $stepId ] = array();
				}
				$stepScores[ $stepId ][] = $score;
			}
		}

		if ( ! empty( $scores ) ) {
			$comprehensionMetrics['average_score'] = array_sum( $scores ) / count( $scores );

			// Calculate improvement trend (simple linear trend)
			if ( count( $scores ) > 1 ) {
				$firstHalf                                 = array_slice( $scores, 0, intval( count( $scores ) / 2 ) );
				$secondHalf                                = array_slice( $scores, intval( count( $scores ) / 2 ) );
				$firstAvg                                  = array_sum( $firstHalf ) / count( $firstHalf );
				$secondAvg                                 = array_sum( $secondHalf ) / count( $secondHalf );
				$comprehensionMetrics['improvement_trend'] = $secondAvg - $firstAvg;
			}

			// Identify high-impact steps
			foreach ( $stepScores as $stepId => $stepScoreArray ) {
				$avgScore = array_sum( $stepScoreArray ) / count( $stepScoreArray );
				if ( $avgScore > 0.8 ) {
					$comprehensionMetrics['high_impact_steps'][] = array(
						'step'          => $stepId,
						'average_score' => round( $avgScore, 2 ),
						'executions'    => count( $stepScoreArray ),
					);
				}
			}

			// Identify comprehension gaps
			foreach ( $stepScores as $stepId => $stepScoreArray ) {
				$avgScore = array_sum( $stepScoreArray ) / count( $stepScoreArray );
				if ( $avgScore < 0.5 ) {
					$comprehensionMetrics['comprehension_gaps'][] = array(
						'step'              => $stepId,
						'average_score'     => round( $avgScore, 2 ),
						'needs_improvement' => true,
					);
				}
			}
		}

		return $comprehensionMetrics;
	}

	/**
	 * Suggest AI-specific optimizations.
	 */
	private static function suggestAIOptimizations( array $executions ): array {
		$suggestions           = array();
		$aiAnalysis            = self::analyzeAIPerformance( $executions );
		$comprehensionAnalysis = self::analyzeCodeComprehension( $executions );

		// Context provision suggestions
		if ( $aiAnalysis['context_provision_rate'] < 0.7 ) {
			$suggestions[] = array(
				'type'       => 'ai_context_improvement',
				'priority'   => 'high',
				'suggestion' => 'Increase AI context provision rate - currently ' .
					round( $aiAnalysis['context_provision_rate'] * 100, 1 ) . '%',
				'benefit'    => 'Better AI comprehension and more accurate suggestions',
			);
		}

		// Comprehension improvement suggestions
		if ( $comprehensionAnalysis['average_score'] < 0.7 ) {
			$suggestions[] = array(
				'type'       => 'code_clarity_improvement',
				'priority'   => 'high',
				'suggestion' => 'Improve code clarity for AI comprehension - average score: ' .
					round( $comprehensionAnalysis['average_score'], 2 ),
				'benefit'    => 'Enhanced AI-driven development and maintenance',
			);
		}

		// Learning trend analysis
		if ( $comprehensionAnalysis['improvement_trend'] < 0 ) {
			$suggestions[] = array(
				'type'       => 'ai_learning_optimization',
				'priority'   => 'medium',
				'suggestion' => 'AI comprehension is declining - investigate protocol changes',
				'benefit'    => 'Maintain AI effectiveness over time',
			);
		}

		// Step-specific optimizations
		foreach ( $comprehensionAnalysis['comprehension_gaps'] as $gap ) {
			$suggestions[] = array(
				'type'       => 'step_optimization',
				'priority'   => 'medium',
				'step'       => $gap['step'],
				'suggestion' => 'Improve AI comprehension for step: ' . $gap['step'],
				'benefit'    => 'Better AI assistance for specific protocol steps',
			);
		}

		return $suggestions;
	}

	/**
	 * Generate variations of the protocol.
	 */
	private static function generateProtocolVariations( array $currentProtocol, array $analysis ): array {
		$variations = array();

		for ( $i = 0; $i < self::MAX_VARIATIONS; $i++ ) {
			$variation    = self::mutateProtocol( $currentProtocol, $analysis );
			$variations[] = $variation;
		}

		return $variations;
	}

	/**
	 * Mutate a protocol based on analysis.
	 */
	private static function mutateProtocol( array $protocol, array $analysis ): array {
		$mutated = $protocol;

		// Apply random mutations based on MUTATION_RATE
		if ( mt_rand( 0, 100 ) / 100 < self::MUTATION_RATE ) {
			$mutationType = mt_rand( 0, 3 );

			switch ( $mutationType ) {
				case 0: // Reorder steps
					shuffle( $mutated );

					break;
				case 1: // Add parallel execution hint
					$randomIndex              = array_rand( $mutated );
					$mutated[ $randomIndex ] .= ' (consider parallel execution)';

					break;
				case 2: // Add automation suggestion
					$randomIndex              = array_rand( $mutated );
					$mutated[ $randomIndex ] .= ' (automate if possible)';

					break;
				case 3: // Add monitoring
					$randomIndex              = array_rand( $mutated );
					$mutated[ $randomIndex ] .= ' (add metrics collection)';

					break;
			}
		}

		// Apply analysis-based improvements
		if ( ! empty( $analysis['bottlenecks'] ) ) {
			$bottleneckSteps = array_keys( $analysis['bottlenecks'] );
			foreach ( $bottleneckSteps as $step ) {
				if ( in_array( $step, $mutated ) ) {
					$index              = array_search( $step, $mutated );
					$mutated[ $index ] .= ' [OPTIMIZE: High execution time]';
				}
			}
		}

		return $mutated;
	}

	/**
	 * Calculate fitness score for a protocol variation.
	 */
	private static function calculateFitnessScore( array $protocol, array $executions ): float {
		// Simulate execution with this protocol
		$simulatedMetrics = self::simulateProtocolExecution( $protocol, $executions );

		$score = 0;
		foreach ( self::FITNESS_WEIGHTS as $metric => $weight ) {
			$value  = $simulatedMetrics[ $metric ] ?? 0;
			$score += $value * $weight;
		}

		return $score;
	}

	/**
	 * Simulate protocol execution to estimate metrics.
	 */
	private static function simulateProtocolExecution( array $protocol, array $executions ): array {
		$stepMetrics = array();
		foreach ( $executions as $execution ) {
			$stepId = $execution['step_id'];
			if ( ! isset( $stepMetrics[ $stepId ] ) ) {
				$stepMetrics[ $stepId ] = array();
			}
			$stepMetrics[ $stepId ][] = $execution['metrics'];
		}

		$totalTime    = 0;
		$successCount = 0;
		$totalCount   = 0;
		$errorCount   = 0;

		foreach ( $protocol as $step ) {
			// Extract step ID from step description
			$stepId  = self::extractStepId( $step );
			$metrics = $stepMetrics[ $stepId ] ?? array();

			if ( ! empty( $metrics ) ) {
				$avgDuration = array_sum( array_column( $metrics, 'duration' ) ) / count( $metrics );
				$avgSuccess  = array_sum( array_column( $metrics, 'success' ) ) / count( $metrics );
				$totalErrors = count( array_filter( $metrics, fn ( $m ) => ! empty( $m['data']['error'] ) ) );

				$totalTime    += $avgDuration;
				$successCount += $avgSuccess;
				$totalCount   += 1;
				$errorCount   += $totalErrors / count( $metrics );
			}
		}

		return array(
			'execution_time'   => $totalTime,
			'success_rate'     => $totalCount > 0 ? $successCount / $totalCount : 0,
			'error_reduction'  => max( 0, 1 - ( $errorCount / max( 1, $totalCount ) ) ),
			'maintainability'  => self::calculateMaintainabilityScore( $protocol ),
			'ai_comprehension' => self::calculateAIComprehensionScore( $protocol, $executions ),
			'code_quality'     => self::calculateCodeQualityScore( $protocol ),
		);
	}

	/**
	 * Calculate maintainability score based on protocol structure.
	 */
	private static function calculateMaintainabilityScore( array $protocol ): float {
		$score = 1.0;

		// Penalize very long protocols
		if ( count( $protocol ) > 20 ) {
			$score -= 0.1;
		}

		// Reward clear, descriptive steps
		foreach ( $protocol as $step ) {
			if ( strlen( $step ) > 50 ) {
				$score += 0.05; // Detailed steps are better
			}
			if ( strpos( $step, '[OPTIMIZE]' ) !== false ) {
				$score += 0.1; // Optimization hints are good
			}
		}

		return max( 0, min( 1, $score ) );
	}

	/**
	 * Calculate AI comprehension score based on protocol structure.
	 */
	private static function calculateAIComprehensionScore( array $protocol, array $executions ): float {
		$score = 0.5; // Base score

		// Reward protocols with clear, descriptive steps (easier for AI to understand)
		foreach ( $protocol as $step ) {
			$stepLength = strlen( $step );
			if ( $stepLength > 20 && $stepLength < 100 ) {
				$score += 0.05; // Good length for comprehension
			}
			if ( strpos( $step, 'AI-' ) !== false ) {
				$score += 0.1; // AI-specific steps
			}
			if ( strpos( $step, 'FAST TRACK' ) !== false ) {
				$score += 0.05; // Fast track indicators help AI prioritize
			}
		}

		// Analyze execution data for AI interaction patterns
		$aiInteractionScore = 0;
		$interactionCount   = 0;
		foreach ( $executions as $execution ) {
			if ( isset( $execution['metrics']['code_comprehension_score'] ) ) {
				$aiInteractionScore += $execution['metrics']['code_comprehension_score'];
				++$interactionCount;
			}
		}
		if ( $interactionCount > 0 ) {
			$score = ( $score + ( $aiInteractionScore / $interactionCount ) ) / 2;
		}

		return max( 0, min( 1, $score ) );
	}

	/**
	 * Calculate code quality score based on protocol structure.
	 */
	private static function calculateCodeQualityScore( array $protocol ): float {
		$score = 0.5; // Base score

		// Reward well-structured protocols
		$phaseCount = 0;
		foreach ( $protocol as $step ) {
			if ( strpos( $step, 'Phase ' ) !== false ) {
				++$phaseCount;
			}
		}
		if ( $phaseCount > 0 ) {
			$score += min( 0.2, $phaseCount * 0.05 ); // Up to 0.2 for good phase organization
		}

		// Penalize overly complex protocols
		if ( count( $protocol ) > 25 ) {
			$score -= 0.1;
		}

		// Reward clear categorization
		$categoryKeywords = array( 'FAST TRACK', 'AI-FOCUSED', 'AI-DRIVEN', 'AI-VALIDATED' );
		$categoryMatches  = 0;
		foreach ( $protocol as $step ) {
			foreach ( $categoryKeywords as $keyword ) {
				if ( strpos( $step, $keyword ) !== false ) {
					++$categoryMatches;

					break;
				}
			}
		}
		$score += min( 0.2, $categoryMatches * 0.02 ); // Up to 0.2 for good categorization

		return max( 0, min( 1, $score ) );
	}

	/**
	 * Extract step ID from step description.
	 */
	private static function extractStepId( string $step ): string {
		// Convert step description to ID
		$id = strtolower( str_replace( array( ' ', '-', '.', '[', ']', '(', ')' ), '_', $step ) );
		$id = preg_replace( '/_+/', '_', $id );
		$id = trim( $id, '_' );

		// Map common variations
		$mapping = array(
			'redownload_current_version_of_debug_log_from_the_server' => 'download_debug_log',
			'read_debug_log'                => 'read_debug_log',
			'read_console_txt'              => 'read_console_log',
			'identify_problems'             => 'identify_problems',
			'debug_issues'                  => 'debug_issues',
			'analyze_code_base'             => 'analyze_codebase',
			'fix_errors'                    => 'fix_errors',
			'optimize_and_enhance_features' => 'optimize_features',
			'commit'                        => 'commit_changes',
			'output_summary'                => 'output_summary',
			'ask_to_push'                   => 'ask_to_push',
		);

		return $mapping[ $id ] ?? $id;
	}

	/**
	 * Get current protocol as array.
	 */
	private static function getCurrentProtocol(): array {
		$protocolFile = __DIR__ . '/../../protocol.md';
		if ( ! file_exists( $protocolFile ) ) {
			return array();
		}

		$content = file_get_contents( $protocolFile );
		$lines   = explode( "\n", $content );

		$protocol = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( strpos( $line, '- ' ) === 0 ) {
				$protocol[] = substr( $line, 2 );
			}
		}

		return $protocol;
	}

	/**
	 * Get current protocol version (timestamp-based).
	 */
	private static function getCurrentProtocolVersion(): string {
		$protocolFile = __DIR__ . '/../../protocol.md';

		return file_exists( $protocolFile ) ? filemtime( $protocolFile ) : 'unknown';
	}

	/**
	 * Format protocol array for file writing.
	 */
	private static function formatProtocolForFile( array $protocol ): string {
		$content = "# Maintenance Protocol\n\n";
		foreach ( $protocol as $step ) {
			$content .= "- $step\n";
		}
		$content .= "\n<!-- Generated by Protocol Evolution Engine on " . date( 'Y-m-d H:i:s' ) . " -->\n";

		return $content;
	}

	/**
	 * Get improvements made by a variation.
	 */
	private static function getVariationImprovements( array $variation, array $current ): array {
		$improvements   = array();
		$currentSteps   = array_map( 'strtolower', $current );
		$variationSteps = array_map( 'strtolower', $variation );

		// Check for new optimization hints
		foreach ( $variation as $i => $step ) {
			if ( isset( $current[ $i ] ) ) {
				$currentStep   = strtolower( $current[ $i ] );
				$variationStep = strtolower( $step );

				if ( strpos( $variationStep, '[optimize' ) !== false && strpos( $currentStep, '[optimize' ) === false ) {
					$improvements[] = 'Added optimization hint to: ' . $current[ $i ];
				}
				if ( strpos( $variationStep, 'parallel' ) !== false && strpos( $currentStep, 'parallel' ) === false ) {
					$improvements[] = 'Added parallel processing suggestion to: ' . $current[ $i ];
				}
				if ( strpos( $variationStep, 'automate' ) !== false && strpos( $currentStep, 'automate' ) === false ) {
					$improvements[] = 'Added automation suggestion to: ' . $current[ $i ];
				}
			}
		}

		return $improvements;
	}

	/**
	 * Load evolution data from file.
	 */
	private static function loadEvolutionData(): array {
		if ( ! file_exists( self::EVOLUTION_DATA_FILE ) ) {
			return array(
				'executions'         => array(),
				'applied_variations' => array(),
			);
		}

		$data = json_decode( file_get_contents( self::EVOLUTION_DATA_FILE ), true );

		return $data ?: array(
			'executions'         => array(),
			'applied_variations' => array(),
		);
	}

	/**
	 * Save evolution data to file.
	 */
	private static function saveEvolutionData( array $data ): void {
		$json = json_encode( $data, JSON_PRETTY_PRINT );
		file_put_contents( self::EVOLUTION_DATA_FILE, $json );
	}

	/**
	 * Run the evolution cycle.
	 */
	public static function runEvolutionCycle(): array {
		$startTime = microtime( true );

		// Analyze current performance
		$analysis = self::analyzeAndSuggestImprovements();

		// Generate and test variations
		$currentProtocol = self::getCurrentProtocol();
		$variations      = self::generateProtocolVariations( $currentProtocol, $analysis );

		// Score and select best
		$scoredVariations = array();
		foreach ( $variations as $variation ) {
			$score              = self::calculateFitnessScore( $variation, self::loadEvolutionData()['executions'] );
			$scoredVariations[] = array(
				'variation' => $variation,
				'score'     => $score,
			);
		}

		usort( $scoredVariations, fn ( $a, $b ) => $b['score'] <=> $a['score'] );
		$bestVariation = $scoredVariations[0] ?? null;

		$result = array(
			'analysis'              => $analysis,
			'best_variation'        => $bestVariation,
			'evolution_time'        => microtime( true ) - $startTime,
			'improvement_potential' => $bestVariation ? $bestVariation['score'] - ( $analysis['current_protocol_score'] ?? 0 ) : 0,
		);

		return $result;
	}
}
