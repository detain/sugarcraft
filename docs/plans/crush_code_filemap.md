# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-10 @ tip `eb9ce68e0540e975dde5db891aa76f9c107cceaa` — regenerate this file at every round-close.
Purpose: map every actionable backlog id (OPEN/PARTIAL/STALE-CITATION/UNCERTAIN = 157) to the files it touches so the supervisor can schedule file-disjoint lanes. Tier/lane analysis lives in `docs/plans/crush_code_concurrency.md` — NOT duplicated here; the `domain` column below is a file-cluster bucket, and the `⚠` markers in the index are round-62 live-lane file ownership only.

## Path normalization

Rows are derived from the ledger's evidence/note citations (`docs/plans/crush_code_backlog_triage.md`), normalized to repo-root paths:

- Bare `src/…`, `tests/…`, `docs/…` (lib docs), `bin/…`, `README.md`, `phpunit.xml` as cited → **`sugar-crush/`-prefixed**.
- Citations already written monorepo-root (`sugar-crush/…`, `docs/plans/…`, `tools/…`, `.github/…`, `docs/_data/…`, `crush_code.md`) → kept as-is.
- Sibling libs (`candy-…`, `sugar-dash`, `sugar-reel`) → kept as-is.
- Bare-directory citations kept with trailing `/` (e.g. `sugar-crush/tests/Cli/`).
- `size`: S = 1 path, M = 2–4 paths, L = 5+ paths or cross-domain.
- `files = UNKNOWN(re-derive)` when the row cites no resolvable path (2 rows: E107, E611).

## Table (one row per actionable id, ledger order)

| id | stamp | conf | files | domain | size |
|---|---|---|---|---|---|
| E1 | OPEN | MED | sugar-crush/README.md;docs/plans/crush_code_RESUME.md | docs | M |
| E2 | OPEN | HIGH | sugar-crush/src/Chat.php | chat-input | S |
| E3 | OPEN | HIGH | sugar-crush/src/Chat.php;sugar-crush/src/Palette/PaletteState.php | chat-input | M |
| E4 | OPEN | HIGH | sugar-crush/src/Chat.php | chat-input | S |
| E5 | OPEN | HIGH | sugar-crush/src/Renderer.php | tui-render | S |
| E8 | OPEN | HIGH | sugar-crush/src/Chat.php;sugar-crush/src/Workflows/WorkflowEngine.php | workflows | M |
| E10 | OPEN | HIGH | sugar-crush/src/Tools/BuiltIn/Doctor.php;sugar-crush/tests/Tools/BuiltInToolTest.php | tools-skills | M |
| E12 | OPEN | HIGH | sugar-crush/src/Commands/KeyBindingRegistry.php;sugar-crush/src/Tui/Renderer.php;sugar-crush/tests/Tui/KeyboardHandlerTest.php | tui-render | M |
| E14 | OPEN | HIGH | sugar-crush/src/ToolRegistry.php | tools-skills | S |
| E15 | OPEN | HIGH | candy-vcr/src/Tape/Lexer.php | sibling:candy-vcr | S |
| E16 | OPEN | MED | sugar-crush/src/Runtime.php;sugar-crush/src/Hooks/HookManager.php | tools-skills | M |
| E17 | OPEN | HIGH | sugar-crush/src/Providers/CompleteResponse.php;sugar-crush/src/Context/ContextCompactor.php;sugar-crush/src/Chat.php | providers | M |
| E20 | OPEN | HIGH | sugar-crush/src/Chat.php;sugar-crush/src/Backend/EngineBackend.php | providers | M |
| E27 | OPEN | HIGH | sugar-crush/src/Providers/ClaudeCodeProvider.php;sugar-crush/src/Providers/VertexProvider.php;sugar-crush/tests/Providers/TransientFailureTest.php | providers | M |
| E28 | OPEN | HIGH | sugar-crush/src/Agents/AgentManager.php | agents | S |
| E29 | OPEN | MED | docs/plans/crush_agent_rules.md;sugar-crush/tests/Cli/ | tests-harness | M |
| E30 | OPEN | HIGH | crush_code.md | docs | S |
| E37 | OPEN | HIGH | sugar-crush/src/Cli/Help.php;sugar-crush/tests/Cli/HelpTest.php | cli-config | M |
| E40 | OPEN | MED | sugar-crush/src/Support/ContainedPath.php;sugar-crush/src/Cli/Bootstrap.php;sugar-crush/src/MCP/McpClient.php | lsp-mcp | M |
| E42 | OPEN | HIGH | sugar-crush/src/Tools/McpToolBridge.php | lsp-mcp | S |
| E43 | OPEN | HIGH | sugar-crush/src/Renderer.php | tui-render | S |
| E47 | OPEN | HIGH | sugar-crush/src/Renderer.php;sugar-crush/tests/Renderer/PaneWidthInvariantTest.php | tui-render | M |
| E48 | OPEN | HIGH | sugar-crush/src/Renderer.php | tui-render | S |
| E49 | OPEN | HIGH | candy-shine/src/Renderer.php | sibling:candy-shine | S |
| E50 | OPEN | HIGH | candy-core/src/SgrState.php | sibling:candy-core | S |
| E78c | OPEN | MED | sugar-crush/src/Cli/NonInteractive.php;sugar-crush/src/Cli/Bootstrap.php | cli-config | M |
| E93 | OPEN | MED | sugar-crush/src/Skills/SkillRegistry.php;sugar-crush/src/Util/PathGlob.php | tools-skills | M |
| E107 | OPEN | LOW | UNKNOWN(re-derive) | other | S |
| E115 | OPEN | MED | sugar-crush/src/Skills/SkillRegistry.php | tools-skills | S |
| E116 | OPEN | MED | sugar-crush/README.md;sugar-crush/src/Cli/Subcommands.php;sugar-crush/src/Cli/NonInteractive.php | cli-config | M |
| E124 | OPEN | MED | docs/_data/sugar-crush.body.html;sugar-crush/tests/Config/EnvRosterDriftTest.php | docs | M |
| E127 | OPEN | HIGH | sugar-crush/tests/Config/Support/EnvReadScanner.php | tests-harness | S |
| E136 | OPEN | HIGH | sugar-crush/src/Agents/Team.php | agents | S |
| E137 | OPEN | HIGH | sugar-crush/src/Agents/TaskList.php;sugar-crush/src/Agents/WorktreeManager.php | agents | M |
| E140 | OPEN | HIGH | sugar-crush/src/Support/ToolIpcFiles.php;sugar-crush/src/Runtime.php | tools-skills | M |
| E143 | OPEN | HIGH | sugar-crush/tests/Config/GlobFigureDriftTest.php | tests-harness | S |
| E144 | OPEN | HIGH | sugar-crush/tests/Chat/ChatConfigChangeDoorsDocumentationDriftTest.php;sugar-crush/tests/Config/GlobFigureDriftTest.php;sugar-crush/tests/Config/ConfigWriteProducerDocumentationDriftTest.php;sugar-crush/tests/Config/ThemePersistenceFramingTest.php | tests-harness | M |
| E148 | OPEN | HIGH | sugar-crush/tests/Config/EnvRosterDriftTest.php | tests-harness | S |
| E149 | OPEN | HIGH | sugar-crush/tests/Config/EnvRosterDriftTest.php | tests-harness | S |
| E156 | OPEN | MED | sugar-crush/tests/Cli/BootstrapLaunchNoticeRoutingTest.php | tests-harness | S |
| E165 | OPEN | MED | sugar-crush/tests/Config/ReadmeRosterDriftTest.php | tests-harness | S |
| E175 | OPEN | HIGH | sugar-crush/src/Chat.php | chat-input | S |
| E184 | OPEN | HIGH | sugar-crush/tests/Cli/BootstrapLaunchNoticeRoutingTest.php;sugar-crush/src/Cli/Bootstrap.php | tests-harness | M |
| E189 | OPEN | HIGH | sugar-crush/tests/Cli/BootstrapLaunchFormatConstantsTest.php | tests-harness | S |
| E194 | OPEN | HIGH | sugar-crush/phpunit.xml;sugar-crush/tests/ | tests-harness | M |
| E195 | OPEN | HIGH | sugar-crush/tests/Cli/StderrEmitterCensusTest.php | tests-harness | S |
| E199 | OPEN | HIGH | sugar-crush/src/Diagnostics/RuntimeNoticeSink.php | cli-config | S |
| E204 | OPEN | MED | docs/plans/crush_code_RESUME.md;docs/plans/crush_code_hardening_backlog.md | docs | M |
| E205 | OPEN | HIGH | sugar-crush/tests/Support/ChildStderrCaptureScanner.php;sugar-crush/tests/Support/ChildStderrCaptureTest.php | tests-harness | M |
| E208 | OPEN | HIGH | sugar-crush/tests/Support/ForkedChildExitScanner.php;sugar-crush/tests/Support/TokenFunctionRanges.php | tests-harness | M |
| E213 | OPEN | HIGH | sugar-crush/tests/Cli/BootstrapLaunchFormatConstantsTest.php | tests-harness | S |
| E214 | OPEN | HIGH | sugar-crush/tests/Cli/BootstrapLaunchFormatConstantsTest.php | tests-harness | S |
| E215 | OPEN | HIGH | sugar-crush/tests/Cli/BootstrapLaunchFormatConstantsTest.php | tests-harness | S |
| E217 | OPEN | HIGH | sugar-crush/tests/Cli/BootstrapLaunchFormatConstantsTest.php | tests-harness | S |
| E242 | OPEN | HIGH | sugar-crush/tests/bootstrap.php | tests-harness | S |
| E258 | OPEN | HIGH | sugar-crush/tests/Cli/StderrEmitterCensusTest.php;sugar-crush/src/Providers/ProviderFactory.php | providers | M |
| E259 | OPEN | HIGH | sugar-crush/src/Agents/WorktreeManager.php;sugar-crush/tests/Cli/StderrEmitterCensusTest.php | agents | M |
| E261 | OPEN | HIGH | sugar-crush/src/Agents/AgentWorkerPool.php | agents | S |
| E262 | OPEN | MED | sugar-crush/src/Agents/AgentWorkerPool.php | agents | S |
| E269 | OPEN | HIGH | sugar-crush/tests/Cli/StderrEmitterCensusTest.php | tests-harness | S |
| E276 | OPEN | HIGH | sugar-crush/tests/Support/InterpolationOpenerTokenTest.php;sugar-crush/tests/Cli/BootstrapLaunchFormatConstantsTest.php | tests-harness | M |
| E281 | OPEN | HIGH | sugar-crush/tests/Agents/TeamTest.php | tests-harness | S |
| E295 | OPEN | HIGH | sugar-crush/src/Agents/AgentWorkerPool.php | agents | S |
| E309 | OPEN | HIGH | sugar-crush/tests/DenialPrefixRosterTest.php | tools-skills | S |
| E324 | OPEN | HIGH | sugar-crush/src/Workflows/WorkflowEngine.php;sugar-crush/tests/Support/ProcessUniqueTempNameTest.php | workflows | M |
| E325 | OPEN | HIGH | sugar-crush/tests/Support/ReflectionLineSliceReaderCensusTest.php;sugar-crush/tests/Cli/HelpTest.php;sugar-crush/tests/VhsTapeContractTest.php | tests-harness | M |
| E342 | OPEN | HIGH | sugar-crush/tests/Support/ForkedChildTest.php;sugar-crush/tests/ChatTest.php;sugar-crush/tests/Backend/EngineBackendTest.php | tests-harness | M |
| E343 | OPEN | MED | sugar-crush/tests/Support/ChildStderrCaptureTest.php;sugar-crush/tests/Support/SuiteChildStdinIsolationTest.php | tests-harness | M |
| E349 | OPEN | HIGH | sugar-crush/src/Workflows/WorkflowEngine.php | workflows | S |
| E353 | OPEN | MED | sugar-crush/docs/HOOKS.md | docs | S |
| E357 | OPEN | HIGH | sugar-crush/tests/Config/DocumentParagraphsTest.php;sugar-crush/tests/Config/ConfigWriteProducerDocumentationDriftTest.php;sugar-crush/tests/Config/GlobFigureDriftTest.php;sugar-crush/tests/Support/DuplicatedTestHelperDriftTest.php | tests-harness | M |
| E369 | OPEN | HIGH | sugar-crush/tests/StdinConstantReaderCensusTest.php | tests-harness | S |
| E370 | OPEN | MED | candy-core/tests/Util/ClosedDescriptorZeroFamilyTest.php | sibling:candy-core | S |
| E375 | OPEN | MED | sugar-crush/src/Permissions/DenialKind.php | tools-skills | S |
| E376 | OPEN | HIGH | sugar-crush/src/Cli/HeadlessPermissionPrompt.php | cli-config | S |
| E378 | OPEN | HIGH | sugar-crush/tests/Support/ForkedChildReaperAdoptionTest.php | tests-harness | S |
| E386 | OPEN | HIGH | sugar-crush/tests/Config/DocumentParagraphsTest.php;sugar-crush/tests/Config/GlobFigureDriftTest.php;sugar-crush/tests/Config/ConfigWriteProducerDocumentationDriftTest.php;sugar-crush/tests/Support/DuplicatedTestHelperDriftTest.php | tests-harness | M |
| E391 | OPEN | HIGH | sugar-crush/tests/Support/RequirementDirectiveProvenanceTest.php | tests-harness | S |
| E407 | OPEN | HIGH | sugar-crush/src/MCP/StdioMcpServer.php;sugar-crush/src/Backend/StreamingCommandBackend.php;sugar-crush/src/Support/ProcessReaper.php | lsp-mcp | M |
| E417 | OPEN | HIGH | sugar-crush/tests/Support/DescriptorInheritanceGuardTest.php | tests-harness | S |
| E419 | OPEN | MED | sugar-crush/tests/Support/ChildLifetimeScanner.php;sugar-crush/tests/Support/DescriptorInheritanceGuardTest.php;sugar-crush/src/Agents/ProcessExecutor.php | tests-harness | M |
| E424 | OPEN | HIGH | sugar-crush/tests/Support/ChildLifetimeScannerFixtureTest.php;sugar-crush/tests/Support/DescriptorInheritanceGuardTest.php;sugar-crush/src/Support/ProcessReaper.php | tests-harness | M |
| E440 | OPEN | HIGH | sugar-crush/src/MCP/StdioMcpServer.php | lsp-mcp | S |
| E445 | OPEN | HIGH | sugar-crush/tests/Support/ForkedChildExitConventionTest.php;sugar-crush/tests/Support/ForkedChildReaperAdoptionTest.php | tests-harness | M |
| E447 | OPEN | HIGH | candy-core/src/Program.php;sugar-crush/tests/Support/DescriptorInheritanceGuardTest.php | sibling:candy-core | M |
| E448 | OPEN | HIGH | sugar-crush/tests/Support/ChildLifetimeScanner.php;sugar-dash/src/Plugin/ExternalModule.php;sugar-reel/src/Decode/FfmpegDecoder.php;sugar-reel/src/AudioPlayer.php | sibling:sugar-reel | L |
| E466 | OPEN | HIGH | candy-pty/src/Posix/PosixMasterPty.php | sibling:candy-pty | L |
| E469 | OPEN | HIGH | sugar-crush/tests/SuiteSkipRosterTest.php | tests-harness | S |
| E475 | OPEN | HIGH | sugar-crush/src/LSP/LspConnection.php | lsp-mcp | S |
| E483 | OPEN | HIGH | sugar-crush/tests/MCP/StdioMcpServerWriteBoundsTest.php | lsp-mcp | S |
| E486 | OPEN | HIGH | tools/;.github/workflows/ci.yml | ci-infra | M |
| E493 | OPEN | HIGH | sugar-crush/src/Backend/EngineBackend.php;sugar-crush/src/Runtime.php | providers | M |
| E564 | OPEN | HIGH | sugar-crush/src/Context/RepoMapBlock.php | other | S |
| E566 | OPEN | MED | sugar-crush/tests/ | tests-harness | L |
| E568 | OPEN | HIGH | sugar-crush/src/Workflows/Workflow.php | workflows | S |
| E572 | OPEN | HIGH | sugar-crush/tests/SwallowingCatchCensusTest.php | tests-harness | S |
| E577 | OPEN | HIGH | sugar-crush/tests/Support/AssertionSwallowingCatchTest.php | tests-harness | S |
| E578 | OPEN | HIGH | sugar-crush/tests/SwallowingCatchCensusTest.php | tests-harness | S |
| E583 | OPEN | HIGH | sugar-crush/tests/;sugar-crush/tests/SymbolCitationDriftTest.php | tests-harness | L |
| E609 | OPEN | HIGH | sugar-crush/tests/Backend/BackendSignatureNullabilityTest.php;sugar-crush/tests/Support/DuplicatedDocBlockLineTest.php | tests-harness | M |
| E610 | OPEN | HIGH | sugar-crush/tests/Support/DuplicatedTestHelperDriftTest.php | tests-harness | S |
| E611 | OPEN | MED | UNKNOWN(re-derive) | other | S |
| E612 | OPEN | HIGH | sugar-crush/tests/SwallowingCatchCensusTest.php | tests-harness | S |
| E615 | OPEN | HIGH | sugar-crush/tests/SwallowingCatchCensusTest.php;sugar-crush/tests/Support/AssertionSwallowingCatchTest.php | tests-harness | M |
| E616 | OPEN | HIGH | sugar-crush/tests/DenialPrefixRosterTest.php | tools-skills | S |
| E617 | OPEN | HIGH | sugar-crush/tests/Support/AssertionSwallowingCatchTest.php | tests-harness | S |
| E629 | OPEN | HIGH | tools/tests/CheckPathReposTest.php | ci-infra | S |
| E652 | OPEN | HIGH | sugar-crush/src/Agents/AgentPoolConfig.php;sugar-crush/src/Chat.php;sugar-crush/src/Cli/Bootstrap.php;sugar-crush/docs/WORKFLOWS.md | agents | L |
| E653 | OPEN | MED | sugar-crush/src/Cli/Bootstrap.php;sugar-crush/tests/Cli/BootstrapLaunchNoticeRoutingTest.php;sugar-crush/tests/Cli/BootstrapToolAndPermissionSettingsTest.php;sugar-crush/tests/Cli/BootstrapTranscriptSeamCallSiteCensusTest.php;sugar-crush/tests/Cli/StderrEmitterCensusTest.php | agents | L |
| E654 | OPEN | MED | sugar-crush/src/Agents/AgentManager.php | agents | S |
| E655 | OPEN | MED | sugar-crush/tests/Chat/CompactModelSummaryTest.php;sugar-crush/tests/MouseModalGuardTest.php | tests-harness | M |
| E656 | OPEN | MED | sugar-crush/tests/Agents/AgentManagerTest.php | agents | S |
| E657 | OPEN | MED | sugar-crush/src/Providers/SglangProvider.php;sugar-crush/src/Providers/VertexProvider.php;sugar-crush/src/Runtime.php | providers | M |
| E658 | OPEN | LOW | sugar-crush/tests/ | tests-harness | L |
| E659 | OPEN | MED | sugar-crush/tests/Tools/BuiltInToolCorpusTest.php;sugar-crush/tests/Context/RepoMapBlockTest.php;sugar-crush/tests/SymbolCitationDriftTest.php | tests-harness | M |
| E660 | OPEN | MED | sugar-crush/src/Agents/ProcessExecutor.php | agents | S |
| E661 | OPEN | LOW | sugar-crush/src/Agents/AgentPresetRegistry.php;sugar-crush/tests/Agents/AgentPresetRegistryTest.php | agents | M |
| E662 | OPEN | LOW | sugar-crush/tests/Tools/BuiltInToolCorpus.php | tests-harness | S |
| E25 | PARTIAL | MED | sugar-crush/src/Context/MemoryBlock.php;sugar-crush/tests/Context/MemoryBlockTest.php | other | M |
| E41 | PARTIAL | HIGH | sugar-crush/src/MCP/McpClient.php | lsp-mcp | S |
| E45 | PARTIAL | MED | sugar-crush/src/Cli/Bootstrap.php | cli-config | S |
| E54 | PARTIAL | HIGH | sugar-crush/src/Tui/AgentViewPane.php;sugar-crush/src/Renderer.php;sugar-crush/src/Tui/Renderer.php;sugar-crush/src/Tui/Components/AgentDashboardPane.php | tui-render | M |
| E74 | PARTIAL | MED | sugar-crush/README.md;sugar-crush/src/Config/LayeredSettings.php;sugar-crush/src/Cli/Bootstrap.php;sugar-crush/docs/SETTINGS.md | cli-config | L |
| E111 | PARTIAL | MED | sugar-crush/tests/Config/EnvRosterDriftTest.php;sugar-crush/docs/SETTINGS.md | tests-harness | M |
| E125 | PARTIAL | HIGH | sugar-crush/tests/Config/ConfigWriteProducerDocumentationDriftTest.php;sugar-crush/tests/Config/ThemePersistenceFramingTest.php;sugar-crush/tests/Config/GlobFigureDriftTest.php;sugar-crush/tests/Chat/ChatConfigChangeDoorsDocumentationDriftTest.php | tests-harness | M |
| E134 | PARTIAL | MED | docs/plans/crush_code_worklog.md | docs | S |
| E154 | PARTIAL | HIGH | sugar-crush/tests/Cli/StderrEmitterCensusTest.php | tests-harness | S |
| E158 | PARTIAL | HIGH | sugar-crush/tests/Integration/BinSugarcrushAutoloadGuardTest.php | tests-harness | S |
| E172 | PARTIAL | HIGH | sugar-crush/src/Commands/CommandLoader.php | tools-skills | S |
| E174 | PARTIAL | HIGH | sugar-crush/tests/Support/FlattensSourceProseTrait.php;sugar-crush/tests/Support/DropsInsignificantTokensTrait.php;sugar-crush/tests/Cli/HeadlessPermissionPromptAttachmentTest.php;sugar-crush/tests/Support/ChildStderrCaptureScanner.php;sugar-crush/tests/Support/ChildLifetimeScanner.php | tests-harness | L |
| E176 | PARTIAL | MED | sugar-crush/tests/Support/ChildStderrCaptureTest.php;sugar-crush/src/Cli/NonInteractive.php | cli-config | M |
| E228 | PARTIAL | MED | sugar-crush/tests/Cli/StderrEmitterCensusTest.php | tests-harness | S |
| E235 | PARTIAL | HIGH | sugar-crush/tests/Support/ChildStderrCaptureTest.php | tests-harness | S |
| E246 | PARTIAL | MED | sugar-crush/src/Runtime.php;sugar-crush/tests/DenialPrefixRosterTest.php | tools-skills | M |
| E257 | PARTIAL | MED | sugar-crush/tests/Integration/BinSugarcrushWiringTest.php;sugar-crush/tests/Tools/BuiltInToolCorpusTest.php | tests-harness | M |
| E267 | PARTIAL | MED | sugar-crush/tests/Cli/StderrEmitterCensusTest.php | tests-harness | S |
| E270 | PARTIAL | MED | sugar-crush/tests/Cli/StderrEmitterCensusTest.php | tests-harness | S |
| E272 | PARTIAL | MED | sugar-crush/tests/Support/DuplicatedTestHelperDriftTest.php | tests-harness | S |
| E283 | PARTIAL | MED | sugar-crush/tests/Support/InterpolationOpenerTokenTest.php;sugar-crush/tests/Support/ChildStderrCaptureTest.php | tests-harness | M |
| E291 | PARTIAL | HIGH | sugar-crush/src/Sessions/BackgroundSessionRunner.php;sugar-crush/tests/Support/ForkedChildExitConventionTest.php | sessions | M |
| E319 | PARTIAL | HIGH | sugar-crush/tests/Support/ForkedChildTest.php;sugar-crush/tests/ChatTest.php;sugar-crush/tests/Backend/EngineBackendTest.php;sugar-crush/tests/Support/SuiteChildStdinIsolationTest.php;sugar-crush/tests/bootstrap.php | tests-harness | L |
| E322 | PARTIAL | MED | sugar-crush/tests/TtyStreamArgumentCensusTest.php | tests-harness | S |
| E331 | PARTIAL | HIGH | sugar-crush/tests/Support/DropsInsignificantTokensTrait.php;sugar-crush/tests/Config/ReadmeJsonErrorContractDriftTest.php | tests-harness | M |
| E347 | PARTIAL | MED | sugar-crush/tests/Cli/RefusalStderrSurfaceTest.php;sugar-crush/src/Permissions/DenialKind.php | tools-skills | M |
| E356 | PARTIAL | MED | sugar-crush/tests/Support/DropsInsignificantTokensTrait.php;sugar-crush/tests/Config/ReadmeJsonErrorContractDriftTest.php | tests-harness | M |
| E358 | PARTIAL | HIGH | sugar-crush/tests/Support/SuiteChildStdinIsolationTest.php;sugar-crush/tests/Support/ForkedChildTest.php;sugar-crush/tests/ChatTest.php;sugar-crush/tests/Backend/EngineBackendTest.php | tests-harness | M |
| E366 | PARTIAL | MED | sugar-crush/tests/Support/DescriptorInheritanceGuardTest.php;sugar-dash/src/Plugin/ExternalModule.php | sibling:sugar-dash | M |
| E390 | PARTIAL | HIGH | sugar-crush/tests/Support/RequirementDirectiveProvenanceTest.php;sugar-crush/tests/Cli/BootstrapSkillSkipsTest.php | tests-harness | M |
| E481 | PARTIAL | HIGH | sugar-crush/tests/Support/DuplicatedTestHelperDriftTest.php;sugar-crush/tests/LSP/LspConnectionShutdownTest.php;sugar-crush/tests/MCP/StdioMcpServerWriteBoundsTest.php | tests-harness | M |
| E505 | PARTIAL | MED | sugar-crush/tests/ClaudeCodeMcpClientStdinWedgeTest.php;sugar-crush/tests/LSP/LspConnectionStdinWedgeTest.php;sugar-crush/tests/MCP/StdioMcpServerShutdownTest.php;sugar-crush/tests/Integration/McpToolWiringTest.php | tests-harness | M |
| E541 | PARTIAL | MED | sugar-crush/tests/DenialPrefixRosterTest.php | tools-skills | S |
| E547 | PARTIAL | HIGH | sugar-crush/tests/Backend/StreamingCommandBackendTest.php | tests-harness | S |
| E565 | PARTIAL | MED | sugar-crush/tests/Support/DuplicatedTestHelperDriftTest.php | tests-harness | S |
| E633 | PARTIAL | HIGH | sugar-crush/src/Context/RepoMapBlock.php | other | S |
| E649 | PARTIAL | HIGH | sugar-crush/src/Agents/AgentPoolConfig.php;sugar-crush/src/Chat.php;sugar-crush/src/Cli/Bootstrap.php;sugar-crush/tests/Workflows/WorkflowProviderHandoffTest.php | agents | M |
| E9 | STALE-CITATION | MED | sugar-crush/README.md;docs/plans/crush_code_worklog.md | docs | M |
| E36 | UNCERTAIN | LOW | sugar-crush/tests/Cli/BootstrapSkillSkipsTest.php | tests-harness | S |

## Domain → ids index (sorted by count)

`⚠<lane>` = id shares at least one file with a LIVE round-62 lane's ownership set. Lane ownership (normalized):
**E** = `sugar-crush/src/Agents/AgentPoolConfig.php`, `sugar-crush/src/Chat*.php`, `sugar-crush/src/Cli/Bootstrap.php`, `sugar-crush/tests/Agents/AgentManagerTest.php`, `sugar-crush/tests/Agents/AgentWorkerPoolTest.php`, `sugar-crush/tests/Workflows/`, `sugar-crush/docs/WORKFLOWS.md` ·
**G** = `sugar-crush/tests/bootstrap.php`, `sugar-crush/tests/App/`, `sugar-crush/tests/TerminalSizeFallbackIsolationTest.php` (new) ·
**H** = doc-blocks in `sugar-crush/src/Agents/Agent.php`, `sugar-crush/src/Chat*.php`, `sugar-crush/src/Renderer.php`, `sugar-crush/src/Runtime.php`, `sugar-crush/src/Cli/NonInteractive.php`, `sugar-crush/src/Providers/VertexProvider.php` + tests prose (committed, unmerged — any tests/*.php is a soft collision; hard ⚠ below reflects only concrete file overlap) ·
**I** = `sugar-crush/src/Cli/Help.php`, `sugar-crush/tests/Config/EnvRosterDriftTest.php`, `sugar-crush/README.md`, `docs/_data/` ·
**J** = `sugar-crush/src/Skills/SkillRegistry.php`.

| domain | n | ids (⚠ = live-lane file collision) |
|---|---:|---|
| tests-harness | 72 | E29, E36, E111⚠I, E125, E127, E143, E144, E148⚠I, E149⚠I, E154, E156, E158, E165, E174, E184⚠E, E189, E194, E195, E205, E208, E213, E214, E215, E217, E228, E235, E242⚠G, E257, E267, E269, E270, E272, E276, E281, E283, E319⚠G, E322, E325, E331, E342, E343, E356, E357, E358, E369, E378, E386, E390, E391, E417, E419, E424, E445, E469, E481, E505, E547, E565, E566, E572, E577, E578, E583⚠H, E609, E610, E612, E615, E617, E655, E658⚠E⚠H, E659, E662 |
| agents | 14 | E28, E136, E137, E259, E261, E262, E295, E649⚠E⚠H, E652⚠E⚠H, E653⚠E, E654, E656⚠E, E660, E661 |
| tools-skills | 13 | E10, E14, E16⚠H, E93⚠J, E115⚠J, E140⚠H, E172, E246⚠H, E309, E347, E375, E541, E616 |
| cli-config | 8 | E37⚠I, E45⚠E, E74⚠E⚠I, E78c⚠E⚠H, E116⚠H⚠I, E176⚠H, E199, E376 |
| lsp-mcp | 7 | E40⚠E, E41, E42, E407, E440, E475, E483 |
| docs | 7 | E1⚠I, E9⚠I, E30, E124⚠I, E134, E204, E353 |
| tui-render | 6 | E5⚠H, E12, E43⚠H, E47⚠H, E48⚠H, E54⚠H |
| providers | 6 | E17⚠E⚠H, E20⚠E⚠H, E27⚠H, E258, E493⚠H, E657⚠H |
| other | 5 | E25, E107, E564, E611, E633 |
| chat-input | 4 | E2⚠E⚠H, E3⚠E⚠H, E4⚠E⚠H, E175⚠E⚠H |
| workflows | 4 | E8⚠E⚠H, E324, E349, E568 |
| ci-infra | 2 | E486, E629 |
| sessions | 1 | E291 |
| sibling:candy-core | 3 | E50, E370, E447 |
| sibling:candy-shine | 1 | E49 |
| sibling:candy-pty | 1 | E466 |
| sibling:candy-vcr | 1 | E15 |
| sibling:sugar-dash | 1 | E366 |
| sibling:sugar-reel | 1 | E448 |

Cross-file collision clusters worth one lane (share files, merge or serialize): E324+E349+E8 (WorkflowEngine.php); E246+E578+E612+E572+E615 (SwallowingCatchCensusTest.php); E189+E213+E214+E215+E217+E276 (BootstrapLaunchFormatConstantsTest.php); E195+E258+E259+E267+E269+E228+E154+E653 (StderrEmitterCensusTest.php); E205+E235+E283+E176+E343+E174 (ChildStderrCapture*); E357+E386+E144+E125 (drift-test splitter family); E2+E3+E4+E175+E8+E17+E20+E652+E649 (Chat.php); E37+E116+E124+E148+E149+E74+E1+E111 (lane-I doc/roster surfaces); E93+E115 (lane-J SkillRegistry).
