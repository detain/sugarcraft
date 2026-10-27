# Design — P-MENU-REDO (owner pick, phase normal, high priority)

Read-only design pass against `master` @ 5e9195687 (2026-10-26). Every MODIFY path below exists and every CREATE path is absent; line numbers are from that tree.

The owner's words: *"I want the menus redone so there are fewer main menu items — the existing ones moved into submenus of new main menu items. Also the active window panes toggles at the top right maybe moved into a submenu so all the panes are listed as submenu items."*

**Supersession.** These steps replace **P-IMG.a** in full: the menu-entry half of P-IMAGE-MENU. **P-IMG.b** (the content-sized popup) stays as designed and comes before P-MENU.c in the layout chain. **P-IMG.c** (the web Generate button) stays and does not interact with these steps. See the Notes.

## Proposed menu tree

The bar goes from **11 menus to 5**: `Session · Agent · Tools · View · App`. The pane-tab strip leaves the bar's right end; only the `Currently: <pane>` indicator stays (ruling R-MENU-2).

```text
 Session   Agent   Tools   View   App    Currently: Settings      ← 61 cells at the longest pane label; fits 80
```

```text
Session                              Agent                                Tools
├ New session          /new          ├ Switch model        /model         ├ Generate image…     generate-form (row 0)
├ New session…         new-picker    ├ /effort                            ├ Media ▸             /play · /sendto · /rendermode
├ Switch session       /sessions     ├ List agents         /agents        ├ Review ▸            /review · /security-review
├ Rename session…      /rename       ├ /btw (side question)               ├ /websearch
├ Pin or unpin session session-pin   ├ ───                                ├ /editor
├ Delete session…      session-delete├ Automate ▸   /goal · /grind · /loop ├ ───
├ ───                                │              /bg · /schedules ·    ├ List MCP servers    /mcp
├ Branch & fork ▸  /branch · /fork · │              /workflow             └ /hooks
│                  /handoff          ├ Context ▸    /context · /sweep ·
├ History ▸   /compact · /clear ·    │              /pruning · /compress ·  (bare /generate is hidden from the
│             /rewind · /undo ·      │              /decompress ·            menu: Generate image… is its door.
│             /redo · /diff          │              /recompress              The slash command is unchanged.)
└ Share & export ▸ /share · /export  └ Memory & rules ▸ /memory · /skills ·
                                                    /init · /rules · /newrule

View                                                 App
├ Panes ▸   ✓ ▢ Chat      (focus only; always on)    ├ View settings        /settings
│           ✓ ◫ Files     (checkable toggle)         ├ /permissions
│             ⚒ Tools                                ├ Status & spend ▸   /status · /budget · /usage · /notices
│             ✦ Skills                               ├ Help ▸             /keys · /help · Open docs (docs)
│             ❖ Agents                               ├ ───
│             ⚙ Settings                             └ Exit                 /exit
│             ☑ Todo        (= Pane::tabCycle(), so new panes join automatically)
├ Layout ▸  Dock pane left · Dock pane right · /pane · Reset layout (/layout)
└ Switch theme        /theme
```

- A row shown as `/name` keeps the label it has today: `CommandSpec::label()`, derived for rows with no `paletteLabel`.
- The only new label is the menu-only `Generate image…`. The palette row keeps `Generate image`, so `MediaFormKeysTest` does not move.
- At 80 columns, **Generate image…** is `F10 → → Enter`, or a click on **Tools** then on its first row.
- Under 41 columns the titles drop from the right into a `»` title, and its dropdown lists the dropped menus as submenus. Every row stays reachable at any width.

### Every existing item and pane toggle: old location → new location

Old menus, in order: Session #1 · Model #2 · App #3 · Appearance #4 · Agents #5 · MCP #6 · Rules #7 · Layout #8 · Workflow #9 · Memory #10 · Tools #11.

Today only menus #1–#3 are painted at 80–120 cols (the budget arithmetic is at `MenuBar.php:176-200`). #11 Tools needs about 190 cols.

| # | item (command) | old location | new location |
|---|---|---|---|
| 1 | New session (`/new`) | Session | Session ▸ New session |
| 2 | New session… (`new-picker`) | Session | Session ▸ New session… |
| 3 | Switch session (`/sessions`) | Session | Session ▸ Switch session |
| 4 | Pin or unpin session (`session-pin`) | Session | Session ▸ Pin or unpin session |
| 5 | Delete session… (`session-delete`) | Session | Session ▸ Delete session… |
| 6 | Switch model (`/model`) | Model | Agent ▸ Switch model |
| 7 | `/effort` | Model | Agent ▸ `/effort` |
| 8 | Share session (`/share`) | Session | Session ▸ Share & export ▸ |
| 9 | Export transcript (`/export`) | Session | Session ▸ Share & export ▸ |
| 10 | Open docs (`docs`) | App | App ▸ Help ▸ |
| 11 | Exit (`/exit`) | App | App ▸ Exit |
| 12 | Switch theme (`/theme`) | Appearance | View ▸ Switch theme |
| 13 | View settings (`/settings`) | App | App ▸ View settings |
| 14 | List agents (`/agents`) | Agents | Agent ▸ List agents |
| 15 | List MCP servers (`/mcp`) | MCP | Tools ▸ List MCP servers |
| 16 | `/keys` | App | App ▸ Help ▸ |
| 17 | `/help` | App | App ▸ Help ▸ |
| 18 | `/permissions` | App | App ▸ `/permissions` |
| 19 | `/notices` | App | App ▸ Status & spend ▸ |
| 20 | `/status` | App | App ▸ Status & spend ▸ |
| 21 | `/context` | App | Agent ▸ Context ▸ |
| 22 | `/sweep` | App | Agent ▸ Context ▸ |
| 23 | `/pruning` | App | Agent ▸ Context ▸ |
| 24 | `/compress` | App | Agent ▸ Context ▸ |
| 25 | `/decompress` | App | Agent ▸ Context ▸ |
| 26 | `/recompress` | App | Agent ▸ Context ▸ |
| 27 | `/hooks` | App | Tools ▸ `/hooks` |
| 28 | `/rules` | Rules | Agent ▸ Memory & rules ▸ |
| 29 | `/newrule` | Rules | Agent ▸ Memory & rules ▸ |
| 30 | `/pane` | Layout | View ▸ Layout ▸ |
| 31 | Reset layout (`/layout`) | Layout | View ▸ Layout ▸ |
| 32 | Dock pane left (`pane-dock-left`) | Layout | View ▸ Layout ▸ |
| 33 | Dock pane right (`pane-dock-right`) | Layout | View ▸ Layout ▸ |
| 34 | `/editor` | App | Tools ▸ `/editor` |
| 35 | `/compact` | Session | Session ▸ History ▸ |
| 36 | `/clear` | Session | Session ▸ History ▸ |
| 37 | `/budget` | App | App ▸ Status & spend ▸ |
| 38 | `/usage` | App | App ▸ Status & spend ▸ |
| 39 | `/workflow` | Workflow | Agent ▸ Automate ▸ |
| 40 | `/memory` | Memory | Agent ▸ Memory & rules ▸ |
| 41 | `/skills` | Memory | Agent ▸ Memory & rules ▸ |
| 42 | `/init` | Memory | Agent ▸ Memory & rules ▸ |
| 43 | Branch session (`/branch`) | Session | Session ▸ Branch & fork ▸ |
| 44 | Rename session… (`/rename`) | Session | Session ▸ Rename session… |
| 45 | `/rewind` | Session | Session ▸ History ▸ |
| 46 | `/undo` | Session | Session ▸ History ▸ |
| 47 | `/redo` | Session | Session ▸ History ▸ |
| 48 | `/diff` | Session | Session ▸ History ▸ |
| 49 | `/review` | Tools | Tools ▸ Review ▸ |
| 50 | `/security-review` | Tools | Tools ▸ Review ▸ |
| 51 | `/bg` | Session | Agent ▸ Automate ▸ |
| 52 | `/schedules` | Session | Agent ▸ Automate ▸ |
| 53 | `/loop` | Session | Agent ▸ Automate ▸ |
| 54 | `/fork` | Session | Session ▸ Branch & fork ▸ |
| 55 | `/websearch` | Tools | Tools ▸ `/websearch` |
| 56 | `/goal` | Session | Agent ▸ Automate ▸ |
| 57 | `/grind` | Session | Agent ▸ Automate ▸ |
| 58 | `/btw` | Session | Agent ▸ `/btw` |
| 59 | `/handoff` | Session | Session ▸ Branch & fork ▸ |
| 60 | bare `/generate` | Tools | **hidden from the menu**. Its door is row 61; `/generate` still works as a slash command. |
| 61 | Generate image (`generate-form`) | Tools (#11, off-screen below ~190 cols) | **Tools ▸ Generate image…** (row 0) |
| 62 | `/sendto` | Tools | Tools ▸ Media ▸ |
| 63 | `/play` | Tools | Tools ▸ Media ▸ |
| 64 | `/rendermode` | Appearance | Tools ▸ Media ▸ |
| P1 | `[▢ Chat]` tab | bar right end | View ▸ Panes ▸ ▢ Chat (focus only, always ✓) |
| P2 | `[◫ Files]` tab | bar right end | View ▸ Panes ▸ ◫ Files (toggle) |
| P3 | `[⚒ Tools]` tab | bar right end | View ▸ Panes ▸ ⚒ Tools (toggle) |
| P4 | `[✦ Skills]` tab | bar right end | View ▸ Panes ▸ ✦ Skills (toggle) |
| P5 | `[❖ Agents]` tab | bar right end | View ▸ Panes ▸ ❖ Agents (toggle) |
| P6 | `[⚙ Settings]` tab | bar right end | View ▸ Panes ▸ ⚙ Settings (toggle) |
| P7 | `[☑ Todo]` tab | bar right end | View ▸ Panes ▸ ☑ Todo (toggle) |
| I | `Currently: <pane>` indicator | bar right end | stays; a click opens View ▸ Panes (R-MENU-2) |

The 64 commands are every row of `CommandRegistry::all()` (`builtin-commands/0100…4040`). File-based `Custom` commands never reach the menu today (`all()` maps `BuiltInCommands` only), and that does not change. The Ctrl+P palette keeps its per-category headers, because no `CommandSpec::$category` changes.

### Rulings (each has a proposed default; the steps can run on the default)

- **R-MENU-1 (P-MENU.b):** approve the tree as drawn above. That covers:
  - the five names `Session · Agent · Tools · View · App`;
  - the placements in the table;
  - hiding bare `/generate` from the menu while keeping it as a slash command.
- **R-MENU-2 (P-MENU.e):** once the tabs leave, what goes at the right end of the bar?
  - **Default:** keep the `Currently: <pane>` indicator. Several tests already pin it (`BootstrapTest:386`, `ComponentTest:89-117`, `Tui/RendererTest:298`), and the step makes it a click door to View ▸ Panes.
  - **Alternative:** leave the right end empty.
- **R-MENU-3 (P-MENU.e):** what does toggling a pane in View ▸ Panes do to the menu?
  - **Default:** the submenu stays open and the check flips in place, so several panes can be toggled in one visit. Esc closes it.
  - **Alternative:** close the menu, as running a command does.

## Current state (investigated)

**Menus.** `src/Tui/Components/MenuBar.php`, a static class.
- **Model.** `menus()` (:74-82) builds a flat `array<category, list<label>>` from `CommandRegistry::all()`, one menu per `CommandSpec::$category` in first-appearance order. That gives 11 menus. There is one row cursor (`$activeItem`, :127) and no nested model anywhere.
- **No reusable menu in the libs.** None of the sugar-* libs candy-code requires has a menu. `sugar-dashboard/src/Components/Nav/Menu.php` is a single-level menu and not a dependency.
- **Render.** `compose()` (:162-204) paints the titles, then `paneTabs()` (:259-288).
  - The tab strip has priority. Titles drop from the right when they don't fit (:181-185).
  - `density()` (:220-236) shrinks the tabs to icons, then the indicator to its bare label.
  - The dropdown is `dropdownLines()` (:535-581), a single box. `activeMenuColumn()` (:588-605) places it.
- **Keyboard.**
  - F10 calls `openMenu()` (`KeyboardHandler.php:151-153`).
  - `handleKey()` (:299-310) handles ←/→ (h/l) to cycle menus, ↑/↓ (k/j) to move with wrap, Enter/o to select, Esc/q to close.
  - `KeyboardHandler.php:591-603` routes the open menu.
- **Mouse.**
  - Title zones `menu:<n>` and row zones `menuitem:<row>` are scan-only marked twins (`Tui/Renderer::scanChrome`, :497-546).
  - A click goes through `App::dispatchChromeClick()` (:3371; title arm :3387, row arm :3394) into the keyboard's own entry points.
  - There is no hover: `Chat::mouseMode()` is CellMotion "because nothing consumes hover yet" (`Chat.php:6034-6060`). `Cmd::enableMouseAllMotion()` exists (`sugar-core/src/Cmd.php:385`).
- **Dispatch.** A selection is `MenuSelectedMsg(menu, label)` → `App::dispatchMenuSelection()` (:3988), which resolves the spec by its label.

**Pane toggles at the top right.**
- `MenuBar::PANE_TABS` (:94-102) holds Chat, Files, Tools, Skills, Agents, Settings and Todo. `PaneReverseCycleTest:268-283` pins it equal to `Pane::tabCycle()`.
- Each tab is `[icon Label]` with three states: focused, docked, or undocked and muted.
- Each tab carries a `panetab:<pane>` zone. A click toggles docking through `togglePaneDocking()` (`App.php:3468-3482`, :2052), and Chat's tab only focuses.
- The tab is also a dock-drag source (`dragTargetPane()`, :3071). This is the live-report fix pinned by `DockDragBytePathTest`.

**Latent bugs found (P-MENU.c and P-MENU.d fix them, each with a regression test).** All were read from source, not run.
1. **The Agents dashboard paints no dropdown.**
   - `renderAgentDashboard()` (`Tui/Renderer.php:905-927`) calls `scanChrome(..., false)` and overlays nothing, yet F10 or a title click opens a menu there.
   - The menu is invisible while it owns the keyboard, and Enter runs its invisible highlighted row.
2. **The video player registers zones it never paints.** `renderVideoPlayer()` scans dropdown row zones (`:1036`, `withDropdown: true`) but paints no dropdown, leaving invisible clickable rows over the player.
3. **Over-wide line.**
   - `activeMenuColumn()` counts dropped titles too, and `spliceInto()` (:2299) never clamps to `$cols`.
   - At 80 cols, F10 then ← opens menu #11 at col ~90. The frame line is then wider than the terminal, which breaks the one-line-per-row law.
4. **Keys fall through an open menu.**
   - ↑/↓ inside an open menu return the same menu index, so `KeyboardHandler.php:591-603` falls through.
   - The key then reaches the skill picker (:612) and `handleNavigation()` (:632 → :1102). The highlight moves in the menu AND in the Agents list or the skill picker.

**Doc pins.**
- Menu rows are not part of any generated roster: `docs/COMMANDS.md` does not render the category (`CommandDocGenerator.php:107-118`).
- The `menu.*` key rows are driven by `tests/Commands/KeyBindingDriftTest.php` (:2215-2261, helpers :2538-2575 and :3250). The README names the in-app `?` reference as the authority (README:336-340).
- These pages mention the bar in prose:
  - README L308 (the generation form's doors) and L353-357 (Mouse);
  - README L372-386 (Pane docking: the tab strip, its densities, dragging the tab);
  - `docs/MEDIA.md` L85 (the form's doors);
  - `docs/ARCHITECTURE.md` L1176-1177 (zone map) and L1200-1205 (bullets).

**Web client.** `candy-code-web/src-web` has **no menu bar**, so no web parity step is proposed.
- `App.vue:92-105` is a flat top bar: ☰ sessions drawer, settings link, about, theme, notify, sign out.
- Panes are the tabbed `components/panels/SidePanels.vue` drawer, plus `CommandPalette.vue`.
- P-IMG.c remains the web's Generate door.

## Summary

| step id | title | effort | serialized tags | deps |
|---|---|---|---|---|
| P-MENU.a | Nested menu kit (`Tui/Menu/*`: node, tree, cursor, cascade) | M | none | none |
| P-MENU.b | `ShellMenu`: the five-menu tree, derived from the registry + `Pane::tabCycle()` | S | [lang] (`tui.menu.`) | P-MENU.a |
| P-MENU.c | Menu overlay seam: every frame paints, clamps and scans the open menu (bugs 1–3) | M | [renderer:Tui/Renderer.php overlayDropdown+scanChrome+5 frame-path tails] [hot:MenuBar.php] | P-MENU.a, P-IMG.b (serial: layout) |
| P-MENU.d | Swap the bar to the tree: keyboard, click, dispatch by name, `»` overflow (bug 4) | M | [lang] (`keys.menu.`, `tui.menu.more`) [docs:README.md] [docs:MEDIA.md] [hot:App.php] [hot:MenuBar.php] [hot:KeyboardHandler.php] [hot:KeyBindingRegistry.php] | P-MENU.b, P-MENU.c |
| P-MENU.e | Pane toggles leave the bar: View ▸ Panes rows own `panetab:`, hover, indicator door | M | [docs:README.md] [docs:ARCHITECTURE.md] [hot:App.php] [hot:MenuBar.php] | P-MENU.d |

Totals: 5 steps — 1 S, 4 M, 0 L. No LATE step.

---

### P-MENU.a — Nested menu kit (`Tui/Menu/*`)
- item: P-MENU-REDO (sources: owner pick)
- kind: feature · effort M
- approach:
  - No nested menu exists. `MenuBar` models a menu as a flat `list<string>` with one static row cursor (`MenuBar.php:74-82`, :127). The kit is therefore new, pure and theme-driven, in `candy-code/src/Tui/Menu/`: the same shape as P-TABS.a's `Tui/Window/*`. It lives in candy-code because no required lib has a menu.
  - **`MenuNode`** (final, readonly):
    - fields: `kind`, a stable `id` (`cmd:<name>`, `pane:<value>`, `sub:<key>`), `label`, `icon`, `checked`, `enabled`, `children`, and an optional `zone` override;
    - factories `command()`, `toggle()`, `submenu()` and `separator()`, plus `withChecked()`.
  - **`MenuTree`:** the top-level submenus, with `rows(path)`, `node(path)`, `pathOf(id)` and `leaves()`.
  - **`MenuCursor`** (immutable) holds the open menu (1-based; 0 is closed), the highlighted row per open level, and an `expanded` flag. `key(string, MenuTree): MenuStep` handles:
    - ↑/↓ (k/j): move and wrap, skipping separators and disabled rows;
    - → (l): on a submenu row, open it at its first row; on a leaf, switch to the next menu;
    - ← (h): close the innermost submenu; at depth 0, switch to the previous menu;
    - Enter/o: open a submenu, or choose a leaf;
    - Esc/q: close one level, then the menu.

    `hover(path)` highlights a row and expands a submenu row without entering it. `MenuStep` is `{cursor, ?chosen MenuNode}`.
  - **`MenuCascade::layout(tree, cursor, theme, titleCol, top, cols, rows, marked): list<MenuPanel>`.**
    - **Placement.** The root panel goes under the title, shifted left to fit `cols`. Each expanded child sits on its parent row, against the parent's right border. If it would cross `cols` it flips to the parent's left. If neither side fits, it is right-aligned to `cols`.
    - **Height.** A panel taller than the band is shifted up, then windowed around the highlight, with the existing faint `…` row (`Tui/Renderer::dropdownOverflowLine`).
    - **Rows.**
      - toggle rows get a check column (`✓` or blank);
      - every row gets its icon and label, and submenu rows a right-aligned `▸`;
      - separators draw as `├─┤`.

      Colours are the tokens `dropdownLines()` already uses (:562-567): `border`, `shellForeground`, and `shellPrimary` bold for the highlight; disabled rows use `shellMuted`.
    - **Marked twin.** Each selectable row is wrapped in its node's `zone`, or in `menuitem:<path>` (the dot-joined row indexes, e.g. `3.1`; a root row stays `menuitem:3`).
    - **Width.** No panel line is ever wider than `cols`.
- files.create: `candy-code/src/Tui/Menu/MenuNode.php`, `candy-code/src/Tui/Menu/MenuNodeKind.php`, `candy-code/src/Tui/Menu/MenuTree.php`, `candy-code/src/Tui/Menu/MenuCursor.php`, `candy-code/src/Tui/Menu/MenuStep.php`, `candy-code/src/Tui/Menu/MenuCascade.php`, `candy-code/src/Tui/Menu/MenuPanel.php`
- files.modify: none
- tests: create
  - `candy-code/tests/Tui/Menu/MenuCursorTest.php`: every key at depths 0, 1 and 2; wrap; separator and disabled skipping; both meanings of → and ←, from a leaf and from a submenu row; Esc pops exactly one level; `hover()` expands without entering;
  - `candy-code/tests/Tui/Menu/MenuCascadeTest.php`: goldens in `candy-code/tests/Tui/Menu/golden/` (`UPDATE_GOLDENS=1`, the `Tui/MediaForm/golden` pattern) for root+child at 80×24, the flip-left at the right edge, and windowing at 40×12; no line wider than `cols` for cols 20..200 with the deepest path open; the marked twin has the same layout as the painted one; every zone covers its painted cells;
  - `candy-code/tests/Tui/Menu/MenuTreeTest.php`: `pathOf()`/`node()` round-trip; `leaves()` order.
- docs/guards: none. The new test files go to the weld for `scripts/parallel-tests-durations.tsv`.
- serialized: none
- deps: none
- phase: normal
- ruling: none
- risks: one-type-per-file. `MenuNodeKind`, `MenuStep` and `MenuPanel` each get their own file (`tools/check-one-type-per-file.php`).

### P-MENU.b — `ShellMenu`: the five-menu tree
- item: P-MENU-REDO (sources: owner pick)
- kind: feature · effort S
- approach:
  - **Builder.** `ShellMenu::tree(?App $a = null, ?list<CommandSpec> $rows = null): MenuTree` builds the tree in "Proposed menu tree" from a nested `PLACEMENT` const keyed by command **name**. Dispatch can then go by name, and a repeated label can never run the wrong row.
  - **Keeps MenuBar's "derived, not declared" law** (`MenuBar.php:59-73`):
    - every leaf is a live `CommandRegistry::all()` spec labelled `$spec->label()`;
    - a PLACEMENT name the registry lacks fails the test; it never becomes a ghost row;
    - a registry row PLACEMENT does not name still lands at runtime in `App ▸ More ▸` (`tui.menu.sub.more`), so it is never unreachable, AND it fails `ShellMenuTest`, so its author has to place it.
    - `HIDDEN = ['generate']`.
  - **Panes ▸** comes from `Pane::tabCycle()`, with one `toggle()` per pane:
    - `checked` is `$a->isDocked($pane)`; Chat is always checked and focus-only;
    - icon `Pane::icon()`, label `Pane::label()`, id `pane:<value>`.

    With `$a === null` every check is false but the structure is identical, so keyboard navigation needs no App.
  - **Menu-only label.** The `generate-form` row reads `Generate image…` (`tui.menu.item.generate_image`). The palette label and `MediaFormKeysTest:211` (`runPaletteAction('Generate image')`) stay untouched.
  - Nothing reads `ShellMenu` until P-MENU.d. The step ships the tree and its guard.
- files.create: `candy-code/src/Tui/Menu/ShellMenu.php`
- files.modify: `candy-code/lang/en.php` (`tui.menu.title.{session,agent,tools,view,app}`, `tui.menu.sub.{branch,history,share,automate,context,knowledge,media,review,panes,layout,status,help,more}`, `tui.menu.item.generate_image`)
- tests: create `candy-code/tests/Tui/Menu/ShellMenuTest.php`. It pins:
  - every registry row is placed exactly once or is in HIDDEN (only `generate`), and no PLACEMENT name is missing from the registry;
  - an injected unplaced spec lands in App ▸ More ▸;
  - there are 4–6 top menus and the titles take ≤ 40 cells;
  - `Generate image…` is row 0 of Tools;
  - the Panes rows equal `Pane::tabCycle()` in order, with `checked` equal to `isDocked`;
  - every label fits `CommandRegistry::MAX_MENU_LABEL_CELLS` + 2 (for `…`/`▸`);
  - submenus are at most 2 deep and none is empty.
- docs/guards: none. `tests/Commands/RegistryTranslationTest.php` does not move, because no category changes.
- serialized: [lang] (prefix `tui.menu.`)
- deps: P-MENU.a
- phase: normal
- ruling: R-MENU-1. Approve the five menus, the placements and hiding bare `/generate` from the menu (default: as drawn).
- risks: the tree is a second list beside the categories. The registry↔PLACEMENT check in both directions is what keeps it honest.

### P-MENU.c — Menu overlay seam: every frame paints, clamps and scans the open menu
- item: P-MENU-REDO (sources: owner pick; latent bugs 1–3 above)
- kind: fix · major · effort M
- approach:
  - **Root causes.** All three are read from source; the lane's regression tests must go red first.
    1. `renderAgentDashboard()` overlays nothing and scans titles only (`src/Tui/Renderer.php:905-927`). Yet F10 (`KeyboardHandler.php:151-153`) and a title click (`App.php:3387-3392`) still open a menu there, so the menu is invisible while it owns the keyboard.
    2. `renderVideoPlayer()` scans row zones (`:1036`) it never paints.
    3. `overlayDropdown()` (`:2145`) splices at `MenuBar::activeMenuColumn()`, which counts unpainted titles (`MenuBar.php:588-605`), and `spliceInto()` (`:2299`) never clamps to `$cols`. The result is an over-wide frame line at 80 cols.
  - **Fix.**
    - One `overlayMenu(App, string $frame, int $cols, int $top): string` splices every `MenuPanel` from `MenuBar::panels()`, clamped to `$cols`.
    - One panel pass in `scanChrome()` reads `MenuBar::panelsMarked()`.
    - All five frame paths call both: `renderView`, `renderAgentDashboard`, `renderMediaForm`, `renderVideoPlayer` and `renderSettingsEditor`.
  - **Behaviour is preserved.**
    - In this step `MenuBar::panels()`/`panelsMarked()` return today's single dropdown as one `MenuPanel`, shifted left to fit. P-MENU.d swaps in the cascade without touching `Tui/Renderer.php` again.
    - `renderDropdown()`/`renderDropdownMarked()` stay as forwarders, since tests read them.
  - `overlayMenu()` runs after P-IMG.b's veil composite, so the menu draws crisply above the popup.
- files.create: none
- files.modify: `candy-code/src/Tui/Renderer.php` (`overlayDropdown()`→`overlayMenu()`, `scanChrome()` panel pass, the tails of `renderView()`, `renderAgentDashboard()`, `renderMediaForm()`, `renderVideoPlayer()` and `renderSettingsEditor()`), `candy-code/src/Tui/Components/MenuBar.php` (new `panels()`, `panelsMarked()`; `renderDropdown*()` forward)
- tests:
  - create `candy-code/tests/Tui/MenuOverlayFrameTest.php`. It is a table over the five frame paths: an open menu is painted AND its row zones land on painted cells. It holds the three regressions:
    - bug 1: the Agents dashboard paints the menu;
    - bug 2: the video player registers no unpainted row zone;
    - bug 3: at 80×24, every menu index reached by F10 then ←/→ gives no line wider than 80.
  - modify `candy-code/tests/Tui/MenuDropdownTest.php` and `candy-code/tests/Tui/MenuBarMouseTest.php` (`testTheAgentDashboardFrameStillRecordsTheMenuTitles` now also records rows).
- docs/guards: none
- serialized: [renderer:Tui/Renderer.php overlayDropdown + scanChrome + the five frame-path tails] [hot:candy-code/src/Tui/Components/MenuBar.php]
- deps: P-MENU.a, P-IMG.b (serial: layout)
- phase: normal
- ruling: none
- risks: P-IMG.b recomposes `renderMediaForm` over a veiled backdrop. Rebase onto it and keep its "menu bar stays clickable" assertion green.

### P-MENU.d — Swap the bar to the five-menu tree: keyboard, click, dispatch, `»` overflow
- item: P-MENU-REDO (sources: owner pick; supersedes P-IMG.a; latent bug 4)
- kind: feature · effort M
- approach:
  - **MenuBar.**
    - `menus()` reads the `ShellMenu::tree()` titles.
    - The static `$activeMenu`/`$activeItem` pair becomes one static `MenuCursor`.
    - `handleKey()` is `MenuCursor::key()`, and `selectItem()` takes a path (an int still means a root row).
    - `panels()` returns the `MenuCascade`, and `activeMenuColumn()` counts painted titles only.
    - The deprecated `get*` forwarders stay.
  - **Narrow widths.** Titles drop from the right into a `»` title (`tui.menu.more`). Its dropdown lists the dropped menus as submenus, so every row stays reachable at any width; this is P-IMG.a's overflow idea, done through the nested model. The tab strip stays in this step (P-MENU.e moves it), so the titles keep today's budget rule.
  - **Dispatch by name.**
    - Leaves raise `MenuSelectedMsg(menu, label, action: 'cmd:<name>' | 'pane:<value>')`.
    - `App::dispatchMenuSelection()` sends `cmd:` to `runRegistryCommand($name)`, with the same settings-view rule (:4000). It sends `pane:` to a `togglePaneFromChrome(Pane)` extracted from the `panetab:` click arm (:3468-3482), so a keyboard toggle and a click are one door.
    - With `action === null`, the label lookup stays. `AppModelTest`, `MenuCommandDraftPreservationTest` and `SettingsCommandTest` build label-only messages and do not move.
  - **Bug 4 (root cause).** While a menu is open, a key the menu answers returns from the open-menu block (`KeyboardHandler.php:591-603`). It never reaches the skill picker (:612) or `handleNavigation()` (:632).
  - **KeyBindingRegistry `menu()`** (:1327-1337):
    - `menu.switch` (`← / →`, from a leaf row) is kept;
    - new `menu.submenu` (`→ / ←`: open the highlighted submenu, then close it again);
    - `menu.run` is reworded to "Run the row, or open its submenu (or o)";
    - `menu.close` is reworded to "Close the submenu, then the menu (or q)".

    Each two-effect row is driven both ways, per the drift test's own rule (`KeyBindingDriftTest.php:78-88`).
  - **Pane tabs.** They stay on the bar for this one step. View ▸ Panes rows carry `menuitem:` zones here and toggle by keyboard and click; P-MENU.e moves the `panetab:` zones onto them.
- files.create: none
- files.modify:
  - `candy-code/src/Tui/Components/MenuBar.php`: `menus()`, `compose()` (title loop + `»`), `handleKey()`, `cycleMenu()`, `moveItem()`, `itemsOf()`, `selectMenuItem()`, `selectItem()`, `openMenu()`, `activateMenu()`, `closeMenu()`, `activeItem()`, `menuItems()`, `panels*()`, `activeMenuColumn()`;
  - `candy-code/src/Tui/Components/MenuSelectedMsg.php`: `?string $action = null`;
  - `candy-code/src/App/App.php`: `dispatchMenuSelection()`; in `dispatchChromeClick()`, the `menuitem:` path parse, and the `panetab:` arm moved onto `togglePaneFromChrome()`;
  - `candy-code/src/Tui/KeyboardHandler.php`: the open-menu block;
  - `candy-code/src/Commands/KeyBindingRegistry.php`: `menu()`;
  - `candy-code/lang/en.php`: `keys.menu.*`, `tui.menu.more`;
  - `candy-code/README.md`: L308 (the form's doors gain `F10 ▸ Tools ▸ Generate image…`); L356 (Mouse: "clicking the menu bar opens a menu", plus submenus);
  - `candy-code/docs/MEDIA.md`: L85 (the form's doors).
- tests:
  - create `candy-code/tests/Tui/MenuReachabilityTest.php`: at 30/40/60/80/120/200 cols, every `ShellMenu` leaf is reachable from F10 by keys, and by clicks through painted titles or `»`; its selection dispatches the spec it names; at 80 cols, `F10 → → Enter` opens the media form.
  - create `candy-code/tests/Tui/MenuKeyboardOwnershipTest.php` (bug 4 regression): a menu open over the Agents dashboard and over an open skill picker; ↑/↓ move only the menu; `selectedAgentIndex` and the picker cursor are unchanged.
  - create `candy-code/tests/App/MenuActionDispatchTest.php`: the `cmd:` and `pane:` actions; the label fallback; `pane:` and `panetab:` leave identical App state.
  - modify:
    - `candy-code/tests/Tui/MenuBarTest.php`, `candy-code/tests/Tui/MenuOpenTest.php`, `candy-code/tests/Tui/MenuDropdownTest.php`, `candy-code/tests/Tui/MenuBarMouseTest.php`;
    - `candy-code/tests/Tui/TuiComponentTest.php` (`testMenuBarRenderProducesNonEmptyOutputWithMenuItems` pins the old category titles);
    - `candy-code/tests/Tui/ShellContrastTest.php` (cascade rows);
    - `candy-code/tests/Commands/KeyBindingDriftTest.php` (the `menu.*` observations :2215-2261; the `openMenuAt()`/`openMenuRowCount()`/`menuCount()` reflection helpers :2538-2575; the `activeItem` reflection :3250).
- docs/guards:
  - README.md and docs/MEDIA.md prose. There is no generated roster: `docs/COMMANDS.md` does not render categories (`CommandDocGenerator.php:107-118`).
  - `KeyBindingDriftTest` moves in-step with the `menu.*` rows.
- serialized: [lang] (prefix `keys.menu.`, `tui.menu.more`) [docs:README.md] [docs:MEDIA.md] [hot:candy-code/src/App/App.php] [hot:candy-code/src/Tui/Components/MenuBar.php] [hot:candy-code/src/Tui/KeyboardHandler.php] [hot:candy-code/src/Commands/KeyBindingRegistry.php]
- deps: P-MENU.b, P-MENU.c
- phase: normal
- ruling: none (it consumes R-MENU-1 through P-MENU.b)
- risks: the menu state is process-static. Every touched test's `setUp` must reset the cursor (as `closeMenu()` does today), or a half-open submenu leaks between tests.

### P-MENU.e — Pane toggles leave the bar: View ▸ Panes owns `panetab:`, hover, indicator door
- item: P-MENU-REDO (sources: owner pick)
- kind: feature · effort M
- approach:
  - **The bar.**
    - `compose()` drops the pane-tab strip and its three densities (`MenuBar.php:94-114`, :220-288).
    - The right end keeps only the `Currently: <pane>` indicator (R-MENU-2). It goes terse (label only) below 61 cols and disappears below 50, so the five titles always come first.
    - The indicator becomes a click door, zone `panesmenu`, that opens View ▸ Panes.
  - **One list.** `PANE_TABS` (:94-102) is folded into `Pane::tabCycle()`, the list `PaneReverseCycleTest` already pins it equal to. Later panes (P-CTX.e's Context, P-TABS.g's Sessions) therefore appear in View ▸ Panes with no MenuBar edit. This is consolidation; no code is deleted.
  - **The `panetab:<pane>` zone moves from the bar to the Panes rows.** `ShellMenu` sets each toggle node's `zone`. The existing click arm (`App.php:3468`) and the drag-to-dock arm (`dragTargetPane()`, :3071) stay wired unchanged:
    - a click toggles docking;
    - a press-drag on a docked pane's row carries the pane to a side, and `beginPaneDrag()` closes the menu once the drag arms;
    - toggling keeps the submenu open and flips the check in place (R-MENU-3).

    The rows keep the old tab's three states: focused is `shellPrimary` bold underlined, docked is ✓ in `shellForeground`, undocked is blank in `shellMuted`.
  - **Hover.**
    - **Mouse mode.** CellMotion reports no button-less motion (`Chat.php:6034`). So while any menu is open, the shell arms `Cmd::enableMouseAllMotion()`, and it restores `Cmd::enableMouseCellMotion()` when the menu closes. This is ONE transition check in `App::update()` (:2714), comparing `MenuBar::activeMenu() > 0` before and after every message, so every close path is covered. It is skipped when the mouse is Off.
    - **Motion routing.** While a menu is open, `handleShellMouse()` (:2883) consumes motion. Over a `menuitem:` or `panetab:` row it calls `MenuBar::hover()` (highlight; a submenu row expands). Anywhere else the motion is swallowed, so the chat never receives hover.
- files.create: none
- files.modify:
  - `candy-code/src/Tui/Components/MenuBar.php`: `compose()`, `density()`, `paneTabs()`→indicator, `PANE_TABS`, new `hover()`, `PANES_DOOR_ZONE`;
  - `candy-code/src/Tui/Menu/ShellMenu.php`: the Panes rows' `zone`;
  - `candy-code/src/App/App.php`: `update()` (mouse-mode transition), `handleShellMouse()` (motion arm), `beginPaneDrag()` (closes the menu), `dispatchChromeClick()` (`panesmenu` arm);
  - `candy-code/README.md`: L353-357 (Mouse); L372-386 (Pane docking: the tabs become View ▸ Panes, the density sentence goes, dragging starts from the row);
  - `candy-code/docs/ARCHITECTURE.md`: zone map L1176-1177; bullets L1200-1205 (also corrects their stale pane list).
- tests:
  - create `candy-code/tests/Tui/MenuHoverTest.php`:
    - AllMotion is armed when a menu opens by F10 and by click;
    - CellMotion is restored on Esc, after a row runs, and after a click-away;
    - no mode Cmd is emitted when the mouse is Off;
    - motion over a row highlights it and expands a submenu;
    - motion with no menu open still reaches Chat.
  - create `candy-code/tests/App/PanesSubmenuToggleTest.php`:
    - Enter and click on each Panes row have `MenuBarPaneTabClickTest`'s semantics;
    - the submenu stays open and the check flips;
    - the indicator door opens View ▸ Panes.
  - modify:
    - `candy-code/tests/Tui/MenuBarCompactStripTest.php` (indicator densities; titles first);
    - `candy-code/tests/App/MenuBarPaneTabClickTest.php` (the zones are now on the rows);
    - `candy-code/tests/App/DockDragBytePathTest.php` (the drag starts from the Panes row);
    - `candy-code/tests/MousePaneJumpDoesNotHandOverTest.php` (docblock);
    - `candy-code/tests/Tui/ComponentTest.php` (the pane-tab assertions move to the Panes rows; the `Currently:` pins are unchanged);
    - `candy-code/tests/Tui/TodoPaneTest.php` (`testTheMenuBarAdvertisesTheTodoTab`);
    - `candy-code/tests/Tui/Components/SettingsPaneTest.php` (`testTheTabStripAdvertisesSettings`);
    - `candy-code/tests/Tui/PaneReverseCycleTest.php` (its pin becomes: Panes rows == `tabCycle()`).
- docs/guards: README.md and docs/ARCHITECTURE.md prose. No generated roster moves. The `mouse.*` key rows do not change, so `KeyBindingDriftTest` does not move.
- serialized: [docs:README.md] [docs:ARCHITECTURE.md] [hot:candy-code/src/App/App.php] [hot:candy-code/src/Tui/Components/MenuBar.php]
- deps: P-MENU.d
- phase: normal
- ruling: R-MENU-2 (keep the `Currently:` indicator as a door to View ▸ Panes; default yes) and R-MENU-3 (the submenu stays open after a toggle; default yes)
- risks: AllMotion floods the read loop, but only while a menu is open. A missed close path would leave it on, so the single transition check and MenuHoverTest's close-path table are the guard.

## Notes for the orchestrator

- **Supersession of P-IMAGE-MENU.**
  - **P-IMG.a is REPLACED by P-MENU.b + P-MENU.d. Drop it from the plan**, along with everything it carried:
    - `CommandRegistry::MENU_ORDER`;
    - the Media category;
    - `CommandSpec::$menuVisible`;
    - its edits to `builtin-commands/4000-4040`;
    - `MenuOverflowTest`.

    Its reachability test becomes `MenuReachabilityTest`, and its README L308 and `docs/MEDIA.md` edits move into P-MENU.d.
  - **P-IMG.b stays** and is a dep of P-MENU.c (serial: layout).
  - **P-IMG.c stays** and is independent.
  - As a result, P-MENU touches **no [commands] file**, so P-IMG.a's [commands] collision with P-CTX.b is gone. Palette grouping is unchanged.
- **Layout chain** (`src/Tui/Renderer.php`, at most one per wave): P-IMG.b → **P-MENU.c** → P-CTX.e → P-TABS.g. Insert P-MENU.c as link #2, and add it to P-CTX.e's deps.
- **MenuBar.php / App.php chain:** P-MENU.c → P-MENU.d → P-MENU.e.
  - **Preferred:** P-MENU.e lands before P-CTX.e and P-TABS.g. Those two then **drop their `MenuBar::PANE_TABS` edit and their `MenuBarCompactStripTest` edit**, because View ▸ Panes derives from `Pane::tabCycle()`.
  - **If they land first:** P-MENU.e's consolidation absorbs their rows.
  - Either way, never in the same wave: they share `App.php` and `MenuBar.php`.
- **New frame paths must paint the menu.** C-TUI-2.e (`renderDiffReview`), C-TUI-3.d (`renderMessagePicker`), C-TUI-5.e (the wizard) and C-SEC-5.d (the trust dialog, if it composites in `Tui/Renderer.php`) add full-band paths. After P-MENU.c, each must call `overlayMenu()` and the `scanChrome()` panel pass, and add its state to `MenuOverlayFrameTest`'s table. Otherwise bug 1 comes back.
- **Hot files.**
  - `KeyBindingRegistry.php`: P-MENU.d edits `menu()` only. Also touched by C-TUI-2.e, 3.d, 3.e and 5.e, C-EXT-5.c, P-TABS.e, P-TABS.g, P-CTX.e and P-AGENT.b.
  - `KeyboardHandler.php`: P-MENU.d edits the open-menu block only. Also touched by C-SEC-5.d, P-TABS.e, P-CTX.e and P-TABS.g.
  - Lang prefixes: `tui.menu.` (b), `keys.menu.` + `tui.menu.more` (d).
- **New test files for the weld** (durations TSV):
  - `tests/Tui/Menu/MenuCursorTest.php`, `MenuCascadeTest.php`, `MenuTreeTest.php`, `ShellMenuTest.php`;
  - `tests/Tui/MenuOverlayFrameTest.php`, `MenuReachabilityTest.php`, `MenuKeyboardOwnershipTest.php`, `MenuHoverTest.php`;
  - `tests/App/MenuActionDispatchTest.php`, `PanesSubmenuToggleTest.php`.
- **Web:** no menu bar exists in `candy-code-web/src-web`, so there is no parity step (see "Current state").
- **Laws.**
  - No fork sites, no E646 exposure, no LATE items, no protocol.
  - No `Chat.php` touch. `Chat.php:7377`'s docblock names `MenuBar::paneTabs()`; fold the one-word fix into any later [chat] lane.
  - Dormant code is kept and rewired:
    - the `panetab:` zone, click arm and drag arm move to the rows;
    - `PANE_TABS` is folded into `tabCycle()`;
    - the `get*` and `renderDropdown*()` forwarders stay.
- **Could not verify (read-only).**
  - Bugs 1–4 were read from source, not run. Each step's regression test must go red first.
  - Whether terminals, tmux included, honour the CellMotion↔AllMotion switch mid-session. `MenuHoverTest` pins only the Cmds; the live check is the tmux `send-keys -H` repro.
  - The exact derived labels of rows with no `paletteLabel`. The table shows them by `/name`, and they do not change.
