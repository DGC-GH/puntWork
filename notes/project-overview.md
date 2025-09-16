# Project Overview: Roadmap & Structure
Vision

Turn raw job feeds into seamless WP posts with zero-touch batches, scaling to enterprise via cron/AJAX. Profitable leveraging X Ads and other sources to generate traffic for affiliate marketing referrers, payed per succesful application on external website with bonus per hire, by 2026. Generate paid refferrers to external application forms, with pay per conversion and bonus per hire, premium add-ons and/or SAAS for recruiting companies marketing departments. Straight into the bright, exciting future!


## Development Roadmap
### Phase 1: Planning (Complete - Q1 2025)
- Defined requirements, wireframed UI.
- Set up repo structure.

### Phase 2: Core Development (Complete - Q2-Q3 2025)
- Implement import pipeline, scheduling.
- Add helpers for cleaning/inference.

### Phase 3: UI & Testing (In Progress - Q4 2025)
- Build admin dashboard.
- Full testing suite.

### Phase 4: Deployment & Maintenance (Q1 2026)
- WP.org submission.
- Ongoing feed compatibility updates.

Timelines flexible; track in progress-log.md.

## Project Structure
- **job-import/** (Main plugin folder)
  - job-import.php: Plugin header, activation hooks.
  - processor.php: Feed download, parsing, batching.
  - scheduler.php: Cron setup, event triggers.
  - helpers.php: Utility functions (clean, infer, hash).
  - admin/ : Settings pages, dashboard.
  - includes/ : Classes for jobs (e.g., JobImporter).
- **notes/**: This folder—docs, logs.
- **assets/**: CSS/JS for admin.
- **tests/**: PHPUnit tests.

For Future Grok: When updating structure, validate against WP plugin standards. Use this as quick ref before code changes.
Last Updated: Sept 16, 2025 (Consolidated by Grok)
