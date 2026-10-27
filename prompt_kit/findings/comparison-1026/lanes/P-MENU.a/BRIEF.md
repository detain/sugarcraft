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

