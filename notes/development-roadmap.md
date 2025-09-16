# Development Roadmap

## Vision
Turn raw job feeds into seamless WP posts with zero-touch batches, scaling to enterprise via cron/AJAX. Profitable leveraging X Ads and other sources to generate traffic for affiliate marketing referrers, payed per succesful application on external website with bonus per hire, by 2026.
Generate paid refferrers to external application forms, with pay per conversion and bonus per hire, premium add-ons and/or SAAS for recruiting companies marketing departments.
Straight into the bright, exciting future!

## Phase 1: Core Development (Completed - 2025-08-15)
- Set up WP plugin structure.
- Implement basic feed download and XML parsing in snippets.
- Milestone: First manual import success.

## Phase 2: Refactor and Modularize (Completed - 2025-09-16)
- Migrate all snippets into /includes (e.g., processor.php for batch logic).
- Add admin UI, AJAX, scheduling, shortcode.
- Clean up: Merge duplicates, remove snippets folder.
- Milestone: Full plugin activation with no errors; features parity confirmed.

## Phase 3: Testing & Optimization (In Progress - Target: 2025-10-01)
- Unit tests for processor functions (duplicates, inference).
- Performance benchmarks: Import 1000+ items.
- Error handling: Resume on failures, email alerts.
- Milestone: 100% test coverage; optimize batch size dynamically.

## Phase 4: Enhancements (Planned - 2025-10-15)
- Multi-feed support, JSONL primary parsing.
- Frontend shortcodes for job listings.
- Integration with WP plugins (e.g., Yoast SEO for jobs).

## Phase 5: Deployment (Planned - 2025-11-01)
- Release to WP.org or GitHub.
- Documentation: User guide, API hooks.
- Maintenance: Version 1.1 with bug fixes.

| Phase | Status | Start | End | Key Deliverables |
|-------|--------|-------|-----|------------------|
| 1     | Done   | 2025-08-01 | 2025-08-15 | Basic import script |
| 2     | Done   | 2025-08-16 | 2025-09-16 | Modular plugin |
| 3     | In Progress | 2025-09-17 | 2025-10-01 | Tests & perf |
| 4     | Planned | 2025-10-02 | 2025-10-15 | Features |
| 5     | Planned | 2025-10-16 | 2025-11-01 | Release |
