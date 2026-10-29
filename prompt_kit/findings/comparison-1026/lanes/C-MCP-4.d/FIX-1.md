# C-MCP-4.d — FIX-1 (round 1)

Commit 3f7a2b93e "C-MCP-4.d: review fixes (round 1)" on cc/C-MCP-4.d, stacked on
lane tip 1ce61c449 (verified HEAD~1 == 1ce61c449c23301cc6586a13f36110cbb69ab9af;
cat-file OK; tree clean; %ae/%ce both detain, zero Co-Authored trailers; NOT pushed).

## Finding 1 (MAJOR) — candy-code/lang/en.php:346-353 (cli.help.screen heredoc)
Rewrote the `mcp import` help-screen entry: header now names
`claude|opencode|claude-project <path> [--write user]`; body states the amended law —
prints only by default; `--write user` appends the translated entries into the user's
own ~/.candy-code/mcp.json (a name collision refuses the whole write, and the project
tier is never written); renames-on-stderr sentence preserved. All 8 lines ≤75 cols,
25-space description indent kept (awk length census).

## Finding 2 (MINOR) — candy-code/src/Cli/Subcommands.php:3427
Dispatcher docblock flipped from "the print-only foreign-config translator" to
"the foreign-config translator that prints only by default — its sole exception is
the C-MCP-4.d `--write user` door". "READ-ONLY BY CONSTRUCTION" left untouched
(it describes the mcp-list path via mcpServerInventory, and was outside the finding).

## Pin greps (before editing)
- "Prints only" / "writes no file": hits only in lang/en.php (the edited line) +
  unrelated prose in ApplySettingsTest/BinCandyCodeDispatchTest/KeyboardHandlerTest/
  TruncatesOutput/SettingsWriter/OAuthLoopbackFlow — NONE assert the screen text.
- "mcp import claude|opencode <path>" in tests/: zero.
- HelpTest reads the screen but pins env-roster names / exit-code wording only;
  DocFigureProseDriftTest:4077 pins only en.php<->T::translate EQUALITY (survives);
  CliLangCatalogue pins translations, not this sentence. Reviewer's "no guard catches
  the lie" CONFIRMED — no test flipped (none to flip).

## Gates (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)
- php -l both files: clean.
- --filter 'Help' WITH fixes: 253T/104919A/6F; control run at HEAD (fixes reverted
  via git checkout, restored after) BYTE-IDENTICAL 253T/104919A/6F with the SAME six
  names (HelpTest CANDY_CODE_SERVICE_TIER + 5 KeyHelpTest) = the reviewer-disclosed
  inherited set. Zero new failures; fixes provably neutral there.
- --filter 'SubcommandsMcpImport': OK 18T/181A (exact match to r1 lane figure).
- --filter 'DocFigure': OK 92T/4108A (2 PHPUnit deprecations = foreign-known pair,
  present in every r1 run).
- No full suite run; PtyRunToolTest never invoked. No suite-figure.json/durations.tsv/
  dist touches. Backup copies in /tmp/opencode/cmc-fix1/ (no stash).
