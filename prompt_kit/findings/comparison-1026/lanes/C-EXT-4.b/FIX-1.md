# C-EXT-4.b FIX-1 (round 1) — scan-branch pins + REPORT truth

Tip: de5c16a51 → amended 1e03fa00a1e868f199244893a6b78e43386049a4 (tree byte-identical; message + "(round 1: scan-branch pins + REPORT truth)"). One commit (master..branch=1), porcelain 0, %ae=detain@interserver.net (grep -c → 1), %ce=detain@interserver.net (separate grep → 1), zero Co-Authored (message grep -ci → 0). Never pushed; master untouched.

NOTE: the cure content (both tests + REPORT fix) had ALREADY landed on disk at dispatch time — the branch tip at my start was the amended de5c16a51, not the reviewed ca7fd9f47 (sibling/prior fixer pass; REVIEW-2.md at de5c16a51 confirms, and r2 reviewer's permission map refused the mutation harness). This round verified the cure live, ran the two REQUIRED mutation proofs, and carried the message suffix the prior amend had omitted.

## MINOR-1 (verbatim: "BundleUpdateTest.php has ZERO pins for the two scan-mode branches this lane shipped: the `!$found` gate (Subcommands.php:2899 → `marketplace \"%s\" offers no plugin named \"%s\"` gate refusal) and the `marketplace_gone` skip (Subcommands.php:2910 → skip + registry untouched, exit ok). Shipped behavior, untested — a mutation that deletes either branch keeps every reviewed filter green.")
Cure (in blob, verified green at tip): two tests mirror the named-case harness (testUpdateStopsWhenTheRecordedMarketplaceIsGone…/testUpdateRefusesWhenTheEntryLeftTheCatalog…, name operand dropped, existing verb/verbCapture/addPathMarketplace/entriesUnderRoot helpers reused — no new helpers, DuplicatedTestHelperDrift-clean).
- (a) testScanUpdateRefusesAnEntryThatLeftTheCatalogAndKeepsTheTree (:141): install alpha@demo, catalog plugins emptied, SCAN update → EXIT_FAILURE; refusal sentence pinned against the LIVE gate via assertSame('marketplace "demo" offers no plugin named "alpha"', $registry->installGate(...)); tree intact (MINE.txt kept + entriesUnderRoot()==['alpha'] → no rewrite, no scratch); 'Updated bundle' absent from captured stdout.
- (b) testScanUpdateSkipsABundleWhoseMarketplaceIsGoneAndExitsOk (:104): marketplace remove demo, SCAN update → EXIT_OK; skip notice byte-pinned against Lang::t('cli.bundles.update.marketplace_gone',…) (em-dash literal); marketplace('demo') null (registry untouched), tree intact, no update line on stdout.
Red-before, honest: the tests are NEW (parent ca7fd9f47 carries zero scan-mode pins — red-before is by absence, same shape r1 accepted for the lane's 22). Discrimination is pinned by the mutations below, one red each, exact names.

## MINOR-2 (verbatim: "REPORT.md: correct '8 foreign occurrences' to '9 occurrences across 7 files' (the numeral IS in the new AutoReviewScopeTest.php; the 2F offenders are not).")
Cure: REPORT.md:17 (lanes dir, main tree, uncommitted — NOT in the commit) now reads "9 foreign unclassified dot-path occurrences across 7 files" with the attribution ledger (verified on disk). The "AutoReviewScopeTest.php" cite in the finding text does not match this lane's 10-file diff (no such file here) — substance (8→9, across 7 files) applied as written.

## NIT (recorded, per brief): the r1 phrase "refusal sentence pinned byte-for-byte" — for leg (a) the pinned byte-literal is produced by calling the PRODUCTION installGate with the live marketplace ('demo' is fixture-deterministic, so the rendered sentence IS byte-asserted); the family "bundlesLine bytes ride stderr, uncapturable" note stands for future harnesses.

## Gates (plain pipe, env -u LINES -u COLUMNS, cwd worktree candy-code; PtyRunToolTest never named)
- --filter BundleUpdateTest: OK Tests: 10, Assertions: 63, PHPUnit Deprecations: 2 (pre-existing config-level pair, same 2 under every filter incl. untouched ones — r1/r2 disclosed).
- --filter Marketplace: OK Tests: 46, Assertions: 272.
- --filter Bundles: OK Tests: 131, Assertions: 564 (r2's tests/Bundles-directory run: 118T/499A — same set, filter-shape difference disclosed).
- --filter 'StderrEmitterCensus|ReadPathCensus': OK 114T/22058A. r2's 131T/25587A spelling (adds TreeWideGuardRoster) unchanged by this round (zero src delta).
- Brief's 'SettingsWriterCensus|ReadmeJsonErrorContract|StderrEmitter|ReadPathCensus' spelling: 138T/27198A, FAILURES 4 — ALL FOUR are ReadmeJsonErrorContractDriftTest failing at :372 on src/MCP/McpServerRequests.php "'error' => […] has no 'type' => <literal>" (fails during the src scan, before any README derivation; the brief predicted "131T + foreign Chat/Host 2F" — the ChildStderrCapture 2F family did NOT match this filter spelling at all (no `Stderr|`), and the 4 ReadmeJsonErrorContract reds are foreign-inherited: McpServerRequests.php and the test are byte-identical base(e4a705244)↔tip (md5 dff1ba4a… / 09635437… both ends) and this lane's 10-file diff contains zero hunks under src/MCP or tests/Config. Disclosed as the actual unchanged-foreign state, not forced.

## Mutations (cp backup + cmp restore each, md5 53a5bd3072cb1f2bf3ba8064529fa0cd; NO git stash; python assert-count-1 anchors, APPLIED printed)
- M1 gate-branch scan-forgiveness flip ($status = NonInteractive::EXIT_FAILURE → $named ? … : $status inside `if ($gate !== null)`): `Tests: 10, Assertions: 58, Failures: 1` — exactly testScanUpdateRefusesAnEntryThatLeftTheCatalogAndKeepsTheTree. Restore cmp clean, porcelain 0.
- M2 marketplace_gone tolerance neuter (`if ($named) { $status = EXIT_FAILURE; }` → unconditional): `Tests: 10, Assertions: 58, Failures: 1` — exactly testScanUpdateSkipsABundleWhoseMarketplaceIsGoneAndExitsOk. Restore cmp clean, porcelain 0, re-run baseline OK 10T/63A.

## Blob-verify at tip
git show 1e03fa00a:…/BundleUpdateTest.php | grep -c 'GoneMarketplace\|NotInMarketplace\|testScan' → 2 (matches ride the testScan token; the guessed substrings do not occur — exact-name grep pins testScanUpdateSkipsABundleWhoseMarketplaceIsGoneAndExitsOk :104 + testScanUpdateRefusesAnEntryThatLeftTheCatalogAndKeepsTheTree :141).
