# C-MCP-3.d — Long MCP calls continue in background — builder DONE

FINISHER run (prior session aborted with real uncommitted work; all 8 dirty files kept,
reviewed against the brief, verified sound, skeleton-committed first, then completed).

## Commits (branch cc/C-MCP-3.d, base 6742a2ad3; both cat-file-verified, %ae+%ce detain, 0 trailers)
- c3de5c442 C-MCP-3.d: skeleton — registry (src/MCP/McpBackgroundCalls.php), hand-off
  wiring (McpToolBridge + McpCallTool executeWithHeartbeat/runExchange/runDispatch split),
  inbox (src/Backend/McpBackgroundInbox.php composed via CompositeTurnInbox::of at the
  EngineBackend turn site), arming at the turn-bound tool loop, lang mcp.background.* (3),
  MCP.md §Server requests prose, TurnInbox docblock truth-flip. 8 files, +711/−5.
- 9b34f63bd tests/MCP/McpBackgroundCallTest.php (8T/49A) + in-step TreeWideGuardRoster
  row (scandir($registry), WALKS_A_DIRECTORY_THE_TEST_MADE). +391.
Net lane diffstat: 10 files, +1102/−5.

## Shape notes (kept from the aborted builder; every seam disk-verified)
- 120 s threshold = McpBackgroundCalls::DEFAULT_AFTER_SECONDS, injectable per-tool via
  withBackgroundAfter(); armed() doors: threshold>0, NOT the -p elicitationsClosed latch,
  pcntl present, owned home (Windows/pcntl-less/no-home all degrade to the byte-identical
  inline path). E646 honored: threshold releases the TURN, never kills the child; child's
  bound stays toolTimeout (doc-disclosed).
- Fast call: renders inside the forked child and is returned at the threshold — byte-pin
  test compares armed vs plain ToolResult content/isError/toolCallId.
- Registry: <home>/.candy-code/mcp-background, 0600 .writing→rename .payload publish;
  claim = rename .payload.taken, unlink after read; >30 s stale .taken restored before
  claim (delivery-preserving GC); delivery = UserMessage rows through McpBackgroundInbox.
- No new proc_open anywhere (pcntl_fork only, house ForkedChild::exitNow terminus —
  ForkedChildExitConventionTest green on the new site; no DescriptorInheritanceGuard row
  needed). §6 ruling (background stdio allowed, later calls queue behind the lock) is
  what the MCP.md paragraph states.

## Gates (cwd candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)
- php -l: all 10 touched files clean.
- Extended targeted filter (McpBackgroundCallTest|McpToolBridge|McpCallTool|ForkedChild|
  LangParity|TurnInbox|GlobDialect|DocFigureProseDrift|DuplicatedTestHelperDrift|
  McpElicitation|NonInteractiveMcpToolsHeadless|TreeWideGuardRoster):
  308T/12213A, failures: 1 = ForkedChildReaperAdoptionTest flagging
  Sessions/BackgroundShellRegistryTest.php — PRE-EXISTING BASE RED (file + guard +
  scanner byte-identical 6742a2ad3..main-HEAD 86f80fef8; the same test fails in the main
  tree; not this lane's file; owner/wave-loop glance owed).
- McpBackgroundCallTest alone: 8T/49A green (×3 runs incl post-mutation restores).
- Mutations: M1 armed()-neuter → exactly 4 hand-off tests red; M2 inbox drain-neuter →
  exactly the delivery test red; cp+md5 restores verified (4f7e8588…/9680b824…).
- Pre-existing 2 PHPUnit Deprecations in every window (reproduced on clean main tree).

## Trio regen-verify: `php scripts/gen-protocol-schema.php --write` → ZERO drift (lane
touches no protocol surface); nothing to restore; weld regenerates once per wave as usual.
Web untouched → no vitest. suite-figure.json / durations.tsv / dist never touched.

## New test file (durations carry): candy-code/tests/MCP/McpBackgroundCallTest.php (8T).

## Seams left
- ForkedChildReaperAdoptionTest base red (above) — not repairable inside this lane's files.
- Background hand-off beats at most 1/s (first at +1 s); sub-second thresholds never
  beat — matches the turn idle-ceiling semantics, untested on purpose (needs >1 s wall).
- A child killed before publish yields the 'lost' error row (pinned via direct await()).
