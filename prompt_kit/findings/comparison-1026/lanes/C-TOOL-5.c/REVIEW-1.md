# REVIEW-1 — lane C-TOOL-5.c (LSP installer core) @ 08e4d7d6a

VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 1 MINOR (step-local) / 2 NIT

## Scope verified
- diff == declared 8 paths (2 new src LSP files, LspLauncher.php, 4 test files) + REPORT.md; single commit; %ae=%ce=detain@interserver.net (separate greps), empty body, zero trailers; porcelain clean after gates.
- Ruling §6.1 alignment: LspReleases admits EXACTLY the four design servers (rust-analyzer binary + intelephense/typescript-language-server/pyright npm). No extra host beyond {github.com, objects.githubusercontent.com} + registry.npmjs.org. All four linux/darwin sha pins re-hashed live against the 2026-10-26 tag — exact match.

## Focus findings
1. SSRF/redirect law — PASS. isAllowedUrl() (https-only, exact hosts, no userinfo/port, date-grammar repo path, asset regex on basename; objects arm requires /github-production-release-asset path prefix AND filename query match) runs pre-request AND in the on-redirect guard every hop; binaryEntry() re-gates its own constructed URL on the way out. 12-way lookalike refusal test incl traversal, @host, port, http:, query-forgery.
2. Untrusted-archive discipline — PASS. hash_equals BEFORE any fopen of compress.zlib://; only decompressor is single-stream gz (no tar/zip → no zip-slip surface; target name is the manifest literal, never archive metadata). PrivateDir::ensure root+bin (lstat, 0700, refusal tested pre-fetch). Mismatch path: finally unlinks temp; residue assertion in testMismatchedBytesRefuseBeforeAnythingLands pins leave-nothing — the extract-before-verify mutation is killed by that listing assert (analytic; reviewer map denies cp/edit mutations, see NIT-2). E646 structural pin: assertArrayNotHasKey 'timeout' x2 (connect/read/low-speed only).
3. npm arm — PASS. Table-literal pkg@ver (no user input); escapeshellarg on every token incl --prefix and pinned https registry; --ignore-scripts inside the const-built command (unremovable); runCaptured = pre-licensed proc_open site (grep proc_open LspInstaller.php == 0); post-run is_file+is_executable on node_modules/.bin/<binary>.
4. Precedence — MINOR-1 below.
5. MINOR-1 (step-local) tests/LSP/LspLauncherAutoDetectTest.php:323-344 — "install-dir beats PATH" is behaviorally vacuous for the both-present case. The planted names (intelephense, pyright-langserver) are not on the test host's PATH, so reordering `$chosen = $installed ?? capabilityPresent(...)` to PATH-first keeps ALL tests green here and on CI. Builder disclosed the hole in REPORT. Fix: add a deterministic discriminator — house genre already at :305 (source-slice): a structural pin that installedBinaryPath resolves BEFORE capabilityPresent in the fromConfig chosen-expression (assert substr offsets), or thread capabilityPresent's existing $pathList seam. (rust-analyzer-on-PATH behavioral test rejected: host-dependent.)
6. NIT-1 src/LSP/LspInstaller.php:352-354 — chmod-failure return leaks $staged ('.dl-*.out') in bin/; the sibling rename-failure branch (:355-356) unlinks. Add @unlink($staged) for symmetry with the leave-nothing law the tests police elsewhere.
7. NIT-2 — builder gate figures (197T/1437A; guard set 343T/20830A) undercount my reproductions (210T/1515A; 357T/20911A): same filter also matches 13 BinCandyCodeWiringTest dataset rows (they pass — good signal). Verdict shape identical; not a finding, bookkeeping note.

## Gates reproduced (lane tree, cwd=candy-code, plain pipe, -u LINES -u COLUMNS)
- --filter '(LSP|ReadPathCensus)': OK 210T/1515A (+2 PHPUnit deprecations — traced to FOREIGN tests/Config/AccessorDeprecationTest.php:168/:202 non-public data providers; pre-existing, lane diff does not touch that file).
- guard set (+DuplicatedTestHelper|GlobDialect|ChildWallClock|DocFigure|TreeWideGuardRoster): 357T/20911A, ONLY the 2 foreign TreeWideGuardRoster failures naming Cli/JsonSchemaEnvelopeTest + Context/MemoryRecallBlockTest — both files byte-identical to base (git diff master...HEAD = empty). GlobDialect green → no new glob-shaped literals in lane tests.
- tools/tests suite: OK 489T/2578A (includes ChildLifetimesToolTest real-tree green ⇒ child-lifetimes rc0). check-path-repos: lane touches zero composer.json ⇒ law holds by inspection (direct `php tools/...` refused by reviewer shell map).
- brief exclusions honored: StderrEmitterCensus 3F, ContainedPathInventory 2F, ReadmeSuiteFigureDrift, ChatHostLangCatalogue = foreign/standing, not counted.

## Weld owes (record, not finding)
- scripts/parallel-tests-durations.tsv row for NEW candy-code/tests/LSP/LspInstallerTest.php (19T); suite-figure re-pin +19T/+~90A at weld.
- API-REFERENCE/README doc rows for LspInstaller/lsp install (design says docs later) — collect at closeout.
