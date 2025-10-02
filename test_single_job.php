<?php

/**
 * Test script to add a single TEST job
 * This verifies that the job import functionality works before debugging batches
 * Modified to work with batch processing functions for single-item testing
 */

// Include the batch processing functions
echo "Including batch-processing.php...\n";
require_once 'includes/batch/batch-processing.php';
echo "✅ Included batch-processing.php successfully\n\n";

// Test job data - extracted from JSONL and modified
$test_job = array(
	'guid'                        => 'TEST_JOB_001',
	'author'                      => '<a10:name xmlns:a10="http://www.w3.org/2005/Atom">Test Company</a10:name>',
	'name'                        => 'Test Company',
	'category'                    => 'Test Category',
	'title'                       => 'TEST', // Modified title for testing
	'description'                 => '<p>This is a test job to verify the import functionality works correctly.</p>',
	'pubdate'                     => date( 'D, d M Y H:i:s O' ),
	'updated'                     => date( 'Y-m-d\TH:i:sP' ),
	'link'                        => 'https://test.com/job/test',
	'applylink'                   => 'https://test.com/apply/test',
	'magiclink'                   => '',
	'branche'                     => 'Test',
	'postalcode'                  => '1000',
	'city'                        => 'TEST CITY',
	'province'                    => 'Test Province',
	'provincecode'                => 'TEST',
	'country'                     => 'BE',
	'validfrom'                   => date( 'Y-m-d\TH:i:s' ),
	'validtill'                   => date( 'Y-m-d\TH:i:s', strtotime( '+30 days' ) ),
	'channeltype'                 => '29998',
	'functiongroup'               => 'Test Services',
	'functiongroup2'              => '',
	'functiongroup3'              => '',
	'functiongroupid'             => '1',
	'functiongroupid2'            => '0',
	'functiongroupid3'            => '0',
	'function'                    => 'Test Function',
	'function2'                   => '',
	'function3'                   => '',
	'functionid'                  => '1',
	'functionid2'                 => '0',
	'functionid3'                 => '0',
	'functiontitle'               => 'TEST',
	'functiondescription'         => '<p>This is a test job function.</p>',
	'education'                   => 'Bachelor',
	'education2'                  => 'Bachelor',
	'education3'                  => 'Bachelor',
	'educationid'                 => '1',
	'educationid2'                => '1',
	'educationid3'                => '1',
	'educationgroup'              => 'Bachelor',
	'educationgroup2'             => 'Bachelor',
	'educationgroup3'             => 'Bachelor',
	'educationgroupcode'          => '001',
	'educationgroupcode2'         => '001',
	'educationgroupcode3'         => '001',
	'jobtype'                     => 'Full-time',
	'jobtypecode'                 => 'FULL',
	'jobtypegroup'                => 'Permanent Contract',
	'jobtypegroupcode'            => '001',
	'contracttype'                => 'Employee',
	'contracttype2'               => '',
	'contracttype3'               => '',
	'contracttypecode'            => '20',
	'contracttypecode2'           => '',
	'contracttypecode3'           => '',
	'experience'                  => 'No experience required',
	'experiencecode'              => '001',
	'brand'                       => 'Test Company',
	'accountid'                   => '123456',
	'internal'                    => 'false',
	'payrollid'                   => '123456',
	'payroll'                     => 'Test Payroll',
	'brancheid'                   => '1',
	'label'                       => 'Test Label',
	'labelid'                     => '1',
	'language'                    => 'English',
	'language2'                   => '',
	'language3'                   => '',
	'languagecode'                => '1',
	'languagecode2'               => '',
	'languagecode3'               => '',
	'languagelevel'               => 'Good',
	'languagelevel2'              => '',
	'languagelevel3'              => '',
	'languagelevelcode'           => '3',
	'languagelevelcode2'          => '',
	'languagelevelcode3'          => '',
	'office'                      => 'Test Office',
	'officeid'                    => '1',
	'officestreet'                => 'Test Street',
	'officehousenumber'           => '1',
	'officeaddition'              => '',
	'officepostalcode'            => '1000',
	'officecity'                  => 'TEST CITY',
	'officetelephone'             => '+32 123 456 789',
	'officeemail'                 => 'test@test.com',
	'hours'                       => '40',
	'salaryfrom'                  => '30000',
	'salaryto'                    => '40000',
	'salarytype'                  => 'per year',
	'salarytypecode'              => '1',
	'parttime'                    => 'false',
	'offerdescription'            => '<p>Test job offer description.</p>',
	'requirementsdescription'     => '<p>Test job requirements.</p>',
	'reference'                   => 'TEST001',
	'shift'                       => 'Day shift',
	'shiftcode'                   => '1',
	'driverslicense'              => '',
	'driverslicenseid'            => '0',
	'publicationlanguage'         => 'EN',
	'companydescription'          => '<p>Test company description.</p>',
	'job_title'                   => 'TEST',
	'job_slug'                    => 'test-job',
	'job_link'                    => 'https://test.com/job/test',
	'job_salary'                  => '€30000 - €40000',
	'job_apply'                   => 'https://test.com/apply/test',
	'job_icon'                    => '<i class="fas fa-briefcase"></i>',
	'job_car'                     => '',
	'job_time'                    => 'Full-time',
	'job_description'             => 'Test job description',
	'job_remote'                  => '',
	'job_meal_vouchers'           => '',
	'job_flex_hours'              => '',
	'job_skills'                  => array(),
	'job_posting'                 => '{}',
	'job_ecommerce'               => '{}',
	'job_languages'               => '<ul><li>English: Good (3/5)</li></ul>',
	'job_category'                => 'Test',
	'job_quality_score'           => 50.0,
	'job_quality_level'           => 'Average',
	'job_quality_factors'         => '{}',
	'job_quality_recommendations' => '[]',
);

echo "=== TESTING SINGLE JOB IMPORT ===\n";
echo 'Job GUID: ' . $test_job['guid'] . "\n";
echo 'Job Title: ' . $test_job['title'] . "\n\n";

// Test the batch processing logic with a single-item batch
echo "Testing batch processing logic with single-item batch...\n\n";

try {
	// Check if required functions are available
	$required_functions = array(
		'process_batch_items_with_metadata',
		'prepare_batch_metadata',
		'load_and_prepare_batch_items',
	);

	$missing_functions = array();
	foreach ( $required_functions as $func ) {
		if ( ! function_exists( $func ) ) {
			$missing_functions[] = $func;
		}
	}

	if ( ! empty( $missing_functions ) ) {
		echo '❌ Missing required functions: ' . implode( ', ', $missing_functions ) . "\n";
		echo 'Available functions: ' . implode( ', ', get_defined_functions()['user'] ) . "\n";
		exit( 1 );
	}

	echo "✅ All required functions are available\n\n";

	// Simulate a single-item batch
	$batch_guids = array( $test_job['guid'] );
	$batch_items = array(
		$test_job['guid'] => array(
			'item' => $test_job,
			'hash' => md5( json_encode( $test_job ) ),
		),
	);

	// Mock post_ids_by_guid (empty for new job)
	$post_ids_by_guid = array();

	// Mock batch metadata
	$batch_metadata = array(
		'last_updates'       => array(),
		'all_hashes_by_post' => array(),
		'acf_fields'         => array(),
		'zero_empty_fields'  => false,
	);

	// Initialize counters and logs
	$logs      = array();
	$updated   = 0;
	$published = 0;
	$skipped   = 0;

	echo "Calling process_batch_items_with_metadata with single job...\n";

	// Process the single job using batch processing logic
	$processed_count = process_batch_items_with_metadata(
		$batch_guids,
		$batch_items,
		$batch_metadata,
		$post_ids_by_guid,
		$logs,
		$updated,
		$published,
		$skipped
	);

	echo "\n✅ Batch processing completed!\n";
	echo "Processed: $processed_count items\n";
	echo "Published: $published\n";
	echo "Updated: $updated\n";
	echo "Skipped: $skipped\n\n";

	if ( ! empty( $logs ) ) {
		echo "Logs:\n";
		foreach ( $logs as $log ) {
			echo "  - $log\n";
		}
		echo "\n";
	}

	// Check if the job was actually processed
	if ( $processed_count > 0 ) {
		echo "✅ Single job processing appears to have worked!\n";
		echo "The batch processing logic successfully handled the test job.\n";
	} else {
		echo "❌ No items were processed - there may be an issue with the batch processing logic.\n";
	}
} catch ( Exception $e ) {
	echo "❌ Exception during testing:\n";
	echo 'Error: ' . $e->getMessage() . "\n";
	echo 'File: ' . $e->getFile() . ':' . $e->getLine() . "\n";
	echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
} catch ( Error $e ) {
	echo "❌ Fatal error during testing:\n";
	echo 'Error: ' . $e->getMessage() . "\n";
	echo 'File: ' . $e->getFile() . ':' . $e->getLine() . "\n";
	echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== TEST COMPLETE ===\n";

// Test job data - extracted from JSONL and modified
$test_job = array(
	'guid'                        => 'TEST_JOB_001',
	'author'                      => '<a10:name xmlns:a10="http://www.w3.org/2005/Atom">Test Company</a10:name>',
	'name'                        => 'Test Company',
	'category'                    => 'Test Category',
	'title'                       => 'TEST', // Modified title for testing
	'description'                 => '<p>This is a test job to verify the import functionality works correctly.</p>',
	'pubdate'                     => date( 'D, d M Y H:i:s O' ),
	'updated'                     => date( 'Y-m-d\TH:i:sP' ),
	'link'                        => 'https://test.com/job/test',
	'applylink'                   => 'https://test.com/apply/test',
	'magiclink'                   => '',
	'branche'                     => 'Test',
	'postalcode'                  => '1000',
	'city'                        => 'TEST CITY',
	'province'                    => 'Test Province',
	'provincecode'                => 'TEST',
	'country'                     => 'BE',
	'validfrom'                   => date( 'Y-m-d\TH:i:s' ),
	'validtill'                   => date( 'Y-m-d\TH:i:s', strtotime( '+30 days' ) ),
	'channeltype'                 => '29998',
	'functiongroup'               => 'Test Services',
	'functiongroup2'              => '',
	'functiongroup3'              => '',
	'functiongroupid'             => '1',
	'functiongroupid2'            => '0',
	'functiongroupid3'            => '0',
	'function'                    => 'Test Function',
	'function2'                   => '',
	'function3'                   => '',
	'functionid'                  => '1',
	'functionid2'                 => '0',
	'functionid3'                 => '0',
	'functiontitle'               => 'TEST',
	'functiondescription'         => '<p>This is a test job function.</p>',
	'education'                   => 'Bachelor',
	'education2'                  => 'Bachelor',
	'education3'                  => 'Bachelor',
	'educationid'                 => '1',
	'educationid2'                => '1',
	'educationid3'                => '1',
	'educationgroup'              => 'Bachelor',
	'educationgroup2'             => 'Bachelor',
	'educationgroup3'             => 'Bachelor',
	'educationgroupcode'          => '001',
	'educationgroupcode2'         => '001',
	'educationgroupcode3'         => '001',
	'jobtype'                     => 'Full-time',
	'jobtypecode'                 => 'FULL',
	'jobtypegroup'                => 'Permanent Contract',
	'jobtypegroupcode'            => '001',
	'contracttype'                => 'Employee',
	'contracttype2'               => '',
	'contracttype3'               => '',
	'contracttypecode'            => '20',
	'contracttypecode2'           => '',
	'contracttypecode3'           => '',
	'experience'                  => 'No experience required',
	'experiencecode'              => '001',
	'brand'                       => 'Test Company',
	'accountid'                   => '123456',
	'internal'                    => 'false',
	'payrollid'                   => '123456',
	'payroll'                     => 'Test Payroll',
	'brancheid'                   => '1',
	'label'                       => 'Test Label',
	'labelid'                     => '1',
	'language'                    => 'English',
	'language2'                   => '',
	'language3'                   => '',
	'languagecode'                => '1',
	'languagecode2'               => '',
	'languagecode3'               => '',
	'languagelevel'               => 'Good',
	'languagelevel2'              => '',
	'languagelevel3'              => '',
	'languagelevelcode'           => '3',
	'languagelevelcode2'          => '',
	'languagelevelcode3'          => '',
	'office'                      => 'Test Office',
	'officeid'                    => '1',
	'officestreet'                => 'Test Street',
	'officehousenumber'           => '1',
	'officeaddition'              => '',
	'officepostalcode'            => '1000',
	'officecity'                  => 'TEST CITY',
	'officetelephone'             => '+32 123 456 789',
	'officeemail'                 => 'test@test.com',
	'hours'                       => '40',
	'salaryfrom'                  => '30000',
	'salaryto'                    => '40000',
	'salarytype'                  => 'per year',
	'salarytypecode'              => '1',
	'parttime'                    => 'false',
	'offerdescription'            => '<p>Test job offer description.</p>',
	'requirementsdescription'     => '<p>Test job requirements.</p>',
	'reference'                   => 'TEST001',
	'shift'                       => 'Day shift',
	'shiftcode'                   => '1',
	'driverslicense'              => '',
	'driverslicenseid'            => '0',
	'publicationlanguage'         => 'EN',
	'companydescription'          => '<p>Test company description.</p>',
	'job_title'                   => 'TEST',
	'job_slug'                    => 'test-job',
	'job_link'                    => 'https://test.com/job/test',
	'job_salary'                  => '€30000 - €40000',
	'job_apply'                   => 'https://test.com/apply/test',
	'job_icon'                    => '<i class="fas fa-briefcase"></i>',
	'job_car'                     => '',
	'job_time'                    => 'Full-time',
	'job_description'             => 'Test job description',
	'job_remote'                  => '',
	'job_meal_vouchers'           => '',
	'job_flex_hours'              => '',
	'job_skills'                  => array(),
	'job_posting'                 => '{}',
	'job_ecommerce'               => '{}',
	'job_languages'               => '<ul><li>English: Good (3/5)</li></ul>',
	'job_category'                => 'Test',
	'job_quality_score'           => 50.0,
	'job_quality_level'           => 'Average',
	'job_quality_factors'         => '{}',
	'job_quality_recommendations' => '[]',
);

echo "=== TESTING SINGLE JOB IMPORT ===\n";
echo 'Job GUID: ' . $test_job['guid'] . "\n";
echo 'Job Title: ' . $test_job['title'] . "\n\n";

// Test the job processing logic without WordPress
echo "Testing job processing logic...\n\n";

try {
	// Test if the job processing functions are available
	if ( function_exists( 'process_single_job_item' ) ) {
		echo "✅ process_single_job_item function is available\n";

		// Test the function with our test job
		$result = process_single_job_item( $test_job );

		if ( $result['success'] ) {
			echo "✅ Job processing successful!\n";
			echo 'Result: ' . json_encode( $result, JSON_PRETTY_PRINT ) . "\n";
		} else {
			echo "❌ Job processing failed!\n";
			echo 'Error: ' . ( $result['error'] ?? 'Unknown error' ) . "\n";
		}
	} else {
		echo "❌ process_single_job_item function not found\n";
		echo 'Available functions: ' . implode( ', ', get_defined_functions()['user'] ) . "\n";
	}

	// Test batch processing functions
	if ( function_exists( 'process_batch_items_with_metadata' ) ) {
		echo "\n✅ process_batch_items_with_metadata function is available\n";

		// Test with a single-item batch
		$batch_result = process_batch_items_with_metadata( array( $test_job ) );

		if ( $batch_result['success'] ) {
			echo "✅ Batch processing successful!\n";
			echo 'Processed: ' . ($batch_result['processed'] ?? 0) . " items\n";
			echo 'Result: ' . json_encode( $batch_result, JSON_PRETTY_PRINT ) . "\n";
		} else {
			echo "❌ Batch processing failed!\n";
			echo 'Error: ' . ( $batch_result['error'] ?? 'Unknown error' ) . "\n";
		}
	} else {
		echo "\n❌ process_batch_items_with_metadata function not found\n";
	}
} catch ( Exception $e ) {
	echo "❌ Exception during testing:\n";
	echo 'Error: ' . $e->getMessage() . "\n";
	echo 'File: ' . $e->getFile() . ':' . $e->getLine() . "\n";
	echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
} catch ( Error $e ) {
	echo "❌ Fatal error during testing:\n";
	echo 'Error: ' . $e->getMessage() . "\n";
	echo 'File: ' . $e->getFile() . ':' . $e->getLine() . "\n";
	echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== TEST COMPLETE ===\n";
