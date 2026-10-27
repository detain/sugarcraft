### C-MCP-6.a — MCP hygiene: `mcp_list` lookup, login-URL scan, SSE fork TLS
- item: C-MCP-6 (sources: MCP-7-P3, MCP-5-P1, MCP-4-P1, VIS-3-P1)
- kind: fix · MINOR (the login-URL leg is upgraded, see the first bullet) · effort S
- approach: Three legs, one lane, disjoint files.
  - **Login URL (upgrade).** `McpLoginFork::poll()` keeps a 1500-byte tail (`candy-code/src/Support/McpLoginFork.php:35,116`) and takes the FIRST `https?://\S+` (`:120`). The real child prints ``Open this URL in a browser to authorize `<serverUrl>`:`` BEFORE the authorize URL (`candy-code/src/MCP/OAuthLoopbackFlow.php:158-160`). So the TUI announces the server URL (with a trailing `` `: ``), not the login link, on every real flow. The existing test stubs print only the URL (`tests/Chat/McpLoginForkTest.php:90`). Fix: accumulate an un-truncated scan buffer (capped at 64 KiB) until announced, and take the first line consisting solely of one URL (`^\s+(https?://\S+)\s*$`m, the shape `OAuthLoopbackFlow` prints at :160). Expose the parsed `redirect_uri` port for C-MCP-4.c.
  - **`mcp_list`.** It truncates at 64 KiB with only a `server` filter (`candy-code/src/Tools/BuiltIn/McpListTool.php:79-92,131`). Add a `tool` argument (exact name → that schema only). When an unfiltered or server-filtered listing would exceed the cap, return a names-plus-one-line overview that says how to fetch each schema.
  - **SSE fork.** `SseMcpServer::openStream()` overwrites `$this->socket` (`candy-code/src/MCP/SseMcpServer.php:413`). In a forked child this frees the inherited TLS resource, whose `SSL_shutdown` puts `close_notify` on the parent's connection. Fix: when the current pid is not the opener, retain the inherited resource (never close it) before replacing it.
  - **VIS-3-P1.** The stale COMPARISON.md:788 sentence is already absent from the rewritten `candy-code/COMPARISON.md` (grep `launch-time entry bytes` = 0 at design time). Re-grep only; COMPARISON.md is orchestrator-owned, so do not edit it.
- files.create: `candy-code/tests/MCP/SseMcpServerForkTlsTest.php`
- files.modify: `candy-code/src/Support/McpLoginFork.php` (`poll`, slot shape), `candy-code/src/Tools/BuiltIn/McpListTool.php` (`description`, `inputSchema`, `execute`), `candy-code/src/MCP/SseMcpServer.php` (`openStream`), `candy-code/docs/MCP.md` (§"Deferred schemas" `mcp_list` bullet)
- tests: modify `candy-code/tests/Chat/McpLoginForkTest.php`: a stub printing the exact `OAuthLoopbackFlow` shape (server URL in backticks, then a 2 KB authorize URL on its own line) announces the authorize URL intact. Modify `candy-code/tests/MCP/McpDispatcherToolsTest.php`: a roster above the cap stays reachable through `tool`, and the overview names every tool. Create `candy-code/tests/MCP/SseMcpServerForkTlsTest.php`: a forked child's `openStream` leaves the parent's inherited resource open (plain-tcp stub; the TLS alert itself is PLAUSIBLE, not observable in-suite).
- docs/guards: `candy-code/tests/Tools/BuiltInToolCorpus.php` and `candy-code/tests/Tools/Catalog/ToolDocDriftTest.php` read `mcp_list` (externally wired, not in the plain roster). Re-run both. No catalog position change (39 stays).
- serialized: [docs:MCP.md §Deferred schemas] [hot:candy-code/src/Tools/BuiltIn/McpListTool.php] [hot:candy-code/src/Support/McpLoginFork.php]
- deps: C-MCP-1.b (edits the same §Deferred schemas paragraphs; sequence, or run as one docs owner)
- phase: normal
- ruling: none
- risks: the `mcp_list` description and schema are model-facing, so any PromptStability pin over externally wired schemas must move in-step.

