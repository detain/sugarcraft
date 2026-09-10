# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-10 @ tip `8c52b26e5` (regenerated at the round-63 close; supersedes the round-62
`1a6ef5f60` cut) — regenerate this file at every round-close.
Purpose: map every actionable backlog id (OPEN/PARTIAL/STALE-CITATION/UNCERTAIN = 131) to the files it
touches so the supervisor can schedule file-disjoint lanes. Tier/lane analysis lives in
`docs/plans/crush_code_concurrency.md` — NOT duplicated here; the `domain` column below is a
file-cluster bucket. Round-63 `AA–JJ` ownership markers retired at this close; round-64 lanes α–ε
(`crush_code_RESUME.md` §0-NOW-65 §2) own via fresh briefs — the `⚠α..⚠ε` markers below flag ids whose
files collide with a PROPOSED lane's ownership set (α/β share `src/Cli/Bootstrap.php` — coordinate;
γ/δ share the MCP/Sessions/ScriptHook region — δ items mount INTO files γ detaches, serialize by file).

## Path normalization

Rows are derived from the ledger's evidence/note citations (`docs/plans/crush_code_backlog_triage.md`), normalized to repo-root paths:

- Bare `src/…`, `tests/…`, `docs/…` (lib docs), `bin/…`, `README.md`, `phpunit.xml` as cited → **`sugar-crush/`-prefixed**.
- Citations already written monorepo-root (`sugar-crush/…`, `docs/plans/…`, `tools/…`, `.github/…`, `scripts/…`, `crush_code.md`) → kept as-is.
- Sibling libs (`candy-…`, `sugar-dash`, `sugar-reel`) → kept as-is.
- Bare-directory citations kept with trailing `/` (e.g. `sugar-crush/tests/Cli/`).
- `size`: S = 1 path, M = 2–4 paths, L = 5+ paths or cross-domain.
- `files = UNKNOWN(re-derive)` when the row cites no resolvable path.

## Table (one row per actionable id, ledger order)

| id | stamp | conf | files (⚠ = proposed-lane collision) | domain | size |
|---|---|---|---|---|---|
| E3 | OPEN | HIGH | sugar-crush/src/Chat.php;sugar-crush/src/Palette/PaletteState.php | chat-input | M |
| E4 | OPEN | HIGH | sugar-crush/src/Chat.php | chat-input | S |
| E5 | OPEN | HIGH | sugar-crush/src/Renderer.php | tui-render | S |
| E9 | STALE-CITATION | MED | sugar-crush/README.md;docs/plans/crush_code_worklog.md⚠ε | docs | M |
| E10 | OPEN | HIGH | sugar-crush/src/Tools/BuiltIn/Doctor.php;sugar-crush/tests/Tools/BuiltInToolTest.php⚠β⚠ε | tools-skills | M |
| E16 | OPEN | MED | sugar-crush/src/Runtime.php;sugar-crush/src/Hooks/HookManager.php | tools-skills | M |
| E17 | OPEN | HIGH | sugar-crush/src/Providers/CompleteResponse.php;sugar-crush/src/Context/ContextCompactor.php;sugar-crush/src/Chat.php⚠γ | providers | M |
| E20 | OPEN | HIGH | sugar-crush/src/Chat.php;sugar-crush/src/Backend/EngineBackend.php⚠γ | providers | M |
| E25 | PARTIAL | MED | sugar-crush/src/Context/MemoryBlock.php;sugar-crush/tests/Context/MemoryBlockTest.php⚠ε | other | M |
| E36 | UNCERTAIN | LOW | sugar-crush/tests/Cli/BootstrapSkillSkipsTest.php⚠ε | tests-harness | S |
| E42 | PARTIAL | HIGH | sugar-crush/src/Tools/McpToolBridge.php⚠ε | lsp-mcp | S |
| E43 | PARTIAL | HIGH | sugar-crush/src/Renderer.php | tui-render | S |
| E45 | PARTIAL | MED | sugar-crush/src/Cli/Bootstrap.php⚠α⚠β | cli-config | S |
| E54 | PARTIAL | HIGH | sugar-crush/src/Tui/AgentViewPane.php;sugar-crush/src/Renderer.php;sugar-crush/src/Tui/Renderer.php;sugar-crush/src/Tui/Components/AgentDashboardPane.php⚠β⚠δ | tui-render | M |
| E74 | PARTIAL | MED | sugar-crush/README.md;sugar-crush/src/Config/LayeredSettings.php;sugar-crush/src/Cli/Bootstrap.php;sugar-crush/docs/SETTINGS.md⚠α⚠β⚠ε | cli-config | M |
| E78c | OPEN | MED | sugar-crush/src/Cli/NonInteractive.php;sugar-crush/src/Cli/Bootstrap.php⚠α⚠β | cli-config | M |
| E93 | OPEN | MED | sugar-crush/src/Skills/SkillRegistry.php;sugar-crush/src/Util/PathGlob.php | tools-skills | M |
| E107 | OPEN | LOW | UNKNOWN(re-derive) | other | S |
| E111 | PARTIAL | MED | sugar-crush/tests/Config/EnvRosterDriftTest.php;sugar-crush/docs/SETTINGS.md⚠ε | tests-harness | M |
| E125 | PARTIAL | HIGH | sugar-crush/tests/Config/ConfigWriteProducerDocumentationDriftTest.php;sugar-crush/tests/Config/ThemePersistenceFramingTest.php;sugar-crush/tests/Config/GlobFigureDriftTest.php;sugar-crush/tests/Chat/ChatConfigChangeDoorsDocumentationDriftTest.php⚠ε | tests-harness | M |
| E127 | OPEN | HIGH | sugar-crush/tests/Config/Support/EnvReadScanner.php⚠ε | tests-harness | S |
| E134 | PARTIAL | MED | docs/plans/crush_code_worklog.md⚠ε | docs | S |
| E140 | OPEN | HIGH | sugar-crush/src/Support/ToolIpcFiles.php;sugar-crush/src/Runtime.php | tools-skills | M |
| E143 | OPEN | HIGH | sugar-crush/tests/Config/GlobFigureDriftTest.php⚠ε | tests-harness | S |
| E144 | OPEN | HIGH | sugar-crush/tests/Chat/ChatConfigChangeDoorsDocumentationDriftTest.php;sugar-crush/tests/Config/GlobFigureDriftTest.php;sugar-crush/tests/Config/ConfigWriteProducerDocumentationDriftTest.php;sugar-crush/tests/Config/ThemePersistenceFramingTest.php⚠ε | tests-harness | M |
| E148 | OPEN | HIGH | sugar-crush/tests/Config/EnvRosterDriftTest.php⚠ε | tests-harness | S |
| E149 | OPEN | HIGH | sugar-crush/tests/Config/EnvRosterDriftTest.php⚠ε | tests-harness | S |
| E154 | PARTIAL | HIGH | sugar-crush/tests/Cli/StderrEmitterCensusTest.php⚠δ⚠ε | tests-harness | S |
| E158 | PARTIAL | HIGH | sugar-crush/tests/Integration/BinSugarcrushAutoloadGuardTest.php⚠ε | tests-harness | S |
| E165 | OPEN | MED | sugar-crush/tests/Config/ReadmeRosterDriftTest.php⚠ε | tests-harness | S |
| E172 | PARTIAL | HIGH | sugar-crush/src/Commands/CommandLoader.php⚠γ | tools-skills | S |
| E174 | PARTIAL | HIGH | sugar-crush/tests/Support/FlattensSourceProseTrait.php;sugar-crush/tests/Support/DropsInsignificantTokensTrait.php;sugar-crush/tests/Cli/HeadlessPermissionPromptAttachmentTest.php;sugar-crush/tests/Support/ChildStderrCaptureScanner.php;sugar-crush/tests/Support/ChildLifetimeScanner.php⚠ε | tests-harness | L |
| E175 | OPEN | HIGH | sugar-crush/src/Chat.php | chat-input | S |
| E176 | PARTIAL | MED | sugar-crush/tests/Support/ChildStderrCaptureTest.php;sugar-crush/src/Cli/NonInteractive.php⚠ε | cli-config | M |
| E194 | OPEN | HIGH | sugar-crush/phpunit.xml;sugar-crush/tests/⚠ε | tests-harness | M |
| E195 | OPEN | HIGH | sugar-crush/tests/Cli/StderrEmitterCensusTest.php⚠δ⚠ε | tests-harness | S |
| E199 | OPEN | HIGH | sugar-crush/src/Diagnostics/RuntimeNoticeSink.php | cli-config | S |
| E204 | OPEN | MED | docs/plans/crush_code_RESUME.md;docs/plans/crush_code_hardening_backlog.md⚠ε | docs | M |
| E205 | OPEN | HIGH | sugar-crush/tests/Support/ChildStderrCaptureScanner.php;sugar-crush/tests/Support/ChildStderrCaptureTest.php⚠ε | tests-harness | M |
| E208 | OPEN | HIGH | sugar-crush/tests/Support/ForkedChildExitScanner.php;sugar-crush/tests/Support/TokenFunctionRanges.php⚠ε | tests-harness | M |
| E214 | PARTIAL | HIGH | sugar-crush/tests/Cli/BootstrapLaunchFormatConstantsTest.php⚠ε | tests-harness | S |
| E228 | PARTIAL | MED | sugar-crush/tests/Cli/StderrEmitterCensusTest.php⚠δ⚠ε | tests-harness | S |
| E235 | PARTIAL | HIGH | sugar-crush/tests/Support/ChildStderrCaptureTest.php⚠ε | tests-harness | S |
| E242 | OPEN | HIGH | sugar-crush/tests/bootstrap.php⚠ε | tests-harness | S |
| E246 | PARTIAL | MED | sugar-crush/src/Runtime.php;sugar-crush/tests/DenialPrefixRosterTest.php⚠ε | tools-skills | M |
| E257 | PARTIAL | MED | sugar-crush/tests/Integration/BinSugarcrushWiringTest.php;sugar-crush/tests/Tools/BuiltInToolCorpusTest.php⚠β⚠ε | tests-harness | M |
| E258 | OPEN | HIGH | sugar-crush/tests/Cli/StderrEmitterCensusTest.php;sugar-crush/src/Providers/ProviderFactory.php⚠γ⚠δ⚠ε | providers | M |
| E259 | OPEN | HIGH | sugar-crush/src/Agents/WorktreeManager.php;sugar-crush/tests/Cli/StderrEmitterCensusTest.php⚠δ⚠ε | agents | M |
| E261 | PARTIAL | HIGH | sugar-crush/src/Agents/AgentWorkerPool.php⚠γ | agents | S |
| E267 | PARTIAL | MED | sugar-crush/tests/Cli/StderrEmitterCensusTest.php⚠δ⚠ε | tests-harness | S |
| E269 | OPEN | HIGH | sugar-crush/tests/Cli/StderrEmitterCensusTest.php⚠δ⚠ε | tests-harness | S |
| E270 | PARTIAL | MED | sugar-crush/tests/Cli/StderrEmitterCensusTest.php⚠δ⚠ε | tests-harness | S |
| E272 | PARTIAL | MED | sugar-crush/tests/Support/DuplicatedTestHelperDriftTest.php⚠ε | tests-harness | S |
| E281 | OPEN | HIGH | sugar-crush/tests/Agents/TeamTest.php⚠ε | tests-harness | S |
| E283 | PARTIAL | MED | sugar-crush/tests/Support/InterpolationOpenerTokenTest.php;sugar-crush/tests/Support/ChildStderrCaptureTest.php⚠ε | tests-harness | M |
| E291 | PARTIAL | HIGH | sugar-crush/src/Sessions/BackgroundSessionRunner.php;sugar-crush/tests/Support/ForkedChildExitConventionTest.php⚠γ⚠δ⚠ε | sessions | M |
| E309 | OPEN | HIGH | sugar-crush/tests/DenialPrefixRosterTest.php⚠ε | tools-skills | S |
| E319 | PARTIAL | HIGH | sugar-crush/tests/Support/ForkedChildTest.php;sugar-crush/tests/ChatTest.php;sugar-crush/tests/Backend/EngineBackendTest.php;sugar-crush/tests/Support/SuiteChildStdinIsolationTest.php;sugar-crush/tests/bootstrap.php⚠ε | tests-harness | L |
| E322 | PARTIAL | MED | sugar-crush/tests/TtyStreamArgumentCensusTest.php⚠ε | tests-harness | S |
| E325 | OPEN | HIGH | sugar-crush/tests/Support/ReflectionLineSliceReaderCensusTest.php;sugar-crush/tests/Cli/HelpTest.php;sugar-crush/tests/VhsTapeContractTest.php⚠ε | tests-harness | M |
| E331 | PARTIAL | HIGH | sugar-crush/tests/Support/DropsInsignificantTokensTrait.php;sugar-crush/tests/Config/ReadmeJsonErrorContractDriftTest.php⚠ε | tests-harness | M |
| E342 | OPEN | HIGH | sugar-crush/tests/Support/ForkedChildTest.php;sugar-crush/tests/ChatTest.php;sugar-crush/tests/Backend/EngineBackendTest.php⚠ε | tests-harness | M |
| E343 | OPEN | MED | sugar-crush/tests/Support/ChildStderrCaptureTest.php;sugar-crush/tests/Support/SuiteChildStdinIsolationTest.php⚠ε | tests-harness | M |
| E347 | PARTIAL | MED | sugar-crush/tests/Cli/RefusalStderrSurfaceTest.php;sugar-crush/src/Permissions/DenialKind.php⚠ε | tools-skills | M |
| E353 | OPEN | MED | sugar-crush/docs/HOOKS.md | docs | S |
| E356 | PARTIAL | MED | sugar-crush/tests/Support/DropsInsignificantTokensTrait.php;sugar-crush/tests/Config/ReadmeJsonErrorContractDriftTest.php⚠ε | tests-harness | M |
| E357 | OPEN | HIGH | sugar-crush/tests/Config/DocumentParagraphsTest.php;sugar-crush/tests/Config/ConfigWriteProducerDocumentationDriftTest.php;sugar-crush/tests/Config/GlobFigureDriftTest.php;sugar-crush/tests/Support/DuplicatedTestHelperDriftTest.php⚠ε | tests-harness | M |
| E358 | PARTIAL | HIGH | sugar-crush/tests/Support/SuiteChildStdinIsolationTest.php;sugar-crush/tests/Support/ForkedChildTest.php;sugar-crush/tests/ChatTest.php;sugar-crush/tests/Backend/EngineBackendTest.php⚠ε | tests-harness | M |
| E366 | PARTIAL | MED | sugar-crush/tests/Support/DescriptorInheritanceGuardTest.php;sugar-dash/src/Plugin/ExternalModule.php⚠ε | sibling:sugar-dash | M |
| E369 | OPEN | HIGH | sugar-crush/tests/StdinConstantReaderCensusTest.php⚠ε | tests-harness | S |
| E370 | OPEN | MED | candy-core/tests/Util/ClosedDescriptorZeroFamilyTest.php | sibling:candy-core | S |
| E375 | OPEN | MED | sugar-crush/src/Permissions/DenialKind.php | tools-skills | S |
| E376 | OPEN | HIGH | sugar-crush/src/Cli/HeadlessPermissionPrompt.php | cli-config | S |
| E378 | OPEN | HIGH | sugar-crush/tests/Support/ForkedChildReaperAdoptionTest.php⚠ε | tests-harness | S |
| E386 | OPEN | HIGH | sugar-crush/tests/Config/DocumentParagraphsTest.php;sugar-crush/tests/Config/GlobFigureDriftTest.php;sugar-crush/tests/Config/ConfigWriteProducerDocumentationDriftTest.php;sugar-crush/tests/Support/DuplicatedTestHelperDriftTest.php⚠ε | tests-harness | M |
| E390 | PARTIAL | HIGH | sugar-crush/tests/Support/RequirementDirectiveProvenanceTest.php;sugar-crush/tests/Cli/BootstrapSkillSkipsTest.php⚠ε | tests-harness | M |
| E391 | OPEN | HIGH | sugar-crush/tests/Support/RequirementDirectiveProvenanceTest.php⚠ε | tests-harness | S |
| E417 | OPEN | HIGH | sugar-crush/tests/Support/DescriptorInheritanceGuardTest.php⚠ε | tests-harness | S |
| E419 | OPEN | MED | sugar-crush/tests/Support/ChildLifetimeScanner.php;sugar-crush/tests/Support/DescriptorInheritanceGuardTest.php;sugar-crush/src/Agents/ProcessExecutor.php⚠γ⚠ε | tests-harness | M |
| E424 | OPEN | HIGH | sugar-crush/tests/Support/ChildLifetimeScannerFixtureTest.php;sugar-crush/tests/Support/DescriptorInheritanceGuardTest.php;sugar-crush/src/Support/ProcessReaper.php⚠δ⚠ε | tests-harness | M |
| E440 | PARTIAL | HIGH | sugar-crush/src/MCP/StdioMcpServer.php⚠γ⚠δ | lsp-mcp | S |
| E445 | OPEN | HIGH | sugar-crush/tests/Support/ForkedChildExitConventionTest.php;sugar-crush/tests/Support/ForkedChildReaperAdoptionTest.php⚠ε | tests-harness | M |
| E447 | OPEN | HIGH | candy-core/src/Program.php;sugar-crush/tests/Support/DescriptorInheritanceGuardTest.php⚠ε | sibling:candy-core | M |
| E448 | OPEN | HIGH | sugar-crush/tests/Support/ChildLifetimeScanner.php;sugar-dash/src/Plugin/ExternalModule.php;sugar-reel/src/Decode/FfmpegDecoder.php;sugar-reel/src/AudioPlayer.php⚠ε | sibling:sugar-reel | M |
| E466 | OPEN | HIGH | candy-pty/src/Posix/PosixMasterPty.php | sibling:candy-pty | S |
| E469 | OPEN | HIGH | sugar-crush/tests/SuiteSkipRosterTest.php⚠ε | tests-harness | S |
| E481 | PARTIAL | HIGH | sugar-crush/tests/Support/DuplicatedTestHelperDriftTest.php;sugar-crush/tests/LSP/LspConnectionShutdownTest.php;sugar-crush/tests/MCP/StdioMcpServerWriteBoundsTest.php⚠ε | tests-harness | M |
| E486 | OPEN | HIGH | tools/;.github/workflows/ci.yml⚠α | ci-infra | M |
| E493 | OPEN | HIGH | sugar-crush/src/Backend/EngineBackend.php;sugar-crush/src/Runtime.php⚠γ | providers | M |
| E505 | PARTIAL | MED | sugar-crush/tests/ClaudeCodeMcpClientStdinWedgeTest.php;sugar-crush/tests/LSP/LspConnectionStdinWedgeTest.php;sugar-crush/tests/MCP/StdioMcpServerShutdownTest.php;sugar-crush/tests/Integration/McpToolWiringTest.php⚠ε | tests-harness | M |
| E541 | PARTIAL | MED | sugar-crush/tests/DenialPrefixRosterTest.php⚠ε | tools-skills | S |
| E547 | PARTIAL | HIGH | sugar-crush/tests/Backend/StreamingCommandBackendTest.php⚠ε | tests-harness | S |
| E564 | OPEN | HIGH | sugar-crush/src/Context/RepoMapBlock.php | other | S |
| E565 | PARTIAL | MED | sugar-crush/tests/Support/DuplicatedTestHelperDriftTest.php⚠ε | tests-harness | S |
| E566 | OPEN | MED | sugar-crush/tests/⚠ε | tests-harness | S |
| E572 | OPEN | HIGH | sugar-crush/tests/SwallowingCatchCensusTest.php⚠ε | tests-harness | S |
| E577 | OPEN | HIGH | sugar-crush/tests/Support/AssertionSwallowingCatchTest.php⚠ε | tests-harness | S |
| E578 | OPEN | HIGH | sugar-crush/tests/SwallowingCatchCensusTest.php⚠ε | tests-harness | S |
| E583 | OPEN | HIGH | sugar-crush/tests/;sugar-crush/tests/SymbolCitationDriftTest.php⚠ε | tests-harness | M |
| E609 | OPEN | HIGH | sugar-crush/tests/Backend/BackendSignatureNullabilityTest.php;sugar-crush/tests/Support/DuplicatedDocBlockLineTest.php⚠ε | tests-harness | M |
| E610 | OPEN | HIGH | sugar-crush/tests/Support/DuplicatedTestHelperDriftTest.php⚠ε | tests-harness | S |
| E611 | OPEN | MED | UNKNOWN(re-derive) | other | S |
| E612 | OPEN | HIGH | sugar-crush/tests/SwallowingCatchCensusTest.php⚠ε | tests-harness | S |
| E615 | OPEN | HIGH | sugar-crush/tests/SwallowingCatchCensusTest.php;sugar-crush/tests/Support/AssertionSwallowingCatchTest.php⚠ε | tests-harness | M |
| E616 | OPEN | HIGH | sugar-crush/tests/DenialPrefixRosterTest.php⚠ε | tools-skills | S |
| E617 | OPEN | HIGH | sugar-crush/tests/Support/AssertionSwallowingCatchTest.php⚠ε | tests-harness | S |
| E629 | OPEN | HIGH | tools/tests/CheckPathReposTest.php | ci-infra | S |
| E633 | PARTIAL | HIGH | sugar-crush/src/Context/RepoMapBlock.php | other | S |
| E653 | PARTIAL | HIGH | sugar-crush/src/Cli/Bootstrap.php;sugar-crush/tests/Cli/BootstrapLaunchNoticeRoutingTest.php;sugar-crush/tests/Cli/BootstrapToolAndPermissionSettingsTest.php;sugar-crush/tests/Cli/BootstrapTranscriptSeamCallSiteCensusTest.php;sugar-crush/tests/Cli/StderrEmitterCensusTest.php⚠α⚠β⚠δ⚠ε | agents | L |
| E657 | OPEN | MED | sugar-crush/src/Providers/SglangProvider.php;sugar-crush/src/Providers/VertexProvider.php;sugar-crush/src/Runtime.php⚠γ | providers | M |
| E658 | OPEN | LOW | sugar-crush/tests/⚠ε | tests-harness | S |
| E659 | OPEN | MED | sugar-crush/tests/Tools/BuiltInToolCorpusTest.php;sugar-crush/tests/Context/RepoMapBlockTest.php;sugar-crush/tests/SymbolCitationDriftTest.php⚠β⚠ε | tests-harness | M |
| E662 | OPEN | LOW | sugar-crush/tests/Tools/BuiltInToolCorpus.php⚠β⚠ε | tests-harness | S |
| E670 | OPEN | LOW | sugar-crush/tests/Workflows/WorkflowProviderHandoffTest.php⚠ε | workflows | S |
| E671 | OPEN | MED | scripts/parallel-tests.sh;.github/workflows/ci.yml⚠α | test-infra | M |
| E672 | OPEN | HIGH | sugar-crush/src/Hooks/ScriptHook.php;sugar-crush/src/Agents/ProcessExecutor.php;sugar-crush/src/Agents/AgentWorkerPool.php;sugar-crush/src/MCP/StdioMcpServer.php;sugar-crush/src/Backend/;sugar-crush/src/Sessions/;sugar-crush/src/Workflows/WorkflowEngine.php;sugar-crush/src/LSP/;sugar-crush/src/Providers/;sugar-crush/src/Config/StatusLineCommand.php;sugar-crush/src/Commands/⚠γ⚠δ | containment | L |
| E673 | OPEN | MED | sugar-crush/src/Tools/Concerns/CapturesProcessOutput.php;sugar-crush/src/Support/ProcessReaper.php⚠δ⚠ε | containment | M |
| E674 | OPEN | LOW | sugar-crush/src/Tools/Concerns/CapturesProcessOutput.php⚠ε | containment | S |
| E675 | OPEN | HIGH | sugar-crush/src/Cli/Bootstrap.php;sugar-crush/src/Tools/TaskTool.php;sugar-crush/src/Tui/Renderer.php;sugar-crush/tests/Tools/⚠α⚠β⚠δ⚠ε | tools-skills | M |
| E676 | OPEN | MED | sugar-crush/src/Hooks/ScriptHook.php;sugar-crush/src/Config/StatusLineCommand.php;sugar-crush/src/Sessions/BackgroundSessionRunner.php;sugar-crush/src/Support/ProcessReaper.php⚠γ⚠δ | containment | M |
| E677 | OPEN | MED | sugar-crush/src/MCP/StdioMcpServer.php;sugar-crush/src/Backend/StreamingCommandBackend.php⚠γ⚠δ | lsp-mcp | M |
| E678 | OPEN | MED | sugar-crush/src/Tools/McpToolBridge.php;sugar-crush/src/Cli/Subcommands.php;sugar-crush/src/Tui/⚠δ⚠ε | cli-config | M |
| E679 | OPEN | LOW | sugar-crush/src/Agents/TaskList.php;sugar-crush/src/Agents/WorktreeManager.php⚠δ | agents | M |
| E680 | OPEN | MED | sugar-crush/src/Session.php⚠δ | sessions | S |
| E681 | OPEN | HIGH | sugar-crush/src/Chat.php;sugar-crush/tests/Commands/⚠ε | chat-input | M |
| E682 | OPEN | MED | sugar-crush/src/Renderer.php;sugar-crush/src/App.php | tui-render | M |
| E683 | OPEN | LOW | sugar-crush/src/Commands/KeyBindingRegistry.php;sugar-crush/src/Renderer.php⚠γ | tui-render | M |
| E684 | OPEN | HIGH | sugar-crush/src/Cli/Bootstrap.php;sugar-crush/tests/Cli/BootstrapLaunchFormatConstantsTest.php⚠α⚠β⚠ε | cli-config | M |
| E685 | OPEN | HIGH | sugar-crush/src/Tools/⚠ε | tools-skills | S |
| E686 | OPEN | MED | sugar-crush/tests/;sugar-crush/docs/;sugar-crush/README.md⚠ε | tests-harness | M |
| E687 | OPEN | MED | sugar-crush/tests/Cli/StderrEmitterCensusTest.php;sugar-crush/src/Agents/AgentWorkerPool.php⚠γ⚠δ⚠ε | agents | M |
## Domain → ids index (sorted by count)

> Round-63 closeout re-derivation: 32 ids left actionable (29 CLOSED + E41 CLOSED from PARTIAL + the
> 3 → PARTIAL moves net out), E43/E633 re-annotated in place, E672–E687 added (16). `⚠α..⚠ε` = id shares
> at least one file with the PROPOSED round-64 lane ownership set (§0-NOW-65 §2) — merge or serialize
> the marked lanes where they collide.

| domain | n | ids (⚠ = proposed-lane file collision) |
|---|---:|---|
| tests-harness | 64 | E36⚠ε, E111⚠ε, E125⚠ε, E127⚠ε, E143⚠ε, E144⚠ε, E148⚠ε, E149⚠ε, E154⚠δ⚠ε, E158⚠ε, E165⚠ε, E174⚠ε, E194⚠ε, E195⚠δ⚠ε, E205⚠ε, E208⚠ε, E214⚠ε, E228⚠δ⚠ε, E235⚠ε, E242⚠ε, E257⚠β⚠ε, E267⚠δ⚠ε, E269⚠δ⚠ε, E270⚠δ⚠ε, E272⚠ε, E281⚠ε, E283⚠ε, E319⚠ε, E322⚠ε, E325⚠ε, E331⚠ε, E342⚠ε, E343⚠ε, E356⚠ε, E357⚠ε, E358⚠ε, E369⚠ε, E378⚠ε, E386⚠ε, E390⚠ε, E391⚠ε, E417⚠ε, E419⚠γ⚠ε, E424⚠δ⚠ε, E445⚠ε, E469⚠ε, E481⚠ε, E505⚠ε, E547⚠ε, E565⚠ε, E566⚠ε, E572⚠ε, E577⚠ε, E578⚠ε, E583⚠ε, E609⚠ε, E610⚠ε, E612⚠ε, E615⚠ε, E617⚠ε, E658⚠ε, E659⚠β⚠ε, E662⚠β⚠ε, E686⚠ε |
| tools-skills | 13 | E10⚠β⚠ε, E16, E93, E140, E172⚠γ, E246⚠ε, E309⚠ε, E347⚠ε, E375, E541⚠ε, E616⚠ε, E675⚠α⚠β⚠δ⚠ε, E685⚠ε |
| cli-config | 8 | E45⚠α⚠β, E74⚠α⚠β⚠ε, E78c⚠α⚠β, E176⚠ε, E199, E376, E678⚠δ⚠ε, E684⚠α⚠β⚠ε |
| tui-render | 5 | E5, E43, E54⚠β⚠δ, E682, E683⚠γ |
| providers | 5 | E17⚠γ, E20⚠γ, E258⚠γ⚠δ⚠ε, E493⚠γ, E657⚠γ |
| other | 5 | E25⚠ε, E107, E564, E611, E633 |
| agents | 5 | E259⚠δ⚠ε, E261⚠γ, E653⚠α⚠β⚠δ⚠ε, E679⚠δ, E687⚠γ⚠δ⚠ε |
| chat-input | 4 | E3, E4, E175, E681⚠ε |
| docs | 4 | E9⚠ε, E134⚠ε, E204⚠ε, E353 |
| containment | 4 | E672⚠γ⚠δ, E673⚠δ⚠ε, E674⚠ε, E676⚠γ⚠δ |
| lsp-mcp | 3 | E42⚠ε, E440⚠γ⚠δ, E677⚠γ⚠δ |
| sessions | 2 | E291⚠γ⚠δ⚠ε, E680⚠δ |
| sibling:candy-core | 2 | E370, E447⚠ε |
| ci-infra | 2 | E486⚠α, E629 |
| sibling:sugar-dash | 1 | E366⚠ε |
| sibling:sugar-reel | 1 | E448⚠ε |
| sibling:candy-pty | 1 | E466 |
| workflows | 1 | E670⚠ε |
| test-infra | 1 | E671⚠α |

## Cross-file collision clusters (≥3 actionable ids sharing a file)

- **`sugar-crush/tests/Cli/StderrEmitterCensusTest.php`** — E154, E195, E228, E258, E259, E267, E269, E270, E653, E687
- **`sugar-crush/src/Chat.php`** — E3, E4, E17, E20, E175, E681
- **`sugar-crush/src/Cli/Bootstrap.php`** — E45, E74, E78c, E653, E675, E684
- **`sugar-crush/tests/Support/DuplicatedTestHelperDriftTest.php`** — E272, E357, E386, E481, E565, E610
- **`sugar-crush/src/Renderer.php`** — E5, E43, E54, E682, E683
- **`sugar-crush/src/Runtime.php`** — E16, E140, E246, E493, E657
- **`sugar-crush/tests/Config/GlobFigureDriftTest.php`** — E125, E143, E144, E357, E386
- **`sugar-crush/tests/Support/ChildStderrCaptureTest.php`** — E176, E205, E235, E283, E343
- **`sugar-crush/tests/`** — E194, E566, E583, E658, E686
- **`sugar-crush/tests/Support/DescriptorInheritanceGuardTest.php`** — E366, E417, E419, E424, E447
- **`sugar-crush/tests/Config/ConfigWriteProducerDocumentationDriftTest.php`** — E125, E144, E357, E386
- **`sugar-crush/tests/DenialPrefixRosterTest.php`** — E246, E309, E541, E616
