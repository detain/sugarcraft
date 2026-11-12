# candy-code workflow handling — gap analysis against the Claude Code `Workflow` model

Date: 2026-11-12 · Scope: `candy-code/src/Workflows/*`, `src/Tools/BuiltIn/WorkflowTool.php`,
`src/Host/Commands/WorkflowCommand.php`, `docs/WORKFLOWS.md`, `examples/workflows/*.yaml`,
`workflows/deep-research.php`.

Reference model ("gold standard"): the Claude Code `Workflow` tool and its `workflow-authoring`
skill. This is the same document as the one you pasted. It covers `agent()` / `pipeline()` /
`parallel()` / `phase()` / `log()` / `budget` / `workflow()` / resume-by-prefix, plus the quality
patterns: adversarial verify, perspective-diverse verify, judge panel, loop-until-dry, multi-modal
sweep, completeness critic, and no silent caps.

The companion file `workflow-authoring-skill.md` is a candy-code `SKILL.md` written as if every
recommendation here has landed. §6 maps each skill section back to the finding it depends on.

---

## 1. Executive summary

candy-code's workflow engine is better engineered than the reference in several ways:

- validation is fail-closed: unknown keys are refused and the loader never coerces;
- pause records are crash-safe, carry process identity, and detect orphaned runs;
- host resource locks keep stages from colliding;
- tool permissions are checked against stage declarations before anything runs;
- `reject:` verdict gating plus `ESCALATE:` sends a question to a human;
- `capture:` writes large outputs to files;
- the `when:`/`loop:` grammar is closed.

None of that needs to change.

The gaps are about **orchestration expressiveness and model ergonomics**. The reference treats a
workflow as *the way to be comprehensive and confident at scale*: fan out over a discovered work
list, verify every claim adversarially, loop until nothing new turns up, and report what was
dropped. candy-code's grammar can describe a fixed sequence of stages very precisely. It cannot
yet express most of those quality patterns, and the model-facing tool doesn't tell the model
about half of what the grammar *can* express.

Highest-leverage fixes, in order:

| # | Fix | Why first |
|---|---|---|
| P0-1 | Make the `Workflow` tool's description/schema advertise the real grammar (generated + drift-tested) and ship the skill | The model currently authors from a 1-paragraph subset; `when`/`loop`/`verification`/`outputSchema`/`from`/`input`/`capture`/`isolation`/`model` are invisible to it |
| P0-2 | Enforce `outputSchema` with a terminal StructuredOutput call, not "first request only" | Every verify/judge/loop pattern needs a verdict a machine can read; today a reviewer that uses tools returns unconstrained prose |
| P0-3 | Default concurrency cap and fan-out ceilings | `maxConcurrent` unset = unbounded; `from: {glob: src/**/*.php}` over candy-code's 1,370 source files forks 1,370 workers at once |
| P0-4 | Lint before run (tool + `/workflow run`) and return structured, unclipped results | Unresolved `{{tokens}}` reach agents verbatim; 8 KB middle-elision corrupts schema'd JSON in the tool report |
| P1 | Data-driven fan-out (`from: {stage, pointer}`), per-item streaming (`type: map`), quorum tolerance (`minSuccess`), multi-vote verification (`votes`/`quorum`/`lenses`), pointer-aware conditions, `loop` accumulate/dry, `preset:`/`effort:`, budgets, resumable tool runs | Brings the quality patterns into the grammar |
| P2 | Real parallelism on the tool path, progress narration, docs/example drift, rewritten examples | Polish + correctness of documentation |

---

## 2. Concept map: reference primitive → candy-code today

| Reference (Claude `Workflow`) | candy-code equivalent | Status |
|---|---|---|
| Script = deterministic JS control flow | YAML data (project + user tiers) or PHP builder (user tier only) | ✅ Different by design. Data-not-code is the right call for a repo-shipped format, but every control-flow shape has to be a grammar key (see P1) |
| `agent(prompt, {schema})` → validated object | stage task + `outputSchema:` | ⚠️ Schema rides the **first request only** (`EngineExecutor.php:534` appends the "not constrained" disclosure). The reference forces a terminal StructuredOutput call and retries on mismatch |
| `agent(..., {label})` | `name:` on task | ✅ But hyphenated names (`docs-explorer`, `research-planner`) are recorded and **unaddressable** by `{{name.results}}` (WORKFLOWS.md:771) |
| `agent(..., {phase})` / `phase()` / `meta.phases` | stage names → live-agent pane tiles | ✅ Equivalent |
| `agent(..., {model})` | `model:` | ✅ Stricter than reference (refuses unservable) |
| `agent(..., {effort})` | — | ❌ No per-stage reasoning effort, though skills and presets both support `effort` |
| `agent(..., {agentType})` | `agent:` / `type:` | ❌ **Label only**: "WorkflowEngine contains no reference to AgentPreset" (WORKFLOWS.md:1206). `.candy-code/agents/reviewer.md` (tools, `permissionMode: plan`, `effort: high`, skills) cannot be used by a stage |
| `agent(..., {isolation:'worktree'})` | `isolation: worktree` | ✅ Equivalent; worktrees stay unmerged (same as reference) |
| `parallel(thunks)` (barrier, null on failure) | `parallel: true` + `agents:` | ⚠️ Barrier ✅, but **one failed agent fails the whole stage** (`WorkflowEngine.php:4154-4163`). There is no equivalent of `.filter(Boolean)` and no quorum |
| `pipeline(items, s1, s2…)` (per-item, **no barrier**) | — | ❌ Missing. candy's `type: pipeline` is a *sequential step chain* (one item), which is the reference's plain `await` sequence. **Name collision**: authors coming from the reference will misread it |
| Fan out over a discovered list (`items.map(...)`) | `from: {glob, chunk}` | ⚠️ Glob only. You can't fan out over a *prior stage's output* (the scout-then-fan-out "hybrid" the reference recommends). `fix-wave.yaml` works around this by hand-writing 3 shards that pick "every 3rd entry" from a CSV string |
| Concurrency cap `min(16, CPUs-2)` | `maxConcurrent` (default **null = no cap**, `Workflow.php:43`, `AgentWorkerPool.php:623`) | ❌ Unsafe default |
| ≤4096 items per call, ≤1000 agents per run (loud errors) | — | ❌ No expansion ceiling and no total-agent backstop (`expandFromGlob`, `WorkflowEngine.php:2662`) |
| `budget.total/spent/remaining` (hard ceiling) | — | ❌ Only wall-clock budgets. No token/cost ceiling, so loop-until-budget can't be expressed |
| `while (...)` loops, loop-until-dry, dedupe vs `seen` | `loop: {max, until}` on plain stages only | ⚠️ Can't loop compound stages, can't see the prior round's output, has no accumulator, no dedup, no "K dry rounds" exit |
| `if (...)` on structured results | `when:` with `equals`/`contains`/`matches` on **text** | ⚠️ Can't address a JSON leaf, even though schema'd stages already record typed rows and `{{build.items/0/id}}` works in *prompts* |
| Adversarial verify (N refuters, majority) | `type: verification` with **one** verifier + `reject:` + `retries` | ⚠️ Single judge. Retries re-roll the same judge; they don't add independent votes |
| Perspective-diverse verify (lenses) | — | ❌ |
| Judge panel / tournament | — | ⚠️ Expressible by hand as parallel → stage, but with no scoring/selection primitive |
| `workflow(name, args)` composition | `uses:` / `with:` (depth 3, cycle-checked) | ✅ Better than reference (lint walks edges) |
| `log()` narration | lifecycle hooks (`StageStarted`/`Complete`/`Fail`) | ⚠️ Observe-only hooks; nothing narrates progress *to the model* or the transcript on the tool path |
| Resume: longest unchanged prefix, cached | `/workflow pause`/`resume` + opt-in `cache:` | ✅ for registry runs. ❌ **Workflow-tool plans are not resumable** (WORKFLOWS.md:1161): a 40-minute model-authored run that dies at stage 5 restarts from zero |
| journal.jsonl of every agent return | pause record + `capture:` files | ⚠️ Only for registry runs; the tool run leaves nothing to inspect |
| `args` (real JSON values) | `context` (strings only: `additionalProperties: {type: string}`) | ⚠️ A list has to be smuggled through a CSV string (see `fix-wave.yaml`) |
| Subagents get CLAUDE.md, not the conversation | Stage agents don't see the conversation | ✅ Same law; the tool description says so |
| Permission | `Workflow` is Write-class + declaration pre-flight | ✅ Stronger than reference |

---

## 3. Findings and recommendations

Each finding gives: **today** (with code citation) → **why it matters** (the reference pattern it
blocks) → **recommendation** (concrete grammar or code).

### P0-1 · The model-facing tool advertises a fraction of the grammar

**Today.** `WorkflowTool::description()` (`src/Tools/BuiltIn/WorkflowTool.php:95`) documents
`name`, `description`, `config.timeout`, plain / `tasks:` / `parallel: true` stages, and five
interpolation forms. The `plan` schema property says only "`stages:` (required), optional `name`,
`description` and `config: {timeout}`". It doesn't mention:
`when`, `loop`, `type: verification` (+ `reject`, `ESCALATE:`), `type: pipeline`, `uses`/`with`,
`from: {glob, chunk}`, `input: {from, as}`, `outputSchema`, `{{stage.<pointer>}}`, `capture`,
`cache`, `isolation`, `model`, `resource`, `stallTimeout`/`restarts`, or `config.maxConcurrent`.

**Why it matters.** The reference puts the whole API, the gotchas and the quality patterns in front
of the model, through the tool description plus the skill. In candy-code the model can't use
features it has never been told about, so every model-authored plan is a straight-line sequence.
The engine work since C1–C13 is unreachable from the model.

**Recommendation.**
1. Generate the description's grammar paragraph from `WorkflowRegistry::YAML_STAGE_KEYS`,
   `YAML_TASK_KEYS` and `Workflow::STAGE_TYPES`, and add a `WorkflowToolDescriptionDriftTest`
   in the style of the existing `ReadmeRosterDriftTest`. Then a new key can't land without the
   model learning about it.
2. Keep the description short: shapes plus one line per key. Ship `workflow-authoring` as a
   **built-in skill** (`src/Skills/BuiltIn/workflow-authoring/SKILL.md`) for patterns and
   examples, and have the description end with "load the `workflow-authoring` skill before
   authoring a non-trivial plan". This is how the reference splits tool description from skill.
3. Give `plan` a JSON-schema `description` that names the stage shapes, so providers that show
   schema text but drop long tool descriptions still see them.

### P0-2 · `outputSchema` is not enforced once the agent uses a tool

**Today.** The schema "rides the first request only". A model that calls a tool first "ends the
turn unconstrained", and the output gets
`[the reply was not constrained to the output schema because the model ran tools before answering]`
(`src/Agents/EngineExecutor.php:534`; WORKFLOWS.md:336-341). Every example works around it in
comments: "A driver should treat any output that does not parse as a verdict as NEEDS-FIX"
(`review-loop.yaml`, `docs-drift-sweep.yaml`).

**Why it matters.** Structured output is the backbone of every reference pattern: verdict votes,
dedup keys, fan-out lists, loop exit conditions. Reviewers *must* read code before answering, so
the stages that most need a schema are exactly the ones that lose it. Pointer interpolation
(`{{review.verdict}}`), pointer conditions (P1-5) and data fan-out (P1-1) all inherit this
weakness.

**Recommendation.** Use the reference's mechanism. When a task has an `outputSchema`, add a
synthetic terminal tool `StructuredOutput` whose input schema *is* the task's schema, and tell
the agent its run ends by calling it. On a final text answer with no call, re-prompt once
("call StructuredOutput now") within the same step budget. Validate the call's input against the
schema; on mismatch, return the validation error as the tool result so the model retries. The
stage output is the validated JSON. Keep the disclosure path only for providers that can't do
tool calls at all, and refuse those up front as you do today for providers without JSON-schema
support. This removes every "treat unparseable as dirty" workaround from the examples.

### P0-3 · Unbounded concurrency and fan-out

**Today.**
- `Workflow::$maxConcurrent` defaults to `null`, which means "no cap" (`Workflow.php:34,43`).
  `AgentWorkerPool` "fills all of them" (`AgentWorkerPool.php:621-623`). The docs confirm "every
  task of a parallel stage is dispatched at once" (WORKFLOWS.md:118).
- `from: {glob}` has no ceiling on matched files (`WorkflowEngine::expandFromGlob`,
  `:2662`). `chunk` is optional and "a ceiling, never a floor".
- There's no total-agents-per-run backstop, and `loop: max` × parallel width × retries ×
  `restarts` is unbounded in product.

**Concrete failure.** `/workflow run audit` with `from: {glob: "src/**/*.php"}` in this repo
expands to 1,370 tasks (`find candy-code/src -name '*.php' | wc -l`). With `maxConcurrent`
unset, all 1,370 are forked at once, each running a provider tool loop.

**Recommendation.** Copy the reference's limits exactly; they are tuned:
- Default `maxConcurrent` = `min(16, max(1, nproc - 2))` when unset. `0` stays an explicit "no
  cap" and the linter warns on it.
- Hard ceiling of **4096** items per fan-out stage, as a *loud* load/run failure ("expanded N
  files; the per-stage ceiling is 4096 — narrow the glob"), never silent truncation.
- An optional `max:` key on `from:` that truncates **and records what it dropped** in the stage
  output (`[from: kept 200 of 1370 matches; dropped list at <capture path>]`). This is the "no
  silent caps" law.
- `config.maxAgents` (default 1000) as a run-wide dispatch backstop. Dispatch past it fails the
  stage with "agent ceiling reached".

### P0-4 · No lint before run; the tool report corrupts structured output

**Today.**
- `WorkflowLinter::lint()` is only called from `/workflow lint`
  (`src/Host/Commands/WorkflowCommand.php:701`). `WorkflowTool::execute()` goes straight from
  `fromYamlString()` (`:156`) to `runWorkflow()` (`:180`), and so does `/workflow run`. The
  linter's own header says an unresolved `{{token}}` is "left VERBATIM in the prompt the agent
  receives". The engine pre-flights catch only tools and `reject` placement.
- The tool's report clips each stage's output to 8 KB with **middle elision**
  (`STAGE_OUTPUT_MAX_BYTES = 8192`, `clip()` at `:227`). A schema'd stage with a 12 KB JSON
  document reaches the model as two halves of a broken document.
- The report is prose (`### Stage N: …`). A model that wants to branch on stage results in its
  *next* turn has to re-parse prose.

**Recommendation.**
1. Run `WorkflowLinter::lint($workflow, $context, $engine->tools()->names(), $resolver)` in
   `WorkflowTool::execute()` and in `WorkflowCommand::start()`. **Errors refuse the run** (zero
   spend) and come back in the result. **Warnings ride the report header**, so the model sees "3
   warnings: bare unknown `{{scope}}` in stage `audit`" next to the outcome.
2. Add a `dryRun: true` input to the tool that returns the lint report and the expanded plan rows
   (`renderStageLedger`-style) without dispatching. This is the reference's habit of checking the
   shape before the orchestration step.
3. Return a machine block: the human headline plus a fenced JSON document
   `{status, runId, stages:[{name, status, error, attempts, tokens, cost, data?, output?, capture?}]}`.
   - `data` is the validated schema object (P0-2), **never clipped**. If it's over budget, write it
     to the capture file and return `{capture: path, preview}`.
   - `output` (prose) keeps head/tail elision, but the elision marker includes the capture path
     so nothing is unrecoverable.
4. Make `capture: true` the default for stages run through the tool (the report already clips), so
   every elided byte has a file behind it.

### P1-1 · Fan-out from data, not just from the filesystem

**Today.** `from:` accepts only `{glob, chunk}` (`WorkflowRegistry.php:2433-2454`). To fan out
over a list another stage produced, you hand-write N shards that each parse a CSV and take "every
Nth entry" (`fix-wave.yaml`). The shard count is fixed when the file is written, and a 4-file list
over 3 shards is lopsided.

**Why it matters.** The reference's main recommended move is *hybrid*: scout first to discover the
work list, then fan out over it. Review (dimensions → findings → verify each), Migrate (discover
sites → transform each) and loop-until-dry all fan out over data.

**Recommendation.** Extend `from:` with a data source:

```yaml
- name: verify
  parallel: true
  from:
    stage: find            # a prior schema'd stage
    pointer: /findings     # RFC 6901 into its typed row; must be an array
    key: /id               # optional: dedup + stable task naming per item
    chunk: 8
    max: 200               # optional; truncation is recorded, never silent
  prompt: "Try to refute: {{item.claim}} at {{item.file}}:{{item.line}}"
```

- `{{item}}` is the element (strings verbatim, objects as JSON). `{{item.<pointer>}}` reads
  leaves, using the same pointer rules as `{{stage.<pointer>}}`. `{{index}}` is 0-based.
- Validation at parse: `stage:` must be a prior stage (same law as `input.from`). At run time a
  non-array pointer fails the stage loudly.
- Resume law: the materialised list rides the pause record, exactly as the glob expansion does
  today.
- Also accept arrays in tool/CLI `context` (`additionalProperties: {}` instead of
  `{type: string}`) plus `from: {context: files}`. Then a caller can pass a real list instead of
  a CSV string, which fixes the reference's "pass arrays as actual JSON values" pitfall at the
  source.

### P1-2 · Per-item streaming: a real `pipeline()` (call it `type: map`)

**Today.** Every stage is a barrier: stage N+1 starts only when every agent of stage N has
settled. `type: pipeline` is a sequential chain of steps for one item.

**Why it matters.** The reference's #1 rule is "DEFAULT TO pipeline()": item A can be in its
verify step while item B is still being found, so wall-clock is the slowest single chain, not the
sum of the slowest per stage. For a find → verify → fix flow over 50 items with skewed durations,
barriers waste most of the fast items' time.

**Recommendation.** Add `type: map`. Don't overload `pipeline`, whose name already means
something else here:

```yaml
- name: per-finding
  type: map
  from: {stage: find, pointer: /findings, key: /id}
  steps:                       # each item flows through these independently
    - name: refute
      prompt: "Refute {{item.claim}}. Default refuted=true if unsure."
      tools: [Read, Grep]
      outputSchema: {$ref: verdict}
    - name: fix
      when: {step: refute, pointer: /refuted, equals: "false"}   # per-item guard
      prompt: "Fix {{item.claim}}. Verdict: {{prevResult}}"
      tools: [Read, Edit]
      isolation: worktree
  minSuccess: 90%
```

Semantics:
- Items run concurrently up to the stage's width. Each item runs its steps in order, with
  `{{prevResult}}` and `{{<step>.results}}` scoped *to that item*.
- A step may be a plain task or a `type: verification` sub-stage, so a per-item vote panel is one
  step. `parallel`, `map` and `uses` are refused as steps in v1.
- A step failure drops **that item** (the reference's "stage throws → item becomes null").
  Other items continue.
- Output is a JSON array of per-item rows `{key, status, steps:{…}}`, pointer-addressable
  downstream.
- Pause granularity can stay whole-stage in v1, which is the same honesty note `parallel` already
  carries.
- Document the naming loudly in WORKFLOWS.md: candy `pipeline` = reference sequential `await`
  chain; candy `map` = reference `pipeline()`.

### P1-3 · Partial-failure tolerance in fan-outs

**Today.** In `executeParallelStage`, any `Failed`/`TimedOut` agent sets `$anyFailure`, and the
stage fails (`WorkflowEngine.php:4154-4163`). The run then stops (fail-fast across stages). With
200 verify agents, one provider hiccup on item 173 kills the run.

**Recommendation.** Add `minSuccess: <int | "NN%">` on `parallel` and `map` stages. The default
stays "all", which keeps today's behaviour byte for byte. When met, the stage **completes**, and
its output lists each casualty: `[3 of 200 agents failed: verify_12 (timed_out), …]`. A
`{{<stage>.failed}}` pointer gives the list to a follow-up retry stage. This is the reference's
`.filter(Boolean)` with an explicit, visible threshold, which fits candy's "never silent" law
better than silently dropping nulls.

### P1-4 · Adversarial and perspective-diverse verification

**Today.** `type: verification` has exactly one `verifier:`. `retries` re-runs *the same* verifier
after a reject. That's a retry, not an independent vote.

**Why it matters.** "Spawn N independent skeptics per finding, each prompted to REFUTE. Kill if a
majority refute." That pattern is the reference's main defence against plausible-but-wrong
output. Diverse lenses (correctness, security, repro) catch failure modes that redundancy can't.

**Recommendation.** Extend the verifier sub-map:

```yaml
verifier:
  prompt: "Via the {{lens}} lens, try to REFUTE this fix. Default to REJECT if uncertain."
  tools: [Read, Grep, "Bash(vendor/bin/phpunit *)"]
  reject: '/\bREJECT\b/'
  votes: 3                                  # independent verifier dispatches
  lenses: [correctness, security, repro]    # optional; length must equal votes; {{lens}} per vote
  quorum: 2                                 # passes if ≥ quorum votes do NOT reject
```

- Votes run concurrently and share the stage budget.
- An `ESCALATE:` from **any** vote still outranks everything (the existing law).
- With `outputSchema`, each vote's verdict is recorded and `{{stage.votes}}` exposes them.
- The rejection reason quotes every rejecting vote's matched line.
- `retries` keeps its meaning: re-run the whole panel after a failed quorum.

### P1-5 · Conditions and loops that read structure

**Today.** `StageCondition` ops are `equals`/`contains`/`matches` over a stage's **text**
(`StageCondition.php`, reading `$context[$stage.'.output']`). `loop:` is plain stages only, rounds
can't see the prior round's output, and there's no accumulator.
`review-loop.yaml` says outright "the loop therefore lives with the DRIVER".

**Recommendation.**
1. Add an optional `pointer:` to a condition leaf, plus a fourth op `empty: true|false` (array or
   string emptiness):
   `when: {stage: review, pointer: /verdict, equals: NEEDS-FIX}`,
   `until: {stage: find, pointer: /fresh, empty: true}`. A pointer that resolves nowhere is
   unsatisfied (the existing fail-closed law).
2. Allow `loop:` on `parallel`, `verification` and `map` stages. Inject `{{previous}}` (the prior
   round's output) alongside `{{round}}`.
3. Add **loop-until-dry with dedup**, the reference's composed exhaustive-review pattern, as a
   closed grammar:

   ```yaml
   loop:
     max: 8
     accumulate: {pointer: /findings, key: /id}  # engine keeps the dedup set across rounds
     until: {dry: 2}                             # 2 consecutive rounds that add nothing new
   ```

   `{{seen}}` interpolates the keys collected so far, so finders can be told "skip these".
   `{{fresh}}` is this round's new items. The stage's final output is the accumulated set as JSON.
   Dedup happens against everything **seen**, not everything confirmed, which is the reference's
   convergence rule.
4. Let `when:` sit on a `map` step (per-item guard, P1-2).

### P1-6 · Stages can't use agent presets or set effort

**Today.** `agent:` is a display label (WORKFLOWS.md:1206-1217). The repo's
`.candy-code/agents/reviewer.md` declares `tools`, `disallowedTools: [Write, Edit]`,
`permissionMode: plan`, `effort: high`, `skills`, `isolation: worktree`. None of it reaches a
stage called `agent: reviewer`. There's no stage `effort:` key.

**Recommendation.**
- Add a `preset: <name>` task key. It resolves through `AgentPresetRegistry` (the same tiers
  `Task` uses) and supplies the system prompt, tools, model, effort, skills and isolation. Stage
  keys override preset fields. An unknown preset is a load error. `agent:` stays a label, so
  nothing existing changes meaning.
- Add `effort: low|medium|high|xhigh|max` on tasks, riding the request's `reasoningEffort` the way
  a skill's `effort` does. Use the reference's guidance: `low` for mechanical stages (format,
  collect, summarise), higher only for the hardest judge/verify stages.

### P1-7 · Token and cost budgets

**Today.** Only wall-clock budgets (`timeout`, `stallTimeout`). Totals are reported after the
fact.

**Recommendation.** Add `config.maxTokens` and `config.maxCost` as **hard** ceilings, checked
before each dispatch, as the reference's `budget.total` is. A dispatch past the ceiling fails its
stage with "budget exhausted (spent X of Y)" and the run pauses rather than fails, so `resume`
with a raised ceiling continues. Expose `{{budget.remaining}}` for prompts, and allow
`loop: {until: {budgetBelow: 50000}}` for loop-until-budget. Accept a `budget` input on the tool
so a user's "+500k" directive flows into the plan.

### P1-8 · Tool-authored runs can't be resumed

**Today.** "A plan has no registry name, so `runWorkflow()` writes no pause file, remembers no
result" (WORKFLOWS.md:1161). The reference resumes any run by `runId`: the longest unchanged
prefix of agent calls is served from cache, and you can edit the script and resume.

**Recommendation.**
- Give tool runs an id `plan-<sha8 of the plan YAML>-<n>` and write the same crash-safe record
  under `<workflowsPath>/.running/`. Return `runId` in the result.
- Accept `resumeFrom: <runId>` on the tool. Stages whose `stageInputHashes` digest is unchanged
  are carried (the existing C13 `cache:` machinery, enabled implicitly for tool resumes). The
  first edited stage and everything after it run live. That's the reference's prefix rule, and
  candy already has the hash.
- `/workflow status <runId>` and `/workflow resume <runId>` then work for model-authored runs too.

### P2-1 · Parallel stages run serially on the tool path

**Today.** The tool uses an injected synchronous `EngineExecutor`, so "the agents of a
`parallel: true` stage take turns" (`WorkflowTool.php` header; WORKFLOWS.md:1150-1155). The
reason is sound: permission `Ask`s must reach the turn's approver. But a 10-agent read-only sweep
takes 10× wall-clock.

**Recommendation.** Fork agents whose *effective* tool set can't produce an `Ask`: every declared
tool is read-only/`ParallelSafe`, or the gate pre-resolves every declaration to `Allow`. Keep the
rest inline. Alternatively, route forked `Ask`s back to the parent over the progress channel.
Report which mode each stage used in the result, so "parallel" in the model's mental model
matches what happened.

### P2-2 · Progress narration

**Today.** Lifecycle hooks are observe-only, and the tool returns only at the end, with heartbeats
to stop it being treated as hung.

**Recommendation.** Stream one line per stage settle (`✓ find (12 findings, 41k tok)`,
`✗ verify: 2/200 failed, minSuccess met`) to the live-agent pane and the transcript. Add an
optional stage `announce:` template that is interpolated at settle, the reference's `log()`.
Truncations (P0-3 `max:`), `minSuccess` casualties and budget stops must always announce, per the
no-silent-caps law.

### P2-3 · Documentation and example drift (verified)

| Where | Claim | Reality |
|---|---|---|
| `docs/WORKFLOWS.md:1265-1270` | "`timeout` and `reject` have **no YAML spelling**" | Both are in `YAML_TASK_KEYS` (`WorkflowRegistry.php:39`), and the same doc documents `timeout:` (:136-141) and `reject:` (:519-523) |
| `docs/WORKFLOWS.md:936-941` | `stageInputHashes`: "today nothing acts on it … future C13" | `cache:` (:470-500) acts on it today |
| `examples/workflows/{fix-wave,review-loop,docs-drift-sweep}.yaml` headers | "run-context values are whitespace-split, so no value may contain a space" | `WorkflowCommand::parseRunArguments` is quote-aware (`key="two words"`, `WorkflowCommand.php:238-250`) and refuses malformed pairs |
| same headers | "Nothing in this file gates …"; "the loop lives with the DRIVER"; "WORKFLOWS.md is the source of truth for what the grammar can gate" | `when:`, `loop:`, `type: verification` + `reject:` exist. The examples were never upgraded to use them |
| `review-loop.yaml` | build → review → *unconditional* address | Should be `type: verification` with `reject:` + `retries`, or `loop:` with a `when:`-guarded address |
| `workflows/deep-research.php` | `refine` emits "RESEARCH COMPLETE" or a new question | Nothing loops on it. Needs `loop: {until: {stage: refine, contains: "RESEARCH COMPLETE"}}` around a composed `uses:` body, or the stage should be deleted |
| `workflows/deep-research.php` | task names `docs-explorer`, `research-planner`, … | Hyphenated names can't be read back with `{{name.results}}` (WORKFLOWS.md:771). Use `docs_explorer` |
| `workflows/deep-research.php` | `report` stage holds `Write`/`Edit` | It returns the report as text. Write access isn't needed, and it widens the permission ask |
| `workflows/deep-research.php` | every stage interpolates whole prior outputs (`{{investigate.output}}` ×3) | Use `capture: true` on `investigate` and `input: {from: [plan, investigate, synthesize], as: all}` |
| WORKFLOWS.md:705 | 0-based stage numbers in errors vs 1-based in example comments | Pick one (the tool report already prints 1-based `Stage %d`). Make errors 1-based too |

Add drift guards to match the rest of candy-code: a test that every key in `YAML_STAGE_KEYS ∪
YAML_TASK_KEYS` appears in WORKFLOWS.md's key tables *and* in the tool description, and a test that
every example under `examples/workflows/` loads and lints with zero errors.

### P2-4 · Named, reusable output schemas

**Today.** `outputSchema:` is per task: an inline map, a JSON string or a file path. `fix-wave.yaml`
repeats the same 14-line schema three times, once per shard. In a votes/lenses panel or a
multi-finder stage the repetition multiplies, and copies drift.

**Recommendation.** Add a top-level `schemas:` map, and let `outputSchema: {$ref: <name>}` resolve
against it at load. An unknown `$ref` is a load error. The resolved schema is what
`stageInputHashes` digests, so `cache:` stays honest when a shared schema changes.

### P2-5 · Example library should demonstrate the quality patterns

Replace or extend the examples with one file per reference pattern. Each should lint clean and use
current keys:

| File | Pattern |
|---|---|
| `review-changes.yaml` | dimensions (parallel) → `find` with `loop.accumulate` / `until: {dry: 2}` → `map` refute with `votes: 3, quorum: 2` → summary |
| `migrate.yaml` | scout (schema list of sites) → `map` transform (`isolation: worktree`) → verify (`Bash(vendor/bin/phpunit *)`) → `minSuccess` |
| `judge-panel.yaml` | 3 designers with distinct angles → 3 judges scoring all (barrier justified) → synthesis grafting runner-ups |
| `research.yaml` | multi-modal sweep (by-file, by-symbol, by-history, by-docs) → completeness critic → second round only `when:` the critic found gaps |
| `review-loop.yaml` | rewritten as `type: verification` + `reject` + `retries` + `ESCALATE:` |

---

## 4. Things candy-code does better — keep them and teach them

The skill documents these as strengths:

- **Fail-closed loader.** Unknown keys, wrong types and duplicate names are refused, never
  coerced. A model-authored typo costs zero tokens.
- **Permission pre-flight on declarations** and argument-scoped tools (`Bash(git *)`), enforced per
  call by `SubAgentGrantHook`. The reference has no per-stage tool narrowing.
- **`reject:` + `ESCALATE:`.** A verdict is distinct from a question, and escalation never burns
  retries.
- **Crash-safe per-stage records** with pid/start-time identity and orphan detection.
- **`resource:` host locks**, taken in sorted order on every exit path.
- **`capture:`**, which keeps prompt envelopes small and cache-stable.
- **`retries` vs `restarts`.** Separate budgets for model failure and silence.
- **`uses:` composition** with depth and cycle checks done at lint time.

---

## 5. Suggested delivery order (PR-sized)

1. **Docs/examples truth pass** (P2-3) + drift tests. No behaviour change. Unblocks the rest by
   giving authors correct docs.
2. **Tool honesty** (P0-1, P0-4): generated description + drift test, lint-before-run,
   `dryRun`, JSON result block, default `capture` on the tool path, built-in `workflow-authoring`
   skill.
3. **Safety rails** (P0-3): default width, 4096 ceiling, `from.max` with recorded drops,
   `config.maxAgents`.
4. **StructuredOutput enforcement** (P0-2). The prerequisite for 5–7.
5. **Data fan-out + tolerance** (P1-1, P1-3), plus array `context`.
6. **`type: map`** (P1-2).
7. **Verification votes/lenses/quorum** (P1-4), **pointer conditions + loop accumulate/dry**
   (P1-5).
8. **`preset:`/`effort:`** (P1-6), **budgets** (P1-7), **tool-run resume** (P1-8).
9. **Tool-path forking** (P2-1), **narration** (P2-2), **named schemas** (P2-4), **pattern
   example library** (P2-5).

Per the repo's PR rules, every grammar addition needs: a parse arm plus a refusal in
`YAML_*_KEYS`, a linter mirror, a WORKFLOWS.md section, the tool-description generator update (now
drift-pinned), and a `suite-figure.json` / `parallel-tests-durations.tsv` refresh for new test
files.

---

## 6. How the skill file maps to this report

`workflow-authoring-skill.md` assumes every item above has shipped. Sections that rely on a
proposed feature:

| Skill section | Depends on |
|---|---|
| "outputSchema is enforced" | P0-2 |
| Default width / ceilings / `from.max` / `maxAgents` | P0-3 |
| `dryRun`, lint-before-run, JSON result block | P0-4 |
| `from: {stage, pointer, key}`, array `context` | P1-1 |
| `type: map` | P1-2 |
| `minSuccess` | P1-3 |
| `votes` / `lenses` / `quorum` | P1-4 |
| `pointer:` + `empty:` in conditions, `loop.accumulate`, `until: {dry}`, `{{previous}}`, `{{seen}}` | P1-5 |
| `preset:`, `effort:` | P1-6 |
| `maxTokens` / `maxCost` / `{{budget.remaining}}` / tool `budget` | P1-7 |
| `runId` / `resumeFrom` | P1-8 |
| `announce:` | P2-2 |
| top-level `schemas:` + `outputSchema: {$ref: …}` | P2-4 |

Because the loader refuses unknown keys, installing the skill before a feature lands is safe. A
plan that uses a not-yet-shipped key fails at load with "`has unknown key "votes"; supported keys:
…`", costs zero tokens, and the model rewrites it without the key. To ship the skill early anyway,
delete the rows above that haven't landed from the skill's key tables.
