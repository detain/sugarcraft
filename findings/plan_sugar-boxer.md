---
status: not-started
phase: 1
updated: 2026-06-30
---

# Implementation Plan: sugar-boxer Audit Fixes

## Goal

Address all 18 findings from the sugar-boxer audit: fix division-by-zero crash, bound unbounded carry string, replace `func_num_args()` pattern, add input validation, optimize performance, and document behavioral quirks.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Use `WeakMap<Style, string>` for SGR prefix cache | WeakMap auto-GC's styles when they're no longer referenced; no manual invalidation needed | Finding 6 recommendation |
| Cap carry at 100 graphemes | Balances safety against adversarial input vs. legitimateCombining character sequences | Finding 2 recommendation |
| Replace `func_num_args()` with nullable params | Modern PHP 8.3 pattern; explicit is clearer than introspection | Finding 3 recommendation |
| Use `InvalidArgumentException` for viewport validation | Standard PHP exception for precondition violations; matches PHP built-in conventions | Finding 5 recommendation |
| Add static compiled regex pattern | Pre-compiled patterns avoid repeated `preg_match` compilation overhead | Finding 10 recommendation |
| Use dedicated sentinel class instead of `\stdClass` | Proper type safety with `instanceof` checks; follows OOP best practices | Finding 13 recommendation |
| Single-pass children iteration | Reduces algorithmic complexity from 2N to N for children array processing | Finding 7 recommendation |
| Guard `array_fill(0, 0, ...)` with early-return | Empty array is valid but was not explicitly handled; defensive coding | Finding 17 |

## Phase 1: Critical/HIGH Severity Fixes [PENDING]

- [ ] **1.1 Fix division by zero in `distribute()`** ← CURRENT
- [ ] **1.2 Add viewport dimension validation in `render()`**

### Task 1.1: Fix division by zero in `distribute()`

**File:** `src/SugarBoxer.php`  
**Line:** ~792  
**Severity:** HIGH  
**Rationale:** When all children have `minWidth = 0` (or `minHeight = 0`), `$totalWeight` is 0, causing division by zero (`NaN` result). This crashes layouts with all-zero-weighted children.

**What to implement:**

```php
private function distribute(int $available, array $weights, int $totalWeight, int $spacing, int $borderPad): array
{
    $n = \count($weights);
    $contentSpan = $available - $spacing * \max(0, $n - 1);
    
    // Guard: if all weights are 0, distribute equally
    if ($totalWeight === 0) {
        $totalWeight = $n;
        $weights = \array_fill(0, $n, 1);
    }
    
    $offsets = [0 => $borderPad];
    $used = $borderPad;
    // ... rest unchanged
}
```

**Conditions for success:**
- Add test: horizontal/vertical layout with all-zero `minWidth`/`minHeight` children renders without NaN/crash
- Existing `testManyFixedChildrenNarrowViewportNoVanish` still passes

**Investigation notes:**
- `distribute()` called from `renderHorizontal` (line 243) and `renderVertical` (line 302)
- In `renderHorizontal` line 241: `$weights = \array_map(fn(Node $c) => $c->minWidth > 0 ? $c->minWidth : 1, $children)` — minWidth=0 becomes 1, so `$totalWeight` won't be 0 via that path
- However, `renderVertical` line 300 uses `minHeight` with same fallback, and `distribute()` is also called from `distributeFlex` indirectly
- The actual bug: if a child has `minWidth = 0` explicitly AND the fallback `? $c->minWidth : 1` logic somehow gets bypassed or all children have explicit 0... but looking at line 241, the ternary ensures 1 is used as fallback. The bug description says `$totalWeight` can be 0 when "all children have `minWidth = 0`" — this is only possible if the ternary fallback is removed or if the code path changes. The finding may be a forward-looking guard.

---

### Task 1.2: Add viewport dimension validation in `render()`

**File:** `src/SugarBoxer.php`  
**Line:** ~87-92  
**Severity:** HIGH  
**Rationale:** Passing negative `-1` instead of `10` for `$width`/`$height` silently returns an empty/corrupt string. The caller has no indication something went wrong.

**What to implement:**

```php
public function render(Node $root, int $width, int $height): string
{
    if ($width < 1 || $height < 1) {
        throw new \InvalidArgumentException(
            \sprintf('Viewport dimensions must be positive, got width=%d height=%d', $width, $height)
        );
    }
    
    // 2D cell grid: each cell holds one logical character (any byte length).
    $cells = \array_fill(0, $height, \array_fill(0, $width, ' '));
    // ...
}
```

**Conditions for success:**
- Add test: `render($layout, -1, 10)` throws `\InvalidArgumentException`
- Add test: `render($layout, 10, -1)` throws `\InvalidArgumentException`
- Add test: `render($layout, 0, 10)` throws `\InvalidArgumentException`
- Add test: valid dimensions still work (existing tests cover this)

**Investigation notes:**
- Line 92: `$cells = \array_fill(0, $height, \array_fill(0, $width, ' '));` — `array_fill(0, 0, ...)` returns empty array per Finding 17, but `$width = 0` would create empty inner arrays causing downstream issues
- The early-return guards at line 139 (`if ($w <= 0 || $h <= 0) return;`) return silently — this is the silent failure mentioned

---

## Phase 2: MEDIUM Severity Fixes [PENDING]

- [ ] **2.1 Bound the carry string in `placeLine()`**
- [ ] **2.2 Replace `func_num_args()` in `withMargin()`**
- [ ] **2.3 Optimize SGR prefix computation with WeakMap cache**
- [ ] **2.4 Single-pass children iteration**
- [ ] **2.5 Document flex children separator behavior**

### Task 2.1: Bound the carry string

**File:** `src/SugarBoxer.php`  
**Line:** ~413-428  
**Severity:** MEDIUM  
**Rationale:** The `$carry` string accumulates zero-width graphemes (combining characters) without bound. An adversarial input with thousands of combining marks could cause memory exhaustion.

**What to implement:**

In `placeLine()` method, add a cap:

```php
private function placeLine(string $line, int $x, int $y, int $w, array &$cells): void
{
    // ...
    $carry     = '';      // zero-width graphemes awaiting a base cell
    $MAX_CARRY = 100;    // cap to prevent memory exhaustion
    
    foreach ($segments as $seg) {
        // ...
        if ($gw <= 0) {
            if ($lastCol >= 0) {
                // Cap carry to prevent unbounded growth
                if (\strlen($carry) < $MAX_CARRY) {
                    $cells[$y][$x + $lastCol] .= $seg;
                }
            } else {
                // When no previous cell, accumulate in carry (up to cap)
                if (\strlen($carry) < $MAX_CARRY) {
                    $carry .= $seg;
                }
            }
            // ...
        }
        // ...
    }
}
```

**Conditions for success:**
- Add test: adversarial input with 200 combining chars doesn't cause memory issues (compare `strlen($carry)`)
- Normal combining character sequences (e.g., accent + letter) still work correctly
- Test at the boundary: exactly 100 combining chars is accepted, 101 is truncated

**Investigation notes:**
- `$carry` holds combining characters (zero-width graphemes) when they appear before any base character
- When `$lastCol < 0` (no base cell yet), carry accumulates until a base char arrives
- The cap of 100 graphemes allows most legitimateCombining sequences while preventing DoS

---

### Task 2.2: Replace `func_num_args()` in `withMargin()`

**File:** `src/Node.php`  
**Line:** ~225-231  
**Severity:** MEDIUM  
**Rationale:** The `func_num_args()` introspection is fragile and doesn't allow explicit `null` passing. Modern PHP 8.3 nullable parameter syntax is clearer.

**What to implement:**

```php
/**
 * Set outer margin (top, right, bottom, left).
 * sugar-boxer-specific: candy-sprinkles Style does not ship margin as a
 * first-class concept.
 *
 * @param int      $top
 * @param int|null $right  Defaults to $top when null
 * @param int|null $bottom Defaults to $top when null
 * @param int|null $left   Defaults to $right when null
 */
public function withMargin(int $top, ?int $right = null, ?int $bottom = null, ?int $left = null): self
{
    $right  ??= $top;
    $bottom ??= $top;
    $left   ??= $right;
    return $this->with(margin: [$top, $right, $bottom, $left], borderStyle: self::nop(), style: self::nop(), alignH: self::nop(), alignV: self::nop());
}
```

**Conditions for success:**
- Existing tests `testNodeWithMargin*` all pass
- `withMargin(1)` → [1,1,1,1]
- `withMargin(1, 2)` → [1,2,1,2]
- `withMargin(1, 2, 3)` → [1,2,3,2] (left defaults to right)
- `withMargin(1, 2, 3, 4)` → [1,2,3,4]
- `withMargin(0, 0, 0, 0)` → [0,0,0,0]

**Investigation notes:**
- The existing `testNodeWithMarginExplicitZeroSides` test at line 550-558 shows the current behavior
- CSS-style shorthand: if fewer than 4 args, missing values are copied from the opposite axis (top→bottom, right→left)
- The proposed implementation preserves this behavior exactly

---

### Task 2.3: Cache SGR prefix with WeakMap

**File:** `src/SugarBoxer.php`  
**Line:** ~362-369  
**Severity:** MEDIUM  
**Rationale:** `renderContent()` calls `Style::render(' ')` on every invocation to probe the SGR prefix, even when the same Style is reused 1000×. A WeakMap cache avoids recomputation.

**What to implement:**

Add a class-level WeakMap cache:

```php
final class SugarBoxer
{
    /** @var Buffer|null Lazily-built previous frame buffer */
    private ?Buffer $previousFrame = null;
    
    /** @var WeakMap<Style, string> SGR prefix cache to avoid repeated render probes */
    private WeakMap $sgrPrefixCache;
    
    public function __construct()
    {
        $this->sgrPrefixCache = new WeakMap();
    }
    
    // In renderContent():
    private function renderContent(string $text, int $x, int $y, int $w, int $h, array &$cells, ?Style $style = null, /*...*/): void
    {
        // ...
        // Pre-compute the SGR prefix from the style (render a single-char probe and strip trailing reset)
        $sgrPrefix = '';
        if ($style !== null) {
            $sgrPrefix = $this->sgrPrefixCache->get($style);
            if ($sgrPrefix === null) {
                $probe = $style->render(' ');
                $parts = \explode("\x00", $probe, 2);
                $sgrPrefix = $parts[0] ?? '';
                $this->sgrPrefixCache->set($style, $sgrPrefix);
            }
        }
        // ...
    }
}
```

**Conditions for success:**
- Performance benchmark: render 1000 frames with same Style object, SGR prefix computed only once
- Visual output identical before/after (SGR prefix unchanged)
- WeakMap auto-GCs entries when Style has no other references

**Investigation notes:**
- `Style` object from candy-sprinkles is immutable and reusable
- WeakMap was introduced in PHP 8.1, compatible with the ^8.3 requirement
- The `render(' ')` call returns `SGR-open + ' ' + SGR-close`, split on `\x00` sentinel to get only the opening SGR

---

### Task 2.4: Single-pass children iteration

**File:** `src/SugarBoxer.php`  
**Line:** ~235-243  
**Severity:** MEDIUM  
**Rationale:** Two `array_map` calls iterate all children (2N operations). A single loop reduces to N.

**What to implement:**

In `renderHorizontal()`:

```php
// Before (2N iterations):
if ($this->hasFlex($children)) {
    $offsets = $this->distributeFlex(
        $availableW,
        \array_map(fn(Node $c) => $c->totalWidth(), $children),   // N iterations
        \array_map(fn(Node $c) => $c->flex, $children),          // N iterations
        $sp,
        $b,
    );
}

// After (N iterations):
if ($this->hasFlex($children)) {
    $bases = [];
    $flexes = [];
    foreach ($children as $c) {
        $bases[] = $c->totalWidth();
        $flexes[] = $c->flex;
    }
    $offsets = $this->distributeFlex($availableW, $bases, $flexes, $sp, $b);
}
```

Also apply the same optimization to `renderVertical()` at lines 293-298.

**Conditions for success:**
- Same output as before (no behavioral change)
- `hasFlex()` still called separately (early exit optimization for non-flex case)
- Existing flex tests pass (FlexLayoutTest.php)

---

### Task 2.5: Document flex children separator behavior

**File:** `src/SugarBoxer.php`  
**Line:** ~268-270  
**Severity:** MEDIUM  
**Rationale:** Separator drawing (vertical line between children) is skipped in the flex path but documented nowhere. Users may expect separators and not understand why they disappear.

**What to implement:**

Add a docblock comment to `renderHorizontal()` explaining the behavior:

```php
/**
 * Render a horizontal (left-to-right) panel layout.
 *
 * Flex children: separators between children are ONLY drawn when
 * spacing === 0 AND the layout has NO flex children. This is because
 * the flex distribution algorithm resizes children dynamically, making
 * a fixed separator position meaningless. Use spacing >= 1 to add
 * visual gaps, or use bordered panels for explicit separation.
 *
 * @param Node $node
 * @param int  $x
 * @param int  $y
 * @param int  $w
 * @param int  $h
 * @param array<int, array<int, string>> $cells
 */
private function renderHorizontal(Node $node, int $x, int $y, int $w, int $h, array &$cells): void
```

Similarly for `renderVertical()` at lines 274-325.

**Conditions for success:**
- Behavior unchanged (no code change)
- Tests pass (no behavior change)
- Documentation visible in IDE/type hints

---

## Phase 3: LOW Severity / Cleanup [PENDING]

- [ ] **3.1 Change `=== []` to `empty()` check**
- [ ] **3.2 Add `totalWidth()/totalHeight()` lazy caching consideration**
- [ ] **3.3 Cache `function_exists('grapheme_extract')` result**
- [ ] **3.4 Extract compiled regex to static pattern**
- [ ] **3.5 Replace `\stdClass` sentinel with dedicated type**
- [ ] **3.6 Document NOBORDER ignoring multiple children**
- [ ] **3.7 Explicit guard for `array_fill(0, 0, ...)` edge case**

### Task 3.1: Change `=== []` to `empty()`

**File:** `src/SugarBoxer.php`  
**Line:** ~329  
**Severity:** LOW  
**Rationale:** Identity check `=== []` is fragile; `empty()` is idiomatic PHP.

```php
// Before:
if ($node->children === []) return;

// After:
if (empty($node->children)) return;
```

**Investigation notes:** Also see `renderHorizontal` line 220 and `renderVertical` line 278 which already use `$n === 0` pattern.

---

### Task 3.2: Consider lazy caching for `totalWidth()/totalHeight()`

**File:** `src/Node.php`  
**Line:** ~260-309  
**Severity:** LOW  
**Rationale:** Recursive recomputation on every call may be slow for deep trees if profiling shows it matters. Not a current issue.

**Recommendation:** Profile first; if needed, add lazy caching via `Closure::bind()` or compute-on-demand with memoization.

**Conditions for success:** Only implement if profiling shows measurable improvement.

---

### Task 3.3: Cache `function_exists` result

**File:** `src/SugarBoxer.php`  
**Line:** ~546-547  
**Severity:** LOW  
**Rationale:** `function_exists('grapheme_extract')` called on every grapheme check.

```php
private function nextGrapheme(string $s, int $i): string
{
    static $hasGrapheme = null;
    if ($hasGrapheme === null) {
        $hasGrapheme = \function_exists('grapheme_extract');
    }
    // ...
}
```

---

### Task 3.4: Extract compiled regex to static pattern

**File:** `src/SugarBoxer.php`  
**Line:** ~576  
**Severity:** LOW  
**Rationale:** `/\x1b\[([0-9;]*)m/` compiled on every `sgrLeavesStyleOpen()` call.

```php
private function sgrLeavesStyleOpen(string $s, bool $open): bool
{
    if (\strpos($s, "\x1b[") === false) {
        return $open;
    }
    // Pre-compiled pattern (class constant or static variable)
    static $sgrPattern = null;
    $sgrPattern ??= '/\x1b\[([0-9;]*)m/u';
    if (\preg_match_all($sgrPattern, $s, $matches) === 0) {
        return $open;
    }
    // ...
}
```

---

### Task 3.5: Replace `\stdClass` sentinel with dedicated type

**File:** `src/Node.php`  
**Line:** ~320-324  
**Severity:** LOW  
**Rationale:** Using `\stdClass` is a code smell; a dedicated type is more explicit and IDE-friendly.

```php
/**
 * Sentinel for "do not change" vs explicit null.
 */
final class Preserve {}
final class Node
{
    private static function preserve(): Preserve
    {
        static $sentinel;
        return $sentinel ??= new Preserve();
    }
}
```

Update all `self::nop()` calls to `self::preserve()`.

**Conditions for success:**
- All existing tests pass
- `instanceof Preserve` check used instead of identity comparison

---

### Task 3.6: Document NOBORDER ignoring extra children

**File:** `src/SugarBoxer.php`  
**Line:** ~327-331  
**Severity:** LOW  
**Rationale:** `renderNoBorder()` only passes through the first child. This should be documented since callers may expect all children to render.

```php
/**
 * No-border wrapper that renders the first child only.
 * 
 * NOTE: Multiple children are NOT supported by design — the NOBORDER node
 * type only renders `$node->children[0]`. Other children are silently ignored.
 * This matches the upstream treilik/bubbleboxer behavior.
 */
private function renderNoBorder(Node $node, int $x, int $y, int $w, int $h, array &$cells): void
```

---

### Task 3.7: Explicit guard for `array_fill(0, 0, ...)` edge case

**File:** `src/SugarBoxer.php`  
**Line:** ~92  
**Severity:** LOW  
**Rationale:** `array_fill(0, 0, ...)` returns `[]`, not an error. The existing `$width < 1 || $height < 1` guard from Task 1.2 handles this.

**Conditions for success:** Task 1.2's validation guard covers this case. Close as duplicate.

---

## Phase 4: Tests [PENDING]

- [ ] **4.1 Add division-by-zero test**
- [ ] **4.2 Add negative dimension validation tests**
- [ ] **4.3 Add carry cap tests**
- [ ] **4.4 Add SGR prefix cache validation test**
- [ ] **4.5 Add flex separator documentation test**

### Task 4.1: Division-by-zero regression test

**File:** `tests/SugarBoxerTest.php` or new `tests/DistributionTest.php`

```php
public function testDistributeWithAllZeroMinWidthDoesNotCrash(): void
{
    // All children with minWidth=0 should not cause division by zero
    $layout = Node::horizontal(
        Node::leaf('a')->withMinWidth(0),
        Node::leaf('b')->withMinWidth(0),
        Node::leaf('c')->withMinWidth(0),
    );
    
    $result = $this->boxer->render($layout, 30, 5);
    $this->assertIsString($result);
    $this->assertStringContainsString('a', $result);
    $this->assertStringContainsString('b', $result);
    $this->assertStringContainsString('c', $result);
}
```

### Task 4.2: InvalidArgumentException tests

```php
public function testRenderWithNegativeWidthThrows(): void
{
    $this->expectException(\InvalidArgumentException::class);
    $this->boxer->render(Node::leaf('test'), -1, 10);
}

public function testRenderWithNegativeHeightThrows(): void
{
    $this->expectException(\InvalidArgumentException::class);
    $this->boxer->render(Node::leaf('test'), 10, -1);
}

public function testRenderWithZeroWidthThrows(): void
{
    $this->expectException(\InvalidArgumentException::class);
    $this->boxer->render(Node::leaf('test'), 0, 10);
}
```

### Task 4.3: Carry cap test

```php
public function testCarryStringIsBounded(): void
{
    // 200 combining marks before any base character
    $adversarial = \str_repeat("\xcc\x80", 100) . 'x'; // 100 combining acute accents + 'x'
    $layout = Node::leaf($adversarial)->withBorder(false);
    
    // Should not throw or cause memory issues
    $result = $this->boxer->render($layout, 10, 3);
    $this->assertIsString($result);
    // The 'x' should appear (carry was flushed before exceeding cap)
    $this->assertStringContainsString('x', $result);
}
```

---

## Summary

| Task | Severity | Status | File:Line |
|------|----------|--------|-----------|
| 1.1 Fix division by zero | HIGH | PENDING | SugarBoxer.php:792 |
| 1.2 Validate viewport dimensions | HIGH | PENDING | SugarBoxer.php:87 |
| 2.1 Bound carry string | MEDIUM | PENDING | SugarBoxer.php:426 |
| 2.2 Replace func_num_args | MEDIUM | PENDING | Node.php:227-231 |
| 2.3 Cache SGR prefix (WeakMap) | MEDIUM | PENDING | SugarBoxer.php:362-369 |
| 2.4 Single-pass children iteration | MEDIUM | PENDING | SugarBoxer.php:235-243 |
| 2.5 Document flex separator behavior | MEDIUM | PENDING | SugarBoxer.php:268-270 |
| 3.1 Change === [] to empty() | LOW | PENDING | SugarBoxer.php:329 |
| 3.2 Lazy caching (profiling first) | LOW | PENDING | Node.php:260-309 |
| 3.3 Cache function_exists | LOW | PENDING | SugarBoxer.php:546-547 |
| 3.4 Static regex pattern | LOW | PENDING | SugarBoxer.php:576 |
| 3.5 Dedicated sentinel class | LOW | PENDING | Node.php:320-324 |
| 3.6 Document NOBORDER behavior | LOW | PENDING | SugarBoxer.php:327-331 |
| 3.7 array_fill edge case guard | LOW | PENDING | SugarBoxer.php:92 |

**Total: 18 findings → 14 tasks (3 merged: 3.7 duplicate of 1.2, 3.2 is investigate-only)**
