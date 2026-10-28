# BRIEF — C-MCP-2.a: `@mcp:` mentions in serve and `-p`; MCP prompt commands in serve

- area: mcp
- src: design-mcp.md
- item: C-MCP-2 (sources: GAP-MCP-06, GAP-MCP-07, GAP-CMD-09, MCP-1-P1, p1-miss "MCP surfaces in serve/web and -p", k2-dorm `ContextMentions`/`McpPromptCommands`)
- kind: dormant-wire · effort M
- phase: normal
- ruling: none
- wave: None | late: False
- deps: C-SEC-3 (provenance-aware shell gate; serving MCP prompt bodies widens the `!cmd` hole until it lands)
- serialized (claims): [headless:NonInteractive] [docs:MCP.md §Resources + §Prompts] [hot:candy-code/src/Host/SessionHost.php]

## Files to create
`candy-code/tests/Host/SessionHostMcpSurfaceTest.php`

## Files to modify
`candy-code/src/Host/SessionHost.php` (`__construct`, `new`, `submit` custom-command branch, `parkExpansion`, `admitPrompt`), `candy-code/src/Cli/NonInteractive.php` (`run` mention chain :448-458), `candy-code/src/Commands/McpPromptCommands.php` (new `mergeUnderDisk()`), `candy-code/docs/MCP.md` (§"Resources as `@mcp:` mentions": replace the dated "TUI-only for now" paragraph at :833-837; §"Prompts as slash commands": serve parity sentence)

## Declared regions
{"candy-code/src/Host/SessionHost.php": ["parkExpansion", "admitPrompt", "__construct", "submit", "new"], "candy-code/src/Cli/NonInteractive.php": ["run"], "candy-code/src/Commands/McpPromptCommands.php": ["mergeUnderDisk()"], "candy-code/docs/MCP.md": ["@mcp:"]}

## Tests
create `candy-code/tests/Host/SessionHostMcpSurfaceTest.php` (fake provider):
- a served submit resolves `@mcp:` into a fenced attachment;
- a served `/mcp__srv__p` materialises and is shadowed by a same-name disk file;
- a failed fetch is refused, not sent empty.
Modify `candy-code/tests/Cli/NonInteractiveMentionsTest.php` (`-p` resolves `@mcp:`) and `candy-code/tests/Host/TurnControllerMcpSeamTest.php` (both hosts reach the one arming site).

## Docs/guards
`candy-code/tests/Config/DocFigureProseDriftTest.php`:
- `testMcpResourceMentionProseReadsTheKeywordLegItself` flips: the absence legs over `mcpProvider`/`withMcpReader` in NonInteractive.php and SessionHost.php become presence legs, and the 'TUI-only for now' pin becomes the parity sentence.
- `testMcpPromptCommandProseReadsTheMergeAndTheFetch` also pins `McpPromptCommands::mergeUnderDisk()` spelling the same merge order, and the SessionHost specs site.

## Approach (verbatim)

Root cause: only Chat passes an MCP provider. `TurnController::userTurnMessage()` already accepts `$mcpProvider` and arms `withMcpReader` (`candy-code/src/Host/TurnController.php:1032-1075`), but `SessionHost::admitPrompt` omits it (`candy-code/src/Host/SessionHost.php:1702-1710`). NonInteractive builds `ContextMentions` without a reader (`candy-code/src/Cli/NonInteractive.php:448-458`). `McpPromptCommands::specs()` is merged only in the Chat ctor (`candy-code/src/Chat.php:1795-1801`). Wire:
1. SessionHost gets a lazy provider `static fn () => Bootstrap::mcpClient($root)`. It is injectable through `SessionHost::new(..., ?\Closure $mcpProvider = null)` for tests, and on serve the workspace tool build has already memoised the client.
2. The ctor merges `McpPromptCommands::specs($provider)` UNDER the disk rows through a new public `McpPromptCommands::mergeUnderDisk()` that spells the same `array_merge($mcpSpecs, $diskSpecs)` order as `Chat::mergeCustomCommandRows` (Chat.php untouched).
3. Both expansion paths call `McpPromptCommands::materialize()` before `expandCustomCommand`: the sync path at :906-918 and the forked `parkExpansion` `$run` at :950-990, so the network fetch stays in the child. A null result expands to '' and hits the existing empty-expansion refusal.
4. `admitPrompt` passes `mcpProvider`.
5. NonInteractive chains `->withMcpReader(...)` over `Bootstrap::mcpClient($mentionRoot)`.
Users see `@mcp:srv:uri` resolve in web and `-p`, and `/mcp__srv__prompt` typed in a web session runs. The `/` popup listing comes in C-MCP-2.b.

## Risks
served disk rows come from `loadAll($root)` without the trust flag (C-SEC-4's defect); the MCP merge must not paper over it, so leave that leg to C-SEC-4.
