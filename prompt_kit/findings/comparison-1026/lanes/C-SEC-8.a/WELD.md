# C-SEC-8.a — WELD (batch-13, 2026-10-28, master base cac93e391, tip weld commit)
Picks: f23193fad -> 7b8d787aa (build), 8771dc2d3 -> b14427010 (fix). Clean cherry-picks, zero manual conflicts.
Gates (merged tree, cwd=candy-code, plain pipe, -c phpunit.xml, env -u LINES -u COLUMNS):
- --filter 'McpOutputFence|StdioMcpHttpRefusals': 7T/17A OK (exact vs brief).
- --filter McpToolBridge: 79T/217A OK (exact).
- --filter 'Mcp' (band context): 1496T/8737A/1S OK — = 1486T/8638A (brief figure at 8.a tree) + 10T/85A C-MCP-3.c
  new-file carry + in-place assertion growth from the 3.c fix rounds; green, arithmetic disclosed.
DUR minted: candy-code/tests/Context/McpOutputFenceTest.php 0.10 6. Figure carry +7T (6 new file + 1 parity method).
WELD-DOC: REPORT.md '<SHA>' row stamped 8771dc2d3. REVIEW-2.md staged untracked copy (main tree); REVIEW-1.md rode pick.
§4 cite: 8771dc2d3. docs/MCP.md fence section auto-merged union with 3.c server-requests section — both live (grepped).
