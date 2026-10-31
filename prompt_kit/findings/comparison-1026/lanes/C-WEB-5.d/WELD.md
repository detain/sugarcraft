# C-WEB-5.d — WELD record (W36 LATE window, sole master writer)

Master before: 52ae3e3eb (weld: C-TOOL-4.e — close record). Lane base 895b9d6fd.
Picks read EXPLICIT from the lane (`git -C /home/sites/cc-cmp-C-WEB-5.d log --format='%h %s' 895b9d6fd..HEAD`), cherry-picked IN ORDER, no range, no -x:
- 9a60cdaf4 → **d3811a3d9** store slice (4 files, +457/−0)
- 84a9674cd → **4b02c6984** RPC wiring slice (7 files, +657/−3) — en.php auto-merged UNION keep-both
- 4b7174c1d → **9af627be9** round-1 fix (3 files, +24/−3)
All three: `git merge-base --is-ancestor` OK. Law on the 895b9d6fd..master range (incl. the 4.e weld's 7 commits): `%ae` grep-COUNT detain = 10, `%ce` grep-COUNT detain = 10 (SEPARATE greps), Co-Authored-By count = 0. Lane law (3 picks): %ae 3 / %ce 3 / Co-Authored 0 (verified pre-pick in the lane).

Trio+dist ABSENCE re-derived from the picks' name-only lists: 0 hits of schema.json|SERVER.md|generated.ts|dist/|suite-figure|durations across all three → regen leg owned by THIS weld (below).

## Evidence (re-statted by THIS welder, main tree, then READ IN FULL)
- REPORT.md 8,284 B · REVIEW-1.md 10,572 B `VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 1 MINOR / 3 NIT` · FIX-1.md 4,640 B (MINOR cured: PushSubscriptionStore.php:192 `isset($row['name'])` gate, both-polarity pin, warning-absence EXEC red-before `8T/33A/1F/1W` → green-after `8T/34A`; genuinely-vacuous PushMethodsTest:239 DELETED with fixer EXEC discrimination — ServerContext:617 ' Edit' leak mutation, cp+md5 08006c43afda46b84bfea219cf03f88b, reddened :237 AND :297 byte-equalities, rc-asymmetry honest, no coverage lost, 49A→48A = exactly the deleted leg) · REVIEW-2.md 5,757 B @14:28:44 `VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR / 1 NIT (observation). WELD-ELIGIBLE: YES.`
- SERVER-MANUAL-SECTION.md 1,759 B — landed BY HAND into docs/SERVER.md VERBATIM (python containment: True) after the generated methods-table region, before `## More than one project root (workspace hosts)`. Regen never derives hand prose (5.c/6.f precedent + §7 rider). MANUAL rows added by hand — disclosed.

## Trio+dist carry (this weld, actuals measured)
- `php candy-code/scripts/gen-protocol-schema.php --write` → schema.json +121/−0 (expected ≈+121 — exact); SERVER.md generated rows +3.
- `node candy-code-web/scripts/gen-protocol.mjs` → generated.ts +49/−0 (expected ≈+49 — exact).
- `npm run build` (pre-seeded node_modules, NEVER npm ci) → dist rebuilt: index-*.js asset rename Dwd-HFYq (562,419 B) → BGaQf8A- (562,451 B, +32 B), index.html 1/1, manifest.json 1/1.
- Post-regen checks rc0: gen-protocol-schema --check · gen-web-strings.php --check · gen-web-strings.mjs --check.
- ProtocolSchemaDriftTest:39 (the by-design pre-regen red) **GREEN at final tree**: drift family anchored `(ProtocolSchemaDriftTest::|ServerProtocolDocumentationDriftTest::|WebStringsTest::|WebStringsDriftTest::)` → Tests: 22, Assertions: 938, OK.
- vitest serial `--no-file-parallelism` dormantRoster.spec.ts + generated.spec.ts → 2 files / 6 tests passed.

## Merged-tree gates (mine, cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe, anchored stems, NON-ZERO each)
- PushMethodsTest:: → **11T/48A OK** (== r2 rule 48A — fix pick applied). PushSubscriptionStoreTest:: → **8T/34A OK** (== r2 rule). Combined push family `Push` → **126T/659A OK** (== r2 era exact).
- Pairing `(BashSandboxTest::|BubblewrapHardeningTest::)` co-run → **39T/190A GREEN** (db1441a61 cure holds, universal proof).
- LayeredSettingsTest:: → **99T/411A 0F** (nothing of 00fedf519 folded — picks extend no settings key).
- BARE TreeWideGuardRosterTest → **17T/3926A OK** (+2A merged-tree recount wobble vs r1's 3924 disclosed; lane adds ZERO TWGR rows — diff empty; all master rows alive by TEXT: AgentLiveRegistry · LoginSubcommand · SubcommandsMcpServe · ContextSources · Protocol/WebStringsTest · SuiteSkipRoster · BashSandbox).
- BARE DuplicatedTestHelperDriftTest → **14T/204A OK EXACT**.
- `(CliLangCatalogueTest::|LangParityTest::)` → **15T/4208A OK** (full Test-suffixed stems; +3A vs r1-era 4205 = merged-tree en.php recount). en.py dupscan: python over key column, 3,374 keys, **0 dups**; the 2 new keys serve.push.notify_question/notify_completed land contiguous after the 5.c serve.push.* run at :3622-3623.
- ReadPathCensusTest: lane SELF_LOCATED row ('Server/Push/PushSubscriptionStore.php|file_get_contents') UNION against master rows — diff pure-addition (0 deletions) → every DIG/RPC/SSR/VapidKeys(:1001-by-text)/ServerJail-era row survives by construction.
- Server-adjacency `Tests\\Server\\` → **510T/6862A/2F** — the 2F EXACTLY the accepted AttachPaging pair (testAnAttachmentStitchesOlderPages… + testAnAttachmentWithoutPaging…), shape-moved from 5.c-era 502T/6825A (+8T/+37A = this lane's Store+Methods classes riding the namespace).
- (StderrEmitterCensusTest::|DescriptorInheritanceGuard) → **110T/28684A OK** — spawn/stderr-side NOT-OWED PROVEN by grep: zero proc_open/error_log/fwrite(STDERR) in all 4 touched src files.
- check-one-type-per-file rc0. Generators --check rc0 above.
- Exclusions honored: PtyRunToolTest::testExpectMiss, RegistryTest O_TRUNC leg, ForkedChildReaper — never in any filter. Accepted-foreign roster (keepWarm/KeyHelp/HelpTest[TIER]/BinCandyCodeDispatch:1893/OneSided:435/staleness pair/GlobDialect-assert-NEITHER/SettingsEditor:189/ChildStderrCapture/AccessorDeprecation/BootstrapLaunchFormat doc-leg/Cli-HelpTest env leg): cure-NONE, none introduced here. ReadmeSuiteFigureDrift staleness pair carried (pinned 25,537/609,443 vs live era) — battery re-pin owns; suite-figure.json + durations.tsv NEVER touched by this weld.

## Absorption proof
`git cherry master cc/C-WEB-5.d` → recorded below post-commit (expected `-`×3). Then worktree ../cc-cmp-C-WEB-5.d + branch cc/C-WEB-5.d removed; survivors exactly cc/C-TOOL-4.g + cc/C-TOOL-6.g + cc/C-TOOL-6.j.

## §7 HAND-OFFS (LISTED, NEVER FIXED here)
1. durations.tsv rows — battery mints at MY live merged-tip counts: candy-code/tests/Protocol/PushMethodsTest.php **11T/48A** · candy-code/tests/Server/Push/PushSubscriptionStoreTest.php **8T/34A** (two NEW test files; unlisted files run zero shards).
2. suite-figure.json + README pair: +19T-class carry (11+8 new methods) — battery K=24 conservation run is the authority.
3. COMPARISON push cells :3470/:3475 flip candidates at battery (Web Push wiring now in-tree).
4. StateDir PUSH_STORE_FILE hub-const deviation — SANCTIONED by the 5.c weld §7 note ("a hub the 5.d/5.e wiring lanes consume"); ledger word only.
5. Serve.php:966 rotate→`prunePrincipal('bearer')` one-liner — store-side shipped+pinned, wiring is LEAD adjudication (report-only, r1 §7-7).
6. web.yml gen:strings step — LEAD rider.
7. WARN NEXT WELDER (6.g): its carry = SERVER.md TWO MANUAL canvas rows + schema +2/−1; base drift — re-derive all rosters/censuses/figures on YOUR current blob (en.php now 3,374 keys with the notify pair at :3622-3623; TWGR/ReadPathCensus positions drift, match by CONTENT).
