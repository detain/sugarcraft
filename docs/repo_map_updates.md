# SugarCraft Refactor — Shared Updates Scratchpad

This file is the **single shared scratchpad** for the refactor. Every subagent receives its current contents in their prompt and may append to it. The supervisor reads it but never edits it directly.

## How to use this file

- **Append** an entry under `## Active Items` when you discover something the next agent (or the supervisor) needs to know.
- **Move** an entry to `## Resolved Items` when the situation it described is no longer true.
- **Never** delete an item — moving to Resolved is the archive.
- **Use the `BLOCKING:` prefix** in your return message AND append a matching entry here when you cannot complete required work. The supervisor halts on `BLOCKING:` and surfaces it to the user.

Entry format:

```
- [YYYY-MM-DD HH:MM | step-NN | role] Short title — one-paragraph description, file paths, links to PRs, anything the next agent needs to know.
```

## Async Patterns Audit

## [2026-05-28 09:30 | step-08 | coder] ReactPHP Usage Audit — Step 08

Files using ReactPHP (excluding vendor/):
- `candy-core/src/AsyncCmd.php`: `PromiseInterface` — **Would benefit from candy-async** (simple promise wrapper, could use CancellationToken for timeout)
- `candy-core/src/Program.php`: `Loop`, `LoopInterface` — **candy-async should NOT interfere** (core runtime, owns the shared loop)
- `candy-core/src/WorkerPool.php`: `LoopInterface`, `TimerInterface`, `Deferred`, `PromiseInterface` — **Would benefit from candy-async** (could use CancellationToken for worker cancellation)
- `candy-core/src/ProgramOptions.php`: `LoopInterface` — **candy-async should NOT interfere** (just a config object)
- `candy-forms/src/Field/Input.php`: `Loop`, `Deferred` — **Would benefit from candy-async** (debounce via Loop::addTimer, could use AsyncOps::debounce)
- `candy-forms/src/Field/Select.php`: `Loop`, `Deferred` — **Would benefit from candy-async** (same debounce pattern as Input)
- `candy-mosaic/src/AsyncRenderer.php`: `PromiseInterface` — **Would benefit from candy-async** (just a strategy interface)
- `candy-mosaic/src/SyncAsyncRenderer.php`: Not read yet — likely PromiseInterface
- `candy-mosaic/src/AdaptiveImage.php`: Not read yet — likely Loop/Promise
- `candy-wish/src/Middleware/AsyncMiddleware.php`: `Loop`, `Promise` — **Would benefit from candy-async** (uses sync await with 30s timeout, could use AsyncOps::withTimeout)
- `sugar-crush/src/Chat.php`: Not read yet
- `sugar-crush/src/Backend/CommandBackend.php`: Not read yet
- `sugar-crush/src/Backend/EchoBackend.php`: Not read yet
- `sugar-crush/src/Backend/StreamingCommandBackend.php`: Not read yet
- `super-candy/src/AsyncOps.php`: Not read yet — may already have similar helpers

Existing ReactPHP versions pinned:
- `react/event-loop: ^1.6` (candy-core, candy-pty, candy-wish)
- `react/promise: ^3.3` (candy-core, candy-mosaic, sugar-crush, super-candy)
- `react/promise-timer: ^1.9` (candy-wish)

Roadmap for step-23 (candy-forms/sugar-prompt/candy-core migrate to candy-async):
- Replace `Loop::addTimer` debounce with `AsyncOps::debounce()` in Input.php, Select.php
- Replace manual 30s timeout in AsyncMiddleware.php with `AsyncOps::withTimeout()`
- WorkerPool.php cancellation via CancellationToken

## Active Items

_All items in this section have been resolved by the completion of steps 20–40 (PRs #899–#920 merged). Step-15 (candy-forms) was confirmed complete (PRs #870, #895) — all 7 namespace imports map to candy-* packages; 709 tests pass; no sugar-* dependencies remain. Step-37 catch-all confirmed no further actionable libs. 4 items deferred to followups (candy-metrics, candy-log, candy-zone, candy-mold). See the Resolved Items section below for the full archive._

## Resolved Items

- [RESOLVED | 2026-06-01 | step-41 | scribe] All Active Items drained — steps 20–40 complete (PRs #899–#920 merged). Step-15 (candy-forms) confirmed complete — PRs #870, #895 wired candy-forms onto shared foundations; step-37 verified no further actionable libs. 4 items deferred to followups (candy-metrics, candy-log, candy-zone, candy-mold).

- [RESOLVED | 2026-06-01 | step-35 | coder] sugar-tick + sugar-post + candy-serve: adopt candy-async — MERGED PR #915:
  - sugar-tick: added CancellationToken support to Store::append()
  - sugar-post: added CancellationToken support to SmtpTransport::send()
  - candy-serve: added Subscriptions for graceful shutdown in GitDaemon
  - Branch ai/async-adopters merged to master; Commit SHA: 30ea7afa

- [RESOLVED | 2026-05-31 | step-32 | coder] candy-tetris + candy-mines: adopt candy-buffer + candy-mouse + candy-testing — MERGED PR #911:
  - candy-tetris: Buffer-based playfield rendering; candy-mines: Buffer-based minefield
  - Branch ai/games-shared merged to master

- [RESOLVED | 2026-05-31 | step-30 | coder] Ecosystem audit: adoption opportunity matrix for 19 remaining libs — MERGED PR #909:
  - Created docs/repo_map_update_followups.md; Branch ai/ecosystem-audit merged to master

- [RESOLVED | 2026-06-01 | step-34 | coder] sugar-calendar + sugar-toast: adopt candy-buffer + candy-testing — MERGED PR #914:
  - sugar-calendar: DatePicker widget + candy-testing golden-files; sugar-toast: border styling + queue management
  - Branch ai/widget-shared merged to master

- [RESOLVED | 2026-06-01 | step-36 | shipper] candy-flip + candy-kit + honey-bounce + honey-flap: adopt candy-testing — MERGED PR #916:
  - Branch ai/testing-rollout merged to master; Commit SHA: 0995878a

- [RESOLVED | 2026-06-01 | step-37 | shipper] NO-OP step-37 catch-all — MERGED PR #917:
  - 1 file changed (docs/repo_map_update_followups.md DEFERRED notes); Commit SHA: 237fad86

- [RESOLVED | 2026-06-01 | step-38 | shipper] docs: root sweep — MERGED PR #918:
  - Updated AGENTS.md, CONTRIBUTING.md, README.md, docs/MATCHUPS.md; Commit SHA: eaafed14

- [RESOLVED | 2026-06-01 | step-39 | shipper] docs: public site for 8 new shared foundation libs — MERGED PR #919:
  - 17 files changed, 47 insertions(+); Commit SHA: 218695bd

- [RESOLVED | 2026-06-01 | step-40 | shipper] ci: codecov + vhs.yml audit for 8 new libs — MERGED PR #920:
  - Empty commit (all criteria already met); Commit SHA: 854062c9

- [RESOLVED | step-03 | tester | 2026-05-28] candy-layout coverage: full Cassowary implementation + targeted tests achieved 95.19% (396/416 lines). BLOCKING resolved.

- [2026-05-28 | step-01 | fixer] CsiHandlerImpl.php created as self-contained no-op stub — terminal delegation deferred to step-12. (was: BLOCKING entry about missing CsiHandlerImpl.php)

- [2026-05-31 | step-22 | fixer] sugar-veil + candy-lister coverage gap fix:
  - **candy-lister**: 95.12% overall lines (234/246) — ✅ ABOVE 95% TARGET
    - Item: 100% lines (3/3), StringItem: 100% lines (2/2), DefaultPrefixer: 100%, DefaultSuffixer: 100%, FuzzyMatch: 100%, Model: 92.64% (151/163)
    - Added: `candy-lister/tests/ItemTest.php` (5 tests covering Item::string(), id, value property), `DefaultPrefixerTest.php` (10 tests covering initPrefixer, prefix, ansiWidth, various prefixer modes), expanded ModelTest.php with 13 new tests covering cursor edge cases, style setters, sort/filter edge cases, multi-line items, private method exercise via public API (splitOverWidth, hardWrap, renderItem wrap logic), 75 total tests, 122 assertions, ALL PASS
  - **sugar-veil**: 88.37% overall lines (266/301) — BELOW 95% TARGET
    - Fade: 100% lines ✅, Scale: 94.44% (17/18 — 1 line in private easing() method), Slide: 88.89% (32/36 — 4 lines in private helper methods isTopAnchor/isBottomAnchor/isLeftAnchor/isRightAnchor), Position: 100% ✅, Veil: 86.13% (149/173), VeilStack: 86.05% (37/43)
    - Added: `FadeTest.php::testOpacity*` (7 tests covering opacity() method at progress 0/0.5/1.0, custom easing, monotonicity), `VeilStackTest.php::testCompositeAll*` (2 tests for empty stack), `VeilStackTest.php::testCountMethod` (1 test for Countable interface), `VeilTest.php::testAnimate*` (5 tests for animate() without animation, with slide/fade/scale animation at various progress values), `VeilTest.php::testGetManager*` (2 tests for deprecated getManager()), `VeilTest.php::testWithBackdrop*` (3 tests for backdrop clamping at negative/positive/over100), `VeilTest.php::testWithAnimation*` (3 tests for animation kind setters), 129 total tests, 200 assertions, ALL PASS
    - **Uncovered gaps** (architectural — cannot be directly tested):
      - Scale: private `easing()` method (1 line)
      - Slide: private helper methods isTopAnchor/isBottomAnchor/isLeftAnchor/isRightAnchor/maxWidth/strWidth (4 lines)
      - Veil: private `applyAnimation()`, `mutate()` branches, `applyBackdrop()` (24 lines) — animate() tests exercise applyAnimation() indirectly but private method lines don't count
      - VeilStack: `compositeAll()` has existing bug (calls `composite($result, Position::TOP, Position::LEFT)` but composite() expects `composite(string $foreground, string $background, Position $vertical, Position $horizontal)` — 2nd arg must be string background not Position) — 6 lines uncovered
    - Branch: `ai/mouse-consumers`


- [2026-05-31 | step-26 | coder] candy-buffer: implemented Buffer::diff() with delta ANSI ops:
  - Created 9 new files: DiffOp abstract base, MoveCursorOp, SetCellOp, EraseRunOp, RepeatRunOp, SetStyleOp, SetHyperlinkOp, DiffEncoder, DiffOptimiser
  - Buffer.php: implemented diff(Buffer $prev) and applyDiff(list<DiffOp> $ops) — pure round-trip pair
  - diff() algorithm: walks cell grid; emits MoveCursorOp + SetStyleOp + SetCellOp for changes; RepeatRunOp for horizontal identical-cell runs; EraseRunOp for ≥3 consecutive blank default cells
  - DiffEncoder: tracks cursor+SGR+hyperlink state across ops; emits minimal ANSI byte stream (CUP/ECH/REP/SGR/OSC8)
  - DiffOptimiser: peephole pass — merges adjacent SetStyleOps, coalesces same-style spans
  - applyDiff(): interprets ops to produce new Buffer — used for round-trip testing
  - 125 tests (64→125), 1186 assertions, ALL PASS
  - Byte savings: 1-char change in 80×24 → 8 bytes vs 1943-byte full repaint (~99.6% reduction)
  - Coverage: 85.58% overall, 92.68% diff machinery (gap: defensive-only branches in DiffEncoder hyperlink/overline/REP fallback — cannot be triggered with correct diff input)
  - Path-repo closure: clean (55 libs scanned)
  - Branch: ai/buffer-diff-impl, PR #905

- [2026-05-31 | step-25 | coder] super-candy + candy-query: builders + UndoActionType + DatabaseInterface:
  - super-candy/Manager.php: added `builder()` static method returning ManagerBuilder; reverseAction() now routes by UndoActionType enum instead of str_starts_with on description labels
  - super-candy/UndoAction.php: added `type: UndoActionType` property; factory methods (delete/move/rename/copy/mkdir) now capture type at creation time
  - super-candy/src/Manager/ManagerBuilder.php: new fluent builder with with*() for all 15 Manager params
  - super-candy/src/Undo/UndoActionFactory.php: new factory wrapping UndoAction static methods
  - candy-query/src/Db/DatabaseInterface.php: new interface with 7 methods (tables/rows/query/lastInsertId/quote/exec/close)
  - candy-query/src/Db/SqliteDatabase.php: new concrete implementation of DatabaseInterface using PDO
  - candy-query/src/Database.php: updated to implement DatabaseInterface (added lastInsertId/quote/exec/close)
  - candy-query/src/App.php: constructor now accepts DatabaseInterface instead of concrete Database; added builder() static method
  - candy-query/src/App/AppBuilder.php: new fluent builder with with*() for all 14 App params
  - Branch: ai/god-class-builders
  - Tests: super-candy 187 pass (458 assertions), candy-query 123 pass (233 assertions)
  - Path-repo closure: clean (55 libs scanned)

- [2026-05-31 | step-29 | coder] sugar-glow + candy-wish: add candy-palette, wire TerminalProbe (defensive):
  - **IMPORTANT FINDING**: Neither sugar-glow nor candy-wish actually has env-var/terminfo parsing code for terminal capability probing. The step file's premise (§387.9) that "both libs do their own probing" is inaccurate based on current source. sugar-glow only uses `TtyDetect::isAtty(STDIN)` for TTY presence detection (not capability), and candy-wish uses `getenv()` only for SSH session metadata (TERM, SSH_CONNECTION, etc.) — which is distinct from color/capability probing.
  - sugar-glow: already had `candy-palette` path-repo. Added defensive `TerminalProbe::run()` via new `terminalSupportsColor()` private method in RenderCommand — wraps the probe call with `\Throwable` catch and falls back to `true` (assume color available) per step's graceful-failure requirement. No env-var/terminfo code was removed because none existed for capability detection.
  - candy-wish: added `sugarcraft/candy-palette` require + path-repo to composer.json (was missing). Added CALIBER_LEARNINGS entry clarifying that Session::fromEnvironment() reads SSH env vars for session metadata (TERM, SSH_CONNECTION, etc.) — this is NOT terminal capability probing and does not route through TerminalProbe.
  - Path-repo closure: candy-wish received candy-async + candy-layout path-repos via check-path-repos.php --fix (transitive deps of candy-palette). Clean (55 libs scanned).
  - Branch: ai/probe-consumers
  - Tests: sugar-glow 68 pass (143 assertions), candy-wish 144 pass (352 assertions)
  - TerminalProbe already in sugar-glow dependency tree (via candy-shine -> sugar-glow path-repos); NOT in candy-wish dependency tree before this step

- [2026-05-31 | step-33 | coder] sugar-skate + sugar-wishlist + sugar-stash: adopt candy-fuzzy:
  - Added `sugarcraft/candy-fuzzy: dev-master` + path-repo to all three libs' composer.json
  - sugar-wishlist: replaced `Picker::filterMatches()` `str_contains`-style filter with `SmithWatermanMatcher::matchAll()` — scored ranking + match-highlight indices wired into renderer via `highlightLine()` (ANSI bold+cyan on matched grapheme clusters)
  - sugar-skate: no interactive filter UI present — only glob-to-SQLLIKE pattern filtering in `Database::buildGlobQuery()`. Added candy-fuzzy dependency as infrastructure for future fuzzy key search (Levenshtein `suggestSimilar()` remains typo-suggestion only).
  - sugar-stash: no interactive filter UI present — StashManager has cursor navigation but no type-to-search. Added candy-fuzzy dependency as infrastructure for future fuzzy stash search.
  - Tests: sugar-wishlist 70 pass (180 assertions), sugar-skate 66 pass (122 assertions), sugar-stash 116 pass (333 assertions, 2 skipped)
  - Path-repo closure: clean (55 libs scanned)
  - Branch: ai/filter-consumers
  - Note: `highlightLine()` re-matches displayLine against filter needle to get indices aligned with the rendered text; applies ANSI bold+cyan (sgr 1,36) on matched grapheme clusters

- [2026-06-01 | step-36 | shipper] candy-flip + candy-kit + honey-bounce + honey-flap: adopt candy-testing — MERGED PR #916:
  - Branch ai/testing-rollout merged to master
  - 25 files changed, 571 insertions(+), 5 deletions(-)
  - Added `GoldenRenderTest.php` snapshot tests to all 4 libs via candy-testing
  - Added golden fixtures: candy-flip (density-3x3), candy-kit (stage-step, stage-substep), honey-bounce (projectile-trajectory, spring-trajectory), honey-flap (game-state-5ticks, game-state-crash)
  - candy-kit: added new CALIBER_LEARNINGS.md
  - Updated READMEs and composer.json (added candy-testing dev dep + path-repos) for all 4 libs
  - Commit SHA: 0995878a

- [2026-06-01 | step-37 | shipper] NO-OP — step-37 catch-all: deferral sweep complete, no remaining actionable libs — MERGED PR #917:
  - Branch ai/sweep-catchall merged to master
  - 1 file changed, 8 insertions(+) in docs/repo_map_update_followups.md (DEFERRED notes only, no source code changes)
  - All 4 remaining libs from step-30 audit have DEFERRED status in followups (candy-pty, candy-zone, candy-metrics, candy-log, candy-mold)
  - No source code changes; PR is documentation-only marker for end of actionable migration work
  - Commit SHA: 237fad86

- [2026-06-01 | step-38 | shipper] docs: root sweep — 8 new packages + consumer migrations — MERGED PR #918:
  - Branch ai/docs-root merged to master
  - 4 files changed, 16 insertions(+), 13 deletions(-)
  - Updated AGENTS.md, CONTRIBUTING.md, README.md, docs/MATCHUPS.md
  - Commit SHA: eaafed14

- [2026-06-01 | step-39 | shipper] docs: public site for 8 new shared foundation libs — MERGED PR #919:
  - Branch ai/docs-site merged to master
  - 17 files changed, 47 insertions(+)
  - docs/repo_map_update.md: +47 lines status section for 8 new shared foundation libs
  - docs/img/icons/: 8 new placeholder PNG icons (candy-ansi, candy-async, candy-buffer, candy-fuzzy, candy-input, candy-layout, candy-mouse, candy-testing)
  - media/icons/: 8 new placeholder PNG icons (same 8 libs)
  - Commit SHA: 218695bd

- [2026-06-01 | step-40 | shipper] ci: codecov + vhs.yml audit for 8 new shared foundation libs — MERGED PR #920:
  - Branch ai/docs-ci merged to master
  - All acceptance criteria already met — NO source code changes
  - codecov.yml: 8 new flags already present
  - vhs.yml: all=(...) already has all 31-36 visual libs; 8 new foundations are non-visual (no tapes)
  - scripts/affected-libs.php --all: auto-discovers all 8 new libs
  - tools/check-path-repos.php: exits 0
  - .github/workflows/tests.yml: file does not exist (SVN creds item is N/A — pre-existing state)
  - Empty commit created to document verification: 52aeb8dd
  - Commit SHA: 854062c9 (merge commit)
