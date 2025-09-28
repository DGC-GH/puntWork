<?php

/**
 * Content quality scoring using linguistic and structural analysis
 *
 * @package    Puntwork
 * @subpackage AI
 * @since      2.1.0
 */

namespace Puntwork\AI;

// Prevent direct access
if (! defined('ABSPATH') ) {
    exit;
}

/**
 * Content quality analysis and scoring
 */
class ContentQualityScorer
{

    /**
     * Quality score ranges
     */
    public const SCORE_EXCELLENT = 90;
    public const SCORE_GOOD      = 70;
    public const SCORE_FAIR      = 50;
    public const SCORE_POOR      = 30;

    /**
     * Quality factors and their weights
     */
    private const QUALITY_FACTORS = array(
    'completeness'    => 0.25,
    'readability'     => 0.20,
    'professionalism' => 0.20,
    'structure'       => 0.15,
    'engagement'      => 0.10,
    'uniqueness'      => 0.10,
    );

    /**
     * Required elements for job descriptions
     */
    private const REQUIRED_ELEMENTS = array(
    'responsibilities',
    'requirements',
    'benefits',
    'company',
    'location',
    'salary',
    );

    /**
     * Professional keywords that indicate quality content
     */
    private const PROFESSIONAL_KEYWORDS = array(
    'responsibilities',
    'requirements',
    'qualifications',
    'benefits',
    'compensation',
    'experience',
    'skills',
    'education',
    'opportunities',
    'development',
    'growth',
    'team',
    'collaborate',
    'innovative',
    'dynamic',
    'professional',
    'excellent',
    'competitive',
    'comprehensive',
    'diverse',
    'inclusive',
    'challenging',
    );

    /**
     * Unprofessional or spammy indicators
     */
    private const UNPROFESSIONAL_INDICATORS = array(
    'urgent',
    'immediate',
    'apply now',
    'limited time',
    'exclusive',
    'confidential',
    'top secret',
    'million dollar',
    'guaranteed',
    'easy money',
    'work from home',
    'no experience needed',
    'quick cash',
    'overnight success',
    );

    /**
     * Score content quality for a job posting
     *
     * @param  array $jobData Job data array with title, description, etc.
     * @return array Quality score and analysis details
     */
    public static function scoreContent( array $jobData ): array
    {
        $scores = array();

        // Calculate individual factor scores
        $scores['completeness']    = self::scoreCompleteness($jobData);
        $scores['readability']     = self::scoreReadability($jobData);
        $scores['professionalism'] = self::scoreProfessionalism($jobData);
        $scores['structure']       = self::scoreStructure($jobData);
        $scores['engagement']      = self::scoreEngagement($jobData);
        $scores['uniqueness']      = self::scoreUniqueness($jobData);

        // Calculate weighted overall score
        $overallScore = 0;
        foreach ( self::QUALITY_FACTORS as $factor => $weight ) {
            $overallScore += $scores[ $factor ] * $weight;
        }

        // Determine quality level
        $qualityLevel = self::determineQualityLevel($overallScore);

        return array(
        'overall_score'   => round($overallScore, 1),
        'quality_level'   => $qualityLevel,
        'factor_scores'   => $scores,
        'recommendations' => self::generateRecommendations($scores),
        'strengths'       => self::identifyStrengths($scores),
        'weaknesses'      => self::identifyWeaknesses($scores),
        );
    }

    /**
     * Score completeness of job content
     */
    private static function scoreCompleteness( array $jobData ): float
    {
        $score    = 0;
        $maxScore = count(self::REQUIRED_ELEMENTS);

        $content = strtolower(
            implode(
                ' ',
                array(
                $jobData['job_title'] ?? '',
                $jobData['job_description'] ?? '',
                $jobData['job_company'] ?? '',
                $jobData['job_location'] ?? '',
                $jobData['job_salary'] ?? '',
                )
            )
        );

        foreach ( self::REQUIRED_ELEMENTS as $element ) {
            if (strpos($content, $element) !== false ) {
                ++$score;
            }
        }

        // Bonus for having contact information
        if (! empty($jobData['job_apply'] ?? '') ) {
            $score += 0.5;
        }

        return min(100, ( $score / $maxScore ) * 100);
    }

    /**
     * Score readability using basic metrics
     */
    private static function scoreReadability( array $jobData ): float
    {
        $description = $jobData['job_description'] ?? '';

        if (empty($description) ) {
            return 0;
        }

        $sentences = preg_split('/[.!?]+/', $description, -1, PREG_SPLIT_NO_EMPTY);
        $words     = str_word_count($description);
        $chars     = strlen($description);

        // Average words per sentence (ideal: 15-20)
        $avgWordsPerSentence = $words / max(1, count($sentences));

        // Average characters per word (ideal: 4-5)
        $avgCharsPerWord = $chars / max(1, $words);

        // Length score (prefer substantial content)
        $lengthScore = min(100, $words / 2); // 200+ words = 100

        // Sentence structure score
        $sentenceScore = 100;
        if ($avgWordsPerSentence < 8 ) {
            $sentenceScore = 70; // Too short sentences
        } elseif ($avgWordsPerSentence > 25 ) {
            $sentenceScore = 80; // Too long sentences
        }

        // Word complexity score
        $complexityScore = 100;
        if ($avgCharsPerWord < 3.5 ) {
            $complexityScore = 60; // Too simple words
        } elseif ($avgCharsPerWord > 6 ) {
            $complexityScore = 90; // Complex words are okay but not excessive
        }

        return ( $lengthScore * 0.4 ) + ( $sentenceScore * 0.4 ) + ( $complexityScore * 0.2 );
    }

    /**
     * Score professionalism of content
     */
    private static function scoreProfessionalism( array $jobData ): float
    {
        $content = strtolower(
            implode(
                ' ',
                array(
                $jobData['job_title'] ?? '',
                $jobData['job_description'] ?? '',
                )
            )
        );

        if (empty($content) ) {
            return 0;
        }

        $professionalScore     = 0;
        $unprofessionalPenalty = 0;

        // Count professional keywords
        foreach ( self::PROFESSIONAL_KEYWORDS as $keyword ) {
            if (strpos($content, $keyword) !== false ) {
                $professionalScore += 2;
            }
        }

        // Penalize unprofessional indicators
        foreach ( self::UNPROFESSIONAL_INDICATORS as $indicator ) {
            if (strpos($content, $indicator) !== false ) {
                $unprofessionalPenalty += 10;
            }
        }

        // Check for proper grammar indicators
        $hasProperStructure = preg_match('/\b(responsibilities|requirements|qualifications)\b/i', $content);
        if ($hasProperStructure ) {
            $professionalScore += 20;
        }

        // Check for excessive caps or special characters
        $capsRatio = preg_match_all('/[A-Z]/', $content) / max(1, strlen($content));
        if ($capsRatio > 0.3 ) {
            $unprofessionalPenalty += 20; // Too many caps
        }

        $finalScore = min(100, max(0, $professionalScore - $unprofessionalPenalty));

        return $finalScore;
    }

    /**
     * Score structural organization
     */
    private static function scoreStructure( array $jobData ): float
    {
        $description = $jobData['job_description'] ?? '';

        if (empty($description) ) {
            return 0;
        }

        $score = 0;

        // Check for bullet points or numbered lists
        if (preg_match('/^[\s]*[-*•]\s/m', $description) || preg_match('/^\s*\d+\.\s/m', $description) ) {
            $score += 30;
        }

        // Check for section headers
        $headers = array( 'responsibilities', 'requirements', 'benefits', 'about us', 'what we offer', 'qualifications' );
        foreach ( $headers as $header ) {
            if (preg_match('/\b' . preg_quote($header, '/') . '\b/i', $description) ) {
                $score += 10;
            }
        }

        // Check for logical flow (has introduction and conclusion-like elements)
        if (preg_match('/^(we are|join|about)/i', trim($description)) ) {
            $score += 15; // Has introduction
        }

        if (preg_match('/(apply|contact|interested)/i', $description) ) {
            $score += 15; // Has call to action
        }

        // Check for appropriate length distribution
        $wordCount = str_word_count($description);
        if ($wordCount > 50 && $wordCount < 500 ) {
            $score += 20; // Good length
        } elseif ($wordCount >= 500 ) {
            $score += 10; // Long but acceptable
        }

        return min(100, $score);
    }

    /**
     * Score engagement and appeal
     */
    private static function scoreEngagement( array $jobData ): float
    {
        $content = strtolower($jobData['job_description'] ?? '');

        if (empty($content) ) {
            return 0;
        }

        $score = 0;

        // Check for action verbs
        $actionVerbs = array( 'develop', 'create', 'manage', 'lead', 'design', 'implement', 'collaborate', 'innovate', 'analyze', 'optimize' );
        foreach ( $actionVerbs as $verb ) {
            if (strpos($content, $verb) !== false ) {
                $score += 5;
            }
        }

        // Check for benefit-focused language
        $benefitWords = array( 'opportunity', 'growth', 'development', 'learning', 'challenge', 'rewarding', 'exciting', 'dynamic' );
        foreach ( $benefitWords as $word ) {
            if (strpos($content, $word) !== false ) {
                $score += 5;
            }
        }

        // Check for inclusive language
        $inclusiveWords = array( 'we', 'our', 'team', 'together', 'join', 'become', 'part of' );
        foreach ( $inclusiveWords as $word ) {
            if (strpos($content, $word) !== false ) {
                $score += 3;
            }
        }

        // Penalize passive voice (basic check)
        if (preg_match('/\b(is|are|was|were)\s+(being\s+)?\b/i', $content) ) {
            $score -= 10;
        }

        return max(0, min(100, $score));
    }

    /**
     * Score uniqueness and originality
     */
    private static function scoreUniqueness( array $jobData ): float
    {
        $content = $jobData['job_description'] ?? '';

        if (empty($content) ) {
            return 0;
        }

        // Basic uniqueness check - look for repeated phrases
        $sentences       = preg_split('/[.!?]+/', $content, -1, PREG_SPLIT_NO_EMPTY);
        $uniqueSentences = count(array_unique(array_map('trim', $sentences)));
        $totalSentences  = count($sentences);

        if ($totalSentences === 0 ) {
            return 0;
        }

        $uniquenessRatio = $uniqueSentences / $totalSentences;

        // Check for template-like language
        $templateIndicators = array(
        'we are looking for',
        'the successful candidate',
        'responsibilities include',
        'requirements include',
        'benefits include',
        );

        $templatePenalty = 0;
        foreach ( $templateIndicators as $indicator ) {
            if (strpos(strtolower($content), $indicator) !== false ) {
                $templatePenalty += 10;
            }
        }

        $score = ( $uniquenessRatio * 100 ) - $templatePenalty;

        return max(0, min(100, $score));
    }

    /**
     * Determine quality level based on score
     */
    private static function determineQualityLevel( float $score ): string
    {
        if ($score >= self::SCORE_EXCELLENT ) {
            return 'Excellent';
        } elseif ($score >= self::SCORE_GOOD ) {
            return 'Good';
        } elseif ($score >= self::SCORE_FAIR ) {
            return 'Fair';
        } elseif ($score >= self::SCORE_POOR ) {
            return 'Poor';
        } else {
            return 'Very Poor';
        }
    }

    /**
     * Generate improvement recommendations
     */
    private static function generateRecommendations( array $scores ): array
    {
        $recommendations = array();

        if ($scores['completeness'] < 70 ) {
            $recommendations[] = 'Add missing job details like responsibilities, requirements, or benefits';
        }

        if ($scores['readability'] < 70 ) {
            $recommendations[] = 'Improve readability by varying sentence length and using clearer language';
        }

        if ($scores['professionalism'] < 70 ) {
            $recommendations[] = 'Use more professional language and avoid unprofessional terms';
        }

        if ($scores['structure'] < 70 ) {
            $recommendations[] = 'Organize content with clear sections and bullet points';
        }

        if ($scores['engagement'] < 70 ) {
            $recommendations[] = 'Make the description more engaging with action verbs and benefit-focused language';
        }

        if ($scores['uniqueness'] < 70 ) {
            $recommendations[] = 'Make the content more unique and avoid generic templates';
        }

        return $recommendations;
    }

    /**
     * Identify content strengths
     */
    private static function identifyStrengths( array $scores ): array
    {
        $strengths = array();

        if ($scores['completeness'] >= 80 ) {
            $strengths[] = 'Comprehensive job information';
        }

        if ($scores['readability'] >= 80 ) {
            $strengths[] = 'Highly readable content';
        }

        if ($scores['professionalism'] >= 80 ) {
            $strengths[] = 'Professional tone and language';
        }

        if ($scores['structure'] >= 80 ) {
            $strengths[] = 'Well-organized structure';
        }

        if ($scores['engagement'] >= 80 ) {
            $strengths[] = 'Engaging and appealing description';
        }

        if ($scores['uniqueness'] >= 80 ) {
            $strengths[] = 'Unique and original content';
        }

        return $strengths;
    }

    /**
     * Identify content weaknesses
     */
    private static function identifyWeaknesses( array $scores ): array
    {
        $weaknesses = array();

        if ($scores['completeness'] < 60 ) {
            $weaknesses[] = 'Missing key job information';
        }

        if ($scores['readability'] < 60 ) {
            $weaknesses[] = 'Poor readability';
        }

        if ($scores['professionalism'] < 60 ) {
            $weaknesses[] = 'Unprofessional language';
        }

        if ($scores['structure'] < 60 ) {
            $weaknesses[] = 'Poor organization';
        }

        if ($scores['engagement'] < 60 ) {
            $weaknesses[] = 'Unengaging content';
        }

        if ($scores['uniqueness'] < 60 ) {
            $weaknesses[] = 'Generic or templated content';
        }

        return $weaknesses;
    }

    /**
     * Get quality metrics for a job
     */
    public static function getQualityMetrics( array $jobData ): array
    {
        return self::scoreContent($jobData);
    }
}
