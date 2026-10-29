# C-MCP-3.c — WELD (batch-13, 2026-10-28)
Picks: dbd11a501 -> d827a5f6b (build), be1d3e425 -> 9de3327fe (fix r1), 8c5db7bf8 -> 310350892 (fix r2). Clean picks;
docs/MCP.md union auto-merge with C-SEC-8.a fence section verified (both sections present).
Gates (merged tree): --filter ReadmeJsonErrorContract 19T/461A GREEN (the 4 standing cure-reds gone — lane's guard fix live);
--filter 'HttpMcpServerGetStream|SseMcpServerServerRequest' 10T/85A OK; tests/MCP dir 864T/3222A/1S byte-exact;
DuplicatedTestHelperDrift 14T/197A OK (rename cure confirmed).
DUR minted x2: HttpMcpServerGetStreamTest 0.12 5; SseMcpServerServerRequestTest 0.13 5.
[protocol]: no src/Protocol touches by this lane; regen trio rode the batch-13 weld commit (schema/SERVER.md from 6.c).
§4 cite: 8c5db7bf8. BRIEF.md + REVIEW-1.md were main-tree-untracked; staged in weld commit. Branch REPORT.md
(round-2 superset) kept over the stashed round-0 mtree copy (mtree dropped — strictly older content).
