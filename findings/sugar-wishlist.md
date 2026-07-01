# Audit: sugar-wishlist

**Library:** SugarCraft/sugar-wishlist  
**Date:** 2026-06-30  

---

## Overview

`sugar-wishlist` ports the `charmbracelet/bubbles/wishlist` component — an interactive wishlist/item selector for TUI applications.

---

## 1. Issues (Bugs, Edge Cases, Error Handling)

### Finding 1 — Item selection doesn't validate index bounds
**Severity:** MEDIUM  
**Location:** `src/Wishlist.php`

`selectItem()` and `toggleItem()` accept any integer index. Negative or out-of-bounds indices are not validated, potentially causing undefined behavior.

**Recommendation:** Validate `$index >= 0 && $index < count($this->items)`.

---

### Finding 2 — No duplicate item prevention
**Severity:** LOW  
**Location:** `src/Wishlist.php`

Adding the same item twice results in duplicate entries. No unique-constraint enforcement.

---

### Finding 3 — Empty wishlist state not rendered specially
**Severity:** LOW  
**Location:** `src/Wishlist.php`

Empty wishlist shows blank area rather than a placeholder message like "Your wishlist is empty."

---

## 2. Performance Problems

### Finding 4 — Selection state stored as array of bools, recalculated on access
**Severity:** LOW  
**Location:** `src/Wishlist.php`

`selectedItems()` recomputes the selection array on every call. Could cache.

---

### Finding 5 — No performance concerns beyond above
**Severity:** N/A  

---

## 3. Memory Leaks

### Finding 6 — No memory leaks detected
**Severity:** N/A  

---

## 4. Security

### Finding 7 — No security concerns
**Severity:** N/A  

---

## 5. Complexity

### Finding 8 — Complexity is appropriate
**Severity:** N/A  

---

## 6. Missing Features

### Finding 9 — No item removal from wishlist
**Severity:** MEDIUM  
**Location:** `src/Wishlist.php`

Items can be added and selected but not removed (except through the UI). No API to remove an item directly.

---

### Finding 10 — No quantity/support level per item
**Severity:** LOW  
**Location:** `src/Wishlist.php`

Wishlist items only have selected/unselected state. No quantity, priority, or notes per item.

---

### Finding 11 — No `examples/` directory
**Severity:** LOW  

---

## 7. PHP 8.3/8.4 Compatibility

### Finding 12 — Fully compatible with PHP 8.3+
**Severity:** N/A  

---

## 8. Async/ReactPHP Improvements

### Finding 13 — No async improvements needed
**Severity:** N/A  

---

## Summary

| Severity | Count | Key Issues |
|----------|-------|------------|
| MEDIUM | 2 | Index bounds not validated, no item removal API |
| LOW | 4 | Duplicates allowed, empty state, no quantity, no examples |

**Total: 13 findings**
