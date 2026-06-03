# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:sugar-table]** i18n via `SugarCraft\Table\Lang::t()`. Each library
  carries its own thin `Lang` facade (e.g. `src/Lang.php`) that wraps
  `SugarCraft\Core\I18n\T` with the library namespace baked in. Translation files
  live in `lang/<code>.php` (e.g. `lang/en.php`). The facade calls
  `T::register(namespace, __DIR__ . '/../lang')` on every invocation — this is
  idempotent so no static bootstrap is needed. See `sugar-wishlist/src/Lang.php`
  and `sugar-calendar/src/Lang.php` for the same pattern.

- **[pattern:viewport-virtualization]** slicing visible row range + scrollY offset.
  In `Table::View()`, when `$viewportHeight > 0`, rows are sliced via
  `array_slice($rows, $this->scrollY, $this->viewportHeight)` so only the visible
  window is rendered. Callers drive `withScrollY()` to scroll. See `Table.php` lines
  566–573.

- **[pattern:column-width-enum]** Fixed/Percent/Dynamic/Content with multi-pass
  computation. `Table::computeColumnWidths()` runs two passes: first collects
  explicit widths and counts flex slots, then distributes remaining space to
  Dynamic/Content columns. `ColumnWidth::Percent` requires a `$percentValue ∈
  [0.0, 100.0]` validated at `Column::withColumnWidth()`. See `ColumnWidth.php`
  and `Table.php` lines 468–527.

- **[pattern:column-width-rendering]** `computeColumnWidths()` is called at the
  start of `renderToBuffer()` and the resulting widths are passed to
  `fillHeaderRow()` and `fillDataRow()`. This ensures computed widths — not raw
  `Column.width` values — are used consistently across the entire render pass
  (header, separators, data cells). The widths are cached in
  `$this->computedColumnWidths` for the duration of the render. See
  `Table.php` lines 661–722 and `computeTotalWidth()` which also calls
  `computeColumnWidths()` to maintain consistency between total width calculation
  and actual rendering (lines 1304–1326).

- **[pattern:computeTotalWidth-single-pass]** `computeTotalWidth()` uses a
  single-pass approximation that may not converge when mixing
  `ColumnWidth::Percent` with `ColumnWidth::Dynamic`/`Content`. The method
  should iterate until the result stabilizes (the iteration converges in 3–6
  steps for typical tables). Until fixed, avoid combinations that trigger
  non-convergence, or use all-Fixed widths for predictable sizing. See
  `Table.php` lines 1304–1326.

- **[pattern:cell-wrap-return-list-string]** renderCell returns list for wrapping;
  callers use `$cell[0]`. `Column::renderCell()` always returns `list<string>` (one
  per line after wrapping). Single-line callers (e.g. `renderHeader()`) use only
  the first element. Multi-line callers iterate all elements. This is why
  `renderHeader()` returns `string` not `list<string>` — it never wraps. See
  `Column.php` lines 128–150 and `Table.php` line 645.

- **[pattern:percent-value-range]** percent values should be validated 0.0–100.0.
  `Column::withColumnWidth(ColumnWidth::Percent, $percentValue)` throws
  `\InvalidArgumentException` if `$percentValue` is outside `[0.0, 100.0]`. Guard
  fires before any object construction. See `Column.php` lines 100–105.

- **[pattern:border-from-sprinkles]** `Table::withBorder(Border)` consumes
  `\SugarCraft\Sprinkles\Border\Border` — the 13-rune box border family from
  candy-sprinkles. Available factories: `Border::normal()`, `rounded()`,
  `thick()`, `double()`, `block()`, `ascii()`, `hidden()`, `markdownBorder()`.
  Border getter methods (`borderTopLeft()`, etc.) fall back to existing
  `$borderStyle` string property for backward compatibility via null-coalescing.
  `middleLeft` maps to column separators in header/row separator lines. See
  `Table.php` lines 234–246 and `candy-sprinkles/src/Border.php`.

- **[pattern:multilineMode-row-height]** when `multilineMode=true`, row height
  equals the maximum cell height across all columns; `renderRowLines()` iterates
  all cell lines to build the full row height. When `false` (the default), cells
  are clamped to `maxLines = 1` preserving backward compatibility. See
  `Table.php` lines 241–246.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.

- **[pattern:assert-golden-ansi]** Use `assertGoldenAnsi` for any new `render()` test. Fixture files live in `tests/fixtures/` with a `.golden` extension. Re-record goldens with `UPDATE_GOLDENS=1 vendor/bin/phpunit` after intentional output changes. Mirrors: `docs/repo_map_step_28.md`.
- **[pattern:stylefunc-signature-shift]** `Table::withStyleFunc()` now accepts
  `(int $row, int $col, string $value): Style|string`. The new `Style`-return path
  is the preferred style (PHP 8.3+ typed return, immutable). A back-compat
  wrapper inside `Table::computeCellStyle()` normalizes plain ANSI SGR strings to
  `Style::fromAnsiString()` so existing callers work without modification. The
  wrapper is a single conditional — no interception layer — so the hot path is
  zero-overhead for `Style` returns. See `Table.php` lines 580–600.
