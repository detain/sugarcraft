# wf-impl track C3 — P1-2 (`type: map`) + P1-4 (verifier votes/lenses/quorum)

Scope: `candy-code/src/Workflows/**`, `candy-code/tests/Workflows/**`.

## 1. What was implemented

### P1-4 · Verifier panels: `votes`, `lenses`, `quorum`

```yaml
verifier:
  prompt: "Via the {{lens}} lens, try to REFUTE this fix. Default to REJECT if uncertain."
  reject: '/\bREJECT\b/'
  votes: 3
  lenses: [correctness, security, repro]
  quorum: 2
  retries: 1
```

**Keys.** The three are task keys, appended to `WorkflowRegistry::YAML_TASK_KEYS`. They are parsed on every task surface, as `reject` is. Placement is the engine's pre-flight law.
- `votes`: a whole number of at least 1 (a quoted int is OK). The default is 1.
- `lenses`: a non-empty list of non-empty strings, trimmed. Its length must equal `votes`.
- `quorum`: a whole number from 1 to `votes`. The default is the majority, `floor(votes/2)+1`, available as `TaskBuilder::defaultQuorum()`.
- DTO: `WorkflowTask::$votes` (int, 1), `$lenses` (?list, null) and `$quorum` (?int, null). Helpers: `declaresPanel()` and `effectiveQuorum()`.

**Load refusals** (`WorkflowLoadException`, prefixed `… stage 'x' "verifier"`):
- `"votes" must be at least 1, got 0`
- `"votes" must be a whole number, got float`
- `"lenses" must be a list of lens names, one per vote, got string`
- `lenses must be a non-empty list of lens names, one per vote, got an empty list|a map`
- `lenses entry #2 must be a non-empty string, got an empty string|int`
- `lenses lists 2 lenses but votes is 3; give exactly one lens per vote` (this also fires for lenses without votes, because votes defaults to 1)
- `quorum 4 exceeds votes 3; at most 3 votes can pass`
- `"quorum" must be at least 1, got 0`

**Placement.** The keys are legal only on the verifier slot of a verification stage, or of a map verification step. Anywhere else, the run is refused before anything dispatches. This uses the same channel and pre-flight as `reject` (`firstRejectMisuse`). The message is `<label>: votes, lenses and quorum convene a verifier panel and belong on the verifier slot of a verification stage`. The verification executor also has a belt check for the task slot.

**Semantics.**
- A verifier with none of the three keys takes the old single-verifier path, which is byte-identical.
- A declared panel (any key away from its default, including `votes: 1` plus `lenses: [x]`) works like this:
  - The task runs once. Then all `votes` verifier dispatches run **concurrently** on one fresh stage pool:
    - its width is `votes`;
    - it carries the shared pool's executor, worker provider, forked executor and retry floor;
    - it runs on what is left of the stage budget.
  - The panel is reserved against `config.maxAgents` as one wave before anything forks.
  - Each vote is dispatched with `maxRetries: 0`, because the panel is the retry unit.
- `{{lens}}` becomes `lenses[k]` for vote k. Without lenses it stays verbatim. `{{prevResult}}` is the task output, as before.
- Each vote's result is recorded as `<verifierName or <stage>_verifier>_<k>` (1-based), e.g. `check_verifier_2`. With a schema, it also gets a typed row.
- Verdict per vote: a completed vote is scanned exactly like a single verifier (`reject` / `rejectMinOccurrences`). A **failed, timed-out or never-produced vote counts as a REJECTION.** It could not attest the claim, and it is never pattern-scanned.
- Order of judgement:
  1. **`ESCALATE:` in any vote outranks everything**, including a met quorum. The stage fails at once, burns no retry, and its error is `escalated: verifier vote 2 (b) asked for a human decision on: "…" [not retried — …]`. The `ESCALATION_MARKER` prefix is kept.
  2. An uncompilable reject pattern fails the stage CLOSED.
  3. The verdict passes iff the number of non-rejecting votes is at least the quorum.
- `retries: N` re-runs the **whole panel** up to N more times after a missed quorum. The task is not re-run.
- Pass:
  - status is completed;
  - output is `trim(task output + "\n" + vote outputs joined by "\n")`;
  - agents are the task plus every vote of every round.
- Failure:
  - the error is `verdict rejected by the panel: 1 of 3 votes passed and the quorum is 2; vote 1 (correctness) matched /\bREJECT\b/ on: "<line>"; vote 3 (repro) timed out: <error>`, plus ` [failed on all 2 panel rounds]` when there were retries;
  - the output is the joined vote outputs.
- `{{<stage>.votes}}` is the context key `<stage>.votes`. It holds compact JSON of the last round: `[{lens: string|null, rejected: bool, output: text}]`, or `data: <decoded>` in place of `output` when the verifier has an `outputSchema` and the vote decoded. It is written whenever a panel ran, even when the stage failed, and it rides the pause record. Interpolation claims the `votes` tail only when that key exists, the same law as `.failed`.

**Builder.** `TaskBuilder::votes(int)`, `lenses(array)` and `quorum(int)` each refuse a bad single value when it is set. The cross-key law (`TaskBuilder::verifierPanel()`) runs at `build()`.

**Linter (`WorkflowLinter`):**
- **Errors:**
  - panel keys off a verifier slot;
  - votes below 1;
  - a lens count that does not equal votes;
  - a quorum outside 1..votes.
- **Warnings:**
  - `This verifier panel has no reject pattern, so only a vote that fails or times out counts against the quorum…` (when votes > 1);
  - `{{lens}} is interpolated only inside a verifier panel that declares lenses; here it stays verbatim in the prompt.`
- `{{lens}}` counts as an engine token on a lensed verifier.

### P1-2 · `type: map`

```yaml
- name: per_finding
  type: map
  from: {stage: find, pointer: /findings, key: /id, max: 150}   # any source: glob | stage+pointer(+key) | context
  minSuccess: 90%
  steps:
    - name: refute
      prompt: "Refute {{item.claim}}. Default refuted=true if unsure."
      outputSchema: {$ref: verdict}
    - name: fix
      when: {step: refute, pointer: /refuted, equals: "false"}
      prompt: "Fix {{item.claim}}. Verdict: {{prevResult}} ({{refute.refuted}})"
      isolation: worktree
    - name: check
      type: verification
      task: {prompt: "Run the tests for {{item.file}}"}
      verifier: {prompt: "Via {{lens}}: REJECT if broken", reject: '/REJECT/', votes: 3, lenses: [a, b, c]}
```

**Grammar.**
- `map` is appended to `Workflow::STAGE_TYPES` (order: `stage, parallel, pipeline, verification, map`). The engine's refusal sentence now ends `'verification', and 'map' are implemented.`
- New stage key `steps` (appended to `YAML_STAGE_KEYS`).
- A map stage needs `from:`, parsed by the same sub-map parser as a parallel fan-out: `chunk`, `max`, `key`, the 4096 ceiling and the prior-stage order law all apply.
- A map stage also needs a non-empty `steps:` list.
- Allowed stage keys are `name`, `type`, `from`, `steps`, `minSuccess`, `when`, `loop`, `capture` and `cache`.
- A step is either:
  - a **task map**: task keys plus `when:` (and optionally `type: stage`); or
  - **`type: verification`** with `task:`/`verifier:` sub-maps, plus `name` and `when`. Its verifier may be a vote panel.
- Every step needs a `name`, unique within the map.
- A step's `when:` is a normal condition clause whose leaves may spell `step: <name>`, a synonym rewritten to `stage:`.

**Load refusals** (`WorkflowLoadException`):
- `is type: map, so it needs a "from" source: …`
- `is type: map, so it needs a "steps" list`
- `"steps" must be a list, got a map` · `"steps" must list at least one step`
- `step #N needs a "name": it keys the step in each item's row and is read as {{<name>.results}} inside the item`
- `reuses the step name 'a'; step names must be unique within a map`
- `step #N declares type: parallel; a map step is a task map or "type: verification" (parallel, map, pipeline and uses steps are refused in v1)`
- `step #N carries "uses"` (and the same for `parallel`/`agents`/`tasks`/`stages`/`from`/`steps`/`loop`/`minSuccess`/`capture`/`cache`/`with`) `, which a map step cannot (…)`
- `"when" reads step 'b', which has not run yet for the item; a step guard reads earlier steps only` (covers itself or a later step)
- `"when" leaf carries both "step" and "stage"; spell one`
- `is type: map, so "prompt" belongs on a step, not on the stage` (any task key at stage level)
- `is type: map, so its body is "steps"; it cannot carry "agents"` (and the same for tasks/task/verifier/stages)
- `carries "steps", which only a "type: map" stage runs` (steps on a non-map stage)
- `declares "parallel: true", which contradicts "type: map"`
- Changed messages:
  - `carries "from", which only a parallel or map stage can fan out`
  - `carries "minSuccess", which only a parallel or map stage can tolerate failures with`
  - loop: `stage, parallel, verification and map stages loop`

**Run semantics** (`WorkflowEngine::executeMapStage()` / `runMapItem()`):
- **Items.** The expansion is the same as a fan-out:
  - glob: the item is the path, and `{{path}}` is set;
  - data: `key` dedups the items.
  - An empty data list completes with `{"items":[]}`. An empty glob fails, as it does for parallel.
- **Per item, steps run in order on an item-scoped context:**
  - It starts from the stage context plus `{{item}}`, `{{item.<pointer>}}` and `{{index}}` (and `{{path}}` for a glob).
  - `{{prevResult}}` is the previous step of THIS item. It is `""` for the first step.
  - `{{<step>.results}}`, `{{<step>.output}}`, `{{<step>.<pointer>}}` (typed row of a schema'd step) and `{{<step>.votes}}` read this item's earlier steps.
  - A verification step's task records under its task name or the step name, and its verifier under `<step>_verifier`. Its votes record as in P1-4.
  - None of this leaks to other items or to later stages. A step name equal to a global stage name shadows that stage's `.output` inside the item.
- **`when` on a step** is evaluated against the item context.
  - **Decision:** a skipped step records `{status: skipped}` and **passes `prevResult` through unchanged**. Its `{{<step>.output}}` stays unset, so the token stays verbatim.
  - A clause that misses (unknown step, pointer miss) is unsatisfied, so the step skips.
- **Failure:** the first failing step drops the item. Its remaining steps never run, and they are **absent** from its `steps` row. Other items continue.
  - A throw inside a step (permission refusal, unknown tool, agent ceiling) is recorded as that step's failure.
- **Budget / keys:**
  - All steps of all items share ONE stage clock (`config.timeout` for the whole stage). `executeStage` and `executeVerificationStage` gained an optional `?int $stageClock` parameter.
  - Per-step task keys (retries, timeout, stallTimeout/restarts, resource, isolation, model, outputSchema, input) work as on any task.
- **`maxAgents`** counts every step dispatch: each step, each vote, and each retry, counted as the step settles.
  - A ceiling refusal (`AgentCeilingReachedException`, new, extends `RuntimeException` with an unchanged message) **stops admitting items and fails the stage whatever `minSuccess` says**.
  - Items not yet started are recorded as `cancelled` (`not started: the stage stopped admitting items`).
- **`minSuccess`** extends to map (`WorkflowBuilder::TOLERANT_TYPES = ['parallel','map']`) and counts ITEMS.
  - Without it, any non-completed item fails the stage: `Map stage 'per': 1 of 3 items failed: per_2 (step 'one' failed: b broke)`.
  - With it, the stage completes when enough items completed, or fails with `minSuccess 100% not met: 2 of 3 items succeeded and 3 were needed; failed: k2 (step 'go' failed: slow)`.
  - `{{<stage>.failed}}` is the JSON list of casualty names: the item key (from `key:`, or the path for a glob), else `<stage>_<n>` (1-based).
- **`stopOnFirstFailure`** (without minSuccess): no item is admitted after the first casualty. In-flight chains stop before their next step, with status `cancelled`.
- **Output.** The stage output, its typed row under the stage name, and `{{<stage>.results}}` are all the JSON document below:
  ```
  {"items":[{"key":<key|path|null>,"index":0,"status":"completed|failed|cancelled",
             "steps":{"<name>":{"status":"completed|failed|skipped","output":"…","data":{…}?,"votes":[…]?,"error":"…"?}},
             "error":"step 'x' failed: …"?}],
   "matched":M?, "dropped":[labels]?}
  ```
  - `data` is the decoded typed row of a task step, or of a verification step's TASK, when schema'd.
  - `matched`/`dropped` appear only when `from.max` cut the list. This replaces the text truncation notice, so the output stays JSON.
  - The document is pointer-addressable: `{{per.items/0/steps/fix/output}}`, `when: {stage: per, pointer: /items, empty: false}`, `from: {stage: per, pointer: /items}`.
- **Hooks:** one StageStarted per round and one StageComplete/StageFail per stage, as for any stage. **No per-item or per-step events**, by design, to avoid spam.
- **Pause/resume:** whole-stage, v1. The materialised item list rides `dynamicWaves.<stage>` exactly as a parallel `from:` does: `{paths…}` for a glob, `{items, names, chunk, (max, matched, dropped)}` for data. A resume re-runs every item from the recorded list (tested).
- **`loop:` on map** falls out naturally (`LOOPABLE_TYPES` includes `map`).
  - `{{round}}`/`{{previous}}` work.
  - `accumulate` reads the map document, e.g. `pointer: /items`.
  - **Caveat:** round 2+ replays round 1's materialised item list from the wave ledger. This is the same behaviour a looped parallel `from:` stage has.
- **`cache:`** — `stageInputHash` covers every step's task prompt/model/schema in order. It does not cover `when`/`from`, the same caveat as other stages.

**Concurrency mechanism (real, no step barrier).**
- `AgentWorkerPool` exposes only blocking batch APIs. However, its `idle()` (inside `waitForCompletion()`) `Fiber::suspend()`s whenever it is called inside a Fiber.
- The map scheduler therefore runs every item chain in its own `\Fiber`, keeping up to *width* chains in flight. Width is `concurrencyFor($workflow)`, narrowed by `from.chunk`; `maxConcurrent: 0` means all items.
- The scheduler resumes chains round-robin. Each resume is one poll of that chain's agent.
- A chain whose step settles starts its next step at once. A finished chain frees a slot for the next item.
- When a full pass settles nothing, the scheduler yields once:
  - inside a Fiber (the TUI's workflow Fiber, which repaints), via `Fiber::suspend()`;
  - outside one, by `usleep(5ms)` (`MAP_IDLE_USEC`).
- While a map runs, `dispatchOne()` takes the pool's `executeAll([$agent])` door instead of `executeOne()`, when no AgentManager is attached (`$batchDispatch`). On the Workflow-tool path, `executeOne()` with an injected executor runs inline, while `executeAll()` → `startAgent()` forks ask-free agents (P2-1).
- Limit: a dispatch that runs INLINE (an injected executor that does not fork the agent, or no pcntl) never suspends. Its chain then runs to completion before the next chain is admitted, the same limit every inline stage has.
- A vote panel inside a step runs its votes together. Agents in flight can therefore reach `width × votes`. **Width counts item chains, not agents.**
- Tested with an executor that suspends its Fiber (`testItemsDoNotWaitForEachOtherBetweenSteps`, `testTheWidthBoundsTheChainsInFlight`).

**Builder parity:**
- `WorkflowBuilder::map(string $name, array $from, array $steps, bool $capture = false, bool $cache = false)`. `$from` is the same spec as `parallel(from:)`. `$steps` is a list of:
  - a `TaskBuilder`, which must have `->name()`;
  - `WorkflowBuilder::mapStep(TaskBuilder $task, ?array $when = null)`;
  - `WorkflowBuilder::mapVerification(string $name, TaskBuilder $task, TaskBuilder $verifier, ?array $when = null)`.
- Public helpers: `stepClause()` (the step→stage rewrite) and `clauseStages()`.
- `minSuccess()` and `loop()` attach to a map.
- Stored shape:
  ```
  ['name', 'type' => 'map', 'from' => normalized, 'steps' => [
      ['name', 'type' => 'stage', 'tasks' => [WorkflowTask], 'when'?],
      ['name', 'type' => 'verification', 'task', 'verifier', 'when'?],
  ]]
  ```

**Engine plumbing extended for map:**
- `collectStageTasks` (resource locks, join ledger)
- `stageInputHash`
- `firstDeclarationRefusal` (labels `Map stage 'x' step 's'` / `… step 's' task|verifier`)
- `firstRejectMisuse`: reject or panel keys on a map task step, or on a verification step's task slot, are refused.

**Linter mirrors:**
- One plan row per step (`stage/step`, type `map-step`).
- A map records one producer under the stage name.
- Steps are checked with item scope:
  - `{{prevResult}}`, the item tokens, and earlier steps' `.results`/`.output` resolve;
  - a later step's `.results` is an error ("names no producer");
  - a verifier sees its own task.
- **Errors:**
  - a duplicate step name;
  - a refused step type;
  - a map with no steps or no `from`;
  - `from` on a non-parallel/non-map stage;
  - `minSuccess` legal on map.
- **Warning:** a step `when` that reads neither an earlier step nor an earlier stage.

**Glossary (`WorkflowGrammarGlossary`):**
- Stage type `map`, including the pipeline-vs-map naming note.
- Stage key `steps`.
- Task keys `votes`, `lenses`, `quorum`.
- Interpolation `{{lens}}` and `{{stage.votes}}`.
- Updated `type`/`from`/`loop`/`minSuccess`/verification/`{{prevResult}}`/`{{item}}`/`{{stage.failed}}` glosses.
- `tests/Tools/WorkflowToolDescriptionDriftTest.php` is green.

### Tests
- New: `tests/Workflows/WorkflowVerifierVotesTest.php` (26) and `tests/Workflows/WorkflowMapStageTest.php` (35).
- Updated pins:
  - `WorkflowYamlKeyRosterTest` (task + stage rosters, from message);
  - `WorkflowStageTypeParityTest` (five shapes, refusal sentence);
  - `WorkflowMinSuccessTest` (messages);
  - `MultiTaskStageTest` (`min($width, $chunk)` appears twice now);
  - `WorkflowAgentResultsTest` (seven join-resolving interpolation sites).
- Whole `tests/Workflows` plus the drift test: 916 tests, green. `tests/Tools/WorkflowToolTest.php` and `WorkflowToolLintAndResultTest.php` are green.

## 2. Not done / decisions to note
- **Pause granularity is whole-stage.** A pause mid-map re-runs every item on resume. Per-item resume is not done.
- **No per-item/per-step lifecycle hook events** (deliberate).
- A vote panel's width is `votes` and is not bounded by `maxConcurrent`. Inside a map, agents in flight can reach `width × votes`.
- The parallel-stage glob expansion was extracted verbatim into `expandGlobSource()`, which is shared with map. `expandFromData()` additionally returns `matched`/`dropped`.
- `stageInputHash` does not cover step `when:`/`from:`, the same caveat as other stages.
- Accumulate-loop on map: round 2+ replays round 1's item list. This is inherited from the wave ledger, the same as parallel `from:`.

## 3. Cross-track requests
- **Docs agent (`docs/WORKFLOWS.md`, skill):** document both sections above. Specifically:
  - Candy `pipeline` is the reference's sequential chain, and candy `map` is the reference's `pipeline()` (say this loudly).
  - The map output document shape and the skipped-step prevResult rule.
  - `{{<stage>.votes}}`, and that mechanical vote failures count as rejections.
  - Width counts item chains.
  - The inline-executor limit.
- **Docs:** the skill's §5 says `when` sits on a map step; the YAML spelling is `when: {step: <name>, …}` (or `stage:`).
- **WorkflowTool (B2):** nothing is required. The plan schema/description is generated from the rosters. If the tool's JSON schema enumerates stage types by hand anywhere, add `map`.

## 4. Commits
- `52ce92ec8` verifier panels — votes, lenses and quorum, {{lens}} and {{<stage>.votes}} (P1-4)
- `be1cb825a` type: map with Fiber-scheduled item chains, per-item when, minSuccess over items, JSON item rows, WorkflowBuilder::map() (P1-2)
