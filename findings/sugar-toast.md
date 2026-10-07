> ⚠️ SUPERSEDED 2026-10-06 — findings largely landed (see PRs/commits era r85-r89 + crush_libs.md re-verify). Item-level status below may misrepresent master. Re-derive before acting.
> Findings 1, 2, 3, 8 below are fixed (viewport clamping via `resolveWidth`/`capWidth`, `cancelAlert`/`extendAlert`/`extendAll`, nullable message, `actions:` parameter); 9 and 10 verified. **Open at 2026-10-06 audit time; re-verify:** the six findings in crush_libs.md §sugar-toast — notably the `dismiss()` one-way trap #1 MAJOR (fix-era c5cce07d7 "repair dismiss lifecycle" may already cover it) — and the Phase 4.4 README MINOR (sugar-toast #4).

# Audit: sugar-toast

**Library:** SugarCraft/sugar-toast  
**Date:** 2026-06-30  

---

## Overview

`sugar-toast` ports the `charmbracelet/bubbles/toast` component — a notification toast for TUI applications that appears, shows a message, and auto-dismisses.

---

## 1. Issues (Bugs, Edge Cases, Error Handling)

### Finding 1 — Toast renders without checking viewport bounds

**Severity:** MEDIUM  
**Location:** `src/Toast.php`

If the toast is wider than the terminal width, it overflows without wrapping or truncation. No viewport bounds checking.

**Recommendation:** Clamp or wrap toast content to viewport width.

---

### Finding 2 — Auto-dismiss timer not cancellable

**Severity:** MEDIUM  
**Location:** `src/Toast.php`

Once a toast is shown, its auto-dismiss timer cannot be cancelled or extended. If the user needs more time to read, the toast disappears.

**Recommendation:** Add `extend()` or `cancelDismiss()` method.

---

### Finding 3 — Null message renders as "null" string

**Severity:** LOW  
**Location:** `src/Toast.php`

If `$message` is `null`, it renders as the string "null". Should be handled explicitly.

---

## 2. Performance Problems

### Finding 4 — No performance concerns

**Severity:** N/A  

---

## 3. Memory Leaks

### Finding 5 — No memory leaks detected

**Severity:** N/A  

---

## 4. Security

### Finding 6 — No security concerns

**Severity:** N/A  

---

## 5. Complexity

### Finding 7 — Complexity is appropriate

**Severity:** N/A  

---

## 6. Missing Features

### Finding 8 — No action buttons on toast

**Severity:** MEDIUM  
**Location:** `src/Toast.php`

Upstream `toast` supports action buttons (e.g., "Undo", "Cancel"). Not implemented.

---

### Finding 9 — No stacked/queued toasts

**Severity:** LOW  
**Location:** `src/Toast.php`

Multiple toasts overwrite each other rather than queuing/stacking.

---

### Finding 10 — No `examples/` directory

**Severity:** LOW  

---

## 7. PHP 8.3/8.4 Compatibility

### Finding 11 — Fully compatible with PHP 8.3+

**Severity:** N/A  

---

## 8. Async/ReactPHP Improvements

### Finding 12 — No async improvements needed

**Severity:** N/A  

---

## Summary

| Severity | Count | Key Issues |
|----------|-------|------------|
| MEDIUM | 3 | Viewport overflow, non-cancellable timer, no action buttons |
| LOW | 3 | Null message, no stacking, no examples |

**Total: 12 findings**
