# C-MCP-3.a — CLOSED (real commit on disk)

VERDICT: DONE. Commit 5773218ab on cc/C-MCP-3.a (cat-file-verified commit object; parent 5ed26cf92; author==committer detain@interserver.net separate greps 1/1; zero Co-Authored trailers; NEVER pushed). 13 files, +1640/−14. No forbidden file touched (suite-figure.json, durations.tsv, dist/, protocol-generated all byte-clean).

GATES (all run against the exact committed tree):
- sugar-mcp-client full suite: 244 tests / 949 assertions OK (pristine baseline was 234/918).
- candy-code --filter 'McpServerRequests|McpElicitation': 29/102 OK (2 inherited PHPUnit deprecations).
- candy-code --filter 'McpServerRequests|McpElicitation|StdioMcpServer|McpClient|WireShape': 236/806 OK, 1 pre-existing skip.
- tools/check-one-type-per-file.php rc0 (3212 files, 0 problems); tools/check-child-lifetimes.php rc0 (33 sites, 9/9 accounted, unchanged).
- five-guard family 133T: only 3 REDS are INHERITED — reproduced verbatim at pristine 5ed26cf92 in main tree (ReadmeSuiteFigureDrift pair 25537-vs-26743 + DocFigure 'thirty-seven' AGENTS_AUTHORING arm; foreign-era re-pin duty, welder's).

NEW candy-code *Test.php files (weld durations TSV duty):
- candy-code/tests/MCP/McpServerRequestsTest.php (12 tests)
- candy-code/tests/MCP/McpElicitationTest.php (13 tests)
(library-side new files: sugar-mcp-client/tests/StdioMcpServerServerRequestTest.php + Fixtures/server_request_server.php; SdkWireShapeTest modified with the mandated default-`{}` pin.)

DISCRIMINATION (red-before/mutation proofs, /tmp cp-harness, md5-restored):
- A: remove deadline-pause-before-write → testCallDeadlineIsPausedWhileAHandlerRuns RED.
- B: neuter method-frame interception in readResponse → 7 server-request tests RED.
- C: drop ping arm in McpServerRequests → exactly 1 core test RED.
- D: flip no-approver cancel→accept → 3 elicitation-law tests RED.
- New classes absent at parent ⇒ core tests trivially fail there (no file).

DEVIATIONS/DISCLOSURES:
1. A concurrent live session kept writing this worktree mid-lane (src + fixture + lib test between my checks; it committed at 06:27:36 a moment before my git add — same state I had fully gated, single cohesive commit; I adopted its on-disk work after reviewing every diff, authored the missing SdkWireShapeTest pin + both candy-code test files + the McpServerRequests `$root`→`$resolved` rename fixing the inherited bare-getcwd guard trip, ran all gates+mutations above).
2. docs/MCP.md server-requests paragraph (writer's) rode the same commit per the doc-rows-move-with-code law; DocFigure guards green on it.
3. The elicitation-holds-the-exchange-lock risk (plan §6) is documented in the library class docblock, core adapter seam, McpClient::callTool param doc, README, and docs/MCP.md.
