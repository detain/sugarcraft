### C-MCP-4.a — User-tier rows + liveness in `context.sources` (data step)
- item: C-MCP-4 (sources: k2-dorm `McpDecision` user servers, GAP-MCP-10 web-liveness half); feeds P-CTX-BREAKDOWN
- kind: dormant-wire · effort M
- approach: Dormant data: `McpDecision::mcpServerInventory()` already computes `userStatus/userPath/userServers/userSkipped/userError` (`candy-code/src/Cli/McpDecision.php:294-340, 648-680`). `ContextSources::answer()` maps only the project `servers` (`candy-code/src/Host/ContextSources.php:158-172`). The fix:
  - `mcp` gains `userStatus`, `userError` and `userSkipped`;
  - every server row gains `tier: project|user` (user rows appended, shadowed names already excluded by the census);
  - every row gains a nullable `liveness {up: bool|null, tools: int}` from `Bootstrap::mcpLivenessSnapshot($root)`, which is memo-only and NEVER launches (null when this process holds no client);
  - `answer()` takes an injectable `?array $mcpLiveness` for tests;
  - the schema lives in `Definitions.php` (the `mcp` object at :215-224);
  - regenerate the chain;
  - the web ContextPanel lists user-scope rows under their own heading with a liveness dot.
  Self-contained: P-CTX-BREAKDOWN reads these rows (web) and `mcpServerInventory()` (TUI) without further MCP work.
- files.create: `candy-code/tests/Protocol/ContextSourcesMcpUserTierTest.php`
- files.modify: `candy-code/src/Host/ContextSources.php` (`answer`), `candy-code/src/Protocol/Schema/Definitions.php` (context-sources `mcp` object), `candy-code/docs/protocol/candy-code.v1.schema.json` (regen), `candy-code/docs/SERVER.md` (regen), `candy-code-web/src-web/protocol/generated.ts` (regen), `candy-code-web/src-web/components/panels/ContextPanel.vue` (MCP section :144-158)
- tests: create `candy-code/tests/Protocol/ContextSourcesMcpUserTierTest.php`:
  - user rows carry `tier: user`;
  - a shadowed user name is absent;
  - `userError` is surfaced for an undecodable user file;
  - liveness is null with no memoised client, and filled from an injected snapshot.

  Modify `candy-code/tests/Protocol/ContextSourcesTest.php` (inventory fixture shape). Modify `candy-code-web/src-web/components/panels/__tests__/contextPanel.spec.ts` (user rows render; liveness dot states).
- docs/guards: `scripts/gen-protocol-schema.php` → `candy-code-web/scripts/gen-protocol.mjs` regen in-step. `candy-code/tests/Protocol/ProtocolSchemaDriftTest.php` and `candy-code/tests/Config/ServerProtocolDocumentationDriftTest.php` go green on the regen. The `vitest --no-file-parallelism` web leg.
- serialized: [protocol] [hot:candy-code/src/Host/ContextSources.php] [hot:candy-code-web/src-web/components/panels/ContextPanel.vue]
- deps: none
- phase: normal
- ruling: none
- risks: ContextPanel.vue is also P-CTX-BREAKDOWN's and C-SEC-5's surface; keep this lane's markup to one section, so the breakdown lane restyles rather than conflicts.

