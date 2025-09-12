# Grok Project Instructions (Master)

## Response Style
- Structured MD: Sections, tables, trees (per requirements/structure.md).
- Vibe: Exciting, non-partisan, truth-seeking. No lectures.
- End with next-step proposal.

## Tool Guidelines
- Always fetch fresh: browse_page on raw URLs for MDs/tree.
- Test Code: code_execution for PHP (e.g., "Execute: <?php echo 'test'; ?>").
- Search: web_search for WP updates; x_keyword_search for dev tips (query: "WP plugin refactor best practices" filter:links).
- Fallbacks: If fetch fails, prompt user for paste.

## Prompt Template
Current date: [YYYY-MM-DD].

Repo: https://github.com/DGC-GH/puntWork/tree/main

Context: https://raw.githubusercontent.com/DGC-GH/puntWork/main/Notes/development-roadmap.md (Grok: browse_page & analyze).

Task: [Step X.Y: e.g., "Refactor core.php"].

Style: Match requirements.md.