# C-CTX-2.c REVIEW-1 (round-1 independent reviewer)

VERDICT: FINDINGS (1 MAJOR + 1 MINOR, both step-local, block weld; core wiring otherwise clean and gate-reproduced)

Reviewed: /home/sites/cc-cmp-C-CTX-2.c @ ec5d4c2a5914d2c5bbb8cafa49b4f15b12923270 (branch cc/C-CTX-2.c, single commit, base 33d015d4a). Read-only; all claims disk-verified in the worktree.

## Findings

[MAJOR step-local] candy-code/src/Host/SessionHost.php:1068-1088 — release() does NOT cancel the idle auto-compaction alarm.
The wave assigned this step the C-CTX-6.a REVIEW-1:34 carry ("SessionHost.php release() never cancels idleAutoTimer … record for wave ledger"); the lane's review checklist item (4) states "release-cancel present … verify the diff actually cancels in release()". It does not. The diff adds `private bool $released = false` and sets it first in release(), but `idleAutoTimer` appears only at the property (:194), the cancel-and-rearm inside armIdleAutoCompaction (:761-763) and the fire site (:774-779) — no touch in release(). settleWith arms the alarm after every reply (`armIdleAutoCompaction()` right before runSettleWork), so a session that serves a turn and then closes keeps a live Loop timer whose closure captures $this; when it fires, onIdleAutoCompaction runs on the released host with no $released guard (the flag guards only the three settle landers, grep: released appears at the new flag + release() + the three landers only). At fire time lastPromptAt/isBusy guards still pass on a closed host, and requestAheadOfNeed executes a backend fold — billing the released ledger and save()/announceTranscriptRow after transcripts->flush() and lease?->release(). This is the same hazard class the landers guard for settle work, left open on the idle lane.
Fix: in release(), immediately after `$this->released = true;` (or beside notices->reset()), add:
    if ($this->idleAutoTimer !== null) {
        Loop::cancelTimer($this->idleAutoTimer);
        $this->idleAutoTimer = null;
    }
Loop is already imported (armIdleAutoCompaction uses it). Pair it with a pin: arm via settle with idleAuto config, release(), drain past the window, assert no fold request lands.

[MINOR step-local] candy-code/tests/Host/SessionHostTurnSettleWorkTest.php — the billed-and-dropped path is unpinned, though the file's own header (:38, "billed and display-dropped if it lands after release()") claims it. release() appears only in tearDown (:73-75, host cleanup). Deleting all three `if ($this->released) return;` guards keeps the suite green. The design's tests: list asked for exactly three pins, so the builder is brief-conformant, but the wave's item (3) explicitly asks whether the post-release path is "genuinely pinned" — it is not.
Fix: extend the consolidation fixture (its summary double is already holdable): settle, release() while the consolidation promise is in flight, resolve and drain, then assert spentUsd still includes the landing's price (billed) AND no 'Auto-memory saved' notice row was appended (dropped). That discriminates the guard in both directions.

[NIT] landDream has no end-to-end pin (disclosed by builder). Accepted: TurnSettleWorkTest + DreamPassTest cover the slot and producer, and the consolidation pin exercises the identical using($notices)-wrap → account → released-check → append skeleton. Not a weld blocker.

## Verified clean

1. Scope == brief's 4 files exactly (git diff --stat: docs/MEMORY.md 11, docs/SERVER.md 12, src/Host/SessionHost.php 167, NEW tests/Host/SessionHostTurnSettleWorkTest.php 326). No suite-figure.json / durations.tsv / dist touches; single commit; %ae and %ce verified as separate greps, both detain@interserver.net; zero Co-Authored (grep -ci = 0). The new test file is git-show --stat new → a durations.tsv row is owed at closeout (correctly not the lane's).
2. MEMORY.md concurrency: this lane's hunks are @@ -630 and @@ -731 (auto-consolidate / dream sections); C-CTX-1.c's MEMORY.md hunk is @@ -897,6 ('### Instruction diff' area, branch cc/C-CTX-1.c) — ~160-line separation, disjoint for parallel welds.
3. settleWith ordering: releaseQueue() → lastSettledAt + armIdleAutoCompaction() → `$carriedOn = $this->turn !== null; $goal = $carriedOn ? null : GoalState::activeIn(...); if ($goal !== null) scheduleGoalJudge($goal); runSettleWork($carriedOn, $goal !== null)` — plan() provably runs after the queue release and the goal decision, per the design block.
4. Landers bill-then-append: each landX does ledger()->account($this->spend, $msg->usage, usageRecordSink(), MODEL) FIRST with the model that actually ran (OBS-3: consolidation = summaryBackend->model() when EngineBackend, autoCommit = titleBackend->model(), dream = engine captured at plan time — dream's closure runs on the session backend, so that is the running engine), then `if ($this->released) return;`, then appendMessageRow(Message::notice(producer::notice())) or nothing when the producer notice is null (AutoCommittedMsg always rows, like Chat). Mirrors Chat HandlesTurnCompletion (:384-417). Closures execute under RuntimeNoticeSink::using($this->notices, …) so emitter-side notices route to the session inbox. Suggestion slot is never run (C-CTX-2.d owns it; disclosed).
5. pin-2 QueueMode::Followup: NOT a weakening. The default onEnter steer bumps the generation so settleWith's stale-gen bail skips runSettleWork entirely — Followup is the shape that carries on into a real settle, which is exactly where carriedOn=true must suppress all three; the row-index-after-last-assistant leg makes broken suppression land the notice early = deterministic red. assistantCount 3 disclosure is incidental (row-count helper), accepted.
6. Docs prose matches shipped behavior: AutoMemoryConsolidator::INTERVAL_SECONDS=300 and DreamPass 7200 with per-project on-disk throttle files ("THE THROTTLE IS ON DISK"; "for one project") back MEMORY.md's shared-budget sentences; SERVER.md's new paragraph matches the lander behavior (bill + display-only row, silence when nothing landed — AutoCommitter::turnCall resolves null on a null prepareTurn → no row) and the suggestion exclusion matches the code.
7. Gates reproduced (plain pipe, cwd=worktree candy-code, env -u LINES -u COLUMNS):
   --filter 'SessionHostTurnSettleWork' → Tests: 3, Assertions: 35, 2 foreign PHPUnit deprecations (AccessorDeprecationTest private data providers, pre-existing). Exact match.
   --filter 'SessionHost|TurnSettleWork|Idle|AutoCompact|Compaction' → Tests: 511, Assertions: 22,506, 1:58.56, OK — all green, builder's "+2 foreign deprecations" confirmed foreign.
   five-guard (ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift) → 133T, Failures: 2 = ONLY the staleness pair (pinned 25,537 vs live 26,235 — closeout re-pin domain); Glob/ChildWallClock/DuplicatedTestHelperDrift legs green, matching the builder's claim.
   --filter 'MemoryDocumentationDrift|DocFigureProseDrift' → Tests: 99, Assertions: 3,989, OK.

## Weld note

Fix both findings in-lane (single follow-up commit on cc/C-CTX-2.c) and re-run the SessionHost filter set; the release() cancel also belongs in the new test as a fourth pin (the MINOR fix). Docs need no further edit — SERVER.md already promises the alarm dies with the session in spirit; nothing in the added prose asserts the opposite.
