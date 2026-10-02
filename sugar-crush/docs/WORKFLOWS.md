# Workflows

A workflow is a named sequence of stages, each dispatching a sub-agent with a
prompt and a tool list. Workflows are the **only** surface in SugarCrush that
dispatches sub-agents from a chat command; `/agents` inspects and does not spawn.

Two formats, two tiers, and an important asymmetry between them: a `.yaml`
workflow is data, a `.php` one is code, and the tier a file lives in decides
which it may be.

---

## Where a workflow goes

| Tier | Directory | Extensions honoured |
|---|---|---|
| project | `<root>/.sugar-crush/workflows/` | `.yaml` **only** |
| user | `~/.sugar-crush/workflows/` | `.yaml` and `.php` |

The project tier is `.yaml`-only by construction (`WorkflowRegistry`'s
constructor takes a project *YAML* path), because a `.php` workflow is reached
by `require`. A repository cannot add code through this door.

`WorkflowRegistry::load()` consults the project tier **first**, the same
precedence a checked-in agent preset gets: what `deploy` means is a property of
the checkout you are sitting in. That crosses extensions — a cloned `deploy.yaml`
shadows your own `deploy.php`, deliberately, and it is pinned by a test. The
alternative (letting `.php` win by extension) makes what a name means depend on
which spelling each tier happens to use, and hands a stale
`~/.sugar-crush/workflows/deploy.php` the power to shadow the curated
`deploy.yaml` a repository ships. What the project tier still cannot do is *add
code*: the substitution replaces code with data and never the reverse.

Both directories are anchored — the project one to the checkout, the user one to
`$HOME` — and a refused directory is reported eagerly at launch by
`Bootstrap::workflowEngine()` rather than left for the first `/workflow list` to
notice. Otherwise the refusal is invisible in every direction you look: the
not-found message stops naming the directory, `projectWorkflowsPath()` still
reports it, and the listing simply has fewer names in it.

### A `.php` workflow that does not parse takes the TUI with it

`load()`'s validation covers YAML only, and the gap is not fixable there. A
user-tier `.php` workflow is reached by `require`, so a syntax error in it is a
**compile fatal** — uncatchable, which means `Chat`'s `catch (\Throwable)` around
`/workflow run` cannot keep the session alive through one. The YAML path, by
contrast, reports every malformed shape as a `WorkflowLoadException` that becomes
one transcript line.

---

## The YAML schema

`examples/workflows/lint-then-fix.yaml` in this repo is a working reference.

```yaml
name: lint-then-fix              # required
description: >                   # optional
  Review the change, fan out fixers, then verify.

config:                          # optional
  maxConcurrent: 3               # positive int; bounds a parallel stage
  timeout: 900                   # positive int; per-stage seconds

stages:                          # a LIST, never a map
  - name: lint                   # required, and unique across the file
    agent: reviewer              # default: coder
    prompt: >
      Review the diff at {{scope}} …
    tools: [Read, Grep, Glob, Bash]
    retries: 1                   # whole number >= 0; default 0

  - name: fix
    parallel: true
    agents:                      # a LIST of maps
      - name: style-fixer
        type: coder              # default: coder
        prompt: Apply the style findings from {{lint.output}} …
        tools: [Read, Edit, Grep]
        retries: 0               # per agent in a parallel stage
```

Defaults are `Workflow`'s own: `maxConcurrent: 5`, `timeout: 3600`,
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
  included: an agent still waiting for one of the `maxConcurrent` slots when the
  budget runs out never starts.
- A PHP task's own `timeout()` (below) bounds that one agent from its dispatch;
  the stage's budget still applies, and whichever expires first wins.

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
- `config:` not a map, or `maxConcurrent`/`timeout` not a positive integer.
- `retries:` not a whole number of at least 0 (`retries: -1`, `retries: lots`).
- `parallel: true` with `agents:` that is not a list.
- any key present but of the wrong type. The check is `array_key_exists()`, not
  `isset()`, so `prompt: ~` is refused as the wrong shape rather than silently
  taking the default: a key the author wrote is a key the author meant.

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
```

**2026-09-10 (E652 → E663, the E649 seam fully closed): what `/workflow run`
reports for a sub-agent changed under you, and the change is on purpose.**
As of E663 the engine's worker pool is fed the same launch-derived provider
spec the chat sub-agent path gets (`Bootstrap::workflowEngine()` builds the
pool through `agentPoolConfig()`); the "engine still fails" gap that stood for
hours between the two is gone.
*WHAT SAID:* before round 61 a stage whose forked sub-agent had no usable
provider could still report **Completed** — the worker script fabricated a
plausible text answer. *WHAT TRUE NOW:* a forked worker is handed the launch's
serializable provider spec (the same selected-provider config the hosted chat
runs on, plus the model override); with no spec derivable it **refuses**, and
the sub-agent surfaces as **FAILED** naming the absence — a run that used to
quietly "finish" now stops with a reason. A configured launch is unaffected
except that its sub-agents now genuinely consult the model in the child.
*WHY EARNS PLACE:* `/workflow status` is an operator's audit trail; "Completed"
has to mean "a model saw this prompt", and pre-E652 it provably could not.

**2026-09-28: a stage now does the agents' work.** *WHAT SAID:* the E663 worker
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
duration. *WHY THE E663 RULE STILL HOLDS:* a launch with no provider keeps the
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

### Pause, resume, status

`<id>` is the run ID `/workflow run` prints (`three-1a2b3c4d`) or the workflow
name; both resolve to the same run.

- **`pause` on a live run** writes the pause file at once and asks the run to
  stop. The stage in flight finishes, and the next stage is not started. The
  run's own report then says **paused**, with the `/workflow resume` line to
  continue it. Two edge cases. If the in-flight stage fails, the run reports
  **failed** and the pause still stands. If it was the last stage, the run
  completed and the pause file is withdrawn. While the run's turn holds the
  prompt, slash commands are refused, so a live pause is typed after
  `Esc Esc` releases the turn (that does not stop the run).
- **`pause` on a finished run** records it. For a **failed** run this is the
  recovery path: pause, fix the cause, resume, and the resume re-runs the
  stage that failed. Pausing a completed run is allowed too. Resuming it runs
  nothing and reports completed.
- **Only stages that succeeded count.** `stagesCompleted` and the recorded
  stage results are the successful prefix of the run, never the failed stage.
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
  requested), then the pause file's status, then a finished run's final status
  (`completed` / `failed`) for runs this session performed.

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

---

## Three limits to know before you design around this

**1. A stage runs its first task only.** `WorkflowEngine::executeStage()`:

```php
// For now, execute only the first task (sequential within a stage is not yet implemented)
$task = $tasks[0];
```

A regular YAML stage builds exactly one task, so this is invisible from YAML. It
bites a PHP workflow that puts several tasks in one stage.

**2. `agent:` / `type:` is a LABEL, not a preset reference.** `WorkflowEngine`
contains no reference to `AgentPreset` or `AgentDefinition` at all. A stage
saying `agent: reviewer` produces

```php
new Agent(name: 'reviewer', description: <the interpolated prompt>, prompt: '', …)
```

— the name is carried for display, and the stage's own `prompt:` is the entire
instruction. Your `~/.sugar-crush/agents/reviewer.md` preset and a stage saying
`agent: reviewer` are unrelated objects that happen to share a string. If you
want a preset's prompt in a stage, paste it into the stage's `prompt:`.

**3. `pipeline` and verification stages are PHP-only.** `WorkflowBuilder` offers
`pipeline()` and `withVerification()`, and the engine implements
`executePipelineStage()` and `executeVerificationStage()` — but
`parseYamlStage()` recognises exactly two stage shapes, regular and
`parallel: true`. There is no YAML spelling for either. They are reachable from
a user-tier `.php` workflow and from an embedder, not from a `.yaml` file.

---

## The PHP form

A `.php` workflow returns a `Workflow`. Build it with `WorkflowBuilder` and
`TaskBuilder` (aliased `Tasks::agent()`):

```php
<?php
use SugarCraft\Crush\Workflows\{WorkflowBuilder, Tasks};

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
`retries()`, `isolation()` and `name()`. `timeout` and `isolation` have **no
YAML spelling**, so a YAML-declared task never carries them; `retries` is the
`retries:` key above. `WorkflowEngine` substitutes defaults for whatever a task
leaves unset when it builds the `SubAgent`:
`$task->timeout ?? config.timeout` seconds, `$task->retries ?? 0`,
`$task->isolation ?? Isolation::None`. A task without its own `timeout()` is
therefore bounded only by its stage's budget (`config.timeout`, default of 3600
seconds); see [`timeout` is a per-stage wall-clock budget](#timeout-is-a-per-stage-wall-clock-budget).

`retries()` reaches `SubAgent::$maxRetries`, and the pool re-runs the agent as
[`retries` re-runs a failed agent](#retries-re-runs-a-failed-agent) describes.

`Workflow` itself is immutable; `withStatus()` returns a new instance.

## Loading one directly

```php
$registry = new WorkflowRegistry(__DIR__ . '/examples/workflows');
$workflow = $registry->loadYaml('lint-then-fix');
(new WorkflowEngine($registry, $pool))->run('lint-then-fix', ['scope' => 'src/Chat.php']);
```

A registry constructed with no arguments defaults its user path to
`~/.sugar-crush/workflows/` and is **not** ownership-checked: `expandPath()`
resolves `~` through `HomeDirectory::path()`, whose fallback is
`sys_get_temp_dir()`, so a process with no resolvable `HOME` anchors
`/tmp/.sugar-crush/workflows` to its own parent. Nothing in `src/` constructs one
that way — `Bootstrap` passes an absolute path derived from a trusted
resolution — but an embedder should pass one explicitly.

## See also

- [`AGENTS_AUTHORING.md`](AGENTS_AUTHORING.md) — why the preset roster and a
  stage's `agent:` do not meet.
- [`PERMISSIONS.md`](PERMISSIONS.md) — the declaration check.
