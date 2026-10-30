### C-SES-7.b — `candy-code session import` verb
- item: C-SES-7 (sources: GAP-SES-10, p2-miss: session import)
- kind: gap · effort S
- approach: Add `candy-code session import <file> [--format auto|candy-code|claude-code|codex|opencode] [--name <n>] [--output-format json]`. It runs the C-SES-7.a importer and prints the new id plus a `--resume` hint.
  - `Subcommands::session()` gains the `import` arm, a `SUBCOMMAND_ACTIONS['session']` entry and the flag table (`:1089`).
  - It refuses while the target session is lock-held, the same way the other `session` verbs do.
- files.create: `candy-code/tests/Cli/SessionImportSubcommandTest.php`
- files.modify: `candy-code/src/Cli/Subcommands.php` (session(), sessionUsage(), the session flag table, SUBCOMMAND_ACTIONS), `candy-code/lang/en.php` (`cli.session.import.*`), `candy-code/README.md` (the Subcommands `session` block)
- tests: create `candy-code/tests/Cli/SessionImportSubcommandTest.php`: happy path text and JSON; unknown format exits 2; a bounds refusal exits 1. Modify `candy-code/tests/Cli/SessionSubcommandsTest.php` if it pins the action list.
- docs/guards: the README Subcommands block. `ReadmeRosterDriftTest` pins top-level verbs only, and `session` already exists.
- serialized: [headless] [lang:cli.session.import] [docs:README.md]
- deps: C-SES-7.a
- phase: normal
- ruling: none
- risks: none notable

