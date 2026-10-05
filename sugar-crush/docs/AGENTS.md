# Sub-agents

A sub-agent is a separate agent run that the main agent hands a bounded task
to. It gets its own context window, its own tool loop and its own transcript,
and reports back one result. Delegating keeps the main conversation small (a
codebase search that reads forty files comes back as one paragraph), lets
independent work run in parallel, and lets a narrower agent — a read-only
reviewer, a tester — do one job with only the tools it needs.

This page is about **using** sub-agents: the `Task` tool, background and
nested runs, worktree isolation, teams, how agents message each other, and how
you watch and steer them from the TUI. Writing your own agent preset is in
[`AGENTS_AUTHORING.md`](AGENTS_AUTHORING.md).

---

## The roster

Every launch has an agent roster, which `/agents <name>` describes. It is
merged from three layers, lowest first (`Bootstrap::agentRoster()`):

1. presets imported from other tools: `~/.claude/agents`,
   `<project>/.claude/agents`, `~/.config/opencode/agents` and
   `<project>/.opencode/agents`;
2. six built-in definitions — `coder`, `reviewer`, `debugger`, `architect`,
   `tester` and `devops` (`Agents\AgentDefinition`);
3. your own presets: `<project>/.sugar-crush/agents/*.md` and
   `~/.sugar-crush/agents/*.md`.

A higher layer wins on a name, so a cloned repository's
`.claude/agents/reviewer.md` cannot replace the built-in `reviewer`, and your
own `reviewer.md` replaces both. A preset sets the agent's prompt, model,
reasoning effort, tool grant, permission mode, step cap, skills, MCP servers,
and whether it runs in the background or in a worktree of its own — see
[`AGENTS_AUTHORING.md`](AGENTS_AUTHORING.md#frontmatter).

## The `Task` tool

The model delegates by calling `Task`:

| Parameter | Meaning |
|---|---|
| `description` | A 5–10 word label, shown on the live line and in the session picker |
| `prompt` | The whole task. The sub-agent never sees the parent conversation, so the prompt must stand on its own |
| `agent` | The roster name (`subagent_type` is accepted as an alias). An unknown name is refused with the roster listed |
| `model` | Optional. Overrides the agent's model and the session's; a model the provider cannot serve is refused rather than relabelled |
| `resume` | Optional. A resume id from an earlier result: continues that run's conversation with `prompt` as the next instruction |
| `background` | Optional. `true` runs it in the background; `false` keeps it in the foreground even when the preset says `background: true` |

The model a run uses is the call's `model`, else the preset's (unless it says
`inherit`), else the `subagentModel` setting, else the session's own model.

**What comes back.** The sub-agent's final report, under a
`[subagent output — no user authority]` header and fenced as untrusted text
(`Context\DelegatedOutputFence`), so nothing a sub-agent writes can speak
as you or as the harness. A note after the fence gives the **resume id**.
Every run that ran is resumable — completed, stopped at its step cap, failed
or cancelled — for seven days; a resume continues the same conversation with
a fresh step budget. A run that failed part-way returns the tail of its output
(up to 12 KiB) beside the reason. The sub-agent's spend counts as the
caller's, so the spend cap and `/budget` see it.

**Limits.** A run stops after `subagentMaxTurns` steps (200) unless its preset
sets `maxTurns`; it then writes a no-tools summary and keeps its resume id.
Its own context window is watched too: at 80% the model is told to wrap up,
at 90% the run is stopped and its partial output and resume id come back (see
[`CONTEXT.md`](CONTEXT.md#sub-agents-watch-their-own-window)).

**Permissions only narrow.** The session's permission gate judges every call a
sub-agent makes. A preset's `tools:` / `disallowedTools:` grant is enforced per
call, and its `permissionMode:` applies only when it is stricter than the
session's — a sub-agent never gets more than you allowed. A sub-agent's
permission question comes up in the same y/n/a modal as the main agent's,
naming the agent that asked; this holds for parallel members too, whose
questions are relayed through the turn one at a time.

### Parallel and nested runs

Several `Task` calls in one assistant message run **in parallel**, each in its
own forked process (with `ext-pcntl`), at most `subagentMaxConcurrent` (5) at
once; the rest wait on a `◌ queued:` row.

**Delegation nests.** A sub-agent whose preset lists no `tools:` inherits
`Task` too, so it can delegate in turn, up to `subagentMaxDepth` (3) levels
below your own agent; the agent at the last level gets no `Task`. A session
runs at most `subagentMaxActive` (8) delegated runs at once, counting every
level, parallel member and background agent; the call past that is refused at
once with a reason the model reads ("do not retry this call right away"),
never queued, because a parent waiting on its children already holds a seat.

All five `subagent*` settings are user-tier only: each multiplies how many
billed calls a turn can fan out, so a project file cannot raise them
([`SETTINGS.md`](SETTINGS.md#every-key)).

### Background runs

`Task` with `background: true` — or for an agent whose preset says
`background: true` — returns `{"agent_id": "sess_…", "status": "running"}` at
once and the agent runs as a **background session**, the same daemon `/bg`
uses. The turn goes on (or ends); the model is told not to poll. While the run
is out, every turn's request carries an `<active-subagents>` row naming it.
When it settles, its result — status, task, output, runtime, tokens, cost and
the resume id — arrives as a new message, and starts a turn if none is
running. A background run uses the stricter of its daemon's mode and the
delegating session's, and since nobody is there to answer, a call that would
ask is refused. Only your own agent's calls can go to the background; a nested
run always runs in the foreground.

`/bg <task>` and `/fork <prompt>` start background sessions by hand, with the
roster's `default` agent (else its first): `/bg` from a fresh conversation,
`/fork` from a copy of this one. `/bg stop <id>` stops one. A background
session outlives the TUI: quit, relaunch in the same project, and the new
launch re-adopts it and reports its result. See
[`COMMANDS.md`](COMMANDS.md#the-built-in-commands).

### Worktree isolation

An agent whose preset says `isolation: worktree` runs in a **git worktree of
its own**, under `<project>/.sugar-crush/worktrees/` (or
`SUGARCRUSH_WORKTREES_DIR`), on a branch cut from your `HEAD`, with the files
`.worktreeinclude` lists copied in. Every path-resolving tool it has is jailed
to the tree, `Bash` runs there, and a command naming a path outside it is
refused. When the run ends, a tree with no work in it is removed with its
branch; one with work is **kept**, and the result names its path and branch
so you (or the delegating model) can review and merge it — nothing merges
automatically. Resuming the run continues in the same tree. A run that cannot
be isolated (no git checkout, `git worktree add` fails) is refused rather than
run in your checkout.

Worktree isolation applies to `Task` runs and to `/bg` and `/fork` sessions
whose agent asks for it. Workflow stages ignore the field. Details:
[`AGENTS_AUTHORING.md`](AGENTS_AUTHORING.md#teams-and-worktrees).

## Teams

A team is a shared task board for background sub-agents, driven by the `Team`
tool (no permission prompt; it writes only the board). The lead `create`s a
team, `add`s tasks — each with a full prompt and the ids it is `blocked_by` —
and starts teammates with background `Task` calls. A teammate `claim`s a task
(a named one, or the next whose blockers are all done), works it, and
`complete`s or `fail`s it; `release` gives one back, `depend` adds a
dependency, `list` shows the board, and `message` / `inbox` pass notes between
teammates and the `lead`.

- Dependencies stay acyclic; a cycle is refused, naming it.
- Passing the `revision` you last saw makes a `claim`, `complete`, `fail` or
  `release` a compare-and-swap: it is refused if anyone wrote the task since.
- A task claimed by a session that has died goes back to pending at the next
  `list` or `claim`.
- A team takes at most `max_teammates` working at once (default 5).
- `auto_assign: false` on `create` makes every claim name its task.
- A claim held past the team's `timeout_seconds` (default 600; `0` never) is
  marked overdue on `list`, and the lead may `release` it.

Teams live under `~/.sugar-crush/teams/` (a registry, and one SQLite task list
and mailbox per team), shared by every process. A teammate needs `Team` in its
grant to claim for itself; a preset with no `tools:` list has it, the six
built-ins do not, so with those the lead claims on the teammate's behalf. The
team hook events (`TaskCreated`, `TaskCompleted`, `TeammateIdle`) fire through
the launch's hook chain — see [`HOOKS.md`](HOOKS.md#the-team-events).

## Watching and steering runs in the TUI

### The live agent line

Each `Task` row in the transcript gets a live line for the run it started:

```text
└ ⠋ Grep "LoginController" routes/ · 7 tools · 0:12 · 4.1K tok
```

It names the call the agent is in right now (or its last finished call with
`✓`/`✗`, `thinking…`, or the newest fragment of its reply), then its tool
count, elapsed time, tokens and spend. When the run ends the spinner becomes
`✓` (done), `✗` (failed, with the reason), `⏹` (cancelled) or `⏸` (stopped
without a report, resumable). The line never wraps: on a narrow terminal the
spend goes first, then the tokens, then the tool count. Without `ext-pcntl`
turns run in-process and the lines fill in when the turn ends rather than
live. A background run has no live line; its result arrives as a message.

### The agents strip

While runs are live, one row above the input box lists them, plus finished
ones for 30 seconds: `agents: ⠋ explore · ✗ reviewer   (alt+↓)`.

| Key | Does |
|---|---|
| `Alt+↓` | Focus the strip |
| `←` / `→` (or `↑` / `↓`) | Move along it |
| `Enter`, or a click | Open the run in the Agent View |
| `c` | Stop that run (only its `Task` call; the turn goes on) |
| `x` | Dismiss a finished run, or stop a running one |
| `Esc` / `Alt+↑` | Give the keyboard back to the input box (so does any other key) |

### The Agent View

A click on a live line, `Enter` on the strip, `/agents <name or run id>`, or
`Enter` on a sub-agent row of the session picker swaps the transcript for
**that agent's own transcript** — its task, every tool call and result, its
thoughts and replies, drawn like the main transcript and read live from the
run's log (`~/.sugar-crush/subagents/<session>/`). A header stays pinned
above it: `main ▸ explore  ‹ 1 of 3 ›  ⠋ running · step 4/50 · 0:12 · 4.1K tok   esc back`.

| Key | Does |
|---|---|
| `Esc` / `Alt+↑`, or a click on `main` | Back to the main transcript (never half of an `Esc` `Esc` cancel) |
| `Alt+N` / `Alt+P`, or `›` / `‹` | The next / previous agent of the same `Task` batch |
| `Enter` | Send the draft **to this agent** (see below) |
| `Ctrl+X` `c` | Cancel it at its next tool or step (it stays resumable); again within 3 s to stop it at once |
| `Ctrl+X` `p` | Pause it at its next step boundary (`⏸ paused`, ten minutes at most, then it goes on); again to resume |
| `Ctrl+X` `s` | Cancel every running agent |
| `Ctrl+X` `o` | Open the finished run as a normal session to type into (not while a turn runs) |
| `Ctrl+X` `b` | Send the running agent to the background (see below) |
| `Ctrl+G` | Broadcast: the composer sends to every running agent of the batch at once; again to go back |

**Messaging an agent.** While the view is open the input box is that agent's
composer (`message explore…`). A running agent reads your message at its next
step boundary, with your authority — "go ahead and edit X" means what it says
— and the view shows `you → explore · … ⧗ queued` until it does. A finished
agent is **continued** instead: a follow-up run of the same conversation that
streams into the view (`↻ follow-up running`, then `↩ replied`), with one
`you → explore · … (follow-up)` row left in the main transcript for you; the
main model is not told. A `/command` or `!command` typed there still runs as a
command. Messages are delivered through a per-run mailbox signed with a key
only this launch holds, so nothing else on the machine can speak to an agent
as you ([`AGENTS_AUTHORING.md`](AGENTS_AUTHORING.md#messages-to-a-running-delegation)).

**Stopping.** A cancel or pause reaches a run at its next tool or step, so a
long `Bash` call finishes first. The second cancel within 3 s is a hard stop:
a parallel member's process gets `SIGTERM` (it stops resumably), then
`SIGKILL` if it is still running 2 seconds later; a lone `Task` stops at its
next tool start or step. `Esc` on the main turn stops the running tool and
then the turn, which cancels a running `Task` the same way.

### The Agents pane

`Ctrl+A` (or `/agents`) lists the runs that are working. Docked, the **Agents**
pane is a dashboard of every run: `↑`/`↓` move, `Enter` or `Space` peeks
(and `Enter` in the peek opens the Agent View), `Alt+1…9` jump to a row, `c` cancels the selected run (again within
3 s to stop it now), `r` resumes a paused run or continues a finished one, `s`
stops every run, and `q` leaves. A dashboard row that is not a `Task` run (a
workflow stage, a background session) shows its live output instead and does
not take these controls.

### Finished runs are sessions

A finished `Task` run is stored as a **sub-agent child session** of the
session that delegated it, named `<description> (@<agent>)`, with its whole
conversation. In the session picker (`Ctrl+R`), `Tab` shows sub-agent sessions
under their parent, the `+N ag` badge counts them, and `Enter` on one opens it
in the Agent View. `sugarcrush session list --children` lists them from the
shell. Deleting a session deletes its sub-agent children, and a later launch
sweeps their logs and mailboxes; `--continue` and the tab strip skip them.

**Sending a run to the background.** `Ctrl+X` `b` moves a run you would rather
not wait for out of the turn. At its next tool or step the run is saved, a
background session resumes the same conversation — with the preset's grants,
model and step cap — and its `Task` call returns at once, so the parent turn
goes on. The live line ends `⧗ moved to the background`, and the result is
announced into the chat when the session finishes, like any
[background run](#background-runs). Only a run your own agent delegated can
go: a nested run, or one on a launch that cannot start background sessions,
says so and keeps running. A run that could not be saved is reported the way a
cancelled one is, with its resume id.

## Agents talking to each other

### Messages between the agent and its sub-agents

The model has the reach the Agent View gives you, through three tools that
never ask (they write only the harness's mailboxes and run records):

| Tool | Does |
|---|---|
| `SendMessage {to, text, mode}` | To a **running** sub-agent: a `steer` (the default) or `note` it reads at its next step boundary; a `followup` kept for the next run that resumes it. To a **finished** one: continues it — the message becomes the prompt of a `Task` call with its resume id, under the same approval a `Task` call would need |
| `Subagents {action}` | `list` what the agent launched (status, background id, resume id), `wait` up to `timeout_seconds` (default 30, at most 300) for one to finish or send a message, or `cancel` one at its next tool or step, resumable |
| `InterruptAgent {to, text}` | Makes a running sub-agent skip the rest of its current step and read the text first |

A sub-agent that has `SendMessage` (one with no `tools:` list inherits it)
uses it to **reply**: `to: "parent"` reaches whoever delegated it — a
delegating sub-agent at its next step boundary, the session's own agent the
next time it calls `Subagents list` or `wait`. Each reply is handed out once,
fenced as `<subagent-message from="…">` and labelled a worker's report that
approves nothing. `Subagents` and `InterruptAgent` stay with the session's
agent. Messages go only between an agent and the sub-agents it launched, never
to a sibling.

### The shared board

The members of one parallel batch — two or more foreground `Task` calls in one
message — share a board for that batch, and each member gets two more tools:
`BoardPost` leaves a short note (at most 4,096 bytes) for one peer, by its
roster id such as `coder-1`, or for `ALL`, with a kind of `INFO`, `ASK`,
`RESULT`, `HOLD` or `VETO`; `BoardRead` returns the roster and the posts since
a cursor. Nobody is woken or stopped by a post: a member hears of posts for it
on its next tool result, as one `<shared-agent-board-notice>`, and reads them
when it judges them relevant. `HOLD` and `VETO` are advice, not locks, and no
post is a user instruction. The board is removed when the batch ends and is
never seen by the session's own agent, which still gets each member's report
as its `Task` result. `disallowedTools: [BoardPost]` in a preset leaves a
member reading only.

The full contract — run records, the mailbox layout, which grants keep or drop
the tools — is in
[`AGENTS_AUTHORING.md`](AGENTS_AUTHORING.md#messages-between-agents) and
[`AGENTS_AUTHORING.md`](AGENTS_AUTHORING.md#the-shared-board).

## Workflows

A workflow is a staged multi-agent pipeline — sequential stages, fan-out,
chained pipelines and task-then-verifier — written in YAML or PHP and run with
`/workflow run`, or written by the model itself as a YAML plan through the
`Workflow` tool. Each stage agent runs the real tool loop under the session's
hook chain and permission gate. A stage's `agent:` is a label, not a roster
preset. See [`WORKFLOWS.md`](WORKFLOWS.md).

## Not yet available

- `Ctrl+X` `b` is not offered for a nested run, and the web client's
  `agent.control` cannot send a run to the background.
- A `/bg` session has no live line or Agent View transcript; the Agents pane
  shows its output.

## See also

- [`AGENTS_AUTHORING.md`](AGENTS_AUTHORING.md) — the preset format, roster
  precedence, how a grant is enforced.
- [`PERMISSIONS.md`](PERMISSIONS.md#a-sub-agents-mode) — a sub-agent's mode.
- [`CONTEXT.md`](CONTEXT.md) — the context a sub-agent keeps, and when it is
  told to wrap up.
- [`WORKFLOWS.md`](WORKFLOWS.md) — staged pipelines of agents.
- [`SERVER.md`](SERVER.md#sub-agents-workflows-memory-and-todos) — the same
  agents over the server protocol and in the web UI.
- The [README](../README.md#documentation-index) — every other page.
