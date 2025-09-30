# Maintenance Protocol - Actionable Edition

## CRITICAL CONSTRAINTS
- **EXISTING CPTs**: DO NOT create new custom post types - use existing ones:
  - `post_type='job'` - for job listings (created with ACF)
  - `post_type='job-feed'` - for feed configurations (created with ACF)
- **Taxonomies**: Use existing taxonomies associated with these CPTs
- **ACF Fields**: Respect existing Advanced Custom Fields configurations

## FAST TRACK - Immediate Actions
- **Download Logs**: Run `ftp_script.txt` to get current debug.log
- **Analyze Logs**: Run `analyze-import-logs.sh` for error patterns and performance metrics
- **Check Console**: Read Console.txt for client-side errors
- **Fix Critical Issues**: Address any 500 errors, class loading failures, or import blocks

## AI-FOCUSED - Code Analysis & Understanding
- **Map Codebase**: Identify all classes, functions, and dependencies
- **Find Comprehension Gaps**: Locate unclear logic, missing docs, complex algorithms
- **Analyze Data Flow**: Map import/export patterns and integration points
- **Review Error Handling**: Identify fragile code sections needing improvement

## AI-DRIVEN - Concrete Code Improvements
- **✅ FIXED: Post Type Bug**: Changed all `job_listing` references to `job` (existing CPT)
- **Fix Import Processing**: Ensure jobs are actually imported (not just counted)
- **Optimize Performance**: Address memory usage, response times, CPU utilization
- **Enhance Error Handling**: Add comprehensive error recovery and logging
- **Refactor Complex Code**: Break down large functions, improve readability
- **Add Debug Logging**: Implement AI-readable logging for future analysis

## EVOLUTION - Continuous Improvement
- **Run Analysis**: Execute `php evolution-helper.php analyze`
- **Apply Improvements**: Execute `php evolution-helper.php apply`
- **Record Metrics**: Track all steps with `php evolution-helper.php record <step> <success> <duration>`

## VALIDATION - Quality Assurance
- **✅ VALIDATED: Post Type**: Jobs now use correct `job` post type (not `job_listing`)
- **Test Imports**: Verify jobs are actually being created/updated
- **Check Performance**: Ensure no degradation in speed or memory usage
- **Validate AJAX**: Confirm all AJAX endpoints work without 500 errors
- **Regression Test**: Run existing functionality to ensure no breakage
