### C-EXT-5.c — Key-bindable slash commands (`command:<name>`)
- item: C-EXT-5 (sources: CC-new#12 key-bindable slash commands)
- kind: gap · effort M
- approach:
  - **Setting.** `keybindings` accepts `"command:<name>": "<chord>"`. `KeyBindingsValidator::validate()` (:42) checks the name syntactically (colon allowed, for bundle and MCP names) and the chord with the existing parser. `collisionNotices()` reports a collision with a shipped chord.
  - **Registry.** `KeyBindingRegistry::useOverrides()` splits `command:` ids into a command map whose memo is cleared in `resetDerived()`, so `KeysReloadTest`'s memo census stays honest. It adds `commandFor(KeyMsg)` and `commandBindings()`.
  - **Dispatch.** `App::handleKey()` (:3500) consults `commandFor()` before `honourKeybindingAliases()`. A hit calls `$this->chat->runCommand('/'.$name)`, the menu-bar idiom (App.php:4058), so mid-turn refusal and draft preservation come for free.
  - **Display.** `/keys` lists the user's command bindings under their own heading. `/keys reload` re-applies them.
  - The static `KeyBindingRegistry::all()` rows are untouched, so `KeyBindingDriftTest` does not move.
- files.create: none
- files.modify: `candy-code/src/Commands/KeyBindingRegistry.php`, `candy-code/src/Config/Settings/Validator/KeyBindingsValidator.php`, `candy-code/src/App/App.php` (`handleKey`), `candy-code/src/Renderer.php` (`renderKeyHelp`), `candy-code/src/Config/Settings/Definitions/InterfaceSettings.php` (:291 readBy), `candy-code/lang/en.php` (`keys.command.*`), `candy-code/docs/SETTINGS.md` (keybindings section ~:1407-1500, then `tools/gen-settings-doc.php` for the row at :302)
- tests: create `candy-code/tests/Commands/KeyBindingCommandBindingTest.php`, `candy-code/tests/App/CommandKeybindingDispatchTest.php`. Modify `candy-code/tests/Commands/KeysReloadTest.php`, `candy-code/tests/Cli/KeybindingCollisionNoticeTest.php`.
- docs/guards: SETTINGS.md through gen-settings-doc; the KeysReload memo census.
- serialized: [renderer:renderKeyHelp] [docs:SETTINGS.md] [lang:keys.command.] [hot:candy-code/src/App/App.php] [hot:candy-code/src/Commands/KeyBindingRegistry.php]
- deps: none
- phase: normal
- ruling: none
- risks: these bindings work only under the bin/candy-code App shell, not in a bare embedded Chat. Document that.

