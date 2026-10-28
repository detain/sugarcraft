# C-EXT-4.b — WELD (master tick, 2026-10-28)

Lane: `bundles marketplace add/list/remove/update`, `install name@mkt`, `update`, `search`, `recommend` (round 2+ fix pass; r2 REVIEW-2.md VERDICT CLEAN 0C/0M).

## Pick
- Lane branch tip: `1e03fa00a1e868f199244893a6b78e43386049a4` (1 commit, merge-base e4a705244). NOTE: lead inflight tip field carried `de5c16a51` — stale; 1e03fa00a is the byte-identical amend of de5c16a51 (commit-message suffix only), per FIX-1.md.
- Cherry-picked CLEAN onto `96d36db45` (weld: close W14) → master pick `3bc062fc4d7c0787f389869dcee49ffd7944cc3b`. Zero conflicts (README.md / lang/en.php / StderrEmitterCensusTest auto-merged keeping both sides). 10 files +2057/−14. patch-id `8260d198355ffc799d7ccf386370367284affaee` IDENTICAL to lane commit. Author law: %ae/%ce separate greps 1/1 detain@interserver.net, zero trailers.

## Regen riders
None required: commit file-set (10 files: README.md, docs/PLUGINS.md soft-doc, lang/en.php, src/Cli/Subcommands.php, 6 test files) has zero `src/Protocol/**` paths and zero candy-code-web paths. Positively PROVEN at weld: on the merged tree all four `--check` arms report up-to-date rc=0 — `tools/gen-command-docs.php` / `tools/gen-tool-docs.php` / `tools/gen-settings-doc.php` / `scripts/gen-protocol-schema.php` (docs/PLUGINS.md is hand-maintained soft-doc, not a generated surface; the bundles verbs are not in the COMMANDS.md generator's domain). Web gen:protocol N/A (zero web paths).

## Merged-tree gates (plain pipe, cwd candy-code, env -u LINES -u COLUMNS)
- `--filter 'BundleMarketplaceCliTest|BundleUpdateTest|BundleSourceTest|BundleRecommenderTest|MarketplaceRegistryTest|MarketplaceTest'` → **OK (67 tests, 341 assertions)** (+2 PHPUnit deprecations, base-present family). junit: /tmp/opencode/cc-cmp-ext4b-weld.xml.
- Brief's expected "~135T-era exact" reconciles to the authoritative REVIEW-2 dir figure: `phpunit tests/Bundles` → **OK (118 tests, 499 assertions) EXACT** (135 was a lead approximation; the six-class named filter's 67T is its own consistent subset).
- `--filter 'ReadPathCensus|ContainedPathInventory|StderrEmitterCensus|TreeWideGuardRoster'` → 180T/25756A, **2F both inside ContainedPathInventoryTest, PROVEN foreign standing, NOT attributable to this pick** (pick fileset overlap zero; ReadPathCensus/StderrEmitterCensus/TreeWideGuardRoster all GREEN in-pick — the in-step roster carried):
  1. testTheRoutedCallSiteInventory — Memory/AgentMemory +1, SkillLoader 3→4 (brief-named standing).
  2. testTheHandSpelledInventoryIncludingItsStatedExclusion — +Host/Commands/ContextHostCommand.php, +Host/TranscriptProjector.php, Tools/PathJail 5→6 (last src touches: bdb1c96d8 P-CTX.b weld, 7b730e485 C-WEB-7.d, 8c93928f2 C-WEB-1.d, 7d7bed319 C-SEC-2.b; matches W13-carried stale-entries list).
- `ProjectTierRefusalInventoryTest` alone → 15T/949A 2F standing report-only: (a) 9-entry/7-file dot-path backlog (Bundles/Marketplace, Bundles/MarketplaceRegistry x2, Format/FormatAutoTable, LSP/LspInstaller, Memory/AgentMemory x2, Support/MacAltNotice, Tools/BuiltIn/ScheduleTool) — NONE names Cli/Subcommands.php, so lane-clean; equals the lane's own REPORT ledger (brief's "8" stale; r2 reviewer saw the same 9); (b) testBothCensusFiguresThisDocBlockQuotes FIFTY-SEVEN numeral = C-SEC-7.d-era standing.

## Durations (scripts commit `8f0001af14d5c63a7e4fcdfeec9e9b6c3d7d81da`)
- +2 rows, col3 = TESTCASE count (not assertions), from junit per-file sums: `BundleUpdateTest.php 0.161 10` (63A), `BundleMarketplaceCliTest.php 0.142 15` (106A). tsv 1533→1535; desc-invariant inserts @601 (0.161 tie band end) and @613 (0.143→0.141 gap); asc-violations 4==4.
- W14-loop NIT folded in the SAME commit: existing `candy-code/tests/Cli/DoctorSandboxProbeTest.php` row col3 51 (assertions) → 10 (testcases) per 15ef514de junit-truth precedent; junit re-confirmed 10T/51A; 0.048 duration kept as recorded.
- Global recount disclosed: 1603 live test-method-bearing files vs tsv 1535 → 68-file durations-row backlog owned by EARLIER welds (sample provenance: 88ac5e485 P-TABS.b, c1267a71a P-MENU.a, 09da7d3e9 C-AGT-3.b, a992413b0 C-PRV-1.b) = closeout re-pin duty, NOT this tick's scope.

## Reviewer/fixer double-pass anomaly (resolved)
FIX-1.md records: at fixer dispatch the cure content had ALREADY landed on the branch (a sibling/prior fixer pass had committed it); FIX-1 then verified live + ran 2 mutation proofs rather than re-writing; the r2 reviewer's permission map refused the cp-harness mutation, so non-vacuity was established by static re-derivation (PASS). Lead's inflight FINDINGS note (replay-gate absent) was PHANTOM-stale in the same family as C-PRV-2.b's r2 note. No weld-time consequence: reviewed tip == picked tip.

## Carry to closeout
- Suite-figure re-pin: brief carry noted as **+2T/+17A** (per lead ledger convention); measured new-file sums are +25T/+169A for the two NEW files — reconciliation belongs to the K=24 battery re-pin (staleness pair is the authoritative pin there). Welder never touches suite-figure.json.
- durations backlog 68 rows (above) — closeout duty.

## Absorption
`git cherry master cc/C-EXT-4.b` after weld commit → `-` (patch-id identical). Worktree /home/sites/cc-cmp-C-EXT-4.b removed, branch cc/C-EXT-4.b deleted.
