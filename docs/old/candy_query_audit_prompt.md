# Supervisor startup prompt — candy-query remediation

Paste the block below into a fresh session to launch the supervisor.

---

You are the **supervisor** for the candy-query remediation effort in this repo
(`/home/sites/sugarcraft`, lib `candy-query`, namespace `SugarCraft\Query\`).

Read **only** `plans/candy-query-fix/SUPERVISOR.md` and follow it exactly. Also read
`plans/candy-query-fix/updates.md` (the shared scratchpad) between steps. Do **not** read the
individual step files or the between-step files, and do **not** investigate or edit code
yourself — your job is solely to walk the handoff sequence in SUPERVISOR.md and spawn ONE
subagent at a time, telling each which instruction file to read.

Key rules (all detailed in SUPERVISOR.md):
- For each sequence entry, spawn the listed agent type (`oac:coder-agent` for real/fix/docs
  steps, `oac:code-reviewer` for review, `oac:test-engineer` for tests/CI). Tell it: "Read
  `plans/candy-query-fix/COMMON.md` first, then read `<instruction file>` and execute it fully."
- After **every real step**, run the four between-steps in order: REVIEW → FIX → TESTS_CI →
  DOCS (templates under `plans/candy-query-fix/between/`). A phase's final step's between-block
  is scoped to the whole phase (phase closeout).
- Spawn **serially** — never two write-capable subagents at once.
- After each subagent, read `updates.md`. Resolve any `BLOCKER:` before advancing. For
  `RESEARCH NEEDED:`, **you** spawn a `general-purpose` researcher (subagents cannot), write the
  findings back as `RESEARCH FINDINGS:`, then re-spawn the blocked step.
- Subagents commit → PR → merge → pull → end on `master`. Remind each that it MUST run
  `unset GITHUB_TOKEN` immediately before EVERY `gh` command. Do NOT run `caliber refresh`.
- Author all commits as `Joe Huss <detain@interserver.net>`.
- `updates.md` and everything under `plans/` are untracked orchestration scratch — never let a
  subagent `git add` them.

The full plan and findings (for your awareness only — you don't act on them directly) live in
`candy_query_audit.md` PART 2. Baseline before any work: **1112 tests green, 1 skipped.**

Begin with sequence entry **1** (Phase 1, STEP 1.1 — admin key routing) and proceed through the
sequence in SUPERVISOR.md until step 23 and its between-block are complete, then report a final
summary (phases completed, PRs merged, items deferred) and stop.

---

## Notes for the human kicking this off
- This is a long, multi-PR run (23 real steps × up to 4 between-steps each ≈ 80–100 subagent
  tasks, one PR per write-task). Expect it to span multiple sessions; the supervisor resumes by
  re-reading `SUPERVISOR.md` + `updates.md` and continuing from the first incomplete sequence
  entry (check `git log` / merged PRs to locate it).
- If you want a shorter run, tell the supervisor to stop after a given phase (e.g. "stop after
  Phase 1" — that alone resurrects most of the admin UI).
- Watch `updates.md` for `BLOCKER:`/`RESEARCH NEEDED:` if you want to intervene; otherwise the
  supervisor handles research spawns itself.
