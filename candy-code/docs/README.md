# candy-code documentation

Index of every page in this directory. Read the [project README](../README.md)
first for the quick start; come here when you know what question you are
asking.

| Page | What it answers | Read it when |
|---|---|---|
| [TUTORIAL.md](TUTORIAL.md) | A first session, from an offline first run to a committed change | You are new, or want one end-to-end walk-through |
| [FAQ.md](FAQ.md) | Short answers to the common questions on providers, permissions, sessions and serving | A question comes up that the reference pages answer at length |
| [GLOSSARY.md](GLOSSARY.md) | What TUI, MCP, OSC 52, sixel, PTY, SGR, permission modes, checkpoints, sessions and the rest mean | Any page's vocabulary stops you |
| [SECURITY.md](SECURITY.md) | The threat model, the trust boundaries, where each secret is stored, how to report a vulnerability, and where each surface's security model lives | You found a security issue or are assessing the trust model |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Dev modes, the sharded suite, the drift guards, docs cadence | You are changing this package's code or docs |
| [ADDING_A_PROVIDER.md](ADDING_A_PROVIDER.md) | The provider contract: capabilities, carrier fields, registration, behavior laws | You are wiring a new model API |
| [COMMANDS.md](COMMANDS.md) | Every slash command, argument-for-argument | You want to know what you can type |
| [SETTINGS.md](SETTINGS.md) | Every config key, its tier and its risk | You are editing `config.json` |
| [KEYBINDINGS.md](KEYBINDINGS.md) | Every key the TUI answers, by the part of the screen that owns it, and how to remap a key with the `keybindings` setting | You want to know what a key does, or change one |
| [THEMES.md](THEMES.md) | The built-in themes, the `theme` setting, custom theme files (their JSON schema and a worked example), and how `adaptive` picks dark or light | You want a different palette, or a custom one |
| [ENVIRONMENT.md](ENVIRONMENT.md) | Every environment variable the app reads | You are configuring a shell, container or CI job |
| [PROVIDERS.md](PROVIDERS.md) | Each provider type: its required keys, the environment variables it reads, its defaults, and the placeholder syntax for config values | You are choosing a provider, or a provider block refuses to start |
| [PERMISSIONS.md](PERMISSIONS.md) | Permission modes, rules, gate chain | The agent is refused (or should be) |
| [MCP.md](MCP.md) | Model Context Protocol servers: declaring, trusting, auth | You are wiring external tools |
| [LSP.md](LSP.md) | Installing and configuring language servers, the `lsp` setting, auto-detect, and the `Lsp` tool | You want diagnostics and go-to-definition from a real language server |
| [SKILLS.md](SKILLS.md) | Skill discovery, precedence, the `context:` field | You author or install skills |
| [PLUGINS.md](PLUGINS.md) | Claude-format plugin bundles: surfaces, precedence, the declined hooks and MCP files | You install someone else's bundle |
| [AGENTS.md](AGENTS.md) | Running sub-agents: presets, teams, delegation | You want work done in parallel |
| [AGENTS_AUTHORING.md](AGENTS_AUTHORING.md) | Writing an agent-preset file, field by field | You author `.candy-code/agents/*.md` |
| [BACKGROUND.md](BACKGROUND.md) | Background shells (`run_in_background`, `ShellOutput`, `ShellSend`, `ShellStop`, `/shells`, `/tasks`) and background sessions (`/bg`) | A command or task outlives the turn that started it |
| [WORKFLOWS.md](WORKFLOWS.md) | Multi-stage agent pipelines | You compose a repeatable run |
| [FLEET-DRIVING.md](FLEET-DRIVING.md) | Headless `-p` as a machine surface | You orchestrate agents from scripts |
| [SCHEDULES.md](SCHEDULES.md) | `schedules.json` rows: cadence, the `root` project binding, the permission floor, locks | You set up a repeating run, or one fired in the wrong project |
| [GC.md](GC.md) | The session store: `gc status`, `sessions`, `analyze`, `purge` (plan first with `--dry-run`), and `vacuum` | The disk is filling up, or you want to prune old sessions safely |
| [HOOKS.md](HOOKS.md) | Lifecycle hooks, exit-code semantics, env | You automate reactions to events |
| [OBSERVABILITY.md](OBSERVABILITY.md) | OpenTelemetry export: what is sent, what never leaves the machine, retries, and the `doctor` row | You point candy-code at an OTLP collector, or wonder what it reports |
| [CREDENTIALS.md](CREDENTIALS.md) | `login` per provider, the key in `config.json` or the OS keychain (`--keychain`), and how a key is found at launch | You need to give a provider its key, or wonder where a key is coming from |
| [USAGE.md](USAGE.md) | The spend ledger (`usage`, `/usage`), the session tracker and cap (`/budget`, `--max-cost`), and reports (`/insights`) | You want to know what a run cost, or set a spend cap |
| [GOAL-GRIND.md](GOAL-GRIND.md) | How `/goal` and `/grind` judge a condition, what counts as evidence, and how to write a condition the judge can check | You set a goal and it stops early, or never ends |
| [SHARE.md](SHARE.md) | `/share` and `/export` files, `/share serve` pages, listing and revoking them, and what leaves the machine | You want to hand a transcript to someone, or publish one |
| [REPO-MAP.md](REPO-MAP.md) | The package map, the symbol map, the `RepoMap` tool, and the switches for each | The model finds the wrong code, or session start is slow |
| [ACP.md](ACP.md) | Running `candy-code acp` as the agent in Zed, JetBrains or Neovim: setup, permission modes, cancelling, troubleshooting | You want an editor to host candy-code |
| [MEMORY.md](MEMORY.md) | The memory stores and what reaches the prompt | You wonder what the model remembers |
| [CONTEXT.md](CONTEXT.md) | Context budget, compaction, "turn was NOT sent" | Long sessions start dropping turns |
| [SERVER.md](SERVER.md) | `candy-code serve`, the WebSocket protocol, tokens | You embed or remote-control the agent |
| [DOCKER.md](DOCKER.md) | Running `serve` in a container: image, volume, ports, health | You deploy the server |
| [MEDIA.md](MEDIA.md) | Image/video generation, render modes, presets | You generate media |
| [CI.md](CI.md) | The GitHub Action around `-p`, fork-PR and prompt-injection doctrine | You run the agent in CI |
| [ARCHITECTURE.md](ARCHITECTURE.md) | The code's shape, seams and invariants, incl. [the screen, zone by zone](ARCHITECTURE.md#the-screen-zone-by-zone) | You are reading or changing `src/` |
| [PROMPT_ENGINEERING.md](PROMPT_ENGINEERING.md) | How the system prompt is assembled | You tune agent behavior |
| [TROUBLESHOOTING.md](TROUBLESHOOTING.md) | Symptom-first error recovery, incl. CI automation notes | Something is broken |
| [ACCESSIBILITY.md](ACCESSIBILITY.md) | Screen-reader mode, reduced motion, the contrast floor, audible cues | You drive the TUI with a reader, motion or color is a problem, or you are checking what is enforced vs promised |
| [protocol/](protocol/) | Generated JSON schema of the serve wire | You build a client |
| [examples/](examples/) | systemd unit, the PR-comment bot workflow, other deployment samples | You install it as a service or wire the bot into CI |

## Reading paths

- **New to candy-code:** [TUTORIAL.md](TUTORIAL.md), then [GLOSSARY.md](GLOSSARY.md) for
  the terms, then [FAQ.md](FAQ.md).
- **Deciding what the agent may do:** [PERMISSIONS.md](PERMISSIONS.md), then
  [SETTINGS.md](SETTINGS.md), then [SECURITY.md](SECURITY.md) for the trust boundaries.
- **Unattended or long-running work:** [BACKGROUND.md](BACKGROUND.md), then
  [GC.md](GC.md) for the disk it leaves behind, then [OBSERVABILITY.md](OBSERVABILITY.md)
  if you export telemetry.
- **Running it headless or in CI:** [FLEET-DRIVING.md](FLEET-DRIVING.md), then
  [CI.md](CI.md), then [ENVIRONMENT.md](ENVIRONMENT.md).
- **Getting a provider working:** [CREDENTIALS.md](CREDENTIALS.md), then
  [ENVIRONMENT.md](ENVIRONMENT.md) for every provider variable, then [USAGE.md](USAGE.md)
  to see what the calls cost.
- **Sharing a transcript:** [SHARE.md](SHARE.md), then [SECURITY.md](SECURITY.md) for
  what a shared page exposes.
- **Working inside an editor:** [ACP.md](ACP.md), then [PERMISSIONS.md](PERMISSIONS.md)
  for the modes the editor can switch between.

## The `AGENTS.md` name collision

Two `AGENTS.md` files exist in this repository with **opposite audiences**: the
one at the repo root is the *contributor playbook* for humans working on the
monorepo; this directory's [AGENTS.md](AGENTS.md) is the *user manual for
sub-agents* (delegation, presets, teams). When a doc says "see `AGENTS.md`",
check which root it resolves against.

## See also

- [README (project)](../README.md) — the user guide and key tables.
- [CHANGELOG](../CHANGELOG.md) — what changed, by phase.
