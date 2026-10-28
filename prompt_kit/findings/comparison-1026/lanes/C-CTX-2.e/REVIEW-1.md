# C-CTX-2.e — independent review 1

VERDICT: CLEAN

Commit f03994e49 (base 33d015d4a), branch cc/C-CTX-2.e, tree clean. Single commit,
author/committer verified: `%ae` and `%ce` greps separately = detain@interserver.net;
zero Co-Authored trailers. No suite-figure.json / durations.tsv / dist touches
(diff name-only lists exactly the four lane files).

## 1. Scope — PASS
`git show --stat` = exactly 4 files, +271/−128: src/Chat.php (149),
src/Chat/Handlers/HandlesTurnCompletion.php (63), tests/Chat/AutoCommitTurnTest.php (+92),
tests/Chat/AutoMemoryConsolidationWiringTest.php (+95). TurnSettleWork.php,
SessionHost.php, AutoCommitter.php untouched (C-CTX-2.c owns SessionHost concurrently).
Chat.php hunks land ONLY in the two declared regions: `schedulePromptSuggestion` (~:12837)
and `scheduleAutoCommit` (~:15717), plus the deletion of the orphaned private static
`checkpointWorkspace()` delegator — part of the brief's "delete the duplicate body"
(in-step stranding cleanup; the surviving `checkpointWorkspace` methods are
AutoCommitter's own private copy and Host\Commands\Checkpoints' public one, both
pre-existing and unrelated). No strays into effort/picker/review regions.

## 2. Gate parity — PASS
Read TurnSettleWork::plan() (master, 127 L) against the deleted inline gates. All four
slots reproduce the old TUI rules for the settle's inputs: carriedOn(=inFlight) zeroes
every slot; suggestion additionally gated by goalActive (old: `$goal !== null`), fed the
host's own TitleService — same `suggestionCall(titleBackend, history, generation,
sessionId, capReached)` args; consolidation/dream gated on `memoryStore === null` (old
writer===null form) with the same AutoMemoryConsolidator/DreamPass `call(...)` argument
shapes; autoCommit = `AutoCommitter::turnCall(...)` — verified byte-equivalent gating to
the deleted Chat body (MODE_TURN / EnhancedSessionStore / sessionId / projectRoot null-or-
'' / try-listCheckpoints / isCaptured / trailer / cap-gated backend / last-user context;
`$done->projectRoot()` equivalence holds because captured checkpoints exist only for
explicit roots). Judge (`$goal !== null → scheduleGoalJudge`) and notify
(`$done->inFlight ? null : terminalNotification`) keep their own gates inline — plan()
has no judge/notify slots and does not swallow them.
Lander sequence read post-change: `[$doneCmd, $suggest, Cmd::promise($consolidate),
$autoCommit, Cmd::promise($dream), $judge, $notify, $measure]` — brief's batch order
preserved; consolidate/dream raw slots still Cmd::promise-wrapped; $measure line
pre-existing, untouched.

## 3. Deletion completeness — PASS
Old ~70-line auto-commit body fully removed; new `scheduleAutoCommit` = fallback plan →
`Cmd::promise($work->autoCommit)` (wrap shape identical to old); `schedulePromptSuggestion`
= `? $work->suggestion : null → Cmd::promise` (identical shape). Caller census: settle is
the only production caller of either delegator (both now pass `$work`); the only other
reach is tests/Host/TitleServiceTest.php:259 via reflection on the fallback path.
`Chat::checkpointWorkspace` — zero remaining references after deletion (grep clean).
No orphaned private helpers stranded.

## 4. Behaviour neutrality — PASS
Only the decision source moved; landers, wraps, and fail-open null→no-op rows unchanged.
Two disclosed fallback-path nuances judged benign: (a) standalone schedulePromptSuggestion
now also computes the autoCommit slot (turnCall — mode-gated pure read, no throttle claim);
(b) standalone scheduleAutoCommit builds a discarded suggestion slot via fresh
`TitleService::new()` (suggestionCall read: side-effect-free closure factory). Neither has
a production caller (settle always hands `$work`); the TitleServiceTest fallback leg is
green. NIT only, already documented in the lane REPORT behaviour note.

## 5. Parity tests — PASS, non-vacuous
Both new `testTheSharedTableSchedulesTheSameSetTheTuiSettleRuns` legs are real: the
settle-leg resolves every batch child (remeasure stamp filtered) and pins the landed msg
class; the table-leg pins the slot set from plan() for the same flags; plus a stand-down
row (mode `off` / memoryStore null ⇒ empty set on both paths). A gate changed on one side
must redden the matching leg (e.g. plan-mode neuter → `off` leg red; settle bypass → set
divergence). Helper byte-identical across the two files (DuplicatedTestHelperDrift-accepted
policy, five-guard confirms green).

## 6. Inherited base-red — CONFIRMED
DreamPassTest::testChatSchedulesThePassAtTurnCloseAndReportsWhatChanged fails at :288
(`assertNull($none)` — settle schedules the P-CTX.d-era unconditional `$measure` closure
beside the passes). Mechanism predates the base; neither the test file nor the $measure /
measureContextCmd lines are in the lane diff; failure names neither lane file. Red on
master already — reproduction-by-read per review instruction.

## 7. Gates (measured, cwd=cc-cmp-C-CTX-2.e/candy-code, plain pipe, env -u LINES -u COLUMNS)
- `--filter 'AutoCommitTurn|AutoMemoryConsolidationWiring'`: **9T / 95A, OK** (builder: 5T/71A + 4T/24A — sums match).
- Builder's family filter `'AutoCommit|AutoMemory|TurnSettleWork|HandlesTurnCompletion|PromptSuggestion|DreamPass|TitleService'`: **81T / 743A, 1F** — EXACT builder reproduction; sole failure = DreamPassTest:288 inherited red. (Reviewer-brief's narrower 'AutoCommit|AutoMemory|Dream|PromptSuggestion|TurnSettle' variant: 79T/712A, same single red.)
- Five-guard `ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift`: **133T / 15781A, 2F** — exactly the staleness pair (headline + artifact-vs-live), red already at base 33d015d4a; re-pin is closeout duty. Full suites never run; no PtyRun filters used.

## 8. Seams — correctly attributed to C-CTX-2.c (carries, not findings)
1. TurnSettleWork DORMANT docblock paragraph ("no host calls plan() yet") goes FALSE the
   moment .e welds — file is .c's to flip; .c also adds the SessionHost consumption that
   finally justifies the sentence rewrite.
2. AutoCommitter::turnCall docblock phrase "the scheduling half of what
   Chat::scheduleAutoCommit() does today" — after .e the delegation is via the table;
   phrase needs tracking in the same .c commit.
3. SessionHost-side parity pin: belongs to .c's tests (its wiring does not exist yet).
All three are on files this brief forbids .e to touch (SessionHost hub, TurnSettleWork,
AutoCommitter — and .c is concurrent on base 33d015d4a), so carrying them is the only
concurrency-safe choice. Weld note required: .e and .c both claim the Chat.php hub — plan
§2 serializes .e's regions ([chat:scheduleAutoCommit, chat:schedulePromptSuggestion]) from
.c's; weld order .e-then-.c keeps .c's docblock flips in-step.

## Findings
None at CRITICAL/MAJOR/MINOR. NIT (out-of-scope, .c/carry): the two fallback-plan
nuances (4) — documented, unreachable in production, green under TitleServiceTest.
NIT (closeout): suite-figure re-pin carries the +2T this lane adds to the already-red
staleness pair.
