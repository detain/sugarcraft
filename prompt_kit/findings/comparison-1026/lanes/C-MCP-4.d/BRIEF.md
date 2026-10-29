### C-MCP-4.d — `mcp import --write user`
- item: C-MCP-4 (sources: GAP-MCP-15)
- kind: gap · effort S
- approach: `mcp import` is print-only by law (`candy-code/src/Cli/Subcommands.php:2348-2440`, "nothing_written").
  - Opt-in `--write user` appends the translated entries into `~/.candy-code/mcp.json` through `Support/AtomicFileWriter` (0600; the owned-home gate `HomeDirectory::owned()`).
  - A name already present is refused, and the whole write aborts listing the collisions (never a merge).
  - Per-project `~/.claude.json` `projects[<root>].mcpServers` is added as an import source (`McpForeignTranslate::CONTAINERS`).
  - The project tier is never written.
- files.create: `candy-code/tests/Cli/SubcommandsMcpImportWriteTest.php`
- files.modify: `candy-code/src/Cli/Subcommands.php` (`mcpImport`, `importUsage`), `candy-code/src/Cli/ParsedArgs.php` (`SUBCOMMAND_FLAGS` gains an `mcp` `--write` entry), `candy-code/src/MCP/McpForeignTranslate.php` (`CONTAINERS` + project-scoped source), `candy-code/lang/en.php` (`cli.mcp.import.*`, `cli.mcp.usage`), `candy-code/README.md` (import block at :2111), `candy-code/docs/MCP.md` (§"Foreign spellings that are read": write mode)
- tests: create `candy-code/tests/Cli/SubcommandsMcpImportWriteTest.php` (writes the user file only; collision refusal writes nothing; mode 0600; an unowned home refuses). Modify `candy-code/tests/Cli/SubcommandsMcpImportTest.php` (the print path is unchanged). Modify `candy-code/tests/Cli/StderrEmitterCensusTest.php` (DIRECT_SITES re-pin for the new lines).
- docs/guards: `candy-code/tests/Support/ReadPathCensusTest.php` row for the new read of the existing user file (or reuse `McpDecision`'s read); `DocFigureProseDriftTest::testMcpServerTypesKeysAndEnvInterpolationMatchTheFactory` (the alias rows stay); the README import block.
- serialized: [headless:Subcommands,ParsedArgs] [lang:cli.mcp.import.] [docs:README.md] [docs:MCP.md §Foreign spellings]
- deps: none
- phase: normal
- ruling: Relax the "import never writes" law for an explicit `--write user` (user tier only, collisions refused)?
- risks: the import adds servers to every future launch with no repo ceremony, which is the user tier's existing posture, so the doc must say so.

