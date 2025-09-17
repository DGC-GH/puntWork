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
