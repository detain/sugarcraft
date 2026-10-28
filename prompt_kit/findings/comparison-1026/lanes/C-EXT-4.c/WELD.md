# C-EXT-4.c — WELD (batch-8 step 1)

Welded 2026-10-28 by batch-8 welder on top of 302e99ac2 (batch-7 closeout). NEVER pushed (lead pushes at wave close).

## Picks (master hashes)
- `0992a65b3` ← lane `896f09903` (bundle:<cmd> namespacing, NAME_PATTERN widen lead-approved)
- `ae8baac43` ← lane `d19029a14` (review fixes round 1)

Collision scan: only `tests/Config/DocFigureProseDriftTest.php` had drifted on master since the
lane base (cd87233d0 → a2d5d0e3f ARCHITECTURE 59→60 weld-cure); hunks disjoint, both picks applied
clean, keep-both not needed.

## Gates on merged tree (cwd candy-code, plain pipe)
- `--filter BundleCommandNamespaceTest`: **9T/62A green** (matches brief expectation).
- Lane canonical filter `BundleCommandNamespace|ForeignCommand|BundleSurface|CommandSpec|CommandLoader|CustomCommand|GenCommandDocs|ReadmeRoster|DocFigure`: **276T/4916A/0F green** (lane measured 276T/4891A +1F). The lane's foreign-standing red `DocFigureProseDriftTest::testArchitecturePageCountersAreLiveOrDigitless` did NOT reproduce — a2d5d0e3f on master already cured it; +25A is that cure. Not attributed to this lane.

## Bookkeeping
- durations.tsv: MINTED `candy-code/tests/Commands/BundleCommandNamespaceTest.php 4.878 9` (descending insert before WorkspaceHostTest row; asc-violations 4 == baseline parity).
- §4 mark: `✅ d19029a14` on the C-EXT-4.c heading.
- **suite-figure re-pin NOT owed here** (§7 duty): this weld adds +9T/+4916A-era live tests; README/suite-figure.json staleness pair stays the wave-close re-pin's problem, per program law.

## Carries
- Lead-ruled deviation recorded at 896f09903: CommandSpec::NAME_PATTERN allows exactly one leading `[A-Za-z0-9][A-Za-z0-9_-]*:` segment for bundle rows.
