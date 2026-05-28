# SugarCraft Ecosystem — Shared-Foundation Refactor Plan

## Context

`docs/repo_map_update.md` analyzed all 47 SugarCraft packages and identified four cross-cutting problem areas:

1. **Suggested Shared Internal Frameworks** (§326) — 7 new shared packages.
2. **Suggested Shared Components/Abstractions** (§344) — 10 cross-cutting value objects/interfaces.
3. **Potential Consolidation Opportunities** (§368) — 7 areas where 3-5 packages each reinvent the same primitive.
4. **Repeated Reinventions Across SugarCraft Packages** (§386) — 10 specific duplications.

The goal of this plan is to land all four areas: build the missing shared foundations, migrate every consumer onto them, and remove every duplicated implementation, ending with a monorepo where one fix at the foundation benefits the entire ecosystem.

Out of scope (deferred): toSshArgv fix, candy-zone debug viz, `flock()` concurrent safety, wide-char overflow, MarkLine wiring, PNG renderer wiring. These are real issues but live outside the four named sections; they will be tackled in a separate follow-up plan.

## Reconnaissance Summary (informs the plan)

- `candy-sprinkles/src/Layout/{Constraint,Layout,Solver}.php` already houses a 5-phase greedy solver — `candy-layout` extracts + upgrades to Cassowary, doesn't start from zero.
- `candy-forms/src/Fuzzy/FuzzyMatcher.php` is the canonical Smith-Waterman matcher; `sugar-prompt` already class_aliases to it, `candy-lister/src/FuzzyMatch.php` mirrors it — `candy-fuzzy` extracts + adds scored matched indices.
- `candy-vt/src/Parser/{Parser,State,Transitions,CsiHandler,OscHandler,Action,Handler}.php` is a near-complete ECMA-48 state machine — `candy-ansi` extracts this subtree, candy-vt becomes a consumer.
- 24 `candy-*` libs exist; the 8 targets (`candy-ansi`, `candy-buffer`, `candy-layout`, `candy-testing`, `candy-mouse`, `candy-input`, `candy-fuzzy`, `candy-async`) do **not**.
- Project skills `scaffold-library` (full new-lib scaffold + root wiring) and `path-repo-closure` (transitive composer.json closure) are pre-built and will be used by coder agents at every applicable step.

## Plan-File Deliverables

When this plan is approved and execution begins, the following on-disk artifacts will be produced **before** the supervisor runs anything. All files live flat under `docs/` with the `repo_map_` prefix — no nested folders — so the source doc `docs/repo_map_update.md` and these instruction files all glob together via `docs/repo_map_*`.

```
docs/repo_map_plan_prompt.md           ← the single user-facing prompt to start the supervisor
docs/repo_map_supervisor.md            ← supervisor's only required reading
docs/repo_map_updates.md               ← shared scratchpad subagents append/consume
docs/repo_map_role_coder.md            ← generic coder workflow
docs/repo_map_role_reviewer.md         ← generic reviewer workflow
docs/repo_map_role_fixer.md            ← generic fixer workflow
docs/repo_map_role_tester.md           ← generic TestEngineer workflow
docs/repo_map_role_scribe.md           ← generic docs workflow
docs/repo_map_role_final_reviewer.md   ← generic final-pass reviewer
docs/repo_map_role_shipper.md          ← generic git/PR workflow
docs/repo_map_role_researcher.md       ← generic deep-research agent
docs/repo_map_step_00.md               ← bootstrap
docs/repo_map_step_01.md               ← candy-ansi
…
docs/repo_map_step_33.md               ← plan retrospective
```

Total artifact files: 1 prompt + 1 supervisor + 1 updates + 8 roles + 34 steps = **45 files**, all matching `docs/repo_map_*`. The existing `docs/repo_map_update.md` source doc stays put and is read-only for this plan.

## How the supervisor works

The supervisor is a *driver*, not an investigator. Its loop is:

1. Open `docs/repo_map_supervisor.md` and find the next un-checked step.
2. Read **only** that step file (`docs/repo_map_step_NN.md`) plus `docs/repo_map_updates.md`.
3. Read the appropriate `docs/repo_map_role_<role>.md` for each agent it is about to spawn.
4. Spawn the coder synchronously with the step file + `docs/repo_map_role_coder.md` + `docs/repo_map_updates.md` path.
5. After coder returns, spawn the reviewer with the step file + `docs/repo_map_role_reviewer.md`.
6. If reviewer reports any issue → spawn fixer with the reviewer's report + `docs/repo_map_role_fixer.md` → re-spawn reviewer → repeat until clean.
7. Spawn TestEngineer (`docs/repo_map_role_tester.md`).
8. Spawn Scribe (`docs/repo_map_role_scribe.md`).
9. Spawn final reviewer (`docs/repo_map_role_final_reviewer.md`); if issues found, spawn fixer → final reviewer loop until clean.
10. Spawn shipper (`docs/repo_map_role_shipper.md`) — commits, pushes, opens PR, merges (`--merge --delete-branch`), checks out master, pulls.
11. Mark step ✅ in `docs/repo_map_supervisor.md`, move on.

All subagents are spawned **synchronously** unless the step file explicitly says "concurrent" (the supervisor only does that when the step says so). Each step ends on `master` with a clean working tree.

`docs/repo_map_updates.md` is a shared scratchpad: agents append items (e.g., "candy-buffer needs an Image cell type — defer to Phase 5") and remove them when resolved. Supervisor includes the current contents in every subagent prompt so context flows.

**BLOCKING issues**: if any agent cannot finish and the unfinished work is required for the next step, it writes `BLOCKING: <description>` to `docs/repo_map_updates.md` and returns that string verbatim to the supervisor. The supervisor halts and surfaces the blocker to the user; otherwise non-blocking unfinished items just live in the updates file for later.

**`gh` CLI rule (repeated everywhere)**: every `gh` invocation must be preceded by `unset GITHUB_TOKEN &&`. This is called out in `docs/repo_map_role_shipper.md`, `docs/repo_map_role_coder.md`, `docs/repo_map_supervisor.md`, and each step's "ship" section.

## Phase / Step Breakdown

### Phase 0 — Bootstrap (1 step)

| # | Step | Branch | Outputs |
|---|---|---|---|
| 0 | Verify plan artifacts present, create empty `updates.md`, do a smoke run (no code changes) confirming each role file is reachable | `ai/plan-bootstrap` | empty PR or no-op (skip ship if no changes) |

### Phase 1 — New shared foundation packages (8 steps)

Each step uses the `scaffold-library` skill, then implements core API per the cited spec. Final shape: PSR-4 `SugarCraft\<Sub>\`, `final` classes, immutable + fluent via `mutate()`, ≥95 % phpunit coverage.

| # | Step | Branch | Key source-of-truth |
|---|---|---|---|
| 1 | `candy-ansi` — extract ECMA-48 state machine. New PSR-4 `SugarCraft\Ansi\`. Copy `candy-vt/src/Parser/*` into `candy-ansi/src/Parser/`. candy-vt does *not* depend on candy-ansi yet — that's step 8. | `ai/candy-ansi-new` | repo_map §327.5, §336, reinvention §387.2 |
| 2 | `candy-buffer` — `Buffer` (Cell grid), `Cell` (rune,style,link,width), `Buffer::diff()` stub. Cell-level SGR-transition optimisation hooks. | `ai/candy-buffer-new` | §327.3, components §345.1, §387.5 |
| 3 | `candy-layout` — Cassowary constraint solver port. `LayoutSolver` interface + `CassowarySolver` impl. Re-implementation of `candy-sprinkles/src/Layout/Solver.php` as the *fallback* `GreedySolver`. | `ai/candy-layout-new` | §327.2, §345.2, consolidation §369.3 |
| 4 | `candy-testing` — `ProgramSimulator`, `Program::withInput()`/`withOutput()` traits, snapshot-assert helpers (`assertGoldenAnsi`, `assertCellGrid`), tape recorder. | `ai/candy-testing-new` | §327.4, §345.4, §387.6/8 |
| 5 | `candy-mouse` — Mark/Scan/Get pattern (self-contained, not wired to candy-zone Manager), `ZoneClickTracker` for press/release dedup. | `ai/candy-mouse-new` | §327.6, §345.5, §387.4 |
| 6 | `candy-input` — terminal escape sequence decoder (Kitty keyboard protocol, SGR mouse, focus/paste). `InputDriver` interface, `EscapeDecoder` impl. | `ai/candy-input-new` | §327.7, §387 (no #), §455 |
| 7 | `candy-fuzzy` — extract `FuzzyMatcher` from candy-forms, add `MatchResult` with scored indices, integrate Smith-Waterman from candy-lister. Leave deprecation shims in source packages. | `ai/candy-fuzzy-new` | §345.3, §387.1 |
| 8 | `candy-async` — `Cancellable`, `Subscription`, `AsyncOps` value objects unifying ReactPHP usage across candy-core/candy-forms/sugar-prompt. | `ai/candy-async-new` | §369.6, §387.5 |

### Phase 2 — Enhance existing foundation packages (5 steps)

| # | Step | Branch |
|---|---|---|
| 9 | `candy-core` — `ProgramOptions::builder()`, `Program::withLogger()`, `Program::withExceptionHandler()`, exposed `$lastFrameDuration`, `ProgressReporter` interface, `UndoActionType` enum. | `ai/candy-core-foundations` |
| 10 | `candy-sprinkles` — consume `candy-layout`; keep existing `Solver` API; route to `LayoutSolver` (default `CassowarySolver`, fallback `GreedySolver`). | `ai/candy-sprinkles-layout` |
| 11 | `candy-shine` — `StyleSheet` cascading + `BlockStack` glamour-parity for nested markdown blocks. | `ai/candy-shine-blockstack` |
| 12 | `candy-vt` — delegate parsing to `candy-ansi`; keep public namespace aliases for back-compat (no semver break). | `ai/candy-vt-uses-ansi` |
| 13 | `candy-mosaic` + `candy-palette` — `Mosaic::auto()` graceful fallback, `Mosaic::diagnose()` structured probe report, extract `TerminalProbe` (lives in candy-palette since palette already does 12-step detection). | `ai/probe-consolidation` |

### Phase 3 — Migrate UI components onto shared foundations (6 steps)

| # | Step | Branch | Adopts |
|---|---|---|---|
| 14 | `sugar-bits` | `ai/sugar-bits-shared` | candy-buffer, candy-layout, candy-mouse, candy-fuzzy |
| 15 | `candy-forms` | `ai/candy-forms-shared` | candy-buffer, candy-layout, candy-testing, candy-fuzzy |
| 16 | `sugar-prompt` | `ai/sugar-prompt-shared` | candy-buffer, candy-testing, candy-fuzzy (drop class_alias once consumers cut) |
| 17 | `sugar-charts` | `ai/sugar-charts-shared` | candy-buffer |
| 18 | `sugar-table` | `ai/sugar-table-shared` | candy-buffer |
| 19 | `candy-shell` | `ai/candy-shell-shared` | candy-fuzzy (replace SubStyleParser fuzzy bits) |

### Phase 4 — Replace reinventions (6 steps)

| # | Step | Branch | Replaces |
|---|---|---|---|
| 20 | sugar-spark, candy-hermit, candy-freeze → candy-ansi | `ai/ansi-consumers` | byte-loop parsers in `sugar-spark/src/Inspector.php`, `candy-hermit/src/Hermit.php::highlightMatches`, `candy-freeze/src/AnsiParser.php` |
| 21 | sugar-readline → candy-input | `ai/sugar-readline-input` | symbolic-only key handling |
| 22 | sugar-veil, sugar-crumbs, candy-lister → candy-mouse | `ai/mouse-consumers` | manual candy-zone `Manager` wiring |
| 23 | candy-forms, sugar-prompt, candy-core → candy-async | `ai/async-consumers` | scattered ReactPHP usage |
| 24 | Vim mode consolidation | `ai/vim-mode-shared` | `vimMode`/`vimNormalMode` flags duplicated across candy-forms TextInput + sugar-prompt + sugar-bits + sugar-readline → shared `VimKeyHandler` in candy-forms (or candy-core — coder chooses, supervisor approves via updates.md) |
| 25 | God-class refactors | `ai/god-class-builders` | `super-candy/src/Manager.php` (915 lines) → `Manager::builder()`; `candy-query/src/App.php` (12-arg ctor) → `App::builder()` + `DatabaseInterface` extraction (§345.7) |

### Phase 5 — Cross-cutting via the new shared packages (4 steps)

| # | Step | Branch |
|---|---|---|
| 26 | `candy-buffer::diff()` — delta ANSI sequence emission (ECH/REP/ICH/DCH), SGR-transition optimisation. | `ai/buffer-diff-impl` |
| 27 | Wire buffer-diff into renderers: sugar-boxer, sugar-dash, sugar-crush, sugar-veil, sugar-stickers, candy-lister. | `ai/buffer-diff-consumers` |
| 28 | Snapshot/golden-file rollout via `candy-testing` for: candy-forms, sugar-prompt, sugar-bits, sugar-charts, sugar-table, sugar-glow, candy-vt, candy-vcr, candy-shine. | `ai/golden-file-rollout` |
| 29 | Terminal-probing consolidation — sugar-glow + candy-wish consume `TerminalProbe` from step 13. | `ai/probe-consumers` |

### Phase 6 — Ecosystem-wide adoption sweep (8 steps)

The libs explicitly named in `docs/repo_map_update.md` §326/§344/§368/§386 are covered by steps 9-29. But many additional libs (`candy-pty`, `candy-tetris`, `candy-mines`, `sugar-skate`, `sugar-calendar`, etc.) would also benefit from the new shared packages without being explicitly cited. This phase audits the gap and lands the easy wins.

| # | Step | Branch |
|---|---|---|
| 30 | **Ecosystem audit** — survey every lib not touched in steps 9-29; per-lib report of which new shared packages it could adopt (candy-buffer, candy-layout, candy-mouse, candy-fuzzy, candy-input, candy-ansi, candy-async, candy-testing). Output: structured roadmap appended to `docs/repo_map_updates.md`. No code changes. | `ai/ecosystem-audit` |
| 31 | candy-pty adopts candy-input + candy-ansi for escape decoding and input handling. | `ai/candy-pty-shared` |
| 32 | candy-tetris + candy-mines adopt candy-buffer (cell-grid renderer) + candy-mouse (zone clicks) + candy-testing (snapshot tests). | `ai/games-shared` |
| 33 | sugar-skate + sugar-wishlist + sugar-stash adopt candy-fuzzy for filtering. | `ai/filter-consumers` |
| 34 | sugar-calendar + sugar-toast adopt candy-buffer + candy-testing. | `ai/widget-shared` |
| 35 | sugar-tick + sugar-post + candy-serve adopt candy-async. | `ai/async-adopters` |
| 36 | candy-flip + candy-kit + honey-bounce + honey-flap adopt candy-testing for snapshot/golden coverage. | `ai/testing-rollout` |
| 37 | Catch-all — any lib the audit identified that didn't fit into 31-36 gets a small migration here, or its candidates roll into `docs/repo_map_update_followups.md` for a future plan. | `ai/sweep-catchall` |

### Phase 7 — Final documentation & CI (3 steps)

| # | Step | Branch |
|---|---|---|
| 38 | Root docs — README, MATCHUPS.md, PROJECT_NAMES.md, CONTRIBUTING.md, AGENTS.md (8 new libs + cross-refs). | `ai/docs-root` |
| 39 | Public site — `docs/index.html` tiles, `docs/lib/<slug>.html` for each new lib, status banner update on `docs/repo_map_update.md` marking sections complete. | `ai/docs-site` |
| 40 | CI/workflow — `.github/workflows/vhs.yml` `all=(...)` add new libs (skip non-visual), `codecov.yml` flags for 8 new packages, sanity-run `scripts/affected-libs.php` + `tools/check-path-repos.php`. | `ai/docs-ci` |

### Phase 8 — Plan retrospective (1 step)

| # | Step | Branch |
|---|---|---|
| 41 | Append plan retrospective to `docs/repo_map_update.md` and close out `updates.md` (move any remaining items to `docs/repo_map_update_followups.md`). | `ai/plan-retrospective` |

**Total: 42 steps** (step 0 + steps 1-41).

## Content blueprints for the files-to-create

### `SUPERVISOR.md` will contain

- One-paragraph mission statement and the **"investigate nothing, drive only"** rule.
- The supervisor loop (1-11 above) as a checklist.
- The list of 34 steps with `[ ]` checkboxes, each linking to `steps/step-NN.md`.
- The `unset GITHUB_TOKEN &&` rule (verbatim) and the master-branch-at-end-of-step rule.
- Pointers to `roles/` and `updates.md`.
- Subagent type mapping: coder → `oac:coder-agent` or generic coder; reviewer → `oac:code-reviewer`; tester → `oac:test-engineer`; scribe → general-purpose; final-reviewer → `oac:code-reviewer`; shipper → general-purpose; researcher → `Explore` or `general-purpose`. Supervisor is instructed to switch agent types if a given role keeps failing.

### Each `steps/step-NN.md` will contain

```
# Step NN — <title>
Branch: ai/<slug>
Depends on: step-XX (must be ✅ before starting)
Blocks: step-YY

## Goal
<one paragraph>

## Files expected to be created
<list>

## Files expected to be modified
<list>

## Acceptance criteria (handed to reviewer + final-reviewer)
- [ ] criterion 1
- [ ] criterion 2
- [ ] phpunit ≥ 95 % coverage for the changed package(s)
- [ ] every touched lib's `vendor/bin/phpunit` passes
- [ ] root `tools/check-path-repos.php` passes
- [ ] `git status` clean, branch on master, PR merged, branch deleted

## Coder brief
<step-specific guidance: project skill to invoke (scaffold-library / path-repo-closure / write-phpunit-test / sugarcraft-model-pattern), file paths, upstream Charmbracelet citation, the existing code to extract/refactor>

## Tester brief
<step-specific guidance: snapshot byte tests for renderers, cell-grid tests for buffer ops, behaviour tests for state machines, coercion tests for fluent setters, immutability checks for with*() builders>

## Scribe brief
<step-specific guidance: README sections to add, CALIBER_LEARNINGS entries, MATCHUPS.md rows, docs/lib HTML — only the parts touched in this step>

## Ship brief
<step-specific PR title + body; commits authored as `Joe Huss <detain@interserver.net>`; `unset GITHUB_TOKEN && gh pr create ...`; `gh pr merge <n> --merge --delete-branch`>
```

### Role files (generic, reused across all steps)

- **`roles/coder.md`** — receive step file + updates.md. Read CLAUDE.md/AGENTS.md. Branch from up-to-date master. Use the named project skill where applicable. Run `composer install && vendor/bin/phpunit` per touched lib. Write findings/gotchas to `updates.md`. Hand off via return message that names the branch and lists files changed.
- **`roles/reviewer.md`** — checklist: acceptance criteria from step file, security (esp. command injection, file path traversal, ANSI injection), partial implementations (`TODO`/`FIXME`/`throw new \LogicException`), broken syntax, missing tests, conventions in CLAUDE.md/AGENTS.md, gotchas in CALIBER_LEARNINGS.md. Output structured report with **Severity / File:Line / Description / Fix-hint** per issue. Empty report = ✅.
- **`roles/fixer.md`** — input is reviewer report; output is fixes only. Does NOT add scope. Re-runs phpunit per touched lib.
- **`roles/tester.md`** — push coverage to ≥95 % per touched lib. Snapshot ANSI bytes for renderers, behaviour for update() loops, immutability for with*(), coercion for clamps. Uses `write-phpunit-test` skill.
- **`roles/scribe.md`** — README, CALIBER_LEARNINGS, MATCHUPS, PROJECT_NAMES, docs/lib HTML, root README table row, docblock `@see Mirrors charmbracelet/<repo>.<Method>` headers.
- **`roles/final-reviewer.md`** — holistic review: does the diff actually deliver the step's *Goal*? Are tests + docs + code consistent? Same severity grid. Verdict line: `SHIP ✅` or `BLOCK ❌`.
- **`roles/shipper.md`** — `git add` only the intended files (never `-A` blindly), commit as `Joe Huss <detain@interserver.net>`, `git push`, `unset GITHUB_TOKEN && gh pr create --title "<lib>: <summary>" --body "$(cat <<'EOF' … EOF\n)"`, `gh pr merge <n> --merge --delete-branch`, `git checkout master && git pull --ff-only`. Per CLAUDE.md: this is a Caliber-free machine — unstage `CLAUDE.md/.claude/.cursor/AGENTS.md/CALIBER_LEARNINGS.md` etc. if the pre-commit hook auto-stages them. Do not run `caliber refresh`.
- **`roles/researcher.md`** — spawn-only-when-needed. Returns findings, never edits code.

### `updates.md` initial content

A one-paragraph header explaining its role, an empty `## Active Items` section, and an empty `## Resolved Items` archive. Subagents append to Active, move to Resolved when done.

### `plan_prompt.md` (repo root)

A short prompt the user pastes into a fresh Claude session to spin up the supervisor. Content sketch:

> You are the **SugarCraft Refactor Supervisor**. Read `plans/repo_map_update/SUPERVISOR.md` and only that file (plus `updates.md`). Spawn subagents to execute each step — never investigate, edit, or commit code yourself. Use the role files in `plans/repo_map_update/roles/`. Per-step specs live in `plans/repo_map_update/steps/step-NN.md`. After every step, ensure the working tree is clean and the branch is `master`. If any subagent returns a `BLOCKING:` line, halt and surface it to me. Begin with step-00.

## Verification (end-to-end test of the plan)

1. After plan-file approval and exit-plan-mode, all artifact files exist on disk at the paths above.
2. `head -1 plan_prompt.md` returns the supervisor kick-off prompt.
3. Each `steps/step-NN.md` opens cleanly and references at least one acceptance criterion.
4. Running supervisor through step-00 produces a "no changes — bootstrap OK" report with `git status` clean on master.
5. Running supervisor through step-01 yields a merged PR for `candy-ansi`, `candy-ansi/` directory exists, `vendor/bin/phpunit` passes in candy-ansi, `tools/check-path-repos.php` exits 0, and `git rev-parse --abbrev-ref HEAD` returns `master`.
6. After step-33, every section heading from `docs/repo_map_update.md` §326/§344/§368/§386 has a counterpart implementation cited in the appended retrospective.

## Risks / open questions to surface during execution (not blockers for approval)

- **Cassowary in PHP** — no production-grade implementation exists; step 3 may need a `researcher` round to choose between porting `kiwi`/`rhea` algorithms or wrapping a minimal hand-roll.
- **candy-vt back-compat** — step 12 ships namespace aliases; if any downstream test imports the inner `Parser/` namespace directly, supervisor will hit a fix-loop on step 12. Mitigation: aliases live for one full phase.
- **Vim mode home** — step 24 forces a decision (candy-forms vs candy-core). Coder will write a recommendation to `updates.md` first, supervisor will accept the recommendation unless it conflicts with CLAUDE.md.
- **Master-branch convention** — repo uses `master` (not `main`); every PR uses `--base master`. Captured in `roles/shipper.md`.
- **Per-lib `composer.lock`/`vendor/` are gitignored & go stale** — coder + tester must `composer update` before trusting local phpunit (captured in `roles/coder.md` + memory `project_stale_vendor_false_test_failures.md`).
