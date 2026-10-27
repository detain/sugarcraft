### C-MCP-1.a — User-tier stdio servers spawn in $HOME, project tier at its root
- item: C-MCP-1 (sources: MCP-3-P1)
- kind: fix · MAJOR · effort M
- approach: Root cause: the library spawns with `proc_open(..., null, $env)`, so the child inherits candy-code's cwd (`sugar-mcp-client/src/StdioMcpServer.php:373-383`, arg 4 `null`). The adapter passes only argv and env (`candy-code/src/MCP/StdioMcpServer.php:103-123`). `McpClient::startServer()` knows `$tierPath` (`candy-code/src/MCP/McpClient.php:623`) but `buildServer()` never receives it (`:653`, `:711-741`). So a user-tier `npx`/`python -m` entry runs inside an untrusted checkout and can load that checkout's code. Fix:
  - the library gains `?string $cwd = null`, passed as `proc_open` arg 4;
  - the adapter forwards it;
  - `buildServer()` takes the tier: a user-tier stdio entry spawns in the owned home (`HomeDirectory::owned()`, the root `McpDecision::mcpUserConfigPath()` derives from);
  - a project-tier entry spawns at the canonical project root (`dirname($configPath)`), which also fixes secondary-root `serve` clients that inherit the server's cwd;
  - an explicit absolute `cwd` key on either tier wins; a relative or non-directory `cwd` is a per-entry config error, reported through the existing `startServers()` aggregate.
  The user sees no prompt change, and a repo can no longer feed code to the user's own servers.
- files.create: `sugar-mcp-client/tests/StdioMcpServerCwdTest.php`, `sugar-mcp-client/tests/Fixtures/cwd_server.php`, `candy-code/tests/MCP/StdioMcpUserTierCwdTest.php`
- files.modify: `sugar-mcp-client/src/StdioMcpServer.php` (ctor, `start()` proc_open arg 4), `sugar-mcp-client/README.md` (spawn section: cwd parameter), `candy-code/src/MCP/StdioMcpServer.php` (ctor), `candy-code/src/MCP/McpClient.php` (`startServer()` → `buildServer()` tier arg; `'stdio'` arm reads `$config['cwd']`), `candy-code/docs/MCP.md` (§"Two tiers, one precedence": cwd per tier; §`.mcp.json` type table: stdio Keys cell gains `cwd`)
- tests: create `sugar-mcp-client/tests/StdioMcpServerCwdTest.php` (the fixture echoes `getcwd()` in its `tools/call` reply; null cwd still inherits). Create `candy-code/tests/MCP/StdioMcpUserTierCwdTest.php`:
  - a user-tier entry reports the home;
  - a project-tier entry reports the root, even when the process cwd is a subdirectory;
  - an explicit absolute `cwd` wins;
  - a relative `cwd` is refused for that entry only.
- docs/guards: `candy-code/tests/Config/DocFigureProseDriftTest.php::testMcpServerTypesKeysAndEnvInterpolationMatchTheFactory` reads the stdio arm's `$config[...]` reads against the table's Keys cell, so the doc edit is mandatory in-step. `tools/check-child-lifetimes.php` row `sugar-mcp-client/src/StdioMcpServer.php::start` is unchanged (same fd spec); re-run `php tools/check-child-lifetimes.php` to confirm. Project-tier pins (`McpTrustPins::pin`) change only for entries that add `cwd`, which is expected.
- serialized: [docs:MCP.md §Two tiers + §`.mcp.json`] [hot:sugar-mcp-client/src/StdioMcpServer.php] [hot:candy-code/src/MCP/McpClient.php]
- deps: none
- phase: normal
- ruling: none
- risks: a TUI launched in a subdirectory now runs project-tier servers at the root rather than the subdir; that is where `.mcp.json`'s relative args are written from, but say so in the doc.

