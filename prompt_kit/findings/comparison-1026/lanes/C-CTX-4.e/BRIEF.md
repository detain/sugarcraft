### C-CTX-4.e — Output styles (`/output-style`)
- item: C-CTX-4 (sources: GAP-PRM-10)
- kind: gap · effort M
- approach:
  - Styles are rulebook-kind files in `~/.candy-code/styles/*.md`, and in `.candy-code/styles/*.md` for the project.
  - `RuleLoader` loads them with a `style` flag on `Rule`, and `RulesState` keeps exactly one style active per session.
  - They ride the loader path that Runtime already hands the `/rules` toggle set to (`Runtime.php:4603-4616`), so there is no Runtime edit. They are fenced as user rules and never override the base prompt (house doctrine). Switching styles is a refresh point, and the docs say so.
  - `/output-style [name|off]` lists, sets or clears the style. New spec `candy-code/builtin-commands/1405-output-style.php` (per-command-disjoint) with `withHandler('handleOutputStyleCommand')` and `withHostCommand(OutputStyleHostCommand::class)`.
- files.create: `candy-code/builtin-commands/1405-output-style.php`, `candy-code/src/Host/Commands/OutputStyleHostCommand.php`, `candy-code/tests/Context/OutputStyleTest.php`
- files.modify: `candy-code/src/Context/Rule.php` (`style` flag), `candy-code/src/Context/RuleLoader.php` (styles dirs), `candy-code/src/Context/RulesState.php` (active style), `candy-code/src/Chat.php` (new `handleOutputStyleCommand()`), `candy-code/README.md` (gen), `candy-code/docs/COMMANDS.md` (gen), `candy-code/docs/MEMORY.md` (rules tiers)
- tests: OutputStyleTest: one active at a time; `off` clears it; a style is present in the splice and the prefix moves only on a switch; served command parity
- docs/guards: command gen; `CommandDocsDriftTest`; `ReadmeRosterDriftTest` slash roster; `RegistryTranslationTest`
- serialized: [chat:handleOutputStyleCommand (new)] [lang:cmd.output-style.*, host.output_style.*] [docs:COMMANDS.md] [docs:README.md] [docs:MEMORY.md] [hot:candy-code/src/Context/Rule.php] [hot:candy-code/src/Context/RuleLoader.php] [hot:candy-code/src/Context/RulesState.php]
- deps: none
- phase: normal
- ruling: none
- risks: if the rulebook splice orders styles wrongly, a dedicated section would need `[runtime]`. The lane must report this rather than widen the step silently.

### C-CTX-4.f — Project references (extra read-only roots)
