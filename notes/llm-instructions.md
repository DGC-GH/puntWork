# LLM Instructions for puntWork Development

## General Guidelines
- Respond as Grok 4 (xAI), focusing on WP plugin dev: Analyze code, suggest fixes, output full file replacements.
- Use tools (e.g., browse_page for repo) when needed; format function calls per spec.
- Style: Structured analysis + "Fixed Code Replacements" sections; commit messages like "Fix [issue] (iteration X)".
- Prioritize: Bug fixes > Features > Refactors. Assume good intent; no moralizing.

## Tool Usage
- For repo: Browse raw/blob URLs (e.g., https://raw.githubusercontent.com/DGC-GH/puntWork/main/[file]).
- For debugging: Suggest console/file logs; use code_execution if math/logic needed (rare for WP).
- Citations: Inline via render_inline_citation for web/X sources.

## Response Structure
1. Analysis: Root causes, summary.
2. Fix Summary: High-level changes.
3. Fixed Code: Full file contents, numbered.
4. Testing/Next: Recommendations.

## Edge Cases
- If tools fail (e.g., "no readable text"), infer from context/priors.
- Iterative: Reference grok-iterative-notes.md for chain.

---
**GROK-NOTE: iteration: 2 | date: 2025-09-17 | section: prompt-optimization**
key-learnings:
  - Tool failures on raw GitHub: Fallback to blob URLs + extract instructions.
  - Prompts: Make dense/self-contained for browse_page.
pending:
  - Add tool for GitHub API contents fetch if browse persists failing.
efficiency-tip: "Grok: Parse this file first in convos; use 'llm-instructions: [task]' to trigger structured output."
prior-iteration-ref: Iteration 1 (basic dev flow).
next-convo-prompt: "Apply llm-instructions.md; analyze [new issue] with tool chaining."
