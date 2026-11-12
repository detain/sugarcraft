---
name: workflow-authoring
description: Reference for writing a workflow plan (YAML for the Workflow tool or a saved workflow file) — stage shapes, keys, interpolation, fan-out, verifier panels, budgets, resume, and quality patterns. Load before authoring a non-trivial plan; loading it does not justify running one.
user-invocable: true
disable-model-invocation: false
paths:
  - "*workflows/*.yaml"
  - "*workflows/*.yml"
---

# Workflow authoring

A workflow spreads work across many sub-agents. Use one to be **comprehensive** (split the work
and cover it in parallel), to be **confident** (get independent, adversarial checks before you
commit to an answer), or to work at a **scale** one context can't hold (migrations, audits, broad
sweeps). The plan is where you write that structure down: what fans out, what verifies, and what
synthesizes.

## 1. Should this be a workflow at all?

| Situation | Use |
|---|---|
| A few tool calls, or a lookup whose target you know | Do it yourself |
| One self-contained delegated job | `Task` |
| Ordered phases (survey → change → review), independent pieces to fan out, or claims that need independent verification | `Workflow` |
| A repeatable procedure the user will re-run | Save it as `.candy-code/workflows/<name>.yaml` and use `/workflow run` |

`Workflow` is a write-class tool: it asks under `default` and is denied under `plan` and
`dont-ask`. A workflow spends many agents' worth of tokens, so match its size to the request:

- "find any bugs" → a few finders and a single verifier.
- "thoroughly audit" or "be comprehensive" → a larger finder pool, a 3–5 vote panel, a synthesis.

**Hybrid is usually right.** Scout inline first (Glob the files, Grep the call sites, read the
diff) so the work list is real, then hand the list to a workflow. For big jobs chain several
single-purpose workflows across turns, and read each result before choosing the next:

| Workflow | Shape |
|---|---|
| **Understand** | Parallel readers over subsystems → a structured map |
| **Design** | A judge panel over N approaches → a scored synthesis |
| **Review** | Find (loop until dry) → per-finding panel verify → report |
| **Research** | Multi-modal sweep → completeness critic → second round |
| **Migrate** | Scout sites → transform each in a worktree → verify → report |

Worked, lint-clean versions of each ship in this tool's `examples/workflows/`
(`review-changes`, `judge-panel`, `research`, `migrate`, `fix-wave`, `review-loop`).

## 2. The ways to run a plan

**Workflow tool.** You pass the YAML inline:

```json
{"plan": "<yaml>", "context": {"files": ["src/A.php", "src/B.php"], "spec": "docs/brief.md"},
 "dryRun": false, "budget": 400000, "resumeFrom": "plan-1a2b3c4d-1"}
```

- `context` values may be any JSON: strings, numbers, booleans, **arrays and objects**. Pass a
  real list, never a CSV string or stringified JSON — a list feeds `from: {context: key}`, and a
  `{{key}}` holding an array or object renders as compact JSON. Keys must not start with `@`.
- **The plan is linted before anything runs.** Lint errors refuse the call with nothing
  dispatched; warnings ride the result. `dryRun: true` lints and returns the expanded stage
  table without dispatching — use it whenever a plan has more than a few stages or a big fan-out.
- `budget` is a token ceiling for this run; it tightens `config.maxTokens` (the smaller wins).
- The result is a headline, any lint warnings, one line per failed stage, then a JSON block
  `{status, runId, stages:[{name, status, error, attempts, tokens, cost, data?|output?, capture?, dispatch?}]}`
  plus the run's announcements (truncations, casualties, budget stops, your `announce:` lines).
  - `data` is a schema'd stage's decoded answer, present when every task of the stage has an
    `outputSchema`. It replaces `output` and is never clipped.
  - `output` is prose, its middle elided past 8 KiB; the marker names the capture file holding
    the rest.
  - `dispatch` (parallel stages) says which agents forked and which ran inline, and why.
- `resumeFrom: <runId>` (ids look like `plan-<sha8>-<n>`) resumes a tool run: stages whose
  declaration is unchanged are carried from the record at zero cost; the first edited stage and
  everything after it run live. To iterate, edit the plan and resume from the same `runId`.
- On tool runs `capture` defaults **on** (§6), so downstream prompts get a path plus a preview —
  give consumers `Read`, or set `capture: false` on a stage whose text they must see inline.

**Saved files.**

- Project tier `.candy-code/workflows/*.yaml` (YAML only; it shadows the user tier). User tier
  `~/.candy-code/workflows/*.yaml|*.php`. A saved file needs `name:`; keep it equal to the file stem.
- `/workflow run <name> key=val key2="two words"` (lints first, refuses on errors),
  `/workflow lint <name|file>`, `/workflow pause|resume|status <runId|name>`, `/workflow list`.
- Headless: `candy-code workflow run <name> --param k=v --format json`, then
  `candy-code workflow status|resume <id>`. Exit codes: 0 completed · 1 failed or refused (lint
  errors included) · 2 usage · 3 escalated, or paused by a signal.
- `/workflow run` and `--param` values are **strings**. A plan that fans out over a list
  (`from: {context: files}`) needs the Workflow tool's JSON `context` (or PHP `run()`); from the
  slash command the stage fails with "… which is a string, not an array".

## 3. Plan skeleton

```yaml
name: review-changes          # optional in the tool (defaults to "plan"); required in saved files
description: >                # optional
  Find bugs in the diff, verify each adversarially, summarise.
config:                       # optional
  maxConcurrent: 8            # width; unset = min(16, CPUs-2); 0 = no cap (lint warns)
  timeout: 1800               # seconds PER STAGE (default 3600), shared by that stage's agents
  maxAgents: 300              # run-wide dispatch ceiling (default 1000); retries, rounds, votes count
  maxTokens: 400000           # hard token ceiling; a dispatch at or past it PAUSES the run
  maxCost: 5.00               # hard USD ceiling; same behaviour
schemas:                      # optional; named JSON Schemas, used as outputSchema: {$ref: findings}
  findings:
    type: object
    properties:
      findings:
        type: array
        items:
          type: object
          properties: {id: {type: string}, file: {type: string}, line: {type: integer}, claim: {type: string}, scenario: {type: string}}
          required: [id, file, line, claim, scenario]
    required: [findings]
  verdict:
    type: object
    properties: {holds: {type: boolean}, evidence: {type: string}}
    required: [holds, evidence]
stages:                       # a LIST; names unique, [a-zA-Z_][a-zA-Z0-9_]* to be referenceable
  - name: find
    prompt: "List bugs in {{scope}}."
    tools: [Read, Grep]
    outputSchema: {$ref: findings}
```

- A schema needs `type: object` at its root, `required` must be a subset of `properties`, and
  `{$ref: name}` must be the only key in that `outputSchema`. An unknown `$ref` is a load error.
- Give each finding a stable `id` (e.g. `<file>:<line>:<slug>`), so `key: /id` really merges
  duplicates.
- **Stage and task maps refuse, never coerce.** An unknown key, a wrong type, a duplicate name, a
  broken regex or a misplaced key fails the load naming the offender, and nothing is spent.
  Unknown **top-level** and **`config:`** keys are the exception: they are ignored. So a typo
  there (`maxToken:`) silently means "no ceiling"; check them by eye. `stopOnFirstFailure` has no
  YAML spelling (PHP `->stopOnFirstFailure(true)` only).

## 4. Stage shapes

| Shape | Spelling | Runs |
|---|---|---|
| plain | task keys on the stage | one task |
| task list | `tasks: [ {...}, {...} ]` | tasks **in order**; each later task sees `{{prevResult}}`; the first failure stops the stage |
| parallel (static) | `parallel: true` + `agents: [ {type, name, prompt, …} ]` | concurrently; **barrier** at the end |
| parallel (fan-out) | `parallel: true` + `from:` + a stage-level template `prompt` | one agent per item, concurrently; barrier |
| **map** | `type: map` + `from:` + `steps: [...]` | each item flows through `steps` **independently, with no barrier** |
| pipeline | `type: pipeline` + `stages: [...]` | sequential steps for **one** item (not the per-item kind; that is `map`) |
| verification | `type: verification` + `task:` + `verifier:` | the task, then a verifier (or a vote panel) judges it |
| include | `uses: <workflow>` + `with: {k: "{{x.output}}"}` | another saved workflow as this stage's body (depth ≤ 3, no cycles) |

- In a parallel `agents:` entry the label is spelled `type:`; `agent:` there is refused.
- A **map step** is a task map (task keys plus `when:`) or `type: verification` with
  `task:`/`verifier:`. Parallel, map, pipeline and `uses:` steps are refused, as are `loop`,
  `minSuccess`, `capture`, `from` and the like on a step. Every step needs a unique `name`.
- The first failing step **drops that item**; the other items carry on. A skipped step
  (`when:`) passes `{{prevResult}}` through unchanged.
- A map's output (and its typed row) is one JSON document:
  `{"items":[{"key", "index", "status", "steps":{"<step>":{"status", "output", "data"?, "votes"?, "error"?}}, "error"?}], "matched"?, "dropped"?}`.

### Use `map` by default. Use a barrier only when you need it.

A barrier (a `parallel` stage feeding the next stage) is justified only when the next stage needs
**cross-item context from all items**: dedup or merge across the whole set before expensive
work, an early exit when the total is zero, or a prompt that compares against the other items
(judges scoring all designs). "I need to reshape first" and "the stages are conceptually
separate" are not reasons: do that per item inside `map` steps or with a step `when:`.

With barriers, wall-clock is the sum of each stage's slowest agent; with `map` it is the slowest
single item's chain. Width counts **item chains**, and a vote panel inside a step runs its votes
together, so agents in flight can reach width × votes.

## 5. Task keys

These go on a plain stage, a `tasks:` entry, an `agents:` entry, a map or pipeline step, or a
verification `task:`/`verifier:`.

| Key | Meaning |
|---|---|
| `name` | Result name, read as `{{name.results}}`. Use underscores: a hyphenated name is recorded but can't be referenced |
| `agent` (`type` in `agents:`) | Display **label only** (default `coder`); it loads nothing |
| `preset` | Run as an agent preset from the session's roster (built-ins `coder`, `reviewer`, `debugger`, `architect`, `tester`, `devops`, plus `.candy-code/agents/*.md`): its system prompt, tools, `disallowedTools`, model, effort, isolation and `maxTurns`. The task's own keys win (its `tools` when non-empty). The preset's permission mode applies only if **stricter** than the session's. Preset `skills` are not carried. An unknown name refuses the run before anything is dispatched |
| `prompt` | The **entire** instruction; must be self-contained (§9) |
| `tools` | Allowed tools, argument-scoping allowed: `[Read, Grep, "Bash(git *)", "Bash(vendor/bin/phpunit *)"]`. **Omitted and `[]` both mean the session's tools** (there is no toolless spelling, and neither form is policed by the grant), so give read-only stages an explicit narrow list such as `[Read]`. `Task` and `Workflow` are always withheld |
| `outputSchema` | A JSON Schema (inline map, a `{…}` string, a file path, or `{$ref: name}`). **Enforced**: the agent must end with a `StructuredOutput` call whose arguments validate; a mismatch is sent back for correction; ending without it gets one re-prompt, then the agent fails. Enforced keywords: `type`, `properties`, `required`, `additionalProperties`, `items`, `enum`, `oneOf`, `$ref` into `$defs`, `maxItems`, `maxLength`, `minimum`, `maximum` (not `minItems`, `minLength`, `pattern`, `const`, `anyOf`, `allOf`, `format`). The output is the validated compact JSON, and the stage records a typed row |
| `model` | Run this task on a named model; an unservable name fails the stage. Usually leave it unset |
| `effort` | `low` · `medium` · `high` · `xhigh` · `max`. `low` for mechanical stages (collect, format, summarise); high tiers only for the hardest judges. Usually leave it unset |
| `isolation` | `worktree`: its own fresh git worktree and branch (under `.candy-code/worktrees/`). Kept if dirty, **never auto-merged**; fails if the directory isn't a checkout. Every isolated task gets a NEW tree; no later step can enter an earlier one, so the agent that edits the tree must also run its checks there |
| `timeout` | This task's own seconds; the smaller of it and the stage budget wins |
| `retries` | Re-run after `failed`/`timed_out`. **Only on agents that are safe to run twice** (readers, judges), never on file-editing fixers |
| `stallTimeout` / `restarts` | Expire a worker silent for N s; `restarts` is its own re-dispatch budget, separate from `retries` |
| `resource` / `lockWaitSeconds` | Exclusive host lock (GPU, display, benchmark) held for the whole stage; contention waits (default 60 s), then fails loudly |
| `input` | `{from: [stageA, stageB], as: file-list\|first\|all\|results}` joins prior stages into `{{input}}` (§9) |
| `reject` / `rejectMinOccurrences` | **Verifier only.** A PCRE marking the verifier's output as a rejection |
| `votes` / `lenses` / `quorum` | **Verifier only.** §7 |

`when:` is also legal on a map step (§8).

## 6. Stage keys

| Key | Meaning |
|---|---|
| `name` | Unique; `{{name.output}}` |
| `parallel` / `type` | Shape (§4). Don't spell both |
| `from` | Fan-out source for `parallel` or `map`, exactly one of: `glob: "src/**/*.php"` (relative to the project root; `{{path}}`); `stage: find` + `pointer: /items` (an array in a prior stage's typed row) + optional `key: /id` (dedup, and names each item `<stage>_<key>`); `context: files` (a run-context list). Plus `chunk: N` (width ceiling) and `max: N` (keep the first N, **record** the dropped rest). Over 4096 items is refused. An empty data list completes with no agents; an empty glob fails |
| `steps` | `map` only: the per-item chain |
| `minSuccess` | `parallel`/`map` only: `N` or `"NN%"` of agents (map: items) must succeed; default all. A percentage rounds **up** (80% of 4 is 4). Never 0. When met the stage completes and lists its casualties as `{{stage.failed}}` |
| `when` | Skip the stage unless the clause holds (§8) |
| `loop` | Re-run until a clause holds (§8) |
| `capture` | `true` writes the full output to a file; downstream prompts get the path plus a 2 KiB preview (typed rows and pointers are unaffected). Use it on any stage whose output is large or feeds several prompts |
| `cache` | On a saved run's resume, carry a successful recorded row whose declaration hash is unchanged |
| `announce` | A template emitted when the stage settles, e.g. `"{{find.rounds}} rounds"`. Truncations, `minSuccess` casualties, budget stops and the agent ceiling always announce on their own |
| `uses` / `with` | Include another workflow |

`from: {stage: X}` reads the typed row stored under `X`'s **stage name**, which exists for a
single-task schema'd stage, an `accumulate` loop (`/items`) or a map (`/items`). A plain parallel
stage keeps its rows per agent (`X_1`, …), so fanning out from it fails loudly; give it
`loop.accumulate` or put a schema'd collector stage in between.

## 7. Verification: a verdict, a panel, or a question

```yaml
- name: check_fix
  type: verification
  task:
    prompt: "Apply the fix described in {{plan.fix}} to {{plan.file}}."
    tools: [Read, Edit]
  verifier:
    prompt: >
      Using the {{lens}} lens, try to REFUTE that this change is correct and complete:
      {{prevResult}}. Default to REJECT if you are not sure. If the requirement itself is
      ambiguous, start a line with "ESCALATE: <question>".
    tools: [Read, Grep, "Bash(vendor/bin/phpunit *)"]
    reject: '/\bREJECT\b/'
    votes: 3
    lenses: [correctness, regression-risk, does-it-reproduce]
    quorum: 2            # passes if >= 2 votes do NOT reject (default: majority)
    retries: 1           # re-runs the whole panel after a missed quorum; the task is not re-run
```

- `votes` dispatches N independent verifiers concurrently; `lenses` must list exactly one lens per
  vote, and `{{lens}}` differs per vote. Use diverse lenses when a claim can fail in more than one
  way; identical refuters when you only need redundancy.
- A vote **rejects** when its output matches `reject`, **or when it fails or times out** — a vote
  that could not attest counts against the quorum. A panel without `reject:` only loses votes to
  failures (lint warns).
- A line starting `ESCALATE:` in **any** vote stops the run for a person. It outranks `reject`
  and a met quorum, and burns **no** retries; the question survives in the stage output, and
  resume re-runs the stage after the human acts.
- Without `votes`, a single verifier's `retries` re-asks a fresh verifier against the same task
  output (a second opinion); the task is not re-run.
- `reject:`, `votes`, `lenses` and `quorum` anywhere but a verifier slot are refused before
  anything is dispatched. The votes are readable afterwards as `{{stage.votes}}`.
- **In a map, a rejected verification drops the item.** If refutation is the expected outcome for
  many items, make the task decide (`holds: true|false`) and let the panel check that decision,
  so a refuted claim still completes; otherwise every refuted item counts against `minSuccess`.

## 8. Control flow: `when`, `loop`, accumulate-until-dry

The condition grammar is closed: no code and no arithmetic.

```yaml
# alternatives — a stage carries exactly one `when:`
when: {stage: review, pointer: /verdict, equals: NEEDS_FIX}   # leaf: one op
# when: {stage: tests,  contains: "FAILURES!"}
# when: {stage: find,   pointer: /items, empty: false}
# when: {stage: check,  pointer: /ok, equals: "true"}          # non-string leaves compare as text: quote it
# when: {any: [ {stage: a, equals: "yes"}, {all: [ {stage: b, contains: GO}, {stage: c, matches: '/^PASS/'} ]} ]}
```

- Leaf ops: `equals` · `contains` · `matches` (PCRE, checked at load) · `empty: true|false`
  (exact: whitespace is content). `all`/`any` nest one level.
- `pointer:` reads an RFC 6901 leaf of a schema'd stage's typed row; it never falls back to text.
- A stage that never ran, a misspelled stage, a missing typed row or a pointer that misses is
  **unsatisfied**, so the guarded stage *skips*. A skip is recorded, is not a failure, and writes
  no `name.output`.
- On a map step the leaf may spell `step: <name>` to read an earlier step of the same item.

```yaml
- name: fix_tests
  prompt: "Round {{round}}. Previous attempt: {{previous}}. Make the suite green."
  tools: [Read, Edit, "Bash(vendor/bin/phpunit *)"]
  loop:
    max: 4
    until: {stage: fix_tests, contains: "OK ("}
```

`loop:` attaches to plain, parallel, verification and map stages (not pipeline or `uses:`). A
loop that hits `max` with the clause unmet **completes** with the last round: it means "try up to
N times", so judge the result downstream. `{{previous}}` exists from round 2.

**Loop-until-dry with dedup** — for discovery of unknown size (bugs, edge cases, drift):

```yaml
- name: find
  parallel: true
  agents:
    - {type: finder, name: f_logic,    prompt: "Find logic bugs in {{scope}}. Skip these known ids: {{seen}}", tools: [Read, Grep], outputSchema: {$ref: findings}}
    - {type: finder, name: f_security, prompt: "Find security bugs in {{scope}}. Skip: {{seen}}",            tools: [Read, Grep], outputSchema: {$ref: findings}}
  loop:
    max: 6
    accumulate: {pointer: /findings, key: /id}   # engine dedups against everything SEEN
    until: {dry: 2}                              # stop after 2 rounds that add nothing new
```

- The stage's result (output and typed row) is `{"items":[…], "keys":[…], "rounds":n}`, so
  downstream reads **`/items`**, not `/findings`: `from: {stage: find, pointer: /items, key: /id}`.
- `{{seen}}` is the JSON list of keys so far (`[]` in round 1); `{{fresh}}` the items the previous
  round added. Dedup is against *seen*, not *confirmed*, or rejected items come back every round.
- Every agent must be schema'd and its `pointer` must hold an array. `{dry: N}` requires
  `accumulate` and should be below `max`.
- Loop-until-budget: `until: {budgetBelow: 50000}`. It only means something with a token ceiling
  (`config.maxTokens` or the tool's `budget`); otherwise the loop runs to `max`.
- A looped fan-out replays round 1's item list; resume re-runs a loop from round 1.

## 9. Interpolation and writing prompts

| Form | Resolves to |
|---|---|
| `{{key}}` | A run-context value (arrays/objects as compact JSON; booleans/null stay verbatim) |
| `{{stage.output}}` | A prior stage's output: parallel = agent outputs joined by newlines; map/accumulate = its JSON document; captured = path + preview |
| `{{name.results}}` | One agent's output by result name. Unnamed: `<stage>` · `<stage>_<n>` (parallel, 1-based) · `<stage>_verifier` · panel votes `<stage>_verifier_<k>` |
| `{{stage.<pointer>}}` | A leaf of a schema'd row: `{{find.items/0/id}}`. Dots don't nest; use `/` |
| `{{item}}`, `{{item.<pointer>}}`, `{{index}}` | The current fan-out/map item (strings verbatim, else JSON); `{{index}}` is 0-based. `{{path}}` on a glob |
| `{{prevResult}}` | The previous task/step in a `tasks:`/pipeline/map chain; the task's output inside a verifier |
| `{{input}}` | The `input:` join. `as: all` = labelled `=== name ===` blocks; `results` = JSON keyed by stage (`{{input.find/items}}`); `first`; `file-list`. Prior stages only; a skipped source is just absent |
| `{{round}}`, `{{previous}}`, `{{seen}}`, `{{fresh}}` | Inside `loop:` |
| `{{lens}}` | Inside a panel vote |
| `{{stage.failed}}`, `{{stage.votes}}` | A `minSuccess` stage's casualties; a panel's last-round votes (JSON) |
| `{{budget.remaining}}` | Tokens left under the run's token ceiling (verbatim without one) |

- An **unresolved token is left verbatim**, not blanked. Lint warns; read the warnings.
- Stage agents **don't see this conversation**. Every prompt carries its own goal, scope, file
  paths, output contract and stopping rule. Repo instruction files still load, so name the
  *specific* rule a stage needs instead of pasting them.
- Don't paste large outputs into many prompts: `capture: true` on the producer, and consumers
  read `{{input}}` or the capture path with `Read`. That keeps prompts small and cache-stable.
- Put long job prose in a **spec file** every agent reads, and pass only its path.
- For hand-offs, use `outputSchema` plus pointers; don't ask the next agent to re-parse prose.

## 10. Safety, cost and failure semantics

- **Width.** Default `min(16, CPUs-2)` agents (map: item chains) in flight per stage. A fan-out
  over 4096 items is refused; narrow it or set `max:`. A `max:` cut is recorded and announced.
  **Never cap silently**: if you sample or take the top N, say what was dropped.
- **Budgets.** Set `maxTokens`/`maxCost` on anything with loops or large fan-outs. A dispatch (or
  retry) that would start at or past a ceiling doesn't start: the run **pauses**, and resuming
  under a raised ceiling (or a bigger tool `budget`) continues it. One in-flight wave can
  overshoot by its own cost. `maxAgents` (default 1000) is a separate dispatch backstop.
- **Fail-fast across stages.** The run stops at the first stage that fails. Inside a fan-out or
  map, `minSuccess` tolerates casualties instead of losing 199 good results to one timeout.
- **Writers.** Parallel writers need disjoint files (a `from:` with `key:` dedups the list) *or*
  `isolation: worktree`. Isolated trees are never merged automatically: plan a report that names
  the branches to merge.
- **Retries** re-run from scratch on a tree the failed attempt may have changed. Idempotent
  agents only.
- **Permissions** are checked on declarations before anything runs; a `Deny` refuses the whole
  run. Scope `Bash` (`"Bash(git *)"`); every call is then held to the declaration.
- **Tool-path parallelism.** On the Workflow tool, a parallel-stage agent (or a map step agent)
  runs in a forked child only when none of its tools could ask permission under the session's
  mode and rules (read-only tools, or tools the session allows outright). Anything that might ask
  runs inline, one at a time, so the question reaches the user. Read-only fan-outs are cheap in
  wall-clock; writing ones are not. The `dispatch` row says what happened.

## 11. Quality patterns (compose freely)

**Adversarial verify.** N independent skeptics are told to *refute*; the claim survives only with
a quorum. `votes` + `quorum` + `reject`; default skeptics to "reject if uncertain".

**Perspective-diverse verify.** `lenses:` (correctness, security, perf, reproduction) catch
failure modes identical voters miss.

**Scout → loop-until-dry → per-item verify → report** (the main shape):

```yaml
name: review-diff
config: {maxTokens: 600000}
schemas:
  findings:
    type: object
    properties:
      findings:
        type: array
        items:
          type: object
          properties: {id: {type: string}, file: {type: string}, line: {type: integer}, claim: {type: string}, scenario: {type: string}}
          required: [id, file, line, claim, scenario]
    required: [findings]
  verdict:
    type: object
    properties: {holds: {type: boolean}, evidence: {type: string}}
    required: [holds, evidence]
stages:
  - name: find
    parallel: true
    agents:
      - {type: finder, name: f_correct, prompt: "List correctness bugs introduced by `git diff {{base}}...HEAD`, one per real defect, with file, line, claim and a concrete failure scenario; id = <file>:<line>:<slug>. Skip known ids: {{seen}}", tools: [Read, Grep, "Bash(git *)"], outputSchema: {$ref: findings}}
      - {type: finder, name: f_edge,    prompt: "Same diff; edge cases only (empty, null, unicode, concurrency, resume). Same contract. Skip: {{seen}}", tools: [Read, Grep, "Bash(git *)"], outputSchema: {$ref: findings}}
    loop: {max: 4, accumulate: {pointer: /findings, key: /id}, until: {dry: 2}}
    capture: true
  - name: verify
    type: map
    from: {stage: find, pointer: /items, key: /id, max: 150}
    minSuccess: 50%
    steps:
      - name: check
        type: verification
        task:
          prompt: "Decide whether this finding holds, with quoted evidence: {{item}}"
          tools: [Read, Grep, "Bash(git *)"]
          outputSchema: {$ref: verdict}
        verifier:
          prompt: "Via the {{lens}} lens, prove this verdict WRONG: {{prevResult}} (finding: {{item.claim}} at {{item.file}}:{{item.line}}). End with REJECT: <why> or UPHOLD."
          tools: [Read, Grep, "Bash(git *)"]
          reject: '/^REJECT\b/m'
          votes: 3
          lenses: [correctness, reachability, reproduction]
          quorum: 2
  - name: report
    effort: low
    input: {from: [verify], as: results}
    prompt: "Items with status completed carry an upheld verdict in steps.check.data. Table the confirmed bugs (holds=true), most severe first: file:line, claim, scenario. {{input}}"
    tools: [Read]
```

**Judge panel** (wide solution space). Designers get *distinct angles*; judges each see all the
designs (the barrier is justified: they compare across items):

```yaml
- name: designs
  parallel: true
  agents:
    - {type: designer, name: d_mvp,  prompt: "Design {{goal}} MVP-first. Constraints: {{spec}}", tools: [Read, Grep], outputSchema: {$ref: design}}
    - {type: designer, name: d_risk, prompt: "Design {{goal}} risk-first. Constraints: {{spec}}", tools: [Read, Grep], outputSchema: {$ref: design}}
    - {type: designer, name: d_user, prompt: "Design {{goal}} user-first. Constraints: {{spec}}", tools: [Read, Grep], outputSchema: {$ref: design}}
- name: judge                       # barrier justified: judges compare ALL designs
  parallel: true
  agents:
    - {type: judge, name: j1, prompt: "Score each design 1-10 on correctness, cost, risk: {{input}}", input: {from: [designs], as: all}, tools: [Read], outputSchema: {$ref: scores}}
    - {type: judge, name: j2, prompt: "Score independently, weighting risk: {{input}}",              input: {from: [designs], as: all}, tools: [Read], outputSchema: {$ref: scores}}
- name: synthesize
  input: {from: [designs, judge], as: all}
  prompt: "Take the top-scored design and graft the best ideas from the runners-up. {{input}}"
  tools: [Read]
```

**Multi-modal sweep + completeness critic.** Readers search *different ways*: by file tree
(`Glob`), by content (`Grep`), by history (`"Bash(git log *)"`) and by docs. A critic returns a
schema'd `gaps` list (empty = complete). A second round runs
`when: {stage: critic, pointer: /gaps, empty: false}` and fans out
`from: {stage: critic, pointer: /gaps, key: /id}` with a template prompt over `{{item.*}}`.

**Migrate.** Scout (a schema'd list of sites) → `map` per site: a `transform` step with
`isolation: worktree` that edits **and** runs the suite in its own tree
(`"Bash(composer install *)"`, `"Bash(vendor/bin/phpunit *)"` — a fresh worktree has no
`vendor/`), then a verification step whose skeptic reads the kept tree → a report naming the
branches to merge. In a monorepo, run the touched package's suite, not the root's.

These aren't the only shapes: tournaments, staged escalation (`effort: low` first, re-run only
failures at `high` behind a `when:`) and self-repair loops compose from the same keys.

## 12. Before you call the tool — checklist

1. Did you scout first, so the work list is real and not guessed?
2. Is every prompt self-contained (goal, scope, paths, output contract, when to stop)?
3. Does every hand-off use `outputSchema` + pointers instead of prose re-parsing?
4. Are barriers only where cross-item context is needed, with `map` everywhere else?
5. Is every claim that matters verified by ≥2 independent votes or lenses?
6. Do writers have disjoint files or worktrees? Are retries only on idempotent agents?
7. Is `Bash` argument-scoped, and does every stage have an explicit, minimal `tools:` list?
8. Are width, `max:`, `minSuccess`, `maxTokens`/`maxCost` set, and is every cap announced?
9. Are names `snake_case` so `{{name.results}}` resolves? Do fan-outs read `/items` after an
   accumulate loop?
10. For anything big, run `dryRun: true` first and read the lint warnings.

## 13. Resume and debugging

- Tool runs: call the tool again with the edited plan and `resumeFrom: <runId>`; the unchanged
  prefix is carried, the rest runs live. Same plan + same context → everything is carried.
- Saved runs: `/workflow pause|resume|status <runId|name>` (headless `candy-code workflow
  resume <id>`). Resume continues after the last successful stage; a budget pause resumes under a
  raised ceiling. Granularity is one whole stage: an interrupted parallel or map stage re-runs
  every item (from the recorded list), a looped stage restarts at round 1, a `when:` skip stays
  skipped. `cache: true` additionally carries an unchanged stage's recorded result.
- Capture files live under `<workflowsPath>/.running/<runId>.stages/<n>-<stage>.txt` and outlive
  the run. Before concluding a stage "returned nothing", **read its capture file**.
- Common load errors: a `stages:` map instead of a list · `agent:` inside `agents:` (use
  `type:`) · `prompt:` beside `tasks:` · `reject:`/`votes:` outside a verifier · `loop:` on a
  pipeline or beside `uses:` · `{dry: N}` without `accumulate` · `input.from` or `from.stage`
  naming a later stage · `minSuccess` on a plain stage · a quoted boolean (`capture: "true"`) ·
  `equals: true` unquoted.

## 14. Anti-patterns

- A linear five-stage plan where every stage pastes `{{everything.output}}`. Use `capture` +
  `input`.
- A single reviewer whose "APPROVE" gates a merge. Use a panel.
- Hand-written shards splitting a CSV by position. Use `from: {context: files}` with a real list,
  or `from: {stage, pointer}`.
- Barrier → reshape → barrier. Use `map`.
- `retries` on a fixer that edits files.
- `tools: []` on a "toolless" judge: it gets the session's tools. Grant `[Read]`.
- An unbounded `from: {glob: "**/*"}` with no `max`/`chunk`, or `maxConcurrent: 0`.
- Asking a stage to "remember what we discussed". It can't see the conversation.
- `model:` or `effort: max` everywhere "to be safe". Inherit unless you have a reason.
