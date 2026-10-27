# P-CTX.c — TUI context pane components — CLOSED

**Commit:** `6a69bf018e6b4a54ea4da4e564f117bc2c6e8b34` on `cc/P-CTX.c` (worktree `/home/sites/cc-cmp-P-CTX.c`, base `29e3a494c`). Author+committer detain 1/1 separate greps, zero Co-Authored-By, no caliber (hook absent → manual skipped per lane law), never pushed/pulled.

## Files
| File | Δ |
|---|---|
| `candy-code/src/Tui/Components/ContextPane.php` | +289 (NEW) |
| `candy-code/src/Tui/Components/ContextPaneModel.php` | +154 (NEW) |
| `candy-code/tests/Tui/Components/ContextPaneTest.php` | +345 (NEW — **durations.tsv row owed to the gate**) |
| `candy-code/lang/en.php` | +8 (exclusive `tui.context.*` prefix: cache, dump, measuring, pruned, title, total, total_partial, unmeasured — all 8 quoted in ContextPane.php, TuiLang census both directions green) |

## Shape (per design block)
- `ContextPaneModel`: immutable VO — `?ContextCategories`, live `historyTokens`, `window`, `expanded`, `?drill`, `stale`, `?cachePercent`, `?prunedTokens`; `messageTokens()` follows live history while `stale`, other rows keep snapshot; `usedTokens()`/`percentOfWindow()` compose the collapsed total.
- `ContextPane::renderModel($model, Theme, width, rows)` + `layoutModel()` → `[frame, list<?string> rowKeys]`. Collapsed = exactly 4 bordered rows (bar, `81K / 200K · 21% ▸`). Expanded = bar + one row/category (11 pairwise-distinct block glyphs `█▓▒░▚▞▛▜▝▘▙`, each in its own theme token, `~tokens` right-aligned, `▸` on drillable), drill expands sub-rows in place (null key), footer `cache 94% · −4K pruned` + `dump prompt` (key `raw`). Cold snapshot → `measuring…`/`not measured`, never 0. Pure — no App, no timers (E646-clean), P-CTX.e adds the render(App) adapter/focus/dock.

## Gates (targeted only; cwd worktree candy-code, plain pipe, -u LINES -u COLUMNS)
- `--filter ContextPane` → **OK 12T / 388A** (+2 deprecations PRE-EXISTING house-wide, identical on untouched PaneFrameTest)
- `--filter TuiLang|LangParity` → **OK 16T / 4121A**
- `tests/Tui/ShellContrastTest.php` (colour-naming guard over src/Tui) → **OK 14T / 2408A**
- `--filter Context` broad → **OK 1827T**
- five-guard (ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift) → 133T, **exactly the 2 allowed staleness pair** (pinned 25,537 vs live) — no other reds

## Pins beyond the brief bullets
Box exact at every width 20–80 **unclipped** (see deviation 1); no-colour-literal structural scan of both new src files; row keys == visible rows; collapsed==expanded when nothing drilled except bar+rows (drill coverage explicit); cache footer both legs (percent + unmeasured).

## Deviations (judged, disclosed)
1. **sugar-style `width()` is content width** (border+padding added outside) — box gets `width−CHROME_COLS`; siblings pass full width only because the Renderer clips. Mine is pinned exact unclipped, so the law had to be honoured.
2. `free-space` colour = `shellInfo`: Theme exposes exactly 11 `Color` tokens (`markdown` is a Markdown theme object, `name` a string) → bijective assignment, docblock records that same-source coincident pairs (userLabel≡shellPrimary, assistantLabel≡shellSuccess, muted family) never sit on adjacent rows; distinctness is carried by the glyph set (brief's NO_COLOR pin).
3. Instructions drill sub-row renders its long note as the figure (truncated narrow).
4. Drill subs appear only under the drilled parent (per design); test drills two categories for both figure kinds.
5. `layoutModel()` has no scroll slot — box is short by construction; P-CTX.e clamps when docking.

## Seams for weld/gate
- NEW test file → `scripts/parallel-tests-durations.tsv` row + suite-figure re-pin (+12T) — deliberately NOT touched here per lane law.
- P-CTX.e consumes `layoutModel()` keys (`cat:*`/`raw`/`toggle`) for focus/mouse + render(App) adapter.

## round 2 fixes
d807cf3e2 ContextPane GLYPHS docblock: cite now {@see ContextCategories::bar()} (correct owner of bar()), whole citation on one unbroken line. Gates: ContextPane 12T/388A green; SymbolCitationDrift|DuplicatedDocBlockLine 13T/16075A green.
