# Audit: sugar-calendar

**Library:** SugarCraft/sugar-calendar  
**Date:** 2026-06-30  

---

## Overview

`sugar-calendar` ports the Charmbracelet `bubbles/calendar` component — a date picker widget for TUI applications. It renders a monthly grid with day names, navigation, and selection handling.

---

## 1. Issues (Bugs, Edge Cases, Error Handling)

### Finding 1 — `View::render()` cells not capped to viewport width
**Severity:** HIGH  
**Location:** `src/View.php:97-135`

The `render()` method builds rows of day cells but doesn't clamp cell output to the viewport width. Days rendered beyond `$width` would overflow.

**Recommendation:** Add width check in the day-cell loop.

---

### Finding 2 — Keyboard navigation off by one in week boundary
**Severity:** MEDIUM  
**Location:** `src/Model.php` (update method)

Navigating left from the first day of a month (e.g., July 1, 2026) wraps to the previous month's last day. However, the boundary check may not correctly account for weeks starting on Sunday vs Monday.

**Recommendation:** Add test coverage for all 7 days of the first week and last week of each month.

---

### Finding 3 — `Day::render()` whitespace trimming inconsistent
**Severity:** LOW  
**Location:** `src/Day.php:55`

`trim()` is applied to day numbers but not to the padding spaces around them, potentially causing misalignment in the grid.

**Recommendation:** Ensure consistent formatting with `str_pad()` for all day cells.

---

## 2. Performance Problems

### Finding 4 — Month re-rendered on every `view()` call
**Severity:** MEDIUM  
**Location:** `src/View.php`

`View::render()` recomputes the entire month grid (7×6 = 42 cells) on every call. For a cursor-blinking TUI at 60fps, this is wasteful since the calendar state rarely changes between frames.

**Recommendation:** Cache the month grid in a property, invalidate on state change.

---

### Finding 5 — No N+1 issues detected
**Severity:** N/A  

---

## 3. Memory Leaks

### Finding 6 — No memory leaks detected
**Severity:** N/A  

Immutable value objects, no streams or resources.

---

## 4. Security

### Finding 7 — No security concerns
**Severity:** N/A  

No external input, no file operations, no shell execution.

---

## 5. Complexity

### Finding 8 — Complexity is appropriate for a calendar widget
**Severity:** N/A  

Clean separation: Model (state), View (rendering), Day (cell).

---

## 6. Missing Features / Incomplete Ports

### Finding 9 — No highlight for "today"
**Severity:** LOW  
**Location:** `src/Day.php`

Upstream `bubbles/calendar` highlights the current day. Not implemented.

---

### Finding 10 — No week number column (ISO week numbers)
**Severity:** LOW  
**Location:** `src/View.php`

Upstream shows ISO week numbers on the left. Not implemented.

---

### Finding 11 — No `examples/` directory
**Severity:** LOW  

---

## 7. PHP 8.3/8.4 Compatibility

### Finding 12 — Fully compatible with PHP 8.3+
**Severity:** N/A  

Uses readonly, promoted constructors, strict types.

---

## 8. Async/ReactPHP Improvements

### Finding 13 — No async improvements needed
**Severity:** N/A  

Synchronous TUI widget. Async not applicable.

---

## Summary

| Severity | Count | Key Issues |
|----------|-------|------------|
| HIGH | 1 | Cell output not capped to viewport width |
| MEDIUM | 2 | Week boundary nav, month re-render on every view() |
| LOW | 4 | Today highlight missing, week numbers missing, no examples, whitespace trimming |

**Total: 13 findings**
