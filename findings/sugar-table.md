# SugarCraft sugar-table Audit Findings

**Library:** sugarcraft/sugar-table (port of Evertras/bubble-table)  
**PHP Version:** 8.3+  
**Date:** 2026-06-30

---

## Severity Summary

| Severity | Count |
|----------|-------|
| HIGH | 0 |
| MEDIUM | 2 |
| LOW | 13 |
| INFO | 4 |

---

## 1. MEDIUM: Hidden + Frozen Column Conflict
**Location:** src/Table.php:1283-1294

withHiddenCols() and withFrozenCols() do not validate against each other. A column can be in both sets. Hidden takes precedence, silently ignoring the freeze request.

Recommendation: Add validation to reject indices in both sets, or document that hidden overrides frozen.

---

## 2. MEDIUM: Invalid Column Keys Silently Ignored
**Location:** src/Table.php:745-765 (Filter), 701-731 (SortBy)

Filter() and SortBy() accept any string as $colKey without validating the column exists. Invalid keys result in silent no-op.

Recommendation: Add columnExists() validation and throw on invalid key.

---

## 3. LOW: TotalRows() + pagedRows() Both Compute on First Call
**Location:** src/Table.php:971-974, 951-958

On fresh Table, calling both methods computes filteredSortedRows() twice.

Recommendation: Single-pass approach or precompute method.

---

## 4. LOW: fillDataRow Recomputes isColumnVisible Per Row
**Location:** src/Table.php:1460-1467

isColumnVisible() called for every column in every row. Column visibility doesn't change between rows.

Recommendation: Compute visible column indices once before row loop.

---

## 5. LOW: SelectNext/Previous Compute Filter/Sort on No-Op
**Location:** src/Table.php:561-576

Both methods call filteredSortedRows() even at boundary when no-op.

Recommendation: Check boundary before computing.

---

## 6. LOW: withExpandedRows Throws on Empty Page
**Location:** src/Table.php:469-482

Error message misleading — says "invalid row index" when page is empty.

---

## 7. LOW: widthSolveCache Grows Unbounded
**Location:** src/Table.php:134

No eviction policy for width cache.

---

## 8. LOW: styleFunc Signature Ambiguity
**Location:** src/Table.php:96-97

Doc says "int col" but doesn't clarify index vs key.

---

## 9. LOW: computeVisibleContentWidth Duplicates Column Iteration
**Location:** src/Table.php:1303-1319

Same logic repeated 4+ times.

---

## 10. LOW: parseAnsiToStyle / styleToAnsi Color Asymmetry
**Location:** src/Table.php:1882-2006, 1848-1877

Different color values for standard colors could cause round-trip issues.

---

## 11. LOW: No column(string $key) Accessor
**Location:** N/A

Users must iterate manually to find column by key.

---

## 12. LOW: No Column::withFlexWidth() Alias
**Location:** src/Column.php

Naming differs from upstream WithFlexWidth().

---

## 13. LOW: No SelectedRow() Convenience Method
**Location:** src/Table.php

Requires $table->pagedRows()[$table->SelectedIndex()] instead of $table->SelectedRow().

---

## Memory: No issues found.

## Security: ANSI injection prevention solid. Sanitize::value() properly neutralizes C0/C1/ESC.

## PHP 8.3+: Fully compatible. grapheme_str_split fallback handled correctly.

## Async: Not applicable — TUI rendering is inherently synchronous.

---

## Recommendations Priority

1. Add column key validation to Filter() and SortBy()
2. Add hidden+frozen conflict detection
3. Pre-compute visible column indices before row loop
4. Optimize TotalRows() + pagedRows() double-compute
