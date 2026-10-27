# cure-w7 — CLOSED (branch cc/cure-w7 @ 08862c003, worktree /home/sites/cc-cmp-cure-w7, never pushed)

## What
W7 wave-review r1 MINOR: `candy-code/src/Session/EnhancedSessionStore.php` —
`saveTranscript()` (:1987 era) and `appendSessionEvent()` (:2376 era) still lazily
`createSession($id,'candy-code','unknown')` for tombstoned ids, the resurrection class
C-SES-9.a forecloses on the usage-ledger path only.

Fix: both paths consult `sessionTombstoned()` (the C-SES-9.a private helper) BEFORE the
lazy mint, mirroring `appendUsageRecord()`'s false-for-tombstoned-only polarity:
a tombstoned id writes NOTHING (sessions row, transcript/event child, search index,
`updateSession` stamp — the FK on the shared `PRAGMA foreign_keys=ON` connection is
exactly why the refusal skips the whole save, not just the mint). saveTranscript
returns void silently; appendSessionEvent answers seq 0 — the same "no event"
sentinel `sessionEventBounds()`/`latestSeq()` give for a log that never was
(EventLog's own law: "a durable event that could not be written must not be
broadcast either"; the broadcast-side seq-0 guard is an EventLog/Host/ seam, not
edited — outside this lane's file set). NEVER-seen-id lazy mint is byte-preserved:
the gate sits inside the same `session() === null` arm, after the tombstone read.

Docblocks of both methods carry the exception + polarity sentence (cure-W7 dated).
`sessionTombstoned()`'s table-absence tolerance (legacy files → lazy mint) rides for
free: both gates call it.

## Files (explicit-path staged, nothing else touched)
- candy-code/src/Session/EnhancedSessionStore.php (+docblock, +2 gates)
- candy-code/tests/Session/UsageRecordsTest.php (Message import + 2 pins; NO new test
  files → no durations.tsv duty)

## RED-BEFORE proof (tests committed after the red run, src fix after that)
Pre-fix, `--filter UsageRecords`: `FAILURES! Tests: 23, Assertions: 104, Failures: 2`
— exactly `testATranscriptSaveForADeletedSessionDoesNotResurrectTranscript`
(:327 resurrected full row) and
`testASessionEventForADeletedSessionDoesNotResurrectEvents` (:352 "1 is identical to 0").

## GREEN gates (plain pipe, cwd candy-code, env -u LINES -u COLUMNS, linked refresh-deps state 23/23)
- `--filter UsageRecords` → 23T/108A OK (2 deprecations: pre-existing family shape, rode the base too)
- `--filter 'UsageRecords|SessionStore|Tombstone'` → 130T/477A OK
- `--filter 'SessionEventsTable|TranscriptResume|EventLog|DebouncedTranscript|ShareStore'` → 61T/306A OK
  (pins the preserved polarity: `testAppendRecreatesAMissingSessionRow`,
  `testSavingRecreatesASessionRowAnotherClientDeleted`, fork + prune-cascade arms)
- five-guard `ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift`
  → 133T/15809A, 2F = STALENESS PAIR ONLY (pinned 25,537 vs live 26,272; closeout re-pin duty — +2T mine in the live enumeration, pre-pair live was 26,270 per W7 review)

## DUR list
NONE — no new test files.

## Commit
08862c003f07f61dfa29b3f5ffa54270d28cf56f — `%ae`/`%ce` detain@interserver.net (1/1
separate greps), zero Co-Authored trailers, plain -m, tree clean, NOT pushed.
