### C-CTX-3.a — `/instructions [open <n|path>]`
- item: C-CTX-3 (sources: GAP-INS-16)
- kind: gap · effort M
- approach:
  - `/memory open <id>` edits memory notes only, and nothing opens CLAUDE.md or AGENTS.md (`EditorCommand::openFile()` has one caller).
  - Add `/instructions`. It lists the session's instruction files with tier and bytes: the same rows as `ContextSources::priceInstructionFiles()` (`ContextSources.php:98`), from `loadDocuments()` + `loadPersonal()`.
  - `/instructions open <n|path>` opens the pick through `EditorCommand::openFile()`. It is jailed to the listed paths, plus the personal file, which it creates if missing.
  - The edits reach the model through `InstructionDiffTracker` (C-CTX-1.a).
  - Spec file `candy-code/builtin-commands/2405-instructions.php` (new, per-command-disjoint) with `withHandler('handleInstructionsCommand')` and `withHostCommand(InstructionsHostCommand::class)`. Served, it lists the rows, and `open` answers "needs a terminal" like `/memory open`.
  - `instructions` joins `READ_ONLY_COMMANDS`.
- files.create: `candy-code/builtin-commands/2405-instructions.php`, `candy-code/src/Commands/InstructionsCommand.php`, `candy-code/src/Host/Commands/InstructionsHostCommand.php`, `candy-code/tests/Commands/InstructionsCommandTest.php`
- files.modify: `candy-code/src/Chat.php` (new `handleInstructionsCommand()`, open arm sharing `memoryOpenArm()`'s shape, `READ_ONLY_COMMANDS`), `candy-code/src/Host/ContextSources.php` (expose the row list for reuse), `candy-code/README.md` (gen), `candy-code/docs/COMMANDS.md` (gen), `candy-code/docs/MEMORY.md`
- tests: InstructionsCommandTest: list; open-by-index; a path outside the list is refused; served `open` says it needs a terminal
- docs/guards: `php tools/gen-command-docs.php --write`; `CommandDocsDriftTest`; `ReadmeRosterDriftTest::testTheSlashCommandRosterIsExactlyWhatTheRegistryAdvertises`; `RegistryTranslationTest`
- serialized: [chat:handleInstructionsCommand (new), memoryOpenArm region, READ_ONLY_COMMANDS] [commands] [lang:cmd.instructions.*, host.instructions.*] [docs:COMMANDS.md] [docs:README.md] [docs:MEMORY.md] [hot:candy-code/src/Commands/EditorCommand.php] [hot:candy-code/src/Host/ContextSources.php]
- deps: C-CTX-6.d (same Chat region and EditorCommand), C-CTX-1.a (edits must reach the model on the fork path)
- phase: normal
- ruling: none
- risks: P-CTX-BREAKDOWN also reads `ContextSources`. Keep the row shape additive.

