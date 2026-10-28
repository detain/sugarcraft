# C-EXT-4.a — Marketplace core — DONE

**Verdict:** complete, committed clean. `cc/C-EXT-4.a @ 8dd4df6ef` (base 341f66404), 12 files, +2091/−1, author+committer detain@interserver.net (separate greps 1/1), zero Co-Authored trailers, never pushed.

**Shipped (one type per PSR-4 file, final + strict_types, CC-new #12 — no port cite):**
- `src/Bundles/Marketplace.php` — parses `.claude-plugin/marketplace.json` (name/owner/plugins[] path|github|url|git sources), bundle-name rule per Subcommands, relative sources gated by `ContainedPath::below` (2 compare sites); absolute/escaping/ssh/http/userinfo sources refused as row-level refusals; unknown keys tolerated.
- `MarketplaceEntry.php` — provenance carrier (sourceType/repo/url/sha); `withPinnedSha/withCategory/withKeywords` via private-ctor copies.
- `MarketplaceRegistry.php` — user-tier `~/.candy-code/marketplaces.json` via `HomeDirectory::owned()` + `AtomicJsonFile` 0600 + `PrivateDir::ensure`; pinned-EMPTY by construction (zero default entries); `installGate` = refuse-by-default: row must be added, sha must be 40-hex, entry must exist, optional allowlist (R13: not forced for bundle manifests).
- `BundleSource.php` — `<bundle>/.candy-code-source.json` provenance record, read gated by ContainedPath; docblock law: records are never trust input for install decisions.
- `BundleRecommender.php` — offline lexical recommender (composer.json/package.json/go.mod/Dockerfile/*.tf root signals → keywords), max 3, announce-only, zero installs, no timers (E646 untouched).

**Guards moved same commit:** `ContainedPathInventoryTest` +3 rows (Marketplace 2, MarketplaceRegistry 1, BundleSource 1); `ContainedPath.php:97` sentence FIFTY-SIX/TWENTY-SEVEN → SIXTY/THIRTY (+$words SIXTY); `ReadPathCensusTest` +3 rows (Marketplace/BundleSource file_get_contents CONTAINED, BundleRecommender scandir NAMES_ONLY).

**Gates (plain-pipe, env -u LINES -u COLUMNS, cwd candy-code):**
- `--filter 'Marketplace|BundleSource|BundleRecommender'` → **47T/202A, 0F/0W** (2 PHPUnit deprecations proven pre-existing at base).
- `--filter 'ReadPathCensus|ContainedPathInventory|StderrEmitterCensus|TreeWideGuardRoster'` → 180T/25008A/2F — **both failures inherited-base**: ContainedPathInventoryTest derivation-vs-committed drift naming only foreign files (Memory/AgentMemory, Skills/SkillLoader 3→4, Host/Commands/ContextHostCommand, Host/TranscriptProjector, Tools/PathJail 5→6); cp-harness reproduced the same reds at base with my files removed. No Bundles row appears in any diff. NOT fixed (out of step scope) — weld/closeout owns.
- `--filter 'ChildLifetime|DuplicatedTestHelperDrift|SymbolCitationDrift'` → 100T/17117A 0F. `tools/check-child-lifetimes.php` → 33 sites, 9 findings, 0 problems (no new spawn site; R13 git-free fetch deferred to C-EXT-4.b's existing bundlesCloneDefault).

**New test files (durations.tsv rows = weld duty):** candy-code/tests/Bundles/{MarketplaceTest,MarketplaceRegistryTest,BundleSourceTest,BundleRecommenderTest}.php.

**Deviations:** suite-figure.json/durations.tsv/dist untouched per law; registry record reads go through AtomicJsonFile so no extra ReadPathCensus sink; raw-English refusal strings (no new Lang keys, matches BundleDiscovery precedent); no UI wiring (pure lib-shaped src per brief).
