# C-EXT-4.d REVIEW r2 (delta) — VERDICT: CLEAN (0C/0M/0 step-local; 3 NIT)

Tip `41a5f0486` (parent = r1 tip `232ae55e4`, base `e6188662a`), tree clean, A/C detain, 0 Co-Authored.

## r1 MINOR root cause REMOVED — verified
- `git diff 232ae55e4..41a5f0486` = exactly 2 files, +40/−0: the prescribed `assertArrayNotHasKey('launchRecommendations', json_decode(file_get_contents($this->stampFile)))` leg (9 lines, placed immediately after the second `addMarketplace(..., existing: $widened)`, r1 wording honored) + the new lane `REPORT.md`. No other file moved; no generated/weld-only file (suite-figure, durations, dist, plan) touched.
- Mutation re-prove: `persist()` carrying the stamp forward (4-line merge in `MarketplaceRegistry::persist`) → `BundleRecommendationNotice` filter = **11T/1F**, the sole red names the new leg ("…does not have the key 'launchRecommendations'"). `MarketplaceRegistryTest` stays green under the same mutation (16T/75A) → the leg is uniquely load-bearing, r1's vacuity is dead. Restored byte-exact: `git diff` empty, `hash-object` == HEAD blob `0f44815c0`.
- Gates re-run (cwd candy-code, plain pipe): lane 11T/**50A** green; 15-filter family **275T/24271A/1F** (1F = foreign PAGE_QUOTES arm, `BootstrapLaunchFormatConstantsTest:1287`); DocFigure/SuiteFigure/Roster/EnvRoster family **143T/43355A/2F** = the known staleness pair (25537 pinned vs 27574 live). All three claims match exactly.

## REPORT truth-check
- Byte-true: the rendered sample is on BOTH pages (`tools your own settings left` greps 1 each at base and tip), and the arm reds on the OTHER leg — live failure shows roster claims `PROJECT_TIER_TOOL_REMOVAL_FORMAT => [README.md, docs/SETTINGS.md]` while the sweep nominates neither; `PAGE_QUOTE_FIELD_BYTES = 282` (test:451, `.{1,282}?` at :2114) vs the flattened 32-name run ≈295 bytes. Base-foreign re-proven by input identity: lane diff touches neither `README.md` nor the census test, and `docs/SETTINGS.md` only the `transcriptLimit` 48→49 cell.
- NIT-1 (evidence prose): cited line numbers are off by 2 — the samples are at `candy-code/README.md:1508` and `docs/SETTINGS.md:887` (base and tip).
- NIT-2: "no master commit since `e6188662a` touches Bootstrap.php, this census test, README or SETTINGS.md" is now stale — master moved to `4aa9ea204` (C-AGT-6.c `237224a7a`/`c36143800` do touch Bootstrap.php + README). Foreignness still holds (batch-8 weld already recorded the 1F); do not carry that sentence into §4 as current truth.
- NIT-3: REPORT numerals are pre-fix ("11 tests, 49 assertions", "275T/24270A") and its Tests paragraph omits the new raw-envelope leg. One-commit text cure at weld.

## Welder carries (unchanged)
DUR row: none new from the fix commit (test file row already owed from `232ae55e4`); §4 mark cites `41a5f0486`; batch-9; suite-figure+README re-pin (+11T); PLUGINS.md DOC-ROW verbatim from the REPORT; NIT-1..3 text cures may ride the weld doc commit.
