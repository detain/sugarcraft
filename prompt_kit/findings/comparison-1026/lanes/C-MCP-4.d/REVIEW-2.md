# C-MCP-4.d — REVIEW-2 (round 2)

VERDICT: CLEAN

Scope: tip 3f7a2b93e = build 1ce61c449 + fixer "review fixes (round 1)" on cc/C-MCP-4.d
(base e91eb1d47). Tree clean; both commits %ae/%ce detain@interserver.net, zero
Co-Authored trailers; NOT pushed. Fresh r2 review (prior r2 session died, no verdict).

## Fix commit vs r1 findings — exactly addressed
1. MAJOR (en.php:346-353 cli.help.screen): heredoc now names
   `claude|opencode|claude-project <path> [--write user]` and states the amended law
   (prints only by default; --write user appends into the user's own
   ~/.candy-code/mcp.json; collision refuses whole write; project tier never
   written). Shape kept: 25-space description indent, all lines ≤78 cols (awk
   census), renames-on-stderr sentence preserved. Help.php consumes it via
   Lang::t('cli.help.screen') — production path verified.
2. MINOR (Subcommands.php:3427): dispatcher docblock flipped to "prints only by
   default — its sole exception is the C-MCP-4.d `--write user` door", in sync
   with the mcpImport law docblock. READ-ONLY-BY-CONSTRUCTION paragraph
   legitimately untouched (describes the mcp-list/inventory path).
Fix commit touches nothing else (2 files, +8/-3). No test flipped — fixer's pin
grep verified: "Prints only"/"writes no file" screen text asserted by no test;
DocFigure/CliLangCatalogue arms pin translation equality, survive.

## Full-diff re-check (build + fix)
- Opt-in-only: tier judged AFTER shape-check in ParsedArgs::SUBCOMMAND_FLAGS
  ('mcp'=>'--write'=>true, strict value flag, --write=v form works); any other
  tier failUsage exit 2 BEFORE any read (Subcommands.php:3886); unowned home
  exit 2 via McpDecision::mcpUserConfigPath()==='' (HomeDirectory::owned()),
  judged before the operand read.
- Collision: array_intersect_key vs existing mcpServers checked BEFORE publish;
  refusal names all colliders, md5-pin proves byte-identical file, non-colliding
  entry never lands. Corrupt/wrong-container existing refused, never repaired
  (both shapes pinned). Merge keeps file order + foreign keys (union operator).
- Writer: AtomicFileWriter::write(path, json+"\n", 0600); 0600 file + 0700 dir
  behaviorally pinned. `written` key rides the JSON envelope only on the write
  path; default path prints E710's bytes exactly (shared mcpImportDocument).
- claude-project: NESTED_CONTAINERS projects["<root>"].mcpServers, named-root
  refusal, top-level block never a fallback, read-from note — all pinned.
  --write user + claude-project still writes no repo file (scandir pin).
- Project tier never written: pinned from both source dialects.
- Guards moved in-step: StderrEmitterCensus Subcommands 38→40 with why-comment;
  ReadPathCensus OWNED_HOME row; structural write-shape pin window intact
  (write helper defined past mcpImportLine, dispatch by name only).
- Docs true: README :2250 block, MCP.md §Foreign (law amendment + no-ceremony
  risk stated once), cli.mcp.import.usage + cli.mcp.usage lines match doors.
- i18n: candy-code ships lang/en.php only — whole locale set, compliant.
- Laws: no timers/timeouts (E646), no proc_open/fork sites, zero
  suite-figure.json/durations.tsv/dist touches (+10T/2-files re-pin + DUR rows
  correctly owed to weld, disclosed in lane REPORT.md).

## Gates (reviewer-run, cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)
- php -l all 8 touched PHP files: clean.
- --filter 'SubcommandsMcpImport|Help|DocFigure': 362T/109159A, 6F — exactly the
  inherited set r1/FIX-1 disclose (HelpTest CANDY_CODE_SERVICE_TIER + 5
  KeyHelpTest; FIX-1's revert-control run byte-identical). Zero lane-attributed
  reds. (r1's "6 KeyHelpTest" wording counted the six-name shared set loosely.)
- --filter 'StderrEmitterCensus|ReadPathCensus|McpForeignTranslate|CliLangCatalogue|DuplicatedTestHelperDrift|GlobDialect|TreeWideGuardRoster|LangParity': 191T/30503A green (2 deprecations = foreign-known pair).
- PtyRunToolTest never invoked; no full suite run.

## NIT (non-blocking, no action required)
- Subcommands.php:3798 importUsage() docblock says "this verb's four doors all
  print" — five doors now print it (write_home_unowned joined). Comment-only,
  unpinned; weld may fold a one-word flip or leave.
