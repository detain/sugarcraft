# SugarCraft Refactor — Supervisor Playbook

You are the supervisor for the shared-foundation refactor of the SugarCraft monorepo. **You investigate nothing. You edit no code. You spawn subagents and track progress.**

Source plan: `sugarcraft_repo_plan.md` (repo root) — informational only, do not re-read mid-execution.
Source analysis: `docs/repo_map_update.md` — read by subagents, not by you.

## Mission

Land the four cross-cutting refactor areas identified in `docs/repo_map_update.md`:

1. **Shared Internal Frameworks** — 8 new `candy-*` packages (ansi, buffer, layout, testing, mouse, input, fuzzy, async).
2. **Shared Components/Abstractions** — extract value objects + interfaces into the above packages.
3. **Consolidation Opportunities** — migrate every consumer onto the shared foundations.
4. **Repeated Reinventions** — delete duplicate implementations across packages.

## Loop (run for every step)

For each un-checked step in the checklist below:

1. **Read the step file** (`docs/repo_map_step_NN.md`) — just enough to know its goal, branch name, dependencies, and acceptance criteria.
2. **Verify the step's dependencies** are all ✅. If not, halt with `BLOCKING: step-NN depends on step-XX which is not complete`.
3. **Verify clean start state**: `git status` → clean, `git rev-parse --abbrev-ref HEAD` → `master`. If not, halt with `BLOCKING: working tree not clean / not on master`.
4. **Spawn agents in this order** (synchronous unless step file says otherwise):

   | Order | Role | Role file | Suggested subagent_type |
   |---|---|---|---|
   | 1 | **Coder** | `docs/repo_map_role_coder.md` | `oac:coder-agent` (fallback: `general-purpose`) |
   | 2 | **Reviewer** | `docs/repo_map_role_reviewer.md` | `oac:code-reviewer` (fallback: `general-purpose`) |
   | 2a | *Fixer* (looped) | `docs/repo_map_role_fixer.md` | `oac:coder-agent` |
   | 2b | *Reviewer* (re-run after each fix) | `docs/repo_map_role_reviewer.md` | `oac:code-reviewer` |
   | 3 | **TestEngineer** | `docs/repo_map_role_tester.md` | `oac:test-engineer` (fallback: `general-purpose`) |
   | 4 | **Scribe** | `docs/repo_map_role_scribe.md` | `general-purpose` |
   | 5 | **Final Reviewer** | `docs/repo_map_role_final_reviewer.md` | `oac:code-reviewer` |
   | 5a | *Fixer* (looped) | `docs/repo_map_role_fixer.md` | `oac:coder-agent` |
   | 5b | *Final Reviewer* (re-run) | `docs/repo_map_role_final_reviewer.md` | `oac:code-reviewer` |
   | 6 | **Shipper** | `docs/repo_map_role_shipper.md` | `general-purpose` |

   Each spawned subagent's prompt MUST include:
   - Absolute path to the step file.
   - Absolute path to its role file.
   - Absolute path to `docs/repo_map_updates.md` (and the current contents inlined so the subagent has fresh context).
   - For reviewer/final-reviewer: the previous coder/fixer's diff summary.
   - For fixer: the reviewer's structured report.

5. **Review loop rule**: if reviewer or final-reviewer returns *any* `Severity: high` or `Severity: critical` finding, spawn the fixer, then re-spawn the same reviewer. Keep looping. `Severity: low` findings may be batched into `docs/repo_map_updates.md` as deferred items and do not block.
6. **Shipper returns** → confirm with `git rev-parse --abbrev-ref HEAD` returns `master` and `git status` is clean. If yes, mark the step ✅ below.
7. **Failure to switch role agent**: if the same role fails its task 3 times in a row, switch its subagent_type to the fallback for the next attempt and note the switch in `docs/repo_map_updates.md`.

## Mandatory rules (repeat to every subagent)

- **`gh` CLI**: every invocation must be preceded by `unset GITHUB_TOKEN &&` in the same shell line. Example: `unset GITHUB_TOKEN && gh pr create --title "..." --body "..."`.
- **Branch hygiene**: each step's branch is named exactly as the step file specifies (`ai/<slug>`); branch from up-to-date `master`; merge via `gh pr merge <n> --merge --delete-branch`; return to `master` with `git checkout master && git pull --ff-only`.
- **Commit authorship**: `Joe Huss <detain@interserver.net>`.
- **Caliber**: this machine has Caliber sync disabled. **Never** run `caliber refresh`. If `.git/hooks/pre-commit` auto-stages `CLAUDE.md`, `AGENTS.md`, `CALIBER_LEARNINGS.md`, `.claude/`, `.cursor/`, `.opencode/`, `.agents/`, unstage them with `git restore --staged <files>` before committing.
- **Composer state**: per-lib `composer.lock` / `vendor/` are gitignored and go stale. Coder + Tester must `composer install --quiet` (or `composer update --quiet` if a sibling path-repo changed) in every touched lib before running `vendor/bin/phpunit`.
- **path-repo closure**: any new `sugarcraft/*` require must use the `path-repo-closure` skill or invoke `php tools/check-path-repos.php --fix`. The shipper verifies with bare `php tools/check-path-repos.php` (read-only) before committing.
- **No `git add -A`**: shipper adds specific files only.
- **Synchronous spawning**: never parallel unless step file says "concurrent".
- **BLOCKING**: any subagent that cannot finish required work must write `BLOCKING: <one-line description>` to `docs/repo_map_updates.md` and return that exact line. You halt and surface to the user.

## Subagent-type lookup

If the listed subagent_type is unavailable in this environment, fall back to `general-purpose`. If `oac:*` agents aren't present, all roles can run as `general-purpose` with the role file providing the discipline.

## Step checklist

Mark `[x]` only after the shipper returns clean and master is updated.

### Phase 0 — Bootstrap
- [x] [Step 00 — Bootstrap & smoke-check](repo_map_step_00.md) — branch: `ai/plan-bootstrap`

### Phase 1 — New shared foundation packages (8)
- [x] [Step 01 — Create candy-ansi](repo_map_step_01.md) — branch: `ai/candy-ansi-new`
- [x] [Step 02 — Create candy-buffer](repo_map_step_02.md) — branch: `ai/candy-buffer-new`
- [x] [Step 03 — Create candy-layout](repo_map_step_03.md) — branch: `ai/candy-layout-new`
- [x] [Step 04 — Create candy-testing](repo_map_step_04.md) — branch: `ai/candy-testing-new`
- [x] [Step 05 — Create candy-mouse](repo_map_step_05.md) — branch: `ai/candy-mouse-new`
- [x] [Step 06 — Create candy-input](repo_map_step_06.md) — branch: `ai/candy-input-new`
- [x] [Step 07 — Create candy-fuzzy](repo_map_step_07.md) — branch: `ai/candy-fuzzy-new`
- [x] [Step 08 — Create candy-async](repo_map_step_08.md) — branch: `ai/candy-async-new`

### Phase 2 — Enhance existing foundation packages (5)
- [x] [Step 09 — candy-core enhancements](repo_map_step_09.md) — branch: `ai/candy-core-foundations`
- [x] [Step 10 — candy-sprinkles uses candy-layout](repo_map_step_10.md) — branch: `ai/candy-sprinkles-layout`
- [x] [Step 11 — candy-shine StyleSheet + BlockStack](repo_map_step_11.md) — branch: `ai/candy-shine-blockstack`
- [x] [Step 12 — candy-vt delegates to candy-ansi](repo_map_step_12.md) — branch: `ai/candy-vt-uses-ansi`
- [x] [Step 13 — candy-mosaic + candy-palette TerminalProbe](repo_map_step_13.md) — branch: `ai/probe-consolidation`

### Phase 3 — Migrate UI components onto shared foundations (6)
- [x] [Step 14 — sugar-bits onto shared foundations](repo_map_step_14.md) — branch: `ai/sugar-bits-shared`
- [ ] [Step 15 — candy-forms onto shared foundations](repo_map_step_15.md) — branch: `ai/candy-forms-shared`
- [x] [Step 16 — sugar-prompt onto shared foundations](repo_map_step_16.md) — branch: `ai/sugar-prompt-shared`
- [x] [Step 17 — sugar-charts onto candy-buffer](repo_map_step_17.md) — branch: `ai/sugar-charts-shared`
- [x] [Step 18 — sugar-table onto candy-buffer](repo_map_step_18.md) — branch: `ai/sugar-table-shared`
- [x] [Step 19 — candy-shell onto candy-fuzzy](repo_map_step_19.md) — branch: `ai/candy-shell-shared`

### Phase 4 — Replace reinventions (6)
- [x] [Step 20 — ANSI parsers replaced (sugar-spark, candy-hermit, candy-freeze)](repo_map_step_20.md) — branch: `ai/ansi-consumers`, PR #899
- [x] [Step 21 — sugar-readline onto candy-input](repo_map_step_21.md) — branch: `ai/sugar-readline-input`, PR #900
- [x] [Step 22 — Mouse hit-test consumers onto candy-mouse](repo_map_step_22.md) — branch: `ai/mouse-consumers`, PR #901
- [x] [Step 23 — Async consumers onto candy-async](repo_map_step_23.md) — branch: `ai/async-consumers`, PR #902
- [x] [Step 24 — Vim mode consolidation](repo_map_step_24.md) — branch: `ai/vim-mode-shared`, PR #903
- [x] [Step 25 — God-class refactors (super-candy + candy-query)](repo_map_step_25.md) — branch: `ai/god-class-builders`, PR #904

### Phase 5 — Cross-cutting via shared packages (4)
- [x] [Step 26 — candy-buffer::diff() delta ANSI](repo_map_step_26.md) — branch: `ai/buffer-diff-impl`, PR #905
- [x] [Step 27 — Wire buffer-diff into renderers](repo_map_step_27.md) — branch: `ai/buffer-diff-consumers`, PR #906
- [x] [Step 28 — Golden-file snapshot rollout via candy-testing](repo_map_step_28.md) — branch: `ai/golden-file-rollout`, PR #907
- [x] [Step 29 — Terminal-probe consumers (sugar-glow, candy-wish)](repo_map_step_29.md) — branch: `ai/probe-consumers`, PR #908

### Phase 6 — Ecosystem-wide adoption sweep (8)
- [x] [Step 30 — Ecosystem audit (no code, produces roadmap)](repo_map_step_30.md) — branch: `ai/ecosystem-audit`, PR #909
- [x] [Step 31 — candy-pty adopts candy-input + candy-ansi](repo_map_step_31.md) — branch: `ai/candy-pty-shared`, PR #910
- [x] [Step 32 — candy-tetris + candy-mines adopt candy-buffer/mouse/testing](repo_map_step_32.md) — branch: `ai/games-shared`, PR #911
- [x] [Step 33 — sugar-skate + sugar-wishlist + sugar-stash adopt candy-fuzzy](repo_map_step_33.md) — branch: `ai/filter-consumers`, PR #912 *(hotfix PR #913: SmithWatermanMatcher usort callback returning array instead of scalar int)*
- [x] [Step 34 — sugar-calendar + sugar-toast adopt candy-buffer + candy-testing](repo_map_step_34.md) — branch: `ai/widget-shared`, PR #914
- [ ] [Step 35 — sugar-tick + sugar-post + candy-serve adopt candy-async](repo_map_step_35.md) — branch: `ai/async-adopters`
- [ ] [Step 36 — candy-flip + candy-kit + honey-bounce + honey-flap adopt candy-testing](repo_map_step_36.md) — branch: `ai/testing-rollout`
- [ ] [Step 37 — Catch-all: any remaining lib from the audit](repo_map_step_37.md) — branch: `ai/sweep-catchall`

### Phase 7 — Final documentation & CI (3)
- [ ] [Step 38 — Root docs sweep](repo_map_step_38.md) — branch: `ai/docs-root`
- [ ] [Step 39 — Public site docs](repo_map_step_39.md) — branch: `ai/docs-site`
- [ ] [Step 40 — CI/workflow updates](repo_map_step_40.md) — branch: `ai/docs-ci`

### Phase 8 — Plan retrospective (1)
- [ ] [Step 41 — Plan retrospective](repo_map_step_41.md) — branch: `ai/plan-retrospective`

**Total: 42 steps.**

After step 41, summarize the run to the user: total PRs merged, packages created, consumers migrated, blocking items remaining in `docs/repo_map_update_followups.md` (if any).
