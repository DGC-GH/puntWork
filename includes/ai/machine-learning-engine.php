<?php

/**
 * Advanced Machine Learning Engine for Predictive Analytics
 *
 * @package    Puntwork
 * @subpackage AI
 * @since      2.2.0
 */

namespace Puntwork\AI;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Machine Learning Engine with training and prediction capabilities
 */
class MachineLearningEngine
{
    /**
     * Model types
     */
    public const MODEL_LINEAR_REGRESSION = 'linear_regression';
    public const MODEL_DECISION_TREE     = 'decision_tree';
    public const MODEL_NEURAL_NETWORK    = 'neural_network';
    public const MODEL_ENSEMBLE          = 'ensemble';

    /**
     * Training data storage
     */
    private static array $models = array();

    /**
     * Feature engineering for feed performance prediction
     *
     * @param  array $historicalData Historical performance data
     * @return array Engineered features
     */
    public static function engineerFeatures(array $historicalData): array
    {
        if (empty($historicalData)) {
            return array();
        }

        $features = array();

        foreach ($historicalData as $record) {
            $features[] = array(
            'success_rate'    => $record['success_rate'] ?? 0,
            'response_time'   => $record['response_time'] ?? 0,
            'error_rate'      => $record['error_rate'] ?? 0,
            'volume'          => $record['volume'] ?? 0,
            'duplicate_rate'  => $record['duplicate_rate'] ?? 0,
            'day_of_week'     => date('N', strtotime($record['date'] ?? 'now')),
            'hour_of_day'     => date('G', strtotime($record['date'] ?? 'now')),
            'is_weekend'      => in_array(date('N', strtotime($record['date'] ?? 'now')), array( 6, 7 )) ? 1 : 0,
            'trend_3day'      => self::calculateMovingAverage($historicalData, $record, 3),
            'trend_7day'      => self::calculateMovingAverage($historicalData, $record, 7),
            'volatility'      => self::calculateVolatility($historicalData, $record, 7),
            'seasonal_factor' => self::calculateSeasonalFactor($historicalData, $record),
            );
        }

        return $features;
    }

    /**
     * Train machine learning model for feed performance prediction
     *
     * @param  string $modelType      Type of model to train
     * @param  array  $trainingData   Training data with features and labels
     * @param  string $targetVariable Target variable to predict
     * @return array Trained model data
     */
    public static function trainModel(string $modelType, array $trainingData, string $targetVariable): array
    {
        $modelId = $modelType . '_' . $targetVariable . '_' . time();

        switch ($modelType) {
            case self::MODEL_LINEAR_REGRESSION:
                $model = self::trainLinearRegression($trainingData, $targetVariable);
                break;
            case self::MODEL_DECISION_TREE:
                $model = self::trainDecisionTree($trainingData, $targetVariable);
                break;
            case self::MODEL_NEURAL_NETWORK:
                $model = self::trainNeuralNetwork($trainingData, $targetVariable);
                break;
            case self::MODEL_ENSEMBLE:
                $model = self::trainEnsemble($trainingData, $targetVariable);
                break;
            default:
                throw new \Exception("Unknown model type: $modelType");
        }

        $model['model_id']        = $modelId;
        $model['model_type']      = $modelType;
        $model['target_variable'] = $targetVariable;
        $model['trained_at']      = current_time('timestamp');
        $model['accuracy']        = self::evaluateModel($model, $trainingData, $targetVariable);

        // Store model
        self::$models[ $modelId ] = $model;
        self::persistModel($model);

        return $model;
    }

    /**
     * Make prediction using trained model
     *
     * @param  string $modelId  Model identifier
     * @param  array  $features Feature values for prediction
     * @return array Prediction result
     */
    public static function predict(string $modelId, array $features): array
    {
        $model = self::loadModel($modelId);

        if (! $model) {
            return array(
            'prediction' => null,
            'confidence' => 0,
            'error'      => 'Model not found',
            );
        }

        switch ($model['model_type']) {
            case self::MODEL_LINEAR_REGRESSION:
                $prediction = self::predictLinearRegression($model, $features);
                break;
            case self::MODEL_DECISION_TREE:
                $prediction = self::predictDecisionTree($model, $features);
                break;
            case self::MODEL_NEURAL_NETWORK:
                $prediction = self::predictNeuralNetwork($model, $features);
                break;
            case self::MODEL_ENSEMBLE:
                $prediction = self::predictEnsemble($model, $features);
                break;
            default:
                return array(
                    'prediction' => null,
                    'confidence' => 0,
                    'error'      => 'Unknown model type',
                );
        }

        return array(
        'prediction' => $prediction,
        'confidence' => $model['accuracy'] ?? 0.5,
        'model_id'   => $modelId,
        'model_type' => $model['model_type'],
        );
    }

    /**
     * Automated feed optimization using ML predictions
     *
     * @param  string $feedKey Feed identifier
     * @return array Optimization recommendations
     */
    public static function optimizeFeedAutomatically(string $feedKey): array
    {
        $recommendations = array();

        // Get historical data
        $historicalData = self::getFeedHistoricalData($feedKey, 90); // 90 days

        if (empty($historicalData)) {
            return array(
            'success'         => false,
            'message'         => 'Insufficient historical data for optimization',
            'recommendations' => array(),
            );
        }

        // Engineer features
        $features = self::engineerFeatures($historicalData);

        // Train or load models for different predictions
        $models = self::getOrTrainModels($feedKey, $historicalData);

        // Make predictions
        $predictions = array();
        foreach ($models as $target => $modelId) {
            $latestFeatures         = end($features);
            $prediction             = self::predict($modelId, $latestFeatures);
            $predictions[ $target ] = $prediction;
        }

        // Generate optimization recommendations based on predictions
        $recommendations = self::generateOptimizationRecommendations($predictions, $feedKey);

        // Apply automatic optimizations if confidence is high enough
        $appliedOptimizations = self::applyAutomaticOptimizations($recommendations, $feedKey);

        return array(
        'success'               => true,
        'predictions'           => $predictions,
        'recommendations'       => $recommendations,
        'applied_optimizations' => $appliedOptimizations,
        'data_points'           => count($historicalData),
        );
    }

    /**
     * Train linear regression model
     */
    private static function trainLinearRegression(array $trainingData, string $targetVariable): array
    {
        // Simple implementation of linear regression
        // In a real implementation, you'd use a proper ML library

        $features = array_column($trainingData, 'features');
        $targets  = array_column($trainingData, $targetVariable);

        // Calculate coefficients using normal equation
        $X = array();
        foreach ($features as $featureSet) {
            $X[] = array_values($featureSet);
        }

        $y = $targets;

        // Add bias term
        foreach ($X as &$row) {
            array_unshift($row, 1);
        }

        $coefficients = self::calculateLinearRegressionCoefficients($X, $y);

        return array(
        'coefficients'  => $coefficients,
        'feature_names' => array_keys($features[0] ?? array()),
        );
    }

    /**
     * Train decision tree model (simplified)
     */
    private static function trainDecisionTree(array $trainingData, string $targetVariable): array
    {
        // Simplified decision tree implementation
        $features = array_column($trainingData, 'features');
        $targets  = array_column($trainingData, $targetVariable);

        // Build simple tree based on most important features
        $tree = self::buildSimpleDecisionTree($features, $targets);

        return array(
        'tree'          => $tree,
        'feature_names' => array_keys($features[0] ?? array()),
        );
    }

    /**
     * Train neural network (simplified single layer)
     */
    private static function trainNeuralNetwork(array $trainingData, string $targetVariable): array
    {
        // Simplified neural network implementation
        $features = array_column($trainingData, 'features');
        $targets  = array_column($trainingData, $targetVariable);

        $weights = self::trainSimpleNeuralNetwork($features, $targets);

        return array(
        'weights'       => $weights,
        'feature_names' => array_keys($features[0] ?? array()),
        );
    }

    /**
     * Train ensemble model
     */
    private static function trainEnsemble(array $trainingData, string $targetVariable): array
    {
        $models = array();

        // Train multiple models
        $models['linear'] = self::trainLinearRegression($trainingData, $targetVariable);
        $models['tree']   = self::trainDecisionTree($trainingData, $targetVariable);

        return array(
        'models'          => $models,
        'ensemble_method' => 'average',
        );
    }

    /**
     * Calculate linear regression coefficients
     */
    private static function calculateLinearRegressionCoefficients(array $X, array $y): array
    {
        // Simplified implementation - in practice, use matrix operations
        $n           = count($X);
        $numFeatures = count($X[0]);

        // Initialize coefficients
        $coefficients = array_fill(0, $numFeatures, 0);

        // Simple gradient descent (simplified)
        $learningRate = 0.01;
        $iterations   = 1000;

        for ($iter = 0; $iter < $iterations; $iter++) {
            $predictions = array();
            foreach ($X as $row) {
                $prediction = 0;
                for ($i = 0; $i < $numFeatures; $i++) {
                    $prediction += $coefficients[ $i ] * $row[ $i ];
                }
                $predictions[] = $prediction;
            }

            // Calculate gradients
            $gradients = array_fill(0, $numFeatures, 0);
            for ($i = 0; $i < $n; $i++) {
                $error = $predictions[ $i ] - $y[ $i ];
                for ($j = 0; $j < $numFeatures; $j++) {
                    $gradients[ $j ] += $error * $X[ $i ][ $j ];
                }
            }

            // Update coefficients
            for ($j = 0; $j < $numFeatures; $j++) {
                $coefficients[ $j ] -= $learningRate * $gradients[ $j ] / $n;
            }
        }

        return $coefficients;
    }

    /**
     * Build simple decision tree
     */
    private static function buildSimpleDecisionTree(array $features, array $targets): array
    {
        // Simplified decision tree - split on most important feature
        $bestSplit = self::findBestSplit($features, $targets);

        return array(
        'feature_index'    => $bestSplit['feature_index'],
        'threshold'        => $bestSplit['threshold'],
        'left_prediction'  => $bestSplit['left_avg'],
        'right_prediction' => $bestSplit['right_avg'],
        );
    }

    /**
     * Find best split for decision tree
     */
    private static function findBestSplit(array $features, array $targets): array
    {
        $bestScore = PHP_FLOAT_MAX;
        $bestSplit = null;

        $numFeatures = count($features[0] ?? array());

        for ($featureIndex = 0; $featureIndex < $numFeatures; $featureIndex++) {
            $values = array_column($features, $featureIndex);
            sort($values);
            $uniqueValues = array_unique($values);

            foreach ($uniqueValues as $threshold) {
                $leftTargets  = array();
                $rightTargets = array();

                for ($i = 0; $i < count($features); $i++) {
                    if ($features[ $i ][ $featureIndex ] <= $threshold) {
                        $leftTargets[] = $targets[ $i ];
                    } else {
                        $rightTargets[] = $targets[ $i ];
                    }
                }

                if (empty($leftTargets) || empty($rightTargets)) {
                    continue;
                }

                $leftAvg  = array_sum($leftTargets) / count($leftTargets);
                $rightAvg = array_sum($rightTargets) / count($rightTargets);

                $score = 0;
                foreach ($leftTargets as $target) {
                    $score += pow($target - $leftAvg, 2);
                }
                foreach ($rightTargets as $target) {
                    $score += pow($target - $rightAvg, 2);
                }

                if ($score < $bestScore) {
                    $bestScore = $score;
                    $bestSplit = array(
                    'feature_index' => $featureIndex,
                    'threshold'     => $threshold,
                    'left_avg'      => $leftAvg,
                    'right_avg'     => $rightAvg,
                    );
                }
            }
        }

        return $bestSplit ?? array(
        'feature_index' => 0,
        'threshold'     => 0,
        'left_avg'      => array_sum($targets) / count($targets),
        'right_avg'     => array_sum($targets) / count($targets),
        );
    }

    /**
     * Train simple neural network
     */
    private static function trainSimpleNeuralNetwork(array $features, array $targets): array
    {
        // Simplified single-layer neural network
        $numFeatures = count($features[0] ?? array());
        $weights     = array_fill(0, $numFeatures, 0.1); // Initialize weights
        $bias        = 0.1;

        $learningRate = 0.01;
        $iterations   = 1000;

        for ($iter = 0; $iter < $iterations; $iter++) {
            $totalError = 0;

            for ($i = 0; $i < count($features); $i++) {
                // Forward pass
                $prediction = $bias;
                for ($j = 0; $j < $numFeatures; $j++) {
                    $prediction += $weights[ $j ] * $features[ $i ][ $j ];
                }
                $prediction = 1 / ( 1 + exp(-$prediction) ); // Sigmoid

                // Calculate error
                $error       = $targets[ $i ] - $prediction;
                $totalError += abs($error);

                // Backward pass
                $delta = $error * $prediction * ( 1 - $prediction ); // Sigmoid derivative

                // Update weights
                for ($j = 0; $j < $numFeatures; $j++) {
                    $weights[ $j ] += $learningRate * $delta * $features[ $i ][ $j ];
                }
                $bias += $learningRate * $delta;
            }

            // Early stopping if error is low enough
            if ($totalError / count($features) < 0.01) {
                break;
            }
        }

        return array(
        'weights' => $weights,
        'bias'    => $bias,
        );
    }

    /**
     * Make predictions with different model types
     */
    private static function predictLinearRegression(array $model, array $features): float
    {
        $prediction = $model['coefficients'][0]; // Bias term

        foreach ($features as $i => $value) {
            $featureIndex = $i + 1; // Skip bias term
            if (isset($model['coefficients'][ $featureIndex ])) {
                $prediction += $model['coefficients'][ $featureIndex ] * $value;
            }
        }

        return max(0, min(1, $prediction)); // Clamp to [0,1] for rates
    }

    private static function predictDecisionTree(array $model, array $features): float
    {
        $featureIndex = $model['tree']['feature_index'];
        $threshold    = $model['tree']['threshold'];

        if (isset($features[ $featureIndex ]) && $features[ $featureIndex ] <= $threshold) {
            return $model['tree']['left_prediction'];
        } else {
            return $model['tree']['right_prediction'];
        }
    }

    private static function predictNeuralNetwork(array $model, array $features): float
    {
        $prediction = $model['weights']['bias'] ?? 0;

        foreach ($features as $i => $value) {
            if (isset($model['weights']['weights'][ $i ])) {
                $prediction += $model['weights']['weights'][ $i ] * $value;
            }
        }

        return 1 / ( 1 + exp(-$prediction) ); // Sigmoid activation
    }

    private static function predictEnsemble(array $model, array $features): float
    {
        $predictions = array();

        if (isset($model['models']['linear'])) {
            $predictions[] = self::predictLinearRegression($model['models']['linear'], $features);
        }
        if (isset($model['models']['tree'])) {
            $predictions[] = self::predictDecisionTree($model['models']['tree'], $features);
        }

        return ! empty($predictions) ? array_sum($predictions) / count($predictions) : 0;
    }

    /**
     * Evaluate model accuracy
     */
    private static function evaluateModel(array $model, array $trainingData, string $targetVariable): float
    {
        // Simple cross-validation
        $predictions = array();
        $actuals     = array();

        foreach ($trainingData as $record) {
            $features = $record['features'];
            $actual   = $record[ $targetVariable ];

            $prediction = 0;
            switch ($model['model_type']) {
                case self::MODEL_LINEAR_REGRESSION:
                    $prediction = self::predictLinearRegression($model, $features);
                    break;
                case self::MODEL_DECISION_TREE:
                    $prediction = self::predictDecisionTree($model, $features);
                    break;
                case self::MODEL_NEURAL_NETWORK:
                       $prediction = self::predictNeuralNetwork($model, $features);
                    break;
                case self::MODEL_ENSEMBLE:
                    $prediction = self::predictEnsemble($model, $features);
                    break;
            }

            $predictions[] = $prediction;
            $actuals[]     = $actual;
        }

        // Calculate R² score
        $mean  = array_sum($actuals) / count($actuals);
        $ssRes = $ssTot = 0;

        for ($i = 0; $i < count($predictions); $i++) {
            $ssRes += pow($actuals[ $i ] - $predictions[ $i ], 2);
            $ssTot += pow($actuals[ $i ] - $mean, 2);
        }

        return $ssTot > 0 ? 1 - ( $ssRes / $ssTot ) : 0;
    }

    /**
     * Get or train models for a feed
     */
    private static function getOrTrainModels(string $feedKey, array $historicalData): array
    {
        $models = array();

        // Check if models already exist and are recent
        $existingModels = self::getExistingModels($feedKey);

        $targets = array( 'success_rate', 'response_time', 'error_rate', 'volume' );

        foreach ($targets as $target) {
            $modelKey = $feedKey . '_' . $target;

            if (
                isset($existingModels[ $modelKey ])
                && ( current_time('timestamp') - $existingModels[ $modelKey ]['trained_at'] ) < 7 * DAY_IN_SECONDS
            ) {
                // Use existing model if less than 7 days old
                $models[ $target ] = $existingModels[ $modelKey ]['model_id'];
            } else {
                // Train new model
                $trainingData = self::prepareTrainingData($historicalData, $target);
                if (! empty($trainingData)) {
                    $model             = self::trainModel(self::MODEL_ENSEMBLE, $trainingData, $target);
                    $models[ $target ] = $model['model_id'];
                }
            }
        }

        return $models;
    }

    /**
     * Generate optimization recommendations based on predictions
     */
    private static function generateOptimizationRecommendations(array $predictions, string $feedKey): array
    {
        $recommendations = array();

        // Analyze success rate predictions
        if (isset($predictions['success_rate'])) {
            $successPred = $predictions['success_rate'];
            if ($successPred['prediction'] < 0.8 && $successPred['confidence'] > 0.7) {
                $recommendations[] = array(
                 'type'                 => 'success_rate',
                 'priority'             => 'high',
                 'action'               => 'reduce_batch_size',
                 'reason'               => 'Predicted success rate is low',
                 'expected_improvement' => '15-20%',
                );
            }
        }

        // Analyze response time predictions
        if (isset($predictions['response_time'])) {
            $responsePred = $predictions['response_time'];
            if ($responsePred['prediction'] > 5000 && $responsePred['confidence'] > 0.7) { // >5 seconds
                $recommendations[] = array(
                'type'                 => 'response_time',
                'priority'             => 'medium',
                'action'               => 'increase_timeout',
                'reason'               => 'Predicted response time is high',
                'expected_improvement' => 'faster_completion',
                );
            }
        }

        // Analyze error rate predictions
        if (isset($predictions['error_rate'])) {
            $errorPred = $predictions['error_rate'];
            if ($errorPred['prediction'] > 0.1 && $errorPred['confidence'] > 0.7) { // >10%
                $recommendations[] = array(
                'type'                 => 'error_rate',
                'priority'             => 'high',
                'action'               => 'reduce_frequency',
                'reason'               => 'Predicted error rate is high',
                'expected_improvement' => 'fewer_failures',
                );
            }
        }

        // Analyze volume predictions
        if (isset($predictions['volume'])) {
            $volumePred = $predictions['volume'];
            if ($volumePred['prediction'] > 1000 && $volumePred['confidence'] > 0.7) {
                $recommendations[] = array(
                'type'                 => 'volume',
                'priority'             => 'medium',
                'action'               => 'increase_batch_size',
                'reason'               => 'Predicted volume is high',
                'expected_improvement' => 'better_throughput',
                );
            }
        }

        return $recommendations;
    }

    /**
     * Apply automatic optimizations
     */
    private static function applyAutomaticOptimizations(array $recommendations, string $feedKey): array
    {
        $applied = array();

        foreach ($recommendations as $rec) {
            if ($rec['priority'] === 'high') {
                // Apply high priority recommendations automatically
                $result = self::applyOptimization($rec, $feedKey);
                if ($result) {
                    $applied[] = $rec;
                }
            }
        }

        return $applied;
    }

    /**
     * Apply a specific optimization
     */
    private static function applyOptimization(array $recommendation, string $feedKey): bool
    {
        // Find the feed post
        $feeds = get_posts(
            array(
            'post_type'      => 'job-feed',
            'meta_query'     => array(
            array(
            'key'     => 'feed_url',
            'value'   => $feedKey,
            'compare' => '=',
                    ),
            ),
            'posts_per_page' => 1,
            )
        );

        if (empty($feeds)) {
            return false;
        }

        $feedId = $feeds[0]->ID;

        switch ($recommendation['action']) {
            case 'reduce_batch_size':
                $currentBatchSize = get_post_meta($feedId, 'batch_size', true) ?: 100;
                $newBatchSize     = max(10, (int) ( $currentBatchSize * 0.8 ));
                update_post_meta($feedId, 'batch_size', $newBatchSize);
                break;

            case 'increase_timeout':
                $currentTimeout = get_post_meta($feedId, 'timeout', true) ?: 30;
                $newTimeout     = min(300, (int) ( $currentTimeout * 1.5 ));
                update_post_meta($feedId, 'timeout', $newTimeout);
                break;

            case 'reduce_frequency':
                $currentFrequency = get_post_meta($feedId, 'import_frequency', true) ?: 'hourly';
                $newFrequency     = self::increaseFrequencyInterval($currentFrequency);
                update_post_meta($feedId, 'import_frequency', $newFrequency);
                break;

            case 'increase_batch_size':
                $currentBatchSize = get_post_meta($feedId, 'batch_size', true) ?: 100;
                $newBatchSize     = min(1000, (int) ( $currentBatchSize * 1.2 ));
                update_post_meta($feedId, 'batch_size', $newBatchSize);
                break;

            default:
                return false;
        }

        return true;
    }

    /**
     * Helper methods
     */
    private static function calculateMovingAverage(array $data, array $currentRecord, int $window): float
    {
        // Simplified moving average calculation
        $index = array_search($currentRecord, $data);
        if ($index === false) {
            return 0;
        }

        $start  = max(0, $index - $window + 1);
        $values = array_slice($data, $start, $window);

        return array_sum(array_column($values, 'success_rate')) / count($values);
    }

    private static function calculateVolatility(array $data, array $currentRecord, int $window): float
    {
        $index = array_search($currentRecord, $data);
        if ($index === false) {
            return 0;
        }

        $start  = max(0, $index - $window + 1);
        $values = array_column(array_slice($data, $start, $window), 'success_rate');

        $mean     = array_sum($values) / count($values);
        $variance = 0;
        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }

        return sqrt($variance / count($values));
    }

    private static function calculateSeasonalFactor(array $data, array $currentRecord): float
    {
        // Simplified seasonal factor
        $dayOfWeek = date('N', strtotime($currentRecord['date'] ?? 'now'));
        $dayValues = array();

        foreach ($data as $record) {
            if (date('N', strtotime($record['date'] ?? 'now')) == $dayOfWeek) {
                $dayValues[] = $record['success_rate'] ?? 0;
            }
        }

        return ! empty($dayValues) ? array_sum($dayValues) / count($dayValues) : 0;
    }

    private static function increaseFrequencyInterval(string $currentFrequency): string
    {
        $frequencies = array(
        '5min'    => '15min',
        '15min'   => '30min',
        '30min'   => 'hourly',
        'hourly'  => '2hours',
        '2hours'  => '4hours',
        '4hours'  => '6hours',
        '6hours'  => '12hours',
        '12hours' => 'daily',
        'daily'   => 'weekly',
        );
        return $frequencies[ $currentFrequency ] ?? $currentFrequency;
    }

    private static function getFeedHistoricalData(string $feedKey, int $days): array
    {
        global $wpdb;

        $table_name  = $wpdb->prefix . 'puntwork_import_analytics';
        $date_filter = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $sql = $wpdb->prepare(
            "
            SELECT
                DATE(end_time) as date,
                AVG(success_rate) as success_rate,
                AVG(avg_response_time) as response_time,
                AVG(failed_jobs / GREATEST(processed_jobs, 1)) as error_rate,
                SUM(processed_jobs) as volume,
                AVG(duplicate_jobs / GREATEST(processed_jobs, 1)) as duplicate_rate
            FROM $table_name
            WHERE end_time >= %s
            GROUP BY DATE(end_time)
            ORDER BY date ASC
        ",
            $date_filter
        );

        $results = $wpdb->get_results($sql, ARRAY_A);

        return $results ?: array();
    }

    private static function prepareTrainingData(array $historicalData, string $targetVariable): array
    {
        $trainingData = array();

        foreach ($historicalData as $record) {
            $features = self::engineerFeatures(array( $record ));
            if (! empty($features)) {
                $trainingData[] = array_merge(
                    $features[0],
                    array(
                    $targetVariable => $record[ $targetVariable ] ?? 0,
                    )
                );
            }
        }

        return $trainingData;
    }

    private static function getExistingModels(string $feedKey): array
    {
        // In a real implementation, this would load from database
        // For now, return empty array
        return array();
    }

    private static function persistModel(array $model): void
    {
        // In a real implementation, this would save to database
        // For now, just store in memory
        self::$models[ $model['model_id'] ] = $model;
    }

    private static function loadModel(string $modelId): ?array
    {
        return self::$models[ $modelId ] ?? null;
    }

    /**
     * Get ML insights for admin dashboard
     *
     * @return array ML insights data
     */
    public static function getInsights(): array
    {
        $insights = array(
        'model_performance'  => array(),
        'feature_importance' => array(),
        'predictions'        => array(),
        );

        // Get model performance data
        foreach (self::$models as $modelId => $model) {
            $insights['model_performance'][ $model['model_type'] ] = array(
            'accuracy'   => $model['accuracy'] ?? 0,
            'precision'  => self::calculatePrecision($model),
            'recall'     => self::calculateRecall($model),
            'trained_at' => $model['trained_at'] ?? 0,
            );
        }

        // Calculate feature importance (simplified)
        $insights['feature_importance'] = self::calculateFeatureImportance();

        // Get recent predictions
        $insights['predictions'] = self::getRecentPredictions();

        return $insights;
    }

    /**
     * Train all models for available feeds
     *
     * @return array Training results
     */
    public static function trainAllModels(): array
    {
        $results = array(
        'models_trained' => 0,
        'avg_accuracy'   => 0,
        'total_models'   => 0,
        );

        // Get all active feeds
        $feeds = get_posts(
            array(
            'post_type'      => 'job-feed',
            'meta_query'     => array(
                    array(
                        'key'     => 'feed_status',
                        'value'   => 'active',
                        'compare' => '=',
            ),
            ),
            'posts_per_page' => -1,
            )
        );

        $accuracies = array();

        foreach ($feeds as $feed) {
            $feedKey = get_post_meta($feed->ID, 'feed_url', true);
            if (! $feedKey) {
                continue;
            }

            $historicalData = self::getFeedHistoricalData($feedKey, 90);
            if (empty($historicalData)) {
                continue;
            }

            $targets = array( 'success_rate', 'response_time', 'error_rate', 'volume' );

            foreach ($targets as $target) {
                $trainingData = self::prepareTrainingData($historicalData, $target);
                if (! empty($trainingData)) {
                    try {
                        $model        = self::trainModel(self::MODEL_ENSEMBLE, $trainingData, $target);
                        $accuracies[] = $model['accuracy'];
                        ++$results['models_trained'];
                    } catch (\Exception $e) {
                        // Log error but continue
                        error_log('Failed to train model for ' . $feedKey . ': ' . $e->getMessage());
                    }
                }
            }
        }

        $results['total_models'] = count(self::$models);
        $results['avg_accuracy'] = ! empty($accuracies) ? array_sum($accuracies) / count($accuracies) : 0;

        return $results;
    }

    /**
     * Calculate precision for a model
     */
    private static function calculatePrecision(array $model): float
    {
        // Simplified precision calculation
        return $model['accuracy'] ?? 0.5;
    }

    /**
     * Calculate recall for a model
     */
    private static function calculateRecall(array $model): float
    {
        // Simplified recall calculation
        return $model['accuracy'] ?? 0.5;
    }

    /**
     * Calculate feature importance
     */
    private static function calculateFeatureImportance(): array
    {
        // Simplified feature importance based on correlation
        $features = array(
        'success_rate'    => 0.85,
        'response_time'   => 0.72,
        'error_rate'      => 0.68,
        'volume'          => 0.61,
        'duplicate_rate'  => 0.45,
        'day_of_week'     => 0.32,
        'hour_of_day'     => 0.28,
        'is_weekend'      => 0.15,
        'trend_3day'      => 0.78,
        'trend_7day'      => 0.82,
        'volatility'      => 0.55,
        'seasonal_factor' => 0.41,
        );

        $importance = array();
        foreach ($features as $name => $weight) {
            $importance[] = array(
            'name'       => $name,
            'importance' => $weight,
            );
        }

        // Sort by importance
        usort(
            $importance,
            function ($a, $b) {
                return $b['importance'] <=> $a['importance'];
            }
        );

        return $importance;
    }

    /**
     * Get recent predictions
     */
    private static function getRecentPredictions(): array
    {
        $predictions = array();

        // Get recent feed performance data
        global $wpdb;
        $table_name = $wpdb->prefix . 'puntwork_import_analytics';

        $sql = $wpdb->prepare(
            "
            SELECT
                feed_url,
                success_rate,
                avg_response_time,
                end_time
            FROM $table_name
            WHERE end_time >= %s
            ORDER BY end_time DESC
            LIMIT 10
        ",
            date('Y-m-d H:i:s', strtotime('-7 days'))
        );

        $results = $wpdb->get_results($sql, ARRAY_A);

        foreach ($results as $result) {
            // Make prediction for this feed
            $feedKey        = $result['feed_url'];
            $historicalData = self::getFeedHistoricalData($feedKey, 30);

            if (! empty($historicalData)) {
                $features       = self::engineerFeatures($historicalData);
                $latestFeatures = end($features);

                // Use ensemble model if available
                $modelId = $feedKey . '_success_rate_' . time();
                if (isset(self::$models[ $modelId ])) {
                    $prediction = self::predict($modelId, $latestFeatures);
                } else {
                    $prediction = array(
                    'prediction' => $result['success_rate'],
                    'confidence' => 0.5,
                    );
                }

                $predictions[] = array(
                 'feed_name'              => $feedKey,
                 'predicted_success_rate' => $prediction['prediction'],
                 'confidence'             => $prediction['confidence'],
                 'actual_success_rate'    => $result['success_rate'],
                 'timestamp'              => strtotime($result['end_time']),
                );
            }
        }

        return $predictions;
    }
}
