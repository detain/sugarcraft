# wf-impl track B1 — P0-2 (StructuredOutput enforcement) + P1-6 plumbing (effort, preset helper)

## 1. What was implemented

### P0-2 · `outputSchema` is enforced through a terminal `StructuredOutput` tool call

Scope: every sub-agent run through `src/Agents/EngineExecutor.php` (every workflow stage agent,
forked or inline). Headless `-p --json-schema` is UNCHANGED (still `withJsonSchema` +
`json_schema_applied`, as docs/FLEET-DRIVING.md documents).

Semantics (doc-ready):

- When a task declares `outputSchema`, the run gets one extra tool, `StructuredOutput`, whose
  input schema IS the task's schema. It is minted per run (`src/Agents/StructuredOutputTool.php`),
  is NOT a catalog built-in (no `#[BuiltInTool]`), cannot be granted/denied by a preset or a
  stage `tools:` list, and is always appended last to the run's tool list (a same-named tool in
  the request is dropped so the model sees exactly one).
- A non-object schema (e.g. `{type: array}`, `{type: string}`, `{}`) is wrapped on the wire as
  `{type: object, properties: {value: <schema>}, required: [value], additionalProperties: false}`
  and unwrapped in the output (the stage output is the bare value, e.g. `["a","b"]`).
- The agent's system prompt gets this paragraph appended (literal):
  "Your final answer is structured output. When you have finished any other work, end your run by
  calling the `StructuredOutput` tool exactly once, with your answer as its arguments; the
  arguments must match its input schema. Do not write the answer as plain text. If the call
  returns validation errors, correct the arguments and call it again. Your run ends when the call
  is accepted."
- Wire shape: the tool's input schema always carries top-level `properties` and `required`, and any
  empty map (`properties: {}`, `items: {}` — decoded to PHP `[]`) is sent as JSON `{}`.
- Each call's arguments are validated against the schema with the existing
  `SugarCraft\Code\Protocol\Schema\SchemaValidator` (no new dependency). Enforced keywords: `type`
  (incl. unions), `properties`, `required`, `additionalProperties` (false or a schema), `items`,
  `enum`, `oneOf`, `$ref` into the schema's own `$defs` (`#/$defs/<name>`), `maxItems`,
  `maxLength`, `minimum`, `maximum`. Other keywords (`minItems`, `minLength`, `pattern`, `const`,
  `anyOf`, `allOf`, `format`, …) are NOT enforced — document this.
- On mismatch the call returns an error tool result listing each violation as `path: expectation`
  (e.g. `$.verdict: must be one of pass, fail`, `$.ok: is required`) and asking for a corrected
  call; the model retries within the same step budget.
- On success the tool answers "Accepted…", and the run ENDS at that step boundary (no further
  provider call). The stage output is the validated document as compact JSON
  (`JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION`); an empty
  object answer is written `{}`. This is exactly what `{{name.output}}` / pointer readers parse.
- A final reply that is ITSELF exactly a valid document (whole reply is JSON, optionally inside
  one ```` ```json ```` fence, and it validates) is accepted as the answer without the call.
  Prose never is.
- A run that ends in plain text (or blank) without an accepted call is re-prompted ONCE with:
  "You ended your turn without calling `StructuredOutput`. Call `StructuredOutput` now with your
  final answer — nothing else is accepted as the result of this run." The re-prompt runs inside
  the REMAINING step budget (`maxTurns` minus steps already used, minimum 1 so a run that spent
  its budget can still deliver). For schema runs this replaces blank-return resume.
- If the re-prompted run still has no accepted call, the agent FAILS:
  `agent "<name>" declares an outputSchema but ended without an accepted StructuredOutput call,
  even after being told to make one, so it produced no structured result (its last reply began:
  "<first ~120 chars>")` (+ worktree note when a tree was kept). Usage of both attempts is billed.
- Pre-run refusal (no billable call): a provider that cannot make tool calls
  (`supportsFunctionCalling() === false`) refuses with `agent "<name>" declares an outputSchema,
  but its provider (<p>) cannot make tool calls, so it cannot constrain the answer to a schema
  (the answer is enforced through the StructuredOutput tool call); the run was not started`.
  Native JSON-schema support (`supportsJsonSchema`) is NO LONGER required for stages.
- `withJsonSchema()` is no longer applied on the sub-agent path. Why: `Runtime::jsonSchemaFor()`
  stamps the schema on a self-finishing request and a constrained reply cannot carry tool calls,
  so on the opening request it would forbid the very StructuredOutput call (and every read before
  it). Requests of a schema stage now carry `jsonSchema: null`.
- The old disclosure tail `[the reply was not constrained to the output schema because the model
  ran tools before answering]` is GONE (the answer is enforced now). Docs and example comments
  ("treat any output that does not parse as a verdict as NEEDS-FIX") can drop that workaround.
- Permissions: the `StructuredOutput` call bypasses the PreToolUse chain (permission gate, stage
  grant hook, user hooks) — `Runtime::gate()` matches it by INSTANCE (`instanceof
  StructuredOutputTool`), never by name, so a user/MCP tool named `StructuredOutput` is still
  gated. Rationale: it is the run's answer, not an action; under Default/Plan/DontAsk an unlisted
  tool would ask or be refused and strand non-interactive stages. Works under every mode and
  with a stage `tools:` grant that doesn't name it (tested).
- Notes on a schema run (kept worktree, resumed note, ignored effort) do NOT ride the output (it
  must stay a bare JSON document); they go to the live activity stream (`executeStream`).
- `requestedWrite` ignores the StructuredOutput call (it writes nothing).

Files: `src/Agents/StructuredOutputTool.php` (new), `src/Agents/EngineExecutor.php`,
`src/Runtime.php` (`gate()` takes `?Tool $tool`, early-allow for StructuredOutputTool; 3 call
sites pass `$tool`), `src/Backend/EngineBackend.php` (`completeTranscript()` gains a trailing
optional `?\Closure $stopRequested = null`, passed to `runTurn()`'s existing step-boundary stop;
null = byte-identical).

Tests: `tests/Agents/EngineExecutorStructuredOutputTest.php` (14),
`tests/Agents/StructuredOutputToolTest.php` (7).

### P1-6 plumbing · `effort` on SubAgent + preset resolution helper

- `SubAgent` gains a trailing `public readonly ?Effort $effort = null` (enum `Effort`:
  `low|medium|high|xhigh|max`); `toArray()` gains `'effort' => value|null`.
- `EngineExecutor` applies a non-null `$agent->effort` as the request's reasoning effort via
  `EngineBackend::withReasoningEffort()` — the same channel a skill's `effort` reaches
  (`App::$reasoningEffort` → `CompleteRequest::$reasoningEffort`). Sent as declared. On a provider
  that ignores the field (`!honoursReasoningEffort()`, i.e. everything but SGLang today) the run
  still runs and notes `[effort: <e> sent, but provider "<p>" ignores reasoning effort]` — on the
  output tail for prose runs, on the live stream for schema runs. Null = request untouched.
  NOTE: EngineExecutor reads ONLY `SubAgent::$effort`, not `$agent->agent->effort` (so existing
  preset-built stage agents are unchanged); the engine must copy a preset's effort into the
  SubAgent when it applies `preset:`.
- New helper `src/Agents/StagePreset.php` (for the workflow-engine agent):

  ```php
  final readonly class StagePreset {
      public string $name;
      public string $systemPrompt;      // preset body (Agent::$prompt), '' if none
      public array $tools;              // list<string> `tools:` grant as declared ([] = no narrowing)
      public array $disallowedTools;    // list<string>
      public ?string $model;            // null when the preset inherits the session model
      public ?Effort $effort;
      public ?Isolation $isolation;
      public array $skills;             // list<string>
      public ?int $maxTurns;
      public PermissionMode $permissionMode; // already provenance-gated by Agent::fromPreset()
      public Agent $agent;              // the roster Agent itself

      public static function fromAgent(Agent $agent): self;
      /** @param AgentManager|iterable<Agent> $roster
       *  @throws \InvalidArgumentException 'unknown agent preset "<n>" (known: a, b, …)' */
      public static function resolve(string $name, AgentManager|iterable $roster): self;
  }
  ```

  Roster source = the same one `Task` uses: the session `AgentManager` (`->all()`), or at load
  time `Bootstrap::agentRoster($root, $provider, $model)` (built-ins < foreign < native presets,
  user tier outranks project). No second loader. Convert the exception to a
  `WorkflowLoadException` at the YAML boundary. Suggested engine wiring: `SubAgent(agent:
  <Agent built with the stage prompt + preset tools/disallowedTools/skills/maxTurns>, model:
  stage model ?? $preset->model, effort: stage effort ?? $preset->effort, isolation: stage ??
  $preset->isolation)`.

Tests: `tests/Agents/StagePresetTest.php` (3); effort legs in
`EngineExecutorStructuredOutputTest` (3).

## 2. Not done / caveats

- No `preset:`/`effort:` YAML keys, linter, builder methods or engine merge — that is the
  workflow-engine track's (files I don't own). Only the plumbing + helper are here.
- Schema keywords outside SchemaValidator's subset are not enforced (see list above); extending
  `src/Protocol/Schema/SchemaValidator.php` (`minItems`, `minLength`, `pattern`, `const`,
  `anyOf`, `allOf`) would be a cross-track change.
- Notes on schema runs are stream-only: `AgentResult` has no side channel and the forked IPC
  frame (`AgentWorkerPool::resultToArray`) would need one too. A kept worktree on a schema stage
  is therefore visible live but not in the stage result.

## 3. Cross-track requests

1. `tests/Workflows/StageOutputSchemaTest.php` (workflow track) — three tests pin the OLD
   behaviour and now fail:
   - `testTheSchemaRidesTheTerminalRequestAndTheReplyIsTheJson`: drop the
     `requests[0]->jsonSchema === $schema` assertion; expect `null` (JSON mode is no longer used on
     the stage path). The provider's reply `{"verdict":"pass"}` is still accepted (exact-document
     text path) and output stays `{"verdict":"pass"}`.
   - `testAProviderWithoutTheCapabilityRefusesTheRunLoudly`: the refusal is now keyed on
     `supportsFunctionCalling()`, and bare `ScriptedProvider` supports tools — use a decorator with
     `supportsFunctionCalling(): false`; message still contains "cannot constrain the answer to a
     schema" and "was not started" (plus "cannot make tool calls").
   - `testTheSettledReplyDisclosesWhenToolsAteTheTerminalTurn`: replace with the new behaviour —
     after the `probe` call, script `toolCalls: [new ToolCall('c2','StructuredOutput',[…])]`;
     output is the JSON, no disclosure tail, `requests[*]->jsonSchema` all null.
2. `tests/RuntimeTest.php::testTheWriteToolRosterDoesNotDriftFromThePermissionGate` — the
   repo-wide Tool corpus now finds `StructuredOutput` (constructible with no args via its default
   `{type: object}` schema). Add `'StructuredOutput'` to `readOnlyBuiltInToolNames()` with the
   note: "writes nothing — it validates its arguments and holds the run's answer on the per-run
   object (workflow-update P0-2)". (Not edited: file not owned by B1.)
2b. `tests/Tools/BuiltInToolCorpus.php::DYNAMIC_TOOL_CLASSES` — register the per-run tool the
   way `DreamMemoryView` is registered (built for one restricted run, never by
   `Bootstrap::tools()`):
   ```php
   // workflow-update P0-2: a schema-declaring sub-agent's answer channel —
   // minted per run by `Agents\EngineExecutor`, never by `Bootstrap::tools()`.
   \SugarCraft\Code\Agents\StructuredOutputTool::class => \SugarCraft\Code\Tests\Agents\EngineExecutorStructuredOutputTest::class
       . '::testTheToolRidesTheWireWithTheSchemaAndNoJsonModeIsRequested',
   ```
   This one entry turns green both
   `BuiltInToolCorpusTest::testTheWideSweepFindsTheFlatGlobPlusExactlyTheRecordedDynamicTools`
   and `Integration/BinCandyCodeWiringTest::testBootstrapToolsShipsAWriteToolAndTheWholeBuiltInSet`
   (both currently red because of the new class). No `instances()` wiring is needed — the class
   builds with zero args.
3. Docs (docs/WORKFLOWS.md:336-341 and the `review-loop.yaml` / `docs-drift-sweep.yaml` comments):
   replace the "rides the first request only / not constrained" text with section 1's semantics.

## 4. Commits

- `e87957623` — StructuredOutput wire schema: empty maps encode as `{}`, top-level `properties`/`required` always present (BuiltInToolTest schema-shape contract; SGLang rejects `[]`).
- `098fe14a3` — candy-code: enforce stage outputSchema via a terminal StructuredOutput call; SubAgent effort + StagePreset helper (wf-impl P0-2 P1-6)
