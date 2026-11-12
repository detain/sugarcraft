---
name: workflow-authoring
description: Reference for writing a candy-code workflow plan (YAML for the Workflow tool, or a saved .candy-code/workflows/*.yaml) — stage shapes, every key, interpolation, control flow, fan-out, verification, budgets, resume, and quality patterns (adversarial verify, loop-until-dry, judge panel, completeness critic). Load before authoring any non-trivial plan; loading it does not itself justify running one.
user-invocable: true
disable-model-invocation: false
paths:
  - ".candy-code/workflows/**"
  - "**/workflows/*.yaml"
  - "**/workflows/*.yml"
---

# Workflow authoring for candy-code

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
| Ordered phases (survey → change → review), or independent pieces to fan out, or claims that need independent verification | `Workflow` |
| A repeatable procedure the user will re-run | Save it as `.candy-code/workflows/<name>.yaml` and use `/workflow run` (project tier is YAML-only) |

`Workflow` is a write-class tool. It asks under `default`, and it is denied under `plan` and
`dont-ask`. A workflow spends many agents' worth of tokens, so match its size to what the user
asked for:

- "find any bugs" → a few finders and a single-vote verify.
- "thoroughly audit" or "be comprehensive" → a larger finder pool, a 3–5 vote adversarial pass,
  and a synthesis stage.

**Hybrid is usually right.** Scout inline first: Glob the files, Grep the call sites, read the
diff. That gives you the work list. Then hand the list to a workflow and fan out over it. You don't
need to know the whole shape before starting the *task*, only before the *orchestration step*.
For big jobs, chain several single-purpose workflows across turns:

| Workflow | Shape |
|---|---|
| **Understand** | Parallel readers over subsystems → a structured map |
| **Design** | A judge panel over N approaches → a scored synthesis |
| **Review** | Find → adversarially verify → summarise |
| **Research** | Multi-modal sweep → completeness critic → second round |
| **Migrate** | Discover sites → transform each in a worktree → verify |

Read each result before you choose the next phase.

## 2. The two ways to run a plan

**Workflow tool.** You pass the YAML inline:

```json
{"plan": "<yaml>", "context": {"files": ["src/A.php", "src/B.php"], "spec": "docs/brief.md"},
 "budget": 400000, "dryRun": false, "resumeFrom": null}
```

- `context` values may be strings, numbers, booleans **or arrays/objects**. Pass real JSON, never
  a CSV string or stringified JSON. Keys must not start with `@`.
- `dryRun: true` lints the plan and returns the expanded stage rows **without dispatching**.
  Use it whenever a plan has more than a few stages or a large fan-out.
- The result is a short headline plus a JSON block:
  `{status, runId, stages:[{name,status,error,attempts,tokens,cost,data,output,capture}]}`.
  - `data` holds a schema'd stage's validated object, and is never clipped.
  - `output` is prose with head/tail elision. The elision marker names the `capture` file that
    holds the rest.
- `resumeFrom: <runId>` resumes a run. Stages whose declaration is unchanged are carried from the
  record. The first edited stage and everything after it run live. So to iterate, edit the plan
  and resume from the same `runId`; don't start a fresh run.

**Saved files.**

- Project tier: `.candy-code/workflows/*.yaml` (YAML only, and it shadows the user tier).
- User tier: `~/.candy-code/workflows/*.yaml|*.php`.
- Run them with:
  `/workflow run <name> key=val key2="two words"`, `/workflow lint <name|file>`,
  `/workflow pause|resume|status <runId|name>`, or headless
  `candy-code workflow run <name> --param k=v --format json`.
- Headless exit codes: 0 ok · 1 failed · 2 usage · 3 escalated or paused.

## 3. Plan skeleton

```yaml
name: review-changes          # optional in the tool (defaults to "plan"); required in saved files
description: >                # optional
  Find bugs in the diff, verify each adversarially, summarise.
config:                       # optional
  maxConcurrent: 8            # default min(16, CPUs-2); 0 = no cap (lint warns)
  timeout: 1800               # seconds PER STAGE (default 3600); shared by that stage's agents, queue time included
  maxAgents: 300              # run-wide dispatch backstop (default 1000)
  maxTokens: 400000           # hard token ceiling; dispatch past it pauses the run
  maxCost: 5.00               # hard USD ceiling; same behaviour
  stopOnFirstFailure: false   # cancel siblings in a parallel stage on the first final failure
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
    properties: {refuted: {type: boolean}, reason: {type: string}}
    required: [refuted, reason]
stages:                       # a LIST; names unique, [a-zA-Z_][a-zA-Z0-9_]* if you want to reference them
  - name: ...
```

Schemas need `type: object` at the root, and `required` must be a subset of `properties`. An
unknown `$ref` is a load error. Give each finding a stable `id` (for example
`<file>:<line>:<slug>`), so dedup (`key: /id`) actually merges duplicates.

The loader **refuses, never coerces**. An unknown key, a wrong type, a duplicate name, a broken
regex or a misplaced key fails the load naming the offender, and nothing is spent. Read the error,
fix the plan, and run again.

## 4. Stage shapes

Every stage has one body. Pick the shape:

| Shape | Spelling | Runs |
|---|---|---|
| plain | `agent`/`prompt`/`tools`… on the stage | one task |
| task list | `tasks: [ {...}, {...} ]` | tasks **in order** in one stage; each later task sees `{{prevResult}}`; the first failure stops the stage |
| parallel (static) | `parallel: true` + `agents: [ {type, name, prompt, …} ]` | concurrently; **barrier** at the end |
| parallel (fan-out) | `parallel: true` + `from: {glob \| stage+pointer \| context}` + a template `prompt` | one task per item, concurrently; barrier |
| **map** | `type: map` + `from:` + `steps: [...]` | each item flows through `steps` **independently, with no barrier** |
| pipeline | `type: pipeline` + `stages: [...]` | sequential steps for **one** item (not the per-item streaming kind; that is `map`) |
| verification | `type: verification` + `task:` + `verifier:` | task, then verifier vote(s) judge it |
| include | `uses: <workflow>` + `with: {k: "{{x.output}}"}` | another saved workflow as this stage's body (depth ≤ 3, no cycles) |

In a parallel `agents:` entry, the label is spelled `type:`, not `agent:`.

### Use `map` by default. Use a barrier only when you need it.

A barrier (a `parallel` stage feeding the next stage) is only justified when the next stage needs
**cross-item context from all items**:

- dedup or merge across the whole result set before expensive downstream work;
- an early exit when the total is zero;
- a prompt that compares against "the other findings".

These do **not** justify a barrier:

- "I need to filter or reshape first." Do that per item inside `map` steps, or with
  `when:` on a step.
- "The stages are conceptually separate."

With barriers, wall-clock is the sum of the slowest agent in each stage. With `map`, it is the
slowest single item's chain.

## 5. Task keys

These go on a plain stage, a `tasks:` entry, an `agents:` entry, a `map`/`pipeline` step, or a
verification `task:`/`verifier:`.

| Key | Meaning |
|---|---|
| `name` | Result name; `{{name.results}}`. Use underscores: a hyphenated name is recorded but **can't be referenced** |
| `agent` (`type` in `agents:`) | Display **label only** |
| `preset` | Load `.candy-code/agents/<preset>.md` (system prompt, tools, model, effort, skills, isolation). Stage keys override it. An unknown preset is a load error |
| `prompt` | The **entire** instruction. Must be self-contained (see §9) |
| `tools` | Allowed tools, which may be argument-scoped: `[Read, Grep, "Bash(git *)", "Bash(vendor/bin/phpunit *)"]`. `[]` means no tools. Omitted means the session's tools. `Task` and `Workflow` are always withheld |
| `outputSchema` | JSON Schema (inline map, a `{…}` string, a file path, or `{$ref: <name>}` into top-level `schemas:`). **Enforced**: the agent must end by calling `StructuredOutput`, the input is validated, and a mismatch makes it retry. The stage records a typed row |
| `model` | Run this task on a named model. An unservable name **fails the stage** before dialing; it never silently falls back. Usually leave it unset |
| `effort` | `low` · `medium` · `high` · `xhigh` · `max`. Use `low` for mechanical stages (collect, format, summarise) and high tiers only for the hardest judge/verify stages. Usually leave it unset |
| `isolation` | `worktree`: own git tree and branch. Kept if dirty and named in the output; **never auto-merged**. Fails if the directory isn't a checkout |
| `timeout` | This task's own seconds. The stage budget still applies, and whichever is smaller wins |
| `retries` | Re-run after `failed`/`timed_out`. **Only on agents that are safe to run twice** (readers, judges, test runners), never on file-editing fixers |
| `stallTimeout` / `restarts` | Expire a worker that is silent for N s; `restarts` is its own re-dispatch budget, separate from `retries` |
| `resource` / `lockWaitSeconds` | Exclusive host lock (GPU, display, benchmark) held for the whole stage. Contention waits, then fails loudly |
| `input` | `{from: [stageA, stageB], as: file-list\|first\|all\|results}` joins prior stages into `{{input}}` |
| `reject` / `rejectMinOccurrences` | **Verifier only.** A PCRE that marks the verifier's output as a rejection |
| `votes` / `lenses` / `quorum` | **Verifier only.** See §7 |
| `when` | On a `map` step: a per-item guard |

## 6. Stage keys

| Key | Meaning |
|---|---|
| `name` | Unique; `{{name.output}}` |
| `parallel` / `type` | Shape (§4). Don't spell both |
| `from` | Fan-out source. Exactly one of the following, plus optional `chunk` (a width ceiling) and `max` (truncate, and **record** what was dropped): `glob: "src/**/*.php"` (relative to the project root; never follows symlinks); `stage: find` + `pointer: /findings` (+ `key: /id` to dedup and name items); `context: files` |
| `steps` | `map` only: the per-item chain. A step is a task map or a `type: verification` sub-stage (`parallel`/`map`/`uses` are refused as steps) |
| `minSuccess` | `parallel`/`map`: `int` or `"90%"`. The default is all. When met, the stage completes and lists every casualty, readable as `{{stage.failed}}` |
| `when` | Skip the stage unless the clause holds (§8) |
| `loop` | Re-run until a clause holds (§8) |
| `capture` | Write the full output to a file; downstream gets the path plus a 2 KiB preview. Use it on any stage whose output feeds several later prompts or is large. Default **on** for tool runs |
| `cache` | On resume, carry a successful recorded row whose declaration hash is unchanged |
| `announce` | A template emitted to the transcript when the stage settles, e.g. `"{{find.count}} findings so far"` |
| `uses` / `with` | Include another workflow |

## 7. Verification: a verdict, a vote, or a question

```yaml
- name: check_fix
  type: verification
  task:
    preset: coder
    prompt: "Apply the fix described in {{plan.data/fix}} to {{plan.data/file}}."
    tools: [Read, Edit]
  verifier:
    preset: reviewer
    prompt: >
      Using the {{lens}} lens, try to REFUTE that this change is correct and complete.
      Default to REJECT if you are not sure. If the requirement itself is ambiguous,
      start a line with "ESCALATE: <question>".
    tools: [Read, Grep, "Bash(vendor/bin/phpunit *)"]
    reject: '/\bREJECT\b/'
    votes: 3
    lenses: [correctness, regression-risk, does-it-reproduce]
    quorum: 2            # passes if ≥2 votes do NOT reject
    retries: 1           # re-runs the whole panel after a failed quorum
```

- Votes run concurrently and are independent; `{{lens}}` differs per vote. Use **diverse lenses**
  when a claim can fail in more than one way. Use identical refuters when you only need
  redundancy.
- `reject` names the words that mean *no*. A match is a verdict failure. Mechanical failures are
  never pattern-scanned.
- A line starting `ESCALATE:` in **any** vote stops the run for a human. It outranks `reject` and
  burns **no** retries. The question survives in the stage output, and `/workflow resume` re-runs
  the stage after the human acts.
- `reject:` anywhere but a verifier is refused before anything is dispatched.

## 8. Control flow: `when`, `loop`, accumulate-until-dry

The condition grammar is closed: no code and no arithmetic.

```yaml
# alternatives — a stage carries exactly one `when:`
when: {stage: review, pointer: /verdict, equals: NEEDS_FIX}   # leaf: one op
# when: {stage: tests,  contains: "FAILURES!"}
# when: {stage: find,   pointer: /findings, empty: false}
# when: {any: [ {stage: a, equals: "yes"}, {all: [ {stage: b, contains: GO}, {stage: c, matches: '/^PASS/'} ]} ]}  # max 2 levels
```

- Leaf ops are `equals` · `contains` · `matches` (PCRE, checked at load) · `empty`.
- `pointer:` reads an RFC 6901 leaf of a schema'd stage's typed row.
- A stage that never ran, a misspelled stage, or a pointer that misses is **unsatisfied**, so the
  guarded stage *skips*. A skip is recorded and isn't a failure. It produces no `name.output`.

```yaml
- name: fix_tests
  preset: coder
  prompt: "Round {{round}}. Previous attempt: {{previous}}. Make the suite green."
  tools: [Read, Edit, "Bash(vendor/bin/phpunit *)"]
  loop:
    max: 4
    until: {stage: fix_tests, contains: "OK ("}
```

A loop that hits `max` with the clause still unmet **completes**, using the last round. It means
"try up to N times", not "fail if it never happens", so judge the result downstream.

**Loop-until-dry with dedup.** Use this for discovery tasks of unknown size: bugs, edge cases,
drift.

```yaml
- name: find
  parallel: true
  agents:
    - {type: finder, name: f_logic,    prompt: "Find logic bugs in {{scope}}. Skip anything in: {{seen}}", tools: [Read, Grep], outputSchema: {$ref: findings}}
    - {type: finder, name: f_security, prompt: "Find security bugs in {{scope}}. Skip: {{seen}}",          tools: [Read, Grep], outputSchema: {$ref: findings}}
  loop:
    max: 6
    accumulate: {pointer: /findings, key: /id}   # engine dedups against everything SEEN
    until: {dry: 2}                              # stop after 2 rounds that add nothing new
```

The stage's output is the accumulated, deduplicated set (JSON). `{{fresh}}` is the current round's
new items. Dedup happens against *seen*, not *confirmed*, or rejected items come back every round
and the loop never converges. Simple `max:` counters miss the tail.

Loop-until-budget: `until: {budgetBelow: 50000}`. It only means something when `config.maxTokens`
or the tool's `budget` is set; otherwise the loop runs to `max`.

## 9. Interpolation and writing prompts

| Form | Resolves to |
|---|---|
| `{{key}}` | A value from `context` (arrays/objects render as JSON) |
| `{{stage.output}}` | A prior stage's text. For parallel stages, agent outputs joined by newlines |
| `{{name.results}}` | One agent's output by result name. Unnamed: `<stage>` · `<stage>_<n>` (parallel, 1-based) · `<stage>_verifier` |
| `{{stage.<pointer>}}` | A leaf of a schema'd row: `{{find.findings/0/id}}`. Dots don't nest; use `/` |
| `{{item}}`, `{{item.<pointer>}}`, `{{index}}` | The current fan-out/map item |
| `{{prevResult}}` | Previous task/step in a `tasks:`/`pipeline`/`map` chain; the task's output inside a verifier |
| `{{input}}` | The `input:` join |
| `{{round}}`, `{{previous}}`, `{{seen}}`, `{{fresh}}` | Inside `loop:` |
| `{{lens}}` | Inside a verifier vote |
| `{{budget.remaining}}` | Tokens left under the run's ceiling |

- An **unresolved token is left verbatim**, not blanked. Lint reports it as a warning; read the
  warnings.
- Stage agents **don't see this conversation**. Every prompt must carry its own goal, scope, file
  paths, output contract and stopping rule. Repo instruction files (CLAUDE.md/AGENTS.md) still
  load, so name the *specific* rule a stage needs instead of pasting them in.
- Don't paste large outputs into many prompts. Put `capture: true` on the producer and point
  consumers at `{{input}}` or the capture path. That keeps prompts small and cache-stable.
- Put long job prose in a **spec file** that every agent reads, and pass only its path.
- For structured hand-offs, use `outputSchema` and then pointers. Don't ask the next agent to
  re-parse prose.

## 10. Safety, cost and failure semantics

- **Width.** The default is `min(16, CPUs-2)` agents in flight. A fan-out over more than 4096
  items is refused, so narrow the glob or use `max:`. Truncation via `max:` is recorded in the
  output and announced. **Never cap silently**: if you sample, take the top N, or skip retries,
  say what was dropped (`announce:`).
- **Budgets.** Set `maxTokens`/`maxCost` on anything with loops or large fan-outs. Hitting a
  ceiling *pauses* the run, so you can resume with a higher ceiling.
- **Fail-fast across stages.** The run stops at the first stage that fails. Inside a fan-out, use
  `minSuccess` to tolerate a few casualties instead of losing 199 good results to one timeout.
- **Writers.** Parallel writers must have disjoint file sets *or* `isolation: worktree`. Isolated
  trees are never merged automatically; plan a merge stage, or tell the user which branches to
  merge.
- **Retries** re-run from scratch on a tree the failed attempt may have changed. Give them only to
  idempotent agents.
- **Permissions** are checked on declarations before anything runs. A `Deny` refuses the whole
  run. Scope `Bash` (`"Bash(git *)"`) instead of granting it bare.
- **Tool-path parallelism.** Agents whose tools can't trigger a permission `Ask` (read-only, or
  pre-allowed) run truly in parallel. Agents that might `Ask` run one at a time so the question
  can reach the user. Read-only fan-outs are cheap in wall-clock; writing fan-outs are not.

## 11. Quality patterns (compose freely)

**Adversarial verify.** N independent skeptics are told to *refute*; the claim survives only with
a quorum. Use `votes` + `quorum` + `reject`, and default skeptics to "refuted if uncertain".

**Perspective-diverse verify.** `lenses:` (correctness, security, perf, does-it-reproduce) catch
failure modes that identical voters miss.

**Scout → fan-out → verify** (the main shape):

```yaml
name: review-diff
config: {maxTokens: 600000}
stages:
  - name: find
    parallel: true
    agents:
      - {type: finder, name: f_correct, prompt: "List correctness bugs introduced by `git diff {{base}}...HEAD`. One finding per real defect; include file, line, claim, and a concrete failure scenario.", tools: [Read, Grep, "Bash(git *)"], outputSchema: {$ref: findings}}
      - {type: finder, name: f_edge,    prompt: "Same diff; edge cases only (empty, null, unicode, concurrency, resume). Same output contract.", tools: [Read, Grep, "Bash(git *)"], outputSchema: {$ref: findings}}
    loop: {max: 4, accumulate: {pointer: /findings, key: /id}, until: {dry: 2}}
    capture: true
  - name: verify
    type: map
    from: {stage: find, pointer: /findings, key: /id, max: 150}
    minSuccess: 95%
    steps:
      - name: refute
        type: verification
        task: {prompt: "Restate this claim precisely with evidence: {{item}}", tools: [Read, Grep]}
        verifier:
          prompt: "Via the {{lens}} lens, REFUTE: {{item.claim}} ({{item.file}}:{{item.line}}). REJECT if it does not hold."
          tools: [Read, Grep, "Bash(git *)"]
          reject: '/\bREJECT\b/'
          votes: 3
          lenses: [correctness, reachability, reproduction]
          quorum: 2
  - name: report
    effort: low
    input: {from: [verify], as: results}
    prompt: "Rank the confirmed findings most-severe first. Output a short table: file:line, claim, scenario."
    tools: []
```

**Judge panel** (wide solution space):

```yaml
- name: designs
  parallel: true
  agents:
    - {type: designer, name: d_mvp,  prompt: "Design {{goal}} MVP-first. Constraints: {{spec}}", tools: [Read, Grep], outputSchema: {$ref: design}}
    - {type: designer, name: d_risk, prompt: "Design {{goal}} risk-first. ...",                   tools: [Read, Grep], outputSchema: {$ref: design}}
    - {type: designer, name: d_user, prompt: "Design {{goal}} user-first. ...",                   tools: [Read, Grep], outputSchema: {$ref: design}}
- name: judge                       # barrier justified: judges compare ALL designs
  parallel: true
  agents:
    - {type: judge, name: j1, prompt: "Score each design 1-10 on correctness, cost, risk: {{input}}", input: {from: [designs], as: all}, tools: [], outputSchema: {$ref: scores}}
    - {type: judge, name: j2, prompt: "...independent scoring...",                                    input: {from: [designs], as: all}, tools: [], outputSchema: {$ref: scores}}
- name: synthesize
  prompt: "Take the top-scored design and graft the best ideas from the runners-up. Designs: {{designs.output}} Scores: {{judge.output}}"
  tools: [Read]
```

**Multi-modal sweep + completeness critic.** Readers search *different ways*: by file tree, by
symbol/content (Grep), by history (`Bash(git log *)`), and by docs. A final critic asks "what's
missing: a modality not run, a claim unverified, a source unread?" and returns a schema'd `gaps`
list. A second round runs `when: {stage: critic, pointer: /gaps, empty: false}` and fans out
`from: {stage: critic, pointer: /gaps}`.

**Migrate.** Scout (schema list of sites) → `map` with `isolation: worktree` per site → a verify
step running `"Bash(vendor/bin/phpunit *)"` in that tree → a report naming the branches to merge.
In this monorepo, a per-lib migration must run that lib's suite, not the root's.

These aren't the only shapes. Compose tournaments, staged escalation (`effort: low` first, re-run
only failures at `high` with `when:`) or self-repair loops as the task needs.

## 12. Before you call the tool — checklist

1. Did you scout first, so the work list is real and not guessed?
2. Is every prompt self-contained (goal, scope, paths, output contract, when to stop)?
3. Does every hand-off use `outputSchema` + pointers instead of prose re-parsing?
4. Are barriers only where cross-item context is needed, with `map` everywhere else?
5. Is every claim that matters verified by ≥2 independent votes or lenses?
6. Do writers have disjoint files or worktrees? Are retries only on idempotent agents?
7. Is `Bash` argument-scoped? Are tools minimal per stage (`[]` for pure judges)?
8. Are width, `max:`, `minSuccess`, `maxTokens`/`maxCost` set, and is every cap announced?
9. Are names `snake_case` so `{{name.results}}` resolves?
10. For anything big, run `dryRun: true` first and read the lint warnings.

## 13. Resume and debugging

- Every run returns a `runId`. After an error, a cancel, or a plan edit, call the tool again with
  the edited plan and `resumeFrom: <runId>`. Stages with an unchanged prefix are carried from the
  record at zero cost; the first changed stage and everything after it run live. Same plan + same
  context → everything is carried.
- A looped stage resumes from round 1, a `parallel`/`map` stage that was interrupted re-runs
  whole, and a `when:` skip stays skipped.
- `/workflow status <runId>` shows a per-stage ledger (status, duration, spend, tokens, attempts).
- Records live under `<workflowsPath>/.running/`. Capture files live under
  `<runId>.stages/<n>-<stage>.txt` and outlive the run. Before concluding that a stage "returned
  nothing", **read its capture file**; don't assume the summary is complete.
- Common load errors: a `stages:` map instead of a list · `agent:` inside `agents:` (use
  `type:`) · `prompt:` beside `tasks:` · `reject:` outside a verifier · `loop:` beside `uses:` ·
  `input.from` naming a later stage · a quoted boolean (`capture: "true"`).

## 14. Anti-patterns

- A linear five-stage plan where every stage pastes `{{everything.output}}`. Use `capture` +
  `input`.
- A single reviewer whose "APPROVE" gates a merge. Use votes.
- Hand-written shards splitting a CSV by position. Use `from: {stage|context}`.
- Barrier → reshape → barrier. Use `map`.
- `retries` on a fixer that edits files.
- An unbounded `from: {glob: "**/*"}` with no `max`/`chunk`, or `maxConcurrent: 0`.
- Asking a stage to "remember what we discussed". It can't see the conversation.
- `model:` or `effort: max` everywhere "to be safe". Inherit unless you have a reason.
