# Audit: sugar-boxer

**Library:** SugarCraft/sugar-boxer  
**Date:** 2026-06-30  

---

## Overview

`sugar-boxer` is a PHP port of `treilik/bubbleboxer` — a box-drawing layout engine for composing terminal UI into H/V panel layouts. Well-structured with proper immutability and good test coverage.

---

## 1. Issues (Bugs, Edge Cases, Error Handling)

### Finding 1: Division by zero in `distribute()` when all weights are zero
**Severity:** HIGH  
**Location:** `src/SugarBoxer.php:792`

```php
$share = (int) \round($weights[$i] / $totalWeight * $contentSpan);
```

If all children have `minWidth = 0`, `$totalWeight` will be 0 causing `NaN`.

**Recommendation:**
```php
if ($totalWeight === 0) {
    $totalWeight = $n;
    $weights = \array_fill(0, $n, 1);
}
```

---

### Finding 2: Zero-width grapheme carry can grow unboundedly
**Severity:** MEDIUM  
**Location:** `src/SugarBoxer.php:426`

`$carry` accumulates combining characters without a length cap. An adversarial input could cause memory issues.

**Recommendation:** Add a cap (e.g., 100 graphemes) and flush/truncate if exceeded.

---

### Finding 3: `withMargin()` uses fragile `func_num_args()` pattern
**Severity:** MEDIUM  
**Location:** `src/Node.php:227-231`

```php
$n = \func_num_args();
$right  = $n >= 2 ? $right  : $top;
$bottom = $n >= 3 ? $bottom : $top;
$left   = $n >= 4 ? $left   : $right;
```

**Recommendation:** Use explicit nullable parameters:
```php
public function withMargin(int $top, ?int $right = null, ?int $bottom = null, ?int $left = null): self
```

---

### Finding 4: Identity check `=== []` for empty array
**Severity:** LOW  
**Location:** `src/SugarBoxer.php:329`

**Recommendation:** Change to `empty($node->children)`.

---

### Finding 5: No validation for `$width` or `$height` being negative
**Severity:** MEDIUM  
**Location:** `src/SugarBoxer.php:87`

Early-return guards silently return empty string for negative dimensions. Caller passing `-1` instead of `10` gets silent failure.

**Recommendation:**
```php
if ($width < 1 || $height < 1) {
    throw new \InvalidArgumentException('Viewport dimensions must be positive');
}
```

---

## 2. Performance Problems

### Finding 6: SGR prefix recomputed on every `renderContent` call
**Severity:** MEDIUM  
**Location:** `src/SugarBoxer.php:362-369`

Probes style by rendering a space on every call. Same style reused 1000× computes same prefix 1000×.

**Recommendation:** Cache via `WeakMap<Style, string>`.

---

### Finding 7: Multiple passes over children arrays
**Severity:** MEDIUM  
**Location:** `src/SugarBoxer.php:235-243`

Two `array_map` calls iterate all children. For N children, 2N iterations.

**Recommendation:** Single-pass:
```php
$bases = [];
$flexes = [];
foreach ($children as $c) {
    $bases[] = $c->totalWidth();
    $flexes[] = $c->flex;
}
```

---

### Finding 8: `totalWidth()`/`totalHeight()` recursively recomputed
**Severity:** LOW  
**Location:** `src/Node.php:260-309`

Consider lazy caching if profiling shows it matters.

---

### Finding 9: `function_exists` called on every grapheme check
**Severity:** LOW  
**Location:** `src/SugarBoxer.php:546-547`

Cache in static variable.

---

### Finding 10: Regex recompiled per call in `sgrLeavesStyleOpen`
**Severity:** LOW  
**Location:** `src/SugarBoxer.php:576`

Extract to static compiled pattern.

---

## 3. Memory Leaks

### Finding 11: No memory leak issues found
**Severity:** N/A  

No streams, resources, or lingering references. `previousFrame` properly cleared on resize.

---

## 4. Security

### Finding 12: No input size limits documented
**Severity:** LOW  

Large inputs could cause memory exhaustion. Document expected size limits.

---

## 5. Complexity

### Finding 13: `nop()` sentinel uses `\stdClass` instead of dedicated type
**Severity:** LOW  
**Location:** `src/Node.php:320-324`

**Recommendation:** Use a proper sentinel class with `instanceof`.

---

### Finding 14: Border separators not drawn between flex children
**Severity:** MEDIUM  
**Location:** `src/SugarBoxer.php:268-270`

In flex path, separator drawing is omitted. Not documented.

**Recommendation:** Document this behavioral difference.

---

## 6. Missing Features / Incomplete Port

### Finding 15: NOBORDER node type ignores multiple children
**Severity:** LOW  
**Location:** `src/SugarBoxer.php:327-331`

Only passes through first child, ignoring others.

---

## 7. PHP 8.3/8.4 Compatibility

### Finding 16: Fully compatible with PHP 8.3+
**Severity:** N/A  

Uses promoted constructors, `match`, `readonly`, `final class`. No issues.

---

### Finding 17: Zero-dimension edge case in `array_fill`
**Severity:** LOW  
**Location:** `src/SugarBoxer.php:92`

`array_fill(0, 0, ...)` returns empty array. Ensure early-return guards catch `$width === 0`.

---

## 8. Async/ReactPHP Improvements

### Finding 18: No async improvements applicable
**Severity:** N/A  

Pure computation library with no I/O. No natural async boundary.

---

## Summary

| # | Category | Severity | Location | Title |
|---|----------|----------|----------|-------|
| 1 | Bug | HIGH | `SugarBoxer.php:792` | Division by zero in `distribute()` |
| 2 | Edge case | MEDIUM | `SugarBoxer.php:426` | Unbounded carry string |
| 3 | Edge case | MEDIUM | `Node.php:227-231` | `func_num_args()` in `withMargin()` |
| 5 | Edge case | MEDIUM | `SugarBoxer.php:87` | No negative viewport validation |
| 6 | Performance | MEDIUM | `SugarBoxer.php:362-369` | SGR prefix recomputed per render |
| 7 | Performance | MEDIUM | `SugarBoxer.php:235-243` | Multiple passes over children |
| 14 | Missing feature | MEDIUM | `SugarBoxer.php:268-270` | No separators with flex children |
| 4,9,10,11,13,15,16,17,18 | Various | LOW/N/A | various | Various low-priority issues |

**Total: 18 findings**
