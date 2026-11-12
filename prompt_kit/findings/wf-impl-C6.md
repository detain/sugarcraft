# wf-impl track C6 — E2's corrections turned into fixes: `tools: []`, key rosters, `stopOnFirstFailure`, `:=` JSON context, linter writer check, skill

Files touched: `src/Workflows/{WorkflowTask,TaskBuilder,WorkflowEngine,WorkflowRegistry,WorkflowGrammarGlossary,WorkflowLinter,RunContextValue(new)}.php`, `src/Agents/{SubAgent,EngineExecutor,AgentManager}.php`, `src/Host/Commands/WorkflowCommand.php`, `src/Cli/Subcommands.php` (workflow verb's `--param` splitter only), `src/Skills/BuiltIn/workflow-authoring/SKILL.md`, `examples/workflows/{fix-wave,judge-panel,review-changes}.yaml`.

## 1. What was implemented

### Task 1 · `tools: []` means NO tools (all paths)
- **Semantics.** `tools:` omitted = the session's tools (unchanged). `tools: []` written = the agent gets **no tools**. A schema'd agent (`outputSchema`) still gets its `StructuredOutput` answer channel. An explicit `[]` beats a preset's tools (`preset:` + `tools: []` = none); only an ABSENT `tools:` takes the preset's.
- **Mechanism.** `WorkflowTask` gains `bool $toolsSet = false` (trailing) + `declaresNoTools()` (= toolsSet && tools === []). `TaskBuilder::tools()` sets it (so PHP `->tools([])` also means none). `SubAgent` gains `bool $toolless = false` (trailing); every `new SubAgent` in the engine (single/tasks/pipeline step, verification task + verifier + panel votes, parallel + `from:` fan-out + map steps via the same sites) passes `toolless: $task->declaresNoTools()`. The request still carries `tools: null` (the "`[]` never travels to a provider" doctrine holds). `EngineExecutor` (both `run()` and `forkRefusal()`) uses `[]` instead of the session set when `$agent->toolless` — this covers inline, forked (ask-free fork of the Workflow tool and the TUI/headless `forkedExecutor`) and the AgentManager path (whose grant resolver returns null for silent agents). `AgentManager::grantRefusalFor()` refuses every call but `StructuredOutput` for a toolless SubAgent: `is refused: agent "<name>" declares tools: [] (no tools)` (per-call backstop via `SubAgentGrantHook`). `mergePreset()` only fills tools when `!$task->toolsSet`.
- **Plans relying on the old meaning:** none in `examples/`, `workflows/`, `.candy-code/`. One test used `->tools([])` as "no declaration": `tests/Workflows/StageArgumentScopedGrantTest::testAStageWithNoDeclarationIsNotPolicedByTheGrant` now omits `tools`. Example headers (judge-panel, review-changes) that said "`[]` = the session's tools" were corrected.
- Tests: `tests/Workflows/WorkflowToollessTaskTest.php` (8, real `EngineExecutor` + scripted provider): `[]` cannot call Read/Bash and puts no tool block on the wire; no `tools:` key can; schema'd `[]` gets only StructuredOutput; parallel per-agent (`[]` / `[Read]` / absent); `from:` fan-out + verifier panel votes; `[]` overrides a preset, absent takes it; PHP builder; grant-hook refusal. Verified load-bearing (5 fail with the executor change reverted).

### Task 2 · Unknown top-level / `config:` keys refused
- Rosters: `WorkflowRegistry::YAML_WORKFLOW_KEYS = [name, description, config, schemas, stages]`, `WorkflowRegistry::YAML_CONFIG_KEYS = [timeout, maxConcurrent, maxAgents, maxTokens, maxCost, stopOnFirstFailure]` (public). Both checked at load, first offender named:
  - `Workflow file <src> has unknown top-level key "<key>"; supported keys: name, description, config, schemas, stages`
  - `Workflow file <src> "config" has unknown key "<key>"; supported keys: timeout, maxConcurrent, maxAgents, maxTokens, maxCost, stopOnFirstFailure`
- Applies to every door (saved YAML, Workflow tool `plan`, `uses:` includes, headless). Every shipped/test YAML still loads (checked).
- Linter mirror: not applicable — unknown keys never survive load, and the linter works on the loaded `Workflow`.
- `WorkflowGrammarGlossary::WORKFLOW_KEYS` is now drift-pinned to `YAML_WORKFLOW_KEYS`; the `config` gloss and `planDescription()` list come from / are pinned to `YAML_CONFIG_KEYS`, and say other keys are refused.
- Tests: `tests/Workflows/WorkflowTopLevelKeyRosterTest.php` (9: both refusals, every roster key loads, defaults, strict-bool provider, roster-is-read source guard); `tests/Tools/WorkflowToolDescriptionDriftTest.php` +2 (`testEveryTopLevelKeyHasAGloss…`, `testEveryConfigKeyIsGlossedAndInThePlanSchema`).

### Task 3 · `config.stopOnFirstFailure`
- YAML `config: {stopOnFirstFailure: true|false}`, strict bool (like `capture:`): anything else → `Workflow file <src> "config.stopOnFirstFailure" must be a boolean (true or false, unquoted), got <type>` (`'yes'`, `1`, `'true'`, `~` all refused). Absent = false, byte-identical. Wires `WorkflowBuilder::stopOnFirstFailure()` (already existed). Semantics (engine, unchanged): a parallel stage cancels its remaining agents on the first failure; a map admits no item after its first casualty; a `minSuccess` stage is exempt.
- Glossary `config` line updated (drift test green).

### Task 4 · `key:=<json>` run context (TUI + headless)
- Shared helper `src/Workflows/RunContextValue.php` (`split()`, `decode()`, `JSON_OPERATOR = ':='`). `key=value` is unchanged (string). `key:=<json>` decodes the value as JSON (list, object, number, bool, null). Malformed → refused naming the key: `` `files:=` needs a JSON value (e.g. files:='["a","b"]'), got `[a` (Syntax error) ``. `@` keys still refused. A key whose `:` is not right before the first `=` (`a:b=1`) stays a plain string pair.
- `/workflow run` (`WorkflowCommand::parseRunArguments`, quote-aware as before): `/workflow run fix-wave files:='["src/A.php","src/B.php"]' spec=docs/brief.md`. The tokenizer strips quotes, so the JSON must be single-quoted; an unquoted `files:=["a","b"]` becomes `[a,b]` and is refused (not run on a mangled value). Problems join the existing "Malformed run arguments: …" refusal.
- Headless `--param` (`Subcommands::parseParamPairs`, repeated flags arrive comma-joined): inside a `:=` segment commas nested in `[]`/`{}` are data, `\"` inside a JSON string does not close it, and an outer single-quote wrapper is stripped (double quotes are kept — they are JSON). `candy-code workflow run fix-wave --param files:='["src/A.php","src/B.php"]' --param spec=docs/brief.md`.
- `examples/workflows/fix-wave.yaml` header now shows both command lines.
- Tests: `tests/Host/Commands/WorkflowCommandRunContextTest.php` +3; `tests/Cli/WorkflowSubcommandTest.php` +11 provider rows; `tests/Workflows/FixWaveRunContextTest.php` (3: TUI line and headless line both fan the shipped fix-wave out over 2 files end to end; plain `files=` is still refused as a string). Runs from a temp copy so `examples/workflows/.running/` stays clean.

### Task 5 · Linter shared-checkout warning
- `WorkflowLinter::checkIsolationSanity` warns only when **≥2 agents of a parallel stage can write AND are not `isolation: worktree`** (counts non-isolated writers; a lone writer or a writer in its own worktree no longer counts). "Can write" = `tools:` absent (session tools; also presets unresolved at lint), or a declared Write-class tool (`ToolPermissionClass::Write`: Bash, Edit, Write, ApplyPatch, NotebookEdit, …; the name half of `Bash(git *)` counts), or a name the catalog does not know (MCP bridge / glob — cannot be vouched for). `tools: []`, and Read/Ask/NoAsk-class tools (Read, Grep, Glob, WebFetch, WebSearch, Todo, …) never count. Deviation from the brief's "non-Read-class": Ask/NoAsk tools never touch the checkout, so treating WebFetch as a writer would keep research panels warning.
- New message: `N agents in this parallel stage can write the shared checkout (no worktree isolation); concurrent file edits can clobber each other. Narrow their tools to read-class ones or set isolation: worktree.`
- Examples now: judge-panel and research no longer warn; docs-drift-sweep, lint-then-fix and review-changes (`Bash(git *)` finders) still do. A `from:` fan-out has one template task, so it never warns (fix-wave header corrected).
- Tests: `WorkflowLinterTest::testOnlyAgentsThatCanWriteDrawTheContentionWarning` (new), existing contention test's assertion updated to the new wording.

### Task 6 · Skill
`src/Skills/BuiltIn/workflow-authoring/SKILL.md`: `tools` row (omitted = session, `[]` = none, StructuredOutput kept, overrides preset), preset row, run-context bullets (`key:=<json>` on both command lines, single-quoting), skeleton `config` gains `stopOnFirstFailure: false`, the "refuse, never coerce" bullet now covers top-level/config keys and lists both rosters, common-load-errors line, a shared-checkout-warning bullet, anti-pattern rewritten. Every YAML/JSON block parses; both full plans load + lint 0 errors (scratch check).

## 2. Not done / notes
- Lang usage strings (`lang/en.php` `host.workflow.usage.run` = `Usage: /workflow run <name> [key=val ...]`, and `cli.workflow.usage`) were not changed: not my files (see cross-track 2).
- `ProcessExecutor` (the fail-closed fabricating worker, not a production dispatch path) does not read `SubAgent::$toolless`.
- `planStageHash` covers the whole task DTO, so the new `toolsSet` field changes every stored plan run's stage hashes: a `resumeFrom` of a plan run recorded before this commit re-runs from stage 1 (no carry). Harmless, one-time.

## 3. Cross-track requests
1. **Docs (E3 / final docs pass) — `docs/WORKFLOWS.md`:** (a) `tools:` — omitted = session's tools, `[]` = no tools (StructuredOutput still available to a schema'd agent; any other call refused by the grant hook), explicit `[]` overrides a preset; remove any "`[]` = session's tools" text. (b) Top-level keys roster + `config:` keys roster, both refused when unknown, with the two error texts above; the "Defaults are … `stopOnFirstFailure: false`" line is now a real YAML key (strict bool, minSuccess exemption, map semantics). (c) `/workflow run … key:=<json>` and `--param key:=<json>` (quoting, comma rule, malformed refusal text); fix-wave now runnable from both. (d) Linter warning rule + new wording. Also `candy-code/README.md` / `docs/` anywhere `/workflow run <name> [key=val …]` is shown.
2. **`lang/*.php` owner:** `host.workflow.usage.run` → `Usage: /workflow run <name> [key=val | key:=json ...]`; `cli.workflow.usage` similarly for `--param`.
3. **suite-figure / `scripts/parallel-tests-durations.tsv`:** new test files `tests/Workflows/WorkflowToollessTaskTest.php` (8), `tests/Workflows/WorkflowTopLevelKeyRosterTest.php` (9 incl. 4 provider rows), `tests/Workflows/FixWaveRunContextTest.php` (3); tests added to `WorkflowToolDescriptionDriftTest` (+2), `WorkflowLinterTest` (+1), `WorkflowCommandRunContextTest` (+3), `WorkflowSubcommandTest` (+11 provider rows).
4. **ReadPathCensus owner:** `tests/Workflows/FixWaveRunContextTest` is test-only; no new `src/` read sites (RunContextValue reads nothing).

## 4. Commits
- `ba479bc39` tools: [] means no tools on every stage path, absent keeps the session's
- `845cc910d` unknown top-level and config: keys refused at load, config.stopOnFirstFailure YAML spelling
- `2b59da231` key:=<json> run context from /workflow run and headless --param; fix-wave runs from both command lines
- `6d22d4b53` linter warns about shared-checkout writers only when two agents can write
- `bf301dc6d` workflow-authoring skill matches C6
