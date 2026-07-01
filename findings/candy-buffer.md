# Code Review: candy-buffer

**Library:** `sugarcraft/candy-buffer` — Cell-grid value objects for terminal rendering  
**Review scope:** `src/` (all .php files) + `tests/` (all .php files)  
**Date:** 2026-06-29

---

## Summary

`candy-buffer` provides the core terminal rendering data model — `Buffer` (2-D cell grid), `Cell`, `Style`, `Hyperlink`, `Position`, `Region`, and a Diff system that produces minimal ANSI delta ops. The implementation is generally sound with good test coverage (round-trip diff tests, random iteration tests, golden-byte SGR tests). However, several real bugs and design issues were identified.

---

## 🔴 Critical Bugs

### 1. `RepeatRunOp` overwrites continuation cells in `applyDiff` — wide char corruption

**File:** `src/Buffer.php:394-413`

When `RepeatRunOp` processes a wide character (width=2), the loop writes a wide cell at position `c` and a continuation at `c+1`, then advances cursor by `count`. On the next iteration, `c` is now `cursorCol + 1` — overwriting the continuation cell that was just written.

```php
// Line 399-410
for ($i = 0; $i < $op->count; $i++) {
    $c = $cursorCol + $i;
    // ...
    $grid[$cursorRow * $this->width + $c] = new Cell($rune, $pendingStyle, null, $width);
    if ($width === 2) {
        $nextCol = $c + 1;
        if ($nextCol < $this->width) {
            $grid[$cursorRow * $this->width + $nextCol] = Cell::continuation();
            // BUG: on next iteration i=1, c = cursorCol+1, overwriting this continuation!
        }
    }
}
$cursorCol += $op->count; // advances by count, not by width
```

**Impact:** `applyDiff` is used only for round-trip testing (documented at line 331: "applyDiff is for round-trip testing only"), so this does not affect production rendering via `DiffEncoder`. However, any future use of `applyDiff` for actual buffer reconstruction would produce corrupted wide characters.

**Recommendation:** Either (a) document this limitation clearly, or (b) fix the cursor tracking: advance by `width * count` for wide chars, or track wide-char boundaries explicitly.

---

### 2. `EraseRunOp` cursor advance mismatch between `Buffer::applyDiff` and `DiffEncoder`

**File:** `src/Buffer.php:383-392` vs `src/Diff/DiffEncoder.php:150-152`

`Buffer::applyDiff` advances cursor after `EraseRunOp`:

```php
// Buffer.php:392
$cursorCol += $op->count;  // cursor advances
```

But `DiffEncoder::encodeEraseRun` comments explicitly:

```php
// DiffEncoder.php:150-151
// ECH erases characters in-place; the logical cursor does NOT
// advance (the cursor stays at the start of the erased region).
```

According to xterm control sequences, ECH (Erase Character) does NOT move the cursor. `DiffEncoder` is correct; `Buffer::applyDiff` is wrong.

**Impact:** Any subsequent `DiffOp` after an `EraseRunOp` in the same diff would be applied at the wrong cursor position in `applyDiff`. Since `applyDiff` is test-only, this affects round-trip test accuracy but not production rendering.

**Recommendation:** Fix `Buffer::applyDiff` to NOT advance cursor after `EraseRunOp`.

---

### 3. `Cell::equals()` uses object identity for `Style` comparison

**File:** `src/Cell.php:83-89`

```php
public function equals(Cell $other): bool
{
    return $this->rune() === $other->rune()
        && $this->style() === $other->style()  // object identity, not value equality
        && $this->link()?->url() === $other->link()?->url()
        && $this->width() === $other->width();
}
```

`Style` is an immutable value object. Two distinct `Style` instances with identical `fg`, `bg`, and `attrs` values would not be considered equal by `===`, even though they represent the same style. This affects `Buffer::diff()` which uses `Cell::equals()` at line 202.

**Recommendation:** Add a proper `Style::equals(Style $other): bool` method and use it in `Cell::equals()`. Alternatively, use PHP's `==` (loose comparison) instead of `===` (strict identity) for Style, or make Style a true value object with `__equals`.

---

### 4. `Buffer::diff()` uses object identity for Style comparison in repeat detection

**File:** `src/Buffer.php:274-276`

```php
if ($run[$i]->rune() !== $repeatRune
    || $run[$i]->style() !== $runStyle  // object identity
    || $run[$i]->link()?->url() !== $runLinkUrl
) {
    $allSame = false;
    break;
}
```

Two `Style` objects with identical values but different instances would prevent `RepeatRunOp` from being emitted, resulting in suboptimal (larger) diff output.

**Recommendation:** Use `Style::equals()` or `$run[$i]->style()->equals($runStyle)` instead of `!==`.

---

## 🟡 Significant Issues

### 5. No `Cell` width validation

**File:** `src/Cell.php:33-38`

The constructor accepts any `int` for `$width` despite the docblock and `width()` method documenting it as only `0, 1, or 2`:

```php
/**
 * @param int $width Display width in cells (1 or 2; 0 for continuation)
 */
public function __construct(
    public readonly string $rune,
    public readonly ?Style $style,
    public readonly ?Hyperlink $link,
    public readonly int $width,  // No validation
) {}
```

Invalid widths (e.g., 3, -1) could propagate through the system causing buffer corruption or index errors.

**Recommendation:** Add validation in the constructor:
```php
if ($width !== 0 && $width !== 1 && $width !== 2) {
    throw new \InvalidArgumentException('Cell width must be 0, 1, or 2');
}
```

---

### 6. Hyperlink equality uses object identity in `Buffer::toAnsi()`

**File:** `src/Buffer.php:450`

```php
if ($prevLink !== null && $cell->link() !== $prevLink) {
    $out .= "\x1b]8;;\x1b\\";  // close old link
}
```

If `$cell->link()` is a different `Hyperlink` object with the same URL as `$prevLink`, this triggers a close + immediate reopen of the same URL. This is redundant (sends extra bytes) but not incorrect.

**Impact:** Minor performance/bandwidth issue. Emits unnecessary OSC 8 close+open sequence when transitioning between two `Hyperlink` objects with identical URLs but different instances.

**Recommendation:** Add `Hyperlink::equals(Hyperlink $other): bool` using value equality (URL + ID comparison), and use it here.

---

### 7. String concatenation in loops — O(n²) for large buffers

**Files:** 
- `src/Buffer.php:433` (`toAnsi()`)
- `src/Diff/DiffEncoder.php:52-56` (`encode()`)

```php
// Buffer.php:433
$out .= '';  // string concatenation in loop

// DiffEncoder.php:52-56
foreach ($ops as $op) {
    $out .= $this->encodeOp($op);  // string concatenation in loop
}
```

PHP strings are immutable. Each ` .= ` creates a new string, copying all previous content. For large buffers (e.g., 80×24 terminal = 1920 cells), this is O(n²) with significant performance impact.

**Recommendation:** Use an array buffer and `implode()` at the end:
```php
$parts = [];
foreach ($ops as $op) {
    $parts[] = $this->encodeOp($op);
}
return implode('', $parts);
```

This applies to both `Buffer::toAnsi()` and `DiffEncoder::encode()`.

---

### 8. `DiffOptimiser::mergeCellSpans()` uses `array_merge()` in a loop

**File:** `src/Diff/DiffOptimiser.php:96`

```php
$buffer = array_merge($buffer, $op->cells);  // called every iteration
```

`array_merge()` re-indexes numeric keys and creates a new array on each call. For diffs with many cells, this is O(n²).

**Recommendation:** Use a simple loop with `$buffer[] = $cell` or `array_push($buffer, ...$op->cells)`.

---

### 9. `DiffOptimiser::canMergeWithBuffer()` empty-cell edge case

**File:** `src/Diff/DiffOptimiser.php:125-127`

```php
if (empty($op->cells)) {
    return true;  // empty SetCellOp merges with anything
}
```

An empty `SetCellOp` merging with any buffer is an unusual behavior. While it doesn't cause correctness issues (it's essentially a no-op), it's an obscure edge case that could mask bugs.

**Recommendation:** Return `false` for empty cells, making the optimizer more predictable.

---

### 10. No serialization support

`Buffer` and `Cell` objects cannot be serialized (no `__serialize()`/`__unserialize()`). This prevents:
- Caching rendered buffers (e.g., in APCu or Redis)
- Sharing buffers between processes
- Storing diffs for later replay

**Recommendation:** Implement `__serialize()`/`__unserialize()` for `Buffer`, `Cell`, `Style`, `Hyperlink`, `Position`, and `Region`.

---

### 11. `DiffEncoder` instance cannot be reused without state reset

**File:** `src/Diff/DiffEncoder.php:28-44`

`DiffEncoder` maintains mutable state (`$cursorCol`, `$cursorRow`, `$currentStyle`, `$currentLinkUrl`, `$lastRune`) across multiple `encode()` calls. Calling `encode()` twice on the same instance will apply the second diff starting from where the first left off (cursor position, active style, open hyperlink), which is incorrect.

**Impact:** Incorrect output if the same `DiffEncoder` instance is reused for multiple diffs. All existing tests create a fresh `DiffEncoder` per test, so this bug hasn't been caught.

**Recommendation:** Reset all state at the start of `encode()`:
```php
public function encode(array $ops): string
{
    $this->cursorCol = 1;
    $this->cursorRow = 1;
    $this->currentStyle = null;
    $this->currentLinkUrl = null;
    $this->lastRune = null;
    // ...
}
```

---

## 🟢 Minor Issues / Suggestions

### 12. Duplicate SGR attribute bit constants

**File:** `src/Style.php:22-30`

```php
public const ATTR_BOLD      = 1 << 0;  // defined
public const ATTR_ITALIC   = 1 << 1;  // defined
// ...
public function hasBold(): bool { return (bool)($this->attrs & self::ATTR_BOLD); }
// uses the constant
```

All `ATTR_*` constants are used in `SgrEmitter` but not in `Style` itself. The `has*()` methods use the constant values inline rather than referencing the constants. This is not a bug but a missed opportunity for consistency.

---

### 13. `DiffOp` type constants are strings, not class constants

**File:** `src/Diff/DiffOp.php:21-36`

```php
abstract class DiffOp
{
    public const TYPE_MOVE_CURSOR = 'move_cursor';  // string constant
```

These are documented as op "types" but are string constants on an abstract class, not true type markers. They are never used in the codebase (the `instanceof` operator is used instead for dispatch). They could be removed without affecting functionality.

---

### 14. `withRegion()` negative origin clipping — subtle behavior

**File:** `src/Buffer.php:143-145`

When `region->origin->col` or `row` is negative, cells are clipped. The test at line 283-318 (`testWithRegionClipsNegativeOrigin`) confirms this works, but the behavior of picking `src (1,1)` when `dst (0,0)` is targeted (as shown in the test comment) may be surprising.

This is not a bug, but the edge case behavior should be documented.

---

### 15. `Region::contains()` accepts zero-width/height regions

**File:** `src/Region.php:14-19`

`Region` can be constructed with width=0 or height=0 (e.g., `new Region(Position::new(0, 0), 0, 0)`). The `contains()` method would then always return `false` (since `col <= right()` would be `0 <= -1`). This is mathematically correct but semantically odd.

No validation is needed, but the behavior should be documented.

---

### 16. `Position` allows negative coordinates

**File:** `src/Position.php:14-17`

`Position::new(-5, -10)` is allowed. This is useful for relative positions but could cause issues if used directly with `Buffer::cellAt()` without bounds checking. No change needed — just note that negative positions are valid for offset calculations but must be bounds-checked before buffer access.

---

### 17. `Style::withBold()` / `withReverse()` only — other attributes lack toggle builders

**File:** `src/Style.php:104-117`

Only `withBold()` and `withReverse()` have toggle (bool) variants. Other attributes (italic, underline, strike, faint, blink, overline, invisible) only have `withAttrs()` which replaces the entire bitmask. 

For consistency, each attribute could have its own toggle builder:
```php
public function withItalic(bool $on = true): self
public function withUnderline(bool $on = true): self
// etc.
```

---

## 💡 Missing Features

### 18. No async / streaming rendering support

This is a ReactPHP-based ecosystem (`candy-buffer` depends on `candy-core` which is ReactPHP-based). The library provides no async rendering path:
- No `Generator`-based streaming rendering for large buffers
- No async observable/subscription model for buffer updates
- No integration with `react/stream`

**Recommendation:** Consider adding:
- `Buffer::toAnsiStream(): \Generator<string>` — yields ANSI chunks
- `Buffer::diffStream(Buffer $previous): \Generator<DiffOp>` — yields diff ops as they're computed
- A `BufferRenderer` interface for ReactPHP component integration

---

### 19. No `JsonSerializable` implementation

`Buffer` and `Cell` cannot be JSON-encoded directly. Applications needing to serialize buffer state (for debugging, caching, or IPC) must manually convert.

**Recommendation:** Implement `JsonSerializable` on all value objects.

---

### 20. No `Buffer::fill()` / bulk cell update

There is no way to efficiently fill a rectangular region with a cell value. Users must call `withCellAt()` repeatedly, which creates a new grid array on each call (O(n) per call, O(n²) total for filling a region).

**Recommendation:** Add:
```php
public function fill(Region $region, Cell $cell): self
public function fillRect(int $col, int $row, int $width, int $height, Cell $cell): self
```

---

### 21. No `Buffer::copy()` / clone with offset

There is no way to copy a sub-region of a buffer to a new buffer or to a different location in the same buffer without manually iterating.

**Recommendation:** Add:
```php
public function copy(Region $region): self  // extract sub-buffer
```

---

## 🔌 Compatibility Notes

### 22. Dependency: `sugarcraft/candy-core` is `dev-master`

**File:** `composer.json:31`

```json
"sugarcraft/candy-core": "dev-master"
```

Using `dev-master` for a required dependency is unstable. This should be pinned to a specific version or use a stable branch alias.

---

### 23. No PHP 8.4 property hooks usage

The library targets PHP 8.3+ but doesn't use PHP 8.4's property hooks. Readonly promoted properties with hooks could simplify the value object pattern:

```php
// Current: public accessor + private field
private int $width;
public function width(): int { return $this->width; }

// PHP 8.4: readonly int { public get; private init; }
```

This is optional but would reduce boilerplate.

---

## ✅ What's Done Well

- **Immutable value objects** — `Buffer`, `Cell`, `Style`, `Hyperlink`, `Position`, `Region` all correctly use readonly properties with private constructors and factory methods. The `mutate()` private method pattern for fluent builders is consistently applied.
- **Comprehensive round-trip testing** — `BufferTest::testRoundTripRandomPairsTwentyIterations` (line 696) runs 20 random diff+apply cycles, catching edge cases.
- **Golden byte tests** — SGR sequences are tested byte-exactly, ensuring `Buffer::toAnsi()` and `DiffEncoder::encode()` produce identical output.
- **Hyperlink security** — `Hyperlink` validates URLs/IDs for C0 control characters, preventing ANSI escape injection (documented at line 11-13).
- **Diff optimization** — `DiffOptimiser` correctly collapses redundant `SetStyleOp` sequences and merges cell spans.
- **Well-documented invariants** — wide character continuation cell requirements, buffer dimension constraints, and lossy hyperlink reconstruction are all clearly documented.
- **Minimal dependencies** — only `php: ^8.3` required for production; `candy-core` and `phpunit` are dev-only.

---

## Priority Recommendations

1. **Fix `EraseRunOp` cursor advance in `Buffer::applyDiff`** (bug #2) — one-line fix, removes incorrect cursor advancement
2. **Fix `Cell::equals()` Style comparison** (bug #3) — add `Style::equals()` method, use value equality
3. **Fix `Buffer::diff()` Style identity comparison** (bug #4) — use Style value equality for repeat detection
4. **Add width validation to `Cell` constructor** (issue #5) — prevents corrupted state propagation
5. **Fix `DiffEncoder` state reset** (issue #11) — reset all state at start of `encode()`
6. **Replace string concatenation loops with array buffers** (issue #7) — O(n²) → O(n) for large renders
7. **Fix `DiffOptimiser::mergeCellSpans` array_merge** (issue #8) — O(n²) → O(n)
8. **Add serialization support** (issue #10) — enables caching and IPC
9. **Add async streaming rendering** (issue #18) — fits ReactPHP ecosystem
