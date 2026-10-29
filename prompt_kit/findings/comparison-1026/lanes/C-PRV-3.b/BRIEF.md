### C-PRV-3.b — `/fast` command (TUI and served)
- item: C-PRV-3 (sources: GAP-MOD-N2, CC parity)
- kind: gap · effort S
- approach:
  - `/fast [on|off]`: bare reports the state, and on/off toggles the live overlay.
  - The setting persists to the user tier through `SettingsWriter`, like `persistEffort()` (`Chat.php:12332`).
  - It refuses out loud when the provider/model pair has no tier, keeping the set-but-ignored ban.
  - New spec file `candy-code/builtin-commands/0360-fast.php` (per-command-disjoint, not serialized) with `withHandler('handleFastCommand')` and `withHostCommand(FastHostCommand::class)`. The served body is session-scoped like `ModelHostCommand`.
  - Add `fast` to `READ_ONLY_COMMANDS` (`Chat.php:10266`), since it never writes the session.
- files.create: `candy-code/builtin-commands/0360-fast.php`, `candy-code/src/Host/Commands/FastHostCommand.php`, `candy-code/tests/Chat/FastCommandTest.php`, `candy-code/tests/Host/Commands/FastHostCommandTest.php`
- files.modify: `candy-code/src/Chat.php` (new `handleFastCommand()`, `READ_ONLY_COMMANDS`), `candy-code/README.md` (gen slash roster), `candy-code/docs/COMMANDS.md` (gen)
- tests: FastCommandTest (on/off/status/refusal; persists only on success); FastHostCommandTest (served overlay; nothing written)
- docs/guards: `php tools/gen-command-docs.php --write`; `candy-code/tests/Commands/CommandDocsDriftTest.php`; `candy-code/tests/Config/ReadmeRosterDriftTest.php::testTheSlashCommandRosterIsExactlyWhatTheRegistryAdvertises`; `candy-code/tests/Commands/RegistryTranslationTest.php`; check `candy-code/tests/Config/ConfigWriteProducerDocumentationDriftTest.php` for a SettingsWriter-producer roster
- serialized: [chat:handleFastCommand (new), READ_ONLY_COMMANDS] [commands] [lang:cmd.fast.*, host.fast.*] [docs:COMMANDS.md] [docs:README.md]
- deps: C-PRV-3.a
- phase: normal
- ruling: none
- risks: none notable.

