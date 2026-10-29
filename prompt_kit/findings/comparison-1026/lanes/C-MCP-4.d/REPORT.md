# C-MCP-4.d — REPORT

LANDED. `candy-code mcp import` gains its opt-in `--write user` door — the E710
print-only law is amended exactly as plan §6.1 rules: user tier only, collisions
refuse the whole write, project tier never written. `claude-project` joined
CONTAINERS as a READ source for `~/.claude.json`'s per-project
`projects["<root>"].mcpServers` block.

## Files
- `src/Cli/ParsedArgs.php` — SUBCOMMAND_FLAGS gains `'mcp' => ['--write' => true]`
  (value flag; the verb judges the value, the parser stays filesystem-blind).
- `src/MCP/McpForeignTranslate.php` — CONTAINERS += `claude-project`; new
  NESTED_CONTAINERS; `translateDocument(..., ?string $projectRoot = null)` reads
  the per-project block, refuses (named) without a root or without the block,
  notes the read; alias/split/entry normalisation shared with the flat path.
- `src/Cli/Subcommands.php` — mcpImport docblock states the amended law + risk;
  two exit-2 doors (unknown tier; unowned home) fire before anything is attempted;
  translate gets the ambient `--root`/cwd; new `mcpImportDocument()` (shared emit,
  `written` key on the write path only) and `mcpImportWriteUser()` (bare
  `@file_get_contents` of the existing owned user file, refused-never-repaired on
  garbage, whole-write collision abort listing names, merge append, AtomicFileWriter
  0600). All write shapes live OUTSIDE the structural-pin window by method placement.
- `lang/en.php` — usage strings now `claude|opencode|claude-project <path>
  [--write user]` (import usage + cli.mcp.usage); new keys collisions / existing_unreadable
  / write_failed / write_home_unowned / write_unknown_tier / write_usage / written
  (en is the only locale shipped for this family today).
- `README.md` :2250 block + `docs/MCP.md` §Foreign — both spell the new sources and
  the flag, and MCP.md states the LAW CHANGE and the no-repo-ceremony RISK explicitly.
- `tests/Cli/SubcommandsMcpImportTest.php` — print-path suite: assertions byte-identical
  (all green); docblock/comments truth-flipped to the amended law.
- `tests/Cli/SubcommandsMcpImportWriteTest.php` — NEW, 10 tests / 115 assertions.
- `tests/Cli/StderrEmitterCensusTest.php` — Subcommands.php MESSAGE_SHAPES 38 → 40
  (the two --write door literals; DIRECT_SITES stays 6 — every note rides the
  mcpImportLine funnel), dated comment.
- `tests/Support/ReadPathCensusTest.php` — operand row truth-flipped; new OWNED_HOME
  row for the merge-read (sites 8 = rows 8).

## Gates (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)
- SubcommandsMcpImportTest + SubcommandsMcpImportWriteTest together: OK 18 tests, 181 assertions.
- StderrEmitterCensusTest: OK 95 tests, 22205 assertions (after re-pin).
- ReadPathCensusTest: OK 19 tests, 680 assertions.
- McpForeignTranslateTest: OK 16/35. BinCandyCodeDispatchTest --filter Import: OK 2/13.
- LangParityTest OK 7/3132; CliLangCatalogueTest OK 8/208; DocFigureProseDriftTest OK 92/4108;
  GlobDialectDifferentialTest OK 13/366; DuplicatedTestHelperDriftTest OK 14/197;
  ReadmeRosterDrift 10/78, KeyBindingDrift 224/1836, EnvRosterDrift 37/39678,
  ConfigWriteProducerDrift 15/34, TrustKeyDrift 12/553 — all OK.
- Five-guard window: only the two expected staleness reds (pinned 25,537 vs live
  27,955 tests figure) — re-pin is the closeout battery's duty; suite-figure.json
  and durations.tsv untouched per law.

## Mutation proofs (cp-backup + md5-verified restores, b734f859…)
- M1 collision gate → `if (false)`: reds EXACTLY testACollisionRefusesTheWholeWriteAndChangesNothing.
- M2 AtomicFileWriter mode 0600 → null: reds exactly the three mode-asserting tests
  (flag-publish, append-survive, claude-project-write); refusals still green (they never reach the write).
- M3 drop the `written` envelope key: reds exactly the flag-publish written-line.

## Weld carries
- DUR row owed: `candy-code/tests/Cli/SubcommandsMcpImportWriteTest.php` (10 tests).
- Suite-figure re-pin carries +10T from this step (inside the standing staleness pair).
- BASE-INHERITED RED, not this lane: HelpTest::testEveryRosterVariableIsDocumented…
  data set CANDY_CODE_SERVICE_TIER — the LaunchContext readers exist at base
  e91eb1d47 (git show proves 2) while Help.php names it 0 times there and on
  origin/master b9a3b0fff; my diff touches neither file. Ruling owed to the owner
  lane that minted the tier env family (add the Help entry or an UNDOCUMENTED row).
- Parser seam disclosed: strict value-flag errors for `--write` (missing/flag-shaped
  value) are raised in bin/candy-code, not in Subcommands::dispatch — in-process the
  dropped flag leaves the verb printing (pinned honestly in test #7; the real-bin
  shape is the bin's existing argv-door, unchanged by this step).
- Sibling lanes: no web files touched (C-MCP-2.c owns those); en.php appended only
  under cli.mcp.* keys; ParsedArgs/Subcommands edits inside the serialized regions named for this step.
