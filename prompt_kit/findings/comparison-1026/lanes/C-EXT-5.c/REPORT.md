# C-EXT-5.c — key-bindable slash commands (`command:<name>`) — FINISH log

Tip so far: `d69c9a430a07b4b15b0be37f6f707b1c23f8d76f` ("C-EXT-5.c: key-bindable slash commands — first cut", 11 files, +694/−2; cat-file-verified commit; %ae and %ce both `detain@interserver.net` counted separately = 1 each, zero trailers). Branch cc/C-EXT-5.c off 86f80fef8. NEVER pushed.

## Provenance
Prior builder aborted with this work uncommitted (claimed SHA 2228d815b never existed — cat-file confirmed absent). Finisher kept ALL 11 dirty files (cp backups /tmp/opencode/cext5c/, diff re-verified byte-identical vs saved wip.patch + untracked cmp after a base-comparison round-trip). Base-verification temporarily removed the work from the tree and re-ran KeyHelp on pristine 86f80fef8: 64T/96540A/5F — IDENTICAL fingerprint to the with-work run ⇒ the 5 KeyHelpTest reds are foreign base-reds, not lane-caused, and the lane's Renderer block adds zero rows when no command bindings are registered.

## Brief coverage (design-ext.md §C-EXT-5.c, lines ~373-391)
- Setting: `KeyBindingsValidator::validate()` accepts `command:<name>` ids; name grammar = CommandSpec::NAME_PATTERN mirrored, equality pinned by reflection test (no drift possible silently). Chord keeps the ordinary override-chord rules + `null` unbind. `collisionNotices()` reports shipped-chord collisions (entries ride `overrides()`, the generic loop covers them).
- Registry: `COMMAND_BINDING_PREFIX`, `commandFor(KeyMsg)` (hot path: empty-map short-circuit never builds the memo — pinned via ReflectionProperty), `commandBindings()`, `$commandMemo` cleared in `resetDerived()` (reflection census in KeysReloadTest covers it automatically — name ends `Memo`). Two commands on one chord → first registered wins (`??=`), pinned.
- Dispatch: `App::handleKey()` consults `commandFor()` at the very top, BEFORE `honourKeybindingAliases()` and modal gates; hit → `runBoundCommand()` → `Chat::runCommand('/'.$name)` — the menu-bar tail idiom (withChat/autoEnablePanes/rekeyDeck/scopeForegroundCmd), so mid-turn refusal, draft preservation, unknown-command notice come free. Null-chat shell → `tui.app.no_chat` status row.
- Display: `Renderer::renderKeyHelp()` lists user command bindings last under `keys.command.heading`, chord spelled as the setting says; `/keys reload` re-applies (real `Bootstrap::reloadKeybindings()` pin in KeysReloadTest incl memo-staleness discriminator).
- `KeyBindingRegistry::all()` untouched ⇒ KeyBindingDriftTest does not move (verified green).
- Risks documented: SETTINGS.md states bindings dispatch only under the bin/candy-code App shell (bare embedded Chat sees the alias half).

## Files (all inside the brief's declared set)
src/Commands/KeyBindingRegistry.php, src/Config/Settings/Validator/KeyBindingsValidator.php, src/App/App.php, src/Renderer.php (renderKeyHelp — declared by the brief; no standalone KeyHelp.php exists in src, the "KeyHelp" surface is this method + its test class), src/Config/Settings/Definitions/InterfaceSettings.php (:291 readBy), lang/en.php (`keys.command.heading`, `keys.command.runs` — en.php only, per-step prefix rule; sibling-locale carry = closeout), docs/SETTINGS.md (:307 row + new `command:<name>` section). Tests: NEW Commands/KeyBindingCommandBindingTest.php (11T), NEW App/CommandKeybindingDispatchTest.php (6T), modified Commands/KeysReloadTest.php (+1T), modified Cli/KeybindingCollisionNoticeTest.php (+2T).

## Out-of-declared-scope (checked, not needed)
- Keybinding persistence round-trip: `command:` ids ride the existing `keybindings` map persistence (same setting, same tier); no new key, no schema change — nothing to add.
- SettingsPanel keybind picker: not declared by the brief; the map setting edits through the existing editor. Not built.

## Gates (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe; restored-tip re-verified)
- `--filter 'KeyBindingCommandBinding|CommandKeybindingDispatch|KeysReload|KeybindingCollisionNotice|KeyBindingDrift'`: **262T / 1995A / 0F** — all lane classes incl. the two new files (11T + 6T) and the two modified ones green; KeyBindingDrift unmoved.
- `--filter 'KeyHelp'`: 64T/96540A/**5F** — fingerprint proven base-inherited: same run on pristine 86f80fef8 (work swapped out) gave 64/96540/5F with identical failure set (slash-command-count and scroll-budget rosters that drifted on other lanes' commits; none touch keybindings or command rows). Zero interference: identical assertion count with work in place (command block renders nothing when no bindings registered).
- `--filter 'Settings'`: 906T/16388A/**7F** at tip AND 906/16388/7F at base (in-place swap proof) — all 7 (autoReviewScope apply-arm, media schema, writer census, ToolCap x2, editor search) foreign, no symbol in the lane's diff.
- guards: `SettingsSchemaDocDrift|ReadmeRosterDrift|EnvRosterDrift|TrustKeyDocumentationDrift|ConfigWriteProducerDocumentationDrift` → **93T/40652A/0F**; `tools/gen-settings-doc.php --check` → rc0 ("up to date"); `KeyBindings|KeybindingChordDispatch` → 10T green; `AppModelTest|MenuCommandDraftPreservation` → 75T/599A green (handleKey funnel regression).
- `--filter 'ReadmeSuiteFigureDrift'`: expected staleness pair only (pinned 25,537 vs live 28,087 — weld-era re-pin duty; live count carries other lanes' tests too, lane +20 methods; suite-figure.json untouched per contract).
- php -l: all 11 files clean. No vitest (web untouched). No protocol trio (src protocol untouched). No suite-figure/durations/dist touches.

## Durations carry (for the welder)
New test files needing durations.tsv rows at weld: `tests/Commands/KeyBindingCommandBindingTest.php`, `tests/App/CommandKeybindingDispatchTest.php` (~8.5s combined wall in-filter).

## Seams / residuals
- `command:` bindings dispatch only in the bin/candy-code App shell (documented in SETTINGS.md, per brief risks).
- sibling locales (keys.command.*) not carried — en.php-only per step prefix rule; closeout carry if the fleet policy wants all 16.
- cc/C-EXT-5.b palette overlap check: lane touches `App::handleKey` head + registry tail sections + renderKeyHelp tail — section-disjoint from 5.b's Chat.php/handlePaletteKey/runPaletteAction regions; registry file edits sit in append-regions around `overrides()`/`resetDerived()`, so a text conflict with 5.b is possible at weld but content-orthogonal. Reviewer re-gates the merged pair at weld as briefed.
- FOREIGN base-reds disclosed above (5 KeyHelpTest + 7 Settings-family) — NOT the lane's to cure.

VERDICT: brief fully executed; single commit d69c9a430; lane complete, ready for review/weld.
