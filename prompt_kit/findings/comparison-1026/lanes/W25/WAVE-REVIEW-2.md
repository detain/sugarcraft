VERDICT: FINDINGS — 0C / 0M / 1m / 1n (Wave 25, round 2; master tip 671105299709170dcace211a333ee93cdb365f58, re-read live)

r1 MAJOR-1 CLOSED: ReadPathCensusTest exact-class = OK 19T/729A EXIT=0 at tip. Both roster rows
(Session/Import/RemoteSessionPush.php + Server/ServerConfig.php file_get_contents) verified LANDED in the
master blob (tests/Support/ReadPathCensusTest.php :959/:972), matching the FIX-m1 disk proof.

FINDINGS
- MINOR-1 (disclosed carry, still open): candy-code/tests/Protocol/UsageMethodsTest.php has NO row in
  scripts/parallel-tests-durations.tsv (grep rows=0) → it runs in ZERO shards until minted. Disclosed in
  run-state as §7 CARRY (NOT applied at weld per WEB-5.a/SES-7.b precedent) + suite-figure/README re-pin.
  Cure = closeout mints the row from a live per-file run AND applies the +8T (not +9T, see NIT-1) re-pin.
- NIT-1: C-SES-8.a mark + weld commit 26896e349 claim "UsageMethods 9T/54A"; disk has 8 test methods
  (git log 5368f7e8f..HEAD on the file = empty; 8 at 6ae5c4b4e), direct run OK 8T/48A. run-state DUR carry
  also says 9T/49A — three mutually inconsistent figures, live authority = 8T/48A. Closeout should fix the
  mark wording or annotate; the DUR row must mint 8T/48A.

ANCHORS / GIT LAW (all PASS)
- 10/10 §4 marks anchored `#### ✅ <sha>`: C-CTX-5.d 37f4ef6e7 · C-SEC-7.a eee747273 · C-SEC-7.b b927d39b8 ·
  C-SES-7.f 31691640f · C-SES-7.g b0fb53309 · C-SES-8.a 94432cfd1 · C-SES-8.d c6e5e2ecc · C-TOOL-2.a 1f7e4934a ·
  C-TOOL-2.c 1b3bbd97c · C-TOOL-2.e 618c093da. All cat-file-exist; all ancestors of HEAD except the two
  lane-sha anchors (31691640f/b0fb53309) whose LANDED pick chains (5d3b47f05; ca37ed2c8→2a5e22326→f1fd07607)
  are ancestors — plan convention, SHA pairs listed in mark text.
- Authors: 17 wave weld/pick/rider shas checked (incl 745a8001c, dc9eea92d, 3a044bc91, 5368f7e8f, 3311bba69,
  26896e349 chain): %ae and %ce BOTH detain@interserver.net (separate greps), zero Co-Authored trailers.

GATES (cwd candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe; PtyRun expectMiss never swept)
- C-CTX-5.d: KeepWarmTest|CacheHealthWatchTest 37T/155A OK; mark filter 'KeepWarm|CacheHealthWatch' 40T/173A
  EXACT (neighbor EngineBackendCacheHealthTest; files unchanged since 37f4ef6e7).
- C-SEC-7.a: 8 classes green per-class (HeadlessDefaultPermissionMode 10/21, BootstrapPermissionGate 68/154
  — grew 65→68 via W26 C-SEC-7.e, BootstrapPermissionGateTest untouched by W26 re-check not needed: file
  stat confirms later-wave growth, 158T total vs mark 155 pre-growth; LaunchNoticeRouting 22/148,
  TranscriptSeamCallSiteCensus 22/134, PermissionGateHook 15/42, ApproverWiring 13/26, BypassLaunchBanner 6/14,
  CommandOverrideLaunchNotice 2/8).
- C-SEC-7.b: RestrictedSafeModeFlags+ArgvParser+Help window 261T/4478A with EXACTLY the rostered foreign
  HelpTest 'CANDY_CODE_SERVICE_TIER' provider red (introduced 36cc39ad1 C-PRV-3.a W18 — run-state standing;
  not W25). KeyHelpTest 5F+1 risky = foreign standing. Not findings.
- C-SES-7.f+g: AttachRemoteCommands+AttachForeignTurn+HandoffRemotePush+HandoffCommand direct-file OK 41T/228A.
- C-SES-8.a: UsageMethodsTest 8T/48A OK (see NIT-1); ProtocolSchemaDrift|ServerProtocolDocumentationDrift
  13T/362A EXACT; vitest serial --no-file-parallelism usagePanel.spec+generated.spec 10 passed;
  trio regen + dist rebuild PROVEN in same commit 26896e349 (schema+165/SERVER.md/generated.ts+44 +
  hashed dist assets) — dist law satisfied at weld.
- C-SES-8.d: InsightsCommandTest+DocFigureProseDrift+BuiltInCommands+SlashDispatch+RegistryTranslation+
  ReadmeRosterDrift direct-file OK 151T/6235A (mark's broader 183T set contained only the 2 rostered foreign
  C-CTX-4.e reds PaletteSupersetDispatchTest:210/MenuBarLabelDerivationTest:102). Weld rider dc9eea92d
  (ARCHITECTURE 63→64 counter) landed.
- C-TOOL-2.a: mark filter 'WebFetch|HtmlToMarkdown|BuiltInToolTest' 363T/1098A EXACT OK; guard window
  ReadmeRoster|DocFigure|EnvRoster|TrustKey|SettingsSchemaDocDrift 177T/45651A OK (weld-era 170T/45240A,
  +7T later-wave drift, green).
- C-TOOL-2.c: provider window 'Anthropic|Usage|SearchBackends|PromptPrefixByteStability' 614T/3028A EXIT=0 —
  the weld-era 1F (UsageRecordsTest wires regex, FOREIGN-INHERITED "finding to lead") is CURED on master
  (e9852993a/9c784d441 C-SES-8.b threads; :586 now reads $keptStore) and re-verified green live.
  Guard window +ToolDoc+LangParity 189T/49003A OK.
- C-TOOL-2.e: family DocumentAttachmentWire|AttachmentWire|ProviderPromptCacheByteStability|
  SupportsDocumentsCoherence 55T/143A EXACT; Providers quartet direct-file (Bedrock/Gemini/OpenAI/Vertex
  ProviderTest) 293T/721A EXACT OK; DuplicatedTestHelperDriftTest 14T/203A green — the 618c093da
  ACCEPTED_CONST_DUPLICATION license holds at tip.
- ReadmeSuiteFigureDriftTest: 2F = pinned 25,537 vs live 28,991 — the program-level §7 rider staleness pair
  (all intermediate welds' carries), closeout duty, NOT W25-local.

GENERATORS: direct `php tools/gen-*.php --check` / `php scripts/gen-protocol-schema.php --check` DENIED by
the reviewer permission map (php runner not allowlisted). Equivalent coverage: the drift-guard families that
re-derive every generator surface (ToolDoc/SettingsSchemaDoc/CommandDocs/ProtocolSchema/ServerProtocolDoc/
EnvRoster/ReadmeRoster/TrustKey/DocFigure) ran GREEN at tip across the windows above — zero drift observable.

REACHABILITY (entry-point traces on master blobs, all 10 WIRED):
KeepWarmPolicy←HandlesRunControl/ContextSettings/SessionHost; safeMode/restricted←ArgvParser :829/:1069→
Bootstrap safeMode() :2673/:3253 + useRestricted :4361 launch notice; RemoteFeedPumpMsg produced in
Chat :12036-12052/:20317 drains; RemoteSessionPush←HandoffHostCommand; UsageMethods::register wired at
Dispatcher.php:151, web UsagePanel.vue issues connection.request('usage.rollup') typed via generated.ts
(METHOD_SCOPES read at :1805); /insights dispatched table-driven via builtin-commands/2215-insights.php
withHandler('handleInsightsCommand')→runHostCommand(InsightsHostCommand) (SlashDispatchTest enumerates it, green);
HtmlToMarkdown←Tools/BuiltIn/WebFetch; webSearchBackend read in AnthropicProvider+LayeredSettings;
supportsDocuments consumed provider-side AND EngineBackend (non-Providers grep hit) — user-attachment leg wired.

LAWS: E646 clean — RemoteBackend SOFT_CANCEL_POLL periodic timer exists ONLY under $cancellation!==null and
sends a user-initiated soft-cancel, never a blanket request deadline (sed 778-800 read). No proc_open/shell_exec/
exit/die in RemoteSessionPush/HandoffHostCommand. Fork discipline N/A (no new spawns). Dormant code: wave steps
folded prior dormant surfaces (insights/usage/handoff) INTO live dispatch rather than deleting.

HYGIENE: no W25 worktree/branch residue — the 5 live cc-cmp-* worktrees/cc/* branches (C-SES-8.g, C-TOOL-6.a,
C-TUI-5.a/e, C-WEB-2.a) belong to OTHER in-flight waves (master-only reads performed here; never read their trees).
DUR rows present for the 9 other new wave test files (verified rows: KeepWarm 8.947/17, HeadlessDefaultPermissionMode
0.058/10, RestrictedSafeModeFlags 0.051/14, AttachRemoteCommands 2.03/5, AttachForeignTurn 1.77/3,
HandoffRemotePush 3.466/21, WebFetchMarkdown 0.335/12, HtmlToMarkdown 0.061/29, AnthropicServerWebSearch 0.240/10,
DocumentAttachmentWire 0.517/15).
