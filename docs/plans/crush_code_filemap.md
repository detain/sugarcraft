# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-11 @ tip `61cde19c5` (rows re-synced at the round-66 close; supersedes the round-65
`0c61c0686` cut) — regenerate this file at every round-close.
Purpose: map every actionable backlog id (OPEN/PARTIAL/STALE-CITATION/UNCERTAIN = **100** per the
§0-NOW-68 §3 ledger arithmetic) to the files it touches so the supervisor can schedule file-disjoint
lanes. Census note: the row census below is the schedulable set — CLOSED rows are RETAINED with their
stamps as the closeout record, a full regen prunes them. This cut's table stamps out at 106 OPEN-family
rows, a +6 drift over the ledger that is NOT introduced here: the round-65 cut (`0c61c0686`) carried the
same delta (117 rows vs ledger 111), the divergence therefore predates round 66, and rounds 64–66 all
ruled the header arithmetic authoritative (the triage section tables are in-place annotated history, not
a clean census). 100 stands as the actionable figure; reconcile the surplus six rows by evidence at the
round-67 close. Tier/lane analysis lives in
`docs/plans/crush_code_concurrency.md` — NOT duplicated here; the `domain` column below is a
file-cluster bucket. Round-64 (`⚠α..⚠ε`), round-65 (`⚠ba..⚠be`) and round-66 (`⚠α..⚠ε` + the
executing `⚠ca..⚠ce/⚠ch` letters) markers are retired history on their CLOSED rows. Round-67 lanes
**da–df** (`crush_code_RESUME.md` §0-NOW-68 §2) own via fresh briefs:
**da** `tests/SwallowingCatchCensusTest.php` + `tests/Support/AssertionSwallowingCatchTest.php`;
**db** `tests/Cli/StderrEmitterCensusTest.php` (ONE file — eight ids; E154's Chat/Bootstrap/
NonInteractive site-halves route to de); **dc** `tests/Support/` child-process/scanner/guard files +
`tests/ChatTest.php` + `tests/Backend/EngineBackendTest.php` + `tests/bootstrap.php`;
**dd** `tests/Config/` + `tests/Support/DuplicatedTestHelperDriftTest.php`;
**de** `sugar-crush/src/Chat.php` (+ CompleteResponse/EngineBackend/PaletteState touch-lists) —
E45/E74/E78c (Bootstrap.php) WAIT behind de, wave-2 of round 67;
**df** sibling libs (`candy-core/`, `sugar-dash/`, `sugar-reel/`, `candy-pty/`) + `tools/` +
`.github/workflows/ci.yml` + README/worklog/RESUME meta. The domain index and collision clusters below
were re-derived at this close.

## Path normalization

Rows are derived from the ledger's evidence/note citations (`docs/plans/crush_code_backlog_triage.md`), normalized to repo-root paths:

- Bare `src/…`, `tests/…`, `docs/…` (lib docs), `bin/…`, `README.md`, `phpunit.xml` as cited → **`sugar-crush/`-prefixed**.
- Citations already written monorepo-root (`sugar-crush/…`, `docs/plans/…`, `tools/…`, `.github/…`, `scripts/…`, `crush_code.md`) → kept as-is.
- Sibling libs (`candy-…`, `sugar-dash`, `sugar-reel`) → kept as-is.
- Bare-directory citations kept with trailing `/` (e.g. `sugar-crush/tests/Cli/`).
- `size`: S = 1 path, M = 2–4 paths, L = 5+ paths or cross-domain.
- `files = UNKNOWN(re-derive)` when the row cites no resolvable path.
- `⚠xx` = id shares at least one file with round-67 lane **xx**'s ownership set (or is a member of it);
  retired letters (⚠α..⚠ε, ⚠ba..⚠be, ⚠ca..⚠ch) on CLOSED rows record the lane that shipped the fix.

## Table (one row per actionable id, ledger order)

| id | stamp | conf | files (⚠ = proposed-lane collision) | domain | size |
|---|---|---|---|---|---|
| E3 | OPEN | HIGH | sugar-crush/src/Chat.php;sugar-crush/src/Palette/PaletteState.php⚠de | chat-input | M |
| E4 | OPEN | HIGH | sugar-crush/src/Chat.php⚠de | chat-input | S |
| E5 | OPEN | HIGH | sugar-crush/src/Renderer.php | tui-render | S |
| E9 | STALE-CITATION | MED | sugar-crush/README.md;docs/plans/crush_code_worklog.md | docs | M |
| E10 | OPEN | HIGH | sugar-crush/src/Tools/BuiltIn/Doctor.php;sugar-crush/tests/Tools/BuiltInToolTest.php | tools-skills | M |
| E16 | OPEN | MED | sugar-crush/src/Runtime.php;sugar-crush/src/Hooks/HookManager.php | tools-skills | M |
| E17 | OPEN | HIGH | sugar-crush/src/Providers/CompleteResponse.php;sugar-crush/src/Context/ContextCompactor.php;sugar-crush/src/Chat.php⚠de | providers | M |
| E20 | OPEN | HIGH | sugar-crush/src/Chat.php;sugar-crush/src/Backend/EngineBackend.php⚠de | providers | M |
| E25 | PARTIAL | MED | sugar-crush/src/Context/MemoryBlock.php;sugar-crush/tests/Context/MemoryBlockTest.php | other | M |
| E36 | UNCERTAIN | LOW | sugar-crush/tests/Cli/BootstrapSkillSkipsTest.php⚠dc⚠df | tests-harness | S |
| E42 | CLOSED | HIGH | sugar-crush/src/Tools/McpToolBridge.php | lsp-mcp | S |
| E43 | PARTIAL | HIGH | sugar-crush/src/Renderer.php | tui-render | S |
| E45 | PARTIAL | MED | sugar-crush/src/Cli/Bootstrap.php | cli-config | S |
| E54 | PARTIAL | HIGH | sugar-crush/src/Tui/AgentViewPane.php;sugar-crush/src/Renderer.php;sugar-crush/src/Tui/Renderer.php;sugar-crush/src/Tui/Components/AgentDashboardPane.php | tui-render | M |
| E74 | PARTIAL | MED | sugar-crush/README.md;sugar-crush/src/Config/LayeredSettings.php;sugar-crush/src/Cli/Bootstrap.php;sugar-crush/docs/SETTINGS.md | cli-config | M |
| E78c | OPEN | MED | sugar-crush/src/Cli/NonInteractive.php;sugar-crush/src/Cli/Bootstrap.php | cli-config | M |
| E93 | OPEN | MED | sugar-crush/src/Skills/SkillRegistry.php;sugar-crush/src/Util/PathGlob.php⚠df | tools-skills | M |
| E107 | OPEN | LOW | UNKNOWN(re-derive)⚠df | other | S |
| E111 | PARTIAL | MED | sugar-crush/tests/Config/EnvRosterDriftTest.php;sugar-crush/docs/SETTINGS.md⚠dd | tests-harness | M |
| E125 | PARTIAL | HIGH | sugar-crush/tests/Config/ConfigWriteProducerDocumentationDriftTest.php;sugar-crush/tests/Config/ThemePersistenceFramingTest.php;sugar-crush/tests/Config/GlobFigureDriftTest.php;sugar-crush/tests/Chat/ChatConfigChangeDoorsDocumentationDriftTest.php⚠dd | tests-harness | M |
| E127 | OPEN | HIGH | sugar-crush/tests/Config/Support/EnvReadScanner.php⚠dd | tests-harness | S |
| E134 | PARTIAL | MED | docs/plans/crush_code_worklog.md | docs | S |
| E140 | OPEN | HIGH | sugar-crush/src/Support/ToolIpcFiles.php;sugar-crush/src/Runtime.php | tools-skills | M |
| E143 | OPEN | HIGH | sugar-crush/tests/Config/GlobFigureDriftTest.php⚠dd | tests-harness | S |
| E144 | OPEN | HIGH | sugar-crush/tests/Chat/ChatConfigChangeDoorsDocumentationDriftTest.php;sugar-crush/tests/Config/GlobFigureDriftTest.php;sugar-crush/tests/Config/ConfigWriteProducerDocumentationDriftTest.php;sugar-crush/tests/Config/ThemePersistenceFramingTest.php⚠dd | tests-harness | M |
| E148 | OPEN | HIGH | sugar-crush/tests/Config/EnvRosterDriftTest.php⚠dd | tests-harness | S |
| E149 | OPEN | HIGH | sugar-crush/tests/Config/EnvRosterDriftTest.php⚠dd | tests-harness | S |
| E154 | PARTIAL | HIGH | sugar-crush/tests/Cli/StderrEmitterCensusTest.php⚠db | tests-harness | S |
| E158 | PARTIAL | HIGH | sugar-crush/tests/Integration/BinSugarcrushAutoloadGuardTest.php | tests-harness | S |
| E165 | OPEN | MED | sugar-crush/tests/Config/ReadmeRosterDriftTest.php⚠dd⚠df | tests-harness | S |
| E172 | PARTIAL | HIGH | sugar-crush/src/Commands/CommandLoader.php | tools-skills | S |
| E174 | PARTIAL | HIGH | sugar-crush/tests/Support/FlattensSourceProseTrait.php;sugar-crush/tests/Support/DropsInsignificantTokensTrait.php;sugar-crush/tests/Cli/HeadlessPermissionPromptAttachmentTest.php;sugar-crush/tests/Support/ChildStderrCaptureScanner.php;sugar-crush/tests/Support/ChildLifetimeScanner.php⚠dc⚠dd | tests-harness | L |
| E175 | OPEN | HIGH | sugar-crush/src/Chat.php⚠de | chat-input | S |
| E176 | PARTIAL | MED | sugar-crush/tests/Support/ChildStderrCaptureTest.php;sugar-crush/src/Cli/NonInteractive.php⚠dc⚠db | cli-config | M |
| E194 | OPEN | HIGH | sugar-crush/phpunit.xml;sugar-crush/tests/ | tests-harness | M |
| E195 | OPEN | HIGH | sugar-crush/tests/Cli/StderrEmitterCensusTest.php⚠db | tests-harness | S |
| E199 | OPEN | HIGH | sugar-crush/src/Diagnostics/RuntimeNoticeSink.php | cli-config | S |
| E204 | OPEN | MED | docs/plans/crush_code_RESUME.md;docs/plans/crush_code_hardening_backlog.md | docs | M |
| E205 | OPEN | HIGH | sugar-crush/tests/Support/ChildStderrCaptureScanner.php;sugar-crush/tests/Support/ChildStderrCaptureTest.php⚠dc | tests-harness | M |
| E208 | OPEN | HIGH | sugar-crush/tests/Support/ForkedChildExitScanner.php;sugar-crush/tests/Support/TokenFunctionRanges.php | tests-harness | M |
| E214 | PARTIAL | HIGH | sugar-crush/tests/Cli/BootstrapLaunchFormatConstantsTest.php | tests-harness | S |
| E228 | PARTIAL | MED | sugar-crush/tests/Cli/StderrEmitterCensusTest.php⚠db | tests-harness | S |
| E235 | PARTIAL | HIGH | sugar-crush/tests/Support/ChildStderrCaptureTest.php⚠dc | tests-harness | S |
| E242 | OPEN | HIGH | sugar-crush/tests/bootstrap.php⚠dc | tests-harness | S |
| E246 | PARTIAL | MED | sugar-crush/src/Runtime.php;sugar-crush/tests/DenialPrefixRosterTest.php | tools-skills | M |
| E257 | PARTIAL | MED | sugar-crush/tests/Integration/BinSugarcrushWiringTest.php;sugar-crush/tests/Tools/BuiltInToolCorpusTest.php | tests-harness | M |
| E258 | OPEN | HIGH | sugar-crush/tests/Cli/StderrEmitterCensusTest.php;sugar-crush/src/Providers/ProviderFactory.php⚠db | providers | M |
| E259 | OPEN | HIGH | sugar-crush/src/Agents/WorktreeManager.php;sugar-crush/tests/Cli/StderrEmitterCensusTest.php⚠db | agents | M |
| E261 | CLOSED | HIGH | sugar-crush/src/Agents/AgentWorkerPool.php;sugar-crush/tests/Agents/AgentWorkerPoolTeardownForkTotalTest.php⚠cd | agents | M |
| E267 | PARTIAL | MED | sugar-crush/tests/Cli/StderrEmitterCensusTest.php⚠db | tests-harness | S |
| E269 | OPEN | HIGH | sugar-crush/tests/Cli/StderrEmitterCensusTest.php⚠db | tests-harness | S |
| E270 | PARTIAL | MED | sugar-crush/tests/Cli/StderrEmitterCensusTest.php⚠db | tests-harness | S |
| E272 | PARTIAL | MED | sugar-crush/tests/Support/DuplicatedTestHelperDriftTest.php⚠dd | tests-harness | S |
| E281 | OPEN | HIGH | sugar-crush/tests/Agents/TeamTest.php | tests-harness | S |
| E283 | PARTIAL | MED | sugar-crush/tests/Support/InterpolationOpenerTokenTest.php;sugar-crush/tests/Support/ChildStderrCaptureTest.php⚠dc | tests-harness | M |
| E291 | PARTIAL | HIGH | sugar-crush/src/Sessions/BackgroundSessionRunner.php;sugar-crush/tests/Support/ForkedChildExitConventionTest.php | sessions | M |
| E309 | OPEN | HIGH | sugar-crush/tests/DenialPrefixRosterTest.php | tools-skills | S |
| E319 | PARTIAL | HIGH | sugar-crush/tests/Support/ForkedChildTest.php;sugar-crush/tests/ChatTest.php;sugar-crush/tests/Backend/EngineBackendTest.php;sugar-crush/tests/Support/SuiteChildStdinIsolationTest.php;sugar-crush/tests/bootstrap.php⚠dc | tests-harness | L |
| E322 | PARTIAL | MED | sugar-crush/tests/TtyStreamArgumentCensusTest.php | tests-harness | S |
| E325 | OPEN | HIGH | sugar-crush/tests/Support/ReflectionLineSliceReaderCensusTest.php;sugar-crush/tests/Cli/HelpTest.php;sugar-crush/tests/VhsTapeContractTest.php | tests-harness | M |
| E331 | PARTIAL | HIGH | sugar-crush/tests/Support/DropsInsignificantTokensTrait.php;sugar-crush/tests/Config/ReadmeJsonErrorContractDriftTest.php⚠dc⚠dd | tests-harness | M |
| E342 | OPEN | HIGH | sugar-crush/tests/Support/ForkedChildTest.php;sugar-crush/tests/ChatTest.php;sugar-crush/tests/Backend/EngineBackendTest.php⚠dc | tests-harness | M |
| E343 | OPEN | MED | sugar-crush/tests/Support/ChildStderrCaptureTest.php;sugar-crush/tests/Support/SuiteChildStdinIsolationTest.php⚠dc | tests-harness | M |
| E347 | PARTIAL | MED | sugar-crush/tests/Cli/RefusalStderrSurfaceTest.php;sugar-crush/src/Permissions/DenialKind.php | tools-skills | M |
| E353 | OPEN | MED | sugar-crush/docs/HOOKS.md | docs | S |
| E356 | PARTIAL | MED | sugar-crush/tests/Support/DropsInsignificantTokensTrait.php;sugar-crush/tests/Config/ReadmeJsonErrorContractDriftTest.php⚠dc⚠dd | tests-harness | M |
| E357 | OPEN | HIGH | sugar-crush/tests/Config/DocumentParagraphsTest.php;sugar-crush/tests/Config/ConfigWriteProducerDocumentationDriftTest.php;sugar-crush/tests/Config/GlobFigureDriftTest.php;sugar-crush/tests/Support/DuplicatedTestHelperDriftTest.php⚠dd | tests-harness | M |
| E358 | PARTIAL | HIGH | sugar-crush/tests/Support/SuiteChildStdinIsolationTest.php;sugar-crush/tests/Support/ForkedChildTest.php;sugar-crush/tests/ChatTest.php;sugar-crush/tests/Backend/EngineBackendTest.php⚠dc | tests-harness | M |
| E366 | PARTIAL | MED | sugar-crush/tests/Support/DescriptorInheritanceGuardTest.php;sugar-dash/src/Plugin/ExternalModule.php⚠dc⚠df | sibling:sugar-dash | M |
| E369 | OPEN | HIGH | sugar-crush/tests/StdinConstantReaderCensusTest.php | tests-harness | S |
| E370 | OPEN | MED | candy-core/tests/Util/ClosedDescriptorZeroFamilyTest.php⚠df | sibling:candy-core | S |
| E375 | OPEN | MED | sugar-crush/src/Permissions/DenialKind.php | tools-skills | S |
| E376 | OPEN | HIGH | sugar-crush/src/Cli/HeadlessPermissionPrompt.php | cli-config | S |
| E378 | OPEN | HIGH | sugar-crush/tests/Support/ForkedChildReaperAdoptionTest.php | tests-harness | S |
| E386 | OPEN | HIGH | sugar-crush/tests/Config/DocumentParagraphsTest.php;sugar-crush/tests/Config/GlobFigureDriftTest.php;sugar-crush/tests/Config/ConfigWriteProducerDocumentationDriftTest.php;sugar-crush/tests/Support/DuplicatedTestHelperDriftTest.php⚠dd | tests-harness | M |
| E390 | PARTIAL | HIGH | sugar-crush/tests/Support/RequirementDirectiveProvenanceTest.php;sugar-crush/tests/Cli/BootstrapSkillSkipsTest.php⚠dc⚠df | tests-harness | M |
| E391 | OPEN | HIGH | sugar-crush/tests/Support/RequirementDirectiveProvenanceTest.php⚠dc | tests-harness | S |
| E417 | OPEN | HIGH | sugar-crush/tests/Support/DescriptorInheritanceGuardTest.php⚠dc | tests-harness | S |
| E419 | OPEN | MED | sugar-crush/tests/Support/ChildLifetimeScanner.php;sugar-crush/tests/Support/DescriptorInheritanceGuardTest.php;sugar-crush/src/Agents/ProcessExecutor.php⚠dc | tests-harness | M |
| E424 | OPEN | HIGH | sugar-crush/tests/Support/ChildLifetimeScannerFixtureTest.php;sugar-crush/tests/Support/DescriptorInheritanceGuardTest.php;sugar-crush/src/Support/ProcessReaper.php⚠dc | tests-harness | M |
| E440 | CLOSED | HIGH | sugar-crush/src/MCP/StdioMcpServer.php | lsp-mcp | S |
| E445 | OPEN | HIGH | sugar-crush/tests/Support/ForkedChildExitConventionTest.php;sugar-crush/tests/Support/ForkedChildReaperAdoptionTest.php | tests-harness | M |
| E447 | OPEN | HIGH | candy-core/src/Program.php;sugar-crush/tests/Support/DescriptorInheritanceGuardTest.php⚠dc⚠df | sibling:candy-core | M |
| E448 | OPEN | HIGH | sugar-crush/tests/Support/ChildLifetimeScanner.php;sugar-dash/src/Plugin/ExternalModule.php;sugar-reel/src/Decode/FfmpegDecoder.php;sugar-reel/src/AudioPlayer.php⚠dc⚠df | sibling:sugar-reel | M |
| E466 | OPEN | HIGH | candy-pty/src/Posix/PosixMasterPty.php⚠df | sibling:candy-pty | S |
| E469 | OPEN | HIGH | sugar-crush/tests/SuiteSkipRosterTest.php | tests-harness | S |
| E481 | PARTIAL | HIGH | sugar-crush/tests/Support/DuplicatedTestHelperDriftTest.php;sugar-crush/tests/LSP/LspConnectionShutdownTest.php;sugar-crush/tests/MCP/StdioMcpServerWriteBoundsTest.php⚠dd | tests-harness | M |
| E486 | OPEN | HIGH | tools/;.github/workflows/ci.yml⚠df | ci-infra | M |
| E493 | OPEN | HIGH | sugar-crush/src/Backend/EngineBackend.php;sugar-crush/src/Runtime.php⚠de | providers | M |
| E505 | PARTIAL | MED | sugar-crush/tests/ClaudeCodeMcpClientStdinWedgeTest.php;sugar-crush/tests/LSP/LspConnectionStdinWedgeTest.php;sugar-crush/tests/MCP/StdioMcpServerShutdownTest.php;sugar-crush/tests/Integration/McpToolWiringTest.php⚠dc | tests-harness | M |
| E541 | PARTIAL | MED | sugar-crush/tests/DenialPrefixRosterTest.php | tools-skills | S |
| E547 | PARTIAL | HIGH | sugar-crush/tests/Backend/StreamingCommandBackendTest.php⚠dd | tests-harness | S |
| E564 | OPEN | HIGH | sugar-crush/src/Context/RepoMapBlock.php | other | S |
| E565 | PARTIAL | MED | sugar-crush/tests/Support/DuplicatedTestHelperDriftTest.php⚠dd | tests-harness | S |
| E566 | OPEN | MED | sugar-crush/tests/ | tests-harness | S |
| E572 | OPEN | HIGH | sugar-crush/tests/SwallowingCatchCensusTest.php⚠da | tests-harness | S |
| E577 | OPEN | HIGH | sugar-crush/tests/Support/AssertionSwallowingCatchTest.php⚠da | tests-harness | S |
| E578 | OPEN | HIGH | sugar-crush/tests/SwallowingCatchCensusTest.php⚠da | tests-harness | S |
| E583 | OPEN | HIGH | sugar-crush/tests/;sugar-crush/tests/SymbolCitationDriftTest.php⚠dd | tests-harness | M |
| E609 | OPEN | HIGH | sugar-crush/tests/Backend/BackendSignatureNullabilityTest.php;sugar-crush/tests/Support/DuplicatedDocBlockLineTest.php | tests-harness | M |
| E610 | OPEN | HIGH | sugar-crush/tests/Support/DuplicatedTestHelperDriftTest.php⚠dd | tests-harness | S |
| E611 | OPEN | MED | UNKNOWN(re-derive) | other | S |
| E612 | OPEN | HIGH | sugar-crush/tests/SwallowingCatchCensusTest.php⚠da | tests-harness | S |
| E615 | OPEN | HIGH | sugar-crush/tests/SwallowingCatchCensusTest.php;sugar-crush/tests/Support/AssertionSwallowingCatchTest.php⚠da | tests-harness | M |
| E616 | OPEN | HIGH | sugar-crush/tests/DenialPrefixRosterTest.php | tools-skills | S |
| E617 | OPEN | HIGH | sugar-crush/tests/Support/AssertionSwallowingCatchTest.php⚠da | tests-harness | S |
| E629 | OPEN | HIGH | tools/tests/CheckPathReposTest.php⚠df | ci-infra | S |
| E633 | PARTIAL | HIGH | sugar-crush/src/Context/RepoMapBlock.php | other | S |
| E653 | CLOSED | HIGH | sugar-crush/src/Cli/Bootstrap.php;sugar-crush/src/Chat.php;sugar-crush/src/Commands/NoticesCommand.php;sugar-crush/tests/Commands/NoticesCommandTest.php;sugar-crush/tests/Cli/PermissionWarningDrainTest.php;sugar-crush/tests/Cli/StderrEmitterCensusTest.php;sugar-crush/tests/Cli/BootstrapTranscriptSeamCallSiteCensusTest.php⚠bd⚠ce | agents | L |
| E657 | CLOSED | MED | sugar-crush/src/Providers/SglangProvider.php;sugar-crush/src/Providers/VertexProvider.php;sugar-crush/src/Runtime.php⚠ca | providers | M |
| E658 | CLOSED | LOW | sugar-crush/tests/⚠ca | tests-harness | S |
| E659 | CLOSED | MED | sugar-crush/tests/Tools/BuiltInToolCorpusTest.php;sugar-crush/tests/Context/RepoMapBlockTest.php;sugar-crush/tests/SymbolCitationDriftTest.php⚠ca | tests-harness | M |
| E662 | CLOSED | LOW | sugar-crush/tests/Tools/BuiltInToolCorpus.php⚠ca | tests-harness | S |
| E670 | CLOSED | LOW | sugar-crush/tests/Workflows/WorkflowProviderHandoffTest.php⚠cd | workflows | S |
| E671 | CLOSED | MED | scripts/parallel-tests.sh;.github/workflows/ci.yml | test-infra | M |
| E672 | CLOSED | HIGH | sugar-crush/src/Hooks/ScriptHook.php;sugar-crush/src/Agents/ProcessExecutor.php;sugar-crush/src/Agents/AgentWorkerPool.php;sugar-crush/src/MCP/StdioMcpServer.php;sugar-crush/src/Backend/;sugar-crush/src/Sessions/;sugar-crush/src/Workflows/WorkflowEngine.php;sugar-crush/src/LSP/;sugar-crush/src/Providers/;sugar-crush/src/Config/StatusLineCommand.php;sugar-crush/src/Commands/ | containment | L |
| E673 | CLOSED | MED | sugar-crush/src/Tools/Concerns/CapturesProcessOutput.php;sugar-crush/src/Support/ProcessReaper.php | containment | M |
| E674 | CLOSED | LOW | sugar-crush/src/Tools/Concerns/CapturesProcessOutput.php | containment | S |
| E675 | CLOSED | HIGH | sugar-crush/src/Cli/Bootstrap.php;sugar-crush/src/Tools/BuiltIn/TaskTool.php;sugar-crush/src/Tui/Renderer.php;sugar-crush/tests/Tools/ | tools-skills | M |
| E676 | CLOSED | MED | sugar-crush/src/Hooks/ScriptHook.php;sugar-crush/src/Config/StatusLineCommand.php;sugar-crush/src/Sessions/BackgroundSessionRunner.php;sugar-crush/src/Support/ProcessReaper.php | containment | M |
| E677 | CLOSED | MED | sugar-crush/src/MCP/StdioMcpServer.php;sugar-crush/src/Backend/StreamingCommandBackend.php | lsp-mcp | M |
| E678 | CLOSED | MED | sugar-crush/src/Tools/McpToolBridge.php;sugar-crush/src/Cli/Subcommands.php;sugar-crush/src/Tui/⚠ba⚠ch | cli-config | M |
| E679 | CLOSED | LOW | sugar-crush/src/Agents/TaskList.php;sugar-crush/src/Agents/WorktreeManager.php | agents | M |
| E680 | CLOSED | MED | sugar-crush/src/Session.php | sessions | S |
| E681 | CLOSED | HIGH | sugar-crush/src/Chat.php;sugar-crush/tests/Commands/ | chat-input | M |
| E682 | CLOSED | MED | sugar-crush/src/Renderer.php;sugar-crush/src/App.php;sugar-crush/tests/Renderer/AbandonedPaletteIsNotCompositedTest.php | tui-render | M |
| E683 | CLOSED | LOW | sugar-crush/src/Commands/KeyBindingRegistry.php;sugar-crush/src/Renderer.php;sugar-crush/tests/MousePaneJumpDoesNotHandOverTest.php | tui-render | M |
| E684 | CLOSED | HIGH | sugar-crush/src/Cli/Bootstrap.php;sugar-crush/tests/Cli/BootstrapLaunchFormatConstantsTest.php | cli-config | M |
| E685 | CLOSED | HIGH | sugar-crush/src/Tools/ | tools-skills | S |
| E686 | PARTIAL | MED | sugar-crush/tests/;sugar-crush/docs/;sugar-crush/README.md⚠be⚠cb | tests-harness | M |
| E687 | CLOSED | MED | sugar-crush/tests/Cli/StderrEmitterCensusTest.php;sugar-crush/src/Agents/AgentWorkerPool.php;sugar-crush/tests/Agents/AgentWorkerPoolTeardownForkTotalTest.php⚠cd | agents | M |
| E688 | CLOSED | MED | sugar-crush/src/Agents/ProcessExecutor.php;sugar-crush/tests/Agents/WorkerExitCodeAttributionTest.php | agents | M |
| E689 | CLOSED | MED | sugar-crush/src/Tui/;sugar-crush/src/Tui/McpPanel.php;sugar-crush/src/Commands/McpAuthCommand.php;sugar-crush/tests/Tui/McpPanelTest.php;sugar-crush/src/Cli/Subcommands.php;sugar-crush/src/Tools/McpToolBridge.php⚠ba | cli-config | M |
| E690 | CLOSED | MED | sugar-crush/src/LSP/LspClient.php;sugar-crush/tests/LSP/LspClientDispatchPumpTest.php;sugar-crush/src/LSP/LspConnection.php;sugar-crush/src/MCP/StdioMcpServer.php⚠ba | lsp-mcp | S |
| E691 | CLOSED | LOW | .github/workflows/ci.yml;scripts/⚠cc | ci-infra | S |
| E692 | CLOSED | LOW | sugar-crush/src/Backend/EngineBackend.php⚠bc | containment | S |
## Domain → ids index (sorted by count)

> Round-66 closeout re-derivation: the actionable rows of the table above (11 ids left CLOSED this round:
> E42, E261, E440, E657–E659, E662, E670, E678, E687, E691 — E653 had left in r65 and merely gained
> Shape B). `⚠da..⚠df` = id shares at least one file with the PROPOSED round-67 lane ownership set
> (§0-NOW-68 §2) — merge or serialize the marked lanes where they collide. CLOSED ids are no longer
> listed here (the r65 index still carried them; this index is actionable-only now).

| domain | n | ids (⚠ = proposed-lane file collision) |
|---|---:|---|
| tests-harness | 52 | E36⚠dc⚠df, E111⚠dd, E125⚠dd, E127⚠dd, E143⚠dd, E144⚠dd, E148⚠dd, E149⚠dd, E154⚠db, E158, E165⚠dd⚠df, E174⚠dc⚠dd, E176⚠dc⚠db, E194, E195⚠db, E205⚠dc, E208, E214, E228⚠db, E235⚠dc, E242⚠dc, E257, E267⚠db, E269⚠db, E270⚠db, E272⚠dd, E281, E283⚠dc, E319⚠dc, E322, E325, E331⚠dc⚠dd, E342⚠dc, E343⚠dc, E356⚠dc⚠dd, E357⚠dd, E358⚠dc, E369, E378, E386⚠dd, E390⚠dc⚠df, E391⚠dc, E417⚠dc, E419⚠dc, E424⚠dc, E445, E469, E481⚠dd, E505⚠dc, E547⚠dd, E565⚠dd, E566, E572⚠da, E577⚠da, E578⚠da, E583⚠dd, E609, E610⚠dd, E612⚠da, E615⚠da, E617⚠da, E686⚠cb (tranche-4 carry, unlaned) |
| tools-skills | 12 | E10, E16, E93⚠df, E140, E172, E246, E309, E347, E375, E541, E616, E675 |
| cli-config | 7 | E45, E74, E78c, E176⚠dc⚠db, E199, E376, E678 |
| providers | 5 | E17⚠de, E20⚠de, E258⚠db, E493⚠de, E657⚠ca |
| tui-render | 4 | E5, E43, E54, E682 |
| other | 4 | E25, E107⚠df, E564, E611 |
| chat-input | 4 | E3⚠de, E4⚠de, E175⚠de, E681 |
| agents | 3 | E259⚠db, E679, E687⚠cd |
| docs | 4 | E9, E134, E204, E353 |
| sibling:candy-core | 2 | E370⚠df, E447⚠dc⚠df |
| ci-infra | 2 | E486⚠df, E629⚠df |
| lsp-mcp | 2 | E42, E440 |
| sibling:sugar-dash | 1 | E366⚠dc⚠df |
| sibling:sugar-reel | 1 | E448⚠dc⚠df |
| sibling:candy-pty | 1 | E466⚠df |
| sessions | 1 | E291 |

## Cross-file collision clusters (≥3 actionable ids sharing a file)

- **`sugar-crush/tests/Cli/StderrEmitterCensusTest.php`** — E154, E195, E228, E258, E259, E267, E269, E270 — the **db** lane, ONE file, eight ids
- **`sugar-crush/src/Chat.php`** — E3, E4, E17, E20, E175 — the **de** lane; **E45/E74/E78c (Bootstrap.php) serialize behind it, wave-2** (per supervisor: Chat/Bootstrap pair)
- **`sugar-crush/src/Cli/Bootstrap.php`** — E45, E74, E78c (wave-2 after de)
- **`sugar-crush/tests/Support/DuplicatedTestHelperDriftTest.php`** — E272, E357, E386, E481, E565, E610 — all **dd**
- **`sugar-crush/src/Renderer.php`** — E5, E43, E54 (E682/E683 CLOSED, out of the cluster)
- **`sugar-crush/src/Runtime.php`** — E16, E140, E246, E493 (E657 CLOSED, out)
- **`sugar-crush/tests/Config/GlobFigureDriftTest.php`** — E125, E143, E144, E357, E386 — all **dd**
- **`sugar-crush/tests/Support/ChildStderrCaptureTest.php`** — E176, E205, E235, E283, E343 — all **dc**
- **`sugar-crush/tests/`** — E194, E566, E583, E686 (bare-dir cites; sub-dir owners rule)
- **`sugar-crush/tests/Support/DescriptorInheritanceGuardTest.php`** — E366, E417, E419, E424, E447 — **dc** core with **df** siblings riding the same file
- **`sugar-crush/tests/Config/ConfigWriteProducerDocumentationDriftTest.php`** — E125, E144, E357, E386 — **dd**
- **`sugar-crush/tests/DenialPrefixRosterTest.php`** — E246, E309, E541, E616 — unlaned cluster
