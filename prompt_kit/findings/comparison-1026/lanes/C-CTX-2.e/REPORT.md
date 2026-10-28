# C-CTX-2.e — the TUI settle consumes the shared gate

Commit: f03994e49eb0242da969dbec22fe80e5c4a4ddad on cc/C-CTX-2.e (base 33d015d4a, not rebased, not pushed). Author/committer detain@interserver.net verified (%ae/%ce separate greps = 1, zero Co-Authored-By, plain -m, no hook).

## What landed

- **src/Chat/Handlers/HandlesTurnCompletion.php** (AssistantMsg settle): the four inline gates (suggestion, consolidation, auto-commit, dream) replaced by one `TurnSettleWork::plan(carriedOn:, goalActive:, ...)` call; slots handed to the loop unchanged (`$suggest`/`$autoCommit` via Chat's wrapping methods, `$consolidate`/`$dream` raw slots wrapped in `Cmd::promise` here as before). Goal judge + notify keep their inline gates. Batch order preserved: doneCmd, suggest, consolidate, autoCommit, dream, judge, notify, measure. Throttle-claim order preserved (plan evaluates named args suggestion→consolidation→autoCommit→dream, same as the old gate sequence).
- **src/Chat.php**: `schedulePromptSuggestion(?\TurnSettleWork $work = null)` and `scheduleAutoCommit(?\TurnSettleWork $work = null)` now delegate to the table — settle hands in its plan; a standalone call falls back to a plan for just its own slot (`memoryStore: null` ⇒ no throttle claims; `engineBackend: null`/`summaryBackend: null`). Chat's ~70-line duplicate auto-commit body deleted — verified `AutoCommitter::turnCall()` (C-CTX-2.b) landed at src/Workspace/AutoCommitter.php:152 with byte-equivalent gating before deleting. The deleted body's sole caller of `Chat::checkpointWorkspace()` is gone; the now-orphaned delegator deleted too (phpstan level-5 would otherwise flag it; zero other src/test refs).
- **tests**: parity assertion `testTheSharedTableSchedulesTheSameSetTheTuiSettleRuns` added to **tests/Chat/AutoCommitTurnTest.php** and **tests/Chat/AutoMemoryConsolidationWiringTest.php** — the passes the TUI settle actually runs (each batch child resolved, remeasure stamp filtered) must equal the slots the table schedules for the same flags, plus a stand-down row (mode `off` / `memoryStore: null` ⇒ empty set on both paths). The consolidation throttle claims at plan time (one turn cannot ask twice), so the wiring test's table access reads a fresh store corner — same decision, same set; documented in the test. Helper `settleScheduledPasses` byte-identical across the two files (DuplicatedTestHelperDrift-accepted policy).

## Behaviour note

`carriedOn: $done->inFlight` mirrors the old `$done->inFlight ? null : ...` on every gate; `projectRoot: $done->projectRoot()` is equivalent for the auto-commit slot because captured checkpoints only ever exist for explicit roots (TurnController gates capture on a non-empty root). `spendCapReached()` read once instead of four times — pure predicate.

## Gates (plain pipe, cwd candy-code, env -u LINES -u COLUMNS; worktree vendor created via `php scripts/refresh-deps.php --mode=linked --libs=candy-code` — OK, path-repos guard rc=0, zero tracked locks)

- `--filter AutoCommitTurnTest`: 5T / 71A OK (+1 parity)
- `--filter AutoMemoryConsolidationWiringTest`: 4T / 24A OK (+1 parity)
- `--filter 'AutoCommit|AutoMemory|TurnSettleWork|HandlesTurnCompletion|PromptSuggestion|DreamPass|TitleService'`: 81T / 743A, 1F = **inherited base-red**, see below
- five-guard (`ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialect|ChildWallClock|DuplicatedTestHelperDrift`): 133T / 15781A, 2F = exactly the staleness pair (figure re-pin is gate/closeout duty; this lane never touches suite-figure.json). Pair was already red at base 33d015d4a (pinned 25,537 vs live 26,234 — other lanes' tests); this lane adds +2T, zero new test files ⇒ durations.tsv untouched by design.
- php -l clean on all four files. Full suite never run.

## Inherited red (not this lane)

`DreamPassTest::testChatSchedulesThePassAtTurnCloseAndReportsWhatChanged:288` ("a backend that is not an engine schedules no pass") fails IDENTICALLY on the pristine main tree at 33d015d4a (verified by running there). Cause is base code, not this change: `Chat::measureContextCmd()` returns a closure unconditionally, and the base settle's own `assertNull($none)` expectation predates it — the echo-chat settle has returned a measure Cmd since P-CTX.d. Slot-by-slot instrumentation confirmed the four shared-table slots are null exactly where the base gates were null. Gate owner/weld should adjudicate that test's expectation (fixing DreamPassTest is outside this lane's files).

## Seams for C-CTX-2.c / weld (files NOT owned here)

1. `src/Host/TurnSettleWork.php` class docblock "DORMANT UNTIL WIRED: no host calls plan() yet. Chat keeps its inline gates…" is now false — C-CTX-2.e wired the TUI; the paragraph should flip with C-CTX-2.c's SessionHost wiring.
2. `src/Workspace/AutoCommitter.php` turnCall docblock phrase "what Chat::scheduleAutoCommit() does today" — scheduleAutoCommit now delegates to this very method; wording should track.
3. SessionHost-side parity pin (it consults the same `plan()` with its own flags) belongs to C-CTX-2.c's tests; the two new parity tests here pin both access paths of the one table from the TUI side.
