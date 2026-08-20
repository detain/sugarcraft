# Authoring an Agent Preset

An agent preset is a markdown file with YAML frontmatter that names a
delegation target and tunes it. Presets are discovered at launch, merged into a
roster, and shown by `/agents`.

This page documents what the frontmatter *does* on this checkout, which is
narrower than what it declares. `AgentPreset` carries sixteen fields; the path
that puts a preset onto the roster reads six of them. That is stated per field
rather than implied, because a preset whose `permissionMode: bypass-permissions`
is silently dropped and a preset whose `permissionMode` is honoured are very
different objects to reason about.

---

## Where a preset goes

Two native tiers, project first (`Bootstrap::agentPresetTiers()`):

| Tier | Directory | Trust anchor |
|---|---|---|
| project | `<root>/.sugar-crush/agents/` | must resolve strictly inside `<root>` |
| user | `~/.sugar-crush/agents/` | must resolve strictly inside `$HOME` |

A file's stem is its preset name: `reviewer.md` → `reviewer`.

Two foreign conventions are also imported, badged with a `SkillSource`
(`src/Agents/ForeignAgentPresetRegistry.php`): `.claude/agents` and
`.opencode/agents`, each in a project and a user flavour. An opencode preset's
per-tool `allow`/`ask`/`deny` rules are collapsed into tool lists on import, and
anything ambiguous is recorded on `warnings()`.

#### Foreign agent presets do NOT resolve the way foreign skills do

Two axes, and **both** point the opposite way from the skills side. This is not a
doc simplification; `ForeignAgentPresetRegistry`'s own doc-block (lines 136-159)
spells it out and calls one of the two "not merely cosmetic".

| Axis | Foreign **agent presets** | Foreign **skills** |
|---|---|---|
| project vs user | **project wins** — `scan()` is ordered user-then-project, last-write-wins | **user wins** — `ForeignSkillDiscovery::tiers()` is ordered project-then-user, last-write-wins |
| Claude vs opencode | **Claude wins** — `discover()` is `$claude + $this->scanOpencode(…)`, and `+` keeps the left operand | **opencode wins** — `SkillManager::loadAll()` registers Claude then opencode into a last-write-wins registry |

The first row is **measured**, not inferred from the ordering. One name, `dup`,
written into all four of `$HOME/.claude/agents/dup.md`,
`<root>/.claude/agents/dup.md`, `$HOME/.claude/skills/dup/SKILL.md` and
`<root>/.claude/skills/dup/SKILL.md`, then
`ForeignAgentPresetRegistry::discoverClaude()` and
`ForeignSkillDiscovery::discoverClaude()` called on the same root:

```
agent  'dup' -> PROJECT-TIER
skill  'dup' -> USER-TIER
```

Same convention, same filename, opposite winner.

The Claude/opencode row is arbitrary in both directions and the source says so:
neither pair has a principled winner, so what is guaranteed is determinism, not a
rule.

**The project/user row is not arbitrary, and it is the one to be careful about.**
A cloned repository's `.claude/agents/reviewer.md` outranks your own
`~/.claude/agents/reviewer.md`. `ForeignSkillDiscovery` deliberately does the
reverse and states the argument — foreign content arrives with any repository you
clone, and letting it displace a name you rely on is the "cloned content silently
redefines the user's setup" shape — and the agents docblock concedes that the same
argument applies here *with a stronger conclusion*, since an imported preset
carries a sub-agent's whole prompt rather than a description. It was **recorded
rather than reversed** because flipping it is a behaviour change with its own
pinned tests, not part of wiring the discovery up.

What limits the blast radius is the roster layering below: a foreign import
cannot displace a built-in or a native preset, whichever tool or tier it came
from. So the exposure is a foreign *name you do not otherwise define* — and that
is exactly the case to check before running a strange repository.

Note that the **native** tiers do not diverge: native agent presets and native
skills both give the project tier precedence over the user tier. The asymmetry is
foreign-vs-foreign, not agents-vs-skills.

### Roster precedence

`Bootstrap::agentRoster()` merges three layers, lowest first:

```
foreign imports  <  the six built-in definitions  <  native presets
```

So a cloned `.claude/agents/reviewer.md` **cannot** re-point `reviewer` — the
built-in wins over it, and your own `.sugar-crush/agents/reviewer.md` wins over
both. Additive is the only safe direction for a new discovery source.

The six built-in definitions (`src/Agents/AgentDefinition.php`) are `coder`,
`reviewer`, `debugger`, `architect`, `tester` and `devops`. All are registered
**inactive**: on `Agent`, active means *currently working*, and a roster
registered active would paint an agent strip claiming six agents were busy on a
session where nothing has been delegated.

### Both tiers are anchored, and one working layout stopped working

`~/.sugar-crush/agents -> /opt/team-agents` — a link out of `$HOME` — is
refused. A link to `~/.claude/agents` is inside `$HOME` and is unaffected, as is
every roster that is a real directory. The reason is in
`Bootstrap::agentPresetTiers()`: the previous discriminator asked "did a
repository choose this content", which the filesystem cannot answer (a
tarball-delivered dotfiles tree and a hand-authored one are byte-identical), and
it defended one launch shape out of four. The question was replaced with one
that is answerable — *is this directory inside the home this process
established as the user's?*

A refused directory is recorded and reported once at launch through
`Bootstrap::reportProjectTierRefusals()`, not silently dropped.

---

## Frontmatter

```yaml
---
name: reviewer                  # optional; defaults to the filename stem
description: Reviews a diff for correctness and style.
tools: [Read, Grep, Glob, Bash]
disallowedTools: [Write, Edit]
model: inherit                  # or a concrete model id
permissionMode: plan
maxTurns: 12
skills: [php-best-practices]
mcpServers: [git]
memory: project                 # user | project | local
background: false
effort: high                    # low | medium | high | xhigh | max
isolation: worktree             # worktree | none
color: "#ffb86c"
initialPrompt: |                # optional; the body is used when absent
  You are a reviewer…
---

The markdown body is the preset's prompt when no `initialPrompt:` is declared.
```

A file with no frontmatter block is refused
(`AgentPresetRegistry::parsePresetFile()`).

**The body is the prompt.** That is where Claude Code and opencode both put a
subagent's prompt, so a `reviewer.md` written to either convention used to
register with an empty prompt — the agent arrived carrying nothing but its
environment block. An explicit `initialPrompt:` wins over the body: a file
carrying both is asking for the declared one.

### Which fields reach the roster

`Bootstrap::agentRoster()` maps each preset through `Agent::fromPreset()`, which
reads **six** fields and nothing else:

| Reaches the roster | Dropped on this path |
|---|---|
| `name` | `permissionMode` |
| `description` | `maxTurns` |
| `initialPrompt` (or the body) | `mcpServers` |
| `model` (`inherit`/empty → the launch's model) | `memory` |
| `tools` | `background` |
| `skills` | `effort`, `isolation`, `color`, `disallowedTools`, `source` |

This is the same for native and imported presets — the wiring neither widens
nor narrows it. It bounds *this* path only: `AgentPreset` still carries every
field, so a future consumer reading presets directly inherits them.

`permissionMode` is the one worth naming out loud. A preset can declare
`bypass-permissions`; nothing on the roster path can act on it. The launch's
permission mode is decided by `SUGARCRUSH_PERMISSION_MODE` or the
`permissionMode` key in `~/.sugar-crush/config.json` — see
[`PERMISSIONS.md`](PERMISSIONS.md).

`AgentPreset::$source` has no reader either: `Agent` carries no source field, so
an imported row is not visually distinguishable from a native one yet.

---

## What you can actually do with a preset today

Be precise about this, because "agent preset" reads like "the model can spawn
one":

- **There is no `Task` or `Agent` tool.** `Bootstrap::tools()` ships eleven
  built-in tools and none of them delegates. The model cannot spawn a
  sub-agent.
- **`/agents` is inspect-only.** `AgentsCommand::execute()` lists the agents
  currently *working* (normally none) and, with a name, shows one agent's
  details. It does not start anything.
- **`WorkflowEngine` does not read presets at all.** Grep it for `AgentPreset`
  and you get nothing. A stage's `agent: reviewer` becomes
  `new Agent(name: 'reviewer', description: <the interpolated prompt>, prompt: '')`
  — the name is a *label*, and the stage's own `prompt:` is the entire
  instruction. A preset named `reviewer` and a workflow stage saying
  `agent: reviewer` are unrelated objects that happen to share a string. See
  [`WORKFLOWS.md`](WORKFLOWS.md).
- **`/bg` and `/fork`** run a task in a background session
  (`src/Sessions/BackgroundSessionRunner.php`), again without consulting the
  preset roster.

So a preset's practical effect today is: it appears in the roster, `/agent
<name>` describes it, and it carries a prompt ready for the executor paths that
already exist in `src/Agents/` (`AgentWorkerPool`, `ProcessExecutor`,
`SubAgent`) but that no chat command routes to.

That is a seam with a finished payload, not an accident, and it is written down
here rather than marketed as delegation.

---

## Teams and worktrees

`src/Agents/` also holds `TeamManager`, `Team`, `Teammate`, `TeamConfig`,
`Mailbox`, `TeamMessage`, `WorktreeManager`, `WorktreeConfig` and
`PathJail`/`PathJailConfig`. `~/.sugar-crush/teams` exists on disk in a
launched install. `SUGARCRUSH_WORKTREES_DIR` re-points the worktree base path
(default `.sugar-crush/worktrees/`) — see [`ENVIRONMENT.md`](ENVIRONMENT.md).

An `isolation: worktree` preset field parses into `Isolation::Worktree`, and
`SubAgent` accepts an `Isolation`, but the roster path drops the field (table
above), so setting it in a preset has no effect on anything a chat command
reaches today.

## See also

- [`SKILLS.md`](SKILLS.md) — the sibling format; a preset's `skills:` names
  those.
- [`PERMISSIONS.md`](PERMISSIONS.md) — where the launch's mode actually comes
  from.
- [`WORKFLOWS.md`](WORKFLOWS.md) — the one surface that does dispatch
  sub-agents.
