# Code Review: sugar-bits

## Overview

Sugar-bits is a PHP port of charmbracelet/bubbles — a TUI component library. It provides 15+ pre-built TUI components (text input, textarea, list, table, tree, viewport, file picker, progress, spinner, etc.) for the SugarCraft ecosystem.

**Architecture observation:** A significant portion of sugar-bits components (TextInput, TextArea, Viewport, ItemList, FilePicker, Spinner/Style, Scrollbar/ScrollbarState, Cursor/Mode) are simple `class_alias` deprecation wrappers that point to `SugarCraft\Forms\*`. The actual implementations live in candy-forms. This is a transitional architecture that may confuse consumers.

---

## CRITICAL ISSUES

### 1. Static State in Timer and Stopwatch — Test Isolation Violation

**Files:** `src/Timer/Timer.php:23` and `src/Stopwatch/Stopwatch.php:20`

Both classes use static `$nextId` counters:

```php
private static int $nextId = 0;

private function __construct(
    ...
    ?int $id = null,
) {
    $this->id = $id ?? ++self::$nextId;  // PRE-increment: first ID = 1
}
```

**Problems:**
- **Test pollution:** The counter is never reset between tests. In a long-running test suite, timer IDs grow monotonically. While the tests at `tests/Timer/TimerTest.php:117` (`testIdAccessor`) and `tests/Stopwatch/StopwatchTest.php:78` (`testIdAccessor`) pass in isolation, they may fail or produce confusing results if run after other timer-heavy tests.
- **Process lifetime:** In a long-running PHP-FPM or ReactPHP event-loop process, `$nextId` increments indefinitely. On a 64-bit system this won't overflow in any practical timeframe, but the pattern is risky.
- **First ID = 1, not 0:** Pre-increment means the first created timer/stopwatch gets ID 1. This is not inherently wrong but is worth documenting.

**Recommendation:** Consider an instance-based ID generator pattern (e.g., a private static `SplId` or `AtomicInt` service), or at minimum document this as an intentional design decision. For testing, add a `resetIdCounter()` test helper and call it in `tearDown()`.

---

## HIGH PRIORITY ISSUES

### 2. Duplicate `sanitizeCell()` Logic Across Multiple Components

**Files:**
- `src/Tabs/Tabs.php:462-466`
- `src/Tree/Tree.php:366-372`
- `src/Table/Table.php:580-584`

All three use identical C0-stripping logic:

```php
private static function sanitizeCell(string $s): string
{
    $s = str_replace(["\n", "\r", "\t"], ' ', $s);
    return preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', $s) ?? $s;
}
```

This violates DRY. A shared utility in `candy-core` or a base `Model` trait would eliminate duplication.

### 3. `Table::sortedRows()` — Multiple Full Array Sorts for Multi-Criteria Sort

**File:** `src/Table/Table.php:506-526`

```php
$criteria = array_reverse($state->criteria);
foreach ($criteria as [$col, $dir]) {
    usort($rows, static function (array $a, array $b) use ($col, $dir): int {
        $va = $a[$col] ?? '';
        $vb = $b[$col] ?? '';
        $cmp = strnatcasecmp((string) $va, (string) $vb);
        return $dir === SortDirection::Desc ? -$cmp : $cmp;
    });
}
```

When N sort criteria exist, this performs N complete array sorts. For large tables with many rows and several sort columns, this is O(N × M × log M) where N = criteria count, M = row count. For 3 criteria and 10,000 rows, this could be slow.

**Recommendation:** Use `array_multisort()` with a single pass to build sort keys, or a single `usort` with a comparator that considers all criteria in priority order.

### 4. `Table::columnWidths()` — Inefficient Round-Robin Shrinking Algorithm

**File:** `src/Table/Table.php:612-632`

```php
while ($total > $budget) {
    $shrunk = false;
    for ($i = $cols - 1; $i >= 0; $i--) {
        if ($widths[$i] > 0) {
            $widths[$i]--;
            $total--;
            $shrunk = true;
            if ($total <= $budget) {
                break;
            }
        }
    }
    if (!$shrunk) {
        break;
    }
}
```

This is O(budget × cols) in the worst case — if the total width exceeds budget by 100 cells and there are 10 columns, this could iterate up to 1,000 times. For constrained-width tables with many columns, this adds measurable overhead on every `view()` call.

**Recommendation:** Compute shrink amounts mathematically: `total - budget` distributed proportionally across columns with remaining width.

### 5. `Tabs::view()` and `Tabs::computeScrollEnd()` — Duplicated Scroll Logic

**File:** `src/Tabs/Tabs.php:212-246` (view) vs `src/Tabs/Tabs.php:432-455` (computeScrollEnd)

The `view()` method at lines 212-246 contains a full scroll-window computation that replicates the logic in `computeScrollEnd()`. The `scrollEnd` property is computed at construction time (line 82) but then `view()` recomputes its own visible range using nearly identical logic.

This means:
1. `computeScrollEnd()` exists but is bypassed in `view()`
2. Two different code paths compute the same thing
3. The stored `scrollEnd` value may be inconsistent with what `view()` actually renders

---

## MEDIUM PRIORITY ISSUES

### 6. `Progress::view()` — Duplicated Width/Percent Calculations Across 3 Render Modes

**File:** `src/Progress/Progress.php:210-319`

Three render mode branches (`Line`, `Slim`, `Block`) each independently compute:
- `suffixCells`
- `showSuffix`
- `barWidth`
- `filledCells` / `emptyCells`

This is approximately 80 lines of very similar logic duplicated with minor variations. The common calculation should be extracted to a shared helper method.

### 7. `Tree::visibleRows()` / `Tree::collectVisible()` — Not Cached, Called Multiple Times Per Update

**File:** `src/Tree/Tree.php:232-261`

`visibleRows()` is called in:
- `update()` (line 284 via `setExpandedAtCursor`)
- `clamp()` (line 319)
- `selectedNode()` (line 213)
- `visibleCount()` (line 224)
- `view()` (line 151)

For deep trees with many nodes, this means O(n) tree traversal happens 5+ times per render/update cycle. A single cached computation would be far more efficient.

### 8. `Table::visibleRows()` — Same Problem as Tree

**File:** `src/Table/Table.php:570-573`

Called by:
- `moveCursor()` (line 664)
- `selectedRow()` (line 245)
- `view()` (line 165)
- `getPaginator()` (line 411)

The `sortedRows()` + `filteredRows()` chain is re-executed on every call.

### 9. Deprecated Components Are Non-Functional Aliases

**Files:** `src/Viewport/Viewport.php`, `src/TextInput/TextInput.php`, `src/TextArea/TextArea.php`, `src/ItemList/ItemList.php`, `src/FilePicker/FilePicker.php`, `src/Spinner/Spinner.php`, `src/Scrollbar/Scrollbar.php`, `src/Cursor/Cursor.php`

All 8 files are identical 8-line deprecation shims:

```php
// @deprecated Use SugarCraft\Forms\Viewport\Viewport
class_alias('SugarCraft\Forms\Viewport\Viewport', 'SugarCraft\Bits\Viewport\Viewport');
```

This means:
- sugar-bits doesn't actually implement these 8 components — candy-forms does
- External consumers who `use SugarCraft\Bits\TextInput\TextInput` are actually getting candy-forms' implementation
- The sugar-bits namespace is essentially a deprecated re-export surface
- No tests exist in sugar-bits for these components (they're tested in candy-forms)

### 10. `SortState` — Readonly Array Property Still Mutable Internally

**File:** `src/Table/SortState.php:11-18`

```php
final readonly class SortState
{
    public function __construct(
        public array $criteria = [],  // readonly property, mutable array
    ) {}
```

The `readonly` on the property prevents `$sortState = new SortState(...)` from being reassigned after construction, and `withCriterion()` correctly returns a new instance. However, the array **contents** are not deep-cloned. If a caller does:

```php
$state = SortState::empty();
$state->criteria[] = [999, SortDirection::Asc];  // mutate internal array
```

This mutates the original state (because `readonly` in PHP 8.1+ doesn't deep-freeze arrays). The `withCriterion()` method works around this by copying the array before appending (line 25-27), so the class is **safe in practice**, but the protection is implicit rather than explicit.

### 11. `AnimatedProgress` — Creates New Spring Instance Every Tick

**File:** `src/Progress/AnimatedProgress.php:79`

```php
$spring = new Spring(Spring::fps((int) $this->fps), $this->angularFrequency, $this->dampingRatio);
```

Every `SpringTickMsg` creates a brand new `Spring` object. While the Spring is lightweight, this is unnecessary allocation pressure in high-FPS animations (60fps = 60 Spring objects/second).

**Recommendation:** Store the `Spring` instance as a private readonly property, recreate only when angularFrequency/dampingRatio/fps change via a `withSpringOptions()` or `withFps()` call.

---

## LOW PRIORITY / STYLE ISSUES

### 12. `Tree::updateAt()` — Reference Parameter Mutation

**File:** `src/Tree/Tree.php:302-315`

```php
private function updateAt(array &$tree, array $path, \Closure $mut): void
```

Modifying `$tree` by reference is a valid technique but can be confusing in an otherwise fully-immutable codebase. A more idiomatic approach would be to return the modified tree and let the caller reassign.

### 13. `Tree::collectVisible()` — Array Spread on Every Recursion

**File:** `src/Tree/Tree.php:259`

```php
$this->collectVisible($child, $depth + 1, [...$path, $i], $rows);
```

The `...$path` creates a new array on every recursive call. For deep trees, this is O(depth) allocation per node. Could be replaced with `array_push($path, $i)` + `array_pop()` in a non-recursive implementation, or by using a path-builder approach.

### 14. `Tabs::tabIndexFromZoneId()` — Fragile String Parsing

**File:** `src/Tabs/Tabs.php:393-402`

```php
$pos = strpos($id, 'tab-');
if ($pos !== false) {
    $idx = (int) substr($id, $pos + 4);
    return $idx >= 0 && $idx < count($this->labels) ? $idx : null;
}
```

If a zone ID is `"my-tab-3"`, this will find "tab-" and parse `3`. But if there's a label that legitimately contains "tab-" in its name, this could produce unexpected results. A more robust approach would be a regex like `/^tab-(\d+)$/` or explicit prefix stripping.

### 15. `Table::columnWidths()` — Empty Rows Edge Case

**File:** `src/Table/Table.php:591`

```php
...array_map('count', $this->rows ?: [[]])
```

When rows is empty, this defaults to `[[]]` — a single empty row — which is then counted. This produces a column count of 1 even when both headers and rows are empty. The early return at line 593-595 catches the `count($this->headers) === 0` case, but the `?: [[]]` fallback is never actually used in a meaningful way since `count($this->headers)` would also be 0.

### 16. `Progress` and `Help` Do Not Sanitize Input

The `Tabs`, `Tree`, and `Table` components all call `sanitizeCell()` on user-supplied text before rendering. However, `Progress` (which accepts custom chars like `fullChar`/`emptyChar`) and `Help` (which renders key binding descriptions) do not perform equivalent sanitization. While less risky (these aren't user data), it creates inconsistent guarantees across the component set.

---

## SECURITY ISSUES

### 17. C0 Control Character Sanitization — Inconsistent Coverage

Tabs, Tree, and Table sanitize C0 characters (except ESC for SGR sequences). This is good for TUI safety (preventing terminal control sequence injection). However:

- **Progress** does not sanitize `fullChar`/`emptyChar` strings — if these come from user configuration, a malicious terminal could be exploited
- **Help** does not sanitize binding keys or descriptions
- The sanitization approach (replace \n \r \t with space, strip \x00-\x08\x0b\x0c\x0e-\x1f) is correct but is duplicated in 3 places rather than centralized

### 18. No Input Validation on Public Constructor Parameters (Most Classes)

Most components validate inputs in the constructor, but this validation is inconsistent:
- `Progress` validates `$width < 0` (line 56)
- `Table` validates `$width < 0 || $height < 0` (line 65)
- `Tree` validates `$width < 0 || $height < 0` (line 89)
- But some value objects like `Node`, `Column`, `Binding`, `SortState` have minimal or no validation on their constructor parameters

---

## COMPATIBILITY ISSUES

### 19. Class Alias Deprecation Creates Confusing Consumer API

Consumers importing from `SugarCraft\Bits\*` get candy-forms implementations. This means:
- sugar-bits has no independent test coverage for 8 of its 15 components
- The "sugar-bits" version of TextInput etc. is always exactly the candy-forms version
- If candy-forms changes, sugar-bits changes — there is no independent versioning

**Recommendation:** Document clearly that sugar-bits is a transitional re-export layer, and that candy-forms is the canonical home for these components.

### 20. ReactPHP/Async Integration — `subscriptions()` Always Returns Null

All Model components return `null` from `subscriptions()`. In a Bubble-Tea TEA (The Elm Architecture) loop running on ReactPHP, this is correct for stateless components. However, `AnimatedProgress` and `Timer`/`Stopwatch` do schedule ticks via `Cmd::tick()`. The tick scheduling pattern is correct, but there is no example or test demonstrating these in a full async ReactPHP program context.

---

## WAYS TO IMPROVE

### 21. Extract Shared `sanitizeCell()` to candy-core

The C0-stripping logic appears in 3 places. A `SugarCraft\Core\Util\Sanitize::controlChars(string $s): string` utility in candy-core would:
- Eliminate duplication
- Provide a single point for security auditing
- Allow consistent behavior across all components

### 22. Add Caching to `visibleRows()` in Tree and Table

Both Tree and Table call `visibleRows()` multiple times per update/render cycle. A simple memoization pattern would cut redundant tree/array traversal:

```php
private ?array $visibleRowsCache = null;

public function visibleRows(): array {
    return $this->visibleRowsCache ??= $this->computeVisibleRows();
}
```

Invalidate the cache in `copy()` or when mutating the model.

### 23. Consider `array_multisort()` for Multi-Column Sort

Instead of N `usort()` passes, use `array_multisort()` which is implemented in C and significantly faster:

```php
$sortColumns = array_column($rows, $col);
$sortDirections = array_fill(0, count($rows), $dir === SortDirection::Desc ? SORT_DESC : SORT_ASC);
array_multisort($sortColumns, $sortDirections, ..., $rows);
```

### 24. Document Static `$nextId` Behavior

If the monotonic ID counter is intentional (useful for debugging, logging), document it clearly. If it's a testability concern, consider injecting an ID generator. Either way, it should be explicitly designed rather than accidental.

### 25. Missing Translation Keys

Checking `lang/en.php`, several error conditions lack translation keys:
- Tree validation messages (none appear in lang/en.php for tree)
- Some input validation errors in Progress (width negative is present, but others may be missing)
- The `SortState` / sorting doesn't use the Lang system for error messages

---

## POSITIVE OBSERVATIONS

- **Immutable + fluent pattern** is well implemented throughout: every `with*()` returns a new instance, no mutations of existing state
- **Sentinel boolean pattern** (`$XSet = false`) for nullable fields is correctly used in Table, Progress, and most components following the CALIBER_LEARNINGS.md pattern
- **`Model` contract** is consistently implemented: `init()`, `update()`, `view()`, `subscriptions()` across all interactive components
- **Input sanitization** (C0 stripping) is present and correct in the components that need it most (Tabs, Tree, Table)
- **Test coverage** is comprehensive with behavior tests for all core components
- **Bubble Tea parity** is high — the PHP port faithfully mirrors the Go upstream API shapes
- **`animate()` tick rescheduling pattern** in AnimatedProgress is correctly implemented with the self-referential Cmd chain
- **Help component** is well-designed with both short (inline) and full (multi-column) rendering modes
