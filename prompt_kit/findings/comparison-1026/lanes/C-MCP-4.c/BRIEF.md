### C-MCP-4.c — Served `/mcp login` + paste-back finish from a remote browser
- item: C-MCP-4 (sources: GAP-MCP-04)
- kind: gap · effort M
- approach: In the TUI, `Chat::handleMcpAuthCommand` intercepts `login <server>` into `McpLoginFork` (`candy-code/src/Chat.php:19810-19843`). Served `/mcp login` reaches `McpAuthHostCommand` → `printLoginGuidance()`, which only prints shell guidance (`candy-code/src/Host/Commands/McpAuthHostCommand.php:20-27`). And a remote browser can never reach the child's `127.0.0.1` callback.
  - (1) `McpAuthHostCommand` intercepts `login <server>` like Chat, arms `McpLoginFork`, and registers the slot with a new `ServedMcpLogins` watcher (server → sessionId). `ServerContext::pump()` polls it and appends uiOnly rows (link, done/failed) through a new `SessionHost::appendNotice()`, which emits `message.created`.
  - (2) Paste-back: `login <server> --code <redirected-url>` is handled inside `McpLoginFork::arm()` when a slot is live. It accepts only `http://127.0.0.1|localhost:<the slot's redirect port>/…`, then issues a local GET to that loopback URL, so the waiting child finishes its own state and PKCE exchange. That works in the TUI too with no Chat change (Chat already forwards `rest`).
  - Anything else is refused (no SSRF). Tokens still attach at the next launch (existing honesty).
- files.create: `candy-code/src/Host/ServedMcpLogins.php`, `candy-code/tests/Host/McpLoginServedTest.php`
- files.modify: `candy-code/src/Host/Commands/McpAuthHostCommand.php` (`run` intercept), `candy-code/src/Support/McpLoginFork.php` (`arm` paste-back leg, redirect-port accessor), `candy-code/src/Host/SessionHost.php` (public `appendNotice`), `candy-code/src/Protocol/ServerContext.php` (`pump`), `candy-code/lang/en.php` (`cmd.mcp-auth.login-*`: served armed, paste-back delivered/refused), `candy-code/docs/MCP.md` (§"Server auth (`/mcp`)" + §"Authorization-code login": served flow and paste-back)
- tests: create `candy-code/tests/Host/McpLoginServedTest.php` (a served `/mcp login` arms and the watcher appends the link row; done row; a second session's login is independent). Modify `candy-code/tests/Chat/McpLoginForkTest.php` (paste-back reaches a stub loopback listener; a non-loopback host, wrong port or no live slot is refused).
- docs/guards: `DocFigureProseDriftTest::testTheLoginCommandWordRoutesThroughTheVerbGateAndGuidanceArm` (the guidance arm stays for bare `login`; re-pin the narrative and the `candy-code mcp auth login <server>` occurrence count of 2) and `::testLoginWaitBudgetIsTheConstantTheProsePrints`. No new `proc_open` (the paste-back is a client socket); `Support/McpLoginFork.php::arm` keeps its `DescriptorInheritanceGuardTest` row.
- serialized: [lang:cmd.mcp-auth.login] [docs:MCP.md §Server auth + §Authorization-code login] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Protocol/ServerContext.php] [hot:candy-code/src/Support/McpLoginFork.php]
- deps: C-MCP-6.a (fixes the URL scan this announces and exposes the redirect port), C-MCP-4.b (same §Server auth doc section and lang prefix)
- phase: normal
- ruling: none
- risks: any client with Write scope can start a login child on the server host, the same power as `command.exec` today; state that in docs/MCP.md.

