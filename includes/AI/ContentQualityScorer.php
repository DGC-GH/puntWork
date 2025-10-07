<?php

/**
 * AI-powered content quality scoring utility.
 *
 * @since      1.0.0
 */

namespace Puntwork\AI;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ContentQualityScorer {
	/**
	 * Get quality metrics for a job posting
	 *
	 * @param array $job_obj Job object data
	 * @return array Quality metrics
	 */
	public static function getQualityMetrics( $job_obj ) {
		$score = 0;
		$max_score = 100;
		$factors = array();
		$recommendations = array();

		// Title quality (20 points)
		$title = $job_obj['job_title'] ?? '';
		if ( strlen( $title ) > 10 && strlen( $title ) < 100 ) {
			$score += 20;
			$factors['title_length'] = 20;
		} else {
			$factors['title_length'] = 0;
			$recommendations[] = 'Title should be between 10-100 characters';
		}

		// Description quality (30 points)
		$description = $job_obj['job_description'] ?? '';
		$desc_length = strlen( $description );
		if ( $desc_length > 200 ) {
			$score += 30;
			$factors['description_length'] = 30;
		} elseif ( $desc_length > 100 ) {
			$score += 20;
			$factors['description_length'] = 20;
			$recommendations[] = 'Description could be more detailed';
		} else {
			$factors['description_length'] = 0;
			$recommendations[] = 'Description is too short, should be at least 200 characters';
		}

		// Company info (15 points)
		$company = $job_obj['job_company'] ?? '';
		if ( ! empty( $company ) ) {
			$score += 15;
			$factors['company_info'] = 15;
		} else {
			$factors['company_info'] = 0;
			$recommendations[] = 'Company information is missing';
		}

		// Location info (15 points)
		$location = $job_obj['job_location'] ?? '';
		if ( ! empty( $location ) ) {
			$score += 15;
			$factors['location_info'] = 15;
		} else {
			$factors['location_info'] = 0;
			$recommendations[] = 'Location information is missing';
		}

		// Skills/requirements (10 points)
		$skills = $job_obj['job_skills'] ?? '';
		if ( ! empty( $skills ) ) {
			$score += 10;
			$factors['skills_info'] = 10;
		} else {
			$factors['skills_info'] = 0;
			$recommendations[] = 'Skills or requirements information is missing';
		}

		// Salary info (10 points) - optional but good to have
		$salary = $job_obj['job_salary'] ?? '';
		if ( ! empty( $salary ) ) {
			$score += 10;
			$factors['salary_info'] = 10;
		} else {
			$factors['salary_info'] = 0;
			$recommendations[] = 'Salary information could improve job appeal';
		}

		// Determine quality level
		if ( $score >= 80 ) {
			$quality_level = 'Excellent';
		} elseif ( $score >= 60 ) {
			$quality_level = 'Good';
		} elseif ( $score >= 40 ) {
			$quality_level = 'Fair';
		} else {
			$quality_level = 'Poor';
		}

		return array(
			'overall_score' => $score,
			'quality_level' => $quality_level,
			'factor_scores' => $factors,
			'recommendations' => $recommendations,
		);
	}
}