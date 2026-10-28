# candy-code documentation

Index of every page in this directory. Read the [project README](../README.md)
first for the quick start; come here when you know what question you are
asking.

| Page | What it answers | Read it when |
|---|---|---|
| [GLOSSARY.md](GLOSSARY.md) | What TUI, MCP, OSC 52, sixel, PTY, SGR… mean | Any page's vocabulary stops you |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Dev modes, the sharded suite, the drift guards, docs cadence | You are changing this package's code or docs |
| [ADDING_A_PROVIDER.md](ADDING_A_PROVIDER.md) | The provider contract: capabilities, carrier fields, registration, behavior laws | You are wiring a new model API |
| [COMMANDS.md](COMMANDS.md) | Every slash command, argument-for-argument | You want to know what you can type |
| [SETTINGS.md](SETTINGS.md) | Every config key, its tier and its risk | You are editing `config.json` |
| [ENVIRONMENT.md](ENVIRONMENT.md) | Every environment variable the app reads | You are configuring a shell, container or CI job |
| [PERMISSIONS.md](PERMISSIONS.md) | Permission modes, rules, gate chain | The agent is refused (or should be) |
| [MCP.md](MCP.md) | Model Context Protocol servers: declaring, trusting, auth | You are wiring external tools |
| [SKILLS.md](SKILLS.md) | Skill discovery, precedence, the `context:` field | You author or install skills |
| [PLUGINS.md](PLUGINS.md) | Claude-format plugin bundles: surfaces, precedence, the declined hooks and MCP files | You install someone else's bundle |
| [AGENTS.md](AGENTS.md) | Running sub-agents: presets, teams, delegation | You want work done in parallel |
| [AGENTS_AUTHORING.md](AGENTS_AUTHORING.md) | Writing an agent-preset file, field by field | You author `.candy-code/agents/*.md` |
| [WORKFLOWS.md](WORKFLOWS.md) | Multi-stage agent pipelines | You compose a repeatable run |
| [SCHEDULES.md](SCHEDULES.md) | `schedules.json` rows: cadence, the `root` project binding, the permission floor, locks | You set up a repeating run, or one fired in the wrong project |
| [HOOKS.md](HOOKS.md) | Lifecycle hooks, exit-code semantics, env | You automate reactions to events |
| [MEMORY.md](MEMORY.md) | The memory stores and what reaches the prompt | You wonder what the model remembers |
| [CONTEXT.md](CONTEXT.md) | Context budget, compaction, "turn was NOT sent" | Long sessions start dropping turns |
| [SERVER.md](SERVER.md) | `candy-code serve`, the WebSocket protocol, tokens | You embed or remote-control the agent |
| [DOCKER.md](DOCKER.md) | Running `serve` in a container: image, volume, ports, health | You deploy the server |
| [MEDIA.md](MEDIA.md) | Image/video generation, render modes, presets | You generate media |
| [CI.md](CI.md) | The GitHub Action around `-p`, fork-PR and prompt-injection doctrine | You run the agent in CI |
| [ARCHITECTURE.md](ARCHITECTURE.md) | The code's shape, seams and invariants, incl. [the screen, zone by zone](ARCHITECTURE.md#the-screen-zone-by-zone) | You are reading or changing `src/` |
| [PROMPT_ENGINEERING.md](PROMPT_ENGINEERING.md) | How the system prompt is assembled | You tune agent behaviour |
| [TROUBLESHOOTING.md](TROUBLESHOOTING.md) | Symptom-first error recovery, incl. CI automation notes | Something is broken |
| [protocol/](protocol/) | Generated JSON schema of the serve wire | You build a client |
| [examples/](examples/) | systemd unit, the PR-comment bot workflow, other deployment samples | You install it as a service or wire the bot into CI |

## The `AGENTS.md` name collision

Two `AGENTS.md` files exist in this repository with **opposite audiences**: the
one at the repo root is the *contributor playbook* for humans working on the
monorepo; this directory's [AGENTS.md](AGENTS.md) is the *user manual for
sub-agents* (delegation, presets, teams). When a doc says "see `AGENTS.md`",
check which root it resolves against.

## See also

- [README (project)](../README.md) — the user guide and key tables.
- [CHANGELOG](../CHANGELOG.md) — what changed, by phase.
