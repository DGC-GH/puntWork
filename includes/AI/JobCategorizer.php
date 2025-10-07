<?php

/**
 * AI-powered job categorization utility.
 *
 * @since      1.0.0
 */

namespace Puntwork\AI;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JobCategorizer {
	/**
	 * Categorize a job based on title and description
	 *
	 * @param string $title Job title
	 * @param string $description Job description
	 * @return string Job category
	 */
	public static function categorize( $title, $description ) {
		// Basic categorization logic - can be enhanced with AI later
		$title_lower = strtolower( $title );
		$desc_lower = strtolower( $description );

		// Define category keywords
		$categories = array(
			'IT & Technology' => array( 'developer', 'programmer', 'software', 'it', 'tech', 'web', 'frontend', 'backend', 'fullstack', 'devops', 'data', 'analyst', 'engineer', 'architect' ),
			'Healthcare' => array( 'nurse', 'doctor', 'medical', 'healthcare', 'hospital', 'clinic', 'patient', 'therapy', 'pharmacist', 'dentist' ),
			'Education' => array( 'teacher', 'professor', 'education', 'school', 'student', 'academic', 'lecturer', 'tutor', 'trainer' ),
			'Finance' => array( 'accountant', 'finance', 'bank', 'financial', 'auditor', 'controller', 'analyst', 'investment', 'banker' ),
			'Marketing' => array( 'marketing', 'advertising', 'brand', 'social media', 'content', 'seo', 'campaign', 'manager' ),
			'Sales' => array( 'sales', 'representative', 'account manager', 'business development', 'commercial' ),
			'Human Resources' => array( 'hr', 'human resources', 'recruiter', 'talent', 'personnel', 'recruitment' ),
			'Engineering' => array( 'engineer', 'mechanical', 'electrical', 'civil', 'chemical', 'industrial', 'structural' ),
			'Construction' => array( 'construction', 'builder', 'contractor', 'architect', 'project manager' ),
			'Manufacturing' => array( 'manufacturing', 'production', 'operator', 'quality', 'assembly' ),
		);

		// Check title first
		foreach ( $categories as $category => $keywords ) {
			foreach ( $keywords as $keyword ) {
				if ( strpos( $title_lower, $keyword ) !== false ) {
					return $category;
				}
			}
		}

		// Check description
		foreach ( $categories as $category => $keywords ) {
			foreach ( $keywords as $keyword ) {
				if ( strpos( $desc_lower, $keyword ) !== false ) {
					return $category;
				}
			}
		}

		// Default category
		return 'General';
	}
}