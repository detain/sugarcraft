# C-TOOL-6.i — WELD record (welder, 2026-10-31)

## Preflight (evidence re-stat by welder)
- REVIEW-1.md 10,659 B @20:58:08.559 — line: "VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR / 2 NIT. WELD-ELIGIBLE: YES."
- REPORT.md 8,130 B · SERVER-MANUAL-SECTION.md 1,824 B · no WELD.md existed.
- Master pre-weld HEAD 0fe9fa6a2 (weld: C-TUI-6.d — run-state fold); lane base 77beb227d is an ancestor; 6.h substrate picks (97b2787d4…85bfba37f) inside it. db1441a61 ancestor. Lead dirt (`.opencode/*`, AGENT_LAW_SNIPPET.md, LEAD_LAWS.md, foreign junk file) present EXACTLY as briefed; NOTHING staged; no reset needed.
- Own heading `#### C-TOOL-6.i —` present UNMARKED at plan :3967 (marked-count probe via startswith, 318 BEFORE).

## Picks (explicit list read from `git -C /home/sites/cc-cmp-C-TOOL-6.i log --format='%h %s' 77beb227d..HEAD`, cherry-picked IN ORDER, no -x, no range)
1. f4587a47e → **22391a02b** (protocol surface+gate+relay, 13 files +305/−3)
2. 6f9142db1 → **3871d0b00** (tests/Protocol/ClientToolMethodsTest.php, +334)
3. 8dfac2340 → **b1fce23aa** (web store+sample+spec+wiring, 5 files +400)
ZERO conflicts. Reflog above 0fe9fa6a2: exactly 3 cherry-pick records, zero foreign. Landed law: %ae=3 %ce=3 detain@interserver.net (SEPARATE COUNT greps), Co-Authored-By 0. `git cherry master cc/C-TOOL-6.i` = three `-`, zero `+` (patch-id identity — content-proof moot). Merged 0fe9fa6a2..HEAD = 19 paths (per-pick file sets union; trio/schema/SERVER.md/generated.ts/dist ABSENT from all three picks — name-only re-proof 0/0/0).

## Mirror-claim erratum (record, no action)
REVIEW-1 Q2 stated "sanctioned mirror pair web-strings.en.json + src-web/lib/strings.en.generated.ts rode 8dfac2340" — FALSE-TO-BLOB and contradicts its own Q0 5-file count. 8dfac2340 landed exactly {web README +6, browserContext.ts 35, clientTools.spec.ts 196, clientTools.ts 158, session.ts +5}. web-strings.en.json does not exist anywhere in the tree; strings.en.generated.ts untouched. (WebStringsTest|WebStringsDriftTest):: GREEN 9T/586A on merged tree ⇒ mirror UNOWED for the two server-setting en.php keys (server-side labels; no web consumer). Welded as briefed via landed blobs.

## Collision unions (merged tree)
- en.php: two sorted keys (settings.server.clientTools.label/.help) appended cleanly; PYTHON dupscan over 3994 keys = 0 dup-keys.
- TreeWideGuardRosterTest: 0-line diff from master; ALL foreign rows survive BY TEXT (WebPush :747, 6.j :768, 222 `=>` rows incl ServerJail/Egress/6.e/6.a/6.d/6.h-family); BARE run OK 17T/3967A (+8A merged 6.d-era drift vs reviewer 3959/3957 — DISCLOSED, rows not numbers).
- DuplicatedTestHelperDriftTest BARE OK 14T/204A EXACT.
- LayeredSettingsTest:: OK 99T/413A 0F — fold NOTHING (cure 00fedf519 in force; lane measured N/A).
- ci.yml: 0-line diff (6.d owns its smoke job — name-diff proved).
- Bash.php :535 double-quote `"bash -c "` form still INTACT (6.d union spot).
- PAIRING (BashSandboxTest|BubblewrapHardeningTest):: one process OK 39T/192A GREEN (db1441a61 carry alive on this tip — this is the figure the 6.g weld must keep green).

## Trio+dist regen carry (r1-ruled; welder-owned)
- `php scripts/gen-protocol-schema.php --write` (cwd candy-code): docs/protocol/candy-code.v1.schema.json +167 lines; docs/SERVER.md +3 generated table rows (tools.client.register/tools.client.result method rows + tool.client.call event row).
- MANUAL PARAGRAPH: 10-line "Client-hosted tools." para inserted VERBATIM (asserted quote-stripped == SERVER-MANUAL-SECTION.md `> ` bodies) after `<!-- protocol:events:end -->` (unique marker), before `### Sending, queueing, steering, cancelling`; SERVER.md total +14 with the gen rows.
- C-TUI-6.d `## Windows` section BYTE-INTACT: tail md5 fe7233d4762c7875f63a37879e9d0d15 identical before regen, after regen (:1570), and after manual insert (now :1581 — position shift only, pure append semantics, never overwritten).
- Web: `npm run gen:protocol` → src-web/protocol/generated.ts +53 lines (METHOD_SCOPES entries :1901-1902 + schema blocks). `npm run build` (vite 847ms, pre-seeded node_modules — NEVER npm ci/composer/refresh-deps): dist/index.html + dist/manifest.json modified, asset churn index-BmARdb9X.js → index-Lga6Dnd-.js (delete+add both staged).
- Drift guards GREEN-AT-REGEN: ProtocolSchemaDriftTest 7T/303A (1F in-lane pre-regen was by-design schema staleness — leg now passes); ServerProtocolDocumentationDriftTest 6T/59A re-run AFTER manual paragraph — combined (ServerProtoDoc|ProtocolSchemaDrift|DocFigure) co-run 105T/4616A OK. Other generators --check rc0 ×3 (command/tool/settings "up to date").

## Merged-tip gates (anchored stems NON-ZERO, cwd candy-code, plain pipe)
ClientToolMethodsTest:: 9T/68A · ServeArgsTest:: 46T/234A · (TrustKeyDocumentationDriftTest|SettingsSchemaDocDriftTest|ReadmeSettingsTierClaimTest):: 37T/834A · (CliLangCatalogueTest|LangParityTest):: 15T/4233A · DocFigureProseDriftTest:: 92T/4254A (+1A merged drift vs 4253-era — DISCLOSED; green final-prose in the 105T co-run) · vitest serial (dormantRoster+generated+clientTools) 3 files 11/11 PASS.
- VITEST CACHE ARTIFACT (disclose): first post-regen dormantRoster run showed 1F (derivedDormant gained tools.client.register+result) though probes proved callsMethod HIT=true, SCOPES has both, clientTools.ts raw TEXTLEN 7360 in MODULES(107); a FRESH COPY of the identical spec passed 3/3 ⇒ stale node_modules/.vite + .vitest-cache transforms from the pre-regen lead session. `rm -rf node_modules/.vite node_modules/.vitest-cache` (node_modules junk only; zero tree files touched) → real spec 3/3, trio 11/11.

## Security posture re-proof (merged blobs)
Gate+relay traced door→register→relay→settle→cancel: ClientToolMethods.php requireDoor :73 (register) / :131 (result) → client_tools_disabled :165; SessionHost::registerClientTools :105 / resolveClientCall :138 (stale registrationId refuse); ServerContext feedFor onClientCall :385 announces TOOL_CLIENT_CALL :387 with deadlineAt :392 (6.h bounded-deadline — E646 clean, no new timer); dropFeed :715 unbinds; removeClient :296 forgetClientTools cancel-at-once. NO ungated exec path, NO stale survivor. Permission twin UNTOUCHED by all three picks; ordering source-slice re-proved at tip: isWriteTool user__(:1816)→client__(:1826)→verbatim mcp__ final verdict(:1836); evaluateAuto client__ Ask(:1106) BEFORE stepTexts(:1113)/classify(:1117). MethodSpec name-grammar widening ACCEPTED additive (r1: 77-name census zero newly-admitted; design-mandated for tools.client.*). Raw-name `request` cast = narrowed alias by design; dormantRoster sees the live browser caller (HIT=true probe).

## Loop arc
First-pass clean close: builder delivered after ONE honest fragment-resume (first wake mid-fragment with real work dirty, resume delivered 3 picks disk-perfect). NO phantom in this lineage.

## §7 HAND-OFFS — LISTED, NEVER FIXED here
1. durations.tsv row candy-code/tests/Protocol/ClientToolMethodsTest.php — welder live count 9T/68A (battery mints).
2. vitest clientTools.spec 5-test note for the battery ledger.
3. suite-figure/README pair carry: this weld adds +9T-class atop 6.h's disclosed +60T-class (pinned 25,537/609,443 vs live ~29.6k+; cumulative ~+69T-class since pin); suite-figure.json + durations.tsv NEVER touched.
4. RESTRICTED_TOOL_NAMES += 'Script' — LEAD ruling rider, still pending.
5. web.yml gen:strings step — LEAD rider.
6. ACCEPTED-FOREIGN reds reported-only cure-NONE (full roster per brief §5: keepWarm, KeyHelp, HelpTest tier-env, BinCandyCodeDispatch :1893, AttachPagingTest 2F, OneSided :435, GlobDialect assert-NEITHER, StderrEmitterCensus PtyRelay debt 75174ea40, LaunchContextDelegates df106a84c, ForeignAgentPresetWiring PermissionMode, SettingsEditor :189, ChildStderrCapture 2F, AccessorDeprecation :168/:202, BootstrapLaunchFormat doc-leg, Cli/HelpTest env-leg) + schedule.json:16367 accept-as-history. SeamCallSiteCensusTest/TurnRunnerTest do not exist (banked law).

## WARN NEXT WELDER
- **C-TOOL-6.g (W35 completer) OWNS its trio regen** — schema +2/−1 MEDIA_REF html + generated.ts +1/−1 (:227) + SERVER.md TWO MANUAL canvas rows VERBATIM (regen never derives hand prose) + dist rebuild + ProtocolSchemaDriftTest GREEN-AT-REGEN on its merged tree; AND its base PREDATES db1441a61 ⇒ its WELD-MUST-GREEN co-run is the BashSandbox|Bubblewrap pairing (39T/192A) ON THE TIP THIS WELD LEAVES (verified green here).
- C-TUI-6.l (voice, in review): trio+dist regen UNOWED per its design — next welder re-prove at its close.

## Closeout
Marks python-count startswith('#### '+✅+' ') 318→319 AFTER edit (below). §3 untouched. NO push — W35 does NOT close on this weld (C-TOOL-6.g sole remaining member; lead pushes at wave close). Absorption: cherry three `-`; worktree /home/sites/cc-cmp-C-TOOL-6.i + branch cc/C-TOOL-6.i pruned after proof; `git worktree list` survivors EXACTLY cc/C-TOOL-6.g + cc/C-TOOL-6.k + cc/C-TUI-6.l (live lanes, never touched).
