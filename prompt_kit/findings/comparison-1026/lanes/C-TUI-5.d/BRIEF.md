### C-TUI-5.d — First-run wizard core + `ProviderKeyStore`
- item: C-TUI-5 (sources: GAP-UI-29)
- kind: gap · effort M
- approach: A first launch with no provider runs the echo backend behind a notice (`Bootstrap.php` ~L3339, L3473).
  - `ProviderKeyStore` extracts the persist half of `Subcommands::loginStoreKey()` (L936-986): merge into the `providers` map, refuse control characters, re-read to verify.
  - `FirstRunWizard` is a pure sugar-forms model. Step 1 picks from `ProviderFactory::availableTypes()`. Step 2 takes a masked key, or offers "run `candy-code login <p>`", or skips. `update()` returns Finish, Skip or Cancel actions.
  - `FirstRunWizard::shouldOffer()` returns true when there is no selected provider, no configured provider, a TTY, and no dismissal stamp in `~/.candy-code/cache/`.

  No wiring in this step.
- files.create:
  - `candy-code/src/Tui/FirstRunWizard/FirstRunWizard.php`
  - `candy-code/src/Tui/FirstRunWizard/FirstRunWizardAction.php`
  - `candy-code/src/Tui/FirstRunWizard/FirstRunWizardActionKind.php`
  - `candy-code/src/Tui/FirstRunWizard/OpenFirstRunWizardMsg.php`
  - `candy-code/src/Config/ProviderKeyStore.php`
- files.modify: `candy-code/lang/en.php` (`tui.wizard.*`)
- tests: create `candy-code/tests/Tui/FirstRunWizard/FirstRunWizardTest.php` and `candy-code/tests/Config/ProviderKeyStoreTest.php` (keeps other providers, refuses control characters, verifies the write)
- docs/guards: SettingsWriterCensusTest (`ProviderKeyStore` is a `config.json` door); ReadPathCensusTest (the dismissal stamp); `tools/check-one-type-per-file.php`
- serialized: [lang:tui.wizard.] [hot:candy-code/tests/Config/Settings/SettingsWriterCensusTest.php] [hot:candy-code/tests/Support/ReadPathCensusTest.php]
- deps: none
- phase: normal
- ruling: none
- risks: `shouldOffer()` must be false under the suite's HOME sandbox and for `-p`/`serve`/`attach`.

