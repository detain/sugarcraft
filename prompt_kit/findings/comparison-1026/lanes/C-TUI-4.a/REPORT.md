# C-TUI-4.a — Custom TUI themes from ~/.candy-code/themes/ (lane report, WIP)

Worktree /home/sites/cc-cmp-C-TUI-4.a, branch cc/C-TUI-4.a, base master aa5ddf638.

## Landed (slice 1: src + tests + census + lang)
- NEW src/Config/ThemeFile.php: loader for `~/.candy-code/themes/<stem>.json` ({"extends","tokens"}), user-dir ONLY via HomeDirectory::owned() (project tree never read); NAME_PATTERN makes traversal impossible; per-file realpath containment refuses symlink escape (the scan lists the stem, the read refuses it); token/colour validation with English note fragments; fatal error property (empty/not-a-JSON-object/unreadable); memoized scan+parse with resetForTesting().
- NEW src/Config/Settings/Validator/ThemeNameValidator.php: replaces the implied EnumValidator on `theme` (accepts null/unset, built-ins case-insensitively, or an existing user file; refusal reason carries "did you mean" via Theme::suggest).
- src/Theme.php: names() = built-ins + discovered (built-ins win collisions); builtInNames() feeds docs/validators; byName() no longer throws — unknown names degrade to default with ONE RuntimeNoticeSink notice per process; malformed files paint as their `extends` base with one notice; notes surface aggregated; palette()/suggest()/resetForTesting() helpers; pair() contrast projection untouched so custom colours ride the same CONTRAST_MIN floor.
- InterfaceSettings.php: theme def `->withEnumValues(Theme::names())` -> `->withValidators(ThemeNameValidator::new())`; OptionsProvider verified-unchanged (L31 Themes=>Theme::names() now feeds the live list incl customs; enumValues=[] falls through to optionsSource).
- lang/en.php: settings.theme.help mentions the themes dir; 3 new chat.theme.file_* keys (hunk-local vs C-TOOL-6.a).
- ReadPathCensusTest rows: Config/ThemeFile.php|file_get_contents (OWNED_HOME), |scandir (NAMES_ONLY) — same commit as the read.
- Tests: NEW tests/Config/ThemeFileTest.php 9T/35A (valid merge, malformed->base+one notice, low-contrast projected, unknown token+bad colour noted, project dir ignored, symlink refused-not-followed, unknown extends, built-in collision shadowed, empty object). ThemeTest: throws-pin flipped to fallback+notice-once pin, builtInNames order pin, suggest pins. ChatTest testThemeCommandWithUnknownNameLeavesThemeUnchanged -> ...FallsBackToTheDefault (in-step flip; Chat.php untouched — the old catch is now unreachable-but-harmless). PaletteThemePickerTest: custom theme listed + seeds its row.

## Gate results (cwd=candy-code worktree, plain pipe)
- ThemeFileTest|ThemeTest|PaletteThemePickerTest: 24T/112A OK (3 repeats 9.48/10.08/10.54s; median 10.08 incl ~8-9s bootstrap).
- flipped ChatTest /theme tests: 2T/5A OK. broad 'theme|Theme': 253T, sole failure MediaSettingsTest::testTheProviderSchemaCarriesTheMediaOptionals = FOREIGN base-red (git diff HEAD touches zero media files; fails identically isolated).
- Settings family (Schema/Resolver/Writer/SessionOverlayFork/UiSettings): 138T/2905A OK.
- SettingsSchemaDocDriftTest + EnvRosterDrift + ReadmeRosterDrift: 66T/40566A OK. php tools/gen-settings-doc.php --check: "settings docs: up to date" (generated table byte-unchanged; no foreign-regen restore needed).
- DocFigure + GlobDialect: green within 119T run.
- Standing reds (not chased): ReadPathCensusTest::testTheCensusIsDerivedFromSrcAndFullyClassified fails on Server/ServerConfig.php|file_get_contents=>2 + Session/Import/RemoteSessionPush.php|file_get_contents=>1 unrostered in the committed census at base (files byte-untouched by this lane; my ThemeFile rows match exactly). DuplicatedTestHelperDriftTest::testNoDuplicatedConstIsUnrecorded... lists OPEN/CLOSE/STUB_CANDIDATE (CopySlashCommandTest vs CopyCommandTest/MouseTextSelectionTest) + HEAVY_CHARS (ResumeCost*) — all foreign files; none of this lane's files named.

## Slice 2 (docs): commit 8bc69dcfa
- docs/SETTINGS.md: new non-generated prose paragraph after the `adaptive` paragraph (L~109) covering the themes-dir vocabulary, file shape, built-ins-win-collision, contrast projection, user-dir-only/never-project, degrade-never-throw, extends-fallback+one notice, and why docs/validators use builtInNames()+ThemeNameValidator live check instead of an enum snapshot.
- README.md Interface settings clause: `theme` mention now names ~/.candy-code/themes/ as the picker's extra rows.
- Generated table byte-unchanged: php tools/gen-settings-doc.php --check = "settings docs: up to date" at tip (NO foreign-regen restore needed — nothing to report there).
- Guards at docs tip: ReadmeRosterDrift|SettingsSchemaDocDrift|EnvRosterDrift|KeyBindingDrift|ConfigWriteProducer = 306T/42451A OK.

## Final sweep at tip
- Consumer sweep (every test file touching Theme::names()/byName outside earlier gates — SkillSource/AgentStatusBar/AgentViewPane*/SettingsFieldFactory/ShellContrast/PaletteClick/UiSettings/RenderMemo/AppModel/*Picker*/ContextPane/CellWidthPadding/VideoPlayerScreen): 392T/8948A, 0 failures; 2 risky = PaletteClickTest::testEveryPaletteRowAnswersAClickExactlyAsItAnswersEnter — PROVEN PRE-EXISTING at base (identical risky pair in the main tree pinned at aa5ddf638; PaletteClickTest diff = 0 lines).
- suggest() self-echo bug fixed in-lane (fold-equal candidate no longer hints itself; 'tokyo-night'→'tokyoNight' kept).

## Durations duty (new test file — for welder's tsv, NOT touched by lane)
- candy-code/tests/Config/ThemeFileTest.php — 9 tests / 35 assertions; 3 repeats 10.082/9.476/10.540s → median 10.08s (≈8-9s is fixed bootstrap overhead the sharding measures per file).

## Commits (branch cc/C-TUI-4.a, base aa5ddf638, never pushed)
- 833fff772828b9399c10f9b2d38db7bae6dd46a3 src+tests+census+lang (11 files +906/−16) — cat-file = commit; %ae/%ce detain each grep =1; zero Co-Authored.
- 8bc69dcfac88512f99912922d97fe2f5f7e5f7af docs — same author proofs.

## Seams / disclosures for the welder
- OptionsProvider.php: verified-unchanged — L31 Themes=>Theme::names() already feeds the live list (customs included) once enumValues is dropped from the definition; design's "modify L31" is a no-op-by-inspection.
- In-step test flips: ThemeTest throws-pin → fallback+notice-once; ChatTest::testThemeCommandWithUnknownName... → asserts set-row + one 'is not a built-in theme' drain notice (Chat.php itself byte-untouched; its byName catch is now unreachable-but-harmless); PaletteThemePickerTest gained custom-row pin; SettingsSchemaTest theme special-case (enumValues=[]).
- Custom 'adaptive'-named theme file is shadowed by the built-in adaptive (names() dedup keeps built-ins).
- Mid-session theme file edits land next launch (process memo in ThemeFile/Theme memos; resetForTesting() for tests) — documented trade, matches "cheap, no I/O per frame" law of Chat::theme().
- Base foreign reds re-encountered (not chased, per brief): ReadPathCensusTest fully-classified arm (Server/ServerConfig.php|file_get_contents x2 + Session/Import/RemoteSessionPush.php|file_get_contents unrostered at base), DuplicatedTestHelperDrift unrecorded-const arm (OPEN/CLOSE/STUB_CANDIDATE, HEAVY_CHARS), MediaSettingsTest::testTheProviderSchemaCarriesTheMediaOptionals, ReadmeSuiteFigureDrift staleness pair — none name this lane's files.
