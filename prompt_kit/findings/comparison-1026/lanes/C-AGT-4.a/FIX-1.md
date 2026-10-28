# C-AGT-4.a — Round-1 fix notes

Verdict: all 5 findings cured. Branch cc/C-AGT-4.a, single amended commit
1cee965710f8bf71939c5f00bf4ed866f0075252 (was b065962c3) on base 63f2de5d9,
22 files, porcelain clean, A/C = detain@interserver.net, zero trailers.

## Finding → cure → test

- MAJOR-1 (lenient foreign `mode` parse unpinned):
  tests/Agents/ForeignAgentPresetRegistryTest.php +2 tests —
  testDiscoverClaudeCarriesDeclaredModeAndReadsForeignSpellingsLeniently and
  testDiscoverOpencodeCarriesDeclaredModeAndReadsForeignSpellingsLeniently:
  declared `mode: primary`/`mode: subagent` honored, `mode: nonsense` → All,
  absent `mode:` → All. Mutation proofs: delete claudePreset mode line →
  claude test red; delete opencodePreset mode line → opencode test red;
  replace `?? AgentMode::All` with throw → lenient legs red. All restored, md5-clean.
- MINOR-1 (docs/AGENTS_AUTHORING.md "carries all eighteen onto the row" FALSE):
  page rewritten — every one of the eighteen accounted for, seventeen carried
  onto the `Agent` row, `mode` read and stopped at the preset. The DocFigure arm
  testAgentsAuthoringPresetRosterDividesTheRegistryItNames re-anchored IN-STEP and
  widened with a carry-count leg (seventeen == 18−uncarried). Mutation: doc
  'seventeen carried'→'eighteen carried' → exactly the arm red at that leg.
  Grep confirmed no other guard needle quoted the old sentence.
- MINOR-2 (no ordering pin): tests/Agents/PrimaryAgentTest.php
  testTheAgentSlotRidesBehindToolGuidanceAndBeforeTheRepoMap — walks
  Runtime::systemPromptSections() with a PromptGuidance tool + agent instructions,
  asserts guidance-index < agent-index < repo-map-index. Mutation: move the
  `<agent-instructions>` slot after repoMapSnapshot in src/Runtime.php → exactly
  this test red (1F/10T). Restored md5-clean.
- MINOR-3 (gate ledger unreproducible): REPORT.md now carries a "Round-1
  corrections (disk-truth)" section — original A/B/C claims preserved with
  withdrawal note (brief's A filter is 456T/1944A; B/C filters absent); round-1
  gates listed with real figures.
- NIT-1: stray blank line between resolveGrantedTools docblock and signature in
  src/Agents/AgentManager.php removed (adjacency blob-verified at HEAD).

## Round-1 gates (plain pipe, env -u LINES -u COLUMNS, cwd worktree candy-code)

PrimaryAgent 10T/45A · ForeignAgentPreset family 47T/151A ·
PrimaryAgent|ForeignAgentPreset 94T/313A · FieldCarry|BaseSystemPrompt|
PromptSection|FrontmatterKeyAudit 104T/773A · DocFigureProseDrift 91T/3980A.
All OK; the 2 PHPUnit deprecations are FOREIGN (tests/Config/
AccessorDeprecationTest providers, pre-existing at base).
