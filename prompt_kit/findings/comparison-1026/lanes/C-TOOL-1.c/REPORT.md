# C-TOOL-1.c — Format hook skips failed edits, never blesses stale

VERDICT: DONE. Branch cc/C-TOOL-1.c off ff10efc43, worktree /home/sites/cc-cmp-C-TOOL-1.c. Commit 27106a6264490f2396dbb924e2707a3776cbfcbe (8 files, +295/−24; author+committer detain@interserver.net verified separately, zero trailers; tree clean; NOT pushed).

## Root cause (EDT-1-P1, MAJOR)
PostEditFormatHook::execute() formatted failed edits too, and its recording
half `$this->ledger?->record($abs, null)` blessed the CURRENT hash
unconditionally. HookContext carried no failure bit, so a REFUSED stale Write
(refused precisely because the user moved the file) had its user bytes
rewritten by the formatter and then re-blessed as if the model had legitimately
edited — the model's retry clobbered the user's edits and the staleness guard
was laundered away.

## Fix (two independent guards)
1. HookContext gains `public bool $toolFailed = false` (trailing defaulted ctor
   param — all existing construction sites unaffected) + `withToolFailed(bool)`;
   every with* copy carries the bit. Runtime::settle() (the one claimed region,
   ~:3410) chains `->withToolFailed($result->isError())` onto the
   withToolOutput() context it hands the PostToolUse chain.
   PostEditFormatHook::execute() early-returns a bare allow when set. The lint
   hook deliberately KEEPS linting failed edits (lint reads, format writes) —
   its docblock text untouched.
2. A kept rewrite re-hashes into the ledger only on AGREEMENT: new private
   preFormatBytes() reads the file's bytes BEFORE the run (PathJail::resolve,
   size-capped at FormatRunner::MAX_SOURCE_BYTES, bytes used only as
   ReadLedger::staleness() evidence, never rendered or kept); record() fires
   only when `staleness($report->absolute, $before) === null`. A stale file is
   still formatted and still noted, but never blessed. Null/unreadable pre-state
   = conservative NO-record (disclosed in code comment). "IT NEVER REFUSES"
   stays true; its doc sentence truth-flipped (failed call → bare allow).

## Files
- src/Hooks/HookContext.php — bit, docblock, withToolFailed, 4 with* carries.
- src/Runtime.php — settle() only (region [runtime:settle]).
- src/Hooks/BuiltIn/PostEditFormatHook.php — early return, $before capture,
  conditional record, preFormatBytes(), PathJail import, docblocks.
- docs/HOOKS.md — 'Post-edit format': intro now "that did NOT fail"; new failed
  call bullet (with lint contrast: reads vs writes); recording bullet made
  conditional (stale formatted, never blessed); DocFigure-pinned registry bullet
  line untouched.
- tests/Support/ReadPathCensusTest.php — NEW mandatory census row
  'Hooks/BuiltIn/PostEditFormatHook.php|file_get_contents' => PATH_JAIL
  (in-step roster law §1.8; file source contains PathJail — measured verdict).

## Tests (fails-before-fix proven)
- Pre-fix targeted run after test adds: Tests: 43, Errors: 4, Failures: 3
  (red = the two guards + the missing bit/field; wiring test erred on the live
  settle path through the real chain).
- Post-fix: --filter '(PostEditFormatHookTest|HookContextTest|
  PostToolUseAttributionTest|HookEnvironmentDocumentationDriftTest|
  ReadPathCensusTest)' → OK 63T/795A, 2 pre-existing PHPUnit deprecations
  (present at baseline 36T/157A run too).
- --filter '(DocFigureProseDriftTest|GlobDialectDifferentialTest)' → OK
  104T/4255A. php -l clean on all 8 files.
- New test methods (existing files, NO new *Test.php): HookContextTest ×4
  (default-false, setter, preservation, round-trip through every with*);
  PostToolUseAttributionTest ×1 (refused Write reaches chain with
  toolFailed=true via real settle path); PostEditFormatHookTest ×3 (failed edit
  neither formatted nor recorded; stale file formatted, noted, NEVER blessed;
  fresh agreement still blessed — polarity).

## Deviations
- ReadPathCensusTest row: not in the brief's file list but mandatory in-step
  (§1.8 census roster; the new file_get_contents sink fails the guard otherwise).
- preFormatBytes() private helper + PathJail import: the design named
  `$before`; the helper is where the jail/size discipline lives so the census
  verdict is honest.
- Script-hook stdin payload NOT widened (per design); Chat.php:4879
  postToolUse call site NOT touched (per design; it lacks an isError-carrying
  ToolResult at that point — Runtime settle is the designed carrier).
