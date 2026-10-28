# C-MCP-3.a — WELD

- Pick: 5773218ab → master e71d398aa. CLEAN (13 files +1640/−14; ping/roots/elicitation/progress server-request seam: sugar-mcp-client StdioMcpServer + candy-code src/MCP/{McpServerRequests,McpElicitation,McpClient,StdioMcpServer(alias)} + docs/MCP.md + 2 new candy-code test files). Author/committer detain@interserver.net both greps, zero trailers.
- TWIN-BUILDER ADOPTION (lead-verified, per brief): the lane's single coherent commit 5773218ab was assembled by two builder sessions; the lead adopted it as one lane tip. Recorded per weld protocol.
- Regen NO-OP (verified, no rider commit — precedent C-TOOL-7.b): `php scripts/gen-protocol-schema.php --write` → "protocol schema: up to date"; `npm run gen:protocol` in candy-code-web rewrote generated.ts BYTE-IDENTICAL (git porcelain clean on docs/protocol/, SERVER.md, web protocol surface); dist unmoved.

## Merged gates (plain pipe, env -u LINES -u COLUMNS, cwd candy-code unless noted)
- `ProtocolSchemaDrift|ServerProtocolDocumentationDrift` — 12T/332A OK (2 standing deprecations). Required post-lane trio check ✓.
- `McpServerRequests|McpElicitation` — 29T/102A OK. EXACT vs builder claim.
- `McpServerRequests|McpElicitation|StdioMcpServer|McpClient|SdkWireShape` — 223T/741A/1S OK. EXACT vs REVIEW-1 (line 38). NOTE: the lead brief quoted this gate as `mcp.resources.read|ProtocolConformance` — that spelling matches no tests on the tree; the REVIEW-1 command is authoritative and reproduced exactly.
- sugar-mcp-client `ServerRequest|SdkWireShape` — 15T/51A OK (per-file 7+8). REVIEW-1 claimed 13T/65A — transcription slip in the reviewer's figures; both files green on the merged tree, zero failures.
- `tests/MCP` directory — 849T/3117A/1S OK. EXACT.
- vitest `src-web/protocol/__tests__/generated.spec.ts` (--no-file-parallelism) — 3/3 pass. dormantRoster.spec.ts does not exist on disk (brief mention stale); protocol trio unmoved so not required.

## Weld cure (grep-unpinned, per brief option)
- docs/MCP.md:611 "`elicitation/create` is declined-with-cancel" → "is answered `cancel`" — matches the wire truth in src/MCP/McpElicitation.php (no per-call answers ⇒ `{"action":"cancel"}`; DECLINE is a separate explicit sentinel path). Zero grep pins of the old wording tree-wide (incl McpStartupOrderingDocDriftTest, which reads docs/MCP.md for server-type claims only); guard trio re-run after cure: 16T/349A OK.

## Durations
- f87047b29: +2 rows — `candy-code/tests/MCP/McpServerRequestsTest.php 0.064 13`, `candy-code/tests/MCP/McpElicitationTest.php 0.049 14` (single-file junit sums on merged tree). Set 1505→1507.
- 15ef514de (cross-lane cure): the twin welder's PrimaryAgentTest row carried assertions(45) in the testcase column; reset to junit truth 0.152/10.

## Incident note — concurrent welder session
A parallel weld-session (the session that had landed 093ea6e13 earlier, believed dead) completed the C-AGT-4.a ceremony at 07:13–07:14 (50bc9cd2b, 55d66b158) while this session ran lane-1 gates; its pick e71d398aa-interleaving is visible in the chain. Content-equivalence of 093ea6e13 vs lane tip 1cee96571 was independently proven here first (sorted +/- lines, 892 each, cmp-equal; /tmp/opencode/mcp-weld-{a,b}.txt). Lane 2 was NOT re-welded; only its durations row was normalized (15ef514de) and its WELD.md extended with the 540b07625 phantom history this run requires.

## Absorption & cleanup
- `git cherry -v master cc/C-MCP-3.a` → `-` (see weld report). Worktree ../cc-cmp-C-MCP-3.a removed, branch cc/C-MCP-3.a deleted after proof.
