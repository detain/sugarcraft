# C-EXT-4.b REVIEW-2 (fresh r2 cure-review, read-only)

Lane: `cc/C-EXT-4.b` @ de5c16a5149dc814ac599891fa1fcc1540166328, base e4a705244, worktree /home/sites/cc-cmp-C-EXT-4.b.
ONE commit (`git log --oneline e4a705244..HEAD` → de5c16a51 alone); porcelain clean; `%ae`=detain@interserver.net and `%ce`=detain@interserver.net verified on SEPARATE greps; no Co-Authored trailer.

## VERDICT: CLEAN (0 CRITICAL / 0 MAJOR / 0 step-local MINOR / 2 NIT-notes) — ship; weld carries the 2-row tsv duty + figure re-pin

## Phantom-fix gate (blob-grep before believing) — PASS
- `git show de5c16a51:candy-code/tests/Bundles/BundleUpdateTest.php` contains BOTH new names: testScanUpdateSkipsABundleWhoseMarketplaceIsGoneAndExitsOk (:104) and testScanUpdateRefusesAnEntryThatLeftTheCatalogAndKeepsTheTree (:137 in-file; the two exit-polarity anchors sit at blob lines :113 and :153 exactly as claimed — grep -n confirmed).
- numstat vs base: 10 files, +2057/−14 — exact (r1 tip was +2008/−14; +49 = the two legs).

## Cure quality
1) **Non-vacuity (static re-derivation; my reviewer map refused the cp-mutation harness — `cp` denied, nothing executed, tree stayed clean — r1 set the same precedent).** Production `bundlesUpdate` (Subcommands.php:2840+): the marketplace-GONE branch sets `$status=EXIT_FAILURE` only `if ($named)` → leg (b)'s :113 EXIT_OK reddens under a tolerance-neuter. The GATE branch and the `not_a_source` fallback set FAILURE unconditionally → leg (a)'s :153 EXIT_FAILURE reddens under the scan-forgiveness flip r1 named. Both legs carry tree-intact (`MINE.txt` + `entriesUnderRoot()==['alpha']`) and `assertStringNotContainsString('Updated bundle', $out)` legs; the success line is `echo`d to STDOUT (:2991) while refusals ride `bundlesLine`→STDERR (:1926), so the stdout-capture helper makes the absence-leg meaningful and the stderr bytes structurally uncapturable.
2) **Sentence is production-rendered, not a fabricated transcript**: leg (a) pins `assertSame('marketplace "demo" offers no plugin named "alpha"', $registry->installGate($marketplace,'alpha'))` against the live registry gate (MarketplaceRegistry.php:410 builds exactly that string); leg (b) pins the byte-literal against `Lang::t('cli.bundles.update.marketplace_gone',...)` and the literal matches en.php:183 verbatim (em-dash included). Test comments state the family law honestly.
3) **Scan semantics match code**: refuse-on-missing-entry FAILs the scan (gate + not_a_source unqualified); skip-on-marketplace-gone exits OK (`if ($named)` gate). Matches the declared deviation and r1's prescription (mirror :89/:102, drop name operand, exact exit polarities).
4) **DenialPrefixRoster**: `--filter DenialPrefixRoster` → OK 17T/155A. The refusal sentence is not '**Refused:**'-shaped, the string lives in pre-base src (untouched by this lane), and the census scans src/ — no roster row owed, none missing.

## Gates reproduced (cwd worktree candy-code, plain pipe, env -u LINES -u COLUMNS; PtyRunToolTest never named)
- `tests/Bundles`: **OK (118 tests, 499 assertions)** — exact (+2T/+17A over r1's 116T/482A).
- `StderrEmitterCensus|ReadPathCensus|TreeWideGuardRoster`: **OK 131T/25587A, 0F** (+2 pre-existing PHPUnit deprecations, r1-disclosed) — exact.
- doc-guards `ReadmeRosterDrift|DocFigureProseDrift|GlobDialect|DuplicatedTestHelperDrift|LangParity`: **OK 136T/7613A, 0F** — exact; GlobDialect green ⇒ no glob-shaped new literals; DuplicatedTestHelperDrift green ⇒ legs reuse the file's existing verb/verbCapture/addPathMarketplace/entriesUnderRoot helpers, none added.
- NIT-A (filter hygiene): the coarse `Stderr|...` spelling also matches ChildStderrCaptureTest, which fails 2/6 here — offenders are Chat/MemoryOpenJournalTest, Host/×4, Protocol/×2, all pre-existing base files, NONE in this lane's 10-file diff (numstat proves it) ⇒ foreign standing red, excluded, not attributed.
- Standing foreign reds excluded per brief: ReadmeSuiteFigureDrift staleness pair, ProjectTierRefusalInventory (9 rows/7 files), PermissionsIntroDrift, DreamPassTest, C-SEC-7.d FIFTY-SEVEN Bootstrap numeral (r1-confirmed not this lane).

## Hygiene
- One cohesive commit; subject as briefed. No suite-figure.json / durations.tsv / candy-code-web/dist touches in the diff. Serialized regions honored: lang adds only cli.bundles.* (plus the completion verb row the BRIEF's completion-roster step authorizes); README/PLUGINS.md rows only; Subcommands hunks stay in the bundles region.
- REPORT.md (main-tree, rides weld): MINOR-2 cure live — "9 foreign unclassified dot-path occurrences across 7 files" with the attribution ledger. Verified on disk.
- r1 NIT-1 (doc-guard 135T-vs-136T) and NIT-2 (28-vs-29 lang keys) — key-count now 29 added keys in en.php; report prose not re-litigated (cosmetic).

## Welder duties (confirmed from the diff)
- Exactly 2 new *Test.php (name-status 'A'): tests/Bundles/BundleMarketplaceCliTest.php + tests/Bundles/BundleUpdateTest.php ⇒ exactly 2 durations.tsv rows. No other new test files.
- suite-figure.json + README figure pair re-pin at weld (live enumeration +2T, +17A over r1's already-shifted figures).
- C-SEC-7.d carries the Bootstrap.php docblock numeral flip; ProjectTier 9-row backlog stays foreign.

## NIT-B
The gate-branch `bundlesLine($gate)` emission itself is unpinnable with the stdout-capture shape (exit parity is double-gated via installEntry's internal re-check); a message-deletion mutation would survive. Accepted — beyond r1's prescription; note for any future stderr-capture harness lane.
