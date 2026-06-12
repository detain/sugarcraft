# SUPERVISOR — candy-query remediation

You are the **supervisor**. Your entire job is to walk the handoff sequence below and spawn ONE subagent at a time. **You do not investigate, read code, or edit anything yourself.** You do not read the step files or the between-step files — you only know which file to tell each subagent to read.

The only files you ever read are: **this file** and **`plans/candy-query-fix/updates.md`** (the shared scratchpad).

---

## How you spawn a subagent

For each entry in the sequence, spawn the listed agent with a prompt of this exact shape:

> Read `plans/candy-query-fix/COMMON.md` first (shared protocol), then read `<INSTRUCTION FILE>` and execute it fully. <FOR BETWEEN-STEPS: The real step that just completed was **STEP <id> — <title>**; scope your work to its diff/area.> When done, ensure you have shipped per COMMON.md (commit → PR → merge → pull → end on master) unless your instruction file says read-only. Append anything worth passing on to `plans/candy-query-fix/updates.md` and remove items you have resolved.

Spawn **serially** — never two write-capable subagents at once (concurrent writes to MATCHUPS/README/composer/updates collide).

After each subagent returns:
1. Read `updates.md`.
2. If it contains a `BLOCKER:` item → resolve it before proceeding (see Research / Blockers below). Do not advance the sequence until cleared.
3. If it contains `RESEARCH NEEDED:` → handle per Research below, then **re-spawn the same step**.
4. Otherwise advance to the next sequence entry.

## Research (only YOU can spawn agents)

Subagents cannot spawn agents. When `updates.md` has `RESEARCH NEEDED: <topic>`:
- Spawn a `general-purpose` agent: "Research <topic>. Read-only. Return concise, concrete, cited findings (file:line / URLs / exact SQL/API)."
- Append its findings under a `RESEARCH FINDINGS: <topic>` heading in `updates.md`.
- Remove the `RESEARCH NEEDED` line and re-spawn the step that requested it.

## Blockers

When a subagent reports a `BLOCKER:` it could not work around: read the item, decide the smallest unblocking action (often a research spawn, or splitting/resequencing a step), apply it via a fresh subagent, then resume. Never push past an unresolved blocker into a dependent step.

## GH TOKEN — remind every subagent

Every subagent that ships MUST `unset GITHUB_TOKEN` immediately before EVERY `gh` call. The instruction files already say so; you do not run `gh` yourself.

---

## The cadence

After **every real step** run the four between-steps in order: **REVIEW → FIX → TESTS_CI → DOCS**. Then start the next real step. A phase's final real step's between-block is scoped to the whole phase (it is the phase closeout too).

Between-step files (reused every time):
- REVIEW → `between/REVIEW.md` — agent `oac:code-reviewer` (read-only, no PR)
- FIX → `between/FIX.md` — agent `oac:coder-agent`
- TESTS_CI → `between/TESTS_CI.md` — agent `oac:test-engineer`
- DOCS → `between/DOCS.md` — agent `oac:coder-agent`

> If REVIEW reports "no findings", still run FIX (it will confirm nothing to do and end on master), or skip FIX and note the skip in `updates.md` — your discretion to keep momentum. TESTS_CI and DOCS always run.

---

## Execution sequence

Legend: `[R]` real step (oac:coder-agent unless noted) · each `[R]` is followed by the 4 between-steps.

### Phase 1 — Wire the admin UI
1. `[R]` steps/phase1/STEP_1.1_admin-key-routing.md  → REVIEW, FIX, TESTS_CI, DOCS
2. `[R]` steps/phase1/STEP_1.2_page-collaborators.md → REVIEW, FIX, TESTS_CI, DOCS
3. `[R]` steps/phase1/STEP_1.3_connections-update.md → REVIEW, FIX, TESTS_CI, DOCS
4. `[R]` steps/phase1/STEP_1.4_connections-actions.md → REVIEW, FIX, TESTS_CI, DOCS  *(phase closeout)*

### Phase 2 — Driver / connection correctness
5. `[R]` steps/phase2/STEP_2.1_dsn-and-factory.md → REVIEW, FIX, TESTS_CI, DOCS
6. `[R]` steps/phase2/STEP_2.2_query-contract-and-flavor.md → REVIEW, FIX, TESTS_CI, DOCS
7. `[R]` steps/phase2/STEP_2.3_exporters.md → REVIEW, FIX, TESTS_CI, DOCS  *(phase closeout)*

### Phase 3 — Reports functional
8. `[R]` steps/phase3/STEP_3.1_reports-async.md → REVIEW, FIX, TESTS_CI, DOCS
9. `[R]` steps/phase3/STEP_3.2_reports-navigation-catalog.md → REVIEW, FIX, TESTS_CI, DOCS  *(phase closeout)*

### Phase 4 — Variables functional
10. `[R]` steps/phase4/STEP_4.1_variables-edit-dialog.md → REVIEW, FIX, TESTS_CI, DOCS
11. `[R]` steps/phase4/STEP_4.2_variables-persist.md → REVIEW, FIX, TESTS_CI, DOCS
12. `[R]` steps/phase4/STEP_4.3_variables-metadata-catalog.md → REVIEW, FIX, TESTS_CI, DOCS  *(phase closeout)*

### Phase 5 — Performance Schema Setup
13. `[R]` steps/phase5/STEP_5.1_perfschema-gating-models.md → REVIEW, FIX, TESTS_CI, DOCS
14. `[R]` steps/phase5/STEP_5.2_perfschema-commit-tree.md → REVIEW, FIX, TESTS_CI, DOCS
15. `[R]` steps/phase5/STEP_5.3_perfschema-easysetup.md → REVIEW, FIX, TESTS_CI, DOCS  *(phase closeout)*

### Phase 6 — Dashboard + Server Status accuracy
16. `[R]` steps/phase6/STEP_6.1_sampler-gauges.md → REVIEW, FIX, TESTS_CI, DOCS
17. `[R]` steps/phase6/STEP_6.2_postgres-status-mapping.md → REVIEW, FIX, TESTS_CI, DOCS
18. `[R]` steps/phase6/STEP_6.3_dashboard-accuracy.md → REVIEW, FIX, TESTS_CI, DOCS
19. `[R]` steps/phase6/STEP_6.4_serverstatus-features.md → REVIEW, FIX, TESTS_CI, DOCS  *(phase closeout)*

### Phase 7 — Async unification, alerts, history, cleanup
20. `[R]` steps/phase7/STEP_7.1_async-throttle-restart.md → REVIEW, FIX, TESTS_CI, DOCS
21. `[R]` steps/phase7/STEP_7.2_alerts-history.md → REVIEW, FIX, TESTS_CI, DOCS
22. `[R]` steps/phase7/STEP_7.3_dead-code-cleanup.md → REVIEW, FIX, TESTS_CI, DOCS  *(phase closeout)*

### Phase 8 — Final integration
23. `[R]` steps/phase8/STEP_8.1_final-integration.md → REVIEW, FIX, TESTS_CI, DOCS  *(final closeout)*

---

## Done

When step 23 + its between-block complete and `updates.md` has no open `BLOCKER:`/`RESEARCH NEEDED:` and every deferred item is either resolved or explicitly recorded as accepted-deferral: report a final summary (phases completed, PRs merged, items deferred) and stop.
