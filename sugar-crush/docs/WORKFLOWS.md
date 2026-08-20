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

  - name: fix
    parallel: true
    agents:                      # a LIST of maps
      - name: style-fixer
        type: coder              # default: coder
        prompt: Apply the style findings from {{lint.output}} …
        tools: [Read, Edit, Grep]
```

Defaults are `Workflow`'s own: `maxConcurrent: 5`, `timeout: 3600`,
`stopOnFirstFailure: false`.

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
| `{{stageName.output}}` | a prior stage's collected output |
| `{{agentName.results}}` | one parallel agent's output |
| `{{variable}}` | a key from the context passed to `run()` |

Names must match `[a-zA-Z_][a-zA-Z0-9_]*`. An **unresolved** reference is left
as written, not blanked — so a typo appears verbatim in the prompt rather than
vanishing.

---

## Running one

```
/workflow list
/workflow run <name>
/workflow pause <id>
/workflow resume <id>
/workflow status <id>
```

Pause files live under `<workflowsPath>/.running/*.json` — anchored to the
registry's directory rather than to `~`, so a registry pointed somewhere trusted
does not pause into a directory nobody vetted.

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
`Bash` *declaration* is allowed even under `plan`, since what makes a `Bash`
call a write there is a redirection in its arguments.

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
        ->timeout(600)
        ->retries(1))
    ->withVerification(
        'ship',
        Tasks::agent('devops')->prompt('Deploy.')->tools(['Bash']),
        Tasks::agent('tester')->prompt('Confirm the deploy is healthy.')->tools(['Bash']),
    )
    ->build();
```

`TaskBuilder` carries `agent()`, `prompt()`, `tools()`, `timeout()`,
`retries()`, `isolation()` and `name()`. `timeout`, `retries` and `isolation`
have **no YAML spelling**. A YAML-declared task therefore carries none of them,
and `WorkflowEngine` substitutes its own literals when it builds the `SubAgent`:
`$task->timeout ?? 300` seconds, `$task->retries ?? 0`,
`$task->isolation ?? Isolation::None`. Note the domain: 300 is the engine's
per-task fallback, which is a different number from `config.timeout`'s per-stage
default of 3600.

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
