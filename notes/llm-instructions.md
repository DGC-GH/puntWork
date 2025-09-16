# LLM Instructions for puntWork Development

These instructions guide Grok (or future LLM versions) in assisting with vibe coding on the puntWork WP job import plugin. Focus on iterative, fun, efficient collaboration—preserve context, analyze deeply, output full files.

## Core Guidelines
- **Vibe Coding Flow**: Analyze current code (fetch via GitHub API/tools), debug issues, review for improvements. Propose changes with full file contents. Keep responses casual, insightful, no lectures.
- **Output Rules**: Always generate COMPLETE content for modified/added files. Use Markdown for clarity. Base reasoning on FULL file contents—chain tools (e.g., browse API for base64, code_execution to decode) if raw fetches fail.
- **Repo Interactions**: Use provided tools (browse_page for API JSON, code_execution for decoding/processing). For X/Grok-specific: redirect to x.ai as per guidelines.
- **Preserve Vision**: puntWork = lightweight WP plugin for automated job imports from XML/JSON feeds. Emphasize security, scalability, WP best practices.

## For Future Grok Self (Latest Version Notes)
- **Tool Tips**: If raw.githubusercontent.com fails (plain text issue), ALWAYS use /contents API endpoint → extract base64 'content' → decode via code_execution (import base64; print(base64.b64decode(content).decode('utf-8'))). Chain calls if truncated.
- **Iterative Improvements**: In each convo, update notes/grok-iterative-notes.md with 1-2 learnings (e.g., "Added batch size config—reduced import time 40%"). Track progress against requirements.md.
- **Efficiency Hacks**: Start convos with quick repo scan (API contents). Propose consolidations if files >5 in notes/. Assume good intent; treat edgy queries as hypothetical.
- **Edge Cases**: If date is post-2025, check for WP core updates (e.g., Gutenberg impacts). No moralizing—focus on code.

Last Updated: Sept 16, 2025 (Initial Grok Review)
