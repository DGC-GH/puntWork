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

7. **Fix Critical Issues Locally**: Address any log errors, 500 errors, class loading failures, warnings or import blocks.

8. **Find Comprehension Gaps**: Locate unclear logic, missing docs, complex algorithms.

9. **Analyze Data Flow**: Map import/export patterns and integration points.

10. **Review Error Handling**: Identify fragile code sections needing improvement.

11. **Optimize Performance**: Address memory usage, response times, CPU utilization.

12. **Enhance Error Handling**: Add comprehensive error recovery and logging.

13. **Refactor Complex Code**: Break down large functions, improve readability.

14. **Add Debug Logging**: Implement AI-readable logging for future analysis.

15. **Run Analysis**: Execute `php evolution-helper.php analyze`.

16. **Apply Improvements**: Execute `php evolution-helper.php apply`.

17. **Record Metrics**: Track all steps with `php evolution-helper.php record <step> <success> <duration>`.

18. **Check Performance**: Ensure no degradation in speed or memory usage.

19. **Validate AJAX**: Confirm all AJAX endpoints work without 500 errors.

20. **Regression Test**: Run existing functionality to ensure no breakage.

21. **Update Documentation**: Update CHANGELOG.md, and README.md if needed.

22. **Fix Errors Locally**: Implement code fixes for identified issues in the local development environment.

23. **Commit Changes**: Commit the local fixes to the git repository with descriptive commit messages.

24. **Deployment Policy**: NEVER upload files directly to the server. All changes MUST go through git commit and push for auto-deployment. Direct FTP uploads are prohibited to maintain version control and deployment consistency.

25. **Wait for Validation**: Pause protocol execution and wait for user validation of the changes.

26. **Push Changes**: Push the committed changes to the remote repository to trigger auto-deployment.

27. **Verify on Server**: Deploy and verify that the fixes resolve issues on the server.

## AI Learnings from Previous Conversations

- Need better context provision for AI comprehension
- AI suggestions are being accepted and implemented

**EXECUTION COMPLETE**: Report final status and any issues encountered.




<!-- Last improved by Protocol Evolution Engine on 2025-10-01 09:01:01 based on conversation learnings -->