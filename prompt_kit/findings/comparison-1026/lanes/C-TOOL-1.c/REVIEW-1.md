# REVIEW-1: C-TOOL-1.c (round 1)

VERDICT: CLEAN

Reviewer: fresh read-only step reviewer, 2026-10-27. Worktree /home/sites/cc-cmp-C-TOOL-1.c, branch cc/C-TOOL-1.c, commit 27106a6264490f2396dbb924e2707a3776cbfcbe (base ff10efc431e948a88bd4176cb166b622d40475b1).

## Commit law (disk-verified)
- git log ff10efc43..cc/C-TOOL-1.c: exactly ONE commit, subject "C-TOOL-1.c: Format hook skips failed edits, never blesses stale".
- %ae = detain@interserver.net AND %ce = detain@interserver.net (checked as separate format fields). Commit body carries no Co-Authored-By and no trailer at all.
- git status --porcelain empty: worktree clean, nothing uncommitted.
- 8 files, +295/-24 — matches the report exactly. No suite-figure.json, no durations TSV, no dist/, no generated protocol files. No new *Test.php (report lists none, so no durations row owed).

## Scope vs brief
- files.modify honored: HookContext.php, Runtime.php, PostEditFormatHook.php, docs/HOOKS.md; tests: PostEditFormatHookTest, HookContextTest, PostToolUseAttributionTest, plus ReadPathCensusTest as the declared deviation. Chat.php NOT touched (diff-verified; its own postToolUse call site stays untouched per the design).
- Runtime.php: the single hunk sits at :3410-3414 inside `private function settle(` (declared :3383; next function settleAsk() at :4086). Exactly the claimed [runtime:settle] region; the ONE-lane / region discipline of plan §2 holds.
- docs/HOOKS.md edited in-step (intro flip "that did NOT fail", new failed-call bullet with the lint contrast, conditional-recording rewrite). The DocFigure-pinned registry bullet is untouched; DocFigureProseDriftTest green proves no pinned sentence was falsified.
- Deviation judged on MERIT: the new `file_get_contents` sink in preFormatBytes() is derived from src/ by ReadPathCensusTest, so §1.8 in-step guard law makes the PATH_JAIL roster row mandatory. Key shape matches the sibling FormatRunner row; the PATH_JAIL claim is honest (the helper really resolves through PathJail::resolve and size-caps at FormatRunner::MAX_SOURCE_BYTES, bytes used only as staleness evidence).

## Correctness (hand-traced)
- Guard 1: execute() early-returns a bare HookResult::allow() when $context->toolFailed. Normal successful Edit: bit false, formats + records as before. Refused/stale Write: ToolResult::isError() true, settle() chains withToolFailed(true), hook stands down, user bytes untouched.
- Guard 2: verified against ReadLedger::staleness (:203-220) — null means agreement, so `=== null` re-hashes only while the ledger agreed with the pre-format bytes; a stale file is still formatted and still noted but never blessed; null/unreadable/oversized/out-of-jail pre-state is a conservative no-record (disclosed in code comment).
- HookContext: trailing defaulted ctor param keeps all 7 src `new HookContext(` sites unchanged (enumerated: HookManager:589, TaskList:1179, Runtime:3941/3955, TurnController:675, CompactionService:913, Chat:4692). Carry-through COMPLETE: all five with* methods (withHeartbeat/withToolFailed/withToolInput/withRewrittenArgs/withToolOutput) pass the bit — line-by-line diff check plus the round-trip test.
- Path agreement: preFormatBytes and FormatRunner::format resolve identically (PathJail::resolve($root,$path) with root = $context->projectRoot in both), so staleness($report->absolute, $before) compares the right file's bytes.
- No fabricated success path: the fresh-agreement polarity test pins that the recording half still fires, so the guard cannot silently disable the hook's whole purpose.

## Fail-before-fix (verified by mechanism against base sources on disk)
- `git show ff10efc43:.../HookContext.php` has NO toolFailed member: every new test calling ->withToolFailed() fatals (4E); the attribution spy reads an undefined property so the seen-array becomes [null,null] != [false,true] (F); testTheFailureBitDefaultsToFalse assertFalse(null) (F); the stale-bless test asserts changedSinceRead()==['modified'] which the base unconditional record() defeats (F). The builder's 4E/3F claim is exactly what the base tree yields. Pins are non-vacuous: removing the early return reddens the failed-edit file-content assertion; removing the agreement gate reddens the stale-bless assertion.

## §1.9 hazards
- No glob-shaped (wildcard, no-space) string literals added in any test or roster row; no **Refused:**-shaped literals (the new failure fixture text is "Error: file changed since you read it"). GlobDialectDifferentialTest green.
- Conventions: strict_types intact, final readonly class, new setter follows the file's explicit `new self(...)` copy idiom, comments carry the why. No timers (E646 n/a), no new proc_open/fork sites, no user-facing strings (Lang n/a).

## Gates re-run by reviewer (plain pipe, worktree candy-code, PHP 8.3.6 / PHPUnit 10.5.66)
- --filter '(PostEditFormatHookTest|HookContextTest|PostToolUseAttributionTest|HookEnvironmentDocumentationDriftTest|ReadPathCensusTest)' -> "OK, but there were issues! Tests: 63, Assertions: 795, PHPUnit Deprecations: 2" (deprecations pre-existing per report; builder figure reproduced EXACT).
- --filter '(DocFigureProseDriftTest|GlobDialectDifferentialTest)' -> "OK, but there were issues! Tests: 104, Assertions: 4255, PHPUnit Deprecations: 2" (builder figure reproduced EXACT).

## Findings
- None at CRITICAL/MAJOR/MINOR. NIT (no action): report cites Chat.php:4879 for the untouched postToolUse site; the actual construction is Chat.php:4692 — line-cite drift, substance correct. NIT: the attribution spy names its tool Bash rather than a Write; the failure bit is tool-agnostic and the settle-path wiring claim is what the test pins.

Ready to weld.
