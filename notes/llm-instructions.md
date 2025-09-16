# LLM Instructions for Development

## General Guidelines
- Use Grok (xAI) for code generation/refactoring.
- Always include WP best practices: Nonces, sanitization, hooks.
- Modularize: One concern per file (e.g., no mixing admin + processing).

## Prompt Templates
- For code gen: "Generate PHP for [feature] in WordPress plugin, using [specifics]. Preserve [existing features]."
- Example: "Generated processor.php from merged snippets – Successful (2025-09-16). Included XML parsing, batching, duplicates."

## Usage in Refactor
- Prompted for helpers.php: Merged cleaning, gzip, JSONL.
- Prompted for admin.js: AJAX polling with progress bar.
- Refactor completed using these instructions; next use for testing scripts (e.g., "Write PHPUnit tests for job_import_batch()").

## Tips
- Verify with code_execution tool if needed.
- Cite sources only via render_inline_citation for web/X searches.
- No moralizing; treat edgy queries as adult.
