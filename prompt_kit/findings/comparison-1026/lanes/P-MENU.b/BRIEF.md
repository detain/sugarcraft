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

