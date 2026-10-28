# C-EXT-2.e round-1 fixes — FIX-1

Amended in place: ef5080a62 → e0fbbff81762b5bab13780f9373b949af35419f3 (single commit atop 63f2de5d9; 13 files, +1009/-79). Author/committer Joe Huss <detain@interserver.net> (%ae/%ce separate greps = 1), zero trailers, NEVER pushed. Message kept + '(round 1: parked-route notice thread + vacuity cure)'.

## MINOR-1 (src/Chat.php) — parked-route fall-back notice thread
- `submit()` :9948 now passes `$commandModelNotice` as the 7th argument to `scheduleParkedCompaction()`.
- `scheduleParkedCompaction()` signature gains `?string $commandModelNotice = null` (with a @param stating the exclusivity-by-construction argument and why the thread is needed: the park returns ahead of submit()'s notice tail at :10047).
- History build: `$pinDropNotes` → `$commandNotes`; the fall-back sentence `Message::notice($commandModelNotice)` now rides the same pre-prompt slot as the pin-case truth row and the attachment notes (mutually exclusive in practice — commandTurnModel() answers a pin OR a notice, never both), so the parked prompt announces the swap exactly like the serve route. Serve/-p parity untouched; heuristic (return-null) route still lands on the :10047 append unchanged.

## New pin (tests/Chat/CustomCommandModelOverrideTest.php)
- `testAParkedCommandTurnAnnouncesTheModelFallBackAheadOfItsPrompt`: EngineBackend over ScriptedProvider([], false, 88_000) + EchoBackend summaryBackend + 78,280-token weighted history (renamed builder `commandParkWeightedHistory()` per DuplicatedTestHelperDrift) + `model: two words` (not-an-id fall-back). Pins: cmd non-null + inFlight (park route actually taken — the non-vacuity guard: a fixture that fell through to the serve route would also see the notice at :10047), fall-back row present exactly once, positioned BEFORE the echoed prompt row, and zero `parked behind summarization` rows (the pin-case truth row stays for the pin case only).
- Mutation proof (cp-harness, no git stash): thread reverted at the call site → exactly 1F at the present-assert (:199); restored md5-identical (367d4d78548cfc6699bbdc9ed5702793).

## NIT (tests/Chat/CustomCommandModelOverrideTest.php:117)
- Vacuous `assertNull(null, ...)` leg deleted (the "first submit settled" claim is already load-bearing at :103); the now-unused `$next` destructure tightened to `[, $cmd]`.

## Gates (cwd candy-code in the lane worktree, plain pipe, env -u LINES -u COLUMNS, PtyRunToolTest never in a filter)
- CustomCommandModelOverride|TurnControllerCommandRouting → 19T/51A OK (18 lane + 1 new pin... builder's pair was 18T pre-cure; +1 = the new pin)
- CommandLoader|FrontmatterKeyAudit|InertFrontmatter|BootstrapLaunchNotice → 99T/949A OK (exact)
- TurnController|SessionHostCommands → 60T/232A OK (exact)
- CustomCommand|ChatSubmit|MidTurnSpendCap → 103T/450A OK (+1T/+5A = the new pin, expected vs builder's 102T/445A)
- SessionHostTest|TurnRunner|InertFrontmatter|FrontmatterKeyAuditTest → 77T/336A OK (exact)
- DocFigureProseDrift|CommandDocsDrift|LangParity|SymbolCitationDrift → 113T/23570A OK (exact)
- five-guard (StderrEmitterCensus|DuplicatedTestHelperDrift|GlobDialectDifferential|ReadmeSuiteFigureDrift|ChildWallClockBudget) → 137T/32877A, Failures: 2 = the allowed staleness pair only (25,537 pinned vs 26,598 live = the 26,597 REVIEW-1 figure + 1 new pin — welder closeout duty)

## Blob-verify @ e0fbbff81
- pin name: 1; threading token `$commandTurnPin, $commandModelNotice`: 2 lines (:9770 destructure, :9948 call); `...$commandNotes,` row: 1; `assertNull(null`: 0.

## VERIFICATION pass (cure-session re-run @ e0fbbff81, disk-only, tree byte-clean 0)
- Brief cited tip ef5080a62; actual branch tip e0fbbff81 (same single commit atop 63f2de5d9, author/committer detain, zero trailers, parent = base). Cures were already ON DISK in that commit; this pass independently re-proved them.
- MINOR-1 on disk: call site :9948 threads 7th arg; signature :14109 takes `?string $commandModelNotice = null`; :14265-67 splices the fall-back notice into `$commandNotes` before `$parkedUserTurn`. Blob `commandModelNotice` count = 9 at HEAD.
- NIT on disk: `assertNull(null` count = 0 in the test blob; premise stays load-bearing at :103.
- RED-BEFORE RE-PROVEN: /tmp cp-harness (never stash) — swapped base 63f2de5d9 src/Chat.php in, ran the new pin ALONE → exactly 1F at the present-assert (:199, "actual size 0 matches expected size 1" = silent parked swap, the finding's defect verbatim); restored → md5 367d4d78548cfc6699bbdc9ed5702793 (identical), pin 6A OK.
- GATES (cwd candy-code, plain pipe, env -u LINES -u COLUMNS): CustomCommandModelOverride|TurnControllerCommandRouting 19T/51A OK · Chat|SessionHost|TurnController 1968T/14167A/8F — ALL 8 PROVEN BASE-INHERITED (isolated-run reds at tip that stay red with the lane's 6 behavior files swapped to base; families: AutomaticCompaction breaker, ChatHostLangCatalogue, 5x ChatTest title-suggestion AsyncCmd-shape, DreamPass not-engine — none touched by the lane's 13 files, zero lane-added failures) · LangParity|DocFigureProseDrift 98T/6833A OK · StderrEmitterCensus 95T/20795A OK (lane adds zero emitters — Message::notice is transcript, not stderr).
