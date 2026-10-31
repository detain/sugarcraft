# WELD — C-WEB-5.b (`serve url --qr` pairing QR, W34 LATE, ruling R13)

## Record-half weld, 2026-10-31 (second dispatch)

STEP-0 DISCREPANCY CHECK (lead order: re-prove the picks before writing):
- master pre-weld: `51bdf2319dc93f07c723e87e536d286ed1970ccf` ("C-WEB-5.b: review fixes (round 1)").
- `git merge-base --is-ancestor` OK for BOTH picks: `1340951c4f6beda30b43dfdc0c524dbf5838ed63` ("C-WEB-5.b: serve url --qr pairing QR") and `51bdf2319...` (review fixes).
- `git cherry master cc/C-WEB-5.b`: `- 357ebe05e2c38a7bdba5cd2844568f4b2e291057`, `- a7399baa3045f7e577487d011c97651be2a7e7df`, NO `+`. Patch-equivalence holds; lane worktree porcelain 0.
- VERDICT: picks are ON master. Per the hard constraint this weld did NOT re-cherry-pick. The record half was genuinely missing: landed-blob heading `#### C-WEB-5.b — \`serve url --qr\` pairing QR` UNMARKED (master blob :3716, found by text), `^#### ✅` count 293, `lanes/C-WEB-5.b/` had no WELD.md, worktree+branch alive. The previous welder's "RECORD HALF ALREADY COMPLETE ON MASTER" receipt was refuted from disk; the wrong-tree-write failure mode was excluded (lane tree clean, its own plan file also unmarked). This commit completes the record: §4 mark, evidence, run-state, prune.

WELDER-PROCESS FACT (disclosed in the §4 mark too): the first welder for this step landed its picks but never its record; this dispatch closes it mechanically — no re-pick, count moves 293→294.

## Review-loop record (read from disk, byte-statted)
- `REVIEW-1.md` 12,619 B — `VERDICT: FINDINGS — C=0 M=1 MINOR=0 NIT=1`.
- `FIX-1.md` 5,184 B — MAJOR-1 cured data-order-only in `candy-code/lang/en.php` (the two new `cli.serve.qr.*` keys moved into the sorted cli.serve block between `cli.serve.none` and `cli.serve.reason_see_log`); executed proof `CliLangCatalogueTest::testTheCliBlockIsOneSortedRunBetweenItsMarkers` RED-before (1F) / GREEN-after at fix tip; zero src lines moved. NIT (run-level >213-byte URL end-to-end pin through Serve::url) DECLINED with cost probe (no hermetic seam; needs live-child server harness) — left as noted later hardening.
- `REVIEW-2.md` 12,924 B — `VERDICT: CLEAN — C=0 M=0 MINOR=0 NIT=0`, MAJOR-1 closed, NIT decline endorsed on merits; r2's own session incident (an earlier false FINAL claim from that reviewer, self-disclosed and re-landed) is recorded inside the file.
- Lane: base `288b81f11`, 2 picks, tips `357ebe05e` → `a7399baa3`; landed `1340951c4` → `51bdf2319`.

## R13 compliance (proved from the diff)
`git diff 288b81f11..cc/C-WEB-5.b --name-only` = 8 paths, `grep -cE 'composer.json|package.json'` = **0** — pure in-tree PHP encoder (QrEncoder.php, byte-mode ECC v1–10) + half-block terminal renderer (QrTerminal.php); segno 1.6.6 lives only as a build-time fixture generator for the committed test vectors. No new dependency anywhere.

## Merged-tree duties (lane base 288b81f11 predates cure e78d5dc14, the C-WEB-2.e/C-TUI-5.b/C-TUI-3.g welds, both §3 flips and five later LATE welds — re-derived, drift disclosed not chased)
- `candy-code/lang/en.php` APPEND UNION PROVEN: duplicate-key scan of the LANDED blob (`grep -oP "^\s*'[a-z0-9_.]+'(?==)" | sort | uniq -d`) = EMPTY. Keys read from disk (not from any brief): `cli.serve.qr.cleartext` (:1115), `cli.serve.qr.too_long` (:1116), `cli.serve.url.qr_json` (:1152). SUPERSESSION NOTE: the C-EXT-6.b mark disclosed "cli.serve.qr.* reads 0 on the merged tree" — true at ITS anchor; this weld is the commit that lands that family.
- `TreeWideGuardRosterTest` `WALKS_A_DIRECTORY_THE_TEST_MADE` three live rows ALL SURVIVE in the landed blob: :587 `Agents/Live/AgentLiveRegistryTest.php`, :645 `Cli/LoginSubcommandTest.php` (cure e78d5dc14), :801 `Tools/BashSandboxTest.php`. This lane adds NO row of its own — its file set touches zero guard files (proved by the 8-path name list above).
- Shared counters re-derived on the merged tree; assertion-only drift is disclosed below.

## Gates at the merge (cwd=candy-code, `-c phpunit.xml`, `env -u LINES -u COLUMNS`, plain pipe, anchored exact-class filters; PtyRun expect-miss not in any filter)
- `(ServeArgsTest|QrEncoderTest)::` → **69T/1107A OK** — r2-EXACT; per-file: QrEncoderTest 25T/877A, ServeArgsTest 44T/230A (composition 44+25 identical to r2).
- `(CliLangCatalogueTest|LangParityTest|ServerProtocolDocumentationDriftTest)::` → **21T/3692A OK** (the lang catalogue, the 16-locale parity guard the design named, and the SERVER.md guard all green on the merged tree).
- `TreeWideGuardRosterTest` by BARE class name → **17T/3865A GREEN (0F)**; `DuplicatedTestHelperDriftTest` → **14T/204A GREEN (0F)**. Both were red-pairs at the lane tip (2F each — r2 attributed them to the pre-e78d5dc14 base); the merge CURES them, exactly as r2's weld-statement predicted. +2A roster-scan wobble vs the C-EXT-6.b weld's 17T/3863A — identical test count, disclosed not chased.
- `GlobDialectDifferentialTest::` → 13T/364A **1F** — EXACTLY the accepted shape: `src/Util/PathGlob.php` docblock quotes 310,245 while the corpora derive 311,360 (560×556). A named §7 battery rider; NOT cured here, NOT a new family.
- Accepted foreign set carried without re-running per the brief (the lane's 8 files reach none of their fixtures): ApplySettingsTest keepWarm 1F, 5 KeyHelpTest datasets, HelpTest[CANDY_CODE_SERVICE_TIER] 1F, BinCandyCodeDispatchTest --worktree 1F at :1893, tests/Server/AttachPagingTest 2F (lead base-proven at 364a52876), OneSidedHomeSandboxTest :435 1F, ReadmeSuiteFigureDrift staleness pair.
- Generators: `gen-command-docs`, `gen-tool-docs`, `gen-settings-doc`, `gen-protocol-schema` all `--check` **rc=0** → NO `weld: W34 regenerate` leg owed.
- Web chain + protocol regeneration UNOWED, proved from the lane name-diff: **0** paths matching `candy-code-web/` or `src/Protocol/`; zero dist work done here.

## §7 riders handed to the gate
- `scripts/parallel-tests-durations.tsv` row owed for NEW test file `candy-code/tests/Server/Pairing/QrEncoderTest.php` at live figures 25T/877A (welder-forbidden file, not touched).
- suite-figure.json/README staleness pair: this lane adds +25T over the pinned floor — §7 re-pin duty, welder did not touch either file.

## Prune (after absorption proof above and mark verification from the landed blob)
- `git worktree remove /home/sites/cc-cmp-C-WEB-5.b` + `git branch -D cc/C-WEB-5.b`; sibling `cc/C-EXT-6.c` worktree/branch left untouched.

## Cherry-pick trailer law
Picks landed WITHOUT `-x` per CHERRY-PICK-TRAILER-LAW-vs-PLAN-PRECEDENT-t646 — the two landed shas are named in the §4 heading.
