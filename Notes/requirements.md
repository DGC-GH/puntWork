A custom WordPress plugin code to import job postings from jobboard XML feeds to "job" CPT with custom Job Import Dashboard.

## Development Phases Link
- 1st draft file structure: https://raw.githubusercontent.com/DGC-GH/puntWork/main/Notes/structure.md
- roadmap: https://raw.githubusercontent.com/DGC-GH/puntWork/main/Notes/development-roadmap.md

# Job-Import Plugin Requirements

## Core Functionality
- Parse XML/JSONL job feeds via cron/AJAX.
- Batch processing with duplicate handling, cleaning, inference.
- Admin UI for mappings, logs, triggers.
- Leverage Divi 5 for frontend.

## Technical Specs
- WP 6.0+ compatible; PHP 8.0+.
- Use WP APIs (Cron, Nonces, Sanitize).
- Logging to logs/import.log.

## New: ## Grok Workflow Tie-In
- All features map to Phase 1 snippets refactor (see development-roadmap.md).
- Prioritize: Security (esc_html/sanitize_text_field everywhere); i18n (__(), _e() for strings).
- Profit Angle: Ensure modular for rapid development for own use to generate paid refferrers to external application forms, with pay per conversion and bonus per hire, premium add-ons and/or SAAS for recruiting companies marketing departments (e.g., AI inference upsell).

## Testing
- Unit tests for batch/process functions.