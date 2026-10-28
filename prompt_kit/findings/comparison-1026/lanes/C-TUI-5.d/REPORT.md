# C-TUI-5.d — CLOSED: first-run wizard core + ProviderKeyStore
Commit: 963ddd0f6 on cc/C-TUI-5.d (base 878047472, NEVER pushed). Author/committer detain verified (1/1), zero Co-Authored trailers.

## Salvage disposition (prior incarnation's WIP)
- src/Config/ProviderKeyStore.php — KEPT byte-faithful extraction of loginStoreKey's persist half (diff-verified vs Subcommands.php:1079-1136: merge, control-char refusal, type-mismatch, corrupt refusal, writeUserConfig door + re-read verify; echoes became returned verdict strings).
- src/Tui/FirstRunWizard/ (Wizard + Action + ActionKind + OpenMsg) — KEPT; pure sugar-forms model, step1 availableTypes pills, step2 masked Input, Finish/Skip(provider)/Cancel, shouldOffer 4-door + stamp under ~/.candy-code/cache (MacAltNotice idiom). No wiring.
- lang/en.php tui.wizard.* (7 keys, EOF block, sorted) — KEPT.
- SettingsWriterCensus door row (`Config/ProviderKeyStore.php::store => 1`) — KEPT.
- ReadPathCensus rows — FIXED: wizard stamp row keyed `Tui/FirstRunWizard.php` → `Tui/FirstRunWizard/FirstRunWizard.php` (subdir path; the key also failed the OWNED_HOME mechanism arm pre-fix).
- ProviderKeyStoreTest — FIXED: the "door bailed unwritten" pin was mis-wired (never re-pointed useConfigPath at the frozen dir, so store() wrote the healthy path and returned success). Replaced chmod-0500 fixture (root-invisible) with a dangling-symlink target — userConfigWriteTarget refuses for every uid.
- FirstRunWizardTest — FIXED + EXTENDED: bare `'*'` mask literal → chr(42) (§1.9 glob-literal law — tests/ is GlobDialect's harvest dir); added the 2 required suite-env pins (piped STDOUT ⇒ shouldOffer false; HOME sandbox ⇒ default stamp undismissed then markDismissed lands inside the sandbox).

## Gates (plain pipe, cwd candy-code, env -u LINES -u COLUMNS) — ACTUALS
- new files: 28T/95A green (Wizard 21, KeyStore 7)
- SettingsWriterCensus|ReadPathCensus: 24T/5436A
- LangParity|TuiLang|GlobDialectDifferential|GlobFigureDrift|DuplicatedDocBlockLine: 95T/7380A
- peers MediaForm|DirectoryPicker|LangLaunchLocale: 317T/2683A
- combined: 147T/12911A; php -l all pass; check-one-type-per-file 3244 files 0 problems
- 2 "PHPUnit Deprecations" baseline rides EVERY filter incl. untouched tests — pre-existing config family, not chased.

## Inherited base-red (NOT mine, proven)
DuplicatedTestHelperDriftTest::testNoDuplicatedConstIsUnrecordedInEitherPolity red at base: CacheTtlIdleFoldTest vs ModelMetadataTest identical const (both files byte-identical to HEAD; failure reproduces with my new test files moved out). Owed by the C-CTX-5.c pick — weld/closeout glance.

## Notes
- ChatHostLangCatalogue standing base-red — untouched per brief.
- Deviation: shouldOffer() takes host-state booleans (pure model, stamp is its only FS touch); C-TUI-5.e wires host-side provider/TTY facts.
- DURATIONS ROWS OWED at closeout for 2 new test FILES: candy-code/tests/Config/ProviderKeyStoreTest.php, candy-code/tests/Tui/FirstRunWizard/FirstRunWizardTest.php (+ suite-figure re-pin +~28T live).
