# wf-impl track C1 — P0-3, P2-4, P1-3, P2-3 (stage numbering)

Scope: `candy-code/src/Workflows/**` (not `StageCondition.php` / `JsonPointer*`) and
`candy-code/tests/Workflows/**` (not `StageConditionTest.php`).

## 1. What was implemented

### P2-3 (stage-numbering row only): diagnostics are 1-based

Every position number in a load or run diagnostic now counts from 1, like the tool report's `Stage %d`:
- `WorkflowRegistry`: `stage #N must be a map` / `must have a "name" field`, the duplicate-name error
  `(stages #1 and #3)`, `agent #N` (parallel agents), `task #N` (`tasks:` lists), `step #N` (pipeline steps).
  The helper is `WorkflowRegistry::ordinal()`.
- `WorkflowEngine`: `Stage 'x' task #N` in the multi-task stage executor and the up-front declaration pre-flight.
- `WorkflowBuilder::stage()`: `Stage 'x' task #N must be a TaskBuilder`.
- `WorkflowLinter` was already 1-based.
- **Docs agent:** `docs/WORKFLOWS.md:705` (0-based numbering note) needs to say 1-based now.

### P0-3: safety rails

**Default width.** `config.maxConcurrent` / `WorkflowBuilder::maxConcurrent(?int)`:
- unset (`Workflow::$maxConcurrent === null`, still the DTO default) → `min(16, max(1, cpus - 2))`
  (`Workflow::defaultConcurrency(int $cpus)`, constant `Workflow::DEFAULT_WIDTH_CAP = 16`).
- `0` → explicit "no cap". The DTO now **keeps 0**; before, the loader turned it into null.
- `n >= 1` → that width. A negative value is refused (`maxConcurrent() needs 0 (no cap) or a width of at least 1, got -1`;
  YAML: `"config.maxConcurrent" must be 0 (no cap) or at least 1, got -1`, unchanged).
- Resolution is in `WorkflowEngine::concurrencyFor(Workflow): ?int` (public). `AgentWorkerPool` is untouched.
  `chunk` still narrows it: `min($width, $chunk)`.
- CPU count: new `src/Workflows/CpuCount.php`. `CpuCount::detect()` reads `NUMBER_OF_PROCESSORS`, then
  `/sys/devices/system/cpu/online`, then `/proc/cpuinfo`. It never forks a process. Fallback is
  `CpuCount::FALLBACK = 4` (so width 2). The result is memoised.
  `WorkflowEngine::setCpuCount(?int)` overrides it (tests); null restores detection.
- Linter: `maxConcurrent 0` → **warning** `maxConcurrent 0 means no cap: every agent of a parallel stage is dispatched at once. Leave it unset for the default width of min(16, CPUs - 2), or set a number.`
  A negative value → error `maxConcurrent -1 is negative; …`.

**Fan-out ceiling.** `Workflow::FAN_OUT_CEILING = 4096` items per `from:` stage. Past it, the stage fails and
dispatches nothing. The list is never truncated:
`Stage 'sweep' expanded 4097 files from "*.txt"; the per-stage fan-out ceiling is 4096 — narrow the glob or set from.max`.
The check runs before the expansion is recorded in the pause ledger, so a resume re-globs a narrowed pattern.
A replayed (recorded) list is checked as well.

**`from: {glob, chunk, max}`.** `max` is optional, a whole number from 1 to 4096 (YAML accepts a quoted int).
- Refusals: `"max" must be at least 1, got 0`, `"max" must be a whole number, got string`,
  `"max" must be at most 4096 (the per-stage fan-out ceiling), got 4097`.
- The roster of `from` keys is `WorkflowRegistry::YAML_FROM_KEYS = ['glob','chunk','max']`, and unknown-key
  errors list it. `max` appears in the spec only when it was written, so a spec without it keeps the two-key shape.
- Semantics: `max` keeps the first N of the **sorted** expansion. When it drops anything, the stage output becomes:
  ```
  [from: kept 2 of 5 matches (max: 2); 3 dropped, listed at the end of this output]
  <agent outputs…>
  [casualty line, if any]
  dropped: c.txt
  dropped: d.txt
  dropped: e.txt
  ```
  The header sits on top so a `capture: true` preview (the first 2 KiB) always shows it. With capture on, the
  full dropped list is in the capture file. Without capture, it rides the output. When nothing was dropped,
  the output is byte-identical to a stage without `max`.
- Pause record: `dynamicWaves.<stage>` gains `max`, `matched` and `dropped` (list) next to `paths`/`chunk`,
  only when `max` was set. A resume replays the recorded cut and its notice. A partial truncation record makes
  the replay refuse ("cannot replay").
- Builder: `WorkflowBuilder::parallel(..., from: ['glob'=>…, 'chunk'=>…, 'max'=>…])` is now validated to the
  loader's shape (unknown key, empty glob, chunk < 1, max outside 1..4096 → `InvalidArgumentException`).
  It is normalised to `{glob (trimmed), chunk (null default), max?}`.
- Linter (only for hand-built arrays): `from.max 5000 is not a whole number from 1 to 4096` (error), and
  `"from" on a 'stage' stage fans nothing out` (error).

**`config.maxAgents`** (default `Workflow::DEFAULT_MAX_AGENTS = 1000`, a whole number ≥ 1, quoted int accepted).
New DTO field `Workflow::$maxAgents` (the last constructor parameter, carried by `mutate()`) and builder method
`WorkflowBuilder::maxAgents(int)`, which refuses values below 1.
- YAML refusals: `"config.maxAgents" must be at least 1, got 0` / `must be a whole number, got string|null`.
- Counting: one engine-wide counter per outermost run.
  - Each sequential dispatch (stage task, pipeline step, verification task, every verifier round) reserves 1
    in `dispatchOne()`.
  - A parallel wave reserves all N in one step before it forks anything, so a wave that would cross the
    ceiling forks none of its agents.
  - Retries and stall restarts are added after the stage settles, from `AgentResult::$attempts - 1`, so the
    next dispatch is refused. The pool does not expose a hook to stop a retry in mid-flight.
  - Loop rounds count naturally.
  - An included (`uses:`) workflow shares its parent's counter and **the outermost run's ceiling**; the inner
    `config.maxAgents` is ignored.
  - A resume continues the count: the pause record gains `agentsDispatched` (written while a run is live and
    the count is > 0). Older records start at 0.
- Failure: the stage fails (through the stage catch) with
  `agent ceiling reached (N): this run has dispatched D agents and this stage needs K more; raise config.maxAgents or narrow the fan-out`.
  The run then stops (fail-fast).
- Linter: `maxAgents 0 is not positive` (error, hand-built only).

### P2-4: named schemas

- New top-level key `schemas:`, a map of name → schema.
  - Names match `[A-Za-z_][A-Za-z0-9_-]*`.
  - Each schema must be a map with `type: object` at its root.
  - When a schema has `required`, it must be a list and every entry must appear in `properties`.
- Errors:
  - `"schemas" must be a map of name => schema, got a list|string`
  - `schema "v" must be a schema map, got bool`
  - `schema "v" must be object-rooted ("type: object" at its root)`
  - `schema "v" "required" must be a list of property names`
  - `schema "v" "required" names "b", which is not one of its "properties"`
  - `"schemas" key "1st" is not a usable name; …`
- `outputSchema: {$ref: <name>}` must have exactly that one key. It resolves at load on **every** task surface:
  a one-task stage, parallel `agents:`, `tasks:` entries, verification `task`/`verifier`, pipeline steps, and
  the `from:` template. The JSON-string spelling (`'{"$ref":"x"}'`) resolves too.
  - A `$ref` nested inside a schema is JSON Schema's own and is left untouched.
- Ref errors:
  - `… "outputSchema" "$ref" names unknown schema "x"; declared schemas: a, b`
  - `… names unknown schema "x"; this file declares no top-level "schemas:" map`
  - `… carries "$ref" beside other keys; a reference is the one key {$ref: <name>}`
  - `… "$ref" must name a schema, got array`
- The task DTO carries the **resolved** document, so `stageInputHash` (cache identity) digests the schema
  itself. A ref and the same schema written inline hash the same, and editing the shared schema changes the hash.
- Linter: `outputSchema is an unresolved {$ref: x}; …` (error) when a hand-built DTO still has a bare ref.
- No PHP builder parity for named schemas: PHP authors can share a variable. TaskBuilder output is built
  eagerly into a readonly DTO, so resolving later would need a WorkflowTask copy-wither.

### P1-3: `minSuccess`

- A stage key (added to `YAML_STAGE_KEYS`), **on parallel stages only**, including `from:` fan-outs.
  It is refused on plain, pipeline, verification and `uses:` stages:
  `stage 'x' carries "minSuccess", which only a parallel stage can tolerate failures with`.
  This is to be extended to `type: map` later.
- Values: an int ≥ 1 (a quoted int is OK) → `{count: n}`, or `"NN%"` with NN from 1 to 100 → `{percent: NN}`.
  The percentage is of the agents actually dispatched (after a `from:` expansion), rounded **up**, with a minimum of 1.
- Refusals: `minSuccess must be at least 1, got 0`, `minSuccess percentage must be from 1% to 100%, got 0%`,
  `minSuccess must be a whole number of agents or a "NN%" percentage, got float|"most"|null`.
  A count above a static agent list's length is refused:
  `minSuccess 4 exceeds the 3 agents stage 'fan' declares; it could never be met`.
- Builder: `WorkflowBuilder::minSuccess(int|string)` attaches to the last stage and refuses anything that is not
  parallel. Helpers: `WorkflowBuilder::parseMinSuccess()`, `requiredSuccesses()` and `minSuccessLabel()`.
- Absent → byte-identical: any failed or timed-out agent fails the stage.
- Present:
  - A casualty is a declared agent whose result is `failed` or `timed_out`, or which never produced a result
    (`cancelled`).
  - Zero casualties → the output is unchanged.
  - When there are casualties, the output gets `\n[k of n agents failed: name (status), …]`, after the agents'
    outputs and before any `from.max` dropped list. Names are the `{{name.results}}` result names in declaration
    order (task name, `<stage>_<n>`, or the path for a fan-out).
  - Met → the stage is `completed` with no error.
  - Not met → `failed` with the error
    `minSuccess 100% not met: 2 of 3 agents succeeded and 3 were needed; failed: fan_2 (failed)`.
- `{{<stage>.failed}}`: the context key `<stage>.failed` holds a JSON list of the casualty names (`[]` when none
  failed). It is written only for minSuccess stages, sits next to `<stage>.output`, and survives the pause record
  verbatim. No `@` key is used. Interpolation: when that context key exists it wins over a typed-row pointer
  `failed` leaf; otherwise the pointer form still resolves.
- **stopOnFirstFailure:** a minSuccess stage is **exempt**. Its pool never gets fail-fast, so every sibling runs
  and the tolerance is judged over all of them. The linter warns when both are set:
  `stopOnFirstFailure does not apply to this minSuccess stage: …`.
- Linter mirrors:
  - minSuccess on a non-parallel stage (error)
  - a malformed normalized spec (error)
  - a count above the static agent count (error)
  - the stopOnFirstFailure warning
  - `{{X.failed}}` that reads a minSuccess stage at or after its own position (error)
  - `{{X.failed}}` naming no minSuccess stage and no result producer (warning)

### Tests added/changed (all `candy-code/tests/Workflows/`)

- New: `WorkflowSafetyRailsTest.php` (15), `WorkflowNamedSchemasTest.php` (14), `WorkflowMinSuccessTest.php` (23).
- Updated: `WorkflowRegistryTest.php` (1-based numbering, explicit 0 kept, absent stays null, maxAgents),
  `WorkflowYamlKeyRosterTest.php` (from roster and max shapes, stage-key pin incl. `minSuccess`),
  `WorkflowLinterTest.php` (0 → warning, negative → error, maxAgents/from.max), and `MultiTaskStageTest.php`
  (1-based task numbers, the source pin is now `min($width, $chunk)`).

## 2. Not done / caveats

- Named schemas have no PHP-builder parity (see above).
- maxAgents cannot stop a retry in mid-flight inside `AgentWorkerPool`, which was out of scope. Retries are
  counted when they settle.
- A pre-existing defect, left untouched: the `from:` fan-out builds each item's `WorkflowTask` without the
  template's `stallTimeout`, `restarts` and `input` (`WorkflowEngine::executeParallelStage`, the `array_map`
  over `$paths`). The P1-1 data-fan-out agent may want to fix it while it is in there.
- `Workflow::$maxConcurrent` is still `null` by default. Its meaning changed from "no cap" to "unset → default width".

## 3. Cross-track requests

- **Docs agent (`docs/WORKFLOWS.md`):** the new default width (`min(16, CPUs-2)`, 0 = no cap with a lint
  warning), the 4096 ceiling, `from.max` and its output format, `config.maxAgents`, `schemas:`/`$ref`,
  `minSuccess`/`{{stage.failed}}`, and 1-based numbering (line 705). WORKFLOWS.md:118 says "every task of a
  parallel stage is dispatched at once", which is now false.
- **Owner of `tests/Config/DocFigureProseDriftTest.php`:** `testWorkflowDefaultFiguresSurviveTheirSignatures`
  fails now.
  - It regex-pins the old `Workflow` constructor `@param $maxConcurrent` line
    (`null (the default) sets no cap.`), which C1 reworded to the unset/default/0 semantics.
  - It also pins WORKFLOWS.md's "`maxConcurrent` unset (no cap…)" sentence, which the docs agent must change.
  - The null-default assertion itself still holds.
  - Re-pin the test once the docs land.
- **Not caused by C1:** `StageOutputSchemaTest` has 3 failures from the in-progress P0-2 StructuredOutput work
  in `src/Agents/EngineExecutor.php`.
- **Tool description (P0-1 agent):** advertise `config.maxAgents`, `from.max`, `schemas:`/`$ref`, `minSuccess`.

## 4. Commits

- `1bfb37f34` 1-based stage/agent/task/step diagnostics (P2-3)
- `a0c2a940d` default width, 4096 ceiling, from.max, config.maxAgents (P0-3)
- `46c42cf00` top-level schemas: + outputSchema $ref (P2-4)
- `3974e34e0` minSuccess + {{stage.failed}} (P1-3)
