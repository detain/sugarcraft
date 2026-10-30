# C-CTX-5.b — FIX-1 (round 1)

VERDICT: both step-local MINORs cured, each pinned non-vacuously (M1 → exactly 1 red, M2 → exactly 1 red); NIT corrected below. Base lane tip 240f0f425; family gate 93T/529A, SessionHost filter 84T, file 9T/129A, guard union 226T/1F-foreign-standing-red. No new *Test.php file.

## MINOR-1 — idle watch now stamps its model (SessionHost.php:1017-1024)

The idle-watch mint inside `if ($this->requestAheadOfNeed($this->history, $this->history))`
now does exactly what the dispatch arm does: reads `$this->backend()`, takes the
`EngineBackend::model()` (or `''` for a non-engine backend), and folds it into the
held slot. A model-A idle prefetch can no longer ride a `/model` switch into a
model-B session.

Pin: `testTheIdleWatchStampsItsModelForTheSwitchRule` — fires the idle watch under a
model-A `EngineBackend` on an 88k window over the floor, asserts the slot carries
`model-a`, switches to model-B, submits, and asserts the latch no longer holds the
model-A slot and no model-A record splices into the history.
Mutation M1 (delete the stamp trio): exactly this one test reds (`'ABSENT'`).

## MINOR-2 — drop arm is model-only; the floor is a request rule

- `CompactionService::aheadOfNeedModelMatches(?string $heldModel, string $currentModel): bool`
  added (CompactionService.php:1364); `aheadOfNeedAllowed()` (:1348) now delegates its
  model half to it (truth table byte-identical, docblock truth-flipped).
- `SessionHost.php:2432`: the drop arm calls `aheadOfNeedModelMatches` instead of
  `aheadOfNeedAllowed`, so a same-model (or legacy un-stamped) slot is kept however
  small the session; the block comment (:2419-2427) and the dispatch stamp comment
  (:2446-2451) were rewritten to match the code.

Scenario correction found while pinning: the LANDED-record case cannot reach the drop
arm at all — when the blocking fold fires in the same `submit()`, `SessionHost.php:1639-1646`
splices and consumes a landed slot before the ahead-of-need block runs (verified by an
instrumented run: `heldModel=NULL tokens=0` by the time the arm saw it). The reachable
harm is therefore the IN-FLIGHT paid request: dialed and billed, summaries pending, and
the old floor-on-drop threw the whole slot away on the next small-window dispatch, the
return landing nowhere and the request arm refusing to re-ask under the same floor.

Pin: `testThePaidInFlightIdleSlotSurvivesTheSubFloorDrop` — a new `PendingAheadBackend`
fixture keeps `summariseAsync` unresolved; the idle watch mints the sub-floor slot
(~62.5k band, 60k window), submit runs the turn, and the in-flight slot must survive the
drop arm with its id intact; `release()` then proves the paid return finds its slot.
Mutation M2 (revert the drop arm to `aheadOfNeedAllowed`): exactly this one test reds.

## NIT — idle-test rationale numeral

The round-0 REPORT (main-tree copy, not carried on this branch) justified
"SessionHostIdleAutoTest needed no bump" partly off a 46_000 figure. Correct
derivation: its load-bearing `idleOverBandPairs` are 4× heavy bands of 41_500 chars
(≈83k estimated tokens — over the 64,000 floor of an 88k window); the 46_000 literals
(:537-538) belong to the under-tier cacheTtl fixture. The conclusion stands: no bump
was needed, because the over-floor band was always the one the idle tests ride.

## Chat.php drop arm — disclosed, no action

`Chat::dispatchTurn` still folds the floor into its drop half via `aheadOfNeedAllowed()`.
Judged harmless and out of the serialized SessionHost scope: Chat has no idle mint, so
every slot it holds passed the floor at its request and the estimate only grows before the
next dispatch — no sub-floor in-flight slot can exist on the TUI host to be wrongly killed.
The one-gate sentence in the class docblock stays true (`aheadOfNeedAllowed` remains the
request rule for both hosts).

## Gates (cwd candy-code, linked, plain pipe, deprecations are the file's pre-existing 2)

- AheadOfNeedSkipRulesTest: 9 tests, 129 assertions (was 7T/76A; +2T/+53A).
- Family 'AheadOfNeed|BackgroundSummary|SummarySafeguard|CompactionFallbackParity|SessionHostIdleAuto|IdleAutoCompaction': 93T/529A (reviewer claimed 91T/476A — the +2T/+53A is exactly this lane's two new tests; all green).
- --filter SessionHost: 84T green (unchanged — the new tests live in the skip-rules file).
- Guard union (GlobDialect|DuplicatedTestHelperDrift|SwallowingCatch|DuplicatedDocBlockLine|SymbolCitation|DocFigure|EnvRoster|TrustKey|ConfigWriteProducer|ReadmeRoster): 226T, sole failure = SwallowingCatchSrcCensus naming `src/Tools/BuiltIn/SkillTool.php::execute` — a standing red on the shared roster (foreign 8ad2de199), naming a file this diff never touches. The reviewer's 208T count for this union did not reproduce; reported as-run, no union member moved because of this fix beyond census counting.
- php -l clean on all three changed files.

## Mutation ledger

Backups: /tmp/opencode/cc-cmp-C-CTX-5b-fix1/mut-backup/ (SessionHost.php d8f5108b…,
CompactionService.php 6eeff32f…, AheadOfNeedSkipRulesTest.php 337c392c…).
M1 stamp-trio deletion → Tests 9, Failures 1 (stamp test only) → restore md5 OK.
M2 drop-arm revert to aheadOfNeedAllowed → Tests 9, Failures 1 (in-flight test only) → restore md5 OK.
Both restores verified by `md5sum -c`; no `git checkout` used on dirty files.

## Weld carries (updated for round 1)

- durations.tsv: unchanged row set — no new test FILE; the existing owed row
  `candy-code/tests/Host/AheadOfNeedSkipRulesTest.php` now covers 9 tests.
- Suite-figure delta carried by this lane becomes +9T/+129A (was +7T/+76A).
- CONTEXT.md untouched: its "64,000-token floor" sentence still matches the constant and
  its skip-rule sentence was already model-only-for-drop phrasing — the code now tells it
  the truth. DocFigure green ⇒ no ARCHITECTURE/dispatch-arms numeral re-derivation owed.
