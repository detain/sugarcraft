---
status: not-started
phase: 1
updated: 2026-06-30
---

# Implementation Plan: candy-buffer Bug Fixes & Improvements

## Goal

Fix all critical bugs and implement high-priority improvements in `sugarcraft/candy-buffer` covering: wide-char cursor tracking, Style value equality, input validation, performance optimizations, serialization, and missing features.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Fix bugs before adding features | Stability before functionality | `ref:candy-buffer-code-review` |
| Style value equality via `equals()` method | Style is an immutable value object — object identity (`===`) prevents equal instances from being treated as equal | `ref:candy-buffer-md` Bug #3 |
| Array buffer pattern for O(n) string building | PHP strings are immutable; ` .= ` in loops is O(n²) for large buffers | `ref:candy-buffer-md` Issue #7 |
| State reset in `DiffEncoder::encode()` | Encoder maintains mutable cursor/style state across calls — reusing instance without reset produces incorrect output | `ref:candy-buffer-md` Issue #11 |
| Add width validation to Cell constructor | Invalid widths (3, -1, etc.) could propagate causing buffer corruption or index errors | `ref:candy-buffer-md` Issue #5 |

## Phase 1: Critical Bugs [PENDING]

### 1.1 Fix `RepeatRunOp` wide-char cursor advance in `Buffer::applyDiff` [PENDING]

**File:** `src/Buffer.php:393-413`

**What:** The loop in `RepeatRunOp` handling writes a wide cell at `$c` and a continuation at `$c+1`, but advances `$cursorCol` by `$op->count` (not by width). On iteration `i=1`, `c = cursorCol + 1` overwrites the continuation cell.

**Why:** `applyDiff` is test-only (documented at line 331), but any future reuse would produce corrupted wide characters. Fix ensures correctness if used.

**Severity:** Critical (data corruption with wide characters)

**Fix:**
```php
// Buffer.php:412 — Change from:
// $cursorCol += $op->count;
// To:
$cursorCol += $op->count * $width;  // advance by total cell width consumed
```

**Verification:**
- Write test: applyRepeatRunOp wide char (width=2) with count>1, verify continuation cells are not overwritten
- Run `vendor/bin/phpunit` — all existing tests pass
- `testRoundTripRandomPairsTwentyIterations` continues to pass

**Investigation Notes:**
- Source: `src/Buffer.php:393-413` (RepeatRunOp handling in `applyDiff`)
- The bug is on line 412: `$cursorCol += $op->count;` advances by count, not by width
- `SetCellOp` handling at line 381 correctly uses `$cursorCol += $width;`
- `DiffEncoder::encodeRepeatRun` at line 171 correctly advances by `$op->count * $op->width`

---

### 1.2 Fix `EraseRunOp` cursor advance mismatch in `Buffer::applyDiff` [PENDING]

**File:** `src/Buffer.php:383-392` vs `src/Diff/DiffEncoder.php:150-152`

**What:** `Buffer::applyDiff` advances cursor after `EraseRunOp` (line 392: `$cursorCol += $op->count`), but `DiffEncoder::encodeEraseRun` explicitly documents that ECH erases in-place and cursor does NOT advance (lines 150-151).

**Why:** Per xterm control sequences, ECH (Erase Character) does not move the cursor. `DiffEncoder` is correct; `applyDiff` is wrong. Any subsequent `DiffOp` after an `EraseRunOp` in the same diff would be applied at the wrong cursor position.

**Severity:** Critical (incorrect cursor tracking in applyDiff)

**Fix:**
```php
// Buffer.php:392 — Remove the cursor advance line:
// $cursorCol += $op->count;  // DELETE THIS LINE
```

**Verification:**
- Write test: buffer with EraseRunOp followed by another op at a later position — verify cursor position is correct
- Run `vendor/bin/phpunit` — all existing tests pass

**Investigation Notes:**
- Source: `src/Buffer.php:383-392` (EraseRunOp handling in `applyDiff`)
- `DiffEncoder.php:150-151` comment: "ECH erases characters in-place; the logical cursor does NOT advance"
- This affects round-trip test accuracy when EraseRunOp is followed by more ops

---

### 1.3 Add `Style::equals(Style $other): bool` method [PENDING]

**File:** `src/Style.php` (new method) and `src/Cell.php:83-89` (use it)

**What:** `Cell::equals()` uses object identity (`===`) for `Style` comparison. Two `Style` instances with identical `fg`, `bg`, and `attrs` values but different object identities would not be considered equal.

**Why:** Style is an immutable value object. Value equality is required for correct `Buffer::diff()` operation.

**Severity:** Critical (affects Buffer::diff correctness)

**Fix - Style.php:**
```php
/**
 * Value equality: two styles are equal iff their fg, bg, and attrs match.
 */
public function equals(self $other): bool
{
    return $this->fg === $other->fg
        && $this->bg === $other->bg
        && $this->attrs === $other->attrs;
}
```

**Fix - Cell.php:83-89:**
```php
public function equals(Cell $other): bool
{
    return $this->rune() === $other->rune()
        && $this->style()?->equals($other->style() ?? new Style())  // value equality
        && $this->link()?->url() === $other->link()?->url()
        && $this->width() === $other->width();
}
```
Note: Need to handle null styles — two null styles are equal, null !== Style object.

**Verification:**
- Write test: two distinct Style instances with identical values — verify they are considered equal
- Write test: Cell::equals with two styles that are equal by value but different instances
- Run `vendor/bin/phpunit`

**Investigation Notes:**
- Source: `src/Cell.php:83-89`
- `Buffer::diff()` uses `Cell::equals()` at line 202 for the diff hot-path
- `Style` has no existing `equals()` method

---

### 1.4 Fix `Buffer::diff()` Style comparison in repeat detection [PENDING]

**File:** `src/Buffer.php:274-276`

**What:** The repeat detection loop uses object identity (`!==`) for Style comparison:
```php
if ($run[$i]->rune() !== $repeatRune
    || $run[$i]->style() !== $runStyle  // object identity — BUG
    || $run[$i]->link()?->url() !== $runLinkUrl
)
```

**Why:** Two `Style` objects with identical values but different instances would prevent `RepeatRunOp` from being emitted, resulting in suboptimal (larger) diff output.

**Severity:** Critical (suboptimal diff output)

**Fix:**
```php
// Change line 275 from:
|| $run[$i]->style() !== $runStyle
// To:
|| !$run[$i]->style()->equals($runStyle)
```

**Verification:**
- Write test: two cells with distinct Style instances but equal values — verify RepeatRunOp is emitted
- Run `vendor/bin/phpunit`

**Investigation Notes:**
- Source: `src/Buffer.php:274-276`
- Depends on 1.3 (Style::equals must be added first)
- Also at line 248: `$nextCell->style() === $runStyle` uses identity — should also use `equals()`

---

## Phase 2: Input Validation & Correctness [PENDING]

### 2.1 Add width validation to `Cell` constructor [PENDING]

**File:** `src/Cell.php:33-38`

**What:** The constructor accepts any `int` for `$width` despite the docblock documenting only `0, 1, or 2`. Invalid widths (e.g., 3, -1) could propagate causing buffer corruption or index errors.

**Why:** Defense-in-depth — invalid state should be caught at construction time.

**Severity:** High (prevents corrupted state propagation)

**Fix:**
```php
public function __construct(
    public readonly string $rune,
    public readonly ?Style $style,
    public readonly ?Hyperlink $link,
    public readonly int $width,
) {
    if ($width !== 0 && $width !== 1 && $width !== 2) {
        throw new \InvalidArgumentException('Cell width must be 0, 1, or 2');
    }
}
```

**Verification:**
- Write test: `new Cell('X', null, null, 3)` throws `\InvalidArgumentException`
- Write test: `new Cell('X', null, null, -1)` throws `\InvalidArgumentException`
- Run `vendor/bin/phpunit`

**Investigation Notes:**
- Source: `src/Cell.php:33-38`
- `Cell::new()` at line 43-49 passes through to constructor
- `Cell::continuation()` at line 56-58 creates width=0 cells (valid)

---

### 2.2 Fix Hyperlink identity comparison in `Buffer::toAnsi()` [PENDING]

**File:** `src/Buffer.php:450`

**What:** 
```php
if ($prevLink !== null && $cell->link() !== $prevLink) {
    $out .= "\x1b]8;;\x1b\\";  // close old link
}
```
If `$cell->link()` is a different `Hyperlink` object with the same URL as `$prevLink`, this triggers a redundant close+open of the same URL.

**Why:** Minor performance/bandwidth issue — emits unnecessary OSC 8 close+open sequence when transitioning between two Hyperlink objects with identical URLs but different instances.

**Severity:** Medium (performance, not correctness)

**Fix - Hyperlink.php** — Add `equals()` method:
```php
public function equals(self $other): bool
{
    return $this->url === $other->url && $this->id === $other->id;
}
```

**Fix - Buffer.php:450:**
```php
if ($prevLink !== null && ($cell->link() === null || !$cell->link()->equals($prevLink))) {
    $out .= "\x1b]8;;\x1b\\";
}
```

**Verification:**
- Write test: two distinct Hyperlink instances with same URL — verify only one close+open pair
- Run `vendor/bin/phpunit`

**Investigation Notes:**
- Source: `src/Buffer.php:450`
- `Hyperlink.php` currently has no `equals()` method

---

## Phase 3: Performance Optimizations [PENDING]

### 3.1 Replace string concatenation loops with array buffers [PENDING]

**Files:** 
- `src/Buffer.php:433` (`toAnsi()`)
- `src/Diff/DiffEncoder.php:52-56` (`encode()`)

**What:** PHP strings are immutable. Each ` .= ` creates a new string, copying all previous content. For large buffers (80×24 = 1920 cells), this is O(n²).

**Why:** Performance improvement for large buffer rendering.

**Severity:** Medium (performance impact on large buffers)

**Fix - Buffer.php:431-484 (`toAnsi`):**
```php
public function toAnsi(): string
{
    $parts = [];
    $prevStyle = null;
    $prevLink = null;

    for ($row = 0; $row < $this->height; $row++) {
        if ($row > 0) {
            $parts[] = "\n";
        }
        // ... rest of loop using $parts[] = ... instead of $out .= ...
    }
    // close hyperlink + reset SGR
    if ($prevLink !== null) {
        $parts[] = "\x1b]8;;\x1b\\";
    }
    if ($prevStyle !== null) {
        $parts[] = "\x1b[0m";
    }
    return implode('', $parts);
}
```

**Fix - DiffEncoder.php:50-65 (`encode`):**
```php
public function encode(array $ops): string
{
    $parts = [];
    foreach ($ops as $op) {
        $parts[] = $this->encodeOp($op);
    }
    // Close any open hyperlink + reset SGR.
    if ($this->currentLinkUrl !== null) {
        $parts[] = "\x1b]8;;\x1b\\";
        $this->currentLinkUrl = null;
    }
    return implode('', $parts);
}
```

**Verification:**
- Existing tests verify byte-identical output
- Run `vendor/bin/phpunit`
- Benchmark: render 80×24 buffer 1000 times, measure time

**Investigation Notes:**
- Source: `src/Buffer.php:433` and `src/Diff/DiffEncoder.php:52-56`

---

### 3.2 Fix `DiffOptimiser::mergeCellSpans()` array_merge in loop [PENDING]

**File:** `src/Diff/DiffOptimiser.php:96`

**What:**
```php
$buffer = array_merge($buffer, $op->cells);  // called every iteration
```
`array_merge()` re-indexes numeric keys and creates a new array on each call — O(n²) for large diffs.

**Why:** Performance — unnecessary algorithmic complexity.

**Severity:** Medium

**Fix:**
```php
// Replace:
$buffer = array_merge($buffer, $op->cells);
// With:
foreach ($op->cells as $cell) {
    $buffer[] = $cell;
}
// Or: array_push($buffer, ...$op->cells);
```

**Verification:**
- Run `vendor/bin/phpunit` — all tests pass
- Byte-identical output verified by existing tests

**Investigation Notes:**
- Source: `src/Diff/DiffOptimiser.php:96`
- `mergeCellSpans` at lines 83-118

---

## Phase 4: State Management & Reusability [PENDING]

### 4.1 Add state reset to `DiffEncoder::encode()` [PENDING]

**File:** `src/Diff/DiffEncoder.php:28-44 (properties)` and `src/Diff/DiffEncoder.php:50-65 (encode method)`

**What:** `DiffEncoder` maintains mutable state (`$cursorCol`, `$cursorRow`, `$currentStyle`, `$currentLinkUrl`, `$lastRune`) across multiple `encode()` calls. Calling `encode()` twice on the same instance applies the second diff starting from where the first left off.

**Why:** Incorrect output if the same `DiffEncoder` instance is reused for multiple diffs. All existing tests create a fresh `DiffEncoder` per test, so this bug hasn't been caught.

**Severity:** High (silent incorrect behavior on reuse)

**Fix:**
```php
public function encode(array $ops): string
{
    // Reset all state at start of encode()
    $this->cursorCol = 1;
    $this->cursorRow = 1;
    $this->currentStyle = null;
    $this->currentLinkUrl = null;
    $this->lastRune = null;

    $parts = [];
    // ... rest of method
}
```

**Verification:**
- Write test: reuse same DiffEncoder instance for two different diffs — verify each encodes correctly
- Run `vendor/bin/phpunit`

**Investigation Notes:**
- Source: `src/Diff/DiffEncoder.php:28-44` (properties), `50-65` (encode method)
- `testEncodeMoveCursorAlreadyAtPositionFromDifferentCursor` at DiffEncoderTest.php:54-60 actually tests state carrying over (line 57 encodes, line 58 encodes again) — this passes because the second call is a no-op for the same position, not because of correct reset

---

### 4.2 Fix `DiffOptimiser::canMergeWithBuffer()` empty-cell edge case [PENDING]

**File:** `src/Diff/DiffOptimiser.php:125-127`

**What:**
```php
if (empty($op->cells)) {
    return true;  // empty SetCellOp merges with anything
}
```
An empty `SetCellOp` merging with any buffer is unusual behavior — essentially a no-op that could mask bugs.

**Why:** Defensive programming — makes optimizer behavior more predictable.

**Severity:** Low

**Fix:**
```php
if (empty($op->cells)) {
    return false;  // empty SetCellOp should not merge with anything
}
```

**Verification:**
- Write test: empty SetCellOp does not merge with existing buffer
- Run `vendor/bin/phpunit`

**Investigation Notes:**
- Source: `src/Diff/DiffOptimiser.php:125-127`

---

## Phase 5: Serialization Support [PENDING]

### 5.1 Implement `__serialize()`/`__unserialize()` for all value objects [PENDING]

**Files:** `src/Buffer.php`, `src/Cell.php`, `src/Style.php`, `src/Hyperlink.php`, `src/Position.php`, `src/Region.php`

**What:** No serialization support. `Buffer` and `Cell` objects cannot be serialized, preventing caching (APCu/Redis), inter-process sharing, or storing diffs for later replay.

**Why:** Enables caching and IPC use cases in the ReactPHP ecosystem.

**Severity:** Medium (missing feature)

**Fix - Cell.php:**
```php
public function __serialize(): array
{
    return [
        'rune' => $this->rune,
        'style' => $this->style,
        'link' => $this->link,
        'width' => $this->width,
    ];
}

public function __unserialize(array $data): void
{
    $this->rune = $data['rune'];
    $this->style = $data['style'];
    $this->link = $data['link'];
    $this->width = $data['width'];
}
```

Apply similar pattern to: `Style`, `Hyperlink`, `Position`, `Region`, `Buffer`.

**Note:** Buffer serialization requires special handling since `$grid` is a flat array of Cell objects.

**Verification:**
- Write test: serialize and unserialize a Buffer — verify equality
- Write test: serialize and unserialize a Cell — verify equality
- Run `vendor/bin/phpunit`

---

## Phase 6: Missing Features [PENDING]

### 6.1 Implement `JsonSerializable` on all value objects [PENDING]

**Files:** `src/Buffer.php`, `src/Cell.php`, `src/Style.php`, `src/Hyperlink.php`, `src/Position.php`, `src/Region.php`

**What:** `Buffer` and `Cell` cannot be JSON-encoded directly.

**Why:** Applications needing to serialize buffer state for debugging, caching, or IPC must manually convert.

**Severity:** Low (missing convenience feature)

**Fix:**
```php
// Cell.php
public function jsonSerialize(): array
{
    return [
        'rune' => $this->rune,
        'style' => $this->style,
        'link' => $this->link,
        'width' => $this->width,
    ];
}
```

Apply similar pattern to other value objects.

**Verification:**
- Write test: `json_encode($buffer)` produces valid JSON
- Run `vendor/bin/phpunit`

---

### 6.2 Add `Buffer::fill(Region $region, Cell $cell)` [PENDING]

**File:** `src/Buffer.php`

**What:** No efficient way to fill a rectangular region with a cell value. Users must call `withCellAt()` repeatedly (O(n) per call, O(n²) total).

**Why:** Common operation for UI rendering — e.g., clearing a region, filling a box with a character.

**Severity:** Medium (missing convenience feature)

**Fix:**
```php
/**
 * Return a new Buffer with $cell filling $region (clipped to buffer bounds).
 */
public function fill(Region $region, Cell $cell): self
{
    $grid = $this->grid;
    for ($dy = 0; $dy < $region->height; $dy++) {
        for ($dx = 0; $dx < $region->width; $dx++) {
            $col = $region->origin->col + $dx;
            $row = $region->origin->row + $dy;
            if ($col < 0 || $col >= $this->width || $row < 0 || $row >= $this->height) {
                continue;
            }
            $grid[$row * $this->width + $col] = $cell;
        }
    }
    return $this->mutate(['grid' => $grid]);
}
```

**Verification:**
- Write test: fill a region — verify all cells in region are the fill cell
- Write test: fill a region that extends beyond buffer bounds — verify clipping
- Run `vendor/bin/phpunit`

---

### 6.3 Add `Buffer::copy(Region $region): self` [PENDING]

**File:** `src/Buffer.php`

**What:** No way to extract a sub-region of a buffer to a new buffer.

**Why:** Common operation for UI composition — e.g., extracting a window content for double-buffering.

**Severity:** Low (missing convenience feature)

**Fix:**
```php
/**
 * Return a new Buffer containing a copy of $region (clipped to this buffer).
 */
public function copy(Region $region): self
{
    $newWidth = $region->width;
    $newHeight = $region->height;
    $newGrid = [];

    for ($dy = 0; $dy < $region->height; $dy++) {
        for ($dx = 0; $dx < $region->width; $dx++) {
            $srcCol = $region->origin->col + $dx;
            $srcRow = $region->origin->row + $dy;
            if ($srcCol < 0 || $srcCol >= $this->width || $srcRow < 0 || $srcRow >= $this->height) {
                $newGrid[$dy * $newWidth + $dx] = Cell::new();
            } else {
                $newGrid[$dy * $newWidth + $dx] = $this->grid[$srcRow * $this->width + $srcCol];
            }
        }
    }
    return self::fromGrid($newWidth, $newHeight, $newGrid);
}
```

**Verification:**
- Write test: copy a region — verify contents match
- Write test: copy a region that extends beyond buffer — verify clipping to blank cells
- Run `vendor/bin/phpunit`

---

## Phase 7: Minor Improvements [PENDING]

### 7.1 Document `DiffOp` type constants are unused [PENDING]

**File:** `src/Diff/DiffOp.php:21-36`

**What:** Type constants like `TYPE_MOVE_CURSOR = 'move_cursor'` are string constants on an abstract class but never used in the codebase (the `instanceof` operator is used for dispatch).

**Why:** Documentation improvement — clarify these are historical artifacts.

**Severity:** Low

**Fix:** Add docblock noting these are deprecated/not used:
```php
/**
 * @deprecated These constants are historical artifacts — the codebase
 * uses `instanceof` for op dispatch. They may be removed in a future
 * major version.
 */
abstract class DiffOp
{
    /** @deprecated Use `instanceof` dispatch instead. */
    public const TYPE_MOVE_CURSOR = 'move_cursor';
    // ...
}
```

**Verification:**
- Run `vendor/bin/phpunit` — no tests should break (these are just constants)

---

### 7.2 Document `withRegion()` negative origin clipping behavior [PENDING]

**File:** `src/Buffer.php:143-145` and add to `testWithRegionClipsNegativeOrigin` comment

**What:** When `region->origin->col` or `row` is negative, cells are clipped. The test at line 283-318 confirms this works, but the behavior of picking `src (1,1)` when `dst (0,0)` is targeted may be surprising.

**Why:** Documentation improvement — prevent user confusion.

**Severity:** Low

**Fix:** Add inline comment in `withRegion()` method explaining negative origin handling:
```php
// Negative origin values are clipped — cells outside buffer bounds are skipped.
if ($dstCol < 0 || $dstRow < 0) {
    continue;
}
```

**Investigation Notes:**
- Source: `src/Buffer.php:143-145` and `src/Buffer.php:283-318` (testWithRegionClipsNegativeOrigin)
- Behavior confirmed correct — just needs documentation

---

### 7.3 Document `Region::contains()` zero-width/height behavior [PENDING]

**File:** `src/Region.php` (add docblock)

**What:** `Region` can be constructed with width=0 or height=0. `contains()` always returns `false` for such regions (since `col <= right()` would be `0 <= -1`).

**Why:** Documentation improvement — clarify semantic behavior.

**Severity:** Low

**Fix:** Add `@see` docblock to `Region::contains()` pointing to the zero-size edge case behavior.

---

### 7.4 Document `Position` negative coordinate semantics [PENDING]

**File:** `src/Position.php` (add docblock)

**What:** `Position::new(-5, -10)` is allowed — useful for relative positions but could cause issues if used directly with `Buffer::cellAt()` without bounds checking.

**Why:** Documentation improvement — clarify when negative coordinates are valid.

**Severity:** Low

**Fix:** Add docblock to constructor:
```php
/**
 * @param int $col Column (0-based). May be negative for relative offsets.
 * @param int $row Row (0-based). May be negative for relative offsets.
 * Note: Negative coordinates must be bounds-checked before use with
 * Buffer::cellAt().
 */
public function __construct(
    public readonly int $col,
    public readonly int $row,
) {}
```

---

### 7.5 Add toggle builders to Style for other attributes [PENDING]

**File:** `src/Style.php:104-117`

**What:** Only `withBold()` and `withReverse()` have toggle (bool) variants. Other attributes (italic, underline, strike, faint, blink, overline, invisible) only have `withAttrs()` which replaces the entire bitmask.

**Why:** API consistency with upstream Charmbracelet/lipgloss Style.

**Severity:** Low

**Fix:**
```php
public function withItalic(bool $on = true): self
{
    return $this->mutate(['attrs' => $on
        ? ($this->attrs | self::ATTR_ITALIC)
        : ($this->attrs & ~self::ATTR_ITALIC)]);
}

public function withUnderline(bool $on = true): self
{
    return $this->mutate(['attrs' => $on
        ? ($this->attrs | self::ATTR_UNDERLINE)
        : ($this->attrs & ~self::ATTR_UNDERLINE)]);
}

// ... etc for: strike, faint, blink, overline, invisible
```

**Verification:**
- Write tests for each new builder
- Run `vendor/bin/phpunit`

---

## Phase 8: Dependency & Compatibility [PENDING]

### 8.1 Pin `sugarcraft/candy-core` to stable version [PENDING]

**File:** `composer.json:31`

**What:**
```json
"sugarcraft/candy-core": "dev-master"
```
Using `dev-master` for a required dependency is unstable.

**Why:** Stability — production dependencies should be pinned to stable versions.

**Severity:** Medium

**Fix:** When `candy-core` has a stable release, update to `^1.0` or similar. Until then, document the risk.

**Investigation Notes:**
- Source: `composer.json:31`
- `candy-core` is a dev dependency (used for `candy-testing` in test builds)
- Actually looking at the output, it's in `require-dev`, not `require` — still should be pinned

---

### 8.2 Note: PHP 8.4 property hooks not used [PENDING]

**File:** All value objects

**What:** Library targets PHP 8.3+ but doesn't use PHP 8.4's property hooks for readonly promoted properties.

**Why:** Future consideration — would reduce boilerplate.

**Severity:** Low (future consideration)

**Fix:** Add note to `CALIBER_LEARNINGS.md` as a future optimization when PHP 8.4 adoption is wider.

---

## Priority Order Summary

| Priority | Item | Phase | Status |
|----------|------|-------|--------|
| 1 | Fix EraseRunOp cursor advance (1.2) | 1 | PENDING |
| 2 | Add Style::equals() (1.3) | 1 | PENDING |
| 3 | Fix Buffer::diff() Style identity (1.4) | 1 | PENDING |
| 4 | Add Cell width validation (2.1) | 2 | PENDING |
| 5 | Fix Hyperlink identity comparison (2.2) | 2 | PENDING |
| 6 | Add DiffEncoder state reset (4.1) | 4 | PENDING |
| 7 | Fix string concatenation loops (3.1) | 3 | PENDING |
| 8 | Fix DiffOptimiser array_merge (3.2) | 3 | PENDING |
| 9 | Fix empty-cell edge case (4.2) | 4 | PENDING |
| 10 | Implement serialization (5.1) | 5 | PENDING |
| 11 | Add JsonSerializable (6.1) | 6 | PENDING |
| 12 | Add Buffer::fill() (6.2) | 6 | PENDING |
| 13 | Add Buffer::copy() (6.3) | 6 | PENDING |
| 14 | Document DiffOp constants (7.1) | 7 | PENDING |
| 15 | Document withRegion behavior (7.2) | 7 | PENDING |
| 16 | Document Region::contains() (7.3) | 7 | PENDING |
| 17 | Document Position negative coords (7.4) | 7 | PENDING |
| 18 | Add Style toggle builders (7.5) | 7 | PENDING |
| 19 | Pin candy-core dependency (8.1) | 8 | PENDING |
| 20 | Note PHP 8.4 hooks (8.2) | 8 | PENDING |
| — | Fix RepeatRunOp wide-char (1.1) | 1 | PENDING (test-only path) |

---

## Notes

- 2026-06-30: Plan created based on `findings/candy-buffer.md` code review findings
- Critical bugs (Phase 1) should be fixed before any other phases
- All fixes must maintain byte-identical output for existing tests
- New tests should be added for each fix to prevent regression
- `applyDiff` is documented as test-only (line 331), but bugs there should still be fixed for future-proofing
