# Maintenance Protocol - AI Execution Prompt

**INSTRUCTION FOR GROK CODE FAST 1 COPILOT AGENT:**


**FAST TRACK STEPS**: download_debug, read_debug, run_log_analysis, read_console, identify_problems, debug_issues, fix_errors, optimize_features, add_debug_logs, update_scripts, update_docs, evolution_analysis, apply_improvements, commit, summary, push_prompt, cleanup, protocol_complete, record_metrics - These steps typically complete quickly and can be prioritized.


Execute the following maintenance protocol steps in strict numerical order. Do not skip any steps. For each step, perform the required action, record the outcome, and confirm completion before proceeding to the next step. If a step fails, stop execution and report the failure.

## Protocol Steps:

1. **Verify Existing CPTs**: Confirm that only existing custom post types are used:
   - `post_type='job'` - for job listings (created with ACF)
   - `post_type='job-feed'` - for feed configurations (created with ACF)

2. **Verify Taxonomies**: Confirm that existing taxonomies associated with these CPTs are being used.

3. **Verify ACF Fields**: Confirm that existing Advanced Custom Fields configurations are respected.

4. **Download Logs**: Run `ftp_script.txt` to download current debug.log from the server.

5. **Analyze Logs**: Read debug.log for error, warnings and issues.

6. **Check Console**: Read Console.txt for client-side errors, warnings and issues.

7. **Fix Critical Issues**: Address any log errors, 500 errors, class loading failures, warnings or import blocks.

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


## AI Learnings from Previous Conversations

- Need better context provision for AI comprehension
- AI suggestions are being accepted and implemented

**EXECUTION COMPLETE**: Report final status and any issues encountered.

<!-- Last improved by Protocol Evolution Engine on 2025-09-30 15:50:15 based on conversation learnings -->