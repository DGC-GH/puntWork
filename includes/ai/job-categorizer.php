<?php

/**
 * Intelligent job categorization using keyword-based classification.
 *
 * @since      2.1.0
 */

namespace Puntwork\AI;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Job categorization engine.
 */
class JobCategorizer
{
    /**
     * Job categories with associated keywords.
     */
    private const CATEGORIES = [
        'IT & Technology' => [
            'developer',
            'programmer',
            'software',
            'engineer',
            'it',
            'tech',
            'web',
            'frontend',
            'backend',
            'fullstack',
            'devops',
            'sysadmin',
            'database',
            'dba',
            'analyst',
            'data',
            'scientist',
            'machine learning',
            'ai',
            'artificial intelligence',
            'cybersecurity',
            'security',
            'network',
            'cloud',
            'aws',
            'azure',
            'gcp',
            'docker',
            'kubernetes',
            'linux',
            'windows',
            'mobile',
            'ios',
            'android',
            'app',
            'api',
            'rest',
            'graphql',
            'microservices',
        ],
        'Marketing & Sales' => [
            'marketing',
            'sales',
            'advertising',
            'seo',
            'sem',
            'social media',
            'content',
            'copywriter',
            'brand',
            'campaign',
            'digital marketing',
            'email marketing',
            'market research',
            'business development',
            'account manager',
            'sales representative',
            'customer success',
            'crm',
            'lead generation',
            'public relations',
            'pr',
            'communications',
        ],
        'Finance & Accounting' => [
            'finance',
            'accounting',
            'auditor',
            'controller',
            'cfo',
            'financial analyst',
            'investment',
            'banking',
            'tax',
            'payroll',
            'bookkeeper',
            'budget',
            'forecasting',
            'risk management',
            'compliance',
            'treasury',
            'credit',
            'loan',
            'mortgage',
        ],
        'Human Resources' => [
            'hr',
            'human resources',
            'recruiter',
            'recruitment',
            'talent',
            'people',
            'employee',
            'training',
            'development',
            'organizational',
            'compensation',
            'benefits',
            'payroll',
            'labor relations',
            'diversity',
            'inclusion',
            'workforce',
        ],
        'Healthcare & Medical' => [
            'nurse',
            'doctor',
            'physician',
            'medical',
            'healthcare',
            'clinical',
            'pharmacy',
            'therapist',
            'counselor',
            'psychologist',
            'dentist',
            'veterinarian',
            'pharmaceutical',
            'biotech',
            'research',
            'patient care',
            'hospital',
            'clinic',
        ],
        'Engineering' => [
            'engineer',
            'engineering',
            'mechanical',
            'electrical',
            'civil',
            'chemical',
            'aerospace',
            'automotive',
            'manufacturing',
            'quality',
            'r&d',
            'research',
            'design',
            'cad',
            'project manager',
            'construction',
            'architect',
        ],
        'Education & Training' => [
            'teacher',
            'professor',
            'educator',
            'trainer',
            'instructor',
            'coach',
            'tutor',
            'curriculum',
            'academic',
            'school',
            'university',
            'college',
            'education',
            'training',
            'learning',
            'development',
            'e-learning',
        ],
        'Operations & Logistics' => [
            'operations',
            'logistics',
            'supply chain',
            'warehouse',
            'inventory',
            'procurement',
            'purchasing',
            'distribution',
            'transportation',
            'shipping',
            'customer service',
            'support',
            'help desk',
            'call center',
            'quality assurance',
            'qa',
        ],
        'Legal' => [
            'lawyer',
            'attorney',
            'legal',
            'paralegal',
            'compliance',
            'contract',
            'intellectual property',
            'ip',
            'litigation',
            'counsel',
            'regulatory',
            'risk',
            'governance',
        ],
        'Creative & Design' => [
            'designer',
            'creative',
            'graphic',
            'ui',
            'ux',
            'user experience',
            'art',
            'photography',
            'video',
            'editor',
            'content creator',
            'marketing design',
            'brand design',
            'illustrator',
        ],
        'Management & Executive' => [
            'manager',
            'director',
            'executive',
            'ceo',
            'cfo',
            'coo',
            'vp',
            'president',
            'leadership',
            'strategic',
            'planning',
            'business development',
            'consultant',
            'advisor',
        ],
        'Other' => [], // Catch-all category
    ];

    /**
     * Categorize a job based on its title and description.
     *
     * @param  string $title       Job title
     * @param  string $description Job description
     * @return string Job category
     */
    public static function categorize(string $title, string $description = ''): string
    {
        $text = strtolower($title . ' ' . $description);
        $scores = [];

        // Calculate scores for each category
        foreach (self::CATEGORIES as $category => $keywords) {
            if (empty($keywords)) {
                continue; // Skip 'Other' category
            }

            $score = 0;
            foreach ($keywords as $keyword) {
                $count = substr_count($text, strtolower($keyword));
                $score += $count;
            }

            if ($score > 0) {
                $scores[$category] = $score;
            }
        }

        // Return category with highest score, or 'Other' if no matches
        if (!empty($scores)) {
            arsort($scores);

            return key($scores);
        }

        return 'Other';
    }

    /**
     * Get all available categories.
     *
     * @return array List of category names
     */
    public static function getCategories(): array
    {
        return array_keys(self::CATEGORIES);
    }

    /**
     * Get keywords for a specific category.
     *
     * @param  string $category Category name
     * @return array List of keywords
     */
    public static function getCategoryKeywords(string $category): array
    {
        return self::CATEGORIES[$category] ?? [];
    }
}
