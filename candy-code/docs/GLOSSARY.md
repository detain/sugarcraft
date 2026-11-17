# Glossary

The vocabulary this documentation assumes, defined in one or two lines each.
Terms are grouped by what they describe. Each entry names the page or source
file where you can read more, so the definition is a starting point rather than
the whole story.

## Terminal and rendering

| Term | Meaning |
|---|---|
| **ANSI** | The family of escape-sequence conventions (ECMA-48 and its successors) terminals use for cursor movement, colors, and modes like the alternate screen. Everything a TUI "draws" is ANSI text. |
| **chafa** | A terminal image renderer that converts pictures to character art. As a render mode it is the last-resort rung: works in any terminal, lowest fidelity ([MEDIA.md](MEDIA.md)). |
| **kitty protocol** | The Kitty terminal's graphics protocol (and its keyboard-extension counterpart): images addressed as numbered, animatable objects at full color; the keyboard side lets Ctrl+Enter arrive as itself. Used when detected, ignored otherwise. |
| **OSC 52** | An escape sequence (Operating System Command 52) that asks the terminal to copy text into the *system* clipboard, even over ssh. How selecting with the mouse in CandyCode reaches your paste buffer. |
| **PTY** | Pseudo-TTY — a kernel terminal pair: a master the parent program reads/writes and a slave that behaves like a real terminal for a child process. How `Bash interactive:true`, `PtyRun`, and tmux-style embedding work. |
| **render mode** | How images and video are drawn in the TUI: `auto` asks the terminal what it supports, and `/rendermode <mode>` switches it mid-session ([COMMANDS.md](COMMANDS.md), [MEDIA.md](MEDIA.md)). |
| **SGR** | Select Graphic Rendition — the `ESC[ … m` sequences that set text color, weight, and underline. "Colored output" is SGR output. |
| **sixel** | A bitmap terminal graphics protocol (DEC) that paints pixels directly as escaped color runs. Middle rung of the render ladder; broader terminal support than kitty. |
| **TUI** | Terminal User Interface — an interactive full-screen program drawn with text (panes, menus, keybindings), as opposed to a line-by-line CLI. CandyCode's main mode is a TUI; `-p` is the non-TUI one-shot path. |

## Protocols and integrations

| Term | Meaning |
|---|---|
| **ACP** | Agent Client Protocol — a JSON-RPC standard for editors and IDEs to drive an AI coding agent as a subprocess. CandyCode speaks it via `candy-code acp` ([SERVER.md](SERVER.md#editors-candy-code-acp)). |
| **bundle** | A Claude-format plugin directory, installed with `candy-code bundles install`. Its hooks run only through a channel you list in `trustedBundleChannels`, and its `.mcp.json` stays declined. `candy-code bundles list` shows what each bundle carries ([PLUGINS.md](PLUGINS.md)). |
| **CandyCode** | This app; formerly SugarCrush / sugar-crush. <!-- @rename-keep --> The `candy-code` binary, the `SugarCraft\Code\` namespace, and the `~/.candy-code/` state directory. <!-- @rename-keep --> |
| **LSP** | Language Server Protocol — the standard JSON-RPC bridge to a code editor's intelligence (diagnostics, symbols). CandyCode is an LSP *client*, feeding model answers with real IDE-grade context. |
| **MCP** | Model Context Protocol — a standard for plugging external tool servers into an AI agent over stdio or HTTP. CandyCode is an MCP *client*: [MCP.md](MCP.md). |
| **OTLP** | OpenTelemetry Protocol. CandyCode exports its event stream to an OTLP endpoint only when `OTEL_EXPORTER_OTLP_ENDPOINT` is set; with it unset, export stays off ([ENVIRONMENT.md](ENVIRONMENT.md)). |
| **sdapi** | The Stable Diffusion web UI API family — the HTTP interfaces of A1111/SD.Next-style servers (`/sdapi/v1/txt2img` and friends). The media tools speak it, plus the SGLang-Diffusion variant ([MEDIA.md](MEDIA.md)). |
| **SSRF** | Server-Side Request Forgery — tricking a program into making requests to an address the attacker cannot reach directly (e.g. cloud metadata, localhost services). `WebFetch` resolves each host and refuses any address in a blocked range (`src/Tools/BuiltIn/WebFetch.php`, class doc). |

## The agent and its tools

| Term | Meaning |
|---|---|
| **agent preset** | A Markdown file with frontmatter that defines a sub-agent, including the permission mode it runs in. Project presets live in `<root>/.candy-code/agents/`, user presets in `~/.candy-code/agents/` ([AGENTS_AUTHORING.md](AGENTS_AUTHORING.md)). |
| **compaction** | Shortening the chat history so the prompt fits the context budget. `/compact` triggers it by hand ([CONTEXT.md](CONTEXT.md)). |
| **context budget** | The token window a prompt must fit into. Long sessions drop or summarize older turns to stay inside it ([CONTEXT.md](CONTEXT.md)). |
| **goal** | `/goal <condition>`: the agent keeps working, and a judge checks the condition after each turn, until it holds ([COMMANDS.md](COMMANDS.md), `src/Goal/GoalJudge.php`). |
| **grind** | `/grind <condition>`: the same loop as a goal, with a much larger budget of follow-up rounds ([COMMANDS.md](COMMANDS.md)). |
| **hook** | A shell command or built-in that runs at a lifecycle event. Its exit code decides the outcome: `0` allow, `1` deny, `2` block, `3` ask, `4` modify (`src/Hooks/ScriptHook.php:94-109`, [HOOKS.md](HOOKS.md)). |
| **memory** | Notes the agent saves and recalls across sessions, through the `Memory` tool and `/memory` ([MEMORY.md](MEMORY.md)). |
| **repo map** | A symbol map of the project, built from the code. The `RepoMap` tool reads it, and the prompt can carry it (`src/RepoMap/`, `src/Tools/BuiltIn/RepoMapTool.php`). |
| **skill** | A folder of instructions the model loads on demand. You mention one with `$name` ([SKILLS.md](SKILLS.md)). |
| **sub-agent** | A child session that the `Task` tool starts to work on part of a job, in parallel with the parent ([AGENTS.md](AGENTS.md)). |
| **team** | A group of sub-agents that share a task board, stored in SQLite. The `Team` tool drives it ([AGENTS.md](AGENTS.md), `candy-code doctor`). |
| **workflow** | A multi-stage pipeline, written in YAML or PHP, run by `/workflow` ([WORKFLOWS.md](WORKFLOWS.md)). |
| **worktree** | A git worktree of its own, created for a session with `--worktree` or for a sub-agent with worktree isolation (`candy-code --help`, [AGENTS.md](AGENTS.md)). |

## Permissions and safety

| Term | Meaning |
|---|---|
| **bang command (`!`)** | A shell command you type in the TUI, prefixed with `!`. Its output goes into the transcript, and it asks no permission, because you typed it (`src/Commands/BangShell.php`). |
| **circuit breaker** | The refusal that runs before every rule and every mode: a recursive delete of `/` or `~` is denied unconditionally ([PERMISSIONS.md](PERMISSIONS.md)). |
| **permission gate** | The check every tool call passes through, in this order: the circuit breaker, the rules, then the mode's own evaluator (`src/Permissions/PermissionGate.php`). |
| **permission mode** | One of six settings for how much the agent may do without asking, from `bypass-permissions` (runs everything) to `dont-ask` (denies everything but reads). See [PERMISSIONS.md](PERMISSIONS.md#the-six-modes). |
| **permission rule** | An allow, deny or ask pattern on a tool and its argument. The first matching rule wins (`src/Permissions/PermissionRule.php`). |
| **plan mode** | A permission mode that denies writes, so the agent can read and plan. The agent leaves it by asking, through `PlanExit`, and you approve or refuse the plan in the permission modal (`src/Tools/BuiltIn/PlanExitTool.php`). |
| **sandbox (`bashSandbox`)** | An OS jail around the Bash tool: Bubblewrap on Linux, Seatbelt on macOS. It is off by default (`src/Tools/Sandbox/SandboxFactory.php`, `src/Config/Settings/Definitions/ToolSettings.php:98`). |
| **trusted project** | A project root you have approved. The `trustedProject*` keys decide whether its hooks, MCP servers, commands and settings run ([SECURITY.md](SECURITY.md#trust-boundaries)). |

## Sessions, state and settings

| Term | Meaning |
|---|---|
| **branch** | A new session of kind `branch`, made by `/branch` from the current session ([COMMANDS.md](COMMANDS.md), `src/Session/SessionKind.php`). |
| **checkpoint** | A git snapshot of the workspace, taken for a turn, so `/undo`, `/rewind` and `/diff` can compare or restore it (`src/Workspace/WorkspaceCheckpointer.php`). |
| **session** | A stored conversation with its transcript, kept in `~/.candy-code/session.db` (`src/Session/SessionStore.php`). |
| **session kind** | What a stored session is: `main`, `branch`, `subagent` or `background` (`src/Session/SessionKind.php:23-26`). |
| **settings layer** | One of five sources a setting can come from. Your own files outrank the project's, and the machine's file outranks yours ([SETTINGS.md](SETTINGS.md), `src/Config/LayeredSettings.php:20-24`). |
| **provider** | The backend that answers the model's calls, chosen by a type key such as `openai` or `anthropic` ([ENVIRONMENT.md](ENVIRONMENT.md), `src/Providers/ProviderInterface.php`). |
| **echo provider** | The offline stand-in provider used when no real one is configured. It quotes your prompt back and makes no network calls (`src/Providers/EchoProvider.php`). |
| **usage ledger** | The durable record of reported spend. `/usage` folds it by day, model and source, and `/budget` caps the session's reported spend ([COMMANDS.md](COMMANDS.md)). |

## Running and automating

| Term | Meaning |
|---|---|
| **attach** | `candy-code attach`: run the TUI on a session that a running `serve` is hosting ([SERVER.md](SERVER.md)). |
| **doctor** | `candy-code doctor`: checks the installation and the selected provider, and exits `1` if a check fails ([TROUBLESHOOTING.md](TROUBLESHOOTING.md)). |
| **exit codes** | `0` for an answer, `1` when the backend ran and failed, `2` for a usage or pre-flight error (`src/Cli/NonInteractive.php:108-187`). |
| **gc** | `candy-code gc`: inspects session storage and reclaims space. Run with no action, it lists the actions it offers (`candy-code --help`). |
| **headless mode** | `-p` or `run`: one prompt, one answer, then exit. An unattended run refuses every call that would ask (`src/Cli/NonInteractive.php`, [FLEET-DRIVING.md](FLEET-DRIVING.md)). |
| **JSON envelope** | The one-document output of `-p --output-format json`. It carries `schema: 1` and sits on the last line of the output (`src/Cli/NonInteractive.php:84`). |
| **schedule** | A recurring prompt in `schedules.json`, fired on a cadence ([SCHEDULES.md](SCHEDULES.md)). |
| **serve** | `candy-code serve`: a WebSocket server that hosts sessions for a browser or another client. It binds `127.0.0.1` unless you say otherwise ([SERVER.md](SERVER.md)). |

## Identifier conventions in code comments

Reading `src/` and `tests/`, you will meet short audit-style labels —
`E707`, `ISS-037`, `SEC-3`, `TUI-4a`, `round-81`. They are **internal
working identifiers** from the hardening and comparison programs: each one
named a specific finding, and the comment carrying it explains why the code
looks the way it does ("the brake the spend cap cannot be", "refuses while
it stands"). Two rules govern them:

- **Comments and changelogs carry them; user-facing prose does not.** The
  docs in this directory state the current behavior without citing the
  ticket that produced it. If you find a bare ID token in a docs page, it
  is drift — strip it and keep the sentence.
- **They are not resolvable outside the repo.** There is no issue tracker
  behind them; the ledger that defined them lives under `docs/plans/` in
  the monorepo. A code comment naming `E707` is pointing at history, not
  at a dependency. Treat the label as decoration and the sentence around
  it as the contract.

## See also

- [`README.md`](README.md) — the index of every page in this directory.
- The [project README](../README.md) — quick start and the key/command tables.
