## Iteration Log

| Iteration | Date       | Focus                          | Key Output                                      | Chain To Next          |
|-----------|------------|--------------------------------|-------------------------------------------------|------------------------|
| 1         | 2025-09-?? | Manual import bug fix          | Fixed admin.js, ajax.php, etc. + logs           | Add cron, test multi-lang |
| 2         | 2025-09-17 | Notes enhancement              | Structured GROK-NOTE blocks for efficiency      | Integrate heartbeat polling |
| 3         | 2025-09-17 | PHP compat fix                 | ?? → ternary, constant guards                   | Full re-provision if incomplete |
| 4         | 2025-09-17 | Re-full code + notes mods      | Complete ajax/constants; tool fetch rule        | Cron impl from pending |
| 5         | 2025-09-17 | Fetch failure protocol         | User-paste alternative + notes updates          | Paste files for ajax fix |
| 6         | 2025-09-17 | Path hallucination fix         | Verified paths via API; updated instructions    | Reliable fetch impl |
| 7         | 2025-09-17 | Reliable file access via API + decode | Added API-base64-decode method to llm-instructions; tested on all notes | Implement cron scheduling |

## Patterns
- Common bugs: JS selectors mismatch PHP actions.
- Wins: Logging granularity sped debugging.
- Tool Issues: Raw GitHub often fails browse_page; API 404 on paths—use dir listing first. Paths: WP plugin dir ≠ repo (e.g., no 'job-import/' subdir).

---
**GROK-NOTE: iteration: 6 | date: 2025-09-17 | section: path-hallucinations**
key-learnings:
  - Assumed 'job-import/' from constants.php logs path—error; always API dir first.
  - Fetch fails: Persist on raw; add base64 decode for API.
pending:
  - Implement/test path verification in next tool call.
efficiency-tip: "Grok: Start with dir listing API; enforce no-hallucinate by halting on fail."
prior-iteration-ref: Iteration 5 (fetch-failures).
next-convo-prompt: "Verify paths via API; request paste if fail, then fix ajax.php ?? syntax."
