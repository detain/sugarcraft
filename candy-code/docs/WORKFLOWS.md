# Workflows

A workflow is a named sequence of stages, each dispatching a sub-agent with a
prompt and a tool list. Workflows are the **only** surface in CandyCode that
dispatches sub-agents from a chat command; `/agents` inspects and does not spawn.
The model reaches the same engine through the `Workflow` tool
([Model-authored plans](#model-authored-plans-the-workflow-tool)).

Two formats, two tiers, and an important asymmetry between them: a `.yaml`
workflow is data, a `.php` one is code, and the tier a file lives in decides
which it may be.

---

## Where a workflow goes

| Tier | Directory | Extensions honoured |
|---|---|---|
| project | `<root>/.candy-code/workflows/` | `.yaml` **only** |
| user | `~/.candy-code/workflows/` | `.yaml` and `.php` |

The project tier is `.yaml`-only by construction (`WorkflowRegistry`'s
constructor takes a project *YAML* path), because a `.php` workflow is reached
by `require`. A repository cannot add code through this door.

`WorkflowRegistry::load()` consults the project tier **first**, the same
precedence a checked-in agent preset gets: what `deploy` means is a property of
the checkout you are sitting in. That crosses extensions — a cloned `deploy.yaml`
shadows your own `deploy.php`, deliberately, and it is pinned by a test. The
alternative (letting `.php` win by extension) makes what a name means depend on
which spelling each tier happens to use, and hands a stale
`~/.candy-code/workflows/deploy.php` the power to shadow the curated
`deploy.yaml` a repository ships. What the project tier still cannot do is *add
code*: the substitution replaces code with data and never the reverse.

Both directories are anchored — the project one to the checkout, the user one to
`$HOME` — and a refused directory is reported eagerly at launch by
`Bootstrap::workflowEngine()` rather than left for the first `/workflow list` to
notice. Otherwise the refusal is invisible in every direction you look: the
not-found message stops naming the directory, `projectWorkflowsPath()` still
reports it, and the listing simply has fewer names in it.

### A `.php` workflow is compiled once per process

`load()`'s validation covers YAML only, and the gap is not fixable there. A
user-tier `.php` workflow is reached by `require`, which re-executes the file's
top-level code on every call. Before the process-wide memo landed,
running the same workflow twice in one session re-declared its file-scope
functions or classes and died with a `Cannot redeclare` **compile fatal** — the
one failure mode `Chat`'s `catch (\Throwable)` around `/workflow run` cannot
survive. `load()` now remembers each resolved path — its return value, and, in
a companion failure map, the `Throwable` a top-level throw raised — keyed with
the bytes it was compiled from, so a file compiles at most once per set of
bytes whether it returned, returned garbage, or *threw*, and that door is
closed on every shape. What
remains is ordinary and catchable: a file that does not *parse* raises a
`ParseError` the command turns into a transcript line, and nothing is
remembered — a file that failed to compile declared nothing, so there is
nothing to redeclare and a typo heals on the next attempt. An unchanged broken
file re-reports from memory on each later attempt: a file that returns
something other than a `Workflow` repeats the same `WorkflowLoadException`
from its cached answer, and a file that compiled and then threw repeats the
original exception without the file ever being touched again (before the
failure map, editing and re-running that second shape fatalled the CLI). Once
the bytes *change*, the remembered answer is stale, and the process decides by
shape: an edited file that declares **nothing** at file scope is re-required —
nothing can redeclare it — so the edit or the repair lands in-process, no
restart needed; an edited file that *does* declare file-scope symbols cannot
be re-required without risking the uncatchable redeclare fatal, and serving
the known-stale answer would silently run bytes you already replaced, so that
one refuses loudly, naming the file and the restart that settles it. The YAML
path, by contrast, re-reads its file on every run and reports every malformed
shape as a `WorkflowLoadException` that becomes one transcript line.

---

## The YAML schema

`examples/workflows/lint-then-fix.yaml` in this repo is a working reference.

```yaml
name: lint-then-fix              # required
description: >                   # optional
  Review the change, fan out fixers, then verify.

config:                          # optional
  maxConcurrent: 3               # whole number >= 1, or 0 for no cap
  timeout: 900                   # positive int; per-stage seconds

stages:                          # a LIST, never a map
  - name: lint                   # required, and unique across the file
    agent: reviewer              # default: coder
    prompt: >
      Review the diff at {{scope}} …
    tools: [Read, Grep, Glob, Bash]
    retries: 1                   # whole number >= 0; default 0
    outputSchema:                # optional; map, inline JSON string, or file path
      type: object
    isolation: worktree          # optional; own git worktree for this task (or none)
    model: claude-sonnet-4-5     # optional; run this task on a named model
    resource: gpu                  # optional; exclusive host lock held for this task's stage
    lockWaitSeconds: 60            # optional; seconds to wait for that lock before the stage fails
    timeout: 60                    # optional; positive int, this ONE task's wall-clock budget
    capture: true                  # optional; publish this stage's full output to a file (downstream sees path + preview)

  - name: fix
    parallel: true
    agents:                      # a LIST of maps
      - name: style-fixer
        type: coder              # default: coder
        prompt: Apply the style findings from {{lint.output}} …
        tools: [Read, Edit, Grep]
        retries: 0               # per agent in a parallel stage
```

Defaults are `Workflow`'s own: `maxConcurrent` unset (no cap — every task of a
parallel stage is dispatched at once), `timeout: 3600`,
`stopOnFirstFailure: false`.

### `timeout` is a per-stage wall-clock budget

`config.timeout` bounds each **stage** as a whole, measured from the moment the
stage starts. When it runs out, the agent still working is killed together with
every process it started (a Bash command that never exits included — the kill
walks the whole process tree), it settles `timed_out`, and the stage fails like
any other failed stage, so the run stops there.

- A **pipeline** or **verification** stage shares one budget across its steps:
  each step gets what the steps before it left, not a fresh allowance. A step
  reached with nothing left is settled `timed_out` without being started.
- A **parallel** stage's agents all run against the same budget, queue time
  included: when `maxConcurrent` bounds the stage, an agent still waiting for
  one of those slots when the budget runs out never starts.
- A PHP task's own `timeout()` (below) bounds that one agent from its dispatch;
  the stage's budget still applies, and whichever expires first wins. The same
  bound is spellable in YAML: `timeout: 60` on a single-task stage, on an entry
  of a `tasks:` list, on a `parallel:` agent, on a pipeline step and on either
  sub-map of a verification stage (the verifier's own budget bounds only the
  verification pass).

The bound is enforced by `AgentWorkerPool` on its forking path, which is the
path `/workflow run` takes. Where the pool cannot fork (no `pcntl`, or a failed
`fork()`) it runs each agent synchronously inside the caller, where the pool
cannot interrupt it; the executor then enforces the agent's own timeout itself
(the stage's `timeout`, or a task's `timeout()`) and settles `timed_out` the same
way. `ProcessExecutor` kills its worker at that bound rather than at a fixed
300 s of its own; `EngineExecutor` (a launch with a provider) stops the run at
the first tool call or streamed chunk past it, so a single provider call or tool
already running finishes first. The stage's shared budget reaches those runs the
same way: when what is left of it is shorter than the agent's own timeout, the
pool hands the executor the remainder (in whole seconds, rounded up) as the
agent's timeout, so the step stops there and settles `timed_out` naming the
budget. A pool built with an injected `ExecutorInterface` passes the same
bound on, and gets whatever enforcement that executor applies.

Cancelling a running agent (`AgentWorkerPool::cancel()` or `cancelAll()`) kills
the same way a timeout does: the worker and every process it started, its Bash
commands included, die with it, and the agent settles `stopped`.

### `retries` re-runs a failed agent

`retries: N` on a stage (or on one agent of a parallel stage), or `->retries(N)`
on a PHP task, lets `AgentWorkerPool` run that agent up to N more times after an
attempt that fails or times out. The default is 0: no retries.

- **What is retried:** an attempt that settles `failed` or `timed_out`. Each
  attempt gets its own timeout, counted from its own start. A `stopped` agent
  (cancelled) is never retried.
- **Within the stage's budget:** every attempt spends the same `config.timeout`
  budget. Nothing is retried once the budget is spent, or once the run was
  cancelled. A retry still waiting for a parallel slot when the budget runs out
  never starts.
- **One result per agent:** the stage sees the **last** attempt only, so a stage
  whose agent fails once and then succeeds is a completed stage. That result's
  tokens and cost add up every attempt, so the run's totals include what the
  failed attempts spent. `AgentResult::$attempts` counts the attempts, and the
  pause file keeps the count. When the last attempt fails, its error says why
  there was no further attempt: `[failed on all 3 attempts]`, or `[not retried
  after attempt 1 of 3: the time budget was spent]`.
- **Fail-fast:** under `stopOnFirstFailure` only an agent's last attempt counts
  as its failure. A failure that is about to be retried does not stop the
  other agents in the stage.
- **The live pane:** a retried agent's tile clears and starts again with the
  new attempt.

A retry runs the agent from scratch: it gets the same prompt again, on a tree
that the failed attempt may already have edited. Give `retries` only to an
agent that is safe to run twice. A reviewer or a test runner usually is; a
fixer that edits files may apply a change twice. `AgentPoolConfig::$maxRetries`
gives every agent of a pool at least that many retries. It defaults to 0, and
the launch leaves it there.

### `when:` makes a stage conditional

`when:` on a stage — or `->when($clause)` on the PHP builder — decides whether
the stage runs at all, from the text prior stages already produced. The clause
is a closed mini-grammar. A **leaf** names one stage and exactly one op on its
output:

```yaml
  - name: deploy
    agent: coder
    prompt: ship it
    when:
      stage: review
      equals: GO
```

The ops are `equals` (whole-string identity), `contains` (substring,
case-sensitive) and `matches` (PCRE, validated at load exactly like `reject:`).
A **combinator** node — `all:` or `any:` — carries a non-empty list of leaves;
one layer of nesting is legal (an `any:` of `all:`s), and a combinator inside a
combinator past that ceiling refuses the file. Everything else — an unknown
key, a leaf with two ops, `when: ready`, an empty list — raises
`WorkflowLoadException` at load; the grammar refuses, never coerces.

Evaluation reads a named stage's recorded output (its `{{name.output}}` value,
falling back to the per-agent results map). A name that never ran — or never
existed, since a misspelling fails closed like every other unknown in this
system — carries no text, and no text satisfies any op: **the guarded stage
skips.** A skip is a recorded fact, not a failure: the run lists the stage as
`skipped`, it counts neither as success nor as failure, nothing is dispatched,
nothing is spent, and the stage contributes **no `name.output` key** — a
downstream `{{deploy.output}}` stays the raw token, honest that the answer
never came. The gate sits ahead of the cache door, so a stage skipped today is
not resurrected by a stale cached row. On resume a skip is settled: its row
survives the pause file, the run prefix counts it, and the stage is never
re-decided — re-evaluating a guard under outputs that changed since the file
was written could run a stage its condition once refused.

`when:` is stage-level in v1; there is no per-task clause inside a `tasks:`
list. Give each conditional step its own stage.

### `loop:` re-runs a stage until a clause holds

`loop:` on a stage — or `->loop(['max' => N, 'until' => $clause])` on the PHP
builder — re-dispatches the stage until its `until:` clause holds, at most
`max:` rounds. Every round sees `{{round}}`, a 1-based number the engine
injects into the stage's interpolation while the round is running, so a prompt
can say what has already been tried:

```yaml
  - name: fix-tests
    agent: coder
    prompt: make the suite green, round {{round}}
    loop:
      max: 4
      until:
        stage: fix-tests
        contains: PASS
```

`until:` is exactly the `when:` mini-grammar — same leaves, same combinators,
validated the same way at load. And it refuses rather than coerces, like every
other key here: a `loop:` that is not a map, a map with any key besides `max`
and `until`, a missing half, a `max` that is not a whole number of at least 1,
or a broken clause each fails the load naming the offender. `max` counts
rounds, not seconds: it is a bounded iteration and holds no timer.

The clause reads the stage's most recent round — that round's output is what
`{{stage.output}}` holds while the stage itself is being judged, so the usual
spelling tests the stage's own result. A round is a full dispatch: every
`tasks:` entry runs, the hooks fire around it, tokens and cost are billed each
time. A round that fails mid-way does not stop the loop — the clause is false
for it — and the next round gets the stage a chance to rescue itself. If the
final round fails too, the stage settles as its normal failure and the run's
fail-fast law takes over.

A stage that uses all `max:` rounds with the clause still unsatisfied
**completes**: the last round's result is the stage's recorded state, nothing
more. `loop:` means "try up to N times and move on", not "fail if it never
happens" — the verdict of what was achieved belongs to the output and to the
stages downstream. Only the final round is recorded: the cache door and the
pause file see one row per stage, the last round's, and round numbers are
never persisted. That means a resumed run re-runs a looped stage from round
1 — whole-stage granularity, honest and disclosed — rather than replaying a
half-loop. A cancel that lands mid-loop ends the loop immediately and the row
settles `cancelled` like any in-flight stage's.

`loop:` is stage-only in v1 in the narrow sense too: a `when:` guard can still
skip the whole thing, a `cache:` hit short-circuits every round (the door sits
ahead of the loop), and attaching a `loop:` to a `parallel:`, `pipeline:`, or
verification stage refuses the load — as does a `loop:` beside a `uses:` stage,
because v1 loops plain stages, not carried workflows. Give each repeating step
its own plain stage.

### `outputSchema` gives a stage a structured verdict

`outputSchema:` on a stage (or on one agent of a parallel stage, or on one task
in a `tasks:` list) — or `->outputSchema($schema)` on a PHP task — constrains
that agent's terminal answer to a JSON schema, on the same leg as the headless
`-p --json-schema` flag. The value is the same shape the flag accepts: a YAML
map (written inline), a string of inline JSON starting with `{`, or a path to a
readable file containing one. Anything else — a sequence, malformed JSON, an
unreadable path — is refused at load or build time, never coerced.

- **What the stage sees:** the agent's report is the schema-constrained JSON
  document itself, byte for byte what `-p --json-schema` prints as the answer,
  so `{{name.output}}` and `{{name.results}}` readers parse it directly.
- **The schema rides the first request only** — exactly as it does headless: a
  model that answers with a tool call ends the turn unconstrained. When that
  happens the stage output carries the same disclosure the headless run prints
  on stderr: `[the reply was not constrained to the output schema because the
  model ran tools before answering]`. A prompt that wants a verdict, not a
  change, says so; a reviewer whose tools list is empty cannot take that branch.
- **Capability is checked before the run:** a provider that cannot honour a
  JSON schema refuses the stage loudly (`was not started`) rather than billing
  for a structured answer and collecting prose.
- **And it composes:** a verification stage's accept/reject pattern and an
  `outputSchema` are independent features that stack — give the verifier a
  schema and its verdict arrives machine-readable, not pattern-matched prose.

### `isolation` runs a stage agent in its own git worktree

`isolation: worktree` on a stage (or on one agent of a parallel stage, or on
one task in a `tasks:` list) — or `->isolation(Isolation::Worktree)` on a PHP
task — gives that agent **a git worktree of its own** to work in, on a branch
`agent-<id>-<time>` cut from the run's checkout. It is the same machinery
behind the Task tool's like-named preset option — one shared copy — so an
isolated stage's paths are jailed to its tree and the shared checkout is
never edited by an isolated run.

- **What the stage reports:** if the tree holds work when the stage ends, it
  is kept, and the stage output carries the same note a Task result carries,
  naming the tree's path (and branch) — later stages and `{{name.output}}`
  readers can find the work there. Nothing merges automatically; you review
  and merge by hand from the named tree.
- **A clean tree vanishes:** a stage that changed nothing removes its worktree
  and its branch at the end, without a note. Silence is the expected shape of
  a read-only isolated stage.
- **No checkout, no run:** a stage that cannot be isolated — the working
  directory is not a git checkout, or `git worktree add` fails — FAILS with
  the reason, before the model is dialed. An isolated stage never quietly
  degrades into the shared checkout; the refusal is the result.
- **In parallel, every tree is its own:** two isolated agents of the same
  stage get distinct trees and distinct branches — parallel work never
  collides in one tree.
- **And it composes:** `isolation` and `outputSchema` are independent options
  that stack — a schema-constrained verifier can work in its own tree, and
  its machine-readable verdict arrives beside the note naming that tree.

### `model` runs one stage on a different model

`model: <id>` on a stage (or on one agent of a parallel stage, or on one
task in a `tasks:` list) — or `->model($id)` on a PHP task — dials **that
agent** on a named model instead of the session's. The value is trimmed and
must name something at load; whether the session can actually SERVE the name
is decided at dispatch by the same ladder a delegation consults (`TaskTool`'s
model choice — one shared copy: session equality, Claude tier names, and the
provider's real capability).

- **A stage refuses; a delegation degrades.** When the ask cannot be
  honoured, a delegated run says so in its result and continues on the
  session's model — but a STAGE fails loudly, before the model is dialed at
  all (`the run was not started`). A plan author who pinned a model's answer
  never silently gets another model's.
- **The ladder:** the stage's ask wins; `inherit` defers to the agent's own
  preset model; naming the session's model pins nothing and changes nothing.
  A task without `model:` dials exactly what it dials today, byte for byte.
- **The run reports it:** an isolated ask that runs writes its dialed model
  into the run's reported `model`, the way a Task result's note names the
  model that actually answered.
- **It composes:** headless `-p --model` pins one model for a whole lane; a
  stage `model:` pins ONE agent's dial. The option is independent and stacks
  with `outputSchema:` and `isolation:` — a fast model drafts, a strong model
  verifies, in the same stage list.

### `resource` serializes a stage against the host

`resource: <name>` on a stage (or on one agent of a parallel stage, or on one
task in a `tasks:` list) — or `->resource($name)` on a PHP task — makes the
engine hold an **exclusive host lock** on that name for the whole stage, from
before its first dispatch until its last settles. A GPU, a display, a
benchmark battery the host has exactly one of: serialization becomes
mechanical instead of a convention somebody remembered to write down.

- **The lock belongs to the host, not to a lib:** `<dir beside the registry>/
  locks/<slug>-<fingerprint>.lock` — production `~/.candy-code/locks/`, or
  wherever a deliberately trusted registry points, never a per-package path.
  File names are a readable slug plus a sha1 fingerprint, so two resource
  names that slug alike cannot silently share a lock.
- **Contention waits, then fails loud:** a competing stage on the same
  resource blocks up to `lockWaitSeconds:` (default 60; `0` asks once and
  gives up), then its stage FAILS with an error naming the resource and the
  bound waited — the same failed-stage shape the retry, pause and resume
  machinery already handles, so a resumed run re-runs just that stage.
- **Release is on every exit path:** success, failure, throw and the
  cooperative stop all pass one `finally`; a process that dies takes its
  locks down with it, because the kernel releases on descriptor close — a
  killed or crashed run never strands a resource for the next one.
- **Sorted, so engines cannot deadlock each other:** a stage naming several
  resources takes them in sorted name order, and tasks in one stage that
  name the same resource share a single acquire. (A forked parallel worker
  INHERITS the held descriptor, so the lock outlives the engine close until
  that worker exits too — the stage pool joins every worker before the
  stage returns, which is exactly why this is a stage lock, not a worker
  lock.)
- **Absent means untouched:** a stage whose tasks name no resource opens no
  lock file at all — pre-O3 runs, byte for byte.

### `capture` moves a stage's output into a file

`capture: true` on a stage — or `->stage($name, $tasks, capture: true)` in
PHP — publishes that stage's FULL output to a run-scoped file the moment the
stage settles, and hands downstream stages the file's path plus a bounded
preview (the first 2 KiB) in place of the whole dump. It is a STAGE flag in
this grammar — stages are the unit YAML lists and the unit results are named
by — so the flag lives on the stage map (legal beside a `tasks:` list, like
`parallel:`), not on the workflow config.

- **Why:** the self-contained-lane law. A downstream agent that needs detail
  reads the file; the prompt envelope stays cache-stable and small, and no
  length cap is silently truncating an agent's input (the hazards a raw
  multi-KiB dump into every prompt carries). The stage's own result and the
  pause record still store the full output — capture changes only what the
  PROMPT next receives, never what the run recorded.
- **The layout:** `<pause dir>/<run id>.stages/<index>-<stage-slug>.txt` —
  production `<workflowsPath>/.running/three-1a2b3c4d.stages/0-plan.txt`,
  always beside the pause record, 0600 files in a 0700 directory, published
  by rename so a reader never sees half a capture. The files OUTLIVE a clean
  terminal on purpose: withdrawal removes the pause RECORD, and the capture
  is the work product whoever was handed the path still wants.
- **Fail loud, degrade honest:** if the file cannot be written, downstream
  gets the full inline dump PLUS a `[capture FAILED: …]` note — the stage's
  work is never lost to the loss of a file.
- **Absent means untouched:** a stage without `capture:` interpolates today's
  full output, byte for byte — and writes nothing anywhere new.

### `cache:` reuses a recorded stage result on resume (opt-in)

A stage may carry `cache: true`. On **resume**, if the pause record already
holds a SUCCESSFUL result for that stage name and the identity hash recorded
when that result settled still matches the stage's declaration, the stage is
CARRIED: settled from the record with zero dispatch — no agent runs, no tokens
billed again. The door defaults closed, and three things keep it honest.

- **The match is the same hash the engine already records** (docs of the g5
  substrate): a digest over the stage's `type` plus each task's
  prompt-template, `model` and `outputSchema` — computed pre-interpolation, at
  one single site. Edit the ask and the hash moves, so a stale answer is
  never reused for new work. The `capture:` and `cache:` keys are NOT part of
  the digest, so toggling them alone never invalidates a recorded answer.
- **Missing, changed or forged means re-dispatch.** No recorded row, no
  recorded hash, a hash that does not match the declaration, or a
  non-successful row: every one falls through to an ordinary dispatch. A
  hand-edited record claiming a hash it cannot prove is refused the same way
  — `hash_equals` with an absent hash is never equal.
- **Lineage and money stay true.** The carried stage's agent records ARE the
  rows the pause file stored — nothing is fabricated and nothing re-billed
  when the record's own totals already counted them; a record that lacks
  numeric totals re-adds the carried row's recorded spend instead of
  undercounting it. A carried stage takes no resource locks, and its capture
  file — when it has one — is republished from the recorded output, never from
  a fresh agent run. A carried stage's downstream interpolation sees exactly
  the bytes a fresh run would have produced.

A `--no-cache` CLI kill-switch is NOT part of this door (a host-command seam
can add one later); `cache:` is per-stage, and a stage that does not ask is
always re-dispatched.

### `type: verification` and `type: pipeline` reach the compound shapes from YAML

A stage may carry an explicit `type:` beside its name. Absent means `type: stage`
— the plain single-task or `tasks:`-list shape above. The accepted spellings are
exactly `stage`, `parallel`, `pipeline`, and `verification`; anything else is
refused at load, naming the offending word. `parallel: true` remains the flag
form of `type: parallel` — carrying both spellings at once, or pairing
`parallel: true` with one of the compound types, is a contradiction the loader
refuses rather than resolves.

```yaml
  - name: review                     # verification: run a task, then judge it
    type: verification
    task:
      agent: coder
      prompt: Fix the finding.
      tools: [Edit, Read]
    verifier:
      agent: reviewer
      prompt: Review the fix. Answer APPROVE or REJECT with reasons.
      reject: '/\bREJECT\b|\bNEEDS-FIX\b/i'   # verifier verdicts fail the stage
      rejectMinOccurrences: 1                  # how many matches make it a REJECT

  - name: scaffold                   # pipeline: steps chained in sequence
    type: pipeline
    stages:
      - name: plan                   # each step is a task map; the step's name
        agent: architect             # is also what {{plan.output}} interpolates
        prompt: Write the plan.
      - agent: coder
        prompt: Implement {{prevResult}}   # prevResult carries the prior step
```

A verification stage fails when the verifier *mechanically* fails, runs out of
time, or produces output matching its `reject:` pattern (see `reject()` below —
the verdict machinery is the same one PHP plans use; YAML plans get it for free).
A pipeline stage runs its steps in order against one shared stage budget, and
each step's `timeout:` bounds only itself. `reject:` belongs on a verifier:
putting it on a plain task parses into the DTO, and the engine's pre-flight
refuses the run naming the misuse — the parser refuses shapes, the engine owns
placement policy.

### `uses:` / `with:` compose one workflow into another

`uses:` arms a stage with the name of another workflow. The stage runs that
workflow — all of its stages, in order — as its body, and settles as exactly
ONE outer stage row labeled with the outer stage's own name; the inner run's
detail rides the row's output as one `name: status` line per inner stage, and
every agent it dispatched bills the outer run's token and cost totals.

```yaml
name: ship-notes
stages:
  - name: research
    agent: coder
    prompt: Gather notes on {{topic}}
  - name: draft                 # one row in this run's stage list, named "draft"
    uses: author-draft          # runs the named workflow as the stage's body
    with:
      notes: "{{research.output}}"   # interpolated in the OUTER scope first
```

`with:` is the inner run's START context: each value interpolates against the
outer run's settled stages before the inner run begins, and nothing the inner
workflow names flows back out — later outer stages read the composed body
through `draft.output` like any other stage's output. The included name is
resolved through the registry's ordinary tiers, the same lookup `run` performs,
so a checked-in plan composes with a personal library exactly as it runs alone.

Two placement rules hold the recursion safe, and both read the whole chain, not
just one hop. Depth counts workflows, outer one included, up to three: `a -> b
-> c` runs, a fourth node refuses as the stage's own failure naming the full
attempted path — `Include chain too deep: a -> b -> c -> d (maximum depth is
3).` A name already on the path is a cycle and refuses the same way —
`Workflow "b" is already in this run's uses chain (a -> b -> a); includes must
not cycle.` The linter walks the `uses:` edges for both verdicts before the run
starts, so a plan that would refuse at dispatch is refused at lint instead of
after four stages of real work.

An inner run is driven by the same engine, on the same call stack: Esc Esc
stops it with the run around it, and its live frame is deliberately not
selectable by `pause()`. That carries the one honest limit of v1 composition:
a pause during an inner run replays the WHOLE inner stage on resume (inner
stage results land in the outer pause snapshot only at stage settle).

The loader keeps one-body-per-stage honest: `uses:` cannot sit beside `agent:`,
`tasks:`, `task:`, `agents:` or `stages:`, cannot accompany `parallel: true` or
a `type:` of `verification` or `pipeline`, and a `with:` with no `uses:` to seed
is refused rather than ignored. `cache: true` beside `uses:` is legal, and it
hashes the DECLARATION — the included name plus the pre-interpolation `with:`
templates — not the inner workflow's own content: editing the included plan
leaves a cached include row carrying, the same author-stability law every other
`cache:` stage follows.

### `from:` fans a parallel stage out at runtime

```yaml
  - name: sweep
    parallel: true
    from:
      glob: src/**/*.php         # relative to the environment root, never the YAML's directory
      chunk: 4                   # optional; max agents of THIS stage in flight at once
    prompt: Audit {{path}} and report findings.
```

A `parallel:` stage may carry `from:` instead of `agents:` or `tasks:` — the
spelling is one at a time, refused at load otherwise. The stage's `prompt:` is
the template; the engine expands the glob **once** when the stage is entered,
against the run's environment root (the session project — not the directory the
workflow file lives in). Every matching file becomes one task sharing the
template's arms, named by its relative path, and `{{path}}` interpolates to
that path per item (an unresolved `{{path}}` elsewhere is left as written, like
any other token). `chunk` caps the stage's in-flight window — a ceiling, never
a floor: it can narrow the workflow's `maxConcurrent` width, never widen it;
omit it and the workflow's own width applies. The walk never follows symlinks —
a linked file or directory is invisible to the expansion, and a name that would
resolve outside the environment root is refused loudly rather than fanned out.

The expansion is the pause record's, not the disk's: the materialised file
list rides every pause write, and a resume replays *that recorded list* — it
never re-globs, because files that changed mid-run would otherwise silently
fork different work from what was paused. A stage that paused *before* it ever
expanded has no record yet, and its resume expands normally — that is entry,
not replay. Once a record exists it is honoured exactly: the file list stands
as recorded, a recorded `chunk` wins over the re-read YAML, and a record
that is malformed — a broken file list, a broken chunk — fails the stage
loudly instead of silently re-expanding. The single fallback is a recorded
chunk written as null: that wave expanded without a ceiling, so its replay
takes the ceiling from the current YAML, and a ceiling can only narrow the
concurrency, never the work itself.

Static and dynamic spellings feed the same machinery, so `retries:`, the stage
`timeout:`, and `stopOnFirstFailure` ride a fan-out unchanged.

### Everything malformed is refused, not coerced

`WorkflowRegistry::parseYamlWorkflow()` raises `WorkflowLoadException` for each
of these rather than loading a workflow that quietly does less:

- `stages:` present but not a list — `stages: nope` used to load as a workflow
  with zero stages. `stages: []` stays legal: a workflow that deliberately does
  nothing is a thing an author can mean.
- `stages:` as a **map** — `stages: {first: {...}}` used to load with the keys
  thrown away, so the author's `first:` meant nothing.
- **two stages with the same name** — stage names are how `{{name.output}}`
  interpolates, so duplicates make every reference ambiguous.
- `config:` not a map; `maxConcurrent` not a whole number >= 0 (0 means no cap);
  `timeout` not a positive integer.
- `retries:` not a whole number of at least 0 (`retries: -1`, `retries: lots`).
- `when:` outside the closed grammar: `when:` not a map, an unknown key in a
  condition (`gt:`), a leaf missing `stage:` or carrying more than one of
  `equals`/`contains`/`matches`, a combinator other than `all:`/`any:` or one
  nested past the two-layer ceiling, a list that is not non-empty, a
  non-string value, or a broken `matches:` PCRE — each fails the load naming
  the offender, so a half-meaningless guard never reaches a run.
- `loop:` outside the closed pair: `loop:` not a map, a map carrying any key
  besides `max` and `until` or missing either half, a `max:` that is not a
  whole number of at least 1, an `until:` outside the `when:` grammar, a
  `loop:` attached to a `parallel:`, `pipeline:`, or verification stage
  (stage-only in v1), or a `loop:` beside a `uses:` stage (carried workflows
  are not looped in v1) — each fails the load naming the offender.
- `outputSchema:` is not a schema-shaped value: a sequence, a bare number,
  malformed inline JSON, or a path that names no readable file (`WorkflowLoadException`
  at load; the same refusals fire at `build()` for `->outputSchema('…')` strings),
  and writing it on a stage that already carries a `tasks:` list is refused
  (`belongs on each task`) rather than silently ignored.
- `isolation:` is not a string, or a string that says anything other than
  `none` and `worktree` (trim- and case-insensitive): the load fails naming
  the supported spellings, and writing it on a stage that already carries a
  `tasks:` list is refused (`belongs on each task`) like its sibling keys.
- `model:` is not a non-empty string: a number, a list, or a blank value
  fails the load (a key the author wrote must name something; blanks are
  never silently read as "session"); writing it on a stage that already
  carries a `tasks:` list is refused (`belongs on each task`) likewise.
- `resource:` is not a non-empty string (same blank-name law as `model:`),
  or `lockWaitSeconds:` is not a whole number `>= 0` (quoted integers are
  accepted exactly as for `retries:`; `0` is the legitimate ask-once bound);
  both are refused on a stage that already carries a `tasks:` list, like
  their sibling keys.
- `capture:` is not a boolean — `true`/`false` unquoted, the exact spelling
  law `parallel:` holds; `'yes'` and `1` are refused, not coerced.
  Whether a *well-formed* name is servable is a dispatch-time refusal, not
  a load-time one — the ladder lives in `ModelChoice`, never duplicated.
- `parallel: true` with `agents:` that is not a list.
- any key present but of the wrong type. The check is `array_key_exists()`, not
  `isset()`, so `prompt: ~` is refused as the wrong shape rather than silently
  taking the default: a key the author wrote is a key the author meant.
- **an unknown key** — at stage level, inside a `tasks:` entry, a parallel
  `agents:` entry, a `type: verification` sub-map, or a `type: pipeline` step,
  a key the parser never reads now fails the load naming itself
  (`has unknown key "colour"; supported keys: …`) instead of being silently
  ignored. The supported set is the parsed-key roster itself
  (`WorkflowRegistry::YAML_TASK_KEYS`), so an arm and its refusal are added
  in one place or the roster guard test reddens. Parallel agents spell their
  label `type:`; the task-level `agent:` spelling is refused there, not
  ignored. Placement of a *known* key on the wrong shape (a `prompt:` beside
  a `tasks:` list) stays the dedicated per-shape refusals' job.

Stage numbering in messages is **0-based** (the list index), while the comments
in `examples/workflows/` number stages from 1. That is worth knowing when reading
an error.

### Interpolation

Three forms, substituted in this order (`WorkflowEngine::interpolateContext()`):

| Form | Resolves to |
|---|---|
| `{{name.results}}` | one agent's output, by its result name (below) — any stage type |
| `{{stageName.output}}` | a prior stage's collected output (a parallel stage's is every agent's output joined by newlines) |
| `{{variable}}` | a key from the context passed to `run()` (`/workflow run <name> key=val …`) |

Names must match `[a-zA-Z_][a-zA-Z0-9_]*`. An **unresolved** reference is left
as written, not blanked — so a typo appears verbatim in the prompt rather than
vanishing. So is a context value that is not a string or a number (a PHP
caller's array, say). Pipeline steps and a verifier also see `{{prevResult}}`,
the previous step's or the task's output.

#### What `{{name.results}}` is named by

Every agent a stage runs records its output under a **result name**: its task's
own `name` when it has one, otherwise a name derived from the stage. The
agent **type** (`agent:` / `type:`, default `coder`) is never a result name, so
two unnamed stages on the default agent no longer overwrite each other, and
`{{coder.results}}` resolves only for a task actually named `coder`.

| Stage type | A named task | An unnamed task |
|---|---|---|
| regular | its `name` | the stage's name |
| `parallel: true` | the agent's `name` | `<stage>_<n>`, 1-based in declaration order (`fix_2`) |
| `pipeline` step | the step task's `name` | the step's name (`WorkflowBuilder::pipeline()` names a step after its task name or agent type) |
| verification task | its `name` | the stage's name |
| verification verifier | its `name` | `<stage>_verifier` |

- A parallel agent's result is matched to the agent it came from, whatever
  order the agents finished in. An agent cancelled before it produced a result
  (`stopOnFirstFailure`) records nothing, and so does a stage refused before
  it dispatched anything.
- A failed agent records its output too, empty when it produced none.
- A pipeline step can read the earlier steps' results, and a verifier the
  task's, as well as later stages.
- **The same result name twice: the later one wins.** Two agents named `fixer`
  in different stages leave the second's output under `fixer`.
- A result name outside the interpolation grammar — the schema example's
  `style-fixer`, or a stage named that way — is recorded but cannot be
  referenced. Name the agent `style_fixer` to address it.

Results are kept in the run's context under the reserved key `@results`
(`WorkflowEngine::RESULTS_CONTEXT_KEY`), apart from the caller's own keys. So
the pause file carries them, and a resumed run still interpolates the results
of the stages it skipped. Before, they shared the caller's namespace:
`/workflow run three coder=x` crashed stage `a` after its agent ran and dropped
that agent's tokens. **A context key starting with `@` is refused**:
`run()` and `runFromPhp()` throw `InvalidArgumentException` before anything is
dispatched, and `/workflow run` prints it as an error.

---

## Running one

```
/workflow list
/workflow run <name>
/workflow pause <id>
/workflow resume <id>
/workflow status <id>
/workflow lint <name|file>
```

`/workflow lint <name|file>` runs the static `WorkflowLinter` over a plan
WITHOUT dispatching — it never starts the engine and never pauses. It reports
errors first as `- severity [stage] message`, then a verdict line
`N error(s), M warning(s)`. On the slash surface the plan's row is flagged an
error ONLY when the plan HAS ERRORS (`WorkflowCommand` flags on `hasErrors()`,
not `hasFindings()`): a warning-only plan prints its verdict line but is not
flagged — errors flag the row, warnings surface in the verdict line only. A
`.php` path is refused (it would execute at load); a `.yaml` or `.yml` path is
read as DATA (`fromYamlString`); any other argument resolves the name from the
registry. ERRORS: unsupported stage types, unknown or forward `.results`
references, `reject` outside the verifier slot, non-compiling PCRE,
out-of-universe tool patterns, and bound violations. WARNINGS: bare unknown
`{{token}}`, an empty prompt, and retries above the warn ceiling.

**2026-09-10 (the seam fully closed): what `/workflow run`
reports for a sub-agent changed under you, and the change is on purpose.**
As of that fix the engine's worker pool is fed the same launch-derived provider
spec the chat sub-agent path gets (`Bootstrap::workflowEngine()` builds the
pool through `agentPoolConfig()`); the "engine still fails" gap that stood for
hours between the two is gone.
*WHAT SAID:* before that fix a stage whose forked sub-agent had no usable
provider could still report **Completed** — the worker script fabricated a
plausible text answer. *WHAT TRUE NOW:* a forked worker is handed the launch's
serializable provider spec (the same selected-provider config the hosted chat
runs on, plus the model override); with no spec derivable it **refuses**, and
the sub-agent surfaces as **FAILED** naming the absence — a run that used to
quietly "finish" now stops with a reason. A configured launch is unaffected
except that its sub-agents now genuinely consult the model in the child.
*WHY EARNS PLACE:* `/workflow status` is an operator's audit trail; "Completed"
has to mean "a model saw this prompt", and before that fix it provably could not.

**2026-09-28: a stage now does the agents' work.** *WHAT SAID:* the worker
above consults the model in the child — ONCE. It advertises the stage's tools
and executes none of them, so a stage whose first move was a tool call (for any
real task, every stage) finished with empty output. *WHAT TRUE NOW:* on a launch
with a provider the pool's forked executor is `Agents\EngineExecutor`, bound by
`Chat` to the chat's own engine, and every stage agent runs that engine's tool
loop in the forked child — the hook chain and the session `PermissionGate` see
each tool call as it is made, the stage's `tools:` is its tool list (`Task`
withheld), and the step cap is 200 (an agent's own `maxTurns` overrides it). Sequential, pipeline and verification stages
fork too now (`AgentWorkerPool::executeOne()` takes the forking path whenever a
forked executor is configured), so no stage type blocks the TUI for its
duration. *WHY THE RULE STILL HOLDS:* a launch with no provider keeps the
refusing worker, and an engine on the offline echo fallback is refused by the
executor — "Completed" still means a model did the work.

While it works, an engine-run stage streams to the live-agent pane: its prose
as it arrives and a `▸ Tool(args)` line per tool call (`EngineExecutor::executeStream()`
runs the turn in a Fiber whose sinks hand each coalesced delta out to the pool's
progress file). The pane shows that activity log; the stage's result is the
final answer only. Every stage type dispatches through the session's
`AgentManager` (`WorkflowEngine::dispatchOne()`), which is what makes a
sequential stage's tile appear at all.

Pause files live under `<workflowsPath>/.running/*.json` — anchored to the
registry's directory rather than to `~`, so a registry pointed somewhere trusted
does not pause into a directory nobody vetted.

A registry-backed run (`/workflow run`, and any `resume()`) refreshes that same
record after **every stage that completes** — not only at pause boundaries. A
`SIGKILL`, a machine that dies, or a dropped container can then lose at most the
in-flight stage: `resume()` replays from the first stage the record does not
count, and skips the prefix it does. The record is withdrawn whenever the run
ends without a standing pause (completed, failed, or cancelled), so an
undisturbed run still leaves no file behind. Honest limit: a stage that was
mid-flight when the process died always **re-runs in full** — records are per
whole stage, and there is no partial credit inside a stage (or inside a
`parallel` sub-stage).

Every record is stamped with the identity of the process that wrote it: its
pid, plus that process's start time where the platform can read one. That is
what lets a fresh session tell the three shapes the same file can stand for.
A record left by a cooperative stop — `pause`, or a signal the engine caught —
carries a clean marker and reads **paused**, exactly as always, whether or not
its writer still lives. A record written by ordinary stage progress and never
revised is crash-class: if its writer process is verifiably gone the run reads
**orphaned** (progress you can resume that was never a pause you chose), and
while that writer is verifiably still live elsewhere the run reads **running**
from every process, so a second `resume` of one live run is refused rather
than silently stealing it. A recycled pid cannot masquerade: a recorded start
time that does not match the process now holding the pid settles it exactly,
and where no start time is readable at all a stale heartbeat past
`ORPHAN_HEARTBEAT_STALE_SECONDS` (two hours — twice the default stage timeout)
decides for the dead. Records written before the stamps existed carry none and
keep behaving as they always did.

The record also carries a purely additive `stageInputHashes` map — per
settled stage, a sha256 over its pre-interpolation prompt templates plus each
task's model and schema identity. It is the substrate a future resume-side
result cache (C13) will use to skip re-dispatching an unchanged stage; today
nothing acts on it, and records written before it existed (or stages a
resumed run never settled itself) read as a cache miss, never as corruption.

### Pause, resume, status

`<id>` is the run ID `/workflow run` prints (`three-1a2b3c4d`) or the workflow
name; both resolve to the same run.

- **`pause` on a live run** writes the pause file at once and asks the run to
  stop. The stage in flight finishes, and the next stage is not started. The
  run's own report then says **paused**, with the `/workflow resume` line to
  continue it. Two edge cases. If the in-flight stage fails, the run reports
  **failed** and the pause still stands. If it was the last stage, the run
  completed and the pause file is withdrawn. While the run's turn holds the
  prompt, other slash commands are refused, but `/workflow pause` and
  `/workflow status` are let through, so a live pause is simply typed. (`Esc
  Esc` cancels instead: the stage in flight has its agents stopped, no later
  stage runs, and the report says **cancelled**. A pause already requested
  still stands, so the run can be resumed from its successful stages.)
- **`pause` on a finished run** records it. For a **failed** run this is the
  recovery path: pause, fix the cause, resume, and the resume re-runs the
  stage that failed. Pausing a completed run is allowed too. Resuming it runs
  nothing and reports completed.
- **Only stages that succeeded count.** `stagesCompleted` and the recorded
  stage results are the successful prefix of the run, never the failed stage.
  A stage declared `cache: true` may additionally be CARRIED on resume from a
  successful recorded row even when its position was not (yet) skipped —
  hash-gated, opt-in, default off; see the `cache:` section above.
  The token and cost totals are the run's whole spend, including what a failed
  attempt spent.
- **`resume`** continues after the last successful stage and reports
  **completed**, **failed** or **paused** to match the result. Like `run`, it
  runs as a turn that does not block the TUI, and it can be paused itself.
  Its result covers the whole run: the stage list, "Stages completed" and the
  totals include the paused leg. The pause file is **consumed** when the
  resumed run completes or fails, and a second `resume` is refused. To retry
  a resume that failed again, `pause` it again first.
- **`status`** reports a live run as `running` (or `paused` once a pause is
  requested), then the pause file's status reconciled against the writer that
  stamped it — a cooperative or pre-stamp record reads `paused`, a crashed
  run's progress record reads `orphaned` (and resumes exactly like a pause),
  and a record whose stamped writer is still live in another process reads
  `running`, where a `resume` is refused — then a finished run's final status
  (`completed` / `failed`) for runs this session performed.
  When a session `CommandContext` is supplied and the workflow's PAUSED ledger
  resolves from HOME, `status` additionally appends a per-stage ledger
  (Stage | Status | Duration | Spend | Tokens | Attempts) plus a Totals line,
  DERIVED ONLY from that resolved paused ledger via `readPauseLedger` —
  live-running per-stage detail remains engine-private and is NOT shown.
  3-arg callers and absent ledgers keep the original plain row. An absent
  `attempts` field renders `—` (never a fabricated 0), and a missing or
  unreadable ledger fails soft: no columns, no crash. Path-shaped workflow
  identifiers are refused before any filesystem access.

Granularity is still one whole stage. A `parallel` stage that is still running
when an interrupt lands is re-run from scratch on resume. The run's context,
with every finished stage's `{{name.results}}` entries, is in the pause file,
so the resumed stages interpolate exactly as they would have.

`WorkflowEngine` is handed the launch's model, provider and `PermissionGate`.
The gate is consulted **before** the first sub-agent is dispatched, on the
*declarations*: `refuseDeniedTools()` walks each task's `tools:` list and refuses
the run if the gate would `Deny` a name. A refusal is knowable from the
definition alone, so it is raised before anything runs rather than after three
stages have already executed. A non-string entry in a `tools:` list is
**refused, not skipped** — the YAML loader can no longer produce one, but the
PHP DSL's `->tools([42])` can, and silently dropping an entry inside a safety
check is the failure mode with no upper bound on how wrong it can be.

Only `Deny` refuses; an `Ask` proceeds, because settling one needs the blocking
permission prompt. See [`PERMISSIONS.md`](PERMISSIONS.md) — and note that a bare
`Bash` *declaration* is allowed even under `plan`, since `plan` judges each
`Bash` call by its command — read-only commands run, everything else is denied
— and a declaration has no command to judge.

A `tools:` entry may be **argument-scoped**, in the `permissionRules` dialect:
`tools: [Read, "Bash(git *)"]`. The stage's tool list is resolved by the
name half (`Bash`), and every call the stage agent then makes is held to the
whole declaration by the same `SubAgentGrantHook` a `Task` preset's grant uses
(`EngineExecutor` binds it, ahead of the session gate and even under
`withoutHooks()`): `git status` runs, `rm x` and `git log && rm x` come back to
the agent as denied calls it can work around. A stage with no `tools:` is not
policed by the grant, and a malformed entry (`Bash(git *`) fails the stage
naming why.

---

## Model-authored plans: the `Workflow` tool

The model can run a workflow of its own: the built-in `Workflow` tool takes the
same YAML as a `.yaml` file, as a string, plus an optional `context` object for
`{{key}}` placeholders:

```json
{"plan": "stages:\n  - name: survey\n    agent: explorer\n    prompt: List the callers of Foo::bar.\n    tools: [Read, Grep]\n  - name: fix\n    prompt: \"Update every caller: {{survey.output}}\"\n    tools: [Read, Edit, \"Bash(composer test*)\"]\n"}
```

- **Same loader, same checks.** `WorkflowRegistry::fromYamlString()` runs the
  plan through the validation a file gets, so a plan and a file cannot mean two
  different things. The plan's `name` is optional (it defaults to `plan`); a
  syntax error is reported with the parser's message, which a file's error
  withholds because it would quote the file.
- **One engine per call, bound to the calling turn.** The tool builds its own
  `WorkflowEngine` and pool around an `EngineExecutor` bound to the engine that
  is running the current turn, and calls `WorkflowEngine::runWorkflow()`. It
  never re-binds the launch's `/workflow` engine, whose executor binding is
  shared.
- **Stages run inside the turn.** The stage agents run synchronously in the
  turn's own process, not in forked workers as `/workflow run` stages do. The
  turn already runs off the TUI, and staying in its process is what lets a stage
  agent's permission questions reach your prompt; the cost is that the agents
  of a `parallel: true` stage take turns. Every stage agent beats the turn's
  heartbeat, so a long plan is not mistaken for a hung turn.
- **What a stage agent may do.** This session's tool loop, hooks, permission
  gate and root, limited to its stage's `tools:` (argument-scoped entries
  included) or the session's tools when it declares none. `Task` and `Workflow`
  are withheld from it, so a plan cannot recurse, and it does not see the
  conversation that called the tool.
- **Not resumable.** A plan has no registry name, so `runWorkflow()` writes no
  pause file, remembers no result and installs no interrupt handlers;
  `/workflow pause`/`resume`/`status` do not apply. Cancelling the turn stops
  the run and its agents.
- **The permission gate sees the tool call itself.** `Workflow` is
  write-capable, so it asks under `default` and is denied under `plan` and
  `dont-ask`, before any stage runs.

The tool's result is a headline (status, stages succeeded, tokens, cost) and
each stage that ran with its status, its error when it failed, and its output,
whose middle is elided past 8 KB. A run that did not complete is returned as an
error result.

---

## Three limits to know before you design around this

**1. A stage's tasks run one after another, never at once.** A regular stage
may hold several tasks — a list in PHP, `->stage('work', [Tasks::agent('architect')->name('plan'), Tasks::agent('coder')])`,
or a `tasks:` list in YAML, whose entries take the one-task stage's keys
(`agent`, `name`, `prompt`, `tools`, `retries`, `outputSchema`, `isolation`, `model`, `resource`, `lockWaitSeconds`);
`capture` is the stage's own flag, not a task key, so it lives on the stage
map even when the stage lists `tasks:`:

```yaml
- name: fix
  tasks:
    - name: lint
      prompt: Lint the tree and list the problems.
      tools: [Read, Grep]
    - prompt: "Fix what this found: {{prevResult}}"
      tools: [Read, Edit]
```

`WorkflowEngine::executeStage()` runs them in order. Each task after the first
sees the previous task's output as `{{prevResult}}` and every earlier task's as
`{{<name>.results}}` (an unnamed task is recorded as `<stage>_<n>`, counting
from 1), the tasks share the stage's one `timeout`, and the first task that does
not complete fails the stage without starting the rest. Every task's `tools:`
is checked before the first one runs. The stage's `{{<stage>.output}}` is the
tasks' outputs, one per line. A `tasks:` stage takes everything from its
entries, so a stage-level `agent`/`prompt`/`tools`/`retries` beside it is
refused, as is an empty list. For tasks that should run at the same time, use
`parallel: true` with `agents:`.

**2. `agent:` / `type:` is a LABEL, not a preset reference.** `WorkflowEngine`
contains no reference to `AgentPreset` or `AgentDefinition` at all. A stage
saying `agent: reviewer` produces

```php
new Agent(name: 'reviewer', description: <the interpolated prompt>, prompt: '', …)
```

— the name is carried for display, and the stage's own `prompt:` is the entire
instruction. Your `~/.candy-code/agents/reviewer.md` preset and a stage saying
`agent: reviewer` are unrelated objects that happen to share a string. If you
want a preset's prompt in a stage, paste it into the stage's `prompt:`.

**3. `pipeline` and verification stages are PHP-only.** `WorkflowBuilder` offers
`pipeline()` and `withVerification()`, and the engine implements
`executePipelineStage()` and `executeVerificationStage()` — but
`parseYamlStage()` recognises only three stage shapes: one task, a `tasks:`
list, and `parallel: true`. There is no YAML spelling for either. They are reachable from
a user-tier `.php` workflow and from an embedder, not from a `.yaml` file.
For the same reason `reject()` ([below](#reject-fails-a-stage-on-the-verifier-verdict))
has no YAML spelling either: an unknown key in a `.yaml` task is silently
ignored, so a stray `reject:` line there buys nothing — a `.php` workflow is
the only place a verdict-gated verification stage can live.

---

## The PHP form

A `.php` workflow returns a `Workflow`. Build it with `WorkflowBuilder` and
`TaskBuilder` (aliased `Tasks::agent()`):

```php
<?php
use SugarCraft\Code\Workflows\{WorkflowBuilder, Tasks};

return (new WorkflowBuilder())
    ->name('deploy')
    ->description('Build, verify, ship.')
    ->maxConcurrent(3)
    ->timeout(900)
    ->stopOnFirstFailure(true)
    ->stage('build', Tasks::agent('coder')
        ->prompt('Run the build and report failures.')
        ->tools(['Bash', 'Read'])
        ->timeout(600))
    ->withVerification(
        'ship',
        Tasks::agent('devops')->prompt('Deploy.')->tools(['Bash']),
        Tasks::agent('tester')->prompt('Confirm the deploy is healthy.')->tools(['Bash']),
    )
    ->build();
```

`TaskBuilder` carries `agent()`, `prompt()`, `tools()`, `timeout()`,
`retries()`, `isolation()`, `name()`, `outputSchema()`, `model()`, `reject()`,
`resource()` and `lockWaitSeconds()`. `timeout` and `reject` have
**no
YAML spelling**, so a YAML-declared task never carries them; `retries`,
`outputSchema` and `isolation` are the `retries:`, `outputSchema:` and
`isolation:` keys above. `WorkflowEngine` substitutes defaults for whatever a task
leaves unset when it builds the `SubAgent`:
`$task->timeout ?? config.timeout` seconds, `$task->retries ?? 0`,
`$task->isolation ?? Isolation::None`. A task without its own `timeout()` is
therefore bounded only by its stage's budget (`config.timeout`, default of 3600
seconds); see [`timeout` is a per-stage wall-clock budget](#timeout-is-a-per-stage-wall-clock-budget).

`retries()` reaches `SubAgent::$maxRetries`, and the pool re-runs the agent as
[`retries` re-runs a failed agent](#retries-re-runs-a-failed-agent) describes.
`outputSchema()` — spelled `outputSchema:` in YAML — reaches
`SubAgent::$outputSchema` decoded, and `EngineExecutor` puts it on the engine
exactly as `-p --json-schema` does, as
[`outputSchema` gives a stage a structured verdict](#outputschema-gives-a-stage-a-structured-verdict)
describes.
`isolation()` — spelled `isolation:` in YAML — reaches `SubAgent::$isolation`,
and `EngineExecutor` honors it for the stage run through the same
WorktreeIsolation machinery `TaskTool` uses for a delegated run: own tree per
stage agent, a refusal (never the shared checkout) when a tree cannot be
made, and the stage text names the tree and branch when work was kept, as
[`isolation` runs a stage agent in its own git worktree](#isolation-runs-a-stage-agent-in-its-own-git-worktree)
describes.
`model()` — spelled `model:` in YAML — reaches `SubAgent::$model`, and
`EngineExecutor` decides it with the same `ModelChoice` ladder `TaskTool`
uses for a delegated run: a servable name pins the stage's dial and the run
reports it, an unservable one fails the stage loudly before anything is
dialed — a stage that names a model means that model, as
[`model` runs one stage on a different model](#model-runs-one-stage-on-a-different-model)
describes.
`resource()` and `lockWaitSeconds()` — spelled `resource:` and
`lockWaitSeconds:` in YAML — reach `WorkflowTask::$resource` /
`::$lockWaitSeconds`, and the engine takes (and always gives back) the host
lock around the stage exactly as
[`resource` serializes a stage against the host](#resource-serializes-a-stage-against-the-host)
describes.
`timeout()` — spelled `timeout:` on any task map, list entry, parallel agent,
pipeline step or verification sub-map in YAML — bounds that one agent, and
`reject()` with its optional `atLeast` — spelled `reject:` and
`rejectMinOccurrences:` on a verification stage's `verifier:` (or `task:`) map
— carries the verdict pattern onto the DTO, after which the semantics below are
identical whatever spelling authored it.

### `reject()` fails a stage on the verifier verdict

A verification stage normally fails only when the verifier's agent *mechanically*
fails (`Failed`) or runs out of time (`TimedOut`). But a verifier that finishes
happily and writes "REJECT — the fix breaks the guard pins" is a **verdict**,
not a crash, and status alone cannot see it. `reject()` closes that gap: attach
a PCRE pattern to the verifier and the engine scans the verifier's final output
after it completes — **a match rejects the run**. Read the name as "this text
means rejection": the pattern names the words your verifier uses when it says
*no*, not the words you hope to see. (An option called `expect` would invert
that reading; `reject` states what a match does.)

```php
->withVerification(
    'review',
    Tasks::agent('coder')->prompt('Fix the finding.')->tools(['Edit', 'Read']),
    Tasks::agent('reviewer')
        ->prompt('Review the fix. Answer APPROVE or REJECT with reasons.')
        ->reject('/\bREJECT\b|\bNEEDS-FIX\b/i')
        ->retries(2),
)
```

Semantics, precisely:

- **Verifier slot only.** `reject()` belongs on the `TaskBuilder` you pass as
  the *verifier*. Placing it on a verification stage's task slot, or on any
  task of a `stage()` / `parallel()` / `pipeline()` stage, is refused by a
  pre-flight check: the run ends `Failed` with a message naming the stage, and
  **zero agents are dispatched** — a misplaced verdict rule never burns spend.
- **The scan runs only on a completed verifier.** `Failed`, `TimedOut` and
  `Stopped` verifier results keep their existing paths untouched; a
  mechanically failed verifier is never pattern-scanned, and its error reaches
  you verbatim.
- **Match ⇒ verdict failure.** The stage fails with a reason that cites the
  pattern and the matched line (control bytes stripped, long lines clipped), so
  resume output reads like a mechanical failure: `verdict rejected: verifier
  output matched /REJECT/ on: "…"`.
- **Occurrence threshold (`atLeast`).** `reject('/…/i', atLeast: 2)` fails the
  verdict only when the pattern matches **at least** that many times — for
  reviewers that grade several findings per run, one stray quoted "REJECT"
  then no longer sinks the stage. The threshold pairs with the pattern in the
  same call (there is no standalone `rejectCount()`); it is meaningless without
  `reject()`. At the default `1` the behavior and the reason string are
  byte-identical to match-once; above it the reason counts matches
  (`matched /REJECT/ 3 of at least 2 matches on: "…"`). A threshold below 1
  throws at the builder boundary like a broken pattern, and a hand-built DTO
  carrying one fails CLOSED at the scan.
- **`retries` is one shared budget.** Verdict re-runs and the pool's mechanical
  re-runs draw from the same `retries(N)` allowance — `N` is attempts beyond
  the first, whichever way they failed. Each re-run is a fresh verifier agent
  against the same task result. When the budget is spent and the last verdict
  still matches, the stage fails like any exhausted retry (the reason gains
  `[failed on all N attempts]`), the run stops, and `/workflow resume` re-runs
  the stage with the successful prefix kept.
- **Invalid patterns fail closed.** `reject()` throws at the builder boundary
  if the PCRE will not compile. A task constructed directly (bypassing the
  builder) with a broken pattern cannot pass *or* silently skip its gate: the
  scan reports `verdict scan failed closed` and the stage fails.
- **Absent ⇒ the verdict path is skipped, the question scan is not.** With no
  `reject()` the stage never re-dispatches on a verdict: one verifier dispatch,
  combined `task + "\n" + verifier` output, byte-for-byte the old engine path.
  The completed output is still read for the `ESCALATE:` marker described
  below — that scan answers "did a human get asked?", not "did the rule fire",
  so it changes outcomes only when the marker is actually present.

This is what makes a review→fix→re-review loop an engine feature rather than an
operator habit: the fixer task runs, the verifier judges, a rejecting verdict
consumes a retry (or stops the run for `/workflow resume` after you act on the
reason), and stage tokens and cost already fold every attempt into the run
totals.

### `ESCALATE:` stops the run for a human — retries never burn

A verdict says *no, try again*. A question says *stop and get a person* — and
the two must not share a mechanism. When a verifier's **completed** output
contains a line that starts with the literal `ESCALATE:` (leading spaces or
tabs allowed, case-sensitive, any line of the output), the engine fails the
stage as `escalated`:

```php
->withVerification(
    'review',
    Tasks::agent('coder')->prompt('Fix the finding.'),
    Tasks::agent('reviewer')
        ->prompt('Review the fix. If scope is unclear, start a line with "ESCALATE: <question>".')
        ->reject('/\bREJECT\b/i')
        ->retries(2),
)
```

Semantics, precisely:

- **The marker is always read, `reject()` or not.** Escalation is a property of
  what the verifier *said*, not of a pattern you configured, so a verification
  stage without `reject()` still stops on the marker. (The scan runs only on a
  `Completed` verifier result — mechanical failures keep their verbatim paths.)
- **A question outranks a verdict.** If the output both matches the `reject()`
  pattern and carries an `ESCALATE:` line, the stage fails as `escalated` —
  retrying something a human needs to answer would waste the budget.
- **Zero retries consumed.** Unlike a reject match (which re-dispatches until
  the shared `retries` budget is spent), an escalation fails on the **first**
  completed attempt: no re-dispatch, `retries(N)` untouched, and the reason
  says `[not retried — the run stops for a person]`. The run then stops exactly
  like any failed stage.
- **The question survives the failure.** The escalated verifier's raw output is
  carried on the stage result, and the engine writes it into the run context
  (`<stage>.output`) before the failure check — so a `/workflow pause` record
  for the run shows the human *what is being asked*. Honest boundary: the
  stage's failure reason itself is not persisted in the pause record's
  `stageResults` list (that list carries the successful prefix only); the
  question is what survives, which is the part a person needs.
- **Resume answers it.** Act on the question, `/workflow resume` re-runs the
  stage with the successful prefix kept — the same machinery every failure uses.

`Workflow` itself is immutable; `withStatus()` returns a new instance.

## Loading one directly

```php
$registry = new WorkflowRegistry(__DIR__ . '/examples/workflows');
$workflow = $registry->loadYaml('lint-then-fix');
(new WorkflowEngine($registry, $pool))->run('lint-then-fix', ['scope' => 'src/Chat.php']);
```

A registry constructed with no arguments defaults its user path to
`~/.candy-code/workflows/` and is **not** ownership-checked: `expandPath()`
resolves `~` through `HomeDirectory::path()`, whose fallback is
`sys_get_temp_dir()`, so a process with no resolvable `HOME` anchors
`/tmp/.candy-code/workflows` to its own parent. Nothing in `src/` constructs one
that way — `Bootstrap` passes an absolute path derived from a trusted
resolution — but an embedder should pass one explicitly.

## See also

- [`AGENTS_AUTHORING.md`](AGENTS_AUTHORING.md) — why the preset roster and a
  stage's `agent:` do not meet.
- [`PERMISSIONS.md`](PERMISSIONS.md) — the declaration check.
- [`AGENTS.md`](AGENTS.md) — `Task` sub-agents, the other way to delegate.
- The [README](../README.md#documentation-index) — every other page.
