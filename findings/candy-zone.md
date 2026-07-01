# Audit: candy-zone

**Library:** SugarCraft/candy-zone  
**Date:** 2026-06-30  

---

## Overview

`candy-zone` provides a zone-based layout system for terminal UIs. Zones are rectangular regions that can be stacked vertically, selected between, and switched interactively. The library ports the `charmbracelet/zone` concept.

---

## 1. Issues (Bugs, Edge Cases, Error Handling)

### Finding 1 — `Zone::collide()` edge case: point exactly on boundary
**Severity:** LOW  
**Location:** `src/Zone.php:58-62`
```php
public function collidesWithPoint(int $x, int $y): bool
{
    return $x >= $this->x
        && $y >= $this->y
        && $x < $this->x + $this->width
        && $y < $this->y + $this->height;
}
```
Uses half-open interval `[x, x+width)` for both dimensions. This is correct for point-in-rectangle. No issue.

---

### Finding 2 — `Stack::current()` returns null Zone on empty stack
**Severity:** MEDIUM  
**Location:** `src/Stack.php:55-58`
```php
public function current(): ?Zone
{
    return $this->zones[$this->index] ?? null;
}
```
If `current()` is called when `$this->zones` is empty, it returns `null`. Callers that don't check for `null` could get `TypeError` when calling methods on `null`.

**Recommendation:** Document that `current()` can return `null` and all callers should null-check.

---

### Finding 3 — `Stack::switchTo()` does not validate index bounds
**Severity:** MEDIUM  
**Location:** `src/Stack.php:99-103`
```php
public function switchTo(int $index): self
{
    return $this->with('index', $index);
}
```
Passing `-1` or a value ≥ `count($zones)` creates an invalid state where `current()` returns `null` or an out-of-bounds zone.

**Recommendation:** Validate:
```php
if ($index < 0 || $index >= \count($this->zones)) {
    throw new \OutOfBoundsException("Zone index {$index} out of bounds [0, " . \count($this->zones) . ")");
}
```

---

### Finding 4 — `Stack::switchUp()` / `switchDown()` wrap around
**Severity:** LOW  
**Location:** `src/Stack.php:105-117`
```php
public function switchUp(): self
{
    $newIndex = $this->index - 1;
    if ($newIndex < 0) {
        $newIndex = \count($this->zones) - 1;
    }
    return $this->with('index', $newIndex);
}
```
Wrapping is documented and intentional. No bug.

---

### Finding 5 — No `Countable` or `Iterator` on Stack
**Severity:** LOW  
**Location:** `src/Stack.php`
`Stack` has `count()` method but doesn't implement `Countable`. `$stack->count()` works but `\count($stack)` would not.

**Recommendation:** Add `implements \Countable { public function count(): int }`.

---

## 2. Performance Problems

### Finding 6 — `Stack::switchTo()` always creates new instance even if index unchanged
**Severity:** LOW  
**Location:** `src/Stack.php:99-103`
```php
public function switchTo(int $index): self
{
    return $this->with('index', $index);
}
```
No early return if `$index === $this->index`. Minor allocation waste.

**Recommendation:**
```php
if ($index === $this->index) return $this;
```

---

### Finding 7 — No N+1 issues
**Severity:** N/A  
No nested loops or repeated traversals.

---

## 3. Memory Leaks

### Finding 8 — No memory leaks detected
**Severity:** N/A  
All objects are immutable value types. No streams, resources, or callbacks.

---

## 4. Security

### Finding 9 — No security concerns
**Severity:** N/A  
No user input, no file operations, no shell execution. Pure computation library.

---

## 5. Complexity

### Finding 10 — Complexity is appropriate
**Severity:** N/A  
Clean, minimal implementation. Each class has a single responsibility.

---

## 6. Missing Features / Incomplete Ports

### Finding 11 — No zone focus/blur lifecycle events
**Severity:** LOW  
Location: `src/Stack.php`

Gum's `zone` has `onFocus` / `onBlur` callbacks that fire when a zone becomes active/inactive. Not implemented.

**Recommendation:** Add `withOnFocus(callable)` and `withOnBlur(callable)` to Stack.

---

### Finding 12 — No `Zone::contains()` method (only `collidesWithPoint`)
**Severity:** LOW  
Location: `src/Zone.php`

Only point-in-rectangle check exists. No rect-vs-rect intersection.

**Recommendation:** Add `Zone::contains(Zone $other): bool` if rect intersection is needed.

---

### Finding 13 — No `examples/` directory
**Severity:** LOW  
No example usage files.

---

## 7. PHP 8.3/8.4 Compatibility

### Finding 14 — Fully compatible with PHP 8.3+
**Severity:** N/A  
Uses `readonly`, promoted constructors, `match` expressions, `final class`. No issues.

---

## 8. Async/ReactPHP Improvements

### Finding 15 — No async improvements needed
**Severity:** N/A  
This is a pure data model library. No natural async boundaries.

---

## Summary

| Severity | Count | Key Issues |
|----------|-------|------------|
| MEDIUM | 2 | `current()` can return null; `switchTo()` doesn't validate bounds |
| LOW | 4 | Missing Countable, no early return on switchTo, no focus/blur lifecycle, no examples |
| N/A | 5 | No perf issues, no memory leaks, no security concerns, complexity appropriate, PHP 8.3+ compatible |

**Total: 15 findings**
