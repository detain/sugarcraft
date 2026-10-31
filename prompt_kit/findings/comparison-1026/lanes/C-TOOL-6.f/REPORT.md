# C-TOOL-6.f — Canvas tool core (HTML artifact into the session MediaStore)

LANE: /home/sites/cc-cmp-C-TOOL-6.f · branch cc/C-TOOL-6.f · base e62fd7f49
TIP: bc04c994f6888ae6c89af1acfb03ab4ce1874ec6 (1 pick, cat-file'd same shell; porcelain 0; %ae=1 %ce=1 detain, 0 Co-Authored trailers)

## STATUS: COMPLETE — single coherent commit (core seam + guard cascade unit)

## Landed (14 files, +530/-27)
- CREATE candy-code/src/Tools/BuiltIn/CanvasTool.php — #[BuiltInTool(name:'Canvas', NoAsk, position:45, gloss per design)]; args html(required)+title(≤80, result-text only); refuses empty / non-UTF-8 / >MAX_BYTES 524288 (refuse-not-truncate, message names both numbers); implements Tool+BuildsFromCatalog(unconditional)+DelegatesToEngine(sessionId() read ONLY)+TakesToolCallId; session = engine?->sessionId() ?? 'main' (GenerateImage doctrine, NoAsk rationale in docblock: session media dir is harness state); result content EXACT `Canvas saved — <title>: artifact <id>, <path> (<n> bytes)`; NO imagePath/imageBytes legs → nothing projected; no sanitising at store door (web half = sandboxed iframe later).
- MODIFY candy-code/src/Support/MediaStore.php — public putDocument(string $bytes, string $ext): string after put(); reuses normalizeExtension/tokenValues([])/resolveTarget/publish verbatim (0600 temp-before-payload, .partial cleanup, collision numbering, containment recheck). No MediaKind::Html (6.g protocol half).
- CREATE tests/Tools/BuiltIn/CanvasToolTest.php — LIVE 7T/43A: catalog entry (pos 45, NoAsk, exact gloss, concern flags, ParallelSafe=false), bound-session (real EngineBackend::new(ScriptedProvider([]),'m')->withSessionId + withEngine/withMediaRoot; dir === reference forSession()->directoryPath(); 0600; exact content regex; pathFor round-trip; zero image legs), fallback '/main/' + default title 'canvas', empty+whitespace refusal with store untouched, invalid-UTF-8 refusal, cap boundary MAX_BYTES accepted/+1 refused, title 80 accepted/81 refused. Helper wipeCanvasTree named distinctly (DTH-safe).
- MODIFY tests/Support/MediaStoreTest.php — +3T (collision-increment/id round-trip/0600/.HTML-lowercase; junk-extension refusal loop; throwing-writer leaves neither final nor partial; reuses existing partialEntries helper). File LIVE 29T/109A.
- MODIFY tests/RuntimeTest.php — readOnlyBuiltInToolNames() += 'Canvas' (GenerateImage-doctrine sentence) AND += 'Advisor' (CURE, see below); divergence exact-list pin extended + prose. File 153T/595A (same T count as base — roster feeds hand assertions, no provider rows).
- DOCS: gen-tool-docs --write regenerated README/ARCHITECTURE/AGENTS_AUTHORING/PERMISSIONS/SETTINGS (35-launch census, 41-directory census, 'fifteen of them differ', disable-report sample '34 of the 35'); --check rc=0 after manual bumps too. MEDIA.md new '### Canvas artifacts' section (where HTML lands, 0600, sweep, 512 KiB cap, not-yet-shown-by-web-client, media.fetch image/octet-stream default arm harmless). PERMISSIONS.md hand roster `Canvas` token. README :214+:2903 unanchored thirty-one→thirty-five (manual, UNGUARDED by any test — verified by grep both before and after).
- WORD MAPS in-step: ReadmeRosterDriftTest $spelled +35=>'Thirty-five'; ReadmeSettingsTierClaimTest +35=>'thirty-five'; DocFigureProseDriftTest :5473-era map +$wordNumbers['forty-one']=41 (41-class directory, era-comment per the lag rule the arm itself documents).

## CURE (foreign base-red, lead should know)
RuntimeTest::testTheWriteToolRosterDoesNotDriftFromThePermissionGate was RED at pristine base e62fd7f49 (all lane changes stashed, reproduced): `Advisor` classification missing — C-PRV-3.d (8defce213) landed AdvisorTool without its readOnlyBuiltInToolNames row. AdvisorTool.php carries zero write primitives; cured in-step per the documented Ls precedent (roster + prose sentence, CURE-tagged). NOT in the accepted foreign-red list — this is a NEW base-red discovery, landed green on my branch.

## Gates (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)
- CanvasToolTest|MediaStoreTest: 36T/152A OK
- RuntimeTest: 153T/595A OK
- ToolDocDriftTest: 5T/17A OK · PermissionsIntroDriftTest: 5T/23A OK
- ReadmeRosterDriftTest: 10T/78A OK · ReadmeSettingsTierClaimTest: 6T/41A OK · DocFigureProseDriftTest: 92T/4219A OK
- StderrEmitterCensusTest: 95T/23225A OK (Canvas adds no emitters; putDocument none)
- TreeWideGuardRosterTest (bare): 17T/3888A GREEN · DuplicatedTestHelperDriftTest (bare): 14T/204A GREEN (both cured-at-base, confirmed)
- php tools/gen-tool-docs.php --check: rc=0 · php tools/check-one-type-per-file.php (repo root): 62 libs/3340 files/0 problems rc=0

## §7 figures for welder
- NEW test file: candy-code/tests/Tools/BuiltIn/CanvasToolTest.php — LIVE 7 tests / 43 assertions (needs durations row at gate; NOT minted here).
- Live suite delta from this step: +10T (Canvas 7 + MediaStore 3), +0T RuntimeTest. ReadmeSuiteFigureDrift staleness pair (pinned 25,537/609,443) remains gate-duty; expected live tests 25,537+10 = 25,547 modulo other in-flight lanes — re-derive on merged tree, do not take this number on faith.
- No protocol-trio/suite-figure/durations/dist files touched (verified: porcelain 0 at commit, no forbidden paths in the pick).

## Deviations & seams
1. Design's audit-era README line cites (:176/:2629 'thirty-one' at the plan's cut) moved to :214/:2903 on live master; bumped thirty-one→thirty-five by SYMBOL. Design said 'five more' carry conditionals etc unchanged.
2. Design's count 'thirty-two' (single-addition arithmetic from its audit-era base) is superseded by live census: unconditional-launch set grew to 35, directory to 41 — generator + word maps all extended from LIVE derivation, not the design's stale numbers (design itself directs regen via gen-tool-docs).
3. Advisor roster row added although the design named it as C-PRV-3.d's home (C-PRV-3.e): base-red cure, in-step guard law (RuntimeTest is MY declared modify file; the leg would have stayed red otherwise). No other lane's file touched.
4. No new env var / settings key / Lang::t keys (tool result content is model-facing prose, GenerateImage precedent — design declares none).
5. media.fetch answers Canvas ids with the kind:image/octet-stream default arm today — design lists this as accepted risk; no event advertises the id until 6.g.
6. Quiescence honored: no writes to ProcessContainment/LaunchContext/src/Server/Renderer/Tui/Bubblewrap/StdioMcpServerTest/protocol trio/suite-figure/durations/dist.
No residue shapes (git diff | grep 'if (false|&& false|| true' empty pre-commit). background=true spawn law honored.
