
## CURE-2 (fixer round 2, 2026-10-28) — MINOR (trait arm lost from commit): ALREADY-CURED ON DISK — NO AMEND NEEDED
Finding adjudication: REVIEW-2 examined tip 4c1c89e1e (the arm-lost amend). At this session's start the
branch tip was ALREADY 5b3c8cfe8 — the complete tree the review's own recipe names ("result provably
equals 5b3c8cfe8's tree"). git diff 4c1c89e1e 5b3c8cfe8 = exactly +10 lines in McpMethodsTest.php
(import + use + arm/restore + docblock note, 3x McpLaunchEnabledTrait). 4c1c89e1e is NOT an ancestor
of HEAD (dangling). Therefore: tip BEFORE = tip AFTER = 5b3c8cfe8; an amend would only churn the
committer stamp away from the reviewed-clean hash. No commit made.
Proof (worktree /home/sites/cc-cmp-C-MCP-2.b):
- porcelain at entry: `?? prompt_kit/.../C-MCP-2.b/` ONLY — no ` M` on McpMethodsTest.php; worktree file
  byte-identical to HEAD blob (cmp). Expected `M` dirt did not exist.
- blob-grep: git show HEAD:candy-code/tests/Protocol/McpMethodsTest.php | grep -c McpLaunchEnabledTrait = 3.
- hygiene: %ae grep -c detain = 1; %ce grep -c detain = 1; trailers (co-authored|caliber) = 0;
  rev-list master..HEAD = 1; parent 4b771634d == master tip.
- gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS):
  'McpMethods|CommandMethodsMcpPrompts' -> OK Tests: 13, Assertions: 99 (matches REVIEW-2 exact).
  CANDY_CODE_MCP_DISABLE=1 'McpMethodsTest' -> OK Tests: 5, Assertions: 21 (trait-armed run under the
    gated shard-runner env — the NIT's substantive concern, green with arm committed).
  'CommandMethods|SessionHostCommands|ServedProjectCommand' -> OK Tests: 30, Assertions: 168 (matches REVIEW-2).
  Note: all three runs report "PHPUnit Deprecations: 2" — environmental/pre-existing (count identical in
  filters not touching the lane's files), not gate-failing, not introduced by this lane.
- No stash; no suite-figure/durations/dist touches; nothing pushed.
