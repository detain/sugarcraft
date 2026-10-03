# Impact-research brief (crush_report execution plan)

Shared brief for every file-impact research agent. Output feeds `17-execution-plan.md`.

## Context
- Roadmap: `prompt_kit/findings/crush-report/99-synthesis.md` Part III (waves 0–5), Part VIII (settings N-P0..P5, server/web O-0..O-8, sessions P-A..P-E + bugs B1–B3), Part IX (open items, live checks). Detailed designs: `13-settings-pane-and-configurability.md` (N), `14-server-mode-and-web-ui.md` (O), `16-sessions-and-live-agent-view.md` (P), `03-opencode-dcp.md` §13.2 (DCP self-pruning). Competitor recs in `00`–`12`.
- Code: `/home/sites/sugarcraft/sugar-crush` (master). Line numbers in the report may have drifted — re-locate by symbol.
- Already DONE (do not plan; if a roadmap item still claims one of these is missing, record it under "Stale claims"): maxToolSteps 1000 + loop guard; SGLang auto-discovery; attachments (`@file`, image paste); read-only second TUI with `/branch`; initial prompt from CLI words; prompt-cache marks on Vertex/Bedrock (live-request verification is still open); MCP trust pins; hooks refusal field.
- Hotspots (one owner per wave unless disjoint method regions): `src/Chat.php` (19k lines), `src/Runtime.php`, `src/Backend/EngineBackend.php`, `src/Cli/Bootstrap.php`, `src/Renderer.php`, `src/Providers/SglangProvider.php`, `src/Providers/CustomProvider.php`, `src/Permissions/PermissionGate.php`, `src/Workflows/WorkflowEngine.php`. Report any other file several of your steps touch.
- Doc drift guards (docs are test-pinned): `tests/Config/ReadmeRosterDriftTest.php`, `EnvRosterDriftTest.php`, `TrustKeyDocumentationDriftTest.php`, `ConfigWriteProducerDocumentationDriftTest.php`, `MemoryDocumentationDriftTest.php`, `PermissionsIntroDriftTest.php`, `GlobFigureDriftTest.php`, `ClaimFamiliesDocumentationDriftTest.php`, `tests/Commands/KeyBindingDriftTest.php`, `CommandsTableTakesColumnDriftTest.php`, `tests/SymbolCitationDriftTest.php`, `tests/Cli/HelpTest.php`, census tests (`*CensusTest.php`, `TreeWideGuardRosterTest.php`), `tests/Integration/SystemPromptWiringTest.php`, `ArchitectureAssemblyOrderTest.php`, `tests/BaseSystemPromptTest.php`. A new tool / slash command / env var / key binding / config key / CLI flag forces the matching README.md / docs/*.md edit. Name the doc file AND section.

## Rules
- READ-ONLY on source. The only file you write is your own `impact/<batch>.md`. No git writes, never `git stash`, never `pkill -f`.
- Verify each step against current source (grep/read). Status: OPEN, PARTIAL (say what's left), DONE (stale roadmap claim — cite evidence).
- For hotspot files give the method names (and current line ranges) each step edits, so the planner can split disjoint regions.
- Be concrete and terse. Tables over prose. Target ≤ 250 lines.

## Output format (`impact/<batch>.md`)
1. `# Impact: <batch>` + one-line scope.
2. **Summary table**: `| ID | Status | Size S/M/L | Depends on (IDs) | Hotspot regions (file::method) | Other files modified | New files (proposed paths, incl. tests) | Docs + drift tests forced | Cross-lib suites |`
3. Per-step notes (≤ 8 lines each): what is left, key risks, splits (if an L step should be split into sequential sub-steps, give sub-IDs like `3.B-1`, `3.B-2` with their own rows in the table).
4. **Stale claims**: roadmap text that is out of date, with evidence.
5. **Shared files**: files touched by ≥2 of your steps, with the region each step needs.
