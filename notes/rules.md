# Coding Rules

## PHP/WordPress
- Use strict typing where possible (PHP 7.4+).
- All functions: Prefix with job_import_ for namespacing.
- Security: wp_verify_nonce() in AJAX, sanitize_* for inputs.
- Logging: Use job_import_log() for all errors/info; level filter.

## JS/CSS
- jQuery for admin; no vanilla unless simple.
- CSS: WP colors (#0073aa blue); responsive @media.
- Minify assets for production.

## General
- Commit messages: "feat: Add batch processing" or "fix: Merge heartbeat duplicates".
- .gitignore: .DS_Store, logs/*, node_modules.
- Tests: Cover 80%+; use WP_UnitTestCase.
