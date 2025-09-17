# Job Import Plugin Requirements

## Functional Requirements
### Import Mechanisms
- Support CSV, XML, JSON imports via file upload.
- Integrate with 3+ APIs (e.g., Indeed, Google Jobs) using OAuth/Keys.
- Handle bulk imports (up to 1000 jobs/batch) with progress indicators.

### Data Handling
- Auto-detect and map fields (title, description, salary, location, etc.).
- Deduplication based on job ID/URL.
- Custom post type 'job_post' with taxonomies (category, type: full-time/part-time).

### Admin UI
- Dashboard page with import history, error logs.
- Settings page for API keys, cron schedules.
- Export jobs to CSV.

### Frontend
- Shortcode [puntwork_jobs] for listing.
- Single job template with apply button.

## Non-Functional Requirements
- Performance: Imports <5s for 100 jobs; cache results.
- Security: Sanitize inputs, nonce all forms, GDPR-compliant data handling.
- Compatibility: WP 6.0+, themes like Astra/GeneratePress.
- Accessibility: WCAG 2.1 AA for UI.

## User Stories (Prioritized)
1. As admin, I can upload CSV and map fields to import jobs.
2. As admin, I can schedule daily imports from API.
3. As visitor, I can view/filter jobs on frontend.

For Grok: Use this for feature validation during code reviews.
