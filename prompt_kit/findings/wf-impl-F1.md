# wf-impl track F1 — final docs touch-up for C6 (cross-track requests 1 and 2)

Every sentence checked against `candy-code/src/` (WorkflowRegistry, WorkflowLinter, RunContextValue,
WorkflowCommand::parseRunArguments, Subcommands::parseParamPairs, AgentManager::grantRefusalFor,
WorkflowEngine preset merge, NonInteractive::failUsage = exit 2).

## 1. Done
- `candy-code/docs/WORKFLOWS.md`
  - (a) `tools:` — new section "`tools:` — omitted means the session's tools, `[]` means none" (absent = session/preset; `[]` = none, grant-hook refusal text, StructuredOutput exception, explicit `[]` beats a preset). Schema block comment on `tools:`. Preset merge table row now "absent" only. Grant paragraph (Running from the CLI) and "What a stage agent may do" corrected.
  - (b) "Defaults are…" rewritten; both closed rosters with the two exact refusal texts; `config.stopOnFirstFailure` documented (strict bool + refusal text, parallel cancel, map admission, minSuccess exemption); schema block gains the key; "Everything malformed is refused" list gains top-level/config-key and stopOnFirstFailure entries.
  - (c) `key:=<json>` on `/workflow run` (single-quoting, `a:b=1` rule, malformed refusal text inside "Malformed run arguments") and headless `--param` (comma rule inside `[]`/`{}`, `\"`, outer single-quote stripping, exit 2 usage error); fix-wave command lines shown for both; `from: {context:}` bullet, interpolation row, `/workflow` synopsis and CLI synopsis updated.
  - (d) linter warning: list entry reworded + a paragraph with the exact message and the can-write rule.
- `candy-code/README.md`: headless synopsis shows `--param k:=json`; the "unpoliced" sentence notes `tools: []` grants none.
- `candy-code/docs/FLEET-DRIVING.md`: `--param 'k:=<json>'` mention.
- `candy-code/lang/*.php` (all 12 locales): `host.workflow.usage.run`, `host.workflow.help.run`, `cli.workflow.usage` syntax token → `[key=val | key:=json ...]` / `[--param k=v | --param k:=json ...]`; surrounding translations untouched.
- Root `docs/_data/candy-code.body.html` does not show the syntax — untouched, no gen-docs run needed.

## 2. Tests run (all green)
LangParityTest, DocFigureProseDriftTest, ReadmeRosterDriftTest, BootstrapLaunchFormatConstantsTest (initially red: a quoted `candy-code: ` prefix in the new `--param` text — rephrased), SymbolCitationDriftTest, WorkflowSubcommandTest, WorkflowRegistryTest, WorkflowCommandRunContextTest, WorkflowToolDescriptionDriftTest, KeyBindingDriftTest and the other tests/Config + tests/Cli doc readers. ReadmeSuiteFigureDriftTest not run (expected red).

## 3. Cross-track requests
None.

## 4. Commits
See `git log -- prompt_kit/findings/wf-impl-F1.md`.
