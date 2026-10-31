# C-TUI-6.g — WELD record (welder session, 2026-10-31)

STEP=C-TUI-6.g ROLE=welder. Master base at weld start: `828aab3eb` (weld: C-TUI-6.c — run-state fold). Lane `cc/C-TUI-6.g` @ /home/sites/cc-cmp-C-TUI-6.g, base `29929c305`, SEVEN picks at tip `27e427e85`, porcelain 0.

## Evidence re-stats (disk, this session — lead figures confirmed)
- REPORT.md 4,959 B @12:13:05 · REVIEW-1.md 9,995 B @12:32:28 · FIX-1.md 6,380 B @12:42:44 · REVIEW-2.md 10,087 B @13:03:07.
- REVIEW-1 verbatim header: `VERDICT: FINDINGS — 0 CRITICAL / 1 MAJOR / 2 MINOR / 0 NIT — step C-TUI-6.g`. REVIEW-2 verbatim header: `VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR / 0 NIT — step C-TUI-6.g`.
- Loop lineage HONEST: builder 5 picks → r1 FINDINGS (MAJOR-1 residual chrome in files declared migrated + FALSE TodoPanel disclosure) → fixer 2 cures (c270a3843 + 27e427e85) → r2 CLEAN, after TWO voided fragment sessions — third-cut header-first law delivered (disclosed in r2 header: "fresh third-cut session"). Owner chrome-only ruling (server/tool/model text stay untranslated) enforced through both cures per r2 Q1.

## Ground law greps (lane, re-derived this session)
- `git log --format='%ae' 29929c305..HEAD | grep -c detain` = 7; `%ce` separate grep = 7; Co-Authored-By = 0. Explicit pick list (in order): cfbbeffc5, b9c7b9db6, d993a3904, 0685ff8d5, f73ac4315, c270a3843, 27e427e85 — matches the lead's list verbatim.
- Lane diff name-only = 48 files, all `.vue` + lang/en.php + web-strings.en.json + strings.en.generated.ts (forbidden-path grep over range: none).
- Master-side since base: en.php +11 lines only (C-TOOL-4.d sandboxScope at :2227/:2321-2323/:4047 + C-WEB-5.c serve.push.* at :3615-3618); mirrors + master-side protocol trio: zero drift.

## Heading state (phantom-reweld check)
- `#### C-TUI-6.g — Web i18n: migrate the remaining components` at plan_comparison.md :3852, UNMARKED (no ✅) → first welder. Marks (python count of `^#### ` lines containing ✅) = 307 pre-weld → 308 post-mark.

## Picks landed (this weld, master, cherry-pick no -x, ALL SEVEN CONFLICT-FREE)
| lane sha | landed sha | subject |
|---|---|---|
| cfbbeffc5 | cb630ea46 | slice 1 views + SessionSidebar/AboutModal/HelpOverlay |
| b9c7b9db6 | 77a328148 | slice 2 Composer + QueueStrip + HistorySearch + DirectoryPicker |
| d993a3904 | 5b66a65af | slice 3 transcript cluster ×8 |
| 0685ff8d5 | 774b7866d | slice 4 panels/* ×11 |
| f73ac4315 | 34524cf58 | slice 5 agents+approvals+grid+settings |
| c270a3843 | 9b1e240f0 | r1 fix-1 TodoPanel + MessageMarkdown (MAJOR-1a) |
| 27e427e85 | 08f53f2eb | r1 fix-2 PaneGrid + ApprovalsDrawer + CommandPalette (MAJOR-1b, MINOR-1) |
- Law greps on the LANDED commits: %ae detain = 7, %ce detain = 7 (SEPARATE greps), Co-Authored-By = 0.

## en.php merged-tree union proof (python, this session)
- `'web.` key lines: 541, ONE contiguous span :4212–:4752, interlopers 0, between the 6.f markers `// --- web.chrome (C-TUI-6.f…` (:4203) and `// --- end web.chrome ---` (:4753); byte-order SORT_STRING ascending = True.
- Whole-file python dupscan over the key column: total keys 3,964, dups {} (0) — 3,956 lane-era total + 8 master-side keys (4.d label/help/hook/runner, 5.c serve.push ×4). Green-first: hunk-disjoint union, no sorted-run red-before state ever arose (disclosed honestly — nothing fought).
- Anchored `(CliLangCatalogueTest::|LangParityTest::)` → OK Tests: 15, Assertions: 4,203 (r2 lane figure 4,195A + 8 = the master-side keys, honest merged growth; NON-ZERO).

## Zero-test-touch proofs (blob identity — stronger than name-diff)
- `git diff 828aab3eb..HEAD --name-only | grep -c 'tests/'` = 0.
- TreeWideGuardRosterTest.php merged md5 ab5d364ecd6f3961a483374f8dd93003 == pre-weld master blob → lane adds ZERO rows; sibling rows alive BY TEXT at current positions: :587 AgentLiveRegistry · :645 LoginSubcommand · :678 SubcommandsMcpServe · :624 Script (6.c-era) · :742 ContextSources · :745 Protocol/WebStringsTest · :747 WebPushSenderTest (5.c chr(42)-row) · :780 SuiteSkipRoster · :811 BashSandbox (brief's :778/:809 shifted +2 by intervening welds — positions drift, green bare run is the mechanical proof).
- ReadPathCensusTest.php md5 33f82e343a8faf65150ac76e1272b25b and DescriptorInheritanceGuardTest.php md5 05357389011ea89ee1c20e50e71626d3 — both byte-identical vs pre-weld master (6.c DIG/RPC/SSR rows survive by construction).

## Universal era checks (merged tip, cwd=candy-code plain pipe, anchored)
- `LayeredSettingsTest::` → 99T/411A 0F (pin==derived incl 4.d sandboxScope — cure 00fedf519 in force, folded nothing).
- PAIRING `BashSandboxTest::|BubblewrapHardeningTest::` ONE-process co-run → 39T/190A GREEN (cure db1441a61 in force — the universal weld proof holds).
- BARE `TreeWideGuardRosterTest::` → 17T/3,914A (lane-era r1/r2 3,901A → +13A merged growth from the 6.c/4.d/5.c welds — DISCLOSED, not chased). `DuplicatedTestHelperDriftTest::` → 14T/204A byte-match.
- `(WebStringsTest::|WebStringsDriftTest::)` → 9T/576A; split WebStringsTest:: 7T/571A + WebStringsDriftTest:: 2T/5A — EXACT r2 figures on the merged tree.

## THE DIST CARRY
- Protocol trio (schema.json / SERVER.md / src-web/protocol/generated.ts): ZERO occurrences in the landed range (`git diff 828aab3eb..HEAD --name-only | grep -cE 'schema.json|SERVER.md|protocol/generated.ts'` = 0) — NOTHING regenerated, consistent with r1+r2 per-pick name-list proofs. No contradiction with the review → no STOP.
- Chrome mirrors changed ⇒ dist REBUILT by this weld: `npm run build` on the PRE-SEEDED node_modules (never npm ci), built in 931ms. Outcome: `dist/assets/index-BEwIkY_l.css` (69,490 B) → `index-C0-nwVJU.css` (69,490 B), `dist/assets/index-C6T8duAb.js` (528,191 B) → `index-Dwd-HFYq.js` (562,419 B, +34,228 B — the migrated chrome strings enter the bundle), `index.html` + `manifest.json` re-pointed (6 porcelain paths total). Dist files committed IN THIS WELD (sanctioned weld-only exception; precedent 6.f weld bdac0b58d).
- Post-build checks at merged tip: `php candy-code/scripts/gen-web-strings.php --check` rc=0; `node candy-code-web/scripts/gen-web-strings.mjs --check` rc=0 (mirror↔json equal — no regen leg needed).
- Vitest serial spot-checks (--no-file-parallelism), all GREEN: panels+grid+approvals+lib/__tests__ 31f/261t; components/__tests__ 29f/286t; i18nCoverage + locale set (src-web/__tests__ + stores/__tests__) 23f/156t.
- Generators `gen-command-docs`/`gen-tool-docs`/`gen-settings-doc`/`gen-protocol-schema` `--check`: ALL rc0 — zero drift, zero regen legs (the chrome keys feed none of those doc regions — measured).

## §7 HAND-OFFS (LISTED, never fixed here)
- durations.tsv: ZERO rows owed — zero new candy-code *Test.php in base..tip (r2-verified; re-derived: name-diff has no tests/ path at all); web vitest specs are NOT durations.tsv rows.
- suite-figure.json/README pair: battery carry — the +chrome-keys class adds NO new tests (0T); staleness pair stays battery-owned (files never touched here).
- web.yml gen:strings CI twin-step: LEAD rider — NOT edited.
- SERVER.md: untouched. PathGlob live re-derive: battery. COMPARISON web-i18n rows for the migrated families: lead decides cells.
- WARN NEXT WELDER: lane bases may predate this weld + the 4.d/5.c/6.c/6.j era — en.php total is 3,964, TWGR row positions shifted +2 since the 5.c-era cites, every counter re-derives on the MERGED blob.

## Absorption proof + prune
- `git cherry master cc/C-TUI-6.g`: result recorded below (run post-commits).
- Worktree ../cc-cmp-C-TUI-6.g + branch cc/C-TUI-6.g removed after proof. Survivors: cc/C-TOOL-6.d, cc/C-TOOL-6.g (+ foreign refs untouched).

## Ledger erratum (self-disclosed)
- An earlier draft of the §4 mark cited dist sizes from arithmetic (13,152→15,637 css / 544,603→557,247 js) that were WRONG; corrected IN-PLACE before the commit to the MEASURED values (69,490→69,490 css / 528,191→562,419 js). The committed mark carries only measured bytes.
