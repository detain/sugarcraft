# C-SES-8.e — `candy-code usage` CLI verb (W26, gap/NIT, effort S)

Design block (verbatim from `design-ses.md`):

- item: C-SES-8 (sources: OBS-3-P1, p2-miss: optional `candy-code usage` verb)
- kind: gap · NIT · effort S
- approach:
  - `candy-code usage [7|30|all] [--session <id>] [--output-format json]` prints `UsageCommand::compose(UsageHostCommand::rollupFacts(...))`.
  - JSON mode emits the facts map.
  - The verb moves `ParsedArgs::SUBCOMMANDS`, the completion verb table (`cli.completion.verb.usage`) and the Help text.
- files.create: `candy-code/tests/Cli/UsageSubcommandTest.php`
- files.modify: `candy-code/src/Cli/ParsedArgs.php` (SUBCOMMANDS), `candy-code/src/Cli/ArgvParser.php` (verb parse, if verbs are listed there), `candy-code/src/Cli/Subcommands.php` (dispatch, usage(), SUBCOMMAND_VERBS), `candy-code/lang/en.php` (`cli.usage.*`, `cli.completion.verb.usage`), `candy-code/README.md` (the Subcommands fence)
- tests: create `candy-code/tests/Cli/UsageSubcommandTest.php`: text and JSON; unpriced `≥`; no store gives a reason. Modify `candy-code/tests/Integration/BinCandyCodeDispatchTest.php` (the verb row).
- docs/guards: the README Subcommands fence → `ReadmeRosterDriftTest::testTheSubcommandFenceNamesEveryVerbTheParserAccepts`
- serialized: [headless] [lang:cli.usage,cli.completion.verb.usage] [docs:README.md]
- deps: C-SES-9.a
- phase: normal
- ruling: none
- risks: none notable

## Base / environment

Cut off master `d79502693`. Branch `cc/C-SES-8.e`. Worktree `/home/sites/cc-cmp-C-SES-8.e`.
`candy-code/vendor` cp-seeded linked, autoload verified — composer is broken host-wide, do NOT run composer or `scripts/refresh-deps.php`.

## Lead notes

- Dep C-SES-9.a IS welded (§4 `✅ 98b862e9f` — ledger: no resurrection, `ts` index, one-snapshot rollups). `UsageHostCommand::rollupFacts()` and `UsageCommand::compose(array $facts): string` (src/Commands/UsageCommand.php:72) are both present on master — re-derive signatures live.
- The `rollup` window semantics come from C-SES-8.a's `days` forwarding; `[7|30|all]` must map onto the same window contract rather than inventing a second one.
- Precedent to mirror closely: `candy-code session import` (C-SES-7.b, now welded at a6c45be43) — same ParsedArgs `SUBCOMMAND_FLAGS`/verb-table/lang/README-fence shape, and it added the verb into `cli.session.usage`'s one string. Check whether `usage` belongs in a `SUBCOMMAND_FLAGS` region or the top-level verb list before editing.
- ReadmeRosterDrift's subcommand-fence arm is the binding guard: the README fence must name the verb or the guard reddens.
- Unpriced rows keep the `≥` framing already established in the TUI panel — do not print a bare `$` figure for an unpriced model.
- ParsedArgs.php / Subcommands.php are shared-serialized files across lanes; stay inside the declared regions.
