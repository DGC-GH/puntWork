<?php

/**
 * Predictive analytics using statistical analysis and trend prediction
 *
 * @package    Puntwork
 * @subpackage AI
 * @since      2.1.0
 */

namespace Puntwork\AI;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Predictive analytics engine for job import data
 */
class PredictiveAnalytics
{
    /**
     * Prediction confidence levels
     */
    public const CONFIDENCE_HIGH = 'high';
    public const CONFIDENCE_MEDIUM = 'medium';
    public const CONFIDENCE_LOW = 'low';

    /**
     * Time periods for analysis
     */
    public const PERIOD_HOUR = 'hour';
    public const PERIOD_DAY = 'day';
    public const PERIOD_WEEK = 'week';
    public const PERIOD_MONTH = 'month';

    /**
     * Predict import success rate for a feed
     *
     * @param string $feedKey Feed identifier
     * @param int $daysHistory Number of days of historical data to analyze
     * @return array Prediction results
     */
    public static function predictImportSuccess(string $feedKey, int $daysHistory = 30): array
    {
        $historicalData = self::getHistoricalImportData($feedKey, $daysHistory);

        if (empty($historicalData)) {
            return [
                'prediction' => null,
                'confidence' => self::CONFIDENCE_LOW,
                'message' => 'Insufficient historical data for prediction'
            ];
        }

        // Calculate success rate trend
        $successRates = array_column($historicalData, 'success_rate');
        $trend = self::calculateTrend($successRates);

        // Predict next success rate
        $predictedRate = self::predictNextValue($successRates, $trend);

        // Calculate confidence based on data consistency and trend strength
        $confidence = self::calculateConfidence($successRates, $trend);

        return [
            'prediction' => round($predictedRate, 2),
            'confidence' => $confidence,
            'trend' => $trend > 0 ? 'improving' : ($trend < 0 ? 'declining' : 'stable'),
            'historical_average' => round(array_sum($successRates) / count($successRates), 2),
            'data_points' => count($successRates)
        ];
    }

    /**
     * Predict feed reliability score
     *
     * @param string $feedKey Feed identifier
     * @return array Reliability prediction
     */
    public static function predictFeedReliability(string $feedKey): array
    {
        $healthData = self::getFeedHealthData($feedKey, 30); // 30 days

        if (empty($healthData)) {
            return [
                'reliability_score' => null,
                'confidence' => self::CONFIDENCE_LOW,
                'message' => 'No health data available'
            ];
        }

        // Calculate reliability metrics
        $responseTimes = array_column($healthData, 'response_time');
        $successRates = array_column($healthData, 'success_rate');
        $errorRates = array_column($healthData, 'error_rate');

        // Weighted reliability score
        $avgResponseTime = array_sum($responseTimes) / count($responseTimes);
        $avgSuccessRate = array_sum($successRates) / count($successRates);
        $avgErrorRate = array_sum($errorRates) / count($errorRates);

        // Normalize response time (lower is better, max 10 seconds = 0 score)
        $responseScore = max(0, 100 - ($avgResponseTime / 100)); // Convert ms to score
        $successScore = $avgSuccessRate * 100;
        $errorScore = max(0, 100 - ($avgErrorRate * 200)); // Errors heavily penalize

        $reliabilityScore = ($responseScore * 0.3) + ($successScore * 0.5) + ($errorScore * 0.2);

        // Predict future reliability
        $trend = self::calculateTrend($successRates);
        $predictedScore = min(100, max(0, $reliabilityScore + ($trend * 10)));

        $confidence = self::calculateConfidence($successRates, $trend);

        return [
            'current_reliability' => round($reliabilityScore, 1),
            'predicted_reliability' => round($predictedScore, 1),
            'confidence' => $confidence,
            'trend' => $trend > 0 ? 'improving' : ($trend < 0 ? 'declining' : 'stable'),
            'factors' => [
                'response_time' => round($avgResponseTime, 0) . 'ms',
                'success_rate' => round($avgSuccessRate * 100, 1) . '%',
                'error_rate' => round($avgErrorRate * 100, 1) . '%'
            ]
        ];
    }

    /**
     * Predict content quality trends
     *
     * @param int $daysHistory Number of days to analyze
     * @return array Quality trend prediction
     */
    public static function predictContentQualityTrends(int $daysHistory = 30): array
    {
        $qualityData = self::getContentQualityData($daysHistory);

        if (empty($qualityData)) {
            return [
                'trend' => null,
                'confidence' => self::CONFIDENCE_LOW,
                'message' => 'No quality data available'
            ];
        }

        $scores = array_column($qualityData, 'quality_score');
        $trend = self::calculateTrend($scores);

        $currentAvg = array_sum($scores) / count($scores);
        $predictedAvg = self::predictNextValue($scores, $trend);

        $confidence = self::calculateConfidence($scores, $trend);

        // Analyze quality distribution
        $excellent = count(array_filter($scores, fn($s) => $s >= 90));
        $good = count(array_filter($scores, fn($s) => $s >= 70 && $s < 90));
        $fair = count(array_filter($scores, fn($s) => $s >= 50 && $s < 70));
        $poor = count(array_filter($scores, fn($s) => $s < 50));

        $total = count($scores);
        $distribution = [
            'excellent' => round(($excellent / $total) * 100, 1),
            'good' => round(($good / $total) * 100, 1),
            'fair' => round(($fair / $total) * 100, 1),
            'poor' => round(($poor / $total) * 100, 1)
        ];

        return [
            'current_average' => round($currentAvg, 1),
            'predicted_average' => round($predictedAvg, 1),
            'trend' => $trend > 0.5 ? 'improving' : ($trend < -0.5 ? 'declining' : 'stable'),
            'confidence' => $confidence,
            'distribution' => $distribution,
            'data_points' => $total
        ];
    }

    /**
     * Predict duplicate detection patterns
     *
     * @param int $daysHistory Number of days to analyze
     * @return array Duplicate pattern prediction
     */
    public static function predictDuplicatePatterns(int $daysHistory = 30): array
    {
        $duplicateData = self::getDuplicateData($daysHistory);

        if (empty($duplicateData)) {
            return [
                'trend' => null,
                'confidence' => self::CONFIDENCE_LOW,
                'message' => 'No duplicate data available'
            ];
        }

        $duplicateRates = array_column($duplicateData, 'duplicate_rate');
        $trend = self::calculateTrend($duplicateRates);

        $currentAvg = array_sum($duplicateRates) / count($duplicateRates);
        $predictedRate = self::predictNextValue($duplicateRates, $trend);

        $confidence = self::calculateConfidence($duplicateRates, $trend);

        // Identify peak duplicate periods
        $peakAnalysis = self::analyzeDuplicatePeaks($duplicateData);

        return [
            'current_duplicate_rate' => round($currentAvg * 100, 2) . '%',
            'predicted_duplicate_rate' => round($predictedRate * 100, 2) . '%',
            'trend' => $trend > 0 ? 'increasing' : ($trend < 0 ? 'decreasing' : 'stable'),
            'confidence' => $confidence,
            'peak_periods' => $peakAnalysis,
            'recommendations' => self::generateDuplicateRecommendations($trend, $currentAvg)
        ];
    }

    /**
     * Predict import volume trends
     *
     * @param string $period Prediction period (hour, day, week, month)
     * @return array Volume prediction
     */
    public static function predictImportVolume(string $period = self::PERIOD_DAY): array
    {
        $volumeData = self::getImportVolumeData($period, 30); // 30 periods of data

        if (empty($volumeData)) {
            return [
                'prediction' => null,
                'confidence' => self::CONFIDENCE_LOW,
                'message' => 'Insufficient volume data'
            ];
        }

        $volumes = array_column($volumeData, 'volume');
        $trend = self::calculateTrend($volumes);

        $currentAvg = array_sum($volumes) / count($volumes);
        $predictedVolume = self::predictNextValue($volumes, $trend);

        $confidence = self::calculateConfidence($volumes, $trend);

        // Calculate seasonal patterns if enough data
        $seasonalPattern = count($volumes) >= 14 ? self::detectSeasonalPattern($volumes) : null;

        return [
            'current_average' => round($currentAvg, 0),
            'predicted_volume' => round($predictedVolume, 0),
            'trend' => $trend > 0 ? 'increasing' : ($trend < 0 ? 'decreasing' : 'stable'),
            'confidence' => $confidence,
            'seasonal_pattern' => $seasonalPattern,
            'volatility' => self::calculateVolatility($volumes),
            'period' => $period
        ];
    }

    /**
     * Calculate linear trend using least squares
     *
     * @param array $data Array of numerical values
     * @return float Trend slope
     */
    private static function calculateTrend(array $data): float
    {
        $n = count($data);
        if ($n < 2) {
            return 0;
        }

        $sumX = $sumY = $sumXY = $sumXX = 0;

        for ($i = 0; $i < $n; $i++) {
            $sumX += $i;
            $sumY += $data[$i];
            $sumXY += $i * $data[$i];
            $sumXX += $i * $i;
        }

        $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumXX - $sumX * $sumX);
        return $slope;
    }

    /**
     * Predict next value using linear regression
     *
     * @param array $data Historical data
     * @param float $trend Calculated trend
     * @return float Predicted next value
     */
    private static function predictNextValue(array $data, float $trend): float
    {
        $lastValue = end($data);
        $nextIndex = count($data);

        // Simple linear extrapolation
        return max(0, $lastValue + ($trend * $nextIndex));
    }

    /**
     * Calculate prediction confidence
     *
     * @param array $data Data points
     * @param float $trend Trend slope
     * @return string Confidence level
     */
    private static function calculateConfidence(array $data, float $trend): string
    {
        $n = count($data);
        if ($n < 3) {
            return self::CONFIDENCE_LOW;
        }

        // Calculate coefficient of determination (R²)
        $mean = array_sum($data) / $n;
        $ssRes = $ssTot = 0;

        for ($i = 0; $i < $n; $i++) {
            $predicted = $mean + ($trend * ($i - $n / 2)); // Center the trend
            $ssRes += pow($data[$i] - $predicted, 2);
            $ssTot += pow($data[$i] - $mean, 2);
        }

        $rSquared = $ssTot > 0 ? 1 - ($ssRes / $ssTot) : 0;

        if ($rSquared > 0.8) {
            return self::CONFIDENCE_HIGH;
        }
        if ($rSquared > 0.5) {
            return self::CONFIDENCE_MEDIUM;
        }
        return self::CONFIDENCE_LOW;
    }

    /**
     * Calculate data volatility (coefficient of variation)
     *
     * @param array $data Data points
     * @return float Volatility percentage
     */
    private static function calculateVolatility(array $data): float
    {
        if (empty($data)) {
            return 0;
        }

        $mean = array_sum($data) / count($data);
        if ($mean == 0) {
            return 0;
        }

        $variance = 0;
        foreach ($data as $value) {
            $variance += pow($value - $mean, 2);
        }
        $variance /= count($data);
        $stdDev = sqrt($variance);

        return round(($stdDev / $mean) * 100, 1);
    }

    /**
     * Detect seasonal patterns in data
     *
     * @param array $data Time series data
     * @return array|null Seasonal pattern analysis
     */
    private static function detectSeasonalPattern(array $data): ?array
    {
        $n = count($data);
        if ($n < 14) {
            return null; // Need at least 2 weeks
        }

        // Simple day-of-week pattern detection (assuming daily data)
        $dayPatterns = [];
        for ($i = 0; $i < 7; $i++) {
            $dayValues = [];
            for ($j = $i; $j < $n; $j += 7) {
                $dayValues[] = $data[$j];
            }
            if (!empty($dayValues)) {
                $dayPatterns[$i] = array_sum($dayValues) / count($dayValues);
            }
        }

        $maxDay = array_keys($dayPatterns, max($dayPatterns))[0];
        $minDay = array_keys($dayPatterns, min($dayPatterns))[0];

        $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        return [
            'peak_day' => $dayNames[$maxDay] ?? 'Unknown',
            'low_day' => $dayNames[$minDay] ?? 'Unknown',
            'peak_average' => round($dayPatterns[$maxDay], 1),
            'low_average' => round($dayPatterns[$minDay], 1)
        ];
    }

    /**
     * Analyze duplicate peaks
     *
     * @param array $duplicateData Duplicate data with timestamps
     * @return array Peak analysis
     */
    private static function analyzeDuplicatePeaks(array $duplicateData): array
    {
        if (empty($duplicateData)) {
            return [];
        }

        $peaks = [];
        $threshold = (array_sum(array_column($duplicateData, 'duplicate_rate')) / count($duplicateData)) * 1.5;

        foreach ($duplicateData as $data) {
            if ($data['duplicate_rate'] > $threshold) {
                $peaks[] = [
                    'date' => $data['date'],
                    'rate' => round($data['duplicate_rate'] * 100, 2) . '%',
                    'volume' => $data['total_jobs']
                ];
            }
        }

        return array_slice($peaks, -5); // Return last 5 peaks
    }

    /**
     * Generate duplicate recommendations
     *
     * @param float $trend Duplicate rate trend
     * @param float $currentRate Current duplicate rate
     * @return array Recommendations
     */
    private static function generateDuplicateRecommendations(float $trend, float $currentRate): array
    {
        $recommendations = [];

        if ($trend > 0.01) {
            $recommendations[] = 'Duplicate rates are increasing - review feed sources for overlapping content';
        }

        if ($currentRate > 0.1) { // >10%
            $recommendations[] = 'High duplicate rate detected - consider implementing stricter deduplication rules';
        }

        if ($currentRate < 0.02) { // <2%
            $recommendations[] = 'Duplicate rate is low - current deduplication is effective';
        }

        return $recommendations;
    }

    // Data retrieval methods using real database data

    private static function getHistoricalImportData(string $feedKey, int $days): array
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'puntwork_import_analytics';
        $date_filter = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        // For now, we'll aggregate all feeds since feed-specific data isn't stored
        // In a future enhancement, we could track per-feed analytics
        $sql = $wpdb->prepare("
            SELECT
                DATE(end_time) as date,
                AVG(success_rate) as success_rate,
                SUM(processed_jobs) as total_jobs
            FROM $table_name
            WHERE end_time >= %s
            GROUP BY DATE(end_time)
            ORDER BY date ASC
        ", $date_filter);

        $results = $wpdb->get_results($sql, ARRAY_A);

        return $results ?: [];
    }

    private static function getFeedHealthData(string $feedKey, int $days): array
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'puntwork_import_analytics';
        $date_filter = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        // Use feed processing stats from analytics table
        $sql = $wpdb->prepare("
            SELECT
                DATE(end_time) as date,
                AVG(avg_response_time) as response_time,
                AVG(feeds_successful / GREATEST(feeds_processed, 1)) as success_rate,
                AVG(feeds_failed / GREATEST(feeds_processed, 1)) as error_rate
            FROM $table_name
            WHERE end_time >= %s AND feeds_processed > 0
            GROUP BY DATE(end_time)
            ORDER BY date ASC
        ", $date_filter);

        $results = $wpdb->get_results($sql, ARRAY_A);

        return $results ?: [];
    }

    private static function getContentQualityData(int $days): array
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'puntwork_import_analytics';
        $date_filter = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        // Estimate quality based on success rates and error patterns
        // In a future enhancement, we could store actual quality scores
        $sql = $wpdb->prepare("
            SELECT
                DATE(end_time) as date,
                GREATEST(50, LEAST(95, AVG(success_rate * 100) - AVG(failed_jobs * 2))) as quality_score,
                SUM(processed_jobs) as total_jobs
            FROM $table_name
            WHERE end_time >= %s
            GROUP BY DATE(end_time)
            ORDER BY date ASC
        ", $date_filter);

        $results = $wpdb->get_results($sql, ARRAY_A);

        return $results ?: [];
    }

    private static function getDuplicateData(int $days): array
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'puntwork_import_analytics';
        $date_filter = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $sql = $wpdb->prepare("
            SELECT
                DATE(end_time) as date,
                AVG(duplicate_jobs / GREATEST(processed_jobs, 1)) as duplicate_rate,
                SUM(processed_jobs) as total_jobs
            FROM $table_name
            WHERE end_time >= %s AND processed_jobs > 0
            GROUP BY DATE(end_time)
            ORDER BY date ASC
        ", $date_filter);

        $results = $wpdb->get_results($sql, ARRAY_A);

        return $results ?: [];
    }

    private static function getImportVolumeData(string $period, int $periods): array
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'puntwork_import_analytics';

        // Calculate date range based on period
        $dateModifier = $period . 's';
        $date_filter = date('Y-m-d H:i:s', strtotime("-{$periods} {$dateModifier}"));

        // Group by the appropriate time period
        $groupBy = '';
        switch ($period) {
            case self::PERIOD_HOUR:
                $groupBy = "DATE_FORMAT(end_time, '%Y-%m-%d %H:00:00')";
                break;
            case self::PERIOD_DAY:
                $groupBy = "DATE(end_time)";
                break;
            case self::PERIOD_WEEK:
                $groupBy = "DATE_SUB(end_time, INTERVAL WEEKDAY(end_time) DAY)";
                break;
            case self::PERIOD_MONTH:
                $groupBy = "DATE_FORMAT(end_time, '%Y-%m-01')";
                break;
            default:
                $groupBy = "DATE(end_time)";
        }

        $sql = $wpdb->prepare("
            SELECT
                {$groupBy} as period,
                SUM(processed_jobs) as volume
            FROM $table_name
            WHERE end_time >= %s
            GROUP BY {$groupBy}
            ORDER BY period ASC
        ", $date_filter);

        $results = $wpdb->get_results($sql, ARRAY_A);

        return $results ?: [];
    }
}
