# C-SEC-8.e REVIEW-2 (r2, fix round, reviewer: cc-cmp step)

VERDICT: CLEAN (0 CRITICAL / 0 MAJOR / 0 MINOR / 2 observations)

Fix commit c44b27101 (parent 851b6614a intact, follow-up not amend; detain %ae/%ce =1 each, zero trailers, tree clean). Scope 851b6614a..c44b27101 = exactly the 3 claimed files, +92/−1; en.php untouched (5/5 cli.keychain.* census, fold reuses cli.keychain.miss — no new key ✓); lane full-set master...HEAD still the declared 7.

MINOR-1 fold — shipped as prescribed. resolve() (Keychain.php:165-176) tests /^-|[\x00-\x1f\x7f]/ after the empty-name check, BEFORE argvFor/spawn; folds to cli.keychain.miss(entry,os), null. Byte-semantics preg (no /u) — NUL/C1-safe on binary subjects.
- Placement trace: fold lives in resolve(), reached only via lookup()'s memo-miss leg; the null result is memoised like every other miss shape (house law testAMissIsMemoisedLikeAHitSoTheLaunchSaysItOnce) → notice once per folded name per launch, no re-announce, no spawn, no re-spawn on repeat. Contract's "no memo entry" wording is imprecise — the on-disk memoised-null is the CORRECT, consistent behavior (matches REPORT's actual claim; no defect).
- Non-vacuity proven by trace: fold-neuter → dash pin reds at assertNull+assertSame(0,calls), control pin reds at first assertNull; discriminator (candy-openai, team-key/v2 → captured[5] name legs) stays green under neuter AND would red under blanket-refusal — a true two-sided pin. Matches fixer's mutation record (21T/F2 exactly, cp+md5 restore).
- Control pin covers NUL-mid, \x01, \x7f (3 rows/3 names) — one-per-name leg real.

MINOR-2 — ProviderFactory.php:482-484 comment at the keychain pass documents the ${VAR}-downstream-expansion tradeoff; REPORT deviation #2 claim now true as written ✓.

Gates re-derived at tip (cwd=candy-code, plain pipe, env -u LINES -u COLUMNS): KeychainTest OK 21 tests/74 assertions (2 house-wide Deprecations, r1-established); ProviderFactoryTest OK 114/304; guard battery (DescriptorInheritanceGuard|StderrEmitterCensus|EnvRosterDrift|DocFigureProseDrift|GlobDialect|DenialPrefixRoster|CliLangCatalogue) 278T/70406A/F1 — sole red NAME-verified CliLangCatalogueTest::testTheCliBlockIsOneSortedRunBetweenItsMarkers (cli.bundles.marketplace.none/not_added disorder; zero cli.keychain lines in diff) = the foreign standing base-red, not a count match.

Welder carries RESTATED:
1. Picks 851b6614a + c44b27101. Collision probe vs current master (81c47175f, past c4c13f101, C-WEB/C-TUI-3.f/C-SES-8.c landed): master has NOT touched ProviderFactory.php, ProviderFactoryTest.php, ENVIRONMENT.md, DescriptorInheritanceGuardTest, Keychain.* since lane base; en.php moved at lines 1311/1617/2368/2559 — lane hunk region ~740-749 → disjoint, expect zero-conflict picks (verify at weld).
2. durations rows: EXACTLY ONE new row — candy-code/tests/Support/KeychainTest.php (now 21T; re-measure the ~0.7s claim). OBSERVATION-A: the r2 brief's second row "ProviderFactoryInterpolationTest.php 3T" is a PHANTOM — no such file exists in the lane or tree; do NOT mint a row for it. ProviderFactoryTest.php row exists (existing file, +4 tests — re-measure from durations regen run).
3. Figure: +21T (was +18T) from the green K-run; assertions from the run, not arithmetic.
4. OBSERVATION-B (closeout-owned, unchanged from r1): NITs (ENVIRONMENT.md -p drain caveat; memo-staleness note for C-SEC-8.f) still un-taken — carried, not blockers.
