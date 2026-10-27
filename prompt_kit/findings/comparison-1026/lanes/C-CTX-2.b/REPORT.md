# C-CTX-2.b — Extract the turn-settle side work into one host-neutral gate

Verdict: DONE. Commit 8b6b440e3b3370a5d150499c16dfb02ef2ab302a on cc/C-CTX-2.b (re-read after commit; author+committer detain, zero trailers; tree clean).

## Shipped (worktree /home/sites/cc-cmp-C-CTX-2.b, branch cc/C-CTX-2.b, base b24e81154)

- **NEW `candy-code/src/Host/TurnSettleWork.php`** — final value with
  `TurnSettleWork::plan(carriedOn, goalActive, memoryStore, engineBackend,
  summaryBackend, titleBackend, history, capReached, sessionId, generation,
  projectRoot, userConfig, sessionStore, ?titleService)` returning four
  nullable `\Closure(): PromiseInterface` calls (`suggestion`,
  `consolidation`, `autoCommit`, `dream`) resolving PromptSuggestionMsg /
  MemoryConsolidatedMsg / AutoCommittedMsg / DreamPassCompletedMsg.
  Gates mirror `HandlesTurnCompletion.php:304-336` exactly: carried-on ⇒ no
  work at all; live goal ⇒ no suggestion (auto-commit keeps running, as the
  settle does); no memory store ⇒ no consolidation/dream; cap reached ⇒ no
  suggestion (and no title dial); `autoCommit` setting ≠ turn ⇒ no commit.
  GoalLoop-shape "one decision table, two hosts"; optional `TitleService`
  seam for the Chat fold (C-CTX-2.e) so its workspace-scoped service is
  injectable. DORMANT until C-CTX-2.c (SessionHost) / C-CTX-2.e (Chat).
- **`src/Workspace/AutoCommitter.php`** — added `static turnCall(userConfig,
  sessionStore, sessionId, projectRoot, history, capReached, titleBackend):
  ?\Closure`: the auto-commit body moved verbatim out of
  `Chat::scheduleAutoCommit` (minus the `Cmd::promise` host wrapper — the
  plan returns raw calls, the host wraps), plus a package-local
  `checkpointWorkspace` reader mirroring `Checkpoints::checkpointWorkspace`
  so Workspace never reaches into Host. `SessionStore` typed as the
  `SessionStore|EnhancedSessionStore|null` union — the two stores are
  unrelated final classes (Chat.php:379 declares the same union).
- **NEW `tests/Host/TurnSettleWorkTest.php`** — 6 tests pinning each gate
  one flip at a time over an all-gates-open baseline (fresh memory homes
  per expectation so the 5-min/2-h plan-time throttle claims don't
  cross-poison; assistant-terminated 400-byte-floor history).
- **`tests/Workspace/AutoCommitterTest.php`** — +2 tests: turnCall commits
  a turn's files with the plain fallback subject and no title dial when
  `titleBackend` is null (usage-null + sha==HEAD pins); turnCall refuses
  without the turn setting / a checkpoint it can read.

## TWO-COPY state (recorded per design block)

`Chat::scheduleAutoCommit` (Chat.php:15573-15647) and the inline settle
gates in HandlesTurnCompletion keep their copies untouched; the new plan is
byte-for-byte the same decision table. The duplicates die in C-CTX-2.e when
both hosts fold onto `TurnSettleWork::plan`. Until then
TurnSettleWorkTest is the drift tripwire.

## Gates (cwd candy-code, plain pipe, -u LINES -u COLUMNS, linked deps)

- `--filter 'TurnSettleWork|AutoCommitter'` → builder-reported **Tests: 20, Assertions: 264** (r1 static recount: only the two classes match the filter, 6+12=18 — welder re-run is the truth)
  green (2 PHPUnit Deprecations verified pre-existing at base).
- `--filter 'Workspace|Host\\'` → 121 tests, 777 assertions green.
- five-guard `ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift`
  → 133 tests, ONLY the expected staleness pair red (live enum +8 new
  test methods vs pinned figure; re-pin is a closeout duty).

## For the gate agent

- **NEW test file: `candy-code/tests/Host/TurnSettleWorkTest.php`** → needs
  its row in `scripts/parallel-tests-durations.tsv` (lane never stages it).
- Suite figure: +8 tests (6 TurnSettleWork + 2 AutoCommitter) — closeout
  re-pin carries it.

## Notes

- Observation (not a defect, pinned): a turn whose paths contain only
  untouched-then-added files produces no snapshot commit — the snapshot
  leg rides turns that entangle the user's earlier edits, matching the
  existing commit()'s documented "their own" split; AutoCommitterTest:110
  pins the observed two-subject chain.
- No docs/guards moved; no serialization files touched.


## Review round-1 erratum (lead-corrected @1024@)

r1 reviewer (static) proved the 6/12/18 recount vs the build-era 7/20 claims; gate legs all pinned, code unchanged — figures corrected in place above.
