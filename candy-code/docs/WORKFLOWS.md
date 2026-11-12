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
  maxConcurrent: 3               # whole number >= 1, or 0 for no cap; unset = min(16, CPUs - 2)
  timeout: 900                   # positive int; per-stage seconds
  maxAgents: 200                 # whole number >= 1; run-wide dispatch ceiling (default 1000)
  maxTokens: 2000000             # whole number >= 1; hard run-wide token ceiling (unset = none)
  maxCost: 5.00                  # positive number of USD; hard run-wide cost ceiling (unset = none)
  stopOnFirstFailure: false      # strict boolean; true = a failed agent cancels its stage's siblings

schemas:                         # optional; named object-rooted schemas for outputSchema: {$ref: …}
  verdict:
    type: object
    properties: {ok: {type: boolean}}
    required: [ok]

stages:                          # a LIST, never a map
  - name: lint                   # required, and unique across the file
    agent: reviewer              # default: coder
    prompt: >
      Review the diff at {{scope}} …
    tools: [Read, Grep, Glob, Bash]  # omitted = the session's tools; [] = no tools at all
    retries: 1                   # whole number >= 0; default 0
    outputSchema:                # optional; map, inline JSON string, or file path
      type: object
    isolation: worktree          # optional; own git worktree for this task (or none)
    model: claude-sonnet-4-5     # optional; run this task on a named model
    preset: reviewer             # optional; run this task as an agent preset (the task's own keys win)
    effort: high                 # optional; low|medium|high|xhigh|max reasoning effort
    resource: gpu                  # optional; exclusive host lock held for this task's stage
    lockWaitSeconds: 60            # optional; seconds to wait for that lock before the stage fails
    timeout: 60                    # optional; positive int, this ONE task's wall-clock budget
    stallTimeout: 120              # optional; whole number >= 1; seconds a forked worker may go silent before it expires
    restarts: 1                    # optional; whole number >= 0; re-dispatch budget for stall expiries — its own, apart from retries:
    capture: true                  # optional; publish this stage's full output to a file (downstream sees path + preview)
    announce: "lint found {{lint.output}}"  # optional; a line the run announces once this stage settles

  - name: fix
    parallel: true
    agents:                      # a LIST of maps
      - name: style-fixer
        type: coder              # default: coder
        prompt: Apply the style findings from {{lint.output}} …
        tools: [Read, Edit, Grep]
        retries: 0               # per agent in a parallel stage
```

Defaults are `Workflow`'s own: `maxConcurrent` unset (the default width, below
— never "no cap" unless you write `0`), `timeout: 3600`, `maxAgents: 1000`,
no `maxTokens`/`maxCost` ceiling, and `stopOnFirstFailure: false`.

**Both maps have a closed roster.** The top-level keys are exactly `name`,
`description`, `config`, `schemas`, `stages`
(`WorkflowRegistry::YAML_WORKFLOW_KEYS`), and `config:` takes exactly
`timeout`, `maxConcurrent`, `maxAgents`, `maxTokens`, `maxCost`,
`stopOnFirstFailure` (`WorkflowRegistry::YAML_CONFIG_KEYS`). Any other key
fails the load, first offender named — on every door (a saved file, the
`Workflow` tool's `plan`, a `uses:` include, headless):

```
Workflow file <src> has unknown top-level key "<key>"; supported keys: name, description, config, schemas, stages
Workflow file <src> "config" has unknown key "<key>"; supported keys: timeout, maxConcurrent, maxAgents, maxTokens, maxCost, stopOnFirstFailure
```

So a typo such as `maxToken:` is a load error, not a silent "no ceiling".

**`config.stopOnFirstFailure`** is the PHP builder's
`->stopOnFirstFailure()` spelled in YAML. It is a strict boolean like
`capture:` — `true`/`false` unquoted; `'yes'`, `1`, `'true'` and `~` are
refused (`Workflow file <src> "config.stopOnFirstFailure" must be a boolean
(true or false, unquoted), got <type>`). Absent means `false`. Under `true`, a
parallel stage cancels its remaining agents on the first failure (only an
agent's last attempt counts — see [`retries`](#retries-re-runs-a-failed-agent)),
and a `type: map` stage admits no item after its first casualty; a
`minSuccess` stage is exempt, so its tolerance is judged over every agent
(the linter warns when both are set).

### `tools:` — omitted means the session's tools, `[]` means none

On any task surface (a one-task stage, a `tasks:` entry, a parallel agent, a
pipeline or map step, either slot of a verification stage, a `from:`
template) and on PHP's `->tools([...])`:

- **`tools:` omitted** — the agent gets the session's tools (or its
  `preset:`'s, when it names one).
- **`tools: []`** — the agent gets **no tools**. Nothing is put on the
  request's tool list, and the grant hook refuses any call that reaches it
  anyway: `is refused: agent "<name>" declares tools: [] (no tools)`. The one
  exception is `StructuredOutput`: a schema'd agent (`outputSchema:`) still
  gets its answer channel.
- **An explicit `[]` beats a preset.** `preset: reviewer` plus `tools: []`
  runs with no tools; only an ABSENT `tools:` takes the preset's list.
- **A non-empty list** is exactly the agent's tool set, each call held to its
  declaration by the grant hook (argument-scoped entries included — see
  [Running from the CLI](#running-from-the-cli-headless) for the up-front
  check).

### Width, the fan-out ceiling and the agent budget

Three rails keep one plan from forking a machine's worth of agents.

- **`maxConcurrent` — how many agents of one stage run at once.** Unset, the
  width is `min(16, max(1, CPUs - 2))` (`Workflow::defaultConcurrency()`):
  two CPUs are left for the session and the provider I/O, and sixteen is the
  point past which provider rate limits, not cores, are the bottleneck. The
  CPU count is read without forking anything — `NUMBER_OF_PROCESSORS`, then
  `/sys/devices/system/cpu/online`, then `/proc/cpuinfo` — and falls back to
  4 (a width of 2) when none of them answers (`Workflows\CpuCount`). A
  number `>= 1` is that width. **`0` is the explicit "no cap"**: every agent
  of a parallel stage is dispatched at once, which is why the linter warns
  on it. A negative value is refused. A fan-out's `from.chunk` can only
  narrow the width, never widen it.
- **The fan-out ceiling — 4096 items per `from:` stage**
  (`Workflow::FAN_OUT_CEILING`). A `from:` expansion past it fails the stage
  and dispatches nothing; the list is never silently truncated. The error
  names the source and the fix: `Stage 'sweep' expanded 4097 files from
  "*.txt"; the per-stage fan-out ceiling is 4096 — narrow the glob or set
  from.max`. The check runs before the expansion is recorded for resume, so
  a narrowed pattern is re-read on the next attempt, and a recorded list is
  held to the same ceiling when it is replayed.
- **`config.maxAgents` — a run-wide dispatch budget** (default 1000, a whole
  number `>= 1`). One counter per outermost run counts every dispatch: each
  sequential task, pipeline step, verification task and verifier round, every
  agent of a parallel wave, every map step, every vote of a verifier panel,
  and every loop round. A parallel wave (or a verifier panel) reserves all
  its agents at once before any of them starts, so a wave that would cross
  the ceiling starts none of them. Retries and stall restarts are added when
  the stage settles (the pool cannot be stopped mid-retry), so they refuse
  the NEXT dispatch. An included (`uses:`) workflow shares its parent's
  counter and the outermost run's ceiling — the inner file's own
  `maxAgents` is ignored — and a resumed run continues the count from the
  pause record (`agentsDispatched`). Crossing it fails the stage, and the run
  stops: `agent ceiling reached (1000): this run has dispatched 998 agents
  and this stage needs 4 more; raise config.maxAgents or narrow the
  fan-out`.

### `config.maxTokens` and `config.maxCost` are hard run budgets

`maxAgents` counts dispatches; these two count spend. `config.maxTokens` is a
whole number of tokens `>= 1`; `config.maxCost` is a positive number of USD
(an int, a float, or a numeric string — `"config.maxCost" must be a positive
number of USD, got …` otherwise). Both default to no ceiling. The PHP
builder spells them `->maxTokens(int)` and `->maxCost(float)`.

- **What counts.** Every settled agent result adds its tokens and cost to the
  run's spend — failed attempts included — and so do rows a `cache:` door
  carries and, on `resume`, the totals the record already holds. The ceiling
  always compares the **whole run's** spend.
- **When it is checked.** Before every dispatch — a stage task, a pipeline
  or map step, a verifier or panel vote, a loop round, every agent of a wave
  (refused whole: nothing of it starts) — and before every pool **retry**,
  counting the failed attempt's spend. Spend AT or past the ceiling refuses:
  `budget exhausted (spent 800 of 700 tokens)` or `budget exhausted (spent
  0.60 of 0.50 USD)`; a refused retry reads `not retried after attempt N of
  M: budget exhausted (…)`. It is a "may the next dispatch start" check
  against settled spend, so a wave already in flight can overshoot by its
  own cost.
- **A budget stop PAUSES the run; it does not fail it.** The refused stage
  carries `…; the run is paused: raise the ceiling (config.maxTokens /
  config.maxCost, or the run's token budget) and resume`, the run returns
  `paused`, and a run that has a record (`/workflow run`, `candy-code
  workflow run`, every `Workflow` tool run) writes its pause record with the
  successful prefix — the refused stage re-runs on resume. A `map` stage
  stops admitting items exactly as it does at the agent ceiling. A PHP host
  calling `runWorkflow()` / `runFromPhp()` gets `paused` with no record:
  there is nothing to resume from.
- **Raising the ceiling.** `resume` re-reads the YAML, so edit
  `config.maxTokens` / `maxCost` and resume; the PHP API also takes a
  trailing `budgetTokens:` on `run()`, `runWorkflow()`, `runPlan()`,
  `resume()` and `resumePlan()` (below 1 throws `a workflow token budget
  must be at least 1, got N`), and the `Workflow` tool takes it as
  [`budget`](#model-authored-plans-the-workflow-tool). The effective token
  ceiling is the tighter of `config.maxTokens` and that budget; `maxCost` has
  no external counterpart. Resuming under the same ceiling pauses again with
  nothing dispatched.
- **`{{budget.remaining}}`** interpolates the tokens left under the token
  ceiling (never negative). Without a token ceiling it is left verbatim, and
  the linter warns.
- **`until: {budgetBelow: N}`** — a loop-only leaf — holds once fewer than N
  tokens remain ([Loop-only `until:` leaves](#loop-only-until-leaves)).

The headless verb reports a budget stop with `status` `paused` and exit 1 —
exit 3 is reserved for an escalation or a signal pause.

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
  included: an agent still waiting for one of the stage's width slots when
  the budget runs out never starts. A **map** stage's item chains share one
  budget the same way.
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

### `stallTimeout:` bounds silence, and `restarts:` is its own budget

A forked worker can sit out its wall-clock budget while producing nothing:
wedged on a subprocess, waiting on a prompt that never returns. `timeout:`
cannot see that — the clock has not run out. `stallTimeout: N` bounds the
silence itself: if a dispatched worker's published progress has not grown for
N whole seconds, the pool expires it the way the timeout does — the tree is
killed, reaped, and the agent settles `timed_out` — but the record carries a
distinct cause: the error says the agent "went silent for N s without
publishing progress", and `AgentResult::$stalled` is set.

- **Progress is speech.** The idle clock re-arms every time the pool sees new
  progress output, so a slow-but-live agent never dies of this. The check is a
  timestamp comparison inside the poll that already runs: no new timers, and no
  deadline on any model call.
- **`restarts:` is spent only by silence.** A stall-expired worker may be
  re-dispatched up to `restarts: N` times — its own budget, default 0, which
  leaves the failure shape exactly as it was before the key existed. The
  budgets are separate in both directions: a stall restart never spends a
  `retries:` attempt, and stall attempts never count against the retry gate.
  Once `restarts:` is spent, the stage fails the way a failed stage has always
  failed, with the distinct-cause note on the record.
- **Forked workers only.** Progress is published only on the forked path; a
  task run inline in the parent has no silence to observe, so it stays
  wall-clock-bound. A `stallTimeout:` on a task that runs inline simply never
  fires.
- **The absolute bound still wins.** When both deadlines have fired, the
  recorded cause is `timeout:` (or the stage's time budget): the outer bound
  running out is the honest reason, however silent the agent was.

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
- **Separate from stall restarts:** the `restarts:` budget described above
  counts only stall expiries. A stall restart spends no retry, and stall
  attempts are subtracted out of the retry gate, so a silent-then-stuck agent
  cannot starve a later model failure of its retry.
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
case-sensitive), `matches` (PCRE, validated at load exactly like `reject:`) and
`empty: true|false`. `empty` is exact, never trimmed: only `''` is empty, and
whitespace is content (spell "blank" as `matches: '/^\s*$/'`). The value of
`empty` must be a boolean; the other ops take strings (`equals: true` must be
quoted).

A leaf may add **`pointer:`** to judge one leaf of a schema'd stage's JSON
answer instead of its text:

```yaml
    when: {stage: review, pointer: /verdict, equals: NEEDS_FIX}
```

The pointer is RFC 6901 (leading `/` optional, every `~` written `~0` or `~1`,
checked at load) and reads the stage's **typed row** — the same row, under
the same result name, that `{{review.verdict}}` reads (see
[Pointing inside a schema'd result](#pointing-inside-a-schemad-result)). It
never falls back to the text. The leaf compares by its string form: strings
verbatim, booleans as `true`/`false`, numbers as interpolation splices them,
JSON `null` as `null`, arrays and objects as compact JSON. Behind a pointer,
`empty: true` also holds for `[]` and `{}`; a number, boolean or null leaf is
neither empty nor non-empty, so both `empty: true` and `empty: false` are
unsatisfied. A stage with no typed row (no `outputSchema`, or an answer that
did not decode) and a pointer that misses are unsatisfied — fail-closed, like
every other miss.

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

`when:` is stage-level, with one exception: a step of a
[`type: map`](#type-map-streams-each-item-through-its-steps) stage carries its
own `when:`, judged per item. There is no per-task clause inside a `tasks:`
list; give each conditional step its own stage.

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

`until:` is the `when:` mini-grammar — same leaves (`pointer:` and `empty:`
included), same combinators, validated the same way at load — plus two
loop-only leaves described below (`dry`, `budgetBelow`) that `when:` refuses.
And it refuses rather than coerces, like every other key here: a `loop:` that
is not a map, a map with any key besides `max`, `until` and `accumulate`, a
missing `max` or `until`, a `max` that is not a whole number of at least 1, or
a broken clause each fails the load naming the offender. `max` counts rounds,
not seconds: it is a bounded iteration and holds no timer.

From the second round on, every round also sees **`{{previous}}`**, the prior round's
`<stage>.output` (in the first round the token is left as the context had it —
normally verbatim). `{{round}}`, `{{previous}}` and the accumulate tokens below
shadow same-named context keys only while the loop runs; your own values are
restored after it.

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
pause file see one row per stage, the last round's. A plain loop's round
number is not persisted, so a resumed plain loop re-runs from round 1 —
whole-stage granularity, honest and disclosed — rather than replaying a
half-loop. An `accumulate:` loop is the exception: its settled rounds are
persisted (see below). A cancel that lands mid-loop ends the loop immediately
and the row settles `cancelled` like any in-flight stage's.

A `when:` guard can still skip the whole thing, and a `cache:` hit
short-circuits every round (the door sits ahead of the loop).

**What loops.** `loop:` attaches to plain, `parallel`, `verification` and `map`
stages (`WorkflowBuilder::LOOPABLE_TYPES`). A looped parallel stage re-runs
its whole fan-out each round; a looped verification stage re-runs task and
verifier. It is refused on a `pipeline` stage (`"loop" cannot attach to a
"pipeline" stage; stage, parallel, verification and map stages loop`) and
beside `uses:`, because carried workflows are not looped. One caveat on a
looped `from:` stage (parallel or map): every round after the first replays the first round's
materialised item list from the fan-out record rather than re-reading the
source.

#### `accumulate:` — loop until nothing new turns up

```yaml
  - name: find
    parallel: true
    agents:
      - {name: hunter_a, prompt: "Find bugs in src/. Already known: {{seen}}", outputSchema: {$ref: findings}}
      - {name: hunter_b, prompt: "Find bugs in lib/. Already known: {{seen}}", outputSchema: {$ref: findings}}
    loop:
      max: 5
      accumulate: {pointer: /findings, key: /id}
      until: {dry: 2}
```

`accumulate: {pointer, key?}` keeps one deduplicated item set across the
rounds. Each round, every completed **schema'd** agent's answer contributes —
all agents of a parallel stage (in declaration order), all tasks of a `tasks:`
stage, only the TASK slot of a verification stage (never the verifier), the
map document of a map stage — and the array at `pointer` in each answer is
folded in. An item's identity is its `key` leaf (a string, number or bool);
without `key` it is the item itself (a scalar's text, otherwise its compact
JSON). Dedup is against everything seen so far, first sighting wins, order is
kept.

- A contributing answer whose `pointer` is not an array fails the round
  (`loop.accumulate: agent 'x' of stage 's' answered a string at "/f", not
  an array`), as does a round with no schema'd answer to read and an item
  with no key leaf. A failed round folds nothing and the loop continues, as a
  plain loop does.
- **`{{seen}}`** is the JSON list of every key collected so far and
  **`{{fresh}}`** the JSON list of the items the previous round added; both
  are `[]` in the first round.
- **The recorded result** — when the last round did not fail — is one JSON
  document under the stage's name, as both its output and its typed row:
  `{"items":[…every distinct item, first-seen order…],"keys":[…],"rounds":N}`.
  It is pointer-addressable like any schema'd answer: `{{find.items/0/id}}`,
  `{{find.rounds}}`, `when: {stage: find, pointer: /items, empty: false}`,
  `from: {stage: find, pointer: /items}`. A last round that failed settles the
  stage as that failure, as for plain loops.
- **The set is persisted per round.** Each settled round writes the
  accumulated set (items, keys, the fresh set and the dry count) and the round
  number into the run's pause or run record, so a resumed run continues at the
  next round with the same `{{seen}}`, `{{fresh}}` and dry count — completed
  rounds are not re-dispatched. The resume applies only when the record is for
  this same stage at the same position, its declaration is unchanged, and the
  loop still has rounds left under `max:` and has not met `until:`; otherwise
  the loop starts from round 1 with an empty set. A record written before this
  state existed resumes exactly as it always did, from round 1. `cache: true`
  can still carry the recorded `{"items":…}` row.

#### Loop-only `until:` leaves

- **`{dry: N}`** (whole number `>= 1`) holds once N consecutive completed
  rounds added nothing new to the accumulated set. It needs `accumulate:` —
  without it the load is refused (`… uses {dry: N}, which counts rounds that
  add nothing new to an accumulated set; add "accumulate: {pointer, key}" or
  drop the dry leaf`) — and the linter warns when N is not below `max`.
- **`{budgetBelow: N}`** (whole number `>= 1`) holds once fewer than N tokens
  remain under the run's token ceiling — `config.maxTokens`, or the tighter
  run budget a caller passed ([run budgets](#configmaxtokens-and-configmaxcost-are-hard-run-budgets)).
  It is checked after each round settles, against the spend so far, so
  "stop looping while there is still room for the stages after this one" is
  `until: {any: [{stage: s, contains: DONE}, {budgetBelow: 200000}]}`. A run
  with no token ceiling has no remaining figure: the leaf never holds and the
  loop runs to `max` — the linter warns when the plan sets no
  `config.maxTokens`.

A loop leaf is a whole condition on its own — no `stage:`, no `pointer:` —
and may sit inside `all:`/`any:`.

### `outputSchema` gives a stage a structured verdict

`outputSchema:` on any task surface — a one-task stage, one agent of a parallel
stage, one task in a `tasks:` list, a pipeline or map step, either slot of a
verification stage — or `->outputSchema($schema)` on a PHP task makes that
agent's answer a JSON document that matches the schema. The value is a YAML
map (written inline), a string of inline JSON starting with `{`, a path to a
readable file containing one, or `{$ref: <name>}` naming a top-level
[`schemas:`](#schemas-names-a-schema-once) entry. Anything else — a sequence,
malformed JSON, an unreadable path — is refused at load or build time, never
coerced.

**The answer is enforced through a tool call, not a request flag.** A
schema'd agent's run gets one extra tool, `StructuredOutput`, whose input
schema IS the task's schema (`Agents\StructuredOutputTool`, minted per run).
Its system prompt is told to finish by calling it exactly once with the
answer as its arguments.

- **Every call is validated.** The arguments are checked against the schema;
  a mismatch comes back to the agent as an error result listing each
  violation (`$.verdict: must be one of pass, fail`, `$.ok: is required`), and
  the agent corrects the call inside the same step budget. An accepted call
  ends the run at that step — no further provider call.
- **What the stage sees:** the validated document as compact JSON (an empty
  object is written `{}`), so `{{name.output}}`, `{{name.results}}` and
  pointer readers parse it directly. A schema whose root is not an object
  (`{type: array}`, `{type: string}`) is wrapped on the wire as
  `{value: …}` and unwrapped again, so the stage output is the bare value
  (`["a","b"]`).
- **A reply that is the document counts too.** A final reply that is itself
  exactly a valid document — the whole reply, optionally inside one
  ```` ```json ```` fence — is accepted without the call. Prose never is.
- **One reminder, then failure.** A run that ends in prose (or blank) without
  an accepted call is re-prompted once, inside what is left of its step
  budget (at least one step), to make the call. If it still does not, the
  agent FAILS: `agent "<name>" declares an outputSchema but ended without an
  accepted StructuredOutput call, even after being told to make one, so it
  produced no structured result (its last reply began: "…")`. Both attempts
  are billed. Tools run before the answer no longer leave it unconstrained:
  the old `[the reply was not constrained …]` disclosure is gone.
- **Capability is checked before the run:** a provider that cannot make tool
  calls refuses the stage loudly — `… cannot make tool calls, so it cannot
  constrain the answer to a schema (the answer is enforced through the
  StructuredOutput tool call); the run was not started` — rather than billing
  for a structured answer and collecting prose. Native JSON-schema mode is
  no longer required, and no longer used on this path: a request held to a
  schema cannot carry tool calls, so it would forbid the very call (and every
  read before it).
- **The call bypasses the permission gate and every `PreToolUse` hook,** the
  stage's `tools:` grant included. It is the run's answer, not an action — it
  writes nothing — and under `default`, `plan` or `dont-ask` an unlisted tool
  would otherwise ask or be refused and strand a non-interactive stage. The
  exemption is by tool *instance*, never by name, so a user or MCP tool that
  happens to be called `StructuredOutput` is still gated. No preset or
  `tools:` list can grant or withhold it.
- **Supported schema keywords.** Validation uses the session's own
  `Protocol\Schema\SchemaValidator`, which enforces `type` (unions
  included), `properties`, `required`, `additionalProperties` (`false` or a
  schema), `items`, `enum`, `oneOf`, `$ref` into the schema's own `$defs`
  (`#/$defs/<name>`), `maxItems`, `maxLength`, `minimum` and `maximum`. Other
  keywords — `minItems`, `minLength`, `pattern`, `const`, `anyOf`, `allOf`,
  `format`, … — are accepted in the schema but NOT enforced; check those in
  a downstream stage if they matter.
- **Notes stay off the answer.** A schema run's side notes (a kept worktree, a
  resume note) go to the live activity stream, not the output, which must
  stay a bare JSON document.
- **Headless is unchanged.** `-p --json-schema` still rides the provider's JSON
  mode with its `json_schema_applied` disclosure (see
  [`FLEET-DRIVING.md`](FLEET-DRIVING.md#structured-answers---json-schema));
  only workflow stage agents use `StructuredOutput`.
- **And it composes:** a verification stage's accept/reject pattern and an
  `outputSchema` are independent features that stack — give the verifier a
  schema and its verdict arrives machine-readable, not pattern-matched prose.
- **And the document stays addressable:** a schema'd stage that answers with
  valid JSON records a typed row beside its text, so later prompts read
  single leaves — `{{build.summary}}`, `{{build.items/0/id}}` — while
  `{{build.output}}` and `{{build.results}}` keep showing the whole document.
  See `Interpolation` below.

### `schemas:` names a schema once

A top-level `schemas:` map declares named schemas that any task's
`outputSchema: {$ref: <name>}` resolves to at load, on every task surface
(the JSON-string spelling `'{"$ref":"verdict"}'` resolves too):

```yaml
schemas:
  verdict:
    type: object
    properties: {refuted: {type: boolean}, why: {type: string}}
    required: [refuted]
stages:
  - name: check
    prompt: Is the claim in {{claim}} wrong?
    outputSchema: {$ref: verdict}
```

- Names match `[A-Za-z_][A-Za-z0-9_-]*`; each schema must be a map with
  `type: object` at its root, and a `required` list may name only keys of
  its `properties`.
- The reference is exactly the one key `{$ref: <name>}`. A `$ref` beside other
  keys, a `$ref` that is not a string, an unknown name (the error lists the
  declared ones) or a `$ref` in a file with no `schemas:` map is refused. A
  `$ref` nested *inside* a schema is JSON Schema's own and is left alone.
- The task carries the resolved document, so the `cache:` identity hash
  digests the schema itself: a reference and the same schema written inline
  hash alike, and editing the shared schema moves the hash.
- PHP has no spelling for it — share a variable instead.

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

### `preset:` runs a task as an agent preset; `effort:` sets its reasoning effort

`agent:` (`type:` on a parallel agent) is only a label. `preset: <name>` on
any task surface — a one-task stage, a `tasks:` entry, a parallel agent, a
pipeline or map step, either slot of a verification stage — or
`->preset($name)` on a PHP task runs that task **as** the named agent preset:

```yaml
  - name: review
    preset: reviewer             # the reviewer `Task` would run
    prompt: Review {{diff}} for correctness.
    tools: [Read, Grep]          # the task's own keys win over the preset's
```

- **Resolution.** The name resolves against the same roster a `Task`
  delegation uses — built-ins, then foreign (`.claude/agents`,
  `.opencode/agents`), then native project, then native user presets, a later
  tier replacing a same-named earlier one (`Bootstrap::agentRoster()`, or the
  session's `AgentManager`). Every door wires it: `/workflow run` and
  `candy-code workflow run` through `Bootstrap::workflowEngine()`'s deferred
  roster, the `Workflow` tool through the session's roster — each built only
  when the plan names a preset, since it reads preset files from disk.
- **An unknown name refuses the run before anything is dispatched.** The
  loader checks only the spelling (`"preset" must name an agent preset (e.g.
  reviewer), got an empty string`); the engine's pre-flight resolves every
  preset at any depth and refuses with `Stage '<s>' preset "<n>": unknown
  agent preset "<n>" (known: …); nothing was dispatched` (or, with no roster
  at all, `…: this engine has no agent roster to resolve presets against (the
  host that runs it must hand one over); nothing was dispatched`). Every lint
  caller — `/workflow lint`, the pre-run lint of all three doors — passes the
  session's preset names, so the same plan is a lint ERROR first: `preset
  "<n>" is not an agent preset this session knows (known: a, b); the engine
  refuses the run before dispatching anything.`
- **The merge — the task's own keys always win.**

  | From the preset | Applied when |
  |---|---|
  | `tools` | the task's `tools:` is absent (an explicit `tools: []` means no tools and wins) |
  | `model` | the task's `model:` is absent or `inherit` |
  | `effort`, `isolation` | the task leaves them unset |
  | system prompt (the preset body) | always — the stage agent runs under it |
  | `disallowedTools`, `maxTurns` | always — the grant hook and the step loop honour them |
  | `permissionMode` | only when **stricter** than the session's mode |

  The permission mode can only tighten: the stage agent's calls are judged
  by the session's rules under the preset's mode, a `bypass-permissions`
  session's children stay bypass, and an engine with no session gate has
  nothing to narrow — the `Task` ruling
  ([`PERMISSIONS.md`](PERMISSIONS.md#a-sub-agents-mode)). A preset's
  `skills` are **not** carried; the `skills:` route is the task's own.
- **Identity.** A task's `preset` and `effort` join its `cache:` identity
  hash only when set, so a stage without them hashes exactly as before.

`effort: low|medium|high|xhigh|max` (or `->effort(Effort|string)`) sends that
reasoning effort with the task's requests. A provider that ignores reasoning
effort still runs the task, and the run says so in a bracketed note —
`[effort: high sent, but provider "x" ignores reasoning effort]`. Any other
value fails the load (`effort must be one of low, medium, high, xhigh, max,
got "huge"`).

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
  Neither are a stage's `from:` and `loop:` specs nor a map step's `when:` (a
  map hashes every step's prompt, model and schema in order): `cache: true`
  on such a stage is the author's assertion that the source is stable.
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
exactly `stage`, `parallel`, `pipeline`, `verification` and `map` (the last has
[its own section](#type-map-streams-each-item-through-its-steps)); anything
else is refused at load, naming the offending word. `parallel: true` remains the flag
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

**A naming note, because it trips people.** A candy `pipeline` is a sequential
chain of steps for ONE item. What the reference workflow tooling calls a
per-item `pipeline()` — every item of a list flowing through the same steps on
its own — is candy's [`type: map`](#type-map-streams-each-item-through-its-steps).

### Verifier panels: `votes`, `lenses` and `quorum`

One verifier is one opinion. A verification stage's `verifier:` may convene a
panel instead:

```yaml
  - name: review
    type: verification
    task: {prompt: "Fix the finding in {{finding}}.", tools: [Read, Edit]}
    verifier:
      prompt: "Via the {{lens}} lens, try to REFUTE this fix: {{prevResult}}. Default to REJECT if uncertain."
      reject: '/\bREJECT\b/'
      votes: 3
      lenses: [correctness, security, repro]
      quorum: 2
      retries: 1
```

- **`votes: N`** (whole number `>= 1`, default 1) dispatches N verifier agents
  **concurrently** after the task has run once, on one fresh pool of width N
  that spends what is left of the stage budget. The panel is reserved against
  `config.maxAgents` as one wave before any vote starts, and each vote runs
  with no retries of its own — the panel is the retry unit.
- **`lenses: [...]`** gives each vote one lens name (non-empty strings, exactly
  one per vote — a count that differs from `votes` is refused, and since
  `votes` defaults to 1 a lone two-lens list is refused too). `{{lens}}` in
  the verifier prompt becomes that vote's lens; without `lenses` it stays
  verbatim (the linter warns).
- **`quorum: K`** (1..`votes`, default the majority `floor(votes/2)+1`) is how
  many votes must NOT reject for the verdict to pass.
- **A vote rejects** when its completed output matches `reject:` (with
  `rejectMinOccurrences:` exactly as for one verifier) — or when the vote
  failed, timed out or never produced a result: a vote that could not attest
  the claim counts against it, and is never pattern-scanned. A panel with no
  `reject:` therefore only loses votes to failures, which the linter warns
  about.
- **Order of judgement:** an `ESCALATE:` line in ANY vote outranks everything,
  a met quorum included — the stage fails at once, burns no retry, and says
  which vote asked (`escalated: verifier vote 2 (security) asked for a human
  decision on: "…"`). Then an uncompilable `reject:` pattern fails the stage
  closed. Then the verdict passes iff the non-rejecting votes reach the
  quorum.
- **`retries: N` re-runs the whole panel** up to N more times after a missed
  quorum; the task is not re-run.
- **What the stage records.** Each vote's result is named
  `<verifier name or <stage>_verifier>_<k>`, 1-based (`review_verifier_2`),
  with a typed row when the verifier is schema'd. On a pass the stage output
  is the task output followed by every vote's output; on a failure the error
  counts the votes and quotes each rejection — `verdict rejected by the
  panel: 1 of 3 votes passed and the quorum is 2; vote 1 (correctness)
  matched /\bREJECT\b/ on: "…"; vote 3 (repro) timed out: …` (plus `[failed
  on all 2 panel rounds]` after retries).
- **`{{review.votes}}`** is the last round's votes as compact JSON,
  `[{"lens": …|null, "rejected": true|false, "output": "…"}]` — `data` (the
  decoded answer) in place of `output` when the verifier has an
  `outputSchema` and the vote decoded. It is written whenever a panel ran,
  even when the stage failed, and it rides the pause record.
- **Placement.** The three keys parse on every task surface, like `reject:`,
  and are legal only on the verifier slot of a verification stage (or of a
  map verification step). Anywhere else the run is refused before anything is
  dispatched: `votes, lenses and quorum convene a verifier panel and belong
  on the verifier slot of a verification stage`. A verifier with none of the
  three keys takes the single-verifier path, byte for byte as before; any
  key set away from its default (`votes: 1` with `lenses: [x]` included)
  convenes a panel.
- PHP: `TaskBuilder::votes()`, `lenses()` and `quorum()`; the cross-key law
  runs at `build()`.

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
      max: 200                   # optional; keep the first N items, list the dropped rest
    prompt: Audit {{path}} and report findings.

  - name: verify
    parallel: true
    from: {stage: find, pointer: /findings, key: /id, chunk: 8}
    prompt: "Refute {{item.claim}} at {{item.file}}:{{item.line}} (#{{index}})"

  - name: each
    parallel: true
    from: {context: files}       # a run-context value that is a JSON list
    prompt: "Read {{item}}"
```

A `parallel:` stage (or a [`type: map`](#type-map-streams-each-item-through-its-steps)
stage) may carry `from:` instead of `agents:` or `tasks:` — the spelling is one
at a time, refused at load otherwise. The stage's `prompt:` is the template:
every item becomes one task sharing the template's arms (`tools`, `retries`,
`timeout`, `stallTimeout`, `restarts`, `input`, `outputSchema`, …). `from:`
reads **exactly one source** (`WorkflowRegistry::YAML_FROM_KEYS`):

- **`glob:`** — files under the run's environment root (the session project —
  not the directory the workflow file lives in), expanded **once** when the
  stage is entered. Each match is one item, named by its relative path, and
  `{{path}}` is that path. The walk never follows symlinks — a linked file or
  directory is invisible to the expansion — and a name that would resolve
  outside the environment root is refused loudly rather than fanned out. An
  empty glob fails the stage, as the likely typo it is.
- **`stage:` + `pointer:`** — the array at an RFC 6901 `pointer` (`""` is the
  whole row) in a **prior** stage's typed row: a schema'd single-task stage
  named `stage`, a [`loop.accumulate`](#accumulate--loop-until-nothing-new-turns-up)
  stage's `{"items":…}` document, or a map stage's document. A parallel stage
  without `accumulate` keeps its rows under `<stage>_<n>`, so it has no row
  under its stage name to point into, and the fan-out fails loudly.
- **`context:`** — a run-context key whose value is a JSON list (handed in
  through the `Workflow` tool's `context`, a PHP caller's array, or a
  `key:=<json>` pair on either command line — `/workflow run fix-wave
  files:='["src/A.php","src/B.php"]'`, see [Running one](#running-one)). A
  plain `files=…` pair is a string, and the stage fails before dispatching
  anything. The key must not be empty or start with `@`.

Optional keys beside the source:

- **`key:`** (data sources only) — a pointer into each item. It dedups the
  list (first occurrence wins, order kept) and names each item's task
  `<stage>_<key>`, sanitised to `[a-zA-Z_][a-zA-Z0-9_]*` (other bytes become
  `_`, a leading digit gets a `_` prefix, later collisions get `_2`, `_3`, …).
  Without `key`, data items are neither deduped nor named and record as
  `<stage>_<n>` (1-based).
- **`chunk:`** caps the stage's in-flight window — a ceiling, never a floor:
  it can narrow the workflow's width, never widen it; omit it and the
  workflow's own width applies.
- **`max:`** (1..4096) keeps the first N items — of the sorted expansion for a
  glob, in source order for data — and drops the rest *visibly*. When it
  drops anything the stage output is headed and tailed with the cut:

  ```
  [from: kept 2 of 5 matches (max: 2); 3 dropped, listed at the end of this output]
  <agent outputs…>
  dropped: c.txt
  dropped: d.txt
  dropped: e.txt
  ```

  The header sits on top so a `capture: true` preview always shows it; a
  dropped data item is labelled by its key, or by its compact JSON clipped to
  120 characters. Nothing dropped means byte-identical output to a stage
  without `max`. Past 4096 items without `max`, the
  [fan-out ceiling](#width-the-fan-out-ceiling-and-the-agent-budget) fails the
  stage.

**The item tokens.** In every fan-out (glob included) the template sees
`{{item}}` — a string verbatim, a boolean as `true`/`false`, a number as text,
anything else as compact JSON — `{{item.<pointer>}}` for one leaf of it
(containers render as JSON, a miss stays verbatim) and `{{index}}`, the
item's **0-based** position. They are spliced in after every other pass, so
item data — usually some model's output — is never re-scanned for `{{…}}`, and
the item wins over a context key or result named `item` or `index`. Inside a
fan-out `{{item.output}}` and `{{item.results}}` are item pointers too.

**Data sources fail loudly, except when empty.** A source that is not a JSON
array fails the stage and dispatches nothing (`Stage 'v' fans out from stage
'find' at "/f", which is an object, not an array`; `… from context 'files',
which is nothing (the run was handed no such key)`), as do a missing typed
row and an item with no string, number or bool at `key`. **An empty data list
completes the stage with no agents and an empty output** — "nothing to
verify" should flow on — while an empty glob still fails. A `minSuccess`
stage then records `{{stage.failed}}` as `[]`.

**The expansion is the pause record's, not the source's.** The materialised
list rides every pause write — file paths for a glob, `{items, names, chunk}`
(plus `max`, `matched` and the dropped list when `max` cut it) for data — and
a resume replays *that recorded list*. It never re-globs or re-reads the
stage or context, because a source that changed mid-run would otherwise
silently fork different work from what was paused. A stage that paused
*before* it ever expanded has no record yet, and its resume expands normally —
that is entry, not replay. Once a record exists it is honoured exactly: the
list stands as recorded, a recorded `chunk` wins over the re-read YAML, and a
record that is malformed — a broken list, a broken chunk, a partial truncation
record — fails the stage loudly (`… resumed with a dynamic-wave record it
cannot replay — refusing to re-read stage 'find' at "/f", which may have
changed`) instead of silently re-expanding. The single fallback is a recorded
chunk written as null: that wave expanded without a ceiling, so its replay
takes the ceiling from the current YAML, and a ceiling can only narrow the
concurrency, never the work itself.

Static and dynamic spellings feed the same machinery, so `retries:`, the stage
`timeout:`, `stallTimeout:`, `restarts:`, `minSuccess`, and
`stopOnFirstFailure` ride a fan-out unchanged.

### `minSuccess` tolerates casualties in a fan-out

By default any failed or timed-out agent fails a parallel stage. `minSuccess`
on a `parallel` stage (static `agents:` or `from:`) or a `map` stage says how
many must succeed instead:

```yaml
  - name: verify
    parallel: true
    from: {stage: find, pointer: /findings, key: /id}
    minSuccess: 90%              # or a count: minSuccess: 12
    prompt: "Refute {{item.claim}}"
```

- The value is a whole number `>= 1` (a quoted integer is accepted) or
  `"NN%"` with NN from 1 to 100. A percentage is of the agents actually
  dispatched (after a `from:` expansion; for `map`, of the items), rounded
  **up**, at least 1. A count above a static `agents:` list's length is
  refused at load — it could never be met.
- A casualty is an agent whose result is `failed` or `timed_out`, or that
  never produced one (`cancelled`). With none, the output is unchanged. With
  some, the output gains `[k of n agents failed: name (status), …]` after the
  agents' outputs (and before any `from.max` dropped list).
- **Met** → the stage completes with no error. **Not met** → it fails:
  `minSuccess 90% not met: 8 of 10 agents succeeded and 9 were needed;
  failed: verify_f3 (failed), verify_f7 (timed_out)`.
- **`{{verify.failed}}`** is the JSON list of the casualties' result names
  (`[]` when none failed; for `map`, the item keys, else `<stage>_<n>`). It is
  written only for `minSuccess` stages, survives the pause record, and wins
  over a typed row's own `failed` leaf only when it exists.
- **`stopOnFirstFailure` does not apply** to a `minSuccess` stage: every
  sibling runs so the tolerance is judged over all of them. The linter warns
  when both are set.
- It is refused on plain, pipeline, verification and `uses:` stages
  (`carries "minSuccess", which only a parallel or map stage can tolerate
  failures with`). PHP: `WorkflowBuilder::minSuccess(12)` or
  `->minSuccess('90%')`, attached to the last stage.

### `type: map` streams each item through its steps

A `map` stage runs a short chain of steps **per item**, with no barrier
between steps: item A can be on its verifier while item B is still being
refuted. This is what the reference tooling calls a per-item `pipeline()`;
candy's own `pipeline` is the sequential one-item chain (see the
[naming note](#type-verification-and-type-pipeline-reach-the-compound-shapes-from-yaml)).

```yaml
  - name: per_finding
    type: map
    from: {stage: find, pointer: /findings, key: /id, max: 150}   # any from: source
    minSuccess: 90%
    steps:
      - name: refute
        prompt: "Refute {{item.claim}}. Default refuted=true if unsure."
        outputSchema: {$ref: verdict}
      - name: fix
        when: {step: refute, pointer: /refuted, equals: "false"}
        prompt: "Fix {{item.claim}}. Verdict: {{prevResult}}"
        isolation: worktree
      - name: check
        type: verification
        task: {prompt: "Run the tests for {{item.file}}"}
        verifier: {prompt: "Via {{lens}}: REJECT if broken", reject: '/REJECT/', votes: 3, lenses: [a, b, c]}
```

- **Shape.** A map stage needs `from:` (any source, with `chunk`, `max`,
  `key`, the 4096 ceiling and the prior-stage law exactly as for a parallel
  fan-out) and a non-empty `steps:` list. Its stage keys are `name`, `type`,
  `from`, `steps`, `minSuccess`, `when`, `loop`, `capture` and `cache`; a task
  key at stage level (`prompt` belongs on a step), a body key (`agents`,
  `tasks`, `task`, `verifier`, `stages`) and `parallel: true` are refused, as
  is `steps:` on any other stage type.
- **Steps.** Each step needs a `name`, unique within the map. A step is a task
  map (task keys plus `when:`, optionally `type: stage`) or `type:
  verification` with `task:`/`verifier:` sub-maps (whose verifier may be a
  [panel](#verifier-panels-votes-lenses-and-quorum)). Parallel, map, pipeline
  and `uses:` steps are refused in v1, as are `parallel`, `agents`, `tasks`,
  `stages`, `from`, `steps`, `loop`, `minSuccess`, `capture`, `cache` and
  `with` on a step.
- **Per item, steps run in order on an item-scoped context**: the stage
  context plus `{{item}}`, `{{item.<pointer>}}`, `{{index}}` (and `{{path}}`
  for a glob). `{{prevResult}}` is the previous step of THIS item (`""` for
  the first), and `{{<step>.results}}`, `{{<step>.output}}`,
  `{{<step>.<pointer>}}` and `{{<step>.votes}}` read this item's earlier
  steps. None of it leaks to other items or to later stages; a step named
  like a global stage shadows that stage's `.output` inside the item.
- **A step `when:`** is judged against the item context; its leaves may spell
  `step: <name>` (a synonym of `stage:`, never both) and may read only earlier
  steps or earlier stages. A skipped step records `{status: skipped}`,
  **passes `{{prevResult}}` through unchanged**, and leaves its `.output`
  token verbatim. A clause that misses skips the step.
- **Failure drops the item.** The first failing step (a throw inside it — a
  permission refusal, an unknown tool, the agent ceiling — included) ends
  that item; its remaining steps never run and are absent from its row. The
  other items carry on. Without `minSuccess` any dropped item fails the stage
  (`Map stage 'per': 1 of 3 items failed: per_2 (step 'one' failed: b
  broke)`); with it, the count is over ITEMS and `{{per_finding.failed}}`
  lists the item keys (from `key:`, the path for a glob, else `<stage>_<n>`).
  `stopOnFirstFailure` without `minSuccess` admits no item after the first
  casualty, and in-flight chains stop before their next step as `cancelled`.
  An agent-ceiling refusal stops admitting items and fails the stage whatever
  `minSuccess` says; items not yet started record `cancelled`.
- **Output.** The stage output, its typed row under the stage name and
  `{{per_finding.results}}` are one JSON document:

  ```
  {"items":[{"key":<key|path|null>,"index":0,"status":"completed|failed|cancelled",
             "steps":{"<name>":{"status":"completed|failed|skipped","output":"…",
                                "data":{…}?,"votes":[…]?,"error":"…"?}},
             "error":"step 'x' failed: …"?}],
   "matched":M?, "dropped":[labels]?}
  ```

  `data` is the decoded answer of a schema'd task step (or a verification
  step's task); `matched`/`dropped` appear only when `from.max` cut the list,
  in place of the text notice, so the output stays JSON. It is
  pointer-addressable: `{{per_finding.items/0/steps/fix/output}}`, `when:
  {stage: per_finding, pointer: /items, empty: false}`, `from: {stage:
  per_finding, pointer: /items}`. An empty data list completes with
  `{"items":[]}`; an empty glob fails.
- **Budget and keys.** Every step of every item shares the one stage clock
  (`config.timeout`); per-step task keys (`retries`, `timeout`,
  `stallTimeout`/`restarts`, `resource`, `isolation`, `model`,
  `outputSchema`, `input`) work as on any task, and every step dispatch,
  vote and retry counts against `config.maxAgents`.
- `loop:` attaches to a map (`accumulate` reads the map document, e.g.
  `pointer: /items`), and `cache:` hashes every step's prompt, model and
  schema in order (not the step `when:` or the `from:` spec).
- PHP: `WorkflowBuilder::map($name, $from, $steps)` with steps built from a
  named `TaskBuilder`, `WorkflowBuilder::mapStep($task, when: …)` or
  `WorkflowBuilder::mapVerification($name, $task, $verifier, when: …)`.

Four limits to design around:

- **Pause and resume are whole-stage.** The materialised item list rides the
  pause record exactly as a parallel `from:` list does, but a pause mid-map
  re-runs EVERY item on resume — there is no per-item credit yet.
- **No per-step hooks.** The map announces `StageStarted` per round and one
  `StageComplete`/`StageFail` like any stage — no per-item or per-step
  events, by design.
- **Width counts item chains, not agents.** Up to the stage width
  (`maxConcurrent`, narrowed by `from.chunk`; `0` = every item) of chains are
  in flight; a vote panel inside a step runs its votes together, so agents in
  flight can reach width × votes.
- **Inline executors serialise.** Chains interleave by suspending while their
  agent runs on a forked worker. A dispatch that runs inline — an injected
  executor that does not fork the agent (the `Workflow` tool's path for an
  agent that may ask), or a build without `pcntl` — never yields, so its
  chain runs to completion before the next item is admitted.

### `announce:` and the run's announcements

A run says what it decided on its own. `announce: <template>` on any stage
form (`uses:` included) — or `->announce($template)` on the PHP builder,
attached to the last stage — is interpolated with the run context once the
stage **settles**, so its `{{<stage>.output}}`, pointer leaves and
`{{<stage>.failed}}` are in scope:

```yaml
  - name: triage
    prompt: Decide whether {{diff}} needs a security review.
    tools: [Read, Grep]
    outputSchema: {$ref: verdict}      # {ok: boolean}
    announce: "triage settled: ok={{triage.ok}}"
```

A failed stage announces too; a `when:` skip, a `cache:` carry and a stage
carried by a `Workflow` tool `resumeFrom` announce nothing (nothing settled).
A blank or non-string template fails the load (`"announce" must be a
non-empty template string, got …`), and the linter mirrors it. `announce` is
presentation: it is not part of a stage's resume identity.

Beside the author's lines, the engine makes four **mandatory** announcements
— the decisions a plan would otherwise take silently:

| Kind | When | Line |
|---|---|---|
| truncation | a `from.max` cut a parallel `from:` list or a map's items | `Stage 'v': from.max kept 2 of 5 items; 3 dropped (listed in the stage output)` (`files` for a glob) |
| casualties | a `minSuccess` stage lost agents or items — met or not | `Stage 'fan': 1 of 3 agents failed, minSuccess 2 met; failed: two (failed)` (at most 10 names, then `, +N more`; `items` for a map) |
| budget | a run budget refused a dispatch or a retry (once per run) | `Stage 'c': budget exhausted (spent 800 of 700 tokens); the run is paused before this dispatch` / `…; a retry was not started and the run is paused` |
| ceiling | `config.maxAgents` refused a dispatch (once per run) | `Stage 'b': agent ceiling reached (1): 1 agent dispatched and a stage needed 2 more` |

Every line lands on `WorkflowResult::$announcements` (also on a paused or
failed result) in the order made. A host can stream them as they happen:
`WorkflowEngine::setAnnouncementSink(\Closure)` is called with `($line,
['kind' => 'announce'|'truncation'|'casualties'|'budget'|'ceiling', 'stage' =>
?string, 'workflowId' => string])`; a sink that throws is ignored, and with
no sink the run is unchanged apart from the result field. Announcements are
not hook events — the [lifecycle events](#stage-lifecycle-events) stay
observe-only stage transitions. Where each surface shows them:

- **`Workflow` tool** — live, as the call's activity line under the
  `Workflow` row while the run works, and as `announcements` in the result's
  JSON.
- **`/workflow run` / `resume`** (TUI and `serve`) — after the run, as an
  `Announcements:` block under the report (`- <line>` each); the run's fiber
  has no channel to the transcript while it works. A run that announced
  nothing reports exactly as before.
- **`candy-code workflow run` / `resume`** — text mode prints `  announce:
  <line>` under the stage rows on stdout; JSON adds `result.announcements`
  only when the list is non-empty.

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
- a top-level key outside `name`, `description`, `config`, `schemas`,
  `stages` (`has unknown top-level key "<key>"; supported keys: …`).
- `config:` not a map; a `config:` key outside its roster (`"config" has
  unknown key "<key>"; supported keys: …`); `maxConcurrent` not a whole number
  >= 0 (0 means no cap); `timeout` not a positive integer; `maxAgents` not a
  whole number of at least 1 (`"config.maxAgents" must be at least 1, got
  0`); `maxTokens` not a whole number of at least 1; `maxCost` not a positive
  number of USD; `stopOnFirstFailure` not an unquoted `true`/`false`.
- `schemas:` not a map of name => schema, a name outside
  `[A-Za-z_][A-Za-z0-9_-]*`, a schema that is not a map or has no
  `type: object` at its root, or a `required` that is not a list or names a
  key its `properties` lacks; an `outputSchema: {$ref: …}` that names an
  unknown schema, sits beside other keys, is not a string, or appears in a
  file with no `schemas:` map.
- `stallTimeout:` not a whole number of at least 1 (`stallTimeout: 0`,
  `stallTimeout: 2.5`).
- `restarts:` not a whole number of at least 0 (`restarts: -1`,
  `restarts: lots`).
- `retries:` not a whole number of at least 0 (`retries: -1`, `retries: lots`).
- `when:` outside the closed grammar: `when:` not a map, an unknown key in a
  condition (`gt:`), a leaf missing `stage:` or carrying more than one of
  `equals`/`contains`/`matches`/`empty`, a combinator other than
  `all:`/`any:` or one nested past the two-layer ceiling, a list that is not
  non-empty, a non-string value for a string op or a non-boolean `empty:`, a
  `pointer:` that is not a non-empty string or not valid RFC 6901 (`~` must
  be `~0` or `~1`), a broken `matches:` PCRE, or a loop-only leaf (`dry`,
  `budgetBelow`) in a `when:` — each fails the load naming the offender, so a
  half-meaningless guard never reaches a run.
- `loop:` outside its closed roster: `loop:` not a map, a map carrying any key
  besides `max`, `until` and `accumulate` or missing `max` or `until`, a
  `max:` that is not a whole number of at least 1, an `until:` outside the
  `when:` grammar plus its loop leaves, a `dry`/`budgetBelow` leaf that is not
  a whole number of at least 1 or carries `stage:`/`pointer:`, `{dry: N}`
  without `accumulate:`, an `accumulate:` that is not a map, lacks `pointer`,
  carries a key besides `pointer` and `key`, or holds an invalid pointer, a
  `loop:` attached to a `pipeline` stage, or a `loop:` beside a `uses:` stage
  (carried workflows are not looped) — each fails the load naming the
  offender.
- `from:` with no source or more than one (`from carries "glob" and
  "context"; a fan-out reads exactly one source`), an unknown key (the
  roster is `glob`, `stage`, `context`, `pointer`, `key`, `chunk`, `max`), a
  `stage:` that is not a stage declared before this one, a `stage:` without
  `pointer:`, a `pointer:` beside `context:`, `key:`/`pointer:` beside
  `glob:`, an invalid pointer, a `context:` that is empty or starts with `@`,
  a `chunk` below 1, a `max` that is not a whole number from 1 to 4096, a
  fan-out stage with no stage-level template `prompt`, or `from:` on a stage
  that is neither parallel nor map (`carries "from", which only a parallel or
  map stage can fan out`).
- `minSuccess` that is not a whole number `>= 1` or a `"NN%"` from 1% to 100%,
  a count above a static `agents:` list's length, or `minSuccess` on a stage
  that is neither parallel nor map.
- `votes:` not a whole number of at least 1, `lenses:` not a non-empty list
  of non-empty strings or of a different length than `votes`, `quorum:` below
  1 or above `votes` (placement off a verifier slot is the engine's
  pre-flight refusal, like `reject:`).
- a `type: map` stage without `from:` or `steps:`, a `steps:` that is not a
  non-empty list, a step without a unique `name`, a refused step type or
  step key, a step `when:` that reads its own or a later step or spells both
  `step:` and `stage:`, a task key or body key at map stage level,
  `parallel: true` beside `type: map`, or `steps:` on any other stage
  type.
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
- `preset:` that is not a non-empty string, or `effort:` that is not one of
  `low`, `medium`, `high`, `xhigh`, `max` (whether a well-formed preset name
  exists is the engine's pre-flight refusal, before any dispatch).
- `announce:` that is not a non-empty template string.
- `capture:` is not a boolean — `true`/`false` unquoted, the exact spelling
  law `parallel:` holds; `'yes'` and `1` are refused, not coerced.
  Whether a *well-formed* name is servable is a dispatch-time refusal, not
  a load-time one — the ladder lives in `ModelChoice`, never duplicated.
- `parallel: true` with `agents:` that is not a list.
- any key present but of the wrong type. The check is `array_key_exists()`, not
  `isset()`, so `prompt: ~` is refused as the wrong shape rather than silently
  taking the default: a key the author wrote is a key the author meant.
- **an unknown key** — at stage level, inside a `tasks:` entry, a parallel
  `agents:` entry, a `type: verification` sub-map, a `type: pipeline` step or
  a map step, a key the parser never reads now fails the load naming itself
  (`has unknown key "colour"; supported keys: …`) instead of being silently
  ignored. The supported set is the parsed-key roster itself
  (`WorkflowRegistry::YAML_TASK_KEYS`), so an arm and its refusal are added
  in one place or the roster guard test reddens. Parallel agents spell their
  label `type:`; the task-level `agent:` spelling is refused there, not
  ignored. Placement of a *known* key on the wrong shape (a `prompt:` beside
  a `tasks:` list) stays the dedicated per-shape refusals' job.

Every position number in a diagnostic counts from 1 — the loader's `stage #N`,
`agent #N`, `task #N` and `step #N` (a duplicate name reads `(stages #1 and
#3)`), the engine's `Stage 'x' task #N`, and the linter's `Stage #N` alike —
so the number in an error is the one you count to in the file.

### Interpolation

Four passes, in this order (`WorkflowEngine::interpolateContext()`), after the
item tokens of a fan-out have been lifted out (they are spliced back last, so
item data is never re-scanned):

| Form | Resolves to |
|---|---|
| `{{name.results}}` | one agent's output, by its result name (below) — any stage type |
| `{{name.<pointer>}}` | one leaf inside a schema'd stage's JSON document (below) |
| `{{stage.failed}}` | a `minSuccess` stage's casualties as a JSON list ([`minSuccess`](#minsuccess-tolerates-casualties-in-a-fan-out)); claims the tail only when that stage recorded one |
| `{{stage.votes}}` | a verifier panel's last round as JSON ([panels](#verifier-panels-votes-lenses-and-quorum)); claims the tail only when a panel ran |
| `{{stageName.output}}` | a prior stage's collected output — every stage type writes it: a parallel stage's is every agent's output joined by newlines, a verification stage's the task and verifier output, a map stage's its JSON item document, an accumulate loop's its `{"items",…}` document |
| `{{variable}}` | a key from the run context (`/workflow run <name> key=val …` or `key:=<json>`, the `Workflow` tool's `context`); an array or object renders as compact JSON |

The engine also injects tokens of its own, each only where it means something:

| Token | Where | Resolves to |
|---|---|---|
| `{{prevResult}}` | a `tasks:` entry after the first, a pipeline step, a map step, a verifier | the previous task's or step's output (in a map, of THIS item; `""` for its first step), or the task's output inside a verifier |
| `{{input}}` | a task carrying `input:` | the join ([below](#joining-prior-stages-with-input)) |
| `{{path}}` | a `from: {glob}` fan-out or map | the item's file path |
| `{{item}}`, `{{item.<pointer>}}` | any `from:` fan-out or map | the item (string verbatim, else JSON), or one leaf of it |
| `{{index}}` | any `from:` fan-out or map | the item's 0-based position |
| `{{round}}` | a looped stage | the 1-based round |
| `{{previous}}` | a looped stage, from the second round | the prior round's `<stage>.output` |
| `{{seen}}`, `{{fresh}}` | a loop with `accumulate:` | every dedup key so far / the items the previous round added, as JSON lists |
| `{{lens}}` | a verifier panel vote with `lenses:` | that vote's lens |
| `{{budget.remaining}}` | any prompt or `announce:`, in a run with a token ceiling | the tokens left under it ([run budgets](#configmaxtokens-and-configmaxcost-are-hard-run-budgets)); verbatim without one |

Names must match `[a-zA-Z_][a-zA-Z0-9_]*`. An **unresolved** reference is left
as written, not blanked — so a typo appears verbatim in the prompt rather than
vanishing. So is a context value that is neither text, a number, an array nor
an object (a boolean, `null`).

#### Pointing inside a schema'd result

A stage whose task carries an `outputSchema` records its JSON answer a second
time as a typed row, and the pointer form reads one leaf of that document by
RFC 6901: `{{build.summary}}` reaches a member, `{{build.items/0/id}}` nests
with `/` and indexes arrays, and the leading-`/` from-root spelling
(`{{build./items/0/id}}`) addresses the same document. Escapes decode `~1`
to `/` first and `~0` to `~` second — so `~01` names the key `~1`, not `/`.
Dots carry no structure: `{{build.a.b}}` names one key spelled `a.b`.

Misses stay loud like every other form: an unknown stage, leaf or index, a
`null` or interior object, and any answer a schema never shaped into JSON all
leave the `{{…}}` as written. The tails `results` and `output` are never
pointers — they belong to their own forms — so reaching a document member
actually named `output` takes the from-root spelling `{{build./output}}`.
Pass order is visible in emitted text: the pointer pass runs before the
`{{stageName.output}}` pass (a leaf whose value is `{{c.output}}` resolves)
and after the `{{name.results}}` one (a leaf spelling `{{b.results}}` does
not re-resolve and survives verbatim).

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
| `from:` fan-out item | — | the path (glob), `<stage>_<key>` (data with `key:`), else `<stage>_<n>` |
| verification task | its `name` | the stage's name |
| verification verifier | its `name` | `<stage>_verifier` |
| verifier panel vote | `<verifier name>_<k>` | `<stage>_verifier_<k>`, 1-based |
| map step | inside its item only: the step task's `name` or the step's name (verifier: `<step>_verifier`) | — |
| map stage | — | the stage's name (the JSON item document) |

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

### Joining prior stages with `input:`

A stage's `input:` reads the **prior stages** of the same file. The join is
built just before the stage's prompt is assembled and injected into the
interpolation context only — it never lands in the run's context, so the pause
file never carries it and a resumed run re-derives identical bytes from the
recorded rows.

```yaml
- name: gather
  prompt: "List the failing modules."
- name: check
  prompt: "Re-run the smoke suite."
- name: triage
  input:
    from: [gather, check]
    as: file-list
  prompt: "Triage everything collected below."
```

The joined text is addressed by the fixed handle `input`: `{{input}}` and
`{{input.results}}` both read it, and `as: results` additionally makes it
pointer-traversable — `{{input.gather/summary}}` (the dot names the row, the
rest is an RFC 6901 pointer). A stage actually named `input` is shadowed inside
a joining stage's prompt only; its recorded rows elsewhere are untouched. The
four `as:` shapes:

| `as:` | the rows become | when every source is missing |
|---|---|---|
| `file-list` | rows joined by newlines | the note `[input: no sources joined]` |
| `first` | the first present row, verbatim | the stage **fails**: every source is missing |
| `all` | a labeled `=== name ===` block per present row | the stage **fails** |
| `results` | a JSON document keyed by source name | the stage **fails** |

- **Prior stages only.** A `from:` name must be a stage declared before the
  joining stage: self-references, forward references and unknown names are
  refused at parse, because the registry sees the whole file. That law is a
  YAML-parse guarantee: a workflow composed in PHP through the builder never
  passes the registry's name walk, so its joins rely on the shape gate and
  the skip rules above — a forward or unknown source simply reads as missing.
  A `uses:` stage cannot carry a join — v1 joins stages of the same file, not
  carried workflows.
- A source skipped by an unsatisfied `when:` clause records nothing, so it is
  simply absent: a partial join skips it, an all-skipped `from:` list answers
  per the table. That refusal is deliberately a run-time one — a parse cannot
  know what will be skipped — and lands as an ordinary stage failure.
- On a `capture:`d source the recorded row carries its `[output captured to
  …]` header, so `file-list` reads as a manifest of capture paths and
  previews — what a fan-in triage stage usually wants.
- The join is a task key: on a `tasks:` list it belongs on each task (a
  stage-level `input:` beside `tasks:` is refused), and it rides the same
  single arm verification slots and pipeline steps read.

---

## Running one

```
/workflow list
/workflow run <name> [key=val | key:=json ...]
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
registry. ERRORS: unsupported stage types, unknown or forward `.results` and
`.output` references (every stage type writes `.output`), duplicate result
keys, `reject` or panel keys (`votes`/`lenses`/`quorum`) outside the verifier
slot, a panel whose lens count differs from `votes` or whose quorum is out of
range, non-compiling PCRE, out-of-universe or unregistered tool declarations,
a `uses:` chain that cycles or runs too deep, a `loop:` on a pipeline or
`uses:` stage, `{dry: N}` without `accumulate:`, a `from.stage` that is not a
prior stage, `minSuccess` off a parallel/map stage or above a static agent
count, a `{{X.failed}}` reading a `minSuccess` stage at or after its own
position, a map with no steps, no `from:` or a duplicate step name, a negative
`maxConcurrent`, an unresolved `{$ref}`, a blank `preset:` or one the
session's roster does not know (every caller passes the roster's names when
the plan names a preset), a blank `announce:`, a hand-built `maxTokens` below
1 or a non-positive `maxCost`, and bound violations. WARNINGS: bare
unknown `{{token}}`, an empty prompt, an unnamed stage, a plan with no stages,
two or more agents of a parallel stage that can write the shared checkout
(below), an unsatisfiable
`rejectMinOccurrences`, retries above the warn ceiling, `maxConcurrent: 0`
(no cap), `{dry: N}` with N not below `max`, `stopOnFirstFailure` beside
`minSuccess`, a panel with no `reject:`, `{{lens}}` with no lenses, a
`{{X.failed}}` naming no `minSuccess` stage, and a map step `when:` that
reads neither an earlier step nor an earlier stage, and an `until:
{budgetBelow}` or a `{{budget.remaining}}` in a plan with no
`config.maxTokens`. Engine-injected tokens
(`{{input}}`, `{{prevResult}}`, `{{round}}`, `{{previous}}`, `{{seen}}`,
`{{fresh}}`, `{{item}}`, `{{index}}`, `{{path}}`, `{{lens}}`) are known in
the scopes listed under [Interpolation](#interpolation) and warn outside them.

**The shared-checkout warning** fires only when **two or more agents of one
parallel stage can write AND are not `isolation: worktree`** — a lone writer,
or a writer in its own worktree, does not count:

```
N agents in this parallel stage can write the shared checkout (no worktree isolation); concurrent file edits can clobber each other. Narrow their tools to read-class ones or set isolation: worktree.
```

An agent *can write* when its `tools:` is absent (the session's tools — a
`preset:` is not resolved at lint time), when it declares a Write-class tool
(`Bash`, `Edit`, `Write`, `ApplyPatch`, `NotebookEdit`, …; the name half of
`Bash(git *)` counts), or when it declares a name the tool catalog cannot
classify (an MCP bridge tool or a glob cannot be vouched for). `tools: []` and
Read-, Ask- and NoAsk-class tools (`Read`, `Grep`, `Glob`, `WebFetch`,
`WebSearch`, `Todo`, …) never count, so a read-only review or research panel
lints clean. A `from:` fan-out has one template task, so it never draws the
warning — keep its items disjoint yourself.

**`/workflow run` lints first.** Before anything is dispatched, `/workflow run
<name>` — in the TUI and on the host (`serve`) surface alike — loads the name
through the same tiers `/workflow lint` uses and lints it with the run's own
`key=value` pairs as the context seed, the session's tool universe, the
`uses:` resolver and — when the plan names a `preset:` — the session's preset
names. Errors refuse the run with an error row naming them
(``**Error:** workflow `<name>` was not run: lint found N error(s), M
warning(s) (nothing was dispatched).`` followed by the findings) — no stage
starts and the turn is not held. Warnings never block: they are listed under
the run report's heading. A name that does not load in those tiers skips the
lint, so the engine's own refusal speaks.

`/workflow run <name> key=value …` seeds the run context from the pairs after
the name. Pairs are split on whitespace outside quotes, and a `"…"` or `'…'`
span keeps its spaces — `/workflow run review-loop specfile="docs/my brief.md"`
— with the quotes removed. A pair with no `=`, an empty key, an `@`-prefixed
key or an unterminated quote is refused before anything is dispatched.

`key=value` is always a **string**. `key:=<json>` carries a typed value
instead — the value is decoded as JSON (a list, object, number, boolean or
null), so a `from: {context: files}` fan-out can be driven from the command
line:

```
/workflow run fix-wave files:='["src/A.php","src/B.php"]' spec=docs/brief.md
```

The tokenizer strips quotes, so **single-quote the JSON** to keep its own
double quotes: an unquoted `files:=["a","b"]` reaches the decoder as `[a,b]`
and is refused rather than run on a mangled value. The operator is only `:`
immediately before the pair's first `=` (`a:b=1` stays a plain string pair
keyed `a:b`). Malformed JSON is refused naming the key, joined into the usual
`Malformed run arguments: …` error with nothing dispatched:

```
`files:=` needs a JSON value (e.g. files:='["a","b"]'), got `[a` (Syntax error)
```

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
does not pause into a directory nobody vetted. A `Workflow` tool run keeps its
files in the same directory (the user tier's): the record `<runId>.json`, the
stored plan `<runId>.plan` and the capture directory `<runId>.stages/`
([below](#model-authored-plans-the-workflow-tool)).

A registry-backed run (`/workflow run`, and any `resume()`) refreshes that same
record after **every stage that completes** — not only at pause boundaries. A
`SIGKILL`, a machine that dies, or a dropped container can then lose at most the
in-flight stage: `resume()` replays from the first stage the record does not
count, and skips the prefix it does. The record is withdrawn whenever the run
ends without a standing pause (completed, failed, or cancelled), so an
undisturbed run still leaves no file behind — a budget stop is a pause and
keeps it. (A `Workflow` tool run is the exception: its record is kept at
every terminal.) Honest limit: a stage that was
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
settled stage, a sha256 over its stage type and its pre-interpolation prompt
templates plus each task's model and schema identity, and its `preset` and
`effort` when it sets them (a `uses:` stage hashes
the included name and its `with:` templates instead). It is what the opt-in
[`cache:`](#cache-reuses-a-recorded-stage-result-on-resume-opt-in) door compares
on resume to carry an unchanged stage without re-dispatching it; a stage
without `cache: true` records its hash and is re-run regardless. Records
written before the map existed (or stages a resumed run never settled itself)
read as a cache miss, never as corruption.

### Pause, resume, status

`<id>` is the run ID `/workflow run` prints (`three-1a2b3c4d`) or the workflow
name; both resolve to the same run. A `Workflow` tool run's ID
(`plan-1a2b3c4d-1`, from its result) works here too: `status <runId>` reads
its kept record and stage ledger (`completed`, `failed`, `cancelled` or
`paused`), and `resume <runId>` resumes it from its stored plan and context
under the [longest-unchanged-prefix rule](#model-authored-plans-the-workflow-tool)
— the record is kept, not consumed, so a tool run can be resumed again.

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

Granularity is still one whole stage. A `parallel` or `map` stage that is still
running when an interrupt lands is re-run from scratch on resume (every item of
a map included; a `from:` list is replayed as recorded). The run's context,
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
policed by the grant; a `tools: []` stage is, and every call but
`StructuredOutput` is refused
([`tools:`](#tools--omitted-means-the-sessions-tools--means-none)). A
malformed entry (`Bash(git *`) fails the stage naming why.

## Running from the CLI (headless)

The `workflow` subcommand executes a saved workflow through the engine
directly — no model in the loop, no TUI, no turn machinery:

```
candy-code workflow run <name-or-path> [--param k=v | --param k:=json]... [--format json]
candy-code workflow status <id-or-name> [--format json]
candy-code workflow resume <id-or-name> [--format json]
```

`run` resolves `<name-or-path>` through the same tiers `WorkflowEngine::run()`
uses, seeds the run context with the `--param` pairs, lints the plan, and prints
one terminal verdict per stage. `status` reads the engine's liveness view
(`WorkflowEngine::status()`); `resume` continues a paused run
(`WorkflowEngine::resume()`).

### Exit codes

| Code | Meaning |
| --- | --- |
| 0 | The run completed; every stage's verification passed. |
| 1 | The run failed, or the request was refused (unknown name, unreadable file, a live pause elsewhere, lint errors). |
| 2 | Usage error — bad action, misplaced flag, missing operand. |
| 3 | Stopped for a person: a verification stage answered `ESCALATE:` (its failure carries the engine's escalation marker), or a `SIGINT`/`SIGTERM` produced a cooperative pause. |

Exit 3 is the W-1/D4 row: `escalated: ` in the first failed stage's error, or
a signal pause. On a signal pause the engine writes the pause record first
(the same serializer a cooperative pause uses), and under `--format json` the
verb emits one final document — `status` `paused` plus the `pause_file` path —
before exiting 3. `candy-code workflow resume <id>` picks it up. The TUI
`/workflow` path and the model-mediated tool path keep the historical raw
130/143 signal exits; nothing about them changes here.

### Lint before dispatch

`run` lints before it builds the engine, exactly as `/workflow run` does: the
same tiers (`~/.candy-code/workflows`, then the project's
`.candy-code/workflows`), the `--param` pairs as the context seed, the launch
backend's tool names, `uses:` resolved through that registry, and — when the
plan names a `preset:` — the launch's preset roster (`Bootstrap::agentRoster()`),
so an unknown preset exits 1 with nothing dispatched. A name that does not
load skips the lint and lets the engine's refusal speak.

- **Errors refuse the run with exit 1**, nothing dispatched. Text mode prints
  `workflow run: <name> was not run: lint found N error(s), M warning(s)
  (nothing was dispatched)` behind the verb's usual `candy-code:` prefix,
  then the finding lines, on stderr. JSON
  mode emits the usual document with `result.status` `refused`, a `refusal`
  sentence, `lint: {errors, warnings, findings: ["- error [stage] …", …]}`,
  empty `stages`, zero `totals` and `session_id: null`.
- **Warnings never block.** Text mode prints one `  lint warning: [stage] …`
  line per finding under the heading line; JSON mode adds `result.lint`
  (`{errors: 0, warnings: N, findings: […]}`) — the key is absent on a clean
  lint, so a clean run's document is unchanged.

### `--param` quoting

Repeated `--param` flags are comma-joined at parse time (`ParsedArgs::REPEATABLE_SUBCOMMAND_FLAGS`),
so a value containing a comma must be quoted *inside* the payload —
`--param 'k="a,b"'` — and the verb's splitter honours `"` and `'` spans when
breaking the joined string back into pairs. Keys are validated the same way
the TUI slash command validates them; `@`-prefixed keys are refused because
they collide with the engine-reserved context namespace.

`--param key:=<json>` is the typed spelling, exactly as on `/workflow run`:
the value is decoded as JSON, so a list can feed a `from: {context: key}`
fan-out.

```
candy-code workflow run fix-wave --param files:='["src/A.php","src/B.php"]' --param spec=docs/brief.md
```

Inside a `:=` segment the comma rule changes: a comma nested in `[]` or `{}`
is the JSON's own, not a pair separator, and `\"` inside a JSON string does
not close it. An outer single-quote wrapper that survives the shell is
stripped; double quotes are kept, since they are JSON. Malformed JSON is a
usage error (exit 2) naming the key, with nothing dispatched — behind the
verb's usual `candy-code:` prefix, `` workflow --param: `files:=` needs a JSON
value (e.g. files:='["a","b"]'), got `[a` (Syntax error) ``.

### Machine document

With `--format json` (or the global `--output-format json`) the verb emits one
schema-versioned document: `verb` `workflow`, the `action`, and a `result` of
`{workflow, status, stages: [{name, status, output, error, attempts}], totals:
{tokens, cost, duration_ms}, session_id}`, plus `lint` when the pre-run lint
found anything (above). `session_id` is honestly `null`: this verb mints no
chat session.

### What the verb deliberately does not add

`cache:` keeps its opt-in-only semantics with **no CLI kill-switch** — the
`--no-cache` override is a documented owed seam (NIT-1), not silently widened
here. The verb adds no wall-clock timeout to any path; stage and workflow
budgets ride the existing engine families unchanged.

---

---

## Stage lifecycle events

While a run is live, the engine raises events into the hook chain (see
[`HOOKS.md`](HOOKS.md), *The workflow events*): `StageStarted` before each
dispatched round of a stage, `StageComplete` when a stage settles without
failing, `StageFail` when it settles failed, and `WorkflowComplete` once when
the run reaches a natural terminal. A stage skipped by its `when:` clause
announces only the complete, at round zero; a `loop:` announces one
`StageStarted` per round but settles exactly once; a `map` stage announces per
round and settles once like any stage — there are no per-item or per-step
events; a `cache:` stage carried from a record announces nothing at all; and a
plan the pre-run lint refuses never reaches the engine, so it announces
nothing either. A run that pauses or cancels raises no
terminal — the resumed run announces its own when it finishes. These events are
observe-only: whatever a hook answers, the engine discards, so a hook can watch
a run but cannot steer it. An engine built without a hook chain raises nothing.

**Checkpoint commits.** `autoCommit: edit` (see [`HOOKS.md`](HOOKS.md),
*Auto-commit*) commits every file-changing tool call as it lands, but it knows
nothing about stages. Pairing `StageComplete` with a command hook turns every
settled stage into a commit of the whole working tree — a checkpoint a later
stage's work can be diffed against and rolled back to, including work agents
landed through tools the built-in never watches:

```yaml
hooks:
  StageComplete:
    - name: stage-checkpoint
      command: ./hooks/stage-checkpoint.sh
```

The script reads the stage name out of `CRUSH_TOOL_INPUT` and commits in the
launch's project root, where hooks run:

```sh
#!/bin/sh
stage=$(sed -n 's/.*"stage":"\([^"]*\)".*/\1/p' "$CRUSH_TOOL_INPUT" | head -n1)
git add -A && git commit -qm "workflow checkpoint: ${stage:-stage}"
```

Because the verdict on a stage event is discarded, the commit cannot block the
run: a failing checkpoint — a dirty-tree conflict, a rejected hook of its own —
just leaves the working tree uncommitted while the workflow marches on.

---

## Model-authored plans: the `Workflow` tool

The model can run a workflow of its own: the built-in `Workflow` tool takes the
same YAML as a `.yaml` file, as a string, plus an optional `context` object for
`{{key}}` placeholders, an optional `dryRun` flag, an optional token `budget`
and an optional `resumeFrom` run ID:

```json
{"plan": "stages:\n  - name: survey\n    agent: explorer\n    prompt: List the callers of Foo::bar.\n    tools: [Read, Grep]\n  - name: fix\n    prompt: \"Update every caller: {{survey.output}}\"\n    tools: [Read, Edit, \"Bash(composer test*)\"]\n"}
```

- **The description is the grammar.** The tool's description is generated
  from the loader's own rosters (`WorkflowGrammarGlossary::grammar()`): every
  stage type, stage key, task key and interpolation form, one gloss line
  each, walked in the loader's order — a key the loader learns is printed
  even before its gloss lands, and `WorkflowToolDescriptionDriftTest` holds
  the glossary set-equal to the rosters. It ends by pointing the model at the
  `workflow-authoring` skill for non-trivial plans.
- **Same loader, same checks.** `WorkflowRegistry::fromYamlString()` runs the
  plan through the validation a file gets, so a plan and a file cannot mean two
  different things. The plan's `name` is optional (it defaults to `plan`); a
  syntax error is reported with the parser's message, which a file's error
  withholds because it would quote the file.
- **Linted before anything runs.** After loading, the plan goes through
  `WorkflowLinter` with the call's `context` as the seed, the session's tool
  names and the registry's `uses:` resolver. Errors refuse the call — no
  engine is built, nothing is dispatched — with an error result:
  `Error: the plan was not run: lint found 1 error, 0 warnings (nothing was
  dispatched).` followed by `- error [stage] …` lines. A declared tool the
  session lacks is refused here, by the linter. Warnings never block; they
  ride the result under its headline (`N lint warning(s):` plus the lines).
- **`dryRun: true` lints without running.** It returns `Dry run of workflow
  '<name>' (no agents dispatched): N stages.`, the linter's plan table, the
  findings and a `Lint:` verdict line (`— a real run would be refused.` when
  there are errors); the result is an error exactly when the lint has
  errors. A `dryRun` that is not a boolean is refused (`Error: "dryRun" must
  be true or false.`).
- **`context` takes any JSON value.** Strings and numbers interpolate as
  themselves, arrays and objects render as compact JSON in a `{{key}}`, and a
  list feeds a `from: {context: key}` fan-out. A key starting with `@` is
  refused (`Error: "context.<k>" is not allowed: keys starting with "@" are
  reserved for the workflow engine.`), as is a value JSON cannot carry
  (`Error: "context.<k>" must be a JSON value (string, number, boolean, null,
  array or object).`).
- **One engine per call, bound to the calling turn.** The tool builds its own
  `WorkflowEngine` and pool around an `EngineExecutor` bound to the engine that
  is running the current turn, and calls `WorkflowEngine::runPlan()` (or
  `resumePlan()` for a `resumeFrom` call). It never re-binds the launch's
  `/workflow` engine, whose executor binding is shared. A `preset:` resolves
  against the session's roster, built only when the plan names one.
- **Stages run inside the turn — unless they cannot ask.** Stage agents run
  synchronously in the turn's own process, not in forked workers as
  `/workflow run` stages do: the turn already runs off the TUI, and staying in
  its process is what lets a stage agent's permission questions reach your
  prompt (the approver answers only in the process that built it). The
  exception is every dispatch that goes through the pool's batch door: the
  agents of a `parallel: true` stage (`from:` fan-outs included), the votes
  of a verifier panel, and **every step agent inside a `type: map` stage**
  (`dispatchOne()` takes the batch door while a map runs). An agent there
  whose effective tools **could not put a permission question** runs in a
  forked child, so those agents — and a map's item chains — run concurrently
  up to the stage width. Sequential stages, `tasks:` lists, pipeline steps
  and a verification stage's task and single verifier run inline; so does a
  map step agent that may ask, whose chain then runs to its end before the
  next item is admitted (see
  [`type: map`](#type-map-streams-each-item-through-its-steps)).
  - *Could not ask* means: for every tool actually on the agent's request
    (after `Task`/`Workflow` are withheld), no judging gate — the session's
    and the agent's own — would answer `Ask` under its mode, rules and grants
    (`PermissionGate::wouldAsk()`, see
    [`PERMISSIONS.md`](PERMISSIONS.md#could-a-declaration-ask-wouldask)). So a
    read-only agent forks, and so does a `Bash` the session allows outright
    by a name-only `Allow Bash` rule, or any agent under `bypass-permissions`
    or `dont-ask`. A `tools:` entry that scopes a tool (`Bash(vendor/bin/phpunit
    *)`) with no bare entry for it is judged by that scope, so an identical
    `Allow` rule clears it; there is no glob subsumption between patterns.
    `StructuredOutput` is always free; tools that relay asks or delegate to
    the engine always count as asking. An agent with no `tools:` inherits the
    session's tools, which nearly always include something that asks, so it
    stays inline.
  - Each agent is judged on its **own** grant: every agent of a parallel,
    `from:` or vote wave is offered exactly the tools its own `tools:` (or
    its preset's) declares, and the grant hook holds its calls to that same
    declaration. A `[Read]` agent beside a `[Read, Bash]` sibling therefore
    forks while the shell agent stays inline — `parallel: 1 forked, 1 inline
    (agent "shell" may ask: Bash)`. (Before this was fixed, every agent of a
    wave rode the first task's grant.)
  - **Hooks that ask on their own are not seen** by that judgement — a user
    `PreToolUse` script answering `ask`, or the built-in protect-files hook's
    question about a policy-file write. In a forked agent such a question
    **fails closed**: that one call is refused, the agent reads the refusal
    and carries on. Nothing runs unasked.
  - Liveness and teardown: inline agents beat the turn's heartbeat
    themselves; while forked ones run, the pool beats it from its idle poll,
    so a long plan is not mistaken for a hung turn. A cancelled turn's tree
    kill takes the forked agents with it, each one also stops at its next
    step once its parent is gone, and an unwinding pool kills and reaps its
    children — the pool's single fork site is reused, and
    `php tools/check-child-lifetimes.php` accounts for it. A forked agent
    keeps the turn's provider, tools, hooks, gate, root and model pin;
    `outputSchema` and `isolation` apply as inline. Session-scoped
    tool state and the sibling spend ledger are not carried back from a
    child: each forked agent's spend-cap check starts from the turn's
    baseline.
- **What a stage agent may do.** This session's tool loop, hooks, permission
  gate and root, limited to its stage's `tools:` (argument-scoped entries
  included), the session's tools when `tools:` is absent, or none at all
  under `tools: []`. `Task` and `Workflow`
  are withheld from it, so a plan cannot recurse, and it does not see the
  conversation that called the tool.
- **Every run is resumable, and returns a `runId`.** The ID is
  `plan-<first 8 hex of sha256(plan YAML)>-<n>`: n counts up from 1 to the
  first number no record or stored plan under that digest has claimed, and
  the claim is an exclusive create of the stored-plan file, so two concurrent
  runs of one plan never share an ID. Under the user workflows directory's
  `.running/` (the directory `/workflow` reads; files 0600) the run keeps:
  - `<runId>.json` — the same crash-safe per-stage record a `/workflow run`
    writes (stamped with the writer's pid), plus `planRun: true` and a
    whole-declaration hash per settled stage. Unlike a saved workflow's, it
    is **kept at every terminal**, stamped `completed`, `failed`,
    `cancelled` or `paused`;
  - `<runId>.plan` — the plan YAML and the seed context, as JSON;
  - `<runId>.stages/<n>-<stage>.txt` — the capture files (below).

  Only the newest **20** plan runs are kept (`WorkflowEngine::PLAN_RUN_RETENTION`,
  by the stored plan's mtime, which every start and resume refreshes): when
  a new plan run starts, older runs' record, plan and captures are deleted,
  except a run whose writer is still live in another process.
  `/workflow status <runId>` and `/workflow resume <runId>` (and the headless
  verb's `status`/`resume`) reach these records too. No SIGINT/SIGTERM
  handler is installed — cancelling the turn stops the run and its agents —
  but the per-stage record means a killed run still leaves its settled
  prefix to resume.
- **`resumeFrom: <runId>` carries the longest unchanged prefix.** Call again
  with the plan as it stands now (`plan` is still required) and the ID. The
  engine walks the plan's stages against the record's settled rows
  (successes and `when:` skips, up to the first failure): stage *i* is
  **carried** — its row, output and context reused, nothing dispatched, no
  spend — while it has row *i*'s name and the same **whole-declaration**
  hash (every task key, `when`, `loop`, `from`, `capture`, …; only
  `announce` and `cache` are left out). The first stage that changed, never
  settled or failed, and **every stage after it**, run live — even unchanged
  later ones, since their inputs are the changed stage's outputs. That
  identity is deliberately stricter than the `cache:` door's prompt hash. An
  omitted `context` reuses the stored one; a `context` that differs from it
  (compared as canonical JSON, key order ignored) carries **nothing** — the
  hash cannot see what a `{{key}}` renders to — and the run starts over from
  stage 1 under the same ID. The run keeps its ID, its record and stored
  plan are rewritten, re-run stages re-expand their `from:` fan-outs, and
  the result's totals (and any `budget`) count only **this call's** live
  spend. Refusals, nothing dispatched, come back as `Error: the plan was not
  resumed: <reason>`: an ID that does not read `plan-…` (`'<id>' is not a
  Workflow-tool run ID (they read plan-<8 hex>-<n>)`), a saved workflow's
  record (resume it with `/workflow resume`), no stored plan under that ID,
  or a writer still live in another process; a non-string `resumeFrom` is
  `Error: "resumeFrom" must be a runId string an earlier Workflow call
  returned.`
- **`budget: N` caps this call's tokens.** A whole number `>= 1` (otherwise
  `Error: "budget" must be a whole number of tokens, at least 1.`), passed
  as the run's `budgetTokens`: the effective ceiling is the tighter of it and
  the plan's `config.maxTokens`. A dispatch at or past it does not start and
  the run **pauses** with its record kept — call again with `resumeFrom` and
  a larger `budget` ([run budgets](#configmaxtokens-and-configmaxcost-are-hard-run-budgets)).
- **The permission gate sees the tool call itself.** `Workflow` is
  write-capable, so it asks under `default` and is denied under `plan` and
  `dont-ask`, before any stage runs.

**The result.** A one-line headline — `Workflow '<name>' <status>: X of N
stages succeeded, T tokens[ of a B-token budget], $C.`, then ` K stages
carried from the record at no cost.` when a resume carried any, and ` Run ID
plan-…-n (resumeFrom).` — then, for a paused run, `The run is paused; call
again with resumeFrom: <runId> (and a larger budget when a budget stopped
it).`, the lint-warning block if any, one `Stage '<name>' <status>: <error>`
line per failed stage (the reason stays readable unescaped), `K later
stage(s) did not run.` when the run stopped early, and a fenced
```` ```json ```` document:

```json
{"status": "completed", "runId": "plan-1a2b3c4d-1", "carried": 0,
 "budget": {"tokens": 500000, "spent": 5120},
 "announcements": [],
 "stages": [{"name": "survey", "status": "completed", "error": null,
             "attempts": 1, "tokens": 5120, "cost": 0.0123,
             "capture": "…/.running/plan-1a2b3c4d-1.stages/0-survey.txt",
             "data": {"callers": ["src/A.php"]}, "dispatch": "…"}]}
```

- `runId` is the record's ID — pass it back as `resumeFrom`. `carried` is how
  many leading stages a resume carried (each such row also has `carried:
  true`); `budget` is `{tokens, spent}` against the effective token ceiling
  (the `budget` input or `config.maxTokens`, whichever is tighter), or `null`
  with neither; `announcements` lists the run's
  [announcements](#announce-and-the-runs-announcements), which also stream
  onto the call's live activity line while it runs.

- `attempts` is the most tries any one agent of the stage took (0 for a stage
  refused before dispatch); `tokens` and `cost` sum the stage's agents.
- `data` appears when **every** task the stage declares (a plain stage, a
  `tasks:` list, a parallel stage) carries an `outputSchema` and the output
  decodes to a JSON object or array (one whole-output ```` ```json ```` fence
  is accepted). It **replaces** `output` and is never clipped — a clipped
  document is a corrupt one. Pipeline and verification stages report
  `output`.
- `output` is the prose, its middle elided past 8192 bytes; the marker names
  where the elided bytes are — `[… N bytes elided; the full output is in
  <capture path> …]`, or, when the capture file could not be written, `[… N
  bytes elided; its capture file could not be written; re-run with capture:
  true on this stage to keep the full output …]`.
- `capture` is the file holding the stage's **full** output. Every stage with
  output gets one, at the engine's path rule
  (`<runId>.stages/<index>-<stage>.txt`, index 0-based), written by the tool
  **after** the run — a report-side capture; carried stages are re-captured
  from their recorded rows, and a `capture: true` stage keeps the file the
  engine already wrote. Capture is deliberately not switched on in the
  engine for every stage: engine capture (`capture: true`) replaces
  `{{stage.output}}` for later stages with the file path and a 2 KiB
  preview, which would silently break a downstream `when: {stage: x, equals:
  PASS}` and truncate downstream prompts. The report-side copy leaves what
  later stages read byte-identical.
- `dispatch` (stages whose agents went through the batch door — parallel,
  panel and map stages) says how its agents ran:
  `parallel: forked (3 ask-free agents)`, `parallel: inline (agent "shell"
  may ask: Bash; 2 more inline)`, `parallel: inline (agent "a0" may ask: an
  Ask rule "Read(*.env)" covers some Read calls)`, `parallel: 2 forked, 1
  inline (…)`, or `parallel: inline (agent "x": pcntl_fork() is unavailable
  in this process)` / `…: pcntl_fork() failed`.
A run that did not complete — paused included — is returned as an error
result.

---

## Three limits to know before you design around this

**1. A stage's tasks run one after another, never at once.** A regular stage
may hold several tasks — a list in PHP, `->stage('work', [Tasks::agent('architect')->name('plan'), Tasks::agent('coder')])`,
or a `tasks:` list in YAML, whose entries take the one-task stage's keys
(every key of `WorkflowRegistry::YAML_TASK_KEYS`: `agent`, `name`, `prompt`,
`tools`, `retries`, `outputSchema`, `isolation`, `model`, `resource`,
`lockWaitSeconds`, `timeout`, `reject`, `rejectMinOccurrences`, `stallTimeout`,
`restarts`, `input`, `votes`, `lenses`, `quorum`, `preset`, `effort`);
`capture`, `cache`, `when`, `loop`, `minSuccess` and `announce` are the
stage's own keys, not task keys, so they live on the stage map even when the
stage lists `tasks:`:

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
`parallel: true` with `agents:`; for the same short chain run per item of a
list, use [`type: map`](#type-map-streams-each-item-through-its-steps).

**2. `agent:` / `type:` is a LABEL; `preset:` is the preset reference.** A
stage saying only `agent: reviewer` produces

```php
new Agent(name: 'reviewer', description: <the interpolated prompt>, prompt: '', …)
```

— the name is carried for display, and the stage's own `prompt:` is the entire
instruction. Your `~/.candy-code/agents/reviewer.md` preset and a stage saying
`agent: reviewer` are unrelated objects that happen to share a string. To run
a task as that preset — its body as the system prompt, its tools, model,
effort, isolation, denylist, step cap and (stricter-only) permission mode
under the task's own keys — write `preset: reviewer`
([`preset:`](#preset-runs-a-task-as-an-agent-preset-effort-sets-its-reasoning-effort)).

**3. `reject:` only takes effect on a verification stage's verifier.** This
claim used to read "pipeline and verification stages are PHP-only" — that
became false-to-disk when `d39afd83c` (C1) gave `.yaml` the same reach as the
builder: a `type:` key spells `verification` (a `task:` sub-map and a
`verifier:` sub-map) and `pipeline` (a `stages:` list), beside the plain task,
`tasks:` list and `parallel:` shapes. The verdict arms `timeout`, `reject` and
`rejectMinOccurrences` are in `WorkflowRegistry::YAML_TASK_KEYS` and parse on
every task surface on purpose; placement is the engine's policy, not the
parser's — the reject-misuse pre-flight refuses a `reject:` sitting anywhere
but the verifier's own task, and a lone `rejectMinOccurrences:` (no `reject:`
to count) is refused at parse. So the verdict-gated stage behind
`reject()` ([below](#reject-fails-a-stage-on-the-verifier-verdict)) lives in
`.yaml` as a `type: verification` stage whose `verifier:` task carries
`reject:` — the same shape `WorkflowBuilder::withVerification()` builds. The
panel keys `votes`, `lenses` and `quorum` follow the same law: they parse on
every task surface, and the same pre-flight refuses them anywhere but a
verifier slot (a verification stage's, or a map verification step's).
Unknown keys are never silently ignored: every parsed key answers to one
fail-closed roster (`ae04d808c`).

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
`resource()`, `lockWaitSeconds()`, `stallTimeout()`, `restarts()`, `input()`,
`votes()`, `lenses()`, `quorum()`, `preset()` and `effort()`. Every one has a YAML spelling — the keys of
`WorkflowRegistry::YAML_TASK_KEYS`, `agent:`/`type:` for `agent()` and
`reject:` plus `rejectMinOccurrences:` for `reject()`'s pattern and
`atLeast` — so a YAML-declared task reaches the same DTO fields a PHP one
does. The stage-level flags are `WorkflowBuilder`'s: `capture` and `cache`
are named arguments of `stage()`/`parallel()`/`pipeline()`/`withVerification()`/`uses()`/`map()`,
and `when()`/`loop()`/`minSuccess()`/`announce()` attach to the stage added last
(`loop()` takes `accumulate` beside `max` and `until`). `parallel()` takes
`from:` as an array in any of the three source shapes, validated by the same
`WorkflowBuilder::fromSource()` law the loader uses; `map()` builds a map stage
from `mapStep()`/`mapVerification()` entries; `maxConcurrent()`,
`maxAgents()`, `maxTokens()` and `maxCost()` set the rails. Only `schemas:` has no builder spelling — share a
PHP variable instead. `WorkflowEngine` substitutes defaults for whatever a task
leaves unset when it builds the `SubAgent`:
`$task->timeout ?? config.timeout` seconds, `$task->retries ?? 0`,
`$task->isolation ?? Isolation::None`, `$task->stallTimeout ?? 0` and
`$task->restarts ?? 0` — the last two carry their own YAML spellings
(`stallTimeout:`, `restarts:`) and reach the forked worker as written: zero is
"inert", not a bound. A task without its own `timeout()` is
therefore bounded only by its stage's budget (`config.timeout`, default of 3600
seconds); see [`timeout` is a per-stage wall-clock budget](#timeout-is-a-per-stage-wall-clock-budget).

`retries()` reaches `SubAgent::$maxRetries`, and the pool re-runs the agent as
[`retries` re-runs a failed agent](#retries-re-runs-a-failed-agent) describes.
`outputSchema()` — spelled `outputSchema:` in YAML — reaches
`SubAgent::$outputSchema` decoded, and `EngineExecutor` enforces it through the
per-run `StructuredOutput` tool call, as
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
`rejectMinOccurrences:` on a verification stage's `verifier:` map (the keys
parse on every task surface; the engine's pre-flight refuses them anywhere
else) — carries the verdict pattern onto the DTO, after which the semantics
below are identical whatever spelling authored it. `input()` — spelled
`input:` — is the join described in
[Joining prior stages with `input:`](#joining-prior-stages-with-input).

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

This is what makes a review→fix→re-review gate an engine feature rather than an
operator habit: the fixer task runs once, the verifier judges, a rejecting
verdict consumes a retry by asking a fresh verifier about the same task result
(the fixer is not re-run), and a verdict that still rejects stops the run for
`/workflow resume` — which re-runs the whole stage, task included, after you
act on the reason. Stage tokens and cost already fold every attempt into the
run totals. To re-run the *fixer* until a reviewer approves inside one run, put
both in a plain stage's `tasks:` list under
[`loop:`](#loop-re-runs-a-stage-until-a-clause-holds) and gate the result with a
verification stage after it — `examples/workflows/review-loop.yaml` is that
shape.

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
  retrying something a human needs to answer would waste the budget. On a
  [verifier panel](#verifier-panels-votes-lenses-and-quorum) the marker in
  ANY vote outranks even a met quorum.
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
