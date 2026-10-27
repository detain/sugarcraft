# P-MENU.a — nested menu kit (Tui/Menu/*) — LANDED

Tip: `6606dbc5d` on `cc/P-MENU.a` (worktree `/home/sites/cc-cmp-P-MENU.a`, base `3ee78848c`).
Single commit, author+committer `Joe Huss <detain@interserver.net>` (separate %ae/%ce greps = 1/1), zero trailers, never pushed.

## Files (numstat 3ee78848c..HEAD — 1883 insertions, 0 deletions, ALL new)

- `candy-code/src/Tui/Menu/MenuNodeKind.php` (+28) — Command/Toggle/Submenu/Separator enum
- `candy-code/src/Tui/Menu/MenuNode.php` (+77) — final readonly row, factories + `withChecked()`, `zone` escape hatch
- `candy-code/src/Tui/Menu/MenuTree.php` (+141) — path algebra: `rows()/node()/pathOf()/leaves()`
- `candy-code/src/Tui/Menu/MenuStep.php` (+26) — {cursor, ?chosen}
- `candy-code/src/Tui/Menu/MenuCursor.php` (+259) — key()/hover(); both →/← meanings, wrap+skip, Esc pops one level, hover expands without entering
- `candy-code/src/Tui/Menu/MenuPanel.php` (+74) — box geometry; `width()` measures VISIBLE cells
- `candy-code/src/Tui/Menu/MenuCascade.php` (+377) — layout: root shift-left, child-on-parent-row flip/right-align, shift-up then window with `…` rows, ✓/blank check column, icon+label, right-aligned ` ▸`, ├─┤ separators; tokens border/shellForeground/shellPrimary+bold/shellMuted; marked twin `menuitem:<dot-path>` (1-based, menu index excluded, `node->zone` override)
- tests: `MenuCursorTest.php` (+302, 16 tests), `MenuCascadeTest.php` (+384, 10 tests), `MenuTreeTest.php` (+131, 8 tests)
- goldens (UPDATE_GOLDENS=1, MediaForm pattern): `golden/{root-80x24,root-child-80x24,flip-left-80x24,windowed-40x12}.txt`

## Durations duty — NEW test files for the gate to add to scripts/parallel-tests-durations.tsv

1. `candy-code/tests/Tui/Menu/MenuCursorTest.php`
2. `candy-code/tests/Tui/Menu/MenuCascadeTest.php`
3. `candy-code/tests/Tui/Menu/MenuTreeTest.php`

(never edited durations.tsv or suite-figure.json here; +34 test methods, live enum now 25,828)

## Gates (cwd worktree candy-code, plain pipe, env -u LINES -u COLUMNS)

- `--filter 'Menu'`: **OK — 208 tests, 2730 assertions, 0F 0E** (incl. 73 pre-existing MenuBar tests, unbroken); 2 PHPUnit deprecations proven PRE-EXISTING (MenuBar-only run shows the same 2)
- five-guard `ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift`: 133 tests, **only reds = the allowed staleness pair** (README/artifact 25,537 vs live 25,828 — in the brief's ~25,80x band; +257 predates this lane, +34 are its own methods)
- `php tools/check-one-type-per-file.php`: `62 libs, 3164 psr-4 files, 0 problems` RC=0
- tree clean at tip; zero existing files touched (files.modify NONE honored); no lang keys (kit mints no user strings)

## Deviations / decisions (all inside the design block)

- **Zone ids 1-based** (`menuitem:3` = third root row, `menuitem:3.1` its child's first row); navigation paths 0-based. Brief's `3.1` example reads 1-based; "no menu index in it" honored.
- **Mark id charset**: `Mark::zone` rejects `/` in ids, so the zone-override docblock example is spelled `cmd:compact` (no leading slash) and Mark itself fail-closes at paint time — pinned by an errored fixture caught in-test (originally `cmd:/play`).
- **Width law measures visible cells**: `Width::string` counts PUA sentinel+id bytes, so `MenuPanel::width()` (and the twin-equality/frame asserts) strip zone wrappers first — otherwise marked placement would diverge from painted. Sentinel-strip regex deliberately spelled `{0,}` (plan §1.9 glob-literal hazard; GlobDialect guard green).
- `MenuPanel` gained `firstRowIndex` (window start) beyond the block's five fields: needed for callers/tests to rebuild zone ids on a windowed panel.
- **Every keyboard move drops the hover preview**; expansion follows hover([submenu-row]) or entering with →/Enter — deterministic reading of "highlights it AND expands … WITHOUT entering".
- Left/Escape collapse order: preview → innermost entered panel → (menu depth) left walks to previous menu, Escape closes.
- Filler blank rows exist only as a windowing-arithmetic corner guard; windowed bands fill exactly.

## Production reachability

Dormant by design at this step (pure kit, files.modify NONE): the P-MENU.b consumer (MenuBar rewire) wires it; nothing here edits existing code, so no unreachable-branch red flags apply to this lane.
