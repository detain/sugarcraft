### C-MCP-3.c — HTTP/SSE server requests + streamable-HTTP GET channel
- item: C-MCP-3 (sources: GAP-MCP-03, GAP-MCP-08, OC-new#1)
- kind: gap · effort M
- approach: `HttpMcpServer` handshakes with `capabilities: {}` (`candy-code/src/MCP/HttpMcpServer.php:518-552`), and `replyFromEventStream()` (:828-851) looks only for its own id, dropping server requests and progress that ride the POST reply stream. `SseMcpServer` ignores requests on its GET stream.
  - Both implement `AnswersServerRequests`: a request frame found while reading a reply (or the legacy GET stream) is answered by a POST of the JSON-RPC response, carrying the `Mcp-Session-Id` header; progress notifications go to the per-call sink.
  - New opt-in streamable-HTTP GET channel (entry key `stream: true`, http only): after `initialize`, reuse `SseMcpServer`'s deadline-bounded raw-socket reader and the shared `SseLineParser`. It is drained at each exchange start and on `McpClient::pumpStderr()`'s idle pump, so pings between calls are answered.
  - The buffered POST path stays the default.
- files.create: `candy-code/tests/MCP/HttpMcpServerGetStreamTest.php`, `candy-code/tests/MCP/SseMcpServerServerRequestTest.php`
- files.modify: `candy-code/src/MCP/HttpMcpServer.php` (`handshake`, `replyFromEventStream`, `callTool`, GET channel), `candy-code/src/MCP/SseMcpServer.php` (stream frame routing, handshake capabilities), `candy-code/src/MCP/SseLineParser.php` (shared reader, if hoisting is needed), `candy-code/src/MCP/McpClient.php` (`buildServer` http arm `stream` key; idle pump), `candy-code/docs/MCP.md` (§"Supported transports" / §"How the legacy SSE transport runs": requests, `stream`; §"`.mcp.json`" http Keys cell gains `stream`)
- tests: create `candy-code/tests/MCP/HttpMcpServerGetStreamTest.php` (a ping on the GET channel is answered; an elicitation inside a POST reply stream reaches the answerer; the default path is unchanged). Create `candy-code/tests/MCP/SseMcpServerServerRequestTest.php` (a request on the legacy stream is answered via the endpoint POST). Modify `candy-code/tests/MCP/HttpMcpServerStreamableHttpTest.php` (capabilities in `initialize`).
- docs/guards: `DocFigureProseDriftTest::testMcpServerTypesKeysAndEnvInterpolationMatchTheFactory` (the http Keys cell gains `stream`) and `::testMcpTransportCensusRowsAreTheFactoryArms`; no proc_open, so the child-lifetime rosters are unaffected.
- serialized: [docs:MCP.md §Supported transports + §Server requests] [hot:candy-code/src/MCP/McpClient.php]
- deps: C-MCP-3.b
- phase: normal
- ruling: none
- risks: the GET channel is read only when candy-code pumps (no event loop on the turn path), so server pushes between turns wait for the next pump.

