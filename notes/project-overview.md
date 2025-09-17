# puntWork Project Overview

## What is puntWork?
puntWork is a WordPress plugin for seamless job import and management. It allows users to pull job listings from external sources (e.g., APIs like Indeed, LinkedIn, or CSV uploads), customize fields, and integrate with WP themes for job boards. Target: Small businesses, recruiters, and career sites.

## Core Features
- **Import Sources:** REST APIs, RSS feeds, CSV/XML files.
- **Field Mapping:** Drag-and-drop UI to map external data to WP custom post types (e.g., 'job_post').
- **Automation:** Cron jobs for scheduled imports, webhooks for real-time.
- **UI/UX:** Admin dashboard for imports, frontend shortcodes/widgets for displaying jobs.
- **Extensions:** Hooks for premium add-ons (e.g., AI matching, email alerts).

## Tech Stack
- WordPress Plugin Boilerplate.
- PHP 8+, Composer for dependencies (e.g., Guzzle for HTTP).
- JS: Vue.js for admin UI.
- DB: Custom tables for job metadata, transients for caching.

## Development Phases
1. MVP: Basic CSV import + simple display.
2. Beta: API integrations + field mapping.
3. Release: Automation + frontend widgets.

## Quick Stats (as of Sep 2025)
- Version: 1.0-alpha
- Commits: Track in Git.
- License: GPL v2.

For Grok assistance: Reference this for high-level queries; use `requirements.md` for specs.
