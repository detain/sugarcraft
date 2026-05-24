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
