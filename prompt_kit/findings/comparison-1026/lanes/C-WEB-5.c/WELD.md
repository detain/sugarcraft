# C-WEB-5.c — WELD record

Welder: cc-cmp-welder, 2026-10-31. Master before: a7425d85e → after: four picks + weld commits on top. Lane /home/sites/cc-cmp-C-WEB-5.c @ b3a41cfd8 (4 picks off 97201c6af; porcelain 0; law 4/4/0 by separate COUNT greps at dispatch AND at landed picks).

## Step 1 — picks (explicit list read from `git -C lane log 97201c6af..HEAD`, no -x per t646 ruling)
- f28c26790 → e510b7806 subscription VO + RFC 8291 aes128gcm cipher + byte-exact vector suite (3 files, +572)
- e0d095eed → 2976e88d6 VapidKeys P-256 mint-once 0600 + ES256 DER→raw + StateDir const + ReadPathCensus SELF_LOCATED row (4 files, +597; ReadPathCensusTest auto-merged clean)
- 6248dd790 → 850c97b77 allowlisted sender through PublicUrlGuard single-sourced + en.php serve.push.* run + TWGR row (5 files, +630/−2) — CONFLICT only in TreeWideGuardRosterTest WALKS_A_DIRECTORY at the same insertion point; resolved KEEP-BOTH in sorted order (Protocol/WebStringsTest.php [C-TUI-6.f, HEAD side] then Server/Push/WebPushSenderTest.php [lane, chr(42)-respelled]); en.php auto-merged (append-union)
- b3a41cfd8 → c53098787 round-1 fix, 4 files +83/−2, NO new files (matches FIX-1.md)

## Step 2 — regeneration
gen-protocol-schema / gen-command-docs / gen-tool-docs / gen-settings-doc `--check` ALL rc0 "up to date" → ZERO regenerate leg (lane moved no method/scope row; name-diff of the 4 picks: 11 files, all candy-code/src/Server/Push + tests/Server/Push + StateDir + en.php + TWGR + ReadPathCensus; zero candy-code-web paths → vitest/dist legs UNOWED).

## Step 3 — merged-tree gates (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe; every figure below measured at merged tip)
- Push trio composed anchored `(PushPayloadCipherTest|VapidKeysTest|WebPushSenderTest)::`: **39T/136A OK** — EXACT r2 match (9T/21A + 16T/45A + 14T/70A class).
- Server adjacency `--filter 'Tests\\Server'`: **502T/6825A/2F** — 2F exactly the accepted AttachPagingTest pair (both legs named: stitchesOlderPages / WithoutPagingTakesWholeSnapshot); +4A wobble vs r2's 6821 = merged-tree growth, disclosed, not chased.
- TWGR BARE: **17T/3914A OK** (lane-era ref 17T/3889A — +25A merged-tree row recount, assertion-only); DuplicatedTestHelperDrift BARE: **14T/204A OK** EXACT.
- ReadPathCensusTest::: **19T/750A OK** (lane ref 19T/738A +12A wobble). UNION PROVEN in blob: DiscoveryFile :993 · StaticFiles :998 · **lane VapidKeys :1001** · ServerConfig :1006 · StateDir :1014 — all master-side rows from the 6.c/4.d era survive; lane word SELF_LOCATED as r1 adjudicated.
- StderrEmitterCensusTest::: **95T/23423A OK** (zero push emitters, per r1 NOT-OWED).
- DescriptorInheritanceGuardTest::: **15T/5159A OK** — NO NEW ROW (zero proc_open in src/Server/Push, r1 Q4).
- CliLangCatalogue|LangParity anchored: **15T/3714A GREEN** (sorted-run placement law holds; serve.push.{endpoint_denied,endpoint_blocked,ttl_invalid,transport_failed} contiguous at en.php:3615-3618, between serve.dir_browse.disabled and dirs.not_found).
- LayeredSettingsTest::: **99T/411A GREEN** — derived==pinned at merged tip, cure 00fedf519 in force, NOT folded (lane extends no settings key).
- PAIRING co-run `--filter 'BashSandboxTest|BubblewrapHardeningTest'` ONE process: **39T/190A GREEN** — db1441a61 cure holds (r2 Q6 pre-registered expectation read green).
- en.php python dupscan: **3,475 keys, 0 dups**.
- Accepted shapes AS MEASURED (report-only, cured NONE): ReadmeSuiteFigureDrift staleness pair 2F (headline+artifact legs — the +39T carry lands at battery re-pin); GlobDialectDifferentialTest::testTheCorpusFigureInTheDocBlockIsTheDerivedOne 1F — same accepted docblock-vs-derived leg; per roster law NEITHER number asserted.
- check-one-type-per-file (repo root): rc0 — 62 libs, 3,351 psr-4 files, 0 problems.

## Step 4 — mark + commit
§4 mark written BY TEXT at the unmarked heading (was :3756); python startswith-prefix count 305 → **306**. Four disk-read landed shas in the mark: e510b7806,2976e88d6,850c97b77,c53098787.

## Step 5 — absorption + prune
git cherry master cc/C-WEB-5.c = `-` ×3 + `+` for 6248dd790 — CONTENT-PROVEN patch-id FALSE-POSITIVE (the TWGR keep-both resolution + en.php/ReadPathCensus unions against master-side additions since base change context, not content): (1) per-file ADDED-LINES of lane 6248dd790 vs landed 850c97b77 byte-identical ×5 files (sorted md5 compare); (2) every lane-added line of en.php (4/4), ReadPathCensusTest (5/5), TWGR (2/2) present in the merged blob — python containment, zero missing; (3) master-vs-lane TWGR blob diff = ONLY the +3 C-TUI-6.f master-side union lines, zero deletions; sender/VO/cipher/VapidKeys/WebPushSenderTest blobs byte-identical (BLOB-SAME proof). Disclosed, then worktree ../cc-cmp-C-WEB-5.c + branch cc/C-WEB-5.c removed. Survivors: cc/C-TOOL-6.d · cc/C-TOOL-6.g · cc/C-TUI-6.c · cc/C-TUI-6.g (cc/C-TUI-6.j-era already pruned; cc/C-TOOL-6.a never touched).
No push. No §3 row edit. suite-figure.json / durations.tsv / dist / protocol trio NEVER touched.
