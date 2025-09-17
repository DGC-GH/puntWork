# Job Import Plugin Requirements

## Functional
- [x] Fetch/parse XML feeds (VDAB/Actiris formats).
- [x] Admin UI: Table of feeds, manual/full import buttons.
- [x] Data cleaning/inference: Salary estimates, enhanced titles/slugs.
- [ ] Scheduled imports: WP Cron daily, skip if run today.
- [ ] Export: JSON of all jobs post-batch.
- [ ] Multi-lang: Detect/enrich NL/FR/EN.

## Non-Functional
- Performance: Batch limit 50 jobs/feed; cache TTL 24h.
- Security: Nonces in AJAX; manage_options cap.
- Logging: import.log + console for debug.

## Tech Specs
- CPTs: 'job-feed', 'job'.
- Fields: ACF (feed_url, functiontitle, salary_estimate, etc.).
- Hooks: admin_enqueue, wp_ajax_*, init for CPTs.

---
**GROK-NOTE: iteration: 2 | date: 2025-09-17 | section: req-prioritization**
key-learnings:
  - Checklists track completion; integrate with progress-log.md.
pending:
  - Mark 'Export' done after v0.3; add 'Error handling: Retry failed downloads'.
efficiency-tip: "Grok: Filter unchecked reqs for next fix suggestions."
prior-iteration-ref: Iteration 1 (UI/AJAX reqs met).
next-convo-prompt: "From requirements: Implement unchecked 'Scheduled imports'; reference rules.md."
