# C-MCP-3.b — DONE (salvage-and-finish)

VERDICT: LANDED. Real tip `828b709ea8586eae4bc9e1bea141b16cf01e1690` on `cc/C-MCP-3.b`
(cat-file commit; porcelain 0; master..HEAD == 1; author+committer detain@interserver.net
via split-var, zero trailers). The prior session's fabricated `d7d008566` never existed;
this commit supersedes that record.

## Shipped (9 files, +1252/-11)
- McpToolBridge + McpCallTool implement RelaysPermissionAsks + AcceptsToolProgress:
  per-call McpServerRequests desk; elicitation answered in the AskUser shape via the
  turn's approver (lang key `mcp.elicitation.ask`, en-only — lang/ has no other locales);
  progress→ToolProgress tails (scrubbed server message else pacing; 4096-byte budget,
  last-bytes-kept); sticky `-p` closure law.
- EngineBackend::turnTools binds the approver in BOTH the AcceptsToolProgress arm and the
  plain arm (TaskTool excluded). NonInteractive::withoutInteractiveUser closes both MCP
  spellings. AskUserTool prompt/typedAnswer/pickedOption/label hoisted public (reuse, not imitation).
- docs/MCP.md new §"Server requests" (ping, roots two-readings disclosed, elicitation-as-
  questions incl. decline/cancel fail-closed + clock pause, progress, refusals incl. sampling,
  http inert-desk note). McpCallTool perCallDesk docblock lie fixed (frames carry the
  `mcp_call` row key itself — `mcp_call` IS the running row).

## NEW test files (welder durations.tsv duty)
- candy-code/tests/Tools/McpToolProgressTest.php — 15T
- candy-code/tests/Cli/NonInteractiveMcpToolsHeadlessTest.php — 4T

## Gates (all cwd=candy-code, plain-pipe, env -u LINES -u COLUMNS)
- Family McpTool|McpCallTool|AskUser|NonInteractive: 356T/1587A OK.
- Combined family+guards (DocFigure/McpStartupDoc/TrustKey/LangParity/ShellOwnership/
  StderrEmitterCensus/ReadPathCensus/TreeWideGuardRoster/DuplicatedTestHelper/DuplicatedDocBlock/
  SymbolCitation/GlobDialect/BuiltInToolCorpus): 705T/52611A OK.
- 2 PHPUnit Deprecations pre-existing on this box (reproduces on untouched AskUserToolTest).

## Red-before proofs
- Base-swap (4 src files @ e4a705244): 14E/3F of 19 new tests.
- M1 -p-close branch neutered: 2F; M2 turnTools binds neutered (both arms): 2F;
  M5 progress-sink legs neutered (both files): 4F; restores cmp+md5-clean.
- Sticky-close is DOUBLE-GATED (withPermissionApprover early-return + perCallDesk flag read);
  each leg alone keeps tests green (belt-braces, honest note); both legs removed (M4): 3F.

## Deviations
- Per-call desk roots at '' → process cwd (spawn-time desk keeps the entry tier dir);
  both readings disclosed in MCP.md §Server requests + docblocks.
- GlobDialect corpus: integer-coerce regex respelled `[+-]{0,1}` (lane-fj precedent) —
  src/Util/PathGlob.php census untouched, corpus byte-identical to base figure 304,700.
- suite-figure.json/durations.tsv/dist/plan files NOT staged (never run caliber).
