# C-SEC-8.a review r2 (delta f23193fad..8771dc2d3) — VERDICT: CLEAN

Delta confined: MCP.md, McpCallTool.php, McpToolBridge.php, McpToolBridgeTest.php + lane REPORT/REVIEW/BRIEF (prompt_kit lane-owned). Tree clean, tip 8771dc2d3 verified, no stubs.

MAJOR-1 closed at root cause. Both catch sites now `McpOutputFence::wrap(sprintf(...))`: bridge :605 passes `$this->descriptor->serverName`/`->name` (config-side); McpCallTool :197 passes `$server`/`$tool` — safe either way since headerName() escapes, role-marks and flattens names (wrap is the sanitizer for header args). Wrap is outer over the full row; `isError`/duration untouched.

Parity: bridge `$this->name()` == `wireNameFor(serverName, name)` (Bridge.php:304) == dispatch's `wireNameFor($server,$tool)` — same wire name, same header args, same wrap. testTheFailureRowsOfBothSpellingsAreByteIdentical drives BOTH real production executes over one throwing client, assertSame byte-equality + lazy asserted: non-vacuous (unfence either site -> assertSame red; name skew -> header red). Forged-message pin asserts quoted-mark, no line-opening `Human:`, escaped tag, never-verbatim — matches the r1 attack string exactly.

Gates re-run (cwd=candy-code): --filter McpToolBridge = 79T/217A OK (both new pins ran: isolated 2T/13A OK); 'McpOutputFence|StdioMcpHttpRefusals' = 7T/17A OK. (PHPUnit Deprecations: 2 = pre-existing tree-wide shape, not this delta.) REPORT re-stamp 130T/388A consistent with r1's 128T/375A +2T/+13A exact.

MCP.md paragraph true for both transports (stdio splices error.message; http/sse rethrow stack words) and both outcome shapes (answer base + failed new). §1.9: no glob-shaped or bold-**Refused:**-shaped literals in the new fixtures (lowercase prose 'refused' is not a roster prefix). '<SHA>' at REPORT.md:62 is the ONLY self-reference left; MINOR-2 attribution list present at :82-84+.

Weld carries: DUR McpOutputFenceTest 6T, +7T figure, §4 cites 8771dc2d3, stamp 8771dc2d3 into REPORT :62, carry MINOR-2 list for the C-MCP no-collision proof.
