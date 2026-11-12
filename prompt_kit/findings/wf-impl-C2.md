# wf-impl track C2 — P1-1 (data fan-out, array context) + P1-5 engine half (loops) + linter/glossary

Scope: `candy-code/src/Workflows/**`, `candy-code/tests/Workflows/**`.

## 1. What was implemented

### P1-1 · `from:` fans out over data

A parallel stage's `from:` now takes **exactly one source**. The roster is
`WorkflowRegistry::YAML_FROM_KEYS = [glob, stage, context, pointer, key, chunk, max]`.

```yaml
- name: verify
  parallel: true
  from: {stage: find, pointer: /findings, key: /id, chunk: 8, max: 200}
  prompt: "Refute {{item.claim}} at {{item.file}}:{{item.line}} (#{{index}})"
- name: each
  parallel: true
  from: {context: files}        # run context value must be a JSON list
  prompt: "Read {{item}}"
```

**Sources:**
- `glob` is unchanged (C8).
- `stage` + `pointer`:
  - `pointer` is required beside `stage`.
  - It is an RFC 6901 pointer. `""` means the whole row, and every `~` must be `~0` or `~1`.
  - It is resolved with `JsonPointer` against the typed row (`@typedResults`) stored under the result name `stage`. That row exists for a single-task schema'd stage named `stage`, or for a `loop.accumulate` stage (its `{"items":…}` set, see below).
  - A parallel stage without accumulate keeps its rows under `<stage>_<n>`, so there is **no** row under its stage name.
- `context`: a run-context key. It must not be empty and must not start with `@`.

**Optional keys:**
- `key` is a pointer into each item, allowed with data sources only. It dedups items: the first occurrence wins and order is kept. It also names each item's task `<stage>_<key>`, sanitised to `[a-zA-Z_][a-zA-Z0-9_]*`:
  - every other byte becomes `_`;
  - a leading digit gets a `_` prefix;
  - when two keys sanitise to the same name, the later ones get `_2`, `_3`, … appended.
- Without `key`, items are neither deduped nor named, and results fall back to `<stage>_<n>` (1-based).
- `chunk` and `max` behave as they do for glob. `max` keeps the first N in source order. The output records the cut with `[from: kept N of M matches (max: N); …]` and `dropped: <label>` lines; a label is the key, or the item's compact JSON clipped to 120 chars.
- The 4096 ceiling applies: `Stage 'x' expanded N items from <source>; the per-stage fan-out ceiling is 4096 — set from.max`.

**Load-time refusals** (all `WorkflowLoadException`, prefixed with `Workflow file … stage 'x'`):
- `"from" needs exactly one source: a "glob" to expand against the environment root, "stage" + "pointer" (an array in a prior stage's typed row) or "context" (a run-context list)`
- `from carries "glob" and "context"; a fan-out reads exactly one source`
- `"from" "stage": "find" is not a stage declared before this one; a fan-out reads prior stages only` (the same order law as `input.from`; covers itself, a later stage, or an unknown stage)
- `from "stage" needs a "pointer" (RFC 6901, e.g. /findings) to the array in that stage's typed row`
- `from "pointer" is not a valid RFC 6901 pointer: …`, and the same message for `from "key"`
- `from "pointer" reads a stage's typed row; beside "context" the value itself must be the list`
- `from "context" must name a run-context key (not empty, not starting with "@"), got "…"`
- `from "key" belongs to a data source ("stage" or "context"), not to "glob"`, and the same for `pointer`
- `from "stage" must be a string, got array`
- The template-prompt message now reads `fans out from "from", so it needs a stage-level "prompt"`.

**Run-time:**
- A source that is not a JSON array fails the stage loudly and dispatches nothing:
  - `Stage 'v' fans out from stage 'find' at "/f", which is an object|a string|nothing|…, not an array`
  - `… from context 'files', which is a string, not an array`
  - `… which is nothing (the run was handed no such key)`
- A missing typed row fails with: `Stage 'v' fans out from stage 'find', which left no typed row to point into (…)`.
- An item with no scalar key fails with: `… item #2 has no string, number or bool key at "/id"`.
- **An empty data list completes the stage with no agents and an empty output.** This is a decision: "nothing to verify" should flow on, while an empty glob still fails as a likely typo. A `minSuccess` stage then records `{{stage.failed}}` = `[]`.

**Interpolation (engine item pass, runs first):**
- It applies to every fan-out, glob included:
  - `{{item}}`: a string verbatim, a bool as `true`/`false`, a number cast to string, anything else as compact JSON (`JSON_UNESCAPED_SLASHES|UNICODE|PRESERVE_ZERO_FRACTION`).
  - `{{item.<pointer>}}`: a leaf of the item using the same pointer rules. Containers render as JSON. A miss is left verbatim.
  - `{{index}}`: 0-based.
- Item tokens are spliced in through placeholders after the other passes. Item data, which is usually model output, is therefore never re-scanned for `{{…}}`. The item wins over any context key or result named `item` or `index`.
- Inside a fan-out, `{{item.output}}` and `{{item.results}}` are item pointers.
- A glob item also keeps `{{path}}` with C8's left-wins rule.
- Mechanism: per-item context key `WorkflowEngine::FAN_OUT_ITEM_CONTEXT_KEY = '@fanOutItem'`. It lives only on the item's copy of the context and never reaches a pause record.

**Resume:**
- The materialised list rides `dynamicWaves.<stage>` as `{items: [...], names: [null|string...], chunk, (max, matched, dropped)}`. A resume replays it and never re-reads the source.
- A malformed record refuses: `Stage 'v' resumed with a dynamic-wave record it cannot replay — refusing to re-read stage 'find' at "/f", which may have changed`.

**Bug fixed:** the `from:` template now carries `stallTimeout`, `restarts` and `input` to every item's task (C1's note).

**Builder parity:**
- `WorkflowBuilder::parallel(..., from: ['stage'=>…, 'pointer'=>…, 'key'=>…, 'chunk'=>…, 'max'=>…])` or `['context'=>…]`.
- The shared source law is `WorkflowBuilder::fromSource(array): array` (public static), which the loader also uses.
- Normalised spec order: source keys (+`key`), `chunk`, `max?`. A glob spec without max is still `{glob, chunk}`.

### Array run context (brief item 2)

- `run()`, `runWorkflow()`, `runFromPhp()` and `resume()` already accepted non-scalar values; only `@` keys are refused, and still are.
- **Changed deliberately:** a bare `{{key}}` whose value is an array (or a `JsonSerializable`/`stdClass`) now renders as compact JSON. Before, it was left verbatim.
- Bools, null and resources are still left verbatim.
- Arrays survive the pause record (JSON) and resume. Tested.
- `from: {context: key}` reads the list directly.
- NOT done here (not my files): the `Workflow` tool's `context` schema (`additionalProperties: {type: string}` → `{}`) and `/workflow run`'s CLI parsing. See cross-track requests.

### P1-5 · loops (engine half)

**Where `loop:` may attach** (`WorkflowBuilder::LOOPABLE_TYPES = [stage, parallel, verification]`):
- `loop:` is now allowed on parallel and verification stages.
- It is still refused on `pipeline` (`"loop" cannot attach to a "pipeline" stage; stage, parallel and verification stages loop`) and beside `uses:` (both the loader message and the builder message).
- Builder `loop()` messages: `loop() cannot repeat the last stage 'x': a 'pipeline' stage does not loop (…)` and `… it carries another workflow (uses), which does not loop`.

**Loop roster and `until:`** (`WorkflowBuilder::LOOP_KEYS = [max, until, accumulate]`):
- `until:` is parsed with `allowLoopLeaves: true` in both the loader and the builder, so `{dry: N}` and `{budgetBelow: N}` load.
- `{budgetBelow}` reads `@budgetRemaining`. Nothing populates it yet (P1-7), so the loop runs to `max`.

**Per-round tokens** (`WorkflowEngine::LOOP_TOKENS`). They shadow same-named context keys only while the loop runs; the author's values are restored afterwards:
- `{{round}}`: 1-based.
- `{{previous}}`: the prior round's `<stage>.output`, from round 2. In round 1 it is left as the context had it (normally verbatim).
- `{{seen}}`: JSON list of every dedup key collected so far. `[]` in round 1. Only with `accumulate`.
- `{{fresh}}`: JSON list of the items the previous round added. `[]` in round 1. Only with `accumulate`.

**`accumulate: {pointer, key?}`** (parser `WorkflowBuilder::parseAccumulate()`, roster `ACCUMULATE_KEYS`):
- Contributing rows each round are the completed, schema'd agents' outputs:
  - parallel stages: all agents, unioned in declaration order;
  - `tasks:` stages: all tasks;
  - verification stages: only the TASK slot, never the verifier.
- Each contributing row's `pointer` must resolve to an array. Otherwise the round fails: `loop.accumulate: agent 'x' of stage 's' answered a string at "/f", not an array`. A round with no contributing row fails: `… no schema'd agent result to read "/f" from; give its agents an outputSchema`.
- **Item identity** is the `key` leaf (string, number or bool). A missing leaf fails the round. Without `key`, the identity is the item itself (a scalar's text, otherwise its compact JSON).
- **Dedup is against everything SEEN.**
- A round that adds nothing increments `@loopDryRounds` (`StageCondition::LOOP_DRY_ROUNDS_CONTEXT_KEY`). A round that adds anything resets it to 0.
- A FAILED round folds nothing and leaves the dry count unchanged. The loop continues, as it does for plain loops.
- The dry key is removed from the context after the loop.

**Recorded result** of an accumulate stage, when its last round did not fail:
- Output and typed row under the STAGE NAME: `{"items":[…all distinct items, first-seen order…],"keys":[…their identities…],"rounds":<rounds run>}`.
- It is pointer-addressable: `{{find.items/0/id}}`, `{{find.rounds}}`, `when: {stage: find, pointer: /items, empty: false}`, `from: {stage: find, pointer: /items}`.
- It is also `{{find.output}}`, or the capture path when `capture: true`.
- If the last round failed, the stage settles as that failure, the same law as plain loops.

**Load refusals:**
- `until: {dry: N}` without `accumulate` is refused: `loop() "until" uses {dry: N}, which counts rounds that add nothing new to an accumulated set; add "accumulate: {pointer, key}" or drop the dry leaf`.
- `loop "accumulate" must be a map {pointer: /items, key: /id}, got …`
- `loop "accumulate" needs a "pointer" …`
- `loop "accumulate" has unknown key(s) 'by'; the grammar is pointer and key`
- `loop "accumulate" "key" must be a string …` and `… is not a valid RFC 6901 pointer …`
- `"loop" carries unknown key(s) …; the grammar is max, until and accumulate only`

**Resume:** whole-stage re-run, as for plain loops. The accumulator lives in memory and is never persisted, so a resumed run re-runs an accumulate loop from round 1 with an empty set. The `cache: true` door can still carry the recorded `{"items":…}` row.

### Other brief items

- **(4)** `WorkflowEngine::jsonPointerValue()` now delegates to `JsonPointer::resolveJson()`, so there is one implementation.
- **(5) Linter (`WorkflowLinter`):**
  - `.output` is a producer for EVERY stage type (stage, parallel, verification, pipeline, uses).
  - Messages are now `{{x.output}} names no stage or pipeline step in this plan, …` and the later-stage message no longer claims only sequential stages write `.output`.
  - Engine-injected tokens no longer warn in their scopes:
    - `{{input}}` on a task with `input:`;
    - `{{prevResult}}` on a `tasks:` entry after the first, any pipeline step, or a verifier;
    - `{{round}}`/`{{previous}}` on a looped stage, and `{{seen}}`/`{{fresh}}` with accumulate;
    - `{{item}}`/`{{index}}` (and `{{item.output}}`) on any `from:` stage, plus `{{path}}` on a glob fan-out.
  - These tokens still warn out of scope.
  - New mirrors for hand-built plans:
    - loop on a pipeline or uses stage (error);
    - `{dry: N}` without accumulate (error);
    - `{dry: N}` with N >= max (warning);
    - `from.stage` naming a non-prior stage (error).
- **(5) `ExampleWorkflowsLintTest`:** every `examples/workflows/*.yaml` and `workflows/*.php` lints with 0 errors. Current state: 0 errors; the warnings are only genuine run-context seeds (`{{scope}}`, `{{question}}`, …) plus shared-checkout warnings.
- **(6)** `StageOutputSchemaTest` was moved to B1's contract:
  - requests carry `jsonSchema: null`;
  - an exact-document reply is accepted;
  - the refusal is keyed on `supportsFunctionCalling()`;
  - a provider with no JSON mode still runs;
  - an answer after tool use is the validated JSON with no tail;
  - a prose answer is re-prompted once and then fails.
- **(7)** `stageInputHash()` docblock now says `cache:` consumes the hash, and that the recipe does NOT cover `from:` or `loop:` specs.
- **(8)** `WorkflowGrammarGlossary` changes:
  - stage key `minSuccess`;
  - rewritten `from`, `loop` and `when` glosses (pointer, empty, data sources, accumulate, dry);
  - workflow keys `config` (maxConcurrent default, maxAgents) and `schemas`;
  - interpolation forms `{{item}}`, `{{index}}`, `{{previous}}`, `{{seen}}`, `{{fresh}}`, `{{stage.failed}}`, plus array rendering for `{{key}}`.
  - `tests/Tools/WorkflowToolDescriptionDriftTest.php` is green.
- **(9) Builder parity:** `parallel(from: data)`, `loop()` with accumulate/dry, on parallel and verification stages.

**Tests:**
- New: `tests/Workflows/WorkflowDataFanOutTest.php` (31), `WorkflowLoopAccumulateTest.php` (17), `WorkflowLinterEngineTokensTest.php` (6), `ExampleWorkflowsLintTest.php` (1).
- Updated: `StageOutputSchemaTest.php`, `WorkflowLoopUntilTest.php` (builder loop shapes), `WorkflowYamlKeyRosterTest.php` (from roster, source message, loop on parallel/verification loads, pipeline refuses), `WorkflowLinterTest.php` (every stage writes .output).
- Whole `tests/Workflows` run: 846 tests, all green. `tests/Tools/Workflow*` and `DeepResearchWorkflowTest` are green.

## 2. Not done / caveats

- `{{budget.remaining}}` and populating `@budgetRemaining`: that is P1-7 (no budget config exists).
- `type: map` and `when:` on map steps: a later agent. `LOOPABLE_TYPES` is the one list to extend.
- `stageInputHash` deliberately does not cover `from`/`loop` specs: widening it would change every recorded hash. `cache: true` on such a stage is the author's stability assertion (now documented in the docblock).
- Ccond's suggested linter mirror "a `pointer:` leaf naming a stage with no outputSchema" was NOT added.
- A live `pause()` mid-loop snapshots the context with the per-round tokens in it, the same pre-existing behaviour `{{round}}` had. A resume re-runs the loop and overwrites them.
- `from: {stage: X}` on a parallel X without accumulate finds no typed row under `X` and fails loudly. Unioning a plain parallel stage's agent rows was not done.

## 3. Cross-track requests

- **WorkflowTool (`src/Tools/BuiltIn/WorkflowTool.php`, B2):** make the `context` input schema `additionalProperties: {}` (any JSON value) instead of `{type: string}`, and pass arrays through untouched. The engine renders them as JSON and `from: {context}` reads them.
- **`/workflow run` (`src/Commands/…WorkflowCommand`):** optionally accept `key=[…]` JSON values so a list can be handed in from the CLI.
- **Docs agent (`docs/WORKFLOWS.md`, skill):**
  - Document section 1.
  - Replace "`loop:` is stage-only in v1" (WORKFLOWS.md ~316-321, ~662-667) and "parallel and verification stages never write `.output`" in the lint roster.
  - Document that an array `{{key}}` renders JSON.
  - Document the accumulate result shape `{"items","keys","rounds"}`.
  - Document that an empty data fan-out completes.
- **Examples (track A files):** `fix-wave.yaml` can now use `from: {context: files}` or `from: {stage, pointer}` instead of static shards. `docs-drift-sweep.yaml` / `deep-research.php` can loop their parallel stages.

## 4. Commits

- `053ece087` StageOutputSchemaTest → StructuredOutput contract (P0-2)
- `8dcd65699` data fan-out, array context, loop on parallel/verification, {{previous}}, accumulate-until-dry, JsonPointer switch, template-field fix (P1-1 P1-5)
- `851404c55` linter: .output for every stage, engine tokens, loop/data mirrors, ExampleWorkflowsLintTest
- `d4cb08903` grammar glossary (minSuccess, maxAgents, schemas, from sources, accumulate, tokens)
