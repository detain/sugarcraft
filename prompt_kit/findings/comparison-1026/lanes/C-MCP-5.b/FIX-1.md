
## Round-1 fixer (2026-10-31)

Finding fixed: REVIEW-1.md Q4 MINOR (step-local) — docs/MCP.md §Serving MCP lead sentence
read exclusively-stale after `mcp serve` landed ("What CandyCode serves itself is
`GitMcpServer`…"). Applied the reviewer's one-word cure verbatim: "serves itself FOR GIT is"
(re-wrapped the following line so the em-dash clause stays whole; 2 lines changed).

- Commit: 1a6130f995593f1508784a98dedf4ae9846544eb "C-MCP-5.b: review fixes (round 1) —
  MCP.md serving sentence scoped to git". Separate follow-up atop reviewed tip 81a4146c2
  (no amend). Author %ae count=1 detain, %ce count=1 detain, zero Co-Authored trailers.
  File set: candy-code/docs/MCP.md only (git diff --stat 1 file, +2/-2).
- Pins preserved (grep counts at tip): "`candy-code serve` is not one of them" =1,
  "exactly fourteen" =1; login-arm substr "candy-code mcp auth login <server>" untouched
  (no edit in that region); DocFigure serve qualifiers byte-untouched.
- Guards (anchored exact-class regexes, cwd=candy-code -c phpunit.xml, env -u LINES -u
  COLUMNS plain pipe, PtyRunToolTest un-matched by filters):
  DocFigureProseDriftTest|ServerProtocolDocumentationDriftTest|CommandDocsDriftTest|
  McpStartupOrderingDocDriftTest => Tests: 108, Assertions: 4289, OK (2 PHPUnit
  deprecations = pre-existing harness notices, zero failures).
  CliLangCatalogueTest|LangParityTest => Tests: 15, Assertions: 3633, OK (same 2 notices).
- Red-before/green-after: not applicable — no pin existed for the underclaiming sentence
  (review: "Not guard-pinned"; grep 'serves itself' hits docs only), so the cure is
  docs-truth-only and the guard evidence is the green-after set above. No behavioral pin
  added: the serve surface itself is pinned by SubcommandsMcpServeTest (2T/25A, unchanged).
- Nothing else touched: no VERIFIED leg re-litigated; suite-figure.json/durations.tsv/dist
  untouched (§7: this pick changes the docs surface only; ReadmeSuiteFigureDrift staleness
  pair +2T/+25A ride the lane's existing §7 entry unchanged, SubcommandsMcpServeTest LIVE
  2T/25A included).
- Lane end state: tip 1a6130f99, picks=4 off 9a0c569eb (002254741, 47d92c5bb, 81a4146c2,
  1a6130f99), porcelain 0, never pushed.

## Round-1 fixer — RESUME probe (2026-10-31): STOPPED, resume brief refuted on disk

Lead's resume claimed: tip 1a6130f99 with 3 picks off 00fedf519 + 1 dirty file; C-MCP-5.b
welded to master as 65c462c56/c538f0b13/8aeae6900 with a landed §4 ✅ mark; residual
MAJOR-1 'thirteen' prose at docs/MCP.md:12. All five points refuted by same-shell reads in
the lane worktree:
- porcelain = 0 (tree clean; nothing uncommitted — the round-1 MINOR cure landed IN FULL
  as 1a6130f99; the prior turn completed, FIX-1.md section above is its record).
- Lane is 4 picks off 9a0c569eb (git log 9a0c569eb..HEAD = 4), not 3 off 00fedf519.
- git cat-file -t: 65c462c56 and c538f0b13 DO NOT EXIST as objects; 8aeae6900 exists but
  is C-MCP-5.a's "review fixes (round 1)" commit (named in C-MCP-5.a's landed mark).
- master (d45cc64db) plan_comparison.md carries NO ✅ C-MCP-5.b mark — :3791 is the raw
  design-catalog heading; C-TOOL-4.f's own landed mark (master tip's last weld) lists
  "live siblings C-MCP-5.b ... are in flight". git cherry master cc/C-MCP-5.b prints '+'
  for all 4 — zero absorption.
- 'thirteen' appears ZERO times in both the lane file and master:candy-code/docs/MCP.md;
  line 12 is '---' on master. The claimed D1 thirteen-vs-twelve defect does not exist
  on either tree.
Per the brief's own gate (only work if the landed disclosure shows the defect open and
unclaimed — here there is no landed disclosure at all), STOPPED. Lane unchanged at
1a6130f99, clean, still WELD-READY with round-1 fix included. If C-MCP-5.b content truly
welded on a moved origin, that weld is not visible from this repo's master ref; the lead's
"thirteen/twelve" narrative matches NO artifact of this step and should be adjudicated as
suspect (possible cross-step contamination).
