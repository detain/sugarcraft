# C-EXT-4.a — Round-1 Review (RECONSTRUCTED by the lead from the reviewer's return message; the reviewer's in-repo write was lost — phantom-write family. Content = verdict as received, no additions.)

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 step-local MINOR (NITs non-blocking).

- Tip 8dd4df6ef on cc/C-EXT-4.a (base 341f66404): ONE commit, 12 files +2091/−1, porcelain 0, %ae/%ce detain separate greps, zero trailers. (The earlier lead-note tip 6d1c5435b was phantom-absent; real tip proven by cat-file.)
- Scope: 5 new src/Bundles (Marketplace, MarketplaceEntry, MarketplaceRegistry, BundleSource, BundleRecommender), 4 new tests/Bundles (MarketplaceTest, MarketplaceRegistryTest, BundleSourceTest, BundleRecommenderTest), 2 modified tests files, ContainedPath.php routing edit, docs rows.
- In-step guards same commit: ReadPathCensus 3 rows (fail-closed both directions); ContainedPathInventoryTest rows via Marketplace::readSource() shared chokepoint; StderrEmitterCensus absent+14→15; SIXTY/THIRTY prose arms.
- Security walk PASS: refuse-by-default pinned-empty registry; 40-hex pinned provenance + optional host allowlist (R13 NOT force-added per §6.1); BundleSource https-only; realpath-below gate BOTH tiers, symlink/non-plain refusal; 1MiB cap; user-tier only (project-tier never reads the manifest); catalog self-heal on corrupt; BundleRecommender offline announce-only (≤3), NO new timers (E646-clean), no new fork sites; no UI wiring (4.b/c deferred).
- Gates reproduced (plain pipe): bundles 50T/198A; census 298T/47145A 4F = TWO pairs BOTH PROVEN FOREIGN at base — ReadmeSuiteFigureDrift staleness (live-at-base 26,564; lane +50T) and TreeWideGuardRoster glob-literal drift introduced by C-TOOL-5.c pick 9070bd57e (W12) — cure belongs to the W13 WAVE LOOP (recorded); guards 100T/17118A; tools/check-child-lifetimes.php + check-path-repos.php rc0.
- Non-vacuity: e2e forgery→zero rows, clean→exactly one; builder's earlier 277T/20290A quote was false-to-disk (true 298T/47145A) — disclosed.
- Weld duties (roster): 4 durations rows for tests/Bundles/* (junit recount), no regen, §4 ✅ mark rides weld commit, suite-figure accumulated +50T = §7 re-pin carry.
