# C-MCP-4.d — REVIEW-1 (round 1)

VERDICT: FINDINGS

Scope: 1 commit 1ce61c449 on cc/C-MCP-4.d (base e91eb1d47), 11 files, tree clean,
author/committer detain@interserver.net, no Co-Authored trailer.

## Brief coverage — landed
- `--write user` door: opt-in by spelling (parsed value flag `mcp` scope in
  ParsedArgs::SUBCOMMAND_FLAGS; any other tier → exit 2 failUsage before any
  read/write). `Subcommands.php:3879-3905`.
- User-tier only: writes `McpDecision::mcpUserConfigPath()` (owned-home gate,
  '' → exit 2, tested with chmod-0777 + clearstatcache); project tier never
  written (`claude-project` + `--write user` pins scandir unchanged).
- Collisions refuse the whole write, list the names, file byte-identical
  (md5 pin); corrupt existing refused-never-repaired; merge keeps existing
  order + foreign keys. `mcpImportWriteUser()` (Subcommands.php:4079+), placed
  past the structural-pin window (mcpImport→mcpImportLine) so the print-path
  write-shape pin still scans clean — verified by placement + green suite.
- House writer + perms: `AtomicFileWriter::write(path, json, 0600)` (parent
  dir 0700); mode 0600 behaviorally pinned. `written` key rides the JSON
  envelope only on the write path; refusal carries no partial document.
- `claude-project` read source: CONTAINERS + NESTED_CONTAINERS
  `projects["<root>"].mcpServers`, named-root refusal when absent, top-level
  block never a fallback, read-from note. `McpForeignTranslate.php`.
- Tests: new `SubcommandsMcpImportWriteTest` 10T/115A (all brief-required
  behaviors incl. unowned-home, mode, collision-abort, default-path-no-write);
  print-path file truth-flipped, assertions intact.
- Docs: README :2250 block, docs/MCP.md §Foreign write-mode paragraphs
  (law change + no-ceremony risk stated), cli.mcp.import.usage + cli.mcp.usage
  updated. (Brief's README ":2111" cite drifted; block located and edited.)

## Gates (reviewer-run, cwd=candy-code -c phpunit.xml, plain pipe)
- php -l all 5 touched PHP files: clean.
- `--filter SubcommandsMcpImport` (both classes): OK 18T/181A (exact match to
  builder claim). `WriteTest` alone: OK 10T/115A.
- `StderrEmitterCensusTest|ReadPathCensusTest|McpForeignTranslateTest|LangParity|CliLangCatalogue|DuplicatedTestHelperDrift|GlobDialect|TreeWideGuardRoster`: OK 189T/30494A.
- `DocFigure|ReadmeRoster|EnvRoster|KeyBinding|TrustKey|ConfigWriteProducer` drift: OK 363T/45700A.
- Inherited reds, NOT this lane (reproduced identically at current master
  6742a2ad3; lane diff contains zero `worktree`/Help.php/Renderer touches):
  HelpTest CANDY_CODE_SERVICE_TIER (builder-disclosed) + 6 KeyHelpTest
  failures + `BinCandyCodeDispatchTest::testEveryVerbTheParserAcceptsIsOfferedByAllThreeCompletions`
  (--worktree offered-by-no-completion; out-of-scope, flag to weld/owner).
  2 PHPUnit deprecations = foreign AccessorDeprecationTest providers.

## Law checks
- E646: no timers/timeouts added. No proc_open/fork sites. No
  suite-figure.json/durations.tsv/dist touches (weld carries the DUR row +
  +10T re-pin, correctly disclosed in REPORT). i18n: candy-code ships en.php
  only — en-only is the whole-locale set here, compliant.
- GlobDialect: no glob-shaped literals introduced (guard green).
- Security posture: existing-file read is OWNED_HOME (bare `@file_get_contents`
  census row added and matched); refusal wording honest everywhere tested;
  commit REPORT.md claims verified against disk, no fabrication found.

## Findings
1. MAJOR — candy-code/lang/en.php:346-350 (`cli.help.screen` heredoc). The
   flagship help screen still reads "mcp import claude|opencode <path>" and
   "Prints only the document and writes no file" — falsified by this very
   commit (no guard catches it; DocFigure only pins translation equality).
   Fix: rewrite that entry to name `claude|opencode|claude-project <path>
   [--write user]` and state the amended law: prints unless `--write user`
   appends the translated entries into the user's own ~/.candy-code/mcp.json
   (collisions refuse the whole write; the project tier is never written),
   keeping the 80-col heredoc shape.
2. MINOR (step-local) — candy-code/src/Cli/Subcommands.php:3427. The `mcp`
   dispatcher docblock still calls the verb "the print-only foreign-config
   translator"; flip to "print-only by default" (or point at the C-MCP-4.d
   exception) to keep comment truth in sync with the :3800 law docblock.
