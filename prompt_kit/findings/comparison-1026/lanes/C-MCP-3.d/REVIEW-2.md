# REVIEW-2 — cc/C-MCP-3.d (tip e0ff98c4c on 6742a2ad3; fresh reviewer)

VERDICT: CLEAN

## (1) CRITICAL (r1 twin) — elicitation/tail severance: CLOSED via remedy (b)

`McpBackgroundCalls::armed()` (src/MCP/McpBackgroundCalls.php:121-130) now gates on
`$turnDriven && !$serverRequestChannelsBound`; both call sites pass real state
(`McpToolBridge.php:613-618`, `BuiltIn/McpCallTool.php:195-199` — `$heartbeat !== null`,
`permissionApprover !== null || toolProgressEmitter !== null`; properties verified at
McpToolBridge.php:162-163, McpCallTool.php:76-77). Trace: an approver-bound turn-driven call
takes `call()` → armed false → inline → the human's question reaches the PARENT approver (C-MCP-3.b
contract intact); an unbound long call still forks (control leg). Prose corrected and now TRUE:
docs/MCP.md §Server requests (door paragraph names the refused cases + the open seam),
await()/armed() docblocks carry the dated round-1 erratum (the relay claim's past falsity
disclosed, not silently deleted). Pins: `testACallHoldingTheTurnsChannelsBlocksWhileACallHoldingNeitherHandsOff`
(3 legs: approver asks inline 1/0-child, tail delivers 3 frames in-process, CONTROL unbound leg hands
off — both revert directions discriminate), door table + structural door-naming in
`testArbitrationArmsOnlyOnACapableTurnDrivenCall`, arming-kernel `testTheTurnBindingIsWhatHandsTheProductFigureToRealCalls`
(closes r1's dead-code relapse gap).

## (2) ReadPathCensus rows: LANDED in-step (925d39ad0)

tests/Support/ReadPathCensusTest.php:735-752: file_get_contents SELF_LOCATED, fopen SELF_LOCATED,
scandir NAMES_ONLY x2 — all four sinks in McpBackgroundCalls.php registered, verdicts match the code's
actual data flow (claimed-path read, `xb`+0600+rename publish, two scans). Gate green at tip.

## (3) McpToolProgressTest:361 witness: GREEN at tip — mutation NOT RUNNABLE BY HARNESS (recorded)

My gate `McpBackground|McpToolProgress|ReadPathCensus|TreeWideGuardRoster|StderrEmitterCensus` =
**163T/26847A, 2 pre-existing PHPUnit Deprecations, OK** (file untouched base..tip; :361 inside).
Mutation execution refused: `cp`, `python3 -c`, `sed -i` (shell) and `edit` on the worktree ALL returned
`permission.rejected` — this reviewer lane is write-denied outside prompt_kit/findings (harness law, like
r1's refused generators). Static surrogate: deleting `&& !$serverRequestChannelsBound` from armed() leaves
the channel-bound legs on `executeWithHeartbeat` (turn door open) → the forks the child, whose copied
closures write the parent's capture arrays in the CHILD → `assertCount(1,$asked)` and the not-handed-off
asserts RED (matches builder M3 "2 reds: door table + bound-channel pin", FIX-1.md); :361 itself needs the
FULL 2-door revert (turnTools uses plain `execute()` → call(args,null) → McpToolBridge.php:582-585). Both
directions (M5 "nothing backgrounds" → control-leg red) are in the builder's cp+md5-proofed table. The
witness's r1-failure mechanism (arming hunk present in EngineBackend turnTools, :3495-3509) is verified
live at tip, so the green is the doors holding, not the arm having been deleted.

## (4) E646: INTACT

DEFAULT_AFTER_SECONDS=120.0 is a hand-off dial (injectable, `withBackgroundAfter`, tests use 0.05/0.15/5.0).
My independent grep of src/MCP/McpBackgroundCalls.php: ZERO hits for posix_kill|pcntl_alarm|pcntl_signal|
pcntl_sigprocmask|proc_terminate|SIGKILL|SIGTERM|SIGINT. Lane diff touches no transport file → per-hop
toolTimeout byte-untouched. Both-way pin `testTheHandOffReleasesTheTurnAndNeverSignalsTheChild`:
behavioral (child alive at hand-off via posix_kill(pid,0), publishes on its own schedule) + structural
(forbidden-token census over await()'s sliced body). The one real wall-clock guard that could kill a live
exchange is the child's own transport timeout — unchanged semantics.

## (5) Carries, hygiene, scope

TurnInbox +7 docblock (Two→Three) is truthful and DECLARED for the weld ledger (FIX-1 Declarations 1-2);
EngineBackend turnTools hunk likewise declared; r1 prescribed exactly this remedy. Degrade legs pinned:
`-p` latch (`testAHeadlessLatchWaitsForTheRealResultAndNeverForks`), no-beat (`testAnArmedToolCalledWithout
ATurnBeatNeverForks`), pcntl-less/Windows by machine-property door-table legs. Registry hygiene read and
confirmed: chmod 0600 on the `.writing` inode BEFORE payload then rename-publish; claim = rename→read→unlink
(single delivery, collect loses race → `lost` + message-route disclosed); stale `.taken` restore-GC runs
BEFORE the claim loop (delivery-preserving). Zero new proc_open/popen in the src diff (grep-proven) →
child-lifetimes roster correctly untouched; fork exits via ForkedChild::exitNow(0) (census green, 39T
guard-window run: LangParity|ForkedChildExitConvention|GlobDialect|DuplicatedTestHelperDrift OK).
TreeWideGuardRoster row landed (tests/MCP/McpBackgroundCallTest.php ⇒ scandir($registry)). One final class
per new file. lang keys 3 + section comment; figure/sentence agreement pin across code/lang/docs.
Authors %ae=%ce=detain@interserver.net on all 4 commits, zero Co-Authored.

## Weld carries (not findings)

- suite-figure/README re-pin carries this lane's +7T (staleness pair expected red, fixer-disclosed).
- durations.tsv row for tests/MCP/McpBackgroundCallTest.php (minted skeleton-round, unchanged file).
- §6 seam: backgrounding an ASK-capable long call (relay option (a)) remains unbuilt by ruling —
  honest docs, owner-visible.
- Pre-existing foreign ForkedChildReaperAdoptionTest red (C-TOOL-3.c) — base-proven at r1, not this lane.
