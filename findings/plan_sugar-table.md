---
status: not-started
phase: 1
updated: 2026-06-30
---

# Implementation Plan: sugar-table Audit Fixes

## Goal
Address all 13 findings from the sugar-table audit (2 MEDIUM, 11 LOW/INFO) to improve validation, performance, and API ergonomics.

## Context & Decisions
| Decision | Rationale | Source |
|----------|-----------|--------|
| Add validation in `withHiddenCols`/`withFrozenCols` rather than `isColumnVisible` | Fail-fast principle: detect conflicts at configuration time, not render time | Finding #1 |
| Add `columnExists()` helper for Filter/SortBy validation | Consistent with other SugarCraft libs that validate before operation | Finding #2 |
| Pre-compute visible column indices once before row loop | Performance: `isColumnVisible` called N×M times (rows×cols) but result is constant per column | Finding #4 |
| Short-circuit navigation at boundaries before calling filteredSortedRows() | Performance: avoid expensive computation when no-op | Finding #5 |
| Add `column(string $key)` accessor | Ergonomic API improvement common in collection types | Finding #11 |
| Add `withFlexWidth(int $share)` alias to match upstream `WithFlexWidth()` | API compatibility with bubble-table | Finding #12 |
| Add `SelectedRow()` convenience method | Reduces boilerplate for common operation | Finding #13 |

---

## Phase 1: Validation & Error Handling [PENDING]

### 1.1 [MEDIUM] Hidden + Frozen Column Conflict Detection
- **Task**: Add validation in `withFrozenCols()` and `withHiddenCols()` to detect overlapping indices
- **What**: When a column index appears in both `$hiddenCols` and `$frozenCols`, throw `\InvalidArgumentException` with a clear message
- **Why**: Silent precedence (hidden overrides frozen) is surprising behavior that leads to user confusion
- **Severity**: MEDIUM
- **Related code**: `src/Table.php:258-263` (withFrozenCols), `src/Table.php:449-454` (withHiddenCols), `src/Table.php:1283-1294` (isColumnVisible)
- **Conditions for success**:
  - `$table->withFrozenCols([0])->withHiddenCols([0])` throws `\InvalidArgumentException`
  - Error message mentions both frozen and hidden conflict
  - Tests verify the exception is thrown
- **Investigation notes**:
  - `withFrozenCols()` at line 258-263 simply stores the array without validation
  - `withHiddenCols()` at line 449-454 similarly stores without validation
  - `isColumnVisible()` (line 1283-1294) checks hidden first, then frozen — hidden silently wins

### 1.2 [MEDIUM] Invalid Column Keys in Filter() and SortBy()
- **Task**: Add column key validation to `Filter()` and `SortBy()`
- **What**: Throw `\InvalidArgumentException` when `$colKey` does not match any column's key (unless opt-in filter gating allows it for Filter)
- **Why**: Silent no-op on invalid keys leads to confusing bugs where operations appear to work but have no effect
- **Severity**: MEDIUM
- **Related code**: `src/Table.php:701-731` (SortBy), `src/Table.php:745-765` (Filter)
- **Conditions for success**:
  - `$table->SortBy('invalid_key')` throws `\InvalidArgumentException`
  - `$table->Filter('invalid_key', 'text')` throws `\InvalidArgumentException`
  - Valid keys continue to work
- **Investigation notes**:
  - `SortBy()` (line 701) accepts any string without validation
  - `Filter()` (line 745) checks `filterableKeys()` but not whether key exists at all — if no columns are filterable, it no-ops on missing keys too
  - Existing `filterableKeys()` helper at line 847-859 could be extended or a new `columnExists()` helper added

### 1.3 [LOW] Improved Error Message for Empty Page in withExpandedRows()
- **Task**: Fix misleading error message in `withExpandedRows()` when page is empty
- **What**: Change error message from "Invalid row index {$idx}" to something like "Invalid row index {$idx}: page has no rows"
- **Why**: Current message implies the index is wrong type/range when the actual issue is the page is empty
- **Severity**: LOW
- **Related code**: `src/Table.php:469-482`
- **Conditions for success**:
  - `withExpandedRows([0])` on empty page throws with message mentioning "empty page" or "no rows"
- **Investigation notes**:
  - Line 477: `throw new \OutOfBoundsException("Invalid row index {$idx} for current page");`
  - When `$paged[$idx] ?? null` returns null because page is empty (not because index is out of range), message is misleading

---

## Phase 2: Performance Optimizations [PENDING]

### 2.1 [LOW] Pre-compute Visible Column Indices Before Row Loop
- **Task**: Compute visible column indices once before iterating rows in `fillDataRow()` and `fillDataRowLines()`
- **What**: Create a `$visibleColumnIndices` array at the start of the row rendering section, then use it instead of calling `isColumnVisible()` per-row per-column
- **Why**: `isColumnVisible()` is called N×M times (rows × columns) but produces the same result for all rows. Pre-computation reduces complexity from O(rows×cols) to O(cols)
- **Severity**: LOW
- **Related code**: `src/Table.php:1460-1467` (fillDataRow), `src/Table.php:1589-1711` (fillDataRowLines)
- **Conditions for success**:
  - Behavior unchanged (visible columns remain the same)
  - Performance improvement measurable on large tables
- **Investigation notes**:
  - `fillDataRow()` line 1464 calls `isColumnVisible($ci)` inside a row loop
  - `fillDataRowLines()` line 1609 also calls `isColumnVisible()` per-row
  - `isColumnVisible()` checks hidden array, frozen array, and computes scrollable start index — all constant for a given render pass

### 2.2 [LOW] SelectNext/SelectPrevious Short-circuit at Boundaries
- **Task**: Check if navigation would be a no-op before calling `filteredSortedRows()`
- **What**: In `SelectNext()`, if `selectedIndex` is already at the last row, return `$this` without computing. In `SelectPrevious()`, if already at 0, return `$this`
- **Why**: Avoid expensive `filteredSortedRows()` call when the operation would have no effect
- **Severity**: LOW
- **Related code**: `src/Table.php:561-576`
- **Conditions for success**:
  - `SelectNext()` at last row returns same instance or identical state
  - `SelectPrevious()` at first row returns same instance or identical state
  - No regression in navigation behavior
- **Investigation notes**:
  - `SelectNext()` (line 561-569) calls `filteredSortedRows()` to get view length before clamping
  - `SelectPrevious()` (line 571-576) doesn't call filteredSortedRows but could still short-circuit if selectedIndex is already 0

### 2.3 [LOW] TotalRows() + pagedRows() Double-compute Fix
- **Task**: When both methods are called, avoid computing `filteredSortedRows()` twice
- **What**: The cached `filteredSortedCache` already prevents double computation when both methods are called on the same Table instance. However, when a new instance is created (e.g., via `withPage()`), the cache is cleared. Consider computing once in methods that both need the result, or document that callers should cache the result themselves.
- **Why**: Redundant computation on first call of each method
- **Severity**: LOW
- **Related code**: `src/Table.php:951-974`
- **Conditions for success**:
  - `TotalRows()` and `pagedRows()` on same instance computes once
  - Document behavior or provide a shared method for callers
- **Investigation notes**:
  - `pagedRows()` (line 951-958) calls `filteredSortedRows()` which populates cache
  - `TotalRows()` (line 971-974) also calls `filteredSortedRows()` which returns cached result
  - The caching works correctly within a single fluent chain. Issue is when callers use both methods separately

### 2.4 [LOW] widthSolveCache Bounded Growth
- **Task**: Add eviction policy to `$widthSolveCache`
- **What**: Use a LRU (Least Recently Used) cache with a maximum size (e.g., 10 entries) for `$widthSolveCache`. When adding a new entry beyond the limit, evict the oldest.
- **Why**: Unbounded cache growth could cause memory issues with long-running applications or many table width computations
- **Severity**: LOW
- **Related code**: `src/Table.php:134` (property), `src/Table.php:1028-1031` (computeColumnWidths)
- **Conditions for success**:
  - Cache size stays bounded under repeated computeColumnWidths calls with varying tableWidth
  - Existing behavior (same inputs → same outputs) preserved
- **Investigation notes**:
  - `$widthSolveCache` is keyed by `tableWidth` (int)
  - No eviction policy exists currently
  - `computeColumnWidths()` line 1030 stores: `$this->widthSolveCache[$tableWidth] ??= ...`

---

## Phase 3: Code Quality & Documentation [PENDING]

### 3.1 [LOW] Clarify styleFunc Signature in Docblock
- **Task**: Update docblock for `$styleFunc` and `withStyleFunc()` to clarify "int col" means column index (not key)
- **What**: Change `@param callable|null $fn (int $row, int $col, string $value): Style|string` to clarify col is the 0-based index, not the column key string
- **Why**: Ambiguity could lead to misuse
- **Severity**: LOW
- **Related code**: `src/Table.php:95-97`, `src/Table.php:387-392`
- **Conditions for success**:
  - Docblock clearly states col is the index
  - Examples or reference to existing usage patterns
- **Investigation notes**:
  - Line 95: `@param callable|null $styleFunc (int $row, int $col, string $value): Style|string`
  - Line 96-97: doc says "int col" without clarifying index vs key
  - `styleFunc` callback at line 1497 is called with `($rowIndex, $ci, $cellStr)` where `$ci` is the column index

### 3.2 [LOW] Extract computeVisibleContentWidth Repeated Logic
- **Task**: DRY up the column visibility + width iteration logic
- **What**: The visible column iteration in `computeVisibleContentWidth()` is repeated 4+ times (header, separators, data rows). Extract a helper method `getVisibleColumnIndices()` that returns the list of visible column indices
- **Why**: Code duplication makes maintenance harder and increases chance of inconsistencies
- **Severity**: LOW
- **Related code**: `src/Table.php:1303-1319` (computeVisibleContentWidth), and similar patterns in `fillHeaderRow()`, `fillDataRow()`, `fillDataRowLines()`, `calculateRowHeight()`
- **Conditions for success**:
  - Single source of truth for visible column calculation
  - Behavior unchanged
- **Investigation notes**:
  - `computeVisibleContentWidth()` at line 1303-1319 iterates columns checking `isColumnVisible()`
  - `fillHeaderRow()` at line 1398-1425 has similar iteration
  - `fillDataRow()` at line 1460-1467 has similar iteration
  - `fillDataRowLines()` at line 1608-1611 has similar iteration
  - `calculateRowHeight()` at line 1550-1553 has similar iteration

### 3.3 [LOW] parseAnsiToStyle / styleToAnsi Color Round-trip Consistency
- **Task**: Audit color value consistency between parseAnsiToStyle and styleToAnsi
- **What**: Standard colors (30-37, 40-47, 90-97, 100-107) in `parseAnsiToStyle()` use specific RGB values (e.g., case 31: `0xcc0000` for red). Verify that `styleToAnsi()` produces the inverse values correctly for these standard colors
- **Why**: Round-trip (parse → style → parse) should preserve colors. Different values could cause subtle visual differences
- **Severity**: LOW
- **Related code**: `src/Table.php:1848-1877` (styleToAnsi), `src/Table.php:1882-2006` (parseAnsiToStyle)
- **Conditions for success**:
  - Standard color codes round-trip correctly
  - Test verifies round-trip consistency for all standard colors
- **Investigation notes**:
  - `styleToAnsi()` (line 1848-1877) converts Style RGB back to 38;2;r;g;b format for fg/bg
  - `parseAnsiToStyle()` (line 1882-2006) parses standard colors 30-37/40-47/90-97/100-107 with specific RGB values like 0xcc0000
  - For standard colors, styleToAnsi uses 38;2;r;g;b format while parseAnsiToStyle accepts 30-37 etc — round-trip should work but edge cases may exist

---

## Phase 4: API Ergonomics [PENDING]

### 4.1 [LOW] Add column(string $key) Accessor
- **Task**: Add `column(string $key): ?Column` method to Table
- **What**: Return the Column with the given key, or null if not found
- **Why**: Users currently must iterate `Columns()` manually to find a column by key — a common operation that deserves a convenience method
- **Severity**: LOW
- **Related code**: `src/Table.php:866` (Columns accessor)
- **Conditions for success**:
  - `$table->column('id')` returns the Column with key 'id' or null
  - Method is immutable (no state change)
- **Investigation notes**:
  - `Columns()` at line 866 returns `list<Column>`
  - No existing `column($key)` method
  - Pattern common in collection APIs (e.g., ArrayObject::getIterator())

### 4.2 [LOW] Add Column::withFlexWidth() Alias
- **Task**: Add `withFlexWidth(int $share): self` as an alias for `withFlexibleWidth()`
- **What**: Alias method that calls through to `withFlexibleWidth()`
- **Why**: Upstream bubble-table uses `WithFlexWidth()` — naming difference makes porting harder
- **Severity**: LOW
- **Related code**: `src/Column.php:77-80`
- **Conditions for success**:
  - `Column::new('id', 'ID', 5)->withFlexWidth(1)` works identically to `->withFlexibleWidth(1)`
- **Investigation notes**:
  - `withFlexibleWidth()` at line 77-80 already exists
  - No `withFlexWidth()` alias exists

### 4.3 [LOW] Add SelectedRow() Convenience Method
- **Task**: Add `SelectedRow(): ?Row` method to Table
- **What**: Return `$this->pagedRows()[$this->selectedIndex] ?? null`
- **Why**: Reduces boilerplate from `$table->pagedRows()[$table->SelectedIndex()]` to `$table->SelectedRow()`
- **Severity**: LOW
- **Related code**: `src/Table.php:960-964` (CurrentRow)
- **Conditions for success**:
  - `$table->SelectedRow()` returns the currently selected Row or null
  - Mirrors existing `CurrentRow()` pattern
- **Investigation notes**:
  - `CurrentRow()` at line 960-964 does exactly this but is not named for selection context
  - `SelectedRow()` would be a naming alias for consistency with `SelectedIndex()`

---

## Phase 5: Testing [PENDING]

### 5.1 Add Tests for All Fixes
- **Task**: Add PHPUnit tests for each fix
- **What**: Test cases covering:
  - Hidden+frozen conflict detection (throws on overlap)
  - Invalid key validation in Filter/SortBy (throws on invalid key)
  - Empty page error message improvement
  - Visible column pre-computation (behavioral test)
  - Navigation short-circuit at boundaries
  - widthSolveCache bounded growth
  - styleFunc signature clarity (documentation only)
  - Round-trip color consistency
  - column() accessor
  - withFlexWidth() alias
  - SelectedRow() convenience
- **Conditions for success**: All new tests pass, all existing tests still pass

---

## Notes
- 2026-06-30: Plan created from audit findings in `findings/sugar-table.md`
- Priority order per audit recommendations: validation fixes first (items 1.1, 1.2), then optimizations, then API ergonomics
- Some items (styleFunc signature, color round-trip) may be documentation-only or low-risk changes
- widthSolveCache LRU could use a simple array-based LRU or defer to a future caching utility class
