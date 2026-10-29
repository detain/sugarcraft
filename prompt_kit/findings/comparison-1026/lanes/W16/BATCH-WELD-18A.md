# BATCH-WELD-18A (welder-18a, 2026-10-29)

Base: master 6d0c79851 (ahead of origin a612fb008 by 2). Branch: weld-18 @ /home/sites/cc-cmp-weld-18.

## Status: CLOSED — master f5379f635 (meta 93cfa58b9 + run-state stamp), origin stays a612fb008 (no wave close → no push), ahead 13.

## Picks (all zero-conflict)
- [x] C-AGT-4.c: f48f62e1f→f51949469, b185db521→758c00418, 704e15fcf→b130d4dc3
- [x] C-PRV-3.b: 3d49d2488→0da9b5071, ee321219f→0d6280972
- [x] C-EXT-5.c: d69c9a430→3ba0a5ce8, 75257027d→d159b2cfd
  - NOTE: predicted KeyBindingRegistry.php conflict vs C-EXT-5.b (welded batch-16 @44ff31314) did NOT materialize — 5.b never touched that file (touched Chat.php/PaletteAction/STATUS/tests). Clean pick; re-gate filter still run per brief.

## Riders
- [x] 4f827ed71: protocol trio regen (docs/protocol/schema.json changed + generated.ts rewrote; SERVER.md unchanged — byte-identical gen) + candy-code-web dist rebuild committed ONCE + SymbolCitationDrift cure: Chat.php:13861 cite `\SugarCraft\Code\Agents\AgentPresetFieldCarryTest` → `\SugarCraft\Code\Tests\Agents\AgentPresetFieldCarryTest` (C-AGT-4.b debt; namespace verified vs tests/Agents/AgentPresetFieldCarryTest.php:5). Generators gen-command-docs/gen-tool-docs/gen-settings-doc --write: zero drift.
- [x] ee2c5b8f4: DUR rows x4, scripts/parallel-tests-durations.tsv 1614→1618 (sorted-by-duration insert; measured green targeted runs):
  - candy-code/tests/Chat/FastCommandTest.php 0.696/18 (OK 18T/113A)
  - candy-code/tests/App/CommandKeybindingDispatchTest.php 0.660/7 (OK 7T/30A)
  - candy-code/tests/Host/Commands/FastHostCommandTest.php 0.349/7 (OK 7T/23A)
  - candy-code/tests/Commands/KeyBindingCommandBindingTest.php 0.313/11 (OK 11T/43A)

## Gates
- [x] php merged filter 'SessionSummary|StatusBar|ServerContext|AgentSwitch|FastCommand|FastHostCommand|SlashDispatch|KeyBinding|KeysReload|SymbolCitationDrift|ReadmeRosterDrift|DocFigureProseDrift|ProtocolSchemaDrift|ServerProtocolDocumentationDrift' → OK 588 tests / 37,412 assertions (2 pre-existing PHPUnit deprecations).
- [x] vitest SERIAL --no-file-parallelism, 11 spec files (statusBar x3, stores/agents x2, components/agents x2, session/sessionDispose, generated.spec, dormantRoster.spec) → 90/90 passed. NOTE: composerQuickAdd.spec named in batch brief does NOT exist on disk (lead list approximate — disclosed).
- [x] npm run build re-run post-dist-commit → git status dist clean (determinism proven).
- Standing reds untouched per brief (suite-figure staleness pair, PermissionsIntroDriftTest:59, ForeignAgentPresetWiringTest:472, HelpTest/KeyHelp, BinCandyCodeDispatch --worktree, ForkedChildReaperAdoptionTest, ToolCapSettingsTest x2 + SettingsWriterCensus, MenuBar/CommandFailureTranscript (C-PRV-3.b foreign base-reds — stay with owners), BootstrapSkillSkipsTest 'Memory' window).

## Ledger
- [x] §4 marks in plan_comparison.md: C-EXT-5.c :2611, C-AGT-4.c :2627, C-PRV-3.b :2716 (✅ heading format per prior waves, pick maps inline).
- [x] Evidence: lanes/C-AGT-4.c, lanes/C-PRV-3.b, lanes/C-EXT-5.c full dirs committed; stale lanes/C-MCP-4.a/REPORT.md + REVIEW-1.md deletions staged in this batch (BRIEF.md kept — lane held).
- [x] run-state.json: welded += C-AGT-4.c C-PRV-3.b C-EXT-5.c (welded_count 227; weld_pending = C-CTX-3.c, C-MCP-3.d).
- [ ] No §3 wave closes this batch (W21/22/23/24 not fully welded) → NO PUSH. origin/master stays a612fb008.

## Prune — DONE (post-FF)
  [x] cc/C-AGT-4.c + /home/sites/cc-cmp-C-AGT-4.c
  [x] cc/C-PRV-3.b + /home/sites/cc-cmp-C-PRV-3.b
  [x] cc/C-EXT-5.c + /home/sites/cc-cmp-C-EXT-5.c
  [x] stray /home/sites/cc-cmp-C-EXT-5c-pristine
  [x] live lanes untouched: C-CTX-4.b C-PRV-4.b C-SES-7.g C-AGT-4.e C-SEC-8.f C-MCP-3.d C-CTX-3.c weld-16 weld-15 C-MCP-4.a (held)

## Absorption proofs (git cherry master <lane>)
- cc/C-PRV-3.b: 2/2 '-'
- cc/C-EXT-5.c: 2/2 '-'
- cc/C-AGT-4.c: 2/3 '-'; f48f62e1f '-' by CONTENT (git-cherry '+' = patch-id context drift — lane base 86f80fef8 vs weld-18 neighbor hunks in ReadPathCensusTest.php; diff of +/- lines vs pick f51949469 = IDENTICAL)
