# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[law:bare-accessor-era]** sugar-table ships ZERO `getFoo()`-form public
  accessors (owner ruling 3b audit 2026-10-24 — the pre-era "getStyle()
  25-file rename" row counts repo-wide `getStyle` SITES, all in
  sugar-dashboard/sugar-widgets/sugar-forms' own classes; no `getStyle()` ever
  existed in this lib). Property reads ride public readonly state or bare
  methods. Ruled keepers — keyed `RowData::get($key)`/`RowData::has($key)`
  (fleet lookup vocabulary) and the `Table::isExpanded($rowIndex)` predicate —
  are pinned with a README tie by `tests/BareAccessorEraTest.php`; a new
  `getFoo()` accessor, a removed keeper, or roster/README drift goes red.
  Fleet-wide: legacy `get*()` BC forwarders remove no earlier than v2.0.0.

- **[pattern:sugar-table]** i18n via `SugarCraft\Table\Lang::t()`. Each library
  carries its own thin `Lang` facade (e.g. `src/Lang.php`) that wraps
  `SugarCraft\Core\I18n\T` with the library namespace baked in. Translation files
  live in `lang/<code>.php` (e.g. `lang/en.php`). The facade calls
  `T::register(namespace, __DIR__ . '/../lang')` on every invocation — this is
  idempotent so no static bootstrap is needed. See `candy-ssh-launcher/src/Lang.php`
  and `sugar-datepicker/src/Lang.php` for the same pattern.

- **[pattern:viewport-virtualization]** slicing visible row range + scrollY offset.
  In `Table::View()`, when `$viewportHeight > 0`, rows are sliced via
  `array_slice($rows, $this->scrollY, $this->viewportHeight)` so only the visible
  window is rendered. Callers drive `withScrollY()` to scroll; `renderToBuffer()` clamps the render
  window to `maxScrollY()` so a stale scroll can never strand the viewport.

- **[pattern:frozen-columns]** pin columns from the left via `withFrozenCols([$idx, ...])`.
  `isColumnVisible(int $colIndex)` determines visibility: frozen columns (in
  `$this->frozenCols`) are always visible; non-frozen columns are visible starting
  at index `count($this->frozenCols) + $this->scrollX`. `computeVisibleContentWidth()`
  sums widths of visible columns plus 1-char separators between consecutive visible
  columns — used by `fillHeaderSeparatorRow()` for correct border sizing. When
  rendering, skipped columns still increment the buffer column position (`$col += $colWidth`)
  so subsequent visible columns align correctly. Guards: `withFrozenCols()` refuses a non-contiguous prefix (`[0, 2]`)
  and `withHiddenCols()` refuses overlap with the frozen prefix — both throw
  `InvalidArgumentException`. See `Table.php::withFrozenCols()/isColumnVisible()/computeVisibleContentWidth()`.

- **[pattern:column-width-enum]** Fixed/Percent/Dynamic/Content/Flex with multi-pass
  computation. `Table::computeColumnWidths()` runs two passes: first collects
  explicit widths and counts flex slots, then distributes the leftover across
  the flex slots (Flex weighted by `Column::$flexibleWidth`; Dynamic/Content hold one
  share each). `ColumnWidth::Percent` requires a `$percentValue ∈
  [0.0, 100.0]` validated at `Column::withColumnWidth()`. See `ColumnWidth.php`.

- **[pattern:column-width-rendering]** `computeColumnWidths()` is called at the
  start of `renderToBuffer()` and the resulting widths are passed to
  `fillHeaderRow()` and `fillDataRow()`. This ensures computed widths — not raw
  `Column.width` values — are used consistently across the entire render pass
  (header, separators, data cells). The widths are cached in
  `$this->computedColumnWidths` for the duration of the render. See
  `Table::renderToBuffer()` and `computeTotalWidth()`, which also calls
  `computeColumnWidths()` to keep total width consistent with actual rendering.

- **[pattern:computeTotalWidth-fixed-point]** `computeTotalWidth()` iterates the
  width solve to a fixed point (`WIDTH_CONVERGE_MAX` caps the loop). The historical
  single-pass version over/under-shot when mixing `ColumnWidth::Percent` with
  `Dynamic`/`Content`; fixed 2026-07-11 (44fb145df). All-Fixed layouts skip the
  iteration. See `Table::computeTotalWidth()`.

- **[pattern:cell-wrap-return-list-string]** renderCell returns list for wrapping;
  callers use `$cell[0]`. `Column::renderCell()` always returns `list<string>` (one
  per line after wrapping). Single-line callers (e.g. `renderHeader()`) use only
  the first element. Multi-line callers iterate all elements. This is why
  `renderHeader()` returns `string` not `list<string>` — it never wraps. See
  `Column::renderCell()`.

- **[pattern:percent-value-range]** percent values should be validated 0.0–100.0.
  `Column::withColumnWidth(ColumnWidth::Percent, $percentValue)` throws
  `\InvalidArgumentException` if `$percentValue` is outside `[0.0, 100.0]`. Guard
  fires before any object construction. See `Column::withColumnWidth()`.

- **[pattern:border-from-sprinkles]** `Table::withBorder(Border)` consumes
  `\SugarCraft\Style\Border\Border` — the 13-rune box border family from
  sugar-style. Available factories: `Border::normal()`, `rounded()`,
  `thick()`, `double()`, `block()`, `ascii()`, `hidden()`, `markdownBorder()`.
  Border getter methods (`borderTopLeft()`, etc.) fall back to existing
  `$borderStyle` string property for backward compatibility via null-coalescing.
  `middleLeft` maps to column separators in header/row separator lines. See
  `Table::withBorder()` and `sugar-style/src/Border.php`.

- **[pattern:multilineMode-row-height]** when `multilineMode=true`, row height
  equals the maximum cell height across all columns; `fillDataRowLines()` iterates
  all cell lines to build the full row height. `calculateRowHeight()` computes the
  max line count across all visible cells using each column's `renderCell()`. When
  `false` (the default), cells are clamped to one line preserving backward
  compatibility. See `Table::$multilineMode`, `Table::calculateRowHeight()`, and
  `Table::fillDataRowLines()`.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.

- **[pattern:assert-golden-ansi]** Use `assertGoldenAnsi` for any new `render()` test. Fixture files live in `tests/fixtures/` with a `.golden` extension. Re-record goldens with `SUGAR_TESTING_UPDATE_GOLDENS=1 vendor/bin/phpunit` after intentional output changes (audit ST-46: the prefixed env is the current spelling — the bare `UPDATE_GOLDENS` form remains a legacy alias; both are CREATE-only, so re-baselining means deleting the stale fixture first. README's Snapshot tests section is the authority). Mirrors: `docs/old/repo_map_step_28.md`.
- **[pattern:stylefunc-signature-shift]** `Table::withStyleFunc()` now accepts
  `(int $row, int $col, string $value): Style|string`. The new `Style`-return path
  is the preferred style (PHP 8.3+ typed return, immutable). The real funnel is
  `Table::normalizeStyleResult()`: a `Style` return wins, an SGR **string** return passes
  through verbatim (`Style::fromAnsiString()` exists in neither this lib nor sugar-style,
  and `computeCellStyle()` never did), and anything else — including a
  `SugarCraft\Style\Style` object — falls back to the inherited style. See
  `Table::normalizeStyleResult()`.

- **[pattern:global-search]** `search()` + `Filter()` are orthogonal concerns
  that stack. `search()` scans the filterable columns (OR logic): once **any** column
  is marked `withFilterable()` the scan narrows to marked columns only — an
  intentional mode-switch via `filterableKeys()`; with none marked, every column is
  scanned. `Filter()` shares the gate (AND logic — row must match all active column
  filters). They combine via `filteredSortedRows()` which applies column filters
  first, then global search. `search('')` and `ClearSearch()` both clear the global
  search. See `Table::search()/filterableKeys()/buildFilteredSortedRows()`.

- **[pattern:row-expansion]** rows are expanded by object identity (`Row` instance)
  stored in `$this->expandedRows`. All expansion methods (`withExpandedRows`,
  `toggleExpanded`, `isExpanded`) use page-relative indexing via `pagedRows()`
  rather than global row indices. `isExpandedByRow(Row $row)` is the private
  rendering helper that checks identity. This design ensures expansion state is
  stable across sorting/filtering since Row objects maintain their identity. Since the
  scroll-stranding fix, `withRows()` prunes expanded identities absent from the new data.
  Invalid indices throw `OutOfBoundsException` (fail fast). Expansion bypasses
  column width truncation in both `fillDataRow` (single-line) and
  `fillDataRowLines` (multiline). See `Table.php` lines 338–398
  (`withExpandedRows`/`toggleExpanded`/`isExpanded`), 1106
  (`fillDataRow` expansion check), and 1241 (`fillDataRowLines` expansion check).

- **[pattern:keyboard-navigation]** `scrollYForKey()` + `handleKey()` provide
  keyboard-driven vertical scrolling. `KEY_*` constants (`KEY_ARROW_UP`,
  `KEY_ARROW_DOWN`, `KEY_PAGE_UP`, `KEY_PAGE_DOWN`, `KEY_HOME`, `KEY_END`)
  identify supported keys. `scrollYForKey()` returns the new scroll offset
  using a `match` expression with per-key delta logic and bounds clamping via
  `maxScrollY()`. `handleKey()` is a convenience wrapper that chains
  `scrollYForKey()` + `withScrollY()` for immutable keyboard integration.
  Unrecognized keys are no-ops (return current `scrollY` unchanged). Requires
  `withViewportHeight()` to be set — when viewport height is 0, `maxScrollY()`
  returns 0 and scrolling is disabled. See `Table.php` lines 507–580
  (`KEY_*` constants, `scrollYForKey`, `handleKey`, `maxScrollY`).

- **[pattern:footer-type-enum]** `FooterType` enum controls footer content via
  `withFooterType(FooterType $type)`. Three cases: `Page` (default, shows "Page N
  of M"), `Rows` (shows "Showing X to Y of Z rows" via `RowsFooter()`), and
  `Both` (combines both with " | " separator). The `fillFooterRow()` method
  uses a `match` expression on `$this->footerType` to select which footer to
  render. Row count calculation uses `TotalRows()` (filtered+sorted count) and
  respects pagination via `page * pageSize` offset. The `showing_rows` i18n key
  is used via `Lang::t()`. See `FooterType.php` and `Table.php` lines 117
  (property), 278–283 (`withFooterType`), 727–738 (`RowsFooter`), and 1336–1376
  (`fillFooterRow`).

- **[pattern:cell-padding]** `withCellPadding(int $padding)` adds inner
  whitespace on left and right sides of each cell's content. The padding value
  is stored in `$this->cellPadding` and subtracted from effective content width
  in rendering methods via `$colWidth - (2 * $this->cellPadding)`. Padding does
  not affect column width calculations — the column width remains unchanged,
  only the content area shrinks. Applied in `fillDataRow()` (line 1286),
  `fillHeaderRow()` (line 1173), `fillDataRowLines()` (line 1426), and
  `calculateRowHeight()` (line 1334) to ensure consistent treatment across all
  rendering paths.

- **[pattern:hidden-columns]** `withHiddenCols(array $indices)` stores hidden
  column indices in `$this->hiddenCols`. `isColumnVisible()` checks hiddenCols
  first (returns false immediately if in array) before checking frozen status
  or scroll position. Hidden columns still participate in data storage, filtering,
  sorting, and search operations — only rendering is affected. This enables
  optional columns that can be toggled via UI without losing data. See
  `Table.php` lines 134–135 (property), 367–381 (`withHiddenCols`), and 1087–1098
  (`isColumnVisible` hidden check at line 1089–1092).

- **[history:accessor-rename-era]** sugar-table shipped zero `get*()` property
  accessors before the fleet rename window opened. Owner ruling 3b (dated
  2026-10-24) froze legacy `get*()` forwarders fleet-wide for removal no earlier
  than v2.0.0; this lib needed no forwarders — the pre-era audit row
  "getStyle() 25-file rename" counts repo-wide `getStyle` **sites**, all of them
  other libraries' own classes, and no `getStyle()` ever existed here. The ruled
  get/has/is keepers (`RowData::get`, `RowData::has`, `Table::isExpanded`) are
  keyed lookups and predicates, pinned fail-closed by
  `tests/BareAccessorEraTest.php` — the README "Accessors" section and that
  test's roster must move together.

- **[api:bool-over-fake-int]** `Row::withZebra()` took an int "stripe
  selector" (audit ST-28, finding 028) whose two states were a fake: only 0
  and 1 ever differed, every other value behaved as 0, and 0 duplicated what
  an even-position row already got from parity. Hard-switched to
  `withZebra(bool $on = true)` — true pins the odd stripe, false restores
  positional parity — pre-1.0 with a consumer census showing zero callers
  passing a non-default index. When an "enum-ish" int parameter has exactly
  one distinguishable non-default value, it is a bool wearing a costume.
