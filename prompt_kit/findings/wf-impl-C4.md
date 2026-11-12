# wf-impl track C4: per-agent grant bug, P1-6 (`preset:`/`effort:`), P1-7 (budgets), P2-2 engine half (announcements)

Scope: `candy-code/src/Workflows/**`, `candy-code/tests/Workflows/**`, `candy-code/src/Agents/{AgentManager,AgentWorkerPool,EngineExecutor}.php`. No docs, README or `WorkflowTool.php` were touched.

## 1. What was implemented

### Bug: each parallel agent gets its own `tools:` grant (security)

**Before.** `executeParallelStage` computed `resolveRequestTools($task->tools)` for every task, then threw the result away. Every agent of the wave rode the default request, which carried the FIRST task's tool list. An agent declared `[Read]` beside a `[Read, Bash]` sibling was therefore offered Bash. In the reverse order, the Bash agent got none.

**Now.**
- The engine keeps a per-agent map, `$agentGrants[$subAgent->id]`, and passes it as `grantResolver()` (a closure that throws for an unknown id).
- Pool path: `AgentWorkerPool::executeAll($agents, $request, $grantResolver, …)`.
- Manager path: `AgentManager::executeAll(…, ?\Closure $fallbackGrantResolver = null)`. A manager WITH a tool registry still resolves each declaration itself. A manager without one now uses the engine's map. Before, every agent got the shared request.
- `SubAgentGrantHook` already judged calls against `$subAgent->agent->tools`. With this fix the request's offered tools and the hook's declaration agree for every agent.
- This covers parallel, `from:` (glob and data) and votes. Map and the per-item steps were already per-task.

**Tests.**
- `tests/Workflows/ParallelPerAgentGrantTest.php` has 3 tests: a reader beside a shell agent in both orders (the reader is offered only `Read`, and its Bash call never runs), and the fallback for a manager with no registry.
- `WorkflowEngineTest::testAParallelAgentIsHandedItsOwnToolsNotTheFirstTasks` replaces the test that pinned the bug.

### P1-6: task keys `preset:` and `effort:`

**Keys.** Both are task keys in `YAML_TASK_KEYS`, parsed in `applyTaskArms`, so every task surface accepts them (stage, parallel agent, pipeline step, verification task and verifier, map step).
- `preset: <name>`: an agent preset name, trimmed.
- `effort: low|medium|high|xhigh|max`: a closed set that maps to `Effort`, then to `SubAgent::$effort`, which `EngineExecutor` applies through `withReasoningEffort`.
- `agent:` stays a plain label.

**DTO.**
- `WorkflowTask::$preset` (?string) and `$effort` (?Effort).
- `WorkflowTask::with(array)` is a copy-with helper.
- `Workflow::withStages()`.

**Builder.**
- `TaskBuilder::preset(string)` refuses an empty name with `preset() needs a preset name, got an empty string`.
- `TaskBuilder::effort(Effort|string)`.
- `TaskBuilder::trustedEffort()` throws `effort must be one of low, medium, high, xhigh, max, got "<x>"`.

**Load refusals** (`WorkflowLoadException`):
- `… "preset" must name an agent preset (e.g. reviewer), got an empty string|<type>`
- `… "effort" must be one of low, medium, high, xhigh, max, got <type>`
- `… effort must be one of … got "huge"`

**Resolution.** At engine pre-flight, before `preflightRefusal`, the engine resolves every task's preset with `StagePreset::resolve()`. It checks the `setAgentRoster(?iterable)` roster first (new public API), then the `AgentManager`. The results are memoised for the run.
- An unknown name refuses the run with zero dispatches: `Stage '<s>' preset "<n>": unknown agent preset "<n>" (known: …); nothing was dispatched`.
- If neither a roster nor a manager is available: `…: this engine has no agent roster to resolve presets against (the host that runs it must hand one over); nothing was dispatched`.

**Merge** (the task's own keys always win):

| Preset field | Applied when |
|---|---|
| tools | the task's `tools` is `[]` |
| model | the task's model is null or `inherit` |
| effort, isolation | the task leaves them null |
| system prompt (preset body) | always, as the stage agent's prompt |
| disallowedTools, maxTurns | always, carried on `Agent`; `SubAgentGrantHook` and `EngineExecutor` honour them |
| permissionMode | only when STRICTER than the session gate: `stageGate()` returns `permissionGate->withMode(…)`. A bypass session stays bypass. With no session gate, the mode is not applied. `EngineExecutor` passes the stricter gate as the third argument of `SubAgentGrantHook` |

**Prompt composition.**
- With a manager, the manager adds the shared prompt and the engine sends only the environment half. Without one, the engine sends body + `\n\n` + environment.
- Parallel stages: when any task carries a preset body, the pool path gets a per-agent system-prompt resolver.

**Identity hash.** `stageInputHash` appends `preset` and `effort` only when they are set, so existing cache and resume hashes are unchanged.

**Linter.** `WorkflowLinter::lint(…, ?array $presetNames = null)`:
- a blank preset is an error;
- when `$presetNames` is given, an unknown name is the error `preset "<n>" is not an agent preset this session knows (known: a, b); the engine refuses the run before dispatching anything.` The registry has no roster, so it is the callers that must pass the names.

**Glossary.** Task-key lines for both keys. `WorkflowToolDescriptionDriftTest` is green.

**Tests.**
- `tests/Workflows/StagePresetEffortTest.php` (14 tests): loader, refusals, builder, unknown name and no roster, what a preset supplies, overrides, effort without a preset, per-agent bodies in a parallel stage, hash identity, isolation/model/inherit merge, linter, and the carry of a stricter mode, maxTurns and the denylist.
- `WorkflowYamlKeyRosterTest` pin updated.

### P1-7: `config.maxTokens` / `config.maxCost` hard ceilings

**Keys.**
- `config.maxTokens`: a positive int (`requirePositiveInt`).
- `config.maxCost`: a positive int, float or numeric string, in USD. Otherwise: `"config.maxCost" must be a positive number of USD, got <type|value>`.
- DTO: `Workflow::$maxTokens` (?int) and `$maxCost` (?float).
- Builder: `maxTokens(?int)` refuses values below 1 with `maxTokens() needs a whole number of at least 1, got N`. `maxCost(int|float|null)` refuses a non-positive or non-finite value with `maxCost() needs a positive number of USD, got …`.

**Accounting.**
- Every settled `AgentResult` adds its `tokensUsed` and `costUsd` to the run's spend (`noteSettled`, which replaced the bare `foldStageWriteSignal` calls).
- Cached stage rows and the prior run's totals on resume count too, so the ceiling always compares the WHOLE run's spend.

**Check.** Spent AT or past the ceiling refuses. The check runs:
- before every dispatch, through `reserveAgents()` (the single pre-dispatch funnel). A wave is refused whole, with nothing dispatched.
- before every pool retry: `AgentWorkerPool::withRetryGate(?\Closure)` (new, a clone) is consulted with the prior attempts' spend plus the failed attempt's. A refusal reads `not retried after attempt N of M: budget exhausted (…)`.

The refusal text is `budget exhausted (spent 800 of 700 tokens)` or `budget exhausted (spent 0.60 of 0.50 USD)`; USD figures have 2–4 decimals.

**Stop = PAUSE.**
- The refusing stage fails with `<refusal>; the run is paused: raise the ceiling (config.maxTokens / config.maxCost, or the run's token budget) and resume`, carried by `BudgetExhaustedException`, which extends `AgentCeilingReachedException`. Map stages therefore stop admitting items exactly as they do at the agent ceiling.
- The loop's `while` condition then exits, and the engine returns `WorkflowStatus::Paused` with a pause record (`writePauseFile`, `pauseRequested=true`).
- The pause record is written only for registry runs (`run()`, which has a load path). `runWorkflow()` and `runFromPhp()` return `Paused` with no record, since there is nothing to resume from.

**Raising the ceiling on resume (decision).**
- `resume()` re-reads the YAML through the registry, so an edited `config.maxTokens`/`maxCost` takes effect.
- Alternatively, pass `resume($id, …, budgetTokens: N)`.
- Either way the completed prefix is kept, and the ceiling still compares the whole-run spend, prior spend included. Resuming under the same ceiling pauses again with nothing dispatched.

**External budget (new API).** `run()`, `runWorkflow()` and `resume()` take a trailing `?int $budgetTokens = null`.
- The effective token ceiling is `min(config.maxTokens, $budgetTokens)` (`tighterCeiling`).
- A value below 1 throws `\InvalidArgumentException('a workflow token budget must be at least 1, got N')`.
- `WorkflowEngineInterface` is unchanged.

**Remaining budget.**
- `@budgetRemaining` (`StageCondition::BUDGET_REMAINING_CONTEXT_KEY`) is now populated for `until`/`when` evaluation, so `until: {budgetBelow: N}` works. It is never written into the result context.
- `{{budget.remaining}}` interpolates the remaining tokens only when a token ceiling exists. Without one it stays verbatim, and `budgetBelow` never holds.

**Linter.**
- Errors for a hand-built `maxTokens < 1` or a non-positive `maxCost`.
- Warnings for `until {budgetBelow}` and for `{{budget.remaining}}` with no `maxTokens`.

**Glossary.** Config gloss (maxTokens, maxCost, pause semantics), loop gloss (`budgetBelow`), and an interpolation line for `{{budget.remaining}}`.

**Tests.** `tests/Workflows/WorkflowBudgetTest.php` (16 tests): loader and builder refusals; a token pause followed by a resume under a raised ceiling; a re-pause under the same ceiling; the USD ceiling; external budget and min(); the retry gate; whole-wave refusal; `budgetBelow` loop plus `{{budget.remaining}}` (rounds of 2000/1600/1200/800); behaviour without a ceiling plus the linter warnings; linter errors.

### P2-2 (engine half): announcements

**Stage key `announce:`.** A non-empty template string in `YAML_STAGE_KEYS`, allowed on every stage form including `uses`. It is interpolated with the run context after the stage settles (`{{<stage>.output}}` etc.).
- Load refusal: `… "announce" must be a non-empty template string, got …`.
- Builder: `WorkflowBuilder::announce(string)` attaches to the last stage and refuses when there is no stage or the template is blank.
- Linter: blank or non-string is an error.

**Mandatory engine announcements.**
- **truncation:** `Stage 'v': from.max kept 2 of 5 items; 3 dropped (listed in the stage output)` (unit `files` for globs). Emitted for parallel `from:` glob/data and for map.
- **casualties:** `Stage 'fan': 1 of 3 agents failed, minSuccess 2 met; failed: two (failed)`. Emitted met or not. The list is capped at 10 names plus `, +N more`. Unit `items` for map.
- **budget** (once per run): `Stage 'c': budget exhausted (spent 800 of 700 tokens); the run is paused before this dispatch`, or `…; a retry was not started and the run is paused`.
- **ceiling** (once per run): `Stage 'b': agent ceiling reached (1): 1 agent dispatched and a stage needed 2 more`.

**API for the tool agent.**
- `WorkflowResult::$announcements` (`list<string>`, trailing, default `[]`). It is also carried on Paused and Failed snapshots.
- `WorkflowEngine::setAnnouncementSink(?\Closure $sink)`. The sink is called as `($line, ['kind' => 'announce'|'truncation'|'casualties'|'budget'|'ceiling', 'stage' => ?string, 'workflowId' => string])` at the moment of the announcement, so it can stream.
  - A throwing sink is swallowed.
  - A null sink (the default) means behaviour is byte-identical apart from the new result field.

**Hooks are NOT used (decision).** Hook lifecycle events are observe-only stage and workflow transitions, not free-text narration, and `src/Hooks` is outside this track. The sink is the transport.

**Tests.**
- `tests/Workflows/WorkflowAnnouncementsTest.php` (8 tests): template plus sink meta, an empty list, a throwing sink, loader/builder refusals, the linter, truncation, casualties, and the ceiling.
- `WorkflowAgentResultsTest::testEveryPromptInterpolationCarriesTheJoin` (a source census) now names the `announce:` interpolation as the one non-prompt surface.

## 2. Not done / decisions to note

- **Preset `skills` are not carried.** On the manager path an unresolvable skill would refuse the dispatch, and without a manager there is no skill registry. The `skills:` task key remains the way to grant them.
- **Preset `permissionMode`** only tightens, and only when the engine has a session gate. It never loosens, and a bypass session inherits bypass.
- **No cost check before a dispatch's own spend.** The ceiling is a "may the next dispatch start" check against settled spend, so one in-flight wave can overshoot by its own cost. That is inherent without per-request token caps.
- The linter cannot check preset names unless the caller passes `presetNames` (the registry has no roster).
- Docs (`candy-code/docs/WORKFLOWS.md`, README) are not updated: owned by the docs agent.

## 3. Cross-track requests

1. **`tests/Tools/WorkflowToolForkTest.php::testEachAgentIsJudgedOnTheToolsItsRequestCarries`** now gets `parallel: 1 forked, 1 inline (agent "shell" may ask: Bash)`. It expected `parallel: inline (agent "shell" may ask: Bash; 1 more inline)`. This is CORRECT after the grant fix (the reader no longer carries Bash, so it can fork). Update the expectation.
2. **`WorkflowTool`**:
   - pass a per-call token budget through `runWorkflow(…, budgetTokens:)` / `run(…, budgetTokens:)`;
   - call `setAgentRoster(Bootstrap::agentRoster(...))` (or ensure `setAgentManager`) so `preset:` resolves;
   - call `setAnnouncementSink()` to stream lines into the tool result or progress, and/or render `WorkflowResult::$announcements`;
   - pass `presetNames` to `WorkflowLinter::lint()`;
   - treat `WorkflowStatus::Paused` from a budget stop as a resumable outcome.
3. **`Bootstrap::workflowEngine`**: call `setAgentRoster()` so CLI/TUI runs resolve presets without a manager.
4. **CLI `workflow lint` and the TUI lint caller**: pass `presetNames` from the roster.
5. **TUI workflow pane**: install an announcement sink.
6. **Docs agent**: add `preset:`, `effort:`, `config.maxTokens`/`maxCost` (pause and resume semantics, `{{budget.remaining}}`, `budgetBelow`), `announce:` and the mandatory announcements to `docs/WORKFLOWS.md`. Note the grant fix.
7. `suite-figure.json` / `parallel-tests-durations.tsv` need refreshing for the new test files: `ParallelPerAgentGrantTest`, `StagePresetEffortTest`, `WorkflowBudgetTest`, `WorkflowAnnouncementsTest`.

## 4. Commits

- `39635fa28`: every parallel stage agent runs on its own declared tools grant (C4 bug)
- `8a3c81806`: task keys `preset:` and `effort:` (P1-6)
- `d04d306f8`: maxTokens/maxCost ceilings, budget pause/resume, `{{budget.remaining}}`, `announce:` and engine announcements plus sink (P1-7, P2-2)
