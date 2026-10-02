# Brief: competitor deep-dive vs sugar-crush

You are one of 12 parallel research agents. Each studies ONE AI coding-agent / chat client and compares it to
**sugar-crush**, a PHP terminal AI agent chat client (like opencode / Claude Code) living at
`/home/sites/sugarcraft/sugar-crush` (src/, docs/, README.md).

## Inputs
- **sugar-crush baseline (READ THIS FIRST, fully):** `/home/sites/sugarcraft/prompt_kit/findings/crush-report/00-sugar-crush-baseline.md`
  — a 1,148-line, source-verified inventory that marks every sugar-crush feature LIVE / PARTIAL / DORMANT / ABSENT
  with `src/...:line` citations. Trust it as your starting point, but open sugar-crush source to verify any claim your
  recommendations hinge on (it is a code-reading inference in places).
- **Competitor source:** shallow clones live in `/home/sites/crush-research-repos/<name>/`. You may `git clone --depth 1`
  extra related repos into that same directory if the competitor's core lives elsewhere. You may also use WebFetch /
  web search for official docs.

## Rules
- READ-ONLY everywhere except your one output file. Do not modify sugar-crush or any repo. Do not commit.
- Write your report to the output path given in your task. Overwrite it if it already exists.
- Back claims with evidence: cite competitor files (`path/to/file.ts:line`) and quote the key prompt text, config
  defaults, thresholds and constants verbatim where they matter (e.g. the actual compaction prompt, the actual
  system-prompt template, token thresholds). Concrete beats generic.
- Focus on what the program does BEST. Find its standout ideas and explain exactly how they work.

## Required report structure (Markdown, be exhaustive — 500-1200 lines is fine)
1. **Overview** — what it is, language/stack, architecture, size, and the 5-10 things it is best at.
2. **Agent loop** — how a turn runs, streaming, tool-call parsing, parallel tools, step limits, retries, error
   recovery, cancellation, interrupts and mid-turn steering, doom-loop detection.
3. **Agents and sub-agents** — agent definitions and modes (plan/build/architect…), how sub-agents spawn, run
   (parallel? isolated context? own model?), return results, and whether parent and child can **communicate**
   while running (messages, mailboxes, shared todo lists, steering, resume). Background agents, multi-agent
   orchestration and workflows.
4. **Context handling and compaction** — token counting, window tracking, when and how compaction triggers, the
   exact summarisation prompt, what is kept or dropped, tool-output truncation and pruning, dedup, caching
   (prompt-cache breakpoints), and especially any **agent-controlled self-pruning or compaction of its own history**.
5. **Prompt generation** — exactly what is sent automatically each request: system prompt layers, env info, date,
   OS, cwd, git status, file tree or repo map, instruction files (AGENTS.md/CLAUDE.md/rules), memory, skills listing,
   tool schemas, reminders injected mid-conversation. Quote or paraphrase the real templates and say where they live.
6. **Memory** — persistent memory, scopes, auto-extraction, retrieval and relevance selection, storage format.
7. **Tools and editing** — tool roster, edit format (search/replace, unified diff, whole file, apply-patch), edit
   validation and fuzzy matching, lint/diagnostic feedback loops, LSP, shell execution (pty, background, timeouts),
   web, file-read limits.
8. **Git integration** — status in prompt, auto-commits, checkpoints and undo (shadow git?), diffs, worktrees,
   commit-message generation, PR flows.
9. **Extensibility** — skills, plugins, MCP (client/server, transports), hooks, custom commands, recipes, rules.
10. **Permissions and safety** — approval model, sandboxing, allow/deny lists, secret handling.
11. **UX** — TUI/GUI features worth copying (sessions, resume, share, diff review, todo display, cost and usage display).
12. **Comparison table** — rows = features, columns = competitor | sugar-crush status (LIVE/PARTIAL/DORMANT/ABSENT,
    citing the baseline) | gap.
13. **Recommended improvements for sugar-crush** — a prioritised list (P0/P1/P2). For each: the idea; why it matters;
    exactly how the competitor does it (file refs); how to implement it in sugar-crush (which `sugar-crush/src/...`
    files or classes to touch or extend — prefer WIRING existing dormant code over writing new code, since the project
    rule is "never remove dormant code — wire it instead"); rough effort (S/M/L).
14. **Problems in sugar-crush exposed by this comparison** — bugs, design flaws, risky defaults, missing safeguards.

Final reply to the orchestrator: ≤30 lines — the output path, the 3-5 standout ideas, and your top 8 recommendations
in one line each. The full detail belongs in the file.
