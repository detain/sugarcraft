---
status: in-progress
phase: 1
updated: 2026-06-30
---

# Master Audit Implementation Plan

## Goal

Consolidate all 60 individual library audit plans into 6 concurrent-safe execution groups, sequenced by dependency order (candy-core/candy-vt foundation → consumer libs → leaf libs → DRY extraction last), then dispatch subagents phase-by-phase to implement all fixes.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| candy-core Phase 1 runs first | 10+ libs depend on `InputReader.php` (undefined `$len_of_buf` bug); breaks UTF-8 multi-byte parsing | `ref:candy-core-investigation` |
| candy-vt Phase 1 (thread-safety) runs before all VT consumers | `Transitions::$table` and `Theme::$fgIndexMap` lazy init races affect any concurrent VT usage | `ref:candy-vt-audit` |
| sugar-dash Phase 1 runs in Group A | Parse error in `Stack.php:78` (missing space before `&&`) blocks compilation of the largest sugar-* lib | `ref:sugar-dash-audit` |
| sugar-reel runs in Group B | 55 findings (most of any lib); `AudioPlayer::pause()` SIGSTOP ineffective in PTY (critical) | `ref:sugar-reel-audit` |
| repeated_logic runs last | Extracts `Clamp.php` and `validateNonNeg()` to candy-core after all dependent plans complete | `ref:repeated-logic-audit` |
| honey-bounce runs early in Group C | `SpringChain::tick()` and `SpringCollection::tick()` must return new instances (not mutate in place) — dependencies for candy-mold | `ref:honey-bounce-audit` |
| sugar-stash path-repo closure check deferred | candy-fuzzy is a leaf lib with no sugarcraft deps — verification needed before assuming clean | `ref:sugar-stash-audit` |
| sugar-tick plan has false positives | Finds non-existent `src/Tick.php`; actual lib is a time-tracking app, not a TUI component — skip | `ref:sugar-tick-audit` |

## Dependency Graph (Summary)

```
candy-core (Phase 1 critical: InputReader undefined var)
    └── all candy-* and sugar-* libs using InputReader / Model contract

candy-vt (Phase 1 thread-safety: Transitions::$table, Theme::$fgIndexMap)
    └── sugar-reel (uses Terminal/VT emulation)

candy-query (Phase 1: SQL injection in identifier quoting)
    └── Independent — no sugarcraft dep consumers

sugar-dash (Phase 1: Stack.php parse error + RingBuffer oldest() bug)
    └── Independent

sugar-reel (Phase 1: AudioPlayer SIGSTOP/PTY, mutate() ?? bug)
    └── Independent

honey-bounce (Phase 1: Spring tick mutates in place)
    └── candy-mold (uses SpringChain)

sugar-charts (Phase 1: Extract ChartExtras trait — DRY)
    └── Independent

repeated_logic (extracts clamp/validateNonNeg to candy-core)
    └── Runs LAST after all consumers complete
```

## Execution Groups

### Group A — Foundation Critical Fixes [PENDING]
**Must execute first — these unblock everything else**

| Lib | Phase | Items | Key Critical Fix |
|-----|-------|-------|------------------|
| candy-core | P1 | 2 | InputReader.php:195 `$len_of_buf` undefined var |
| candy-vt | P1 | 2 | Transitions::$table thread-safe lazy init; Theme::$fgIndexMap race |
| sugar-dash | P1 | 2 | Stack.php:78 parse error; RingBuffer::oldest() wrapped buffer bug |

**Concurrency:** 3 libs, all isolated files — safe to parallelize.

---

### Group B — Large/Complex Libraries [PENDING]

| Lib | Phase | Items | Key Critical Fix |
|-----|-------|-------|------------------|
| sugar-reel | P1 | 3 | AudioPlayer::pause() SIGSTOP ineffective in PTY; mutate() `??` breaks falsy values |
| candy-pty | P1 | 3 | FD reuse race via `fopen('php://fd/N')` dup(); EINTR silently treated as timeout |
| candy-query | P1 | 8 | SQL injection in MysqlDatabase::rows() identifier quoting (7 critical + voryx replacement) |

**Concurrency:** 3 libs, all isolated files — safe to parallelize.

---

### Group C — Dependency-Next Tier [PENDING]

| Lib | Phase | Items | Key Fix |
|-----|-------|-------|---------|
| honey-bounce | P1 | 2 | SpringChain::tick() and SpringCollection::tick() must return new instances |
| sugar-charts | P1 | 2 | Extract ChartExtras trait (DRY across BarChart/Scatter/OHLCChart ~200 lines) |
| candy-async | P1 | 2 | AsyncOps duplicated across 3 libs — extract to candy-core after honey-bounce |
| candy-buffer | P1 | 2 | AsyncOps duplication (same as candy-async) |
| candy-sprinkles | P1 | 1 | Style value object — finalize after candy-core foundation stable |
| sugar-glow | P1 | 2 | Glow Renderer animation timing |

**Concurrency:** 6 libs — spawn 6 subagents in parallel.

---

### Group D — Medium Complexity [PENDING]

| Lib | Phase | Items | Key Fix |
|-----|-------|-------|---------|
| sugar-bits | P1 | 2 | Stopwatch teardown pattern; Tick::advance() edge cases |
| candy-mouse | P1 | 3 | Mouse event coordinate validation; wheel event handling |
| candy-lister | P1 | 4 | Paginator state machine; filter input handling |
| candy-input | P1 | 2 | InputReader buffer boundary conditions |
| candy-wish | P1 | 3 | AsyncMiddleware blocks event loop; PromiseAwait race |
| sugar-veil | P1 | 2 | Animation frame timing |

**Concurrency:** 6 libs — spawn 6 subagents in parallel.

---

### Group E — Component Libraries [PENDING]

| Lib | Phase | Items | Key Fix |
|-----|-------|-------|---------|
| sugar-post | P1 | 3 | Attachment silent failure; unchecked fwrite(); @ suppression on fgets() |
| sugar-prompt | P1 | 2 | `$pid` uninitialized on fork failure in Spinner.php |
| candy-log | P1 | 2 | Log level filtering; Handler interface consistency |
| sugar-stash | P1 | 2 | Path-repo closure check (verify candy-fuzzy is clean) |
| candy-testing | P1 | 2 | TapeRecorder Dracula theme test bug (`' Dracula'` with leading space) |
| candy-freeze | P1 | 2 | WeakMap for allocateColor cache (memory leak fix) |

**Concurrency:** 6 libs — spawn 6 subagents in parallel.

---

### Group F — Remaining + DRY Extraction [PENDING]

| Lib | Phase | Items | Key Fix |
|-----|-------|-------|---------|
| candy-shine | P1 | 3 | BlockStack state not reset in copy(); withTheme() shares block stack |
| candy-mosaic | P1 | 2 | Mosaic grid layout edge cases |
| candy-hermit | P1 | 2 | Shell environment isolation |
| candy-focus | P1 | 2 | Focus ring rendering |
| candy-fuzzy | P1 | 2 | Fuzzy matcher state |
| sugar-table | P1 | 2 | Table layout overflow |
| sugar-spark | P1 | 2 | Empty data set guard |
| sugar-toast | P1 | 2 | Viewport overflow; non-cancellable timer |
| repeated_logic | P1 | 10 | Extract Clamp.php + validateNonNeg() to candy-core; remove 35+ duplicate mutate() implementations |

**Concurrency:** 9 libs + repeated_logic — spawn 6 subagents, process in sub-batches of 6.

---

## Per-Library Plan Files (Source of Truth)

Each lib's implementation plan is at `findings/plan_<slug>.md`. The master plan here provides sequencing; individual plans provide exact file:line changes.

### Group A Source Plans
- `findings/plan_candy-core.md` — Phase 1 (items 1.1–1.2), Phase 2 (items 2.1–2.2)
- `findings/plan_candy-vt.md` — Phase 1 (items 1.1–1.2), Phase 2–12 (deferred after P1)
- `findings/plan_sugar-dash.md` — Phase 1 (parse error + RingBuffer bug; full plan ~52KB)

### Group B Source Plans
- `findings/plan_sugar-reel.md` — Phase 1 (items 1.1–1.8), Phase 2–6 (deferred)
- `findings/plan_candy-pty.md` — Phase 1 (FD reuse race, EINTR timeout)
- `findings/plan_candy-query.md` — Phase 1 (items 1.1–1.8 all critical SQL injection)

### Group C Source Plans
- `findings/plan_honey-bounce.md` — Phase 1 (Spring tick mutates in place)
- `findings/plan_sugar-charts.md` — Phase 1 (ChartExtras trait extraction)
- `findings/plan_candy-async.md` — Phase 1 (AsyncOps duplication)
- `findings/plan_candy-buffer.md` — Phase 1 (AsyncOps duplication)
- `findings/plan_candy-sprinkles.md` — Phase 1 (Style immutable value object)
- `findings/plan_sugar-glow.md` — Phase 1 (animation timing)

### Group D Source Plans
- `findings/plan_sugar-bits.md` — Phase 1 (Stopwatch teardown, Tick edge cases)
- `findings/plan_candy-mouse.md` — Phase 1 (mouse coordinate validation, wheel events)
- `findings/plan_candy-lister.md` — Phase 1 (Paginator state machine, filter input)
- `findings/plan_candy-input.md` — Phase 1 (buffer boundary conditions)
- `findings/plan_candy-wish.md` — Phase 1 (AsyncMiddleware blocks loop, PromiseAwait race)
- `findings/plan_sugar-veil.md` — Phase 1 (animation frame timing)

### Group E Source Plans
- `findings/plan_sugar-post.md` — Phase 1 (Attachment silent failure, unchecked fwrite)
- `findings/plan_sugar-prompt.md` — Phase 1 ($pid uninitialized on fork failure)
- `findings/plan_candy-log.md` — Phase 1 (log level filtering, handler consistency)
- `findings/plan_sugar-stash.md` — Phase 1 (path-repo closure verification)
- `findings/plan_candy-testing.md` — Phase 1 (TapeRecorder Dracula theme bug)
- `findings/plan_candy-freeze.md` — Phase 1 (WeakMap allocateColor cache)

### Group F Source Plans
- `findings/plan_candy-shine.md` — Phase 1 (BlockStack state not reset, withTheme shares stack)
- `findings/plan_candy-mosaic.md` — Phase 1 (mosaic grid layout edge cases)
- `findings/plan_candy-hermit.md` — Phase 1 (shell environment isolation)
- `findings/plan_candy-focus.md` — Phase 1 (focus ring rendering)
- `findings/plan_candy-fuzzy.md` — Phase 1 (fuzzy matcher state)
- `findings/plan_sugar-table.md` — Phase 1 (table layout overflow)
- `findings/plan_sugar-spark.md` — Phase 1 (empty data set guard)
- `findings/plan_sugar-toast.md` — Phase 1 (viewport overflow, non-cancellable timer)
- `findings/plan_repeated_logic.md` — Phase 1 (extract Clamp.php + validateNonNeg() to candy-core; deduplicate mutate() across 35+ libs)

## Phase 1 Implementation Steps

### Step 1: Execute Group A
1. Spawn 3 subagents in parallel:
   - **Subagent A1:** Implement candy-core Phase 1.1 (InputReader undefined var) + Phase 1.2 + Phase 2
   - **Subagent A2:** Implement candy-vt Phase 1.1 (Transitions thread-safe) + Phase 1.2 (Theme maps)
   - **Subagent A3:** Implement sugar-dash Phase 1 (Stack.php parse error + RingBuffer oldest())
2. Each subagent: review → implement → run tests → fix any issues → commit
3. After all 3 complete: commit to master, pull, continue to Group B

### Step 2: Execute Group B
1. Spawn 3 subagents in parallel:
   - **Subagent B1:** Implement sugar-reel Phase 1.1–1.3 (AudioPlayer SIGSTOP, mutate() ?? bug)
   - **Subagent B2:** Implement candy-pty Phase 1.1–1.3 (FD reuse race, EINTR timeout)
   - **Subagent B3:** Implement candy-query Phase 1.1–1.8 (all SQL injection fixes)
2. Each subagent: review → implement → run tests → fix any issues → commit
3. After all 3 complete: commit to master, pull, continue to Group C

### Step 3: Execute Group C
1. Spawn 6 subagents in parallel:
   - **Subagent C1:** honey-bounce (SpringChain::tick() returns new instances)
   - **Subagent C2:** sugar-charts (ChartExtras trait extraction)
   - **Subagent C3:** candy-async (AsyncOps deduplication)
   - **Subagent C4:** candy-buffer (AsyncOps deduplication)
   - **Subagent C5:** candy-sprinkles (Style immutable value object)
   - **Subagent C6:** sugar-glow (animation timing)
2. Each subagent: review → implement → run tests → fix any issues → commit
3. After all 6 complete: commit to master, pull, continue to Group D

### Step 4: Execute Group D
1. Spawn 6 subagents in parallel (6 libs from Group D)
2. After all complete: commit to master, pull, continue to Group E

### Step 5: Execute Group E
1. Spawn 6 subagents in parallel (6 libs from Group E)
2. After all complete: commit to master, pull, continue to Group F

### Step 6: Execute Group F
1. Spawn 6 subagents in parallel (first 6 of 9 libs + repeated_logic)
2. Commit, spawn remaining 3 subagents
3. Execute repeated_logic LAST (after all dependent plans complete)

## No-Action Items (Documented)

The following plans have findings marked as no-action — only documentation/clarification needed:

| Lib | Finding | Reason |
|-----|---------|--------|
| candy-core | Mutable trait `get_object_vars()` allocation | Standard PHP pattern; negligible performance impact |
| candy-core | Renderer token cache growth | Monitor for memory issues; add LRU cap if needed |
| candy-core | reconcileWantedSubscriptions() double-call | Pre-loop call handles edge cases; removing could break timing |
| candy-vt | `mutate()` helper extraction (Phase 9.1) | Current explicit pattern is documented as intentional |
| sugar-tick | Entire plan | False positive — no `src/Tick.php`; not a TUI component |

## Critical Context for All Subagents

- **PHP minimum:** 8.3 throughout (no PHP 8.4-specific features)
- **Immutable + fluent:** Every `with*()` returns a new instance via private `mutate()`
- **Tests required:** Every public method ≥ 1 test; snapshot byte tests for renderers
- **Commit cadence:** Commit after each lib's changes pass tests; pull before next group
- **Author:** Joe Huss `<detain@interserver.net>` for all commits

## Verification After Each Group

```bash
# Run tests for all libs in the group
cd candy-core && composer install && vendor/bin/phpunit
cd ../candy-vt && composer install && vendor/bin/phpunit
cd ../sugar-dash && composer install && vendor/bin/phpunit

# PHPStan level 9
cd candy-core && vendor/bin/phpstan analyse --level=9 src/
cd ../candy-vt && vendor/bin/phpstan analyse --level=9 src/
cd ../sugar-dash && vendor/bin/phpstan analyse --level=9 src/
```

All tests must pass with `failOnWarning="true"` before committing.

## Notes

- 2026-06-30: Master plan created by consolidating all 60 `findings/plan_*.md` files
- Delegation IDs from the original research session (`ref:*`) are preserved as cited in individual plans
- Groups are ordered by dependency criticality: foundation → consumers → leaf → DRY extraction
- Within each group, libs are ordered by finding count (most findings first within group)
- Each subagent should spawn a sub-subagent for actual code changes, then run review/fix cycles
- `findings/plan_sugar-tick.md` is a false positive — the plan is not for a TUI component and should be skipped
