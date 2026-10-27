# P-MENU.d — menu reachability + keyboard ownership + action dispatch

**Commit:** `6c7cf7d1b` on `cc/P-MENU.d` (base 86f43fae2), 20 files, +1251/−493. Author/committer `detain@interserver.net` verified by separate `%ae`/`%ce` greps, zero Co-Authored trailers. Never pushed.

## What landed

- **MenuBar swapped to the ShellMenu five-menu tree** (Session·Agent·Tools·View·App, R-MENU-1). `menus()`/title loop read `ShellMenu::tree()` roots; the static `$activeMenu`/`$activeItem` pair became one static `MenuCursor`; `handleKey()` delegates to `MenuCursor::key()`; `selectItem()` takes a 1-based dotted path (bare int = root row); `panels()`/`renderDropdown()` return `MenuCascade` layout; `activeMenuColumn()` counts painted titles only; deprecated `get*` forwarders kept.
- **Narrow-width fold:** titles drop from the right into a `»` overflow title (lang `tui.menu.more`); its dropdown lists the dropped menus as submenus, so every row stays reachable at any width. Tab strip stays on the bar (P-MENU.e moves it). Measured fold table at the swept widths: 30/40 → no titles painted, no fold; 60 → `»` only; 80 → Session Agent `»`; 120 → Session `»`; 200 → all five. Non-monotonic, live-paint only.
- **Dispatch by name:** leaves raise `MenuSelectedMsg(menu, label, action:'cmd:<name>'|'pane:<value>')` (new optional 3rd param; `action===null` keeps the old label lookup so AppModelTest/MenuCommandDraftPreservationTest/SettingsCommandTest did not move). `App::dispatchMenuSelection()` sends `cmd:` to `runRegistryCommand($name)` with the same settings-view rule, and `pane:` to the new `togglePaneFromChrome(Pane)` extracted verbatim from the `panetab:` click arm — keyboard toggle and click are one door (state-identity pinned).
- **Bug 4 fixed:** while a menu is open, a key the menu answers now RETURNS from the open-menu block in `KeyboardHandler::handle()` (the block moved above the Agents-dashboard arm); only a key the cursor hands back untouched falls through. Proven red-at-base: with the six src/lang files swapped to base 86f43fae2, `MenuKeyboardOwnershipTest` fails (e.g. "the menu owned the Enter and raised its selection — null is not null"); cp-restored md5-verified, green.
- **KeyBindingRegistry `menu()`:** kept `menu.switch`/`menu.move`; NEW `menu.submenu` (`→ / ←`, `keys.menu.submenu`); `menu.run`/`menu.close` reworded via lang ("Run the row, or open its submenu (or o)" / "Close the submenu, then the menu (or q)"). Each two-effect row driven both ways (KeyBindingDriftTest rule).
- **Zone law:** `menuitem:` zones are now the kit's 1-based dotted path (`menuitem:3.1`; click rebuild `[...path, firstRowIndex+j]`); `menu:` title zones stay 1-based over PAINTED titles incl the `»` slot.
- **Docs same commit:** README form-doors + Mouse wording, docs/MEDIA.md doors — `F10 ▸ Tools ▸ Generate image…` is the bare-`/generate` door (R-MENU-1; hidden stays hidden).
- **MenuBar.php `panels()` seam note** (the :581 "nodes stays empty until click-routing" region): rewritten to document the real nodes/path/firstRowIndex fill the cascade now carries.

## Gates (targeted, plain pipe, cwd candy-code)

- `--filter 'Menu'`: **261T / 13,911A green** (2 PHPUnit deprecations, house-normal).
- `'Renderer|KitchenSink'`: **1197T / 152,281A green** (KeyHelpTest fixed in-step: `menu.submenu` grew the live list 180→181 lines; 155→156 at 100x30, footer rows 185→186, changelog sentence appended).
- `'KeyBinding'`: **278T / 3,332A green**.
- five-guard: exactly the allowed 2 staleness-pair reds (suite figure 25,537 pinned vs 26,124 live — gate-owned re-pin). The lane's new files contribute exactly 28 cases (14+5+9, §1.5 rows — reviewer-verified); provider-row expansion inside modified tests carries the remainder, so the green run at weld is the figure authority, not this report's arithmetic. DuplicatedTestHelperDrift red cured in-step (helper bodies byte-identical to canonical / `paneApp` rename).

## NEW test files — gate must add DURATIONS rows (plan §1.5)

- `candy-code/tests/Tui/MenuReachabilityTest.php` (14T) — every ShellMenu leaf reachable from F10 by keys and by clicks (painted titles / `»`) at 30/40/60/80/120/200 cols; selection dispatches the spec it names; contiguous title-zone law; 80-col media form via F10→→Enter (through the fold: first Enter enters the submenu, second chooses `cmd:generate-form`; update raises the `\Closure`, `update($cmd())` delivers `OpenMediaFormMsg` → `mediaForm` non-null).
- `candy-code/tests/Tui/MenuKeyboardOwnershipTest.php` (5T) — bug 4: menu open over the Agents dashboard and over an open skill picker; ↑/↓ move ONLY the menu (`selectedAgentIndex`/`skillPickerIndex` unchanged); declined keys still reach the dashboard; Esc one level per press.
- `candy-code/tests/App/MenuActionDispatchTest.php` (9T) — `cmd:` runs the named spec (incl the generate-form → mediaForm chain and no-chat status wording); settings-view rule (cmd:settings keeps the editor open, other cmds step it aside); `pane:` and `panetab:` leave identical App state through the one door; `pane:nowhere` loud error; unknown prefix no-op identity; `action===null` label fallback byte-behavior.

## Disclosures

- Brief-list tests adapted: MenuBarTest (restructured to MenuCursor keys + dotted `selectItem`), MenuBarMouseTest (1-based dotted zones; `menuName` from ShellMenu roots), TuiComponentTest (five-title needles), KeyBindingDriftTest (activeMenuItem via cursor rows, `menuDepth()` helper, NEW `menu.submenu` observation driven both ways).
- Green UNCHANGED despite being on the modify list: MenuOpenTest, MenuDropdownTest, ShellContrastTest.
- Forced-by-swap beyond the brief list: KeyboardHandlerTest + AbandonedPaletteIsNotCompositedTest (reflection resets → `MenuBar::closeMenu()`; statics-census property names), KeyBindingRegistryTest (152-row count + changelog), MenuOverlayFrameTest (separator skip + 1-based zones + ShellMenu-derived helpers), KeyHelpTest (pins grown by `menu.submenu`).
- `src/Tui/Renderer.php` untouched by design: `panels()` gained an optional `?int $rows` (default = no windowing) so the existing splice call stays compatible and `renderDropdown() == panels()[0]->lines` equality holds.
- Process-static law honored: every touched test resets the cursor via `MenuBar::closeMenu()` in setUp/tearDown (it now clears cursor AND fold).
- `suite-figure.json` / `parallel-tests-durations.tsv` / dist / generated protocol: NOT touched, NOT staged (gate-owned).

## Round-2 (review fixes, commit on cc/P-MENU.d atop 6c7cf7d1b)

- **[MAJOR] The 80-col media-form leg now walks the FOLD.** `testTheMediaFormOpensThroughF10AtEightyColumns` — which never repainted and so strolled the structural five-menu ring with ONE Enter — is replaced by `testTheMediaFormIsReachableThroughTheFold` (`candy-code/tests/Tui/MenuReachabilityTest.php`): it calls the file's own `repaint(80)` FIRST (compose() records the fold in MenuBar's render state), asserts the painted observables (title zones exactly `menu:1..menu:3`, effective tree 3 roots, root 3 = `MenuBar::MORE_MENU_ID`), then drives F10 → Right → Right (lands on the `»` fold — row under the cursor is the shed `menu:tools` SUBMENU, asserted via `cursor()->absolutePath()` + `tree()->node()`) → Enter (ENTERS: cmd null, menu stays at 3, cursor rows depth 2, row under the cursor is `cmd:generate-form`) → Enter (CHOOSES: menu closes, `\Closure` cmd) → `update($cmd())` → `mediaForm` non-null. The paragraph above line 25 ("through the fold: first Enter enters the submenu, second chooses cmd:generate-form") is now literally what the test executes.
- **[NIT] Rename** — width-free name shipped with the rewrite (no references to the old method name existed tree-wide; grep-verified).
- **[NIT] Estimate looseness** — the "+~51T-class" note in the Gates section replaced by the enumerable statement above.
- **[MINOR] fold-budget non-monotonicity** — NOT touched per fixer ruling; recorded as a P-MENU.e handoff carry (that step moves the tab strip and recomputes the budget).

**Mutation proofs (new leg is load-bearing, not re-labelled):**
- M1 delete `$this->repaint(80);` → red at the first fold observable (`Failed asserting that … ['menu:1','menu:2','menu:3'] … [] `) — no paint, no fold.
- M2 delete the first Enter (the fold-entry hop) → red at 'choosing closes the menu' (3 ≠ 0): the lone Enter lands on the submenu ROW and ENTERS instead of choosing — i.e. the two-Enter fold walk the README's keystroke actually needs at 80 cols is pinned.
- Removing the Right,Right hops reddens the activeMenu observables at each hop (1→2→3 asserted individually, with the node-under-cursor idents above).

cp-backup + md5-verified restores after both mutations. Gates re-run after restore: `--filter 'Menu'` 261T/13,920A green (base 261T/13,911A — +9 assertions, +0 tests), `Renderer|KitchenSink` 1197T/152,281A goldens UNMOVED, `KeyBinding` 278T/3,332A, five-guard 133T with ONLY the staleness pair (live 26,124 unchanged by this round). durations.tsv/suite-figure.json untouched (gate/closeout duty).
