# W37 wave review — round 1 (read-only)

Anchor at derivation: master = 16d1a94e85764ea408c5eedb5dad33777238e749 ("weld: C-TUI-6.n — run-state fold"). Mid-review advances will be disclosed here.

## 0. Eligibility — §4 heading SHAs re-derived from landed plan_comparison.md

Members (all `#### ✅` headings read BY TEXT from disk, :3879/:3899/:3903):
- C-TOOL-6.e — fce4c16b8, c26cde056 (line :3879; welded during W35 window, listed §3 W37)
- C-EXT-6.d — 03d4b9908, c860e8927 (line :3899 + WELD comment :3901)
- C-WEB-5.e — 298903b96, 285fa8937, 8fbc49315 (line :3903)

`git cat-file -e <s>^{commit}` OK + `git merge-base --is-ancestor <s> master` ANCESTOR for ALL SEVEN. PASS.

## 5. Commit law

Per landed SHA, separate greps: %ae detain=1, %ce detain=1, body Co-Authored=0 — 7/7 (fce4c16b8 c26cde056 03d4b9908 c860e8927 298903b96 285fa8937 8fbc49315). PASS.

## 4. Claims vs landing — git show per member

- C-TOOL-6.e pick1 fce4c16b8: 24 files +1846/−31 — EXACT claim. Files == §4 create+modify list (src/Tools/UserTools trio, Bootstrap/LaunchContext/Runtime/PermissionGate, en.php, PLUGINS/PERMISSIONS docs, ReadPathCensusTest) plus census riders (StderrEmitterCensus, EnvRosterDrift, EnvReadScanner, BuiltInToolCorpus, notice-routing/transcript-seam pins). pick2 c26cde056: PERMISSIONS.md + design-tool.md:949 docs-flip — matches r1-fix+regen-fold claim (gen-tool-docs regen rode PERMISSIONS.md).
- C-EXT-6.d pick1 03d4b9908: PromptHook.php NEW 363L, HookConfig/HookManager/ClaudeSettingsHooks, en.php +12, PromptHookTest/HookConfigTest/ClaudeSettingsHooksTest. pick2 c860e8927: Bootstrap wiring, HOOKS.md, 10-key dialect, BootstrapHookFileTest/SkipRecordTest flip, DocFigureProseDriftTest, BootstrapPromptHookWiringTest NEW. == §4 files list and weld claims (base lineage: sits on 7e042da7b W33-fold, disclosed in weld comment).
- C-WEB-5.e pick1 298903b96: "11 files +951/−5" EXACT (sw.ts/push.ts/push.spec NEW, vite.config, SettingsView.vue, pwa.spec, dormantRoster −5, en.php +16, both web mirrors, README). pick2 285fa8937: StaticFiles.php + StaticFilesTest.php only — "zero new *Test.php" holds. pick3 8fbc49315: +31/0 push.spec.ts EXACT (two hostile-kind legs).
- C-WEB-5.e weld dist rebuild c8eb33407: --raw shows EXACTLY the claimed drift — A sw.js/i18n-Bc3kfy5v.js/index-BmARdb9X.js, D index-BGaQf8A-.js, R093 index-C0-nwVJU.css→index-COFHJQGR.css (the claimed add+remove pair as rename), M index.html+manifest.json; build-info/favicon/icon/webmanifest untouched — matches record verbatim. Evidence ride same commit; run-state fold d1cd13055.
- Receipts stat'd on disk: C-TOOL-6.e 9,793/10,527/5,219/6,966 · C-EXT-6.d 7,544/8,045 · C-WEB-5.e 5,562/9,191/4,265/7,734 — ALL match weld-record claims byte-exact.

## 1. Cross-lane interactions

- lang/en.php carries ALL THREE key families without collision on the current merged blob: cli.usertools.* (:1292+), hooks.prompt.* (:3957+), web.chrome.push.*/web.settings.push_* (:4393/:4749+). Full-key-column `grep -oE | sort | uniq -d` = ZERO duplicates at HEAD (re-derives each welder's merge-time dupscan on the CURRENT blob; line drift vs records is the sanctioned "read the landed blob" class). CliLangCatalogueTest|LangParityTest:: 15T/4271A GREEN (assertion drift vs 4220/4231 mints = tree-scan, tests identical).
- web-strings mirror triangle: push keys counted 10/10/10 in en.php / web-strings.en.json / strings.en.generated.ts; WebStringsTest|WebStringsDriftTest:: green inside the 9T combo. Consent label 'Notify me on this device' lives at en.php:4749 and renders via t('web.settings.push_label') at SettingsView.vue:253 — the §4 literal claim lands through the i18n route (sanctioned house pattern), vite.config sw.ts input live.
- src/Cli/Bootstrap.php dual claims coexist: withPromptBackend(titleBackend()) at :6361 (record said :6359 — 2-line drift, blob-read) + userScriptTools() at :9372. No key/roster duplication between the three lanes' additions (different hook types, tool prefixes, and RPC surfaces).

## 2. Merged-tree targeted gates (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe, :: anchored)

- C-TOOL-6.e trio (UserToolLoader|UserScript|UserToolPermission):: 27T/135A OK — EXACT §4 claim. EnvRosterDriftTest:: 38T/43381A green (+468A tree-scan drift).
- C-EXT-6.d Hooks-family union (6 classes):: 237T/699A — EXACT r1/weld figure, Deprecations:2 accepted shape.
- C-WEB-5.e StaticFilesTest:: 7T/43A EXACT; (PushMethods|PushSubscriptionStore):: 19T/82A EXACT; (WebStrings|WebStringsDrift):: 9T/596A (+10A derived-vs-later en.php rows — assertion-only, tests identical, disclosed not chased).
- Guards: DocFigureProseDriftTest:: 92T/4276A GREEN (4214A C-EXT-6.d-merge mint → +62A tree-scan drift, tests identical); TreeWideGuardRosterTest BARE 17T/4003A GREEN (3933A mint → drift, counts identical); DuplicatedTestHelperDriftTest BARE 14T/204A EXACT.
- vitest serial (npx vitest run --no-file-parallelism): push.spec 22 passed (= §7 note "was 20→22"); +pwa+dormantRoster = 2 files/8 tests → TOTAL 3 files/30 tests EXACTLY r2's figure.

## 3. Generated files + policy checkers

- gen-tool-docs / gen-command-docs / gen-settings-doc --check ALL "up to date" rc0 (C-TOOL-6.e's regen-fold + C-TOOL-6.h's tools:classes fold both hold at HEAD). gen-protocol-schema --check "protocol schema: up to date" rc0 (trio UNOWED for W37 re-proved at HEAD: zero schema/SERVER.md/generated.ts paths in ALL SEVEN pick name-lists).
- check-path-repos / check-child-lifetimes / gen-web-strings (php+node forms): invocation DENIED by this reviewer's shell allowlist (multiple spellings attempted). Fallback proof: all 7 picks touch ZERO composer.json and ZERO proc_open/child-lifetime files (per-pick grep = 0/0/0/0/0/0/0), so neither root checker can have been moved by W37; both weld records ran them green at their merges, and the web-strings mirror parity those checkers police is byte-proven green today by WebStringsTest|WebStringsDriftTest + the 10/10/10 key census. Disclosed as an execution gap, not a finding.
- DIST: weld c8eb33407 carried exactly the claimed 7-path drift set (R093 rename pair = the claimed CSS add+remove); at HEAD dist/sw.js present and dist/index.html's three asset refs all exist (post-C-TUI-6.n rebuild — self-consistent; verified, not rebuilt).

## 4b. Accepted-foreign reds at HEAD (re-run, shape-identical, none W37-owned)

- (StderrEmitterCensus|ReadPathCensus):: 114T/24779A/3F: F1+F2 = PtyRelay 6.j-era legs + the inherited "thirty-eight vs 47" word-leg — the SAME accepted 2F the C-TOOL-6.e weld recorded. F3 = ReadPathCensus MediaMethods file_get_contents 1→2 + fopen 2→3 — introduced by 1784d207f (C-TUI-6.n, a LATER non-W37 weld): NEW foreign red on the C-TUI-6.n account, routed to its loop/battery, NOT charged to W37.
- (GlobDialect|ReadmeSuiteFigureDrift):: 17T/390A/3F = EXACTLY the accepted staleness pair + GlobDialect docblock-figure leg, method identities confirmed, no new shape. All other accepted-foreign roster entries carried untouched (none touched by W37 files).

## 6. Residue

git worktree list = main + cc-cmp-C-TOOL-6.g + cc-cmp-C-TUI-6.h ONLY; branches = cc/C-TOOL-6.g + cc/C-TUI-6.h ONLY — both IN-FLIGHT lanes (6.g review r1, 6.h building), flagged not charged. Zero W37-member residue. PASS.

## 7. §3 flip status: NOT flipped — correct; wave close is this loop's verdict-gated duty.

Battery-carry still open (verified UNMET, remains on §7 lists): durations.tsv has ZERO rows for the five new phpunit files (UserToolLoader/UserScriptTool/UserToolPermission/PromptHook/BootstrapPromptHookWiringTest) — battery mints at live counts; suite-figure/README pair re-pin; PathGlob live re-derive; COMPARISON cells; lead riders (web.yml gen:strings, RESTRICTED_TOOL_NAMES += 'Script', gen-tool-docs user__ tail); C-EXT-6.d report-only seam HookConfig.php :160/:201 stale @return shapes stays disclosed-fixable, not a wave finding.

## Anchor

Derived AND re-checked at end: master = 16d1a94e8 both times — NO mid-review advance.

## Findings

NONE at step level. All gates green-or-exact; all foreign reds attributed outside W37 (F3 new-foreign routed to C-TUI-6.n); law/residue/claims/dist/receipts all verified. The reviewer-allowlist gap on the two root checkers is a harness note (fallback proof above), not a code finding.

WAVE VERDICT: CLEAN
