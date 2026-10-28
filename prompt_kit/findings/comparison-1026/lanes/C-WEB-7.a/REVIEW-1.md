# REVIEW-1 — C-WEB-7.a (round 1)

VERDICT: CLEAN

Branch cc/C-WEB-7.a @ b810efe67, ONE commit atop 581ac6ede, worktree clean before and after gates. Master moved only +MCP-2.a (b441c48e9: mcp files + durations row) — zero overlap with the lane's 5 files (git diff name-check empty). 3.b/7.d/7.e/PRV-5.d all predate the base; 1999f4114 (C-TOOL-3.b) touched ToolTailSlots/Chat/Renderer/lang, NOT ShellTailRing/Bash — no docblock-truth contradiction.

## Category checks
1. Scope: exactly the 5 brief-named files; Bash edit confined to runCommand's $onWait composition + the two capture-call argument swaps ($heartbeat→$onWait); CapturesProcessOutput trait byte-untouched (stat). No files.create, none appeared.
2. Completeness: no stubs/TODOs; tick(), $onWait, both docblocks all real.
3. Correctness: hand-traced cadence pin — t=0 frame1 (seq==0 immediate), 0.1 held, tick@0.3 silent (<0.5), tick@0.6 frame2 'ab' seq2, tick@0.9 and finish clean → 2 frames. tick() cannot observe dirty&&seq==0 (onChunk pushes unconditionally at seq 0), so the lastEmittedAt=0.0 seed never mis-fires. $onWait: ring-null path is byte-identical to the pre-fix argument; non-null is tick-then-heartbeat per design. discard() prose NOW MATCHES reality: CookieSessions.php:154-160 and the pinned CookieSessionPersistenceTest:231 "everyone restarts signed out" carry the same truth; old "ride over the restart unsigned-out" was the lie.
4. E646: NO new timer, NO scheduled callback, NO wall-clock kill anywhere in the diff — tick() is hook-called from the EXISTING wait beat (runCaptured slice :243, awaitExitUntil :354, interactive :545 — all verified in trait).
5. Non-vacuity: tick neutered → ring pin red at t=0.6 + Bash timing leg (0.4 s margin) red (pre-fix the 'ab' frame is finish() at exit, ~µs before return — builder's 1.1e-5s claim mechanistically sound; my live mutation attempt was permission-refused in this read-only lane, reasoning-only here). Interval-neuter tick → t=0.3 pin red. Heartbeat-leg strip → testARingRunKeepsBeating red. Both heartbeat polarities pinned.
6. Conventions: strict_types, final, docblocks say WHY, no user-facing strings (i18n N/A).
7. Tests re-run: `--filter 'ShellTailRingTest|BashToolProgressTest'` → Tests: 15, Assertions: 56, OK (matches builder). Five-guard family → 133T, failures EXACTLY the staleness pair (25,537 pinned vs 26,417 live — inherited master-wide drift, +880, lane adds 0 new test files/methods-enumeration beyond in-file methods; NOT attributable). 2 PHPUnit Deprecations originate in AccessorDeprecationTest providers (base-era, foreign, disclosed here as non-lane).
8. Guard law: no guard pins the old discard()/ring docblock strings (grep empty); no new test file → no durations.tsv row owed; suite-figure.json/durations.tsv untouched; no proc_open/site (no new spawn).
9. Laws: %ae and %ce both detain@interserver.net (separate format fields verified), zero Co-Authored; PtyRunToolTest never in filters.

NITs (non-blocking, no action): ShellTailRingTest class header still says "two contracts (WEB-4)" though tick adds a third; SessionFileStore docblock line-wrap now slightly ragged ("signed out / at the restart") — cosmetic.
