# C-CTX-2.c — served settles run auto-memory, dream pass and auto-commit

Commit: `ec5d4c2a5914d2c5bbb8cafa49b4f15b12923270` on `cc/C-CTX-2.c` (base 33d015d4a, worktree
/home/sites/cc-cmp-C-CTX-2.c). Author/committer detain@interserver.net verified by separate greps,
zero Co-Authored trailers. Never pushed.

## Scope delivered
- `candy-code/src/Host/SessionHost.php` (hot file, minimal hunks):
  - `settleWith()` tail: the W7a-3 goal block now captures `$carriedOn = $this->turn !== null`
    BEFORE the goal decision (releaseQueue can re-arm `turn` when a queued prompt takes over),
    keeps the goal judge exactly as before, then calls `runSettleWork($carriedOn, $goal !== null)`.
    release()/settle ordering otherwise untouched, per the serialization note.
  - New `private runSettleWork()`: calls `TurnSettleWork::plan()` (C-CTX-2.b) with the session's
    engine from `$this->backend()`, workspace summary/title backends, memoryStore, history,
    `ledger()->capReached(...)`, sessionId, generation, root(), userConfig, sessionStore. Each
    non-null slot among consolidation / autoCommit / dream runs its factory under
    `RuntimeNoticeSink::using($this->notices, …)` (C-CTX-2.a per-host sink) and hands the resolved
    message to its lander. The suggestion slot is deliberately never run: a served session has no
    input box (C-CTX-2.e owns that decision).
  - Three landers, mirroring the Chat `HandlesTurnCompletion` arms byte-for-byte in semantics:
    bill FIRST via `ledger()->account($this->spend, $msg->usage, $this->usageRecordSink(), model)`
    with the OBS-3 model of the engine that actually ran the job (summary / title / the session
    engine captured at plan time for the dream), then post-release landing is billed-and-dropped
    (new `private bool $released`, set on the first line of `release()`), then the producer's
    `::notice()` row is appended via `appendMessageRow()` — or nothing when the producer returns
    null (consolidation/dream); `AutoCommittedMsg` always appends its row.
- `candy-code/docs/MEMORY.md`: Auto-memory and Dream-pass "When it runs" sentences extended —
  both now state they run at served settles too, with the shared on-disk throttle explicitly
  intended (one 5-minute auto-memory budget and one 2-hour dream budget per project, shared by
  the TUI and every served session of the checkout).
- `candy-code/docs/SERVER.md`: under `### Sub-agents, workflows, memory and todos`, a new
  "Settled-turn memory and commit work." paragraph: served settles run auto-memory consolidation,
  turn-mode auto-commit and the dream pass; spend is billed to the session total; the producer
  notice rows land in the transcript (`Auto-memory saved …`, `Auto-committed …`, `Dream pass …`);
  throttles shared on disk; the next-prompt suggestion deliberately not run. Hand-edited prose
  only — generated protocol tables untouched (weld regenerates).

## Tests
`candy-code/tests/Host/SessionHostTurnSettleWorkTest.php` (new, 3 tests / 35 assertions, green):
1. **Settled serve runs auto-memory, bills it on the summary model, shows its notice**: a forked
   EngineBackend turn settles; a forked EngineBackend summary (model `sum-model`, usage
   42tok/$0.003) answers the consolidation ops; asserts the `Auto-memory saved 1 note` notice row,
   session spend exactly 0.001+0.003, and — the OBS-3 pin — `usageModelRollups` carries the
   consolidation cost on `sum-model` while the turn rides `m` (the record sink's session-model
   fallback would merge them under `m`).
2. **Queued prompt carries the conversation on and suppresses the settle work**: submit a, submit
   b with `SubmitOptions::new()->withDelivery(QueueMode::Followup)` (the default `onEnter` STEERS —
   see deviations); both turns settle; exactly one consolidation request (`$asked === 1`), exactly
   one notice row, and its index is AFTER the final assistant row — with the suppression broken the
   plain (non-forking) summary backend resolves synchronously inside the first settle tail and the
   row lands early: a deterministic red. (This discriminator is verified — it produced the red that
   rooted out the test bug described below.)
3. **Turn-mode auto-commit runs through the checkpointer**: `CheckpointedRepoTrait` repo, engine
   runs a real `Write` tool inside the forked child, `userConfig autoCommit=turn`, no title
   backend (fallback subject); asserts the `Auto-committed ` notice row, `note.txt` on disk, and
   exactly 2 commits in `git log`. Dream is not pinned end-to-end (slot + producer are covered by
   TurnSettleWorkTest/DreamPassTest; the lander mirrors the consolidation lander's shape).

## Gate runs (plain pipe, cwd worktree candy-code, env -u LINES -u COLUMNS)
- New file alone: `OK (3 tests, 35 assertions)` — Time 00:02.578. Zero warnings/deprecations.
- `--filter 'SessionHost|TurnSettleWork|Idle|AutoCompact|Compaction'`:
  `Tests: 511, Assertions: 22506, PHPUnit Deprecations: 2` — all green; Time 01:59.9. The 2
  deprecations are sibling-side (the isolated new-file run shows none) and PHPUnit-level, not
  failures.
- `--filter 'MemoryDocumentationDrift|DocFigureProseDrift'`:
  `Tests: 99, Assertions: 3989, PHPUnit Deprecations: 2` — all green; Time 00:06.9. Doc edits keep
  both drift guards satisfied.
- five-guard `--filter 'ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift'`:
  `Tests: 133, Assertions: 15788, Failures: 2` — exactly the expected staleness pair
  (headline figure + artifact-vs-live enumeration; pinned 25,537, live higher by this lane's 3 new
  tests). GlobDialectDifferential, ChildWallClockBudget and DuplicatedTestHelperDrift all green —
  helper names (drainWork/recordingSummary/seedConversation/…) dodge the one-token-divergence law.
  suite-figure.json / durations.tsv untouched per law; re-pin is a weld duty.

## Deviations / disclosures
- Pin 2 passes `QueueMode::Followup` explicitly: SessionHost's default `onEnter` STEERS (cancels
  the in-flight turn → generation bump → the first settle bails as stale before `runSettleWork`),
  so a plain queued submit would exercise the steer path, not queue carry-on. Followup is the
  "queued prompt carries on" the brief describes.
- Pin 2's assistant-row count asserts 3 — the seeded conversation itself carries one assistant row
  plus one per served turn.
- Dream lander not end-to-end exercised from the host (double-fork provider-advance ambiguity);
  disclosed above.
- Debug detour during pin-2 diagnosis (a test-only anonymous class couldn't see a private const,
  plus a `??`-dropped host debug line) never reached the commit: SessionHost.php's committed hunks
  match the pre-debug verified shape; `CC_CTX2C_DEBUG` greps 0 in the tree.

## DUR rows owed (gate adds; lane never edits scripts/parallel-tests-durations.tsv)
- `candy-code/tests/Host/SessionHostTurnSettleWorkTest.php` — 3 tests (~2.6s wall incl. forks;
  suggest a conservative ~4s estimate).
