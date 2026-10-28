# C-MCP-3.a: Server-request seam: ping, roots, elicitation, progress (library + core)

- wave ? · area mcp · kind gap · effort M · effort ? · phase normal
- item: C-MCP-3 (sources: GAP-MCP-08, p1-miss "MCP elicitation/sampling/roots/logging/ping", OC-new#1, CC-new#2)
- deps: C-MCP-1.a (same two files; C-MCP-1.a adds the ctor `cwd` param this lane extends)
- serialized: [hot:sugar-mcp-client/src/StdioMcpServer.php] [hot:candy-code/src/MCP/McpClient.php]
- ruling: none
- risks: an elicitation holds the server's cross-process exchange lock while the human answers, so other calls to that one server queue; document it.

## Files
- create: `candy-code/src/MCP/AnswersServerRequests.php`, `candy-code/src/MCP/McpServerRequests.php`, `candy-code/src/MCP/McpElicitation.php`, `sugar-mcp-client/tests/StdioMcpServerServerRequestTest.php`, `sugar-mcp-client/tests/Fixtures/server_request_server.php`, `candy-code/tests/MCP/McpServerRequestsTest.php`, `candy-code/tests/MCP/McpElicitationTest.php`
- modify: `sugar-mcp-client/src/StdioMcpServer.php` (ctor, `start` handshake capabilities, `callTool`/`callToolWaiting`, `request`, `readResponse`), `sugar-mcp-client/README.md` (Wire shape: capabilities; a new "Server requests" section; drop the "Phase 2" exclusion wording that no longer holds), `candy-code/src/MCP/StdioMcpServer.php` (ctor capabilities, `callTool` per-call answers), `candy-code/src/MCP/McpClient.php` (`callTool`, `buildServer` stdio arm)
- tests: create `sugar-mcp-client/tests/StdioMcpServerServerRequestTest.php` (fixture `server_request_server.php`):
- a ping mid-call is answered;
- an unknown request gets -32601;
- the call deadline is paused while the handler runs;
- configured capabilities reach `initialize`.
Modify `sugar-mcp-client/tests/StdioMcpServerSdkWireShapeTest.php` (the default still sends `{}`). Create `candy-code/tests/MCP/McpServerRequestsTest.php` (roots answer, sampling -32601, progress sink). Create `candy-code/tests/MCP/McpElicitationTest.php` (schema→questions, type validation, decline/cancel).
- docs/guards: `sugar-mcp-client/tests/McpMessageWireShapeTest.php` unchanged; `tools/check-child-lifetimes.php` unchanged (no new spawn); `php tools/check-one-type-per-file.php` (three new types, three files).

## Approach
Today the client advertises `capabilities: {}` (`sugar-mcp-client/src/StdioMcpServer.php:408-414`), and `readResponse()` skips every method-bearing frame unanswered (`:1106-1160`), so a server's `ping`, `roots/list` or `elicitation/create` waits forever.
Library:
- a ctor `array $capabilities = []` (still encoded as an object);
- an instance-level `?\Closure $onServerMessage`, plus a per-call override on `callTool()`/`request()`;
- inside the exchange, a request frame (method + id) goes to the handler and its result or error is written back under the lock with `McpMessage::success/error`, preserving the wire id;
- notifications go to the handler too;
- with no handler, `ping` answers `{}` and every other request answers -32601 (spec-compliant default);
- the per-call deadline is extended by the handler's wall time, so a human answering an elicitation never burns `toolTimeout` (E646 spirit).
candy-code core:
- `McpServerRequests` dispatches `ping` → `{}`, `roots/list` → the project root as a `file://` root, `elicitation/create` → `McpElicitation` (an injected approver, else `cancel`), sampling and the rest → -32601, and `notifications/progress` → an injected progress sink;
- `McpElicitation` maps the flat requested schema to per-field questions (enum → options; boolean → yes/no; string/number/integer → typed answer validated against the type) and returns `accept + content`, `decline` or `cancel`;
- `AnswersServerRequests` is a narrowed-instanceof capability (the `pumpStderr` precedent), so `McpServer` stays unchanged;
- the stdio adapter implements it;
- `McpClient::callTool()` takes `?McpServerRequests` and builds the instance-level roots/ping answerer at `buildServer()`, advertising `roots` and `elicitation`.
