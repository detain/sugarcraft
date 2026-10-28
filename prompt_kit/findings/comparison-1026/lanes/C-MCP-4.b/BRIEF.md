# BRIEF — C-MCP-4.b (wave 16)

## Runtime `/mcp enable|disable <server> [--persist]`
- area: mcp · kind: gap · effort M · effort:  · phase: normal
- item: C-MCP-4 (sources: GAP-MCP-10)
- deps: none
- serialized: [lang:cmd.mcp-auth.] [docs:MCP.md §Server auth + §Liveness] [docs:COMMANDS.md] [hot:candy-code/src/MCP/McpClient.php] [hot:candy-code/src/Commands/McpAuthCommand.php]
- ruling: none

## Files to create
`candy-code/tests/Commands/McpAuthCommandEnableDisableTest.php`, `candy-code/tests/MCP/McpRuntimeDisableTest.php`

## Files to modify
`candy-code/src/MCP/McpClient.php` (`isServerAllowed`, `router`, `startedSnapshot`, new session-disable API), `candy-code/src/Commands/McpAuthCommand.php` (`execute` arms), `candy-code/src/Tui/McpPanel.php` (liveness suffix), `candy-code/lang/en.php` (`cmd.mcp-auth.enable.*`, `cmd.mcp-auth.disable.*`, `cmd.mcp-auth.unknown`, `cmd.mcp.hint`, `cmd.mcp.description`), `candy-code/docs/MCP.md` (§"Server auth (`/mcp`)": the five-command fence becomes seven, plus the lead-in count; §"Liveness in a running session": suffix row), `candy-code/docs/COMMANDS.md` (regen via `candy-code/tools/gen-command-docs.php`)

## Tests
create `candy-code/tests/MCP/McpRuntimeDisableTest.php` (a disabled server's call is refused with the hint; the roster is unchanged; enable restores; a forked copy inherits). Create `candy-code/tests/Commands/McpAuthCommandEnableDisableTest.php` (the arms; `--persist` writes and removes exactly one name; an unknown server is refused). Modify `candy-code/tests/Tui/McpPanelTest.php` (suffix).

## Docs / guards
`DocFigureProseDriftTest`:
- `::testMcpCommandsSurfaceCountsHelpRowsAndTheRunArm` (match-arm order pin at :4050-4061, "five sub-commands" → "seven", the `cmd.mcp-auth.unknown` roster);
- `::testMcpLivenessSuffixesAndTheNeverLaunchingSnapshotReadThePanel` (new suffix);
- `::testCommandsMcpPaletteSentenceTracksTheTruthfulLabel` if `cmd.mcp.description` moves.
Regen `docs/COMMANDS.md` in-lane. `builtin-commands/0900-mcp.php` is untouched (its text is Lang keys), so this step is not `[commands]`.

## Approach
Today a server can be disabled only through `disabledMcpServers` plus a relaunch (E696 deny map, applied at start and in `isServerAllowed()`, `candy-code/src/MCP/McpClient.php:204-207, 893-901`).
- `McpClient` gains `disableForSession()`/`enableForSession()`/`sessionDisabled()`: a session deny set consulted by `isServerAllowed()` and the router.
- The tool roster is unchanged (cache law): calls are refused with "disabled for this session (/mcp enable <server>)".
- `startedSnapshot()` rows carry `disabled`.
- `McpAuthCommand::execute()` grows `enable` and `disable` arms (:167-174).
- `--persist` adds or removes the exact name in the user `disabledMcpServers` via `Bootstrap::writeUserConfig()` (static call; Bootstrap.php untouched).
- The TUI panel shows a "disabled" suffix.
- Forked turns and sub-agents inherit the parent's memoised client, so the next turn sees the change.
- The served path works through `McpAuthHostCommand` → `command.exec` with no protocol change.
- The web disabled state is not shown (C-MCP-4.a's liveness has no `disabled` field). Optional follow-up.

## Risks
a turn already forked before `/mcp disable` keeps its pre-disable copy, so document "takes effect from the next turn".
