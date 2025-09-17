# puntWork Project Overview

## Core Concept
puntWork is a WordPress plugin for automating job listings import from XML feeds (e.g., startpeople.be, unique.be) into a custom 'job' post type. Integrates ACF for fields (title, salary, location), admin UI for feed management, AJAX imports, data cleaning/inference, and JSON export. Targets Belgian job market (NL/FR/EN locales).

## Key Components
- **Feeds**: 'job-feed' CPT with ACF 'feed_url'.
- **Processing**: Download/cache XML, parse jobs, infer details (e.g., salary estimates), upsert to 'job' CPT.
- **UI**: Admin table with manual/full import buttons, progress via transients/Heartbeat.
- **Exports**: JSON dump of all jobs post-import.
- **Tech**: PHP 8+, JS (jQuery), ACF Pro, WP Cron for scheduled runs.

## Milestones
- v0.1: Basic XML parse/import.
- v0.2: AJAX manual/full imports + logging (recent fix).
- v0.3: Scheduled cron, multi-lang inference, SEO slugs.

## Dependencies
- ACF Pro
- WP 6.0+

---
**GROK-NOTE: iteration: 2 | date: 2025-09-17 | section: overview-evolution**
key-learnings:
  - Plugin fixes focused on JS-PHP mismatches; next: Add cron scheduling.
  - Efficiency: Use this block to bootstrap project context in convos.
pending:
  - Integrate SEO plugin hooks for job slugs.
  - Test multi-feed concurrency.
efficiency-tip: "In future convos, query 'puntWork overview' to load this; chain to progress-log.md for latest."
prior-iteration-ref: Iteration 1 (initial plugin bug fix).
