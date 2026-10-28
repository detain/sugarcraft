# C-SEC-8.e — WELD record (batch-5 step 6, 2026-10-28)

Verdict source: canonical REVIEW-2.md (CLEAN 0C/0M, twin-A). lanes/C-SEC-8.e/
REVIEW-2-twin-claims.md IGNORED per lead directive (that reviewer was briefed on
phantom hashes).

## Picks (cherry-pick -x, zero conflicts, as REVIEW-2:1 predicted)
- 851b6614a → 6a1bbc540 (${keychain:<name>} provider keys: src/Support/Keychain.php
  + KeychainTest + ProviderFactory pass + en.php 5 keys + ENVIRONMENT.md; en.php
  soft-doc append auto-merged clean)
- c44b27101 → cc3be741c (r1 review fixes: MINOR-1 dash/control-name fold in
  resolve(), MINOR-2 ProviderFactory.php:482-484 tradeoff comment)

## Gates on merged tree (plain pipe, cwd=candy-code) — EXACT vs REVIEW-2
- `--filter KeychainTest` → Tests: 21, Assertions: 74 (+2 house Deprecations,
  r1-established) — EXACT. Junit: /tmp/opencode/keychain-sec8e.xml.
- `--filter ProviderFactoryTest` → Tests: 114, Assertions: 304 — EXACT.
- guard battery `DescriptorInheritanceGuard|StderrEmitterCensus|EnvRosterDrift|DocFigureProseDrift|GlobDialect|DenialPrefixRoster|CliLangCatalogue`
  → Tests: 278 EXACT, Assertions 70,712 (+306 tree-scan wobble), FAILURES: 1 —
  sole red NAME-verified CliLangCatalogueTest::testTheCliBlockIsOneSortedRunBetweenItsMarkers.
  STANDING-BASE PROOF: identical single failure reproduced at pre-weld tip
  49658ade4 (same cli.bundles.marketplace.* disorder) BEFORE any lane content
  landed → foreign standing red, not lane-attributable.

## Durations (welder minted; scripts commit 0cd7dc95b)
- candy-code/tests/Support/KeychainTest.php 0.43 / 21 (re-measured from merged
  junit; r2's ~0.7s claim now 0.43s). tsv 1561 → 1562.
- OBSERVATION-A honored: NO ProviderFactoryInterpolationTest row minted (phantom
  file — exists neither in lane nor tree). ProviderFactoryTest row already
  present (existing file, +4 tests; shard time rides the closeout regen).

## Figure carry (note only — §7 closeout re-pins)
+21T per REVIEW-2 (r1-era +18T plus the fix round's new tests; ProviderFactoryTest
row already exists, +4 tests inside it). r2: take the green K-run figure, not
arithmetic.

## Carried NITs (closeout-owned, OBSERVATION-B)
ENVIRONMENT.md -p drain caveat; memo-staleness note for C-SEC-8.f. Unchanged
from r1; not weld content.

## Absorption proof (post-weld)
git cherry master cc/C-SEC-8.e → both '-'; patch-id SAME both picks. Worktree
../cc-cmp-C-SEC-8.e + branch cc/C-SEC-8.e pruned after proof.
