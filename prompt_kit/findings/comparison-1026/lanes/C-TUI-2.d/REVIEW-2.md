# C-TUI-2.d REVIEW-2 (r2 — fix verification) — branch cc/C-TUI-2.d @ 6697ce667 (on c49418587)

VERDICT: CLEAN. 0 CRITICAL / 0 MAJOR / 0 MINOR. All three r1 step-local MINORs cured as claimed; no scope creep.

## Scope
git show 6697ce667 --stat: exactly one file, candy-code/tests/Tui/DiffReview/DiffReviewTest.php (+19/−2). Zero src, zero weld-only files; worktree porcelain-clean. Test-count neutral (2 existing methods extended) — no durations row owed this round, matching the claim.

## Pin non-vacuity (read + reasoned; mutations not re-executed — /tmp-writes-only reviewer law)
- MINOR-1 (turn-flip reset): pin dirties state with premise asserts (assertSame(1, selectedIndex()) after Down; assertGreaterThan(0, scroll()) after PageDown) before 't', then asserts both land 0. toggleTurn (DiffReview.php:195) is the sole path zeroing selected/scroll on the flip; the mutate() carry propagates unspecified fields, so a stripped reset pair leaves 1/>0 through the post-'t' asserts — red. The premise legs make a silent pass impossible.
- MINOR-2 (bases() mixed hole): hole saved uncaptured BETWEEN two captured, newest checkpoint captured. Scan order newest-first = [second, hole, first]: no-filter yields 3 entries (reds baseCount + index-list + assertNotContains); stop-at-first-miss yields [second] (reds the exact index-list assert); correct filter is the only green shape. Genuinely discriminating.
- MINOR-3: REPORT corrected to 21 methods (7+14) with FIX ROUND 1 section appended; arithmetic verified against the r0 tree's method lists.

## Gates re-run at tip (plain pipe, cwd=candy-code)
DiffReview 19/195 (r0 190, +5A exact) · CheckpointDiff 11/91 · combo CheckpointDiff|DiffReview|LangParity|GlobDialect|DocFigure|SymbolCitation 152/24,974 (+5A recount) · census trio StderrEmitterCensus|BootstrapLaunchFormatConstants|TreeWideGuardRoster 130/25,406 — all OK. The 2 extended methods isolated: 2T/39A green.

## Standing reds only
DuplicatedTestHelperDrift: exactly 1 failure, the known base-inherited const-polity pair at :2829 (names no DiffReview file — grep 0). "PHPUnit Deprecations: 2" baseline each leg. No new red introduced.

## Weld-carry (unchanged from r1)
durations.tsv rows for both new test FILES (r0); suite-figure re-pin ≈+21T green-run authoritative; §4 mark: C-TUI-2.d ✅ c49418587 + 6697ce667.
