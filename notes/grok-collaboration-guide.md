# Grok Collaboration Guide for puntWork

## Core Prompt Template
When querying Grok for code/help:
"Context: puntWork WP job import plugin. [Reference relevant .md file]. Task: [Specific, e.g., 'Write PHP function for CSV parsing with field mapping']. Output: Code snippet + explanation + tests. Style: Follow coding-standards.md."

## Key Learnings from Iterative Sessions
- **Iteration 1 (CSV Import):** Grok excels at generating parsers but over-engineered; always specify "minimal viable" to avoid bloat. Learning: Use `fgetcsv()` over libs for speed.
- **Iteration 2 (Admin UI):** Vue components need WP enqueuing hooks. Tip: Ask for "WP-integrated Vue setup" to get `wp_enqueue_script` right.
- **Iteration 3 (API Integration):** Handle rate limits with transients. Learning: Request "error-resilient Guzzle client" for robustness.
- **Iteration 4 (Deduplication):** Use WP_Query for uniqueness checks. Tip: Combine with "performance optimization" for indexed queries.
- **Common Pitfalls Avoided:** Grok may assume non-WP env; always prefix "WordPress plugin context". For debugging: "Simulate error X and suggest fix."
- **Efficiency Tips:** Chain queries (e.g., "Refactor previous code for Y"). Use tables for comparisons in responses.

## Response Preferences
- Code: Block-formatted, ready-to-paste.
- Explanations: Bullet points, step-by-step.
- No lectures; focus on "vibe coding" – fun, iterative, optimal.

Update this file after major sessions to retain vibe.


## Learnings from Iterations
- Specify "minimal viable" to avoid over-engineering (e.g., fgetcsv() for speed).
- Always include "WP plugin context" for enqueuing/hooks.
- For APIs: Request "error-resilient Guzzle" w/ transients.
- Dedupe: Use WP_Query; add "perf opt" for indexes.
- Debug: "Simulate error X, suggest fix."
- Efficiency: Chain queries; use tables for comparisons.
- Repo Fetch Reliability: GitHub raw/blob tools fail on plain text/HTML mismatch; chain API call + base64 decode in code_execution (if internet enabled) or prompt for manual paste. Test: Simulate fetch error, output 'UNRELIABLE_FETCH' flag.


# LLM Instructions for puntWork Collaboration

## Core Guidelines
- Context: Always prepend "puntWork WP job import plugin" to queries.
- Output Format: Code snippet (block-ready) + bullet explanation + PHPUnit stub.
- Enforce: WP Coding Standards; namespaces (Puntwork\); esc_html all.

## Tool Usage
- For code gen: Reference includes/[file].php; suggest hooks.
- Repo Access: Use raw URLs for content; if fail, flag 'FETCH_ERROR' and suggest manual.

## Tool Reliability for Repo Fetches
For file retrieval, prioritize GitHub API JSON (base64 decode content); fallback to web_search_with_snippets on exact raw URL if API rate-limited. Avoid raw.githubusercontent.com direct in browse_page—use blob HTML extraction as last resort. Example: browse_page on https://api.github.com/repos/DGC-GH/puntWork/contents/notes/llm-instructions.md, instructions: "Parse JSON, base64 decode 'content' field to raw MD."

## Preferences
- Minimal deps; perf-first (transients/cache).
- No over-engineering: MVP > perfect.

Full: Align w/ grok-collaboration-guide.md.
