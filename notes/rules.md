# Coding Rules

## PHP
- PSR-12 compliant; no globals except WP funcs.
- Functions: Single responsibility; docblocks with @param/@return.
- WP Best: Use transients for state; wp_remote_get for HTTP.
- Errors: wp_send_json_error; log via custom function.

## JS
- jQuery strict; no conflicts (use 'use strict').
- AJAX: Always nonce, error handling (.fail()).
- Selectors: Data attrs over IDs for dynamic rows.

## General
- Commits: Semantic ("Fix [file]: [desc] (iter X)").
- Version: Semantic (v0.2.1); tag releases.
- Tests: Manual (console logs); add unit post-MVP.

## Repo
- Branches: main only; PRs for features.
- Files: Full replacements in responses; no diffs.

---
**GROK-NOTE: iteration: 2 | date: 2025-09-17 | section: rules-enforcement**
key-learnings:
  - Rules reduced bugs (e.g., nonce addition fixed security).
pending:
  - Add 'Testing: PHPUnit stubs for WP mocks'.
efficiency-tip: "Grok: Validate suggested code against this before output."
prior-iteration-ref: Iteration 1 (JS rules applied in admin.js fix).
next-convo-prompt: "Adhere to rules.md; suggest cron impl with full file output."
