<?php

/**
 * Advanced duplicate detection using ML-like similarity algorithms
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
 * Duplicate detection engine using similarity algorithms
 */
class DuplicateDetector
{
    /**
     * Similarity threshold for considering items as duplicates
     */
    public const SIMILARITY_THRESHOLD = 0.85;

    /**
     * Detect potential duplicates in a batch of jobs using content similarity
     *
     * @param array $jobBatch Array of job data arrays
     * @return array Array of duplicate groups, each containing similar job indices
     */
    public static function detectDuplicates(array $jobBatch): array
    {
        $duplicates = [];
        $processed = [];

        foreach ($jobBatch as $i => $job1) {
            if (in_array($i, $processed)) {
                continue;
            }

            $group = [$i];
            $processed[] = $i;

            foreach ($jobBatch as $j => $job2) {
                if ($i === $j || in_array($j, $processed)) {
                    continue;
                }

                $similarity = self::calculateSimilarity($job1, $job2);

                if ($similarity >= self::SIMILARITY_THRESHOLD) {
                    $group[] = $j;
                    $processed[] = $j;
                }
            }

            if (count($group) > 1) {
                $duplicates[] = $group;
            }
        }

        return $duplicates;
    }

    /**
     * Calculate similarity between two jobs using multiple algorithms
     *
     * @param array $job1 First job data
     * @param array $job2 Second job data
     * @return float Similarity score between 0 and 1
     */
    public static function calculateSimilarity(array $job1, array $job2): float
    {
        // Extract comparable fields
        $title1 = strtolower($job1['job_title'] ?? '');
        $title2 = strtolower($job2['job_title'] ?? '');
        $desc1 = strtolower($job1['job_description'] ?? '');
        $desc2 = strtolower($job2['job_description'] ?? '');
        $company1 = strtolower($job1['job_company'] ?? '');
        $company2 = strtolower($job2['job_company'] ?? '');

        // Calculate different similarity scores
        $titleSimilarity = self::jaccardSimilarity($title1, $title2);
        $descSimilarity = self::jaccardSimilarity($desc1, $desc2);
        $companySimilarity = self::levenshteinSimilarity($company1, $company2);

        // Weighted combination (title and description are most important)
        $weightedSimilarity = (
            $titleSimilarity * 0.4 +
            $descSimilarity * 0.4 +
            $companySimilarity * 0.2
        );

        return $weightedSimilarity;
    }

    /**
     * Calculate Jaccard similarity between two strings
     *
     * @param string $str1 First string
     * @param string $str2 Second string
     * @return float Similarity score between 0 and 1
     */
    private static function jaccardSimilarity(string $str1, string $str2): float
    {
        if (empty($str1) && empty($str2)) {
            return 1.0;
        }

        if (empty($str1) || empty($str2)) {
            return 0.0;
        }

        // Tokenize strings (split by whitespace and punctuation)
        $tokens1 = preg_split('/\s+|[^\w\s]/', $str1, -1, PREG_SPLIT_NO_EMPTY);
        $tokens2 = preg_split('/\s+|[^\w\s]/', $str2, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($tokens1) && empty($tokens2)) {
            return 1.0;
        }

        $set1 = array_unique($tokens1);
        $set2 = array_unique($tokens2);

        $intersection = array_intersect($set1, $set2);
        $union = array_unique(array_merge($set1, $set2));

        return count($intersection) / count($union);
    }

    /**
     * Calculate Levenshtein similarity between two strings
     *
     * @param string $str1 First string
     * @param string $str2 Second string
     * @return float Similarity score between 0 and 1
     */
    private static function levenshteinSimilarity(string $str1, string $str2): float
    {
        if (empty($str1) && empty($str2)) {
            return 1.0;
        }

        if (empty($str1) || empty($str2)) {
            return 0.0;
        }

        $len1 = strlen($str1);
        $len2 = strlen($str2);

        // Normalize by the longer string length
        $maxLen = max($len1, $len2);
        if ($maxLen === 0) {
            return 1.0;
        }

        $distance = levenshtein($str1, $str2);
        return 1 - ($distance / $maxLen);
    }

    /**
     * Fuzzy match two strings using multiple algorithms
     *
     * @param string $str1 First string
     * @param string $str2 Second string
     * @return float Fuzzy match score between 0 and 1
     */
    public static function fuzzyMatch(string $str1, string $str2): float
    {
        $str1 = strtolower(trim($str1));
        $str2 = strtolower(trim($str2));

        if ($str1 === $str2) {
            return 1.0;
        }

        // Use combination of Jaccard and Levenshtein
        $jaccard = self::jaccardSimilarity($str1, $str2);
        $levenshtein = self::levenshteinSimilarity($str1, $str2);

        return ($jaccard * 0.6) + ($levenshtein * 0.4);
    }

    /**
     * Check if two jobs are duplicates based on similarity
     *
     * @param array $job1 First job data
     * @param array $job2 Second job data
     * @return bool True if jobs are considered duplicates
     */
    public static function isDuplicate(array $job1, array $job2): bool
    {
        return self::calculateSimilarity($job1, $job2) >= self::SIMILARITY_THRESHOLD;
    }
}
