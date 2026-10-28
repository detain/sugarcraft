# WELD — C-EXT-4.a "Marketplace core: manifest reader, registry, provenance, search, recommender"

- **Welded:** 2026-10-28 by weld agent (sole master writer), wave-14 batch, run-state 128→129 (final lane of this seven-lane run).
- **Pick:** lane `8dd4df6ef` (base 341f66404) → master `533a881ca`, zero conflicts, patch-id
  `4e6d52aed179…` identical both sides (author/committer detain@interserver.net, 0 trailers).
  `ReadPathCensusTest`/`ContainedPathInventoryTest` roster hunks auto-merged keep-both against the
  C-TOOL-5.c rows already in master.
- **Durations:** `scripts:` commit `5ed26cf92` — four rows from single-file phpunit runs on the merged
  tree (MarketplaceRegistryTest 0.152/16, BundleSourceTest 0.070/7, MarketplaceTest 0.054/9,
  BundleRecommenderTest 0.051/10). tsv 1494→1498, set-equality exact, baseline desc-violations unchanged.

## Phantom-hash history (lead request)
An earlier incarnation of this lane's closeout cited `6d1c5435b`; that object exists nowhere in the
repo (`git cat-file -t 6d1c5435b` → fatal). The lane genuinely re-landed as `8dd4df6ef`, which is the
one commit on `cc/C-EXT-4.a` over base 341f66404 and is what was picked here. No content from the
phantom hash exists or was welded.

## Gates (merged tree, cwd candy-code, plain pipe)
- `--filter 'Marketplace|BundleSource|BundleRecommender'` → **47T/202A, 0F/0W** (roster said ~50T/198A;
  47/202 is the lane's own as-built figure, reproduced exactly; 2 PHPUnit deprecations = process noise).
- `--filter 'ChildLifetime|DuplicatedTestHelperDrift|SymbolCitationDrift'` → 100T/17206A green.
- `--filter 'ReadPathCensus|ContainedPathInventory|StderrEmitterCensus|TreeWideGuardRoster'` →
  180T/25157A/**4F**, both families attributed, neither fixed (report-only law):
  1. **ContainedPathInventoryTest ×2** — derivation-vs-committed drift naming ONLY foreign files
     (Memory/AgentMemory, Skills/SkillLoader 3→4, Host/Commands/ContextHostCommand,
     Host/TranscriptProjector, Tools/PathJail 5→6). Zero Bundles rows in either diff. Lane cp-harness
     reproduced identical reds at base 341f66404 with lane files removed → pre-existing foreign drift;
     weld/closeout domain owns the inventory re-derive.
  2. **TreeWideGuardRosterTest ×2** — `tests/LSP/LspInstallerTest.php => scandir($dir)` unrostered.
     That file arrived via **C-TOOL-5.c pick `14d37bbf5` earlier in THIS run** (lane-3 gates and reviews
     did not include TreeWideGuardRoster). Needs one roster row (`WALKS_A_DIRECTORY_THE_TEST_MADE` —
     the walk is over a test-created dir); the fix is one line in a candy-code test guard, out of the
     welder's remit → **escalated to the lead as the run's new finding.**

## Notes
- No REVIEW file in this lane's evidence dir (BRIEF+REPORT only) — lead's standing prompt listed the
  lane as verified-CLEAN; welded on that authority.
- Registry is pinned-EMPTY by construction (zero default entries); install gate refuse-by-default.
- Proof: `git cherry master 8dd4df6ef` → `-` (absorbed). Worktree + branch removed post-proof.
