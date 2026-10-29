### C-AGT-4.b — `/agent use`, Alt+A cycle, TUI badge, serve effect
- item: C-AGT-4 (sources: GAP-AGT-01/02)
- kind: gap · effort M
- approach:
  - `/agent use <preset>` and `/agent use default` become a verb of `AgentsCommand`/`AgentsHostCommand`, returning a new `CommandEffect::setPrimaryAgent(?string)`.
  - Chat's `applyCommandResult` arm applies it through `PrimaryAgent` (stored per session as `primaryAgent`), refused mid-turn like a mode switch. `SessionHost`'s `applyCommandResult` arm stores it on its session-backend override, as `SetModel` does (`SessionHost.php:1348`).
  - Add a `chat.agent-cycle` binding on `Alt+A` (free; Tab, Shift+Tab and Ctrl+Tab are taken) that cycles default → `mode: primary|all` presets.
  - Show the agent name in the status line beside the mode badge (`Renderer::modeBadge` :2522).
  - `handleAgentsCommand` (`Chat.php:12930`) keeps its live-run → Agent View branch, which P-AGENT-ATTACH owns. `use` is reserved before that lookup.
  - Not persisted across restarts (documented).
- files.create: none
- files.modify: `candy-code/src/Commands/AgentsCommand.php` (`use` verb), `candy-code/src/Host/Commands/AgentsHostCommand.php`, `candy-code/src/Host/Commands/CommandEffect.php` and `candy-code/src/Host/Commands/CommandEffectKind.php` (`SetPrimaryAgent`), `candy-code/src/Chat.php` (`handleAgentsCommand`, `applyCommandResult` arm, `primaryAgent` state, Alt+A key arm), `candy-code/src/Host/SessionHost.php` (`applyCommandResult` arm, `primaryAgent()` accessor), `candy-code/src/Renderer.php` (`modeBadge` neighbour), `candy-code/src/Commands/KeyBindingRegistry.php` (`chat.agent-cycle`), `candy-code/builtin-commands/0800-agents.php` (argument hint), `candy-code/lang/en.php` (`cmd.agents.*`, `keys.chat.agent-cycle`, `host.agents.use_*`), `candy-code/README.md` (Keys table), `candy-code/docs/AGENTS.md` (primary agents section), `candy-code/docs/COMMANDS.md` (regenerated)
- tests: create `candy-code/tests/Chat/PrimaryAgentSwitchTest.php` (`/agent use reviewer` narrows the next turn's tools and shows the badge; `default` reverts; refused mid-turn; Alt+A cycles only primary-mode presets); modify `candy-code/tests/Commands/AgentsCommandTest.php` (`use` verb), `candy-code/tests/Host/SessionHostCommandsTest.php` (served effect)
- docs/guards: `candy-code/tests/Commands/KeyBindingDriftTest.php` + README Keys table; `candy-code/tests/Commands/CommandDocsDriftTest.php` (`php tools/gen-command-docs.php --write`); `docs/AGENTS.md`
- serialized: [chat:handleAgentsCommand, applyCommandResult, key dispatch,ctor] [renderer:modeBadge] [commands:0800-agents.php] [hot:candy-code/src/Host/SessionHost.php] [lang: `cmd.agents.*`, `keys.chat.agent-cycle`, `host.agents.*`] [docs:README.md] [docs:AGENTS.md] [docs:COMMANDS.md]
- deps: C-AGT-4.a, P-AGENT.b (shared Agents handler/strip files; never the same wave)
- phase: normal
- ruling: none
- risks: shares `handleAgentsCommand`, `AgentsCommand` and the status line with P-AGENT-ATTACH. Never run both in one wave.

