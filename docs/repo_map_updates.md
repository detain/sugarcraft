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

- [RESOLVED | 2026-05-29 | step-15 | tester] BLOCKING: candy-forms Select.php: `withFuzzySuggestions()` calls `$this->mutate(fuzzyCandidates: ...)` but `mutate()` method signature doesn't accept `fuzzyCandidates` parameter — FIXED by Fixer: mutate() now accepts `?array $fuzzyCandidates = null, bool $fuzzyCandidatesSet = false`.

- [2026-05-29 | step-15 | tester] candy-forms + candy-fuzzy test coverage summary:
  - candy-forms: 87.33% lines (2434/2787) — +0.61% from previous. 706 tests (+12 from tester additions).
  - candy-fuzzy: 96.69% lines (175/181) — above 95% target.
  - Added 10 new tests in SelectTest.php: withFuzzySuggestions, fuzzy() alias, blur, async suggestions setup, SuggestionsReadyMsg handling, focus/blur immutability, enum mode, height setter, short-form methods, and 2 fuzzy filter integration tests.
  - BLOCKING resolved — `withFuzzySuggestions` no longer throws "Unknown named parameter $fuzzyCandidates".
  - SmithWatermanMatcherTest already has `testAmbiguousQueryAbOrderingAndIndices` covering the exact ambiguous query test from brief.
  - Select coverage improved from 60.58% to 70.80% (83→97/137 lines). Remaining uncovered: fuzzy filter lines 203-218 (need ItemList filter mode integration), scheduleAsyncSuggestions lines 247-269 (private async callback, needs event loop).
  - Coverage shortfall: step-15 candy-forms @ 87.33% (target 95%) — gap due to FilePicker/Entry (11.11%), Group (68.75%), Select (70.80%), Input (75.64%), ItemList (83.86%) which are infrastructure classes requiring extensive setup.

- [2026-05-28 08:00 | step-03 | coder] candy-layout: 56 tests, 152 assertions, OK. GreedySolver passes all golden tests (bit-for-bit parity with candy-sprinkles Solver). CassowarySolver is simplified prototype (~66% lines, hand-rolled per researcher findings). Coverage: 78.16% overall (below 95% target). Files created: candy-layout/{composer.json,phpunit.xml,README.md,CALIBER_LEARNINGS.md,src/{LayoutSolver,Region,Direction,Constraint,Constraint/{Length,Min,Max,Fill,Percentage,Ratio,Constraint},GreedySolver,CassowarySolver,Tableau}.php,lang/en.php,tests/{Constraint,GreedySolver,CassowarySolver}Test.php}. path-repo clean (50 libs). Research: hand-roll Cassowary (600-900 LoC for 1D); php-tui/cassowary lacks edit vars; kiwi-php archived.

- [2026-05-28 06:00 | step-02 | coder] candy-buffer: 29 tests, 58 assertions, 100% OK. Files: candy-buffer/{composer.json,phpunit.xml,README.md,CALIBER_LEARNINGS.md,src/{Buffer,Cell,Position,Region,Style,Hyperlink,DiffOp,Lang}.php,lang/en.php,tests/{Buffer,Cell}Test.php}. Root wiring: composer.json, docs/MATCHUPS.md, codecov.yml, docs/index.html, docs/lib/candy-buffer.html, README.md. Buffer::diff() stub returns []. Width::graphemeWidth() from candy-core used for wide-char width (no new dep needed). Media icon (media/icons/candy-buffer.png) pending — Scribe/Shipper to add. path-repo check: clean (49 libs scanned).

- [2026-05-28 00:00 | step-00 | tester] Verified actual artifact count is 61 repo_map_* files total (42 step files step_00–step_41, 8 role files, 3 core files plan_prompt/supervisor/updates, plus 8 supporting files: repo_map_prompt.md, repo_map_prompt_pr.md, repo_map_update.md, repo_map_update_prompt.md, repo_map_update_stage{1,3,4,5}.txt). Tester brief said 46/57 — actual is 61; all excess files are supplementary intermediate artifacts, not missing required files. All 42 step files have all 6 canonical sections. All 8 role files are non-empty. Supervisor has 42 unchecked entries. git tree is dirty only from the Coder's expected 1-line append to repo_map_updates.md. Coverage step is a no-op (no code changed). Verdict: ALL PASS.
- [2026-05-28 00:00 | step-00 | scribe] Scribe brief is a NO-OP — "No docs to write. Confirm docs/repo_map_update.md (the analysis doc) is unmodified." Confirmed: `git diff docs/repo_map_update.md` returns empty; last commit touching it is ee80cdf3 (pre-refactor). Stale counts in repo_map_step_00.md (says 34 steps / 45 artifacts; actual is 42 steps / 61 repo_map_* files) and the placeholder timestamp flagged by the reviewer are NOT in scope for this step's Scribe brief, so step_00.md was left untouched. Suggest the supervisor schedule a one-line doc-fix step (or fold into step-41 retrospective) to update step_00.md's acceptance-criteria counts to match the post-expansion plan (42 steps / 61 artifacts / 42 unchecked supervisor entries). No README, CALIBER_LEARNINGS, MATCHUPS, PROJECT_NAMES, docs/index.html, or docs/lib/* changes were appropriate — no source files touched in step-00.
- [2026-05-28 05:42 | step-01 | tester] candy-ansi test suite: 131 tests, 240 assertions, 100% coverage (320/320 lines, 38/38 methods, 5/5 classes). Test files: ParserTest.php (51 tests), HandlerAdapterTest.php (33 tests), OscHandlerImplTest.php (5 tests), TransitionsTest.php (42 tests). Coverage environment issue: pcov showed 0% when invoked normally; xdebug mode needed explicit enable (`php -d xdebug.mode=coverage`). Tests cover: all ECMA-48 state transitions, CSI dispatch (CUU/CUD/CUF/CUB/CUP/SGR/ED/EL/DECSET/DECRST/DECSTBM/TBC/CBT/CHT), OSC dispatch (title/hyperlink), UTF-8 multi-byte sequences, edge cases (malformed sequences, BEL vs ST terminators, premature ST, 7-bit vs 8-bit C1). Handler/CsiHandler/OscHandler interfaces excluded from coverage (no executable code).

- [2026-05-29 | step-15 | coder] candy-forms: adopted 4 shared packages. composer.json: added `sugarcraft/candy-buffer`, `sugarcraft/candy-layout`, `sugarcraft/candy-testing` (dev), `sugarcraft/candy-fuzzy` (already had). Path-repos propagated via `php tools/check-path-repos.php --fix` (41 issues fixed). Select filter now uses `\SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher` internally (public `withFilter(callable)` preserved). `Buffer::toAnsi()` implemented (candy-buffer/src/Buffer.php) — SGR RGB + attrs + OSC 8 hyperlinks. TextInput/TextArea still use string rendering (fallback per step-15 brief); Buffer-based ANSI rendering needs per-cell Sprinkles\Style→Buffer\Style mapping — tracked for step-26. Form.php gained `withConstraints(array $constraints)` — stores constraints for LayoutSolver routing (view() routing deferred to step-26). All 692 tests pass, path-repo closure clean.

## Resolved Items

- [RESOLVED | step-03 | tester | 2026-05-28] candy-layout coverage: full Cassowary implementation + targeted tests achieved 95.19% (396/416 lines). BLOCKING resolved.

- [2026-05-28 | step-01 | fixer] CsiHandlerImpl.php created as self-contained no-op stub — terminal delegation deferred to step-12. (was: BLOCKING entry about missing CsiHandlerImpl.php)
