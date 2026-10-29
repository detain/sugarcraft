# C-EXT-5.b — palette prefill rows (LANE CLOSED)

Commit: `9d0750cd8` on `cc/C-EXT-5.b` (base e306bf4fc), 9 files, +535/−13.
Author+committer detain@interserver.net verified separate greps ==1, zero Co-Authored. Tree clean, never pushed.

## What shipped
- `Chat::paletteItemLabels()` default arm → `PaletteCatalog::rows($this->slashCommandRows())` (providers/models/themes arms untouched).
- Prefill dispatch in `runPaletteAction` + `runRootPaletteAction`: closes palette, writes `/<name> ` into a BLANK draft, caret at end (mutate inputBuf-only write re-seeds the widget caret); non-blank draft KEPT + one-line notice via `Message::notice(Lang::t('palette.prefill_draft_kept'))` (draft-preservation law); mid-turn allowed via the public arm; MRU records through the existing `rememberPaletteUse`. No new sprintf/stderr emitter (StderrEmitterCensus unaffected — re-run green).
- New privates `palettePrefillEntry`/`applyPalettePrefill` inside [chat:palette]. Original `InvalidArgumentException 'No palette action is labelled "%s".'` throw preserved for unknown labels (MenuCommandDraftPreservationTest's throw-pin survives).
- `PaletteAction.php` VIS-4 comment → full-superset truth. `lang/en.php` soft-appended `palette.prefill_draft_kept`. `docs/COMMANDS.md` palette paragraph (DocFigure AL/E697 needle windows byte-untouched; guard run green).

## Tests
- CREATE `candy-code/tests/Chat/PalettePrefillRowsTest.php` — **6 tests / 7-ish assertions per test; DUR row owed by welder: `tests/Chat/PalettePrefillRowsTest.php	6`**. Covers: every survivor row prefills a blank draft (caret end, palette closed, MRU front, no cmd executed); file command + MCP prompt arrive as prefill rows; non-blank draft kept + single-line system/uiOnly notice naming the command; prefill allowed mid-turn while action rows keep refusing; interactive Down+Enter route; action half leads the list, prefills trail.
- MODIFY `candy-code/tests/Chat/PaletteSupersetDispatchTest.php` — now 7 tests: new `testTheRootPaletteListsEverySlashVisibleRow` superset assertion over all slash-visible rows; 15→19 survivor reasons kept; census roster gained `/instructions`.

## Gate results (all `cwd=candy-code`, plain pipe, -u LINES -u COLUMNS)
- `--filter 'Palette'`: **229T / 4175A, 0F** (2 deprecations pre-existing at base).
- `--filter '(SlashDispatch|MenuCommandDraftPreservation|KeyBindingDrift|SettingsCommand|MediaFormKeys|DeckHostedSessionRoutes|InFlightInputQueue)'`: 318T, 0F.
- `--filter '(DocFigureProseDrift|GlobDialect|ReadmeSuiteFigureDrift|ChildWallClockBudget|DuplicatedTestHelper|StderrEmitterCensus)'`: 230T, only-reds = the staleness pair (below).
- COMMANDS.md/contrast family `(InertFrontmatter|CommandsTableTakes|CommandShellPosture|RegistryTranslation|ShellContrast)`: 41T, 0F.
- Mutation sanity: new PrefillRowsTest against pristine base Chat.php = 6/6 red (5F/1E, runPaletteAction throw + absent rows); restore md5-identical.

## §1.8 guard flips (in-step, this commit)
1. `PaletteFullQueryMatchTest` 'mdl' expected gains `/schedules [list|…]` — the prefill row legitimately fuzzy-matches m-d-l through the hint ("<na**m**e> … **d**i**l**able"); test pins coverage, not a fixed list (own comment).
2. `PaletteSupersetDispatchTest` census +`instructions` — PRE-EXISTING BASE RED (2405 spec landed e80d84b40 C-CTX-3.a after the roster's last edit; proven failing in pristine main tree too; base red at e306bf4fc). Cured because the design assigns this file to the lane.
3. `PaletteClickTest`/`RendererTest` palette fixtures 80→140 rows — same family's own documented VIS-4 height-adaptation precedent; the grown list (69 rows) needs it to keep every listed row PAINTED.
4. `PaletteClickTest` `WIDE_UNCLICKABLE_ROWS` fail-closed roster (see seam (b)) + census arm re-deriving it from the painted frame.

## Seams (weld/owner attention)
(a) **Mid-turn asymmetry (by design, disclosed):** interactive Enter/click on a prefill row mid-turn goes through `runSelectedPaletteActionWhileInFlight` (NOT a declared region) and still REFUSES, while the public `runPaletteAction` allows prefill mid-turn per the design block ("allowed mid-turn, because it only edits the draft"). If the block intends Enter to fill too, a follow-up mints that arm; the parity sweep pins the refusal is at least consistent between Enter and click.
(b) **Renderer.php (out of design-block files) — wide-label rows lose their click zone.** The box is a fixed `width(50)`; 4 argument-hint labels (`/schedules` 77, `/websearch` 69, `/generate` 63, `/sendto` 55 cells) paint clipped, and `recordPaletteItemZones` matches the FULL content line — so those rows own no picker zone (keyboard half unaffected). Latent since PaletteCatalog shipped long labels; C-EXT-5.b surfaces it. Cure candidates: clip the stored row to the painted line, or budget the hint in `PaletteCatalog::rows`. Pinned honestly meanwhile by the roster + census in PaletteClickTest (a fifth wide label reddens).
(c) **No palette scroll:** below-fold rows on short real terminals are reachable via query typing only (design never asked for windowing; C-EXT-5.c is key-help, not scroll). Disclosed.
(d) Inherited staleness pair `ReadmeSuiteFigureDriftTest` (pinned 25,537 vs live 27,864 at base; my branch adds ~+9) — welder re-pin + DUR for `tests/Chat/PalettePrefillRowsTest.php`.
(e) App.php menu rows unaffected (`runRegistryCommand` still routes slash-visible rows via `runCommand`).
