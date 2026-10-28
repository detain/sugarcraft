# W17 WAVE-REVIEW-1 — reviewed at 86f95e191 (brief tip); re-verified battery at a03990a0f (C-TUI-2.d fixes rode mid-session; 86f95e191 confirmed ancestor; lang/en.php cli block byte-identical across the two)

VERDICT: FINDINGS — 0 CRITICAL / 1 MAJOR / 0 MINOR / 3 NIT

## 1. Pick verification (10/10 PASS)

All ten §4 marks cat-file resolve, are ancestors of master, and carry `ae=ce=detain@interserver.net` (separate greps) with zero Co-Authored trailers — 29 commits checked (17 code + 10 welds + 240ba85c1 DuplicatedTestHelper cure + e14c3cc7c §4 restamp):

| step | weld | §4 cite |
|---|---|---|
| P-AGENT.b | f77460186 (picks f1ec9a0d7+9fc915cd9) | 9fc915cd9 ✓ |
| C-CTX-5.c | dea3d0b12 (pick 878047472) | 878047472 ✓ |
| C-PRV-2.c | ba3c51aab (8848bbcc9+ac5eb1749) | ac5eb1749 ✓ |
| C-SES-4.c | d5a97541c (f5bd88ca3) | f5bd88ca3 ✓ |
| C-SES-6.b | e5dec3b71 (09525c072+8fce79ce0) | 8fce79ce0 ✓ |
| C-SES-6.c | ff8be1e5f (c7ee812b9+24d746501) | 24d746501 ✓ |
| C-SES-8.c | ebbb14984 (d59c71edc+ccf94cd63) | ccf94cd63 ✓ |
| C-SES-8.f | f3236300a (e209a8544+9a8a96f23) | 9a8a96f23 ✓ (restamped from f9f079205 by e14c3cc7c) |
| C-TOOL-2.d | eadec1a44 (d284451fa) | d284451fa ✓ |
| C-TOOL-3.e | da284586a (a1a997b4f+eed6630d3) | eed6630d3 ✓ |

## 2. MAJOR — P-AGENT.b durations row names a non-existent path; interrupt-send pins run in ZERO CI shards

`scripts/parallel-tests-durations.tsv:1030` reads `candy-code/tests/Agents/AgentInterruptSendTest.php	0.015	4`. That file does not exist — the real file is `candy-code/tests/Chat/AgentInterruptSendTest.php` (4 test methods, so duration/count are right; only the dir prefix is wrong). Row introduced by weld bookkeeping commit b270c3874 ("P-AGENT.b weld scripts: durations.tsv rows …").

parallel-tests.sh's own header (line ~42) documents the law: a tree file absent from the manifest runs silently in zero shards. Consequence: P-AGENT.b's 4 Ctrl+X interrupt-send pins never execute in the sharded CI/conservation gate, and no guard validates tsv paths, so the phantom row is uncaught. All 12 other W17 durations rows verified against disk (InstallScriptTest 4.959/13, ShellsMethodsTest 1.543/7, HomebrewFormulaTest 1.424/7, InsightsHostCommandTest 0.414/5, AgentWindowViewportTest 0.366/5, NonInteractiveOtelExportTest 0.339/2, ReadPdfTextTest 0.190/10, AzureOpenRouterTypesTest 0.108/21, ToolResultDocumentBlocksTest 0.092/13, CacheTtlIdleFoldTest 0.071/14, JsonlTranscriptWriterTest 0.054/9, InsightsCommandTest 0.007/10) — this is the only broken row.

**Cure:** edit line 1030's path to `candy-code/tests/Chat/AgentInterruptSendTest.php` (file is sorted by duration desc; a path-only edit preserves order). Owning lane: P-AGENT.b (welder bookkeeping). Candidate follow-up (not blocking): a fail-closed arm asserting every tsv path exists on disk — same "silent zero-shard" hazard class will recur otherwise.

## 3. Cross-lane file interactions — CLEAN

- lang/en.php touched by 4 lanes (P-AGENT.b, C-CTX-5.c, C-SES-8.c, C-SES-8.f): no duplicate keys; LangParity + ReadmeRosterDrift green (guard set §4). ChatHostLangCatalogue 1F is NOT W17 — the 5 unasked keys trace to 97113b210/23fa8ab8e/foreign C-TUI-7.a era; grepped all 17 W17 commits: zero touch any of the 5 keys (see §6).
- src/Chat/Handlers/HandlesRunControl.php dual-touched P-AGENT.b (@@ -201 draft/mailbox) vs C-CTX-5.c (@@ -300 cacheTtl IdleCompactTickMsg branch + handleCacheTtlIdleCompactTick): disjoint hunks, merged code coherent (IdleCompaction family green).
- Export-write trio no clash: InsightsHostCommand.php:94 AtomicFileWriter::write(0600) vs ExportCommand.php:191 AtomicFileWriter::write vs JsonlTranscriptWriter.php:152 fopen('ab') append — distinct windows, distinct formats.
- C-TOOL-2.d documentBytes (ToolResult → AnthropicMessagesWire.php:831/853) vs C-TOOL-3.e shells DTOs (Dispatcher.php:142 ShellsMethods::register): frame-encode neighborhoods do not overlap; ShellsMethodsTest + ToolResultDocumentBlocksTest both green.
- README.md union (C-PRV-2.c provider rows :214/:1677/:1686 + C-SES-6.c install/brew :143-159 + C-SES-8.f Sessions-row jsonl :639): all three landed, no dropped row. ENVIRONMENT.md union (C-PRV-2.c + C-SES-4.c): EnvRosterDrift green.

## 4. Merged-tree spot battery at tip — green (exact counts, cwd candy-code, plain pipe, -c phpunit.xml, env -u LINES -u COLUMNS)

Compaction family `CacheTtlIdleFold|IdleCompactionPolicyTest|IdleAutoCompactionTest|SessionHostIdleAutoTest|CompactionSettingsTest` OK 111T/556A · `Insights|Export` OK 123T/1800A · `AgentWindowViewport|AgentInterruptSend|AgentViewTest` OK 27T/307A · `Shells|Processes` OK 71T/334A · `ReadPdfText|ReadBinaryScan|ToolResultTest|ToolResultDocumentBlocks|PromptStability|AttachmentWire` OK 122T/730A (EXACT vs weld) · `Otel|OTel|Observability` OK 94T/418A · `PharCompat|BinCandyCodeAutoloadGuard` OK 52T/422A/1S (EXACT) · `InstallScript|HomebrewFormula|SelfUpdate` OK 62T/901A (EXACT) · `Azure|OpenRouter` OK 23T/59A · `JsonlTranscriptWriter|ShareSession|Exporter|ShellsMethods` OK 50T/242A.
Nine-family guard set `DocFigureProseDrift|GlobDialectDifferential|LangParity|ReadmeRosterDrift|KeyBindingDrift|CommandDocsDrift|TreeWideGuardRoster|StderrEmitterCensus|DuplicatedTestHelperDrift` OK 478T/34825A 0F — 240ba85c1's NOW-pair cure HOLDS (+1T vs W15's 477T is the wave's own new guard row). KeyHelpTest 63T/5F = standing roster, unchanged per W14/W15 WELD.md rosters. ReadmeSuiteFigureDrift 2F (pinned 25,537 vs live 27,451) = EXPECTED §7 closeout re-pin pair, excluded/noted. 2 PHPUnit deprecations per filter = standing AccessorDeprecation house noise.

## 5. Generated surfaces — rc0

gen-tool-docs --check ("tool docs: up to date"), gen-settings-doc --check, gen-command-docs --check, scripts/gen-protocol-schema --check ("protocol schema: up to date"). Protocol trio: docs/SERVER.md:489-491 shells.list/output/stop rows; generated.ts 8 shells.* refs; dist/assets/index-NcaNumyb.js contains shells.list (tracked dist swap rode weld da284586a). vitest --no-file-parallelism processesSection.spec.ts 8 passed. NIT-3: tools/gen-homebrew-formula.php ships WITHOUT a --check mode (usage() only) — its drift surface is guarded behaviorally by HomebrewFormulaTest instead; acceptable, recorded.

## 6. Foreign-standing reds (NOT W17; attributed)

(a) CliLangCatalogueTest sorted-run 1F — `cli.bundles.marketplace.none` misordered, introduced by 3bc062fc4 (C-EXT-4.b, not a W17 step). (b) ChatHostLangCatalogueTest unasked-keys 1F — chat.startup.personal (97113b210), chat.attach.pdf_pages (23fa8ab8e W11 cure), chat.effort.launch_unshaped, chat.permission.not_persisted/persisted; real ask sites live outside the guard's scanned set (src/Permissions/SavedRules.php, src/Support/StartupSummary.php, src/Cli/LaunchContext.php, src/Attachments/FileMentions.php). (c) OneSidedHomeSandboxTest 1F — extra row Context/InstructionWalkPastRootTest.php (foreign C-CTX-4.c; already routed by C-SES-8.c REVIEW-2). NIT-1: the wave-loop closeout should carry (a)+(b) to their owning lanes; (c) is known.
NIT-2 (ambient race, not a defect): the 5-family filter `ReadPathCensus|EnvRosterDrift|SettingsSchemaDocDrift|SymbolCitationDrift|DenialPrefixRoster` went 101T/3F ONCE (SettingsSchemaDocDriftTest.php:185 "+one hundred and thirty-five" anchor) while a foreign weld was mid-write to docs, then GREEN 3× consecutive (57347A/57439A×2); on-disk docs byte-consistent now (SETTINGS.md:325 == README.md:1247); SettingsSchemaDocDrift alone OK 19T/185A.

## 7. Production reachability — PASS

C-SES-4.c: NonInteractive.php:597 `$eventSink = Bootstrap::observabilitySink();` (Bootstrap.php:1190; dark → NullEventSink :1369). C-PRV-2.c: ProviderFactory.php:520 type list carries azure+openrouter (templates :498/:507, schema :563). C-TOOL-3.e: Dispatcher.php:142 registers ShellsMethods; web BackgroundPanel/ProcessesSection/shells.ts consume. C-TOOL-2.d: wire leg fed by Read.php documentBytes (:853; pdftotext :872 gated by Bootstrap::capabilityPresent :839). C-SES-6.b: SOURCE_DATE_EPOCH honored tools/build-phar.php:281. C-SES-8.c InsightsCommand/InsightsHostCommand intentionally unwired — dormancy-by-design, wiring rides C-SES-8.d per plan §4 Deps (E646-style: code+wired-later, not dead). C-AGT-4.b unblocked later per plan.

## 8. Hygiene + ledger

Zero W17 lane branches/worktrees survive (git worktree list / branch -a show only in-flight foreign lanes). Main tree dirt = lead's plan/prompt_kit files only. C-SES-6.c §4 promise fully landed (install.sh, packaging/homebrew/candy-code.rb.tmpl, both tests, README rows); landed superset adds tools/gen-homebrew-formula.php + TreeWideGuardRoster row — faithful to the step's "What" (per-release formula renderer); NIT attached to §4 "Files — create" omission of the generator, not a finding against the lane.
