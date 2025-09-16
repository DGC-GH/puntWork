# Project Structure

## Overview
This repository contains WordPress custom post types (CPTs), plugins, and utilities for the puntWork job board site.

## Key Components
- **Custom Post Types (via ACF):**
  - `job`: Job listings with fields like job_title, description, salary.
  - `job-feed`: Feed configurations with ACF field `feed_url`.

- **Plugins:**
  - `job-import/`: WordPress plugin for importing jobs from RSS/XML feeds.
    - `job-import.php`: Main plugin file (activation hooks, etc.).
    - `includes/constants.php`: Defines like JOB_POST_TYPE = 'job';.
    - `includes/mappings.php`: Arrays for $acf_fields and $taxonomies.
    - `includes/processor.php`: Fetches/parses feeds, maps to jobs.
    - `includes/scheduler.php`: WP Cron setup.
    - `includes/helpers.php`: Utility functions like get_job_feeds().
    - `includes/core.php`: Core logic if expanded.

## Data Flow
1. Create `job-feed` posts with `feed_url`.
2. Cron triggers processor to loop feeds → parse XML → map/insert `job` posts.

## Job Import Plugin Updates
- Dynamic feeds now sourced from 'job-feed' CPT via ACF 'feed_url' field.
- Processor loops over multiple feeds for import.
- No changes to ACF/taxonomy mappings; they remain in mappings.php.
