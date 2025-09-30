# Maintenance Protocol - AI Execution Prompt

**INSTRUCTION FOR GROK CODE FAST 1 COPILOT AGENT:**

Execute the following maintenance protocol steps in strict numerical order. Do not skip any steps. For each step, perform the required action, record the outcome, and confirm completion before proceeding to the next step. If a step fails, stop execution and report the failure.

## Protocol Steps:

1. **Verify Existing CPTs**: Confirm that only existing custom post types are used:
   - `post_type='job'` - for job listings (created with ACF)
   - `post_type='job-feed'` - for feed configurations (created with ACF)

2. **Verify Taxonomies**: Confirm that existing taxonomies associated with these CPTs are being used.

3. **Verify ACF Fields**: Confirm that existing Advanced Custom Fields configurations are respected.

4. **Download Logs**: Run `ftp_script.txt` to download current debug.log from the server.

5. **Analyze Logs**: Run `analyze-import-logs.sh` for error patterns and performance metrics.

6. **Check Console**: Read Console.txt for client-side errors.

7. **Fix Critical Issues**: Address any 500 errors, class loading failures, or import blocks.

8. **Map Codebase**: Identify all classes, functions, and dependencies.

9. **Find Comprehension Gaps**: Locate unclear logic, missing docs, complex algorithms.

10. **Analyze Data Flow**: Map import/export patterns and integration points.

11. **Review Error Handling**: Identify fragile code sections needing improvement.

12. **Optimize Performance**: Address memory usage, response times, CPU utilization.

13. **Enhance Error Handling**: Add comprehensive error recovery and logging.

14. **Refactor Complex Code**: Break down large functions, improve readability.

15. **Add Debug Logging**: Implement AI-readable logging for future analysis.

16. **Run Analysis**: Execute `php evolution-helper.php analyze`.

17. **Apply Improvements**: Execute `php evolution-helper.php apply`.

18. **Record Metrics**: Track all steps with `php evolution-helper.php record <step> <success> <duration>`.

19. **Check Performance**: Ensure no degradation in speed or memory usage.

20. **Validate AJAX**: Confirm all AJAX endpoints work without 500 errors.

21. **Regression Test**: Run existing functionality to ensure no breakage.

**EXECUTION COMPLETE**: Report final status and any issues encountered.
