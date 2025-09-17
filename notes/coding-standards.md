# Coding Standards for puntWork

## PHP
- Follow WordPress Coding Standards (PHPCS with WPCS ruleset).
- Use namespaces: `namespace Puntwork;`.
- Functions: CamelCase, private methods prefixed with `_`.
- Error Handling: WP_DEBUG mode; log with `error_log()` or custom logger.
- Security: Always `esc_html()`, `sanitize_text_field()`, `wp_verify_nonce()`.

## JavaScript
- ES6+ with Babel if needed.
- Use Vue 3 for components in admin.
- Linting: ESLint with Airbnb style.
- Async: Await for API calls.

## General
- Indentation: 4 spaces (no tabs).
- Line Length: 100 chars max.
- Comments: PHPDoc for functions/classes.
- Version Control: Atomic commits, PRs for features; use `git flow`.
- Dependencies: Minimal; prefer WP core functions.

## Testing
- Unit: PHPUnit for PHP logic.
- E2E: Cypress for UI flows.
- Manual: Test on staging site.

Violations: Run `composer lint` pre-commit.

For Grok: Quote these in code gen requests to ensure consistency.
