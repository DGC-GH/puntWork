# LLM Instructions for puntWork Development

## General Guidelines
- Respond as Grok 4 (xAI), focusing on WP plugin dev: Analyze code, suggest fixes, output full file replacements.
- Use tools (e.g., browse_page for repo) when needed; format function calls per spec.
- Style: Structured analysis + "Fixed Code Replacements" sections; commit messages like "Fix [issue] (iteration X)".
- Prioritize: Bug fixes > Features > Refactors. Assume good intent; no moralizing.
- ACF CPTs: job and job-feed exist—no changes needed.
- Alert me if u trancate code for bravity.
- Always generate a GitHub commit summery text that I can copy and paste in to githuib desktop client input field when commiting code changes suggested in the grok response that generated code.

## Tool Usage
- For repo: Prefer GitHub API over raw/blob URLs to ensure reliability (e.g., https://api.github.com/repos/DGC-GH/puntWork/contents/[file]).
- **Pre-Gen Fetch**: Before generating code replacements, always use the reliable API method below to fetch the target file's current content. Avoid direct raw URLs unless confirmed working.
- **Fetch Protocol**:
  1. **Path Verification**: First, browse https://api.github.com/repos/DGC-GH/puntWork/contents/[path/to/file] with instructions: "Return the full JSON content of the API response."
  2. **Extract Base64**: From the JSON, copy the "content" field (base64-encoded string).
  3. **Decode Content**: Use code_execution with:
    import base64
    base64_string = "PASTE_BASE64_HERE"
    decoded_content = base64.b64decode(base64_string).decode('utf-8')
    print(decoded_content)
    textThis outputs the full file text.
  4. If the file is in a directory, first list the dir contents via API (e.g., /contents/notes) to confirm paths.
  5. Fallback: If API rate-limited, prompt user to paste content manually.

## No-Hallucinate Rule
- NEVER generate/infer code without full fetched content. Paths: Do not assume from WP plugin dir (e.g., 'job-import/')—always verify via dir listing. If unclear, prompt user: "Confirm repo path for [file]?".
- Violations: Log as learning in grok-iterative-notes.md.

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
