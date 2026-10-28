# C-WEB-7.a — CLOSED

Verdict: DONE. ShellTailRing grows `tick()` (trailing-edge flush on the capture's existing wait beat), Bash composes it with the heartbeat into `$onWait`, `discard()` docblock truth-flipped.

## What landed
- `src/Tools/ShellTailRing.php`: new `tick()` — pushes when `dirty` AND the interval since the last frame elapsed, else silent; class docblock's "not lost" sentence rewritten to name `tick()` (the false "the next frame's tail carries them" promise replaced with the trailing-edge + finish() truth). No new timers (E646-clean: rides the loop that already beats).
- `src/Tools/BuiltIn/Bash.php` `runCommand()`: `$onWait` = ring null ? `$heartbeat` unchanged : closure(`$progress->tick()` then `$heartbeat` if non-null). The two capture calls (:591-592) receive `$onWait` instead of `$heartbeat`. Trait untouched; `finish()` stays the exit flush; sandbox-probe call untouched (outside the two named).
- `src/Server/Auth/SessionFileStore.php` `discard()` docblock: "rides over the restart unsigned-out" → "every live session is signed out at the restart" (matches CookieSessions.php:154-160 truth; prose only).

## Tests (all in the 2 named files; NO new *Test.php — no durations row owed)
- `ShellTailRingTest::testTickFlushesAHeldTailAtTheTrailingEdgeWithoutANewChunk` — exact brief cadence: chunk t=0→frame1, t=0.1→none, tick 0.3→none, tick 0.6→frame2 'ab', tick 0.9→none (clean), finish→none. Clock seam, zero sleeps.
- `BashToolProgressTest::testAHeldTailLandsOnTheWaitBeatBeforeTheExitFlush` — `printf a; sleep 0.05; printf b; sleep 1.2`, timestamping emitter: exactly 2 frames, 2nd carries `ab`, 2nd emitted ≥0.4 s before `execute()` returned. RED pre-fix at 1.1e-5 s (the exit flush), green post.
- `...ARunWithoutAnEmitterStillBeatsASuppliedHeartbeat` — no ring → beat is the plain heartbeat (0.4-b contract).
- `...ARingRunKeepsBeatingTheHeartbeatAlongsideTheFlush` — composition fires tick AND heartbeat.

## Gates (plain pipe, cwd=candy-code, -c phpunit.xml, -u LINES -u COLUMNS; PtyRunToolTest never in a filter)
- 2 named files: `OK (15 tests, 56 assertions)`.
- Adjacent capture-path files `CapturedProcessDeadlineTest + OutputTruncationTest`: `OK (30 tests, 201 assertions)`.
- Five-guard `--filter 'ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift'`: 133T, ONLY the staleness pair red (pinned 25,537 vs live 26,417) — FOREIGN-INHERITED, red at base 581ac6ede too (gap ≈ 880 ≫ my +3 methods; re-pin is gate/weld duty; suite-figure.json untouched here).
- `php -l` clean on all 3 src files. No roster/doc page touched (brief docs/guards: none); verified pre-edit that no guard cites the flipped docblock sentences.

## Mutations (cp+md5 restores verified)
- M1 Bash composition heartbeat-leg strip → exactly 1 red (the alongside-beat pin).
- M2 ring tick interval-guard neuter (`if (false)`) → exactly 1 red ('tick waits for the interval too'). (First M2 attempt: needle mismatched → assert threw before write, tree untouched — applied with corrected needle.)

## Scope notes
- Read master state first: C-TOOL-3.b (ToolTailSlots) landed but lives in Chat.php/Renderer.php — untouched, as briefed. `ShellTailRing` API change is additive; PtyRun (the ring's other consumer) composes its own onChunk and is unaffected.
- No glob-shaped/denial-shaped literals introduced. No new spawns (no child-lifetime row). dist/ untouched.
- Live timing pins are wall-clock-based with wide margins (1.2 s sleep vs ~0.5–0.7 s flush) per brief risks; ~1.6 s of added suite wall time in BashToolProgressTest.
