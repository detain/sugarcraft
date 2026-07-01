# Audit: sugar-crush

**Library:** SugarCraft/sugar-crush  
**Date:** 2026-06-30  

---

## Overview

`sugar-crush` ports `charmbracelet/bubbletea` input handling. It processes keyboard/mouse/EOF/paste events and routes them to the appropriate handler. Uses `candy-async` for debouncing and throttling.

---

## 1. Issues (Bugs, Edge Cases, Error Handling)

### Finding 1 — EOF detection doesn't consume pending input
**Severity:** HIGH  
**Location:** `src/InputHandler.php`

When `HandleCyan` (Ctrl+D) is received, the handler immediately sets `stopPropagation()`. But any buffered input ahead of the Ctrl+D is not consumed before stopping. If there are pending keystrokes, they could be lost.

**Recommendation:** Drain the input buffer before stopping.

---

### Finding 2 — Mouse event coordinates off-by-one at viewport edges
**Severity:** HIGH  
**Location:** `src/MouseHandler.php:55`

Mouse coordinates are 0-indexed but terminal cells start at 1. The coordinate conversion `col - 1` is correct, but the boundary check at `col < 1 || col > width` may reject valid edge clicks (col=1 or col=width).

**Recommendation:** Verify boundary conditions with tests.

---

### Finding 3 — Debounce timer not reset on config change
**Severity:** MEDIUM  
**Location:** `src/Config.php`

When `throttle` or `debounce` settings change via `withThrottle()`/`withDebounce()`, the existing pending timer is not cancelled. Old timer could still fire with stale config.

**Recommendation:** Cancel existing timer before setting new one.

---

### Finding 4 — Paste handler assumes clipboard is available
**Severity:** MEDIUM  
**Location:** `src/PasteHandler.php`

`PasteHandler` calls `$clipboard->get()` synchronously. If the clipboard is empty or unavailable, this could throw. No try/catch.

**Recommendation:** Wrap in try/catch, fall back to empty string.

---

## 2. Performance Problems

### Finding 5 — Event handler chain O(n) lookup per event
**Severity:** LOW  
**Location:** `src/InputHandler.php`

Handlers stored in array, linear scan to find matching handler. For small N (typically <10 handlers), this is fine.

---

### Finding 6 — No N+1 issues detected
**Severity:** N/A  

---

## 3. Memory Leaks

### Finding 7 — Timer resource not explicitly closed
**Severity:** MEDIUM  
**Location:** `src/Config.php`

`Loop::addTimer()` returns a `Timer` object. If the handler is destroyed mid-timer, the timer is cancelled but the object reference may linger.

**Recommendation:** Store timer references and `cancel()` in `__destruct()`.

---

## 4. Security

### Finding 8 — No security concerns
**Severity:** N/A  

Terminal-only library, no external input beyond keyboard/mouse.

---

## 5. Complexity

### Finding 9 — Complexity is appropriate
**Severity:** N/A  

Clean separation of concerns.

---

## 6. Missing Features / Incomplete Ports

### Finding 10 — Missing resize event handler
**Severity:** MEDIUM  
**Location:** `src/InputHandler.php`

Upstream tea handles window resize events. Not implemented.

---

### Finding 11 — No paste start/end event support
**Severity:** LOW  
**Location:** `src/PasteHandler.php`

Only `PasteMsg` handled. No `PasteStartMsg`/`PasteEndMsg`.

---

## 7. PHP 8.3/8.4 Compatibility

### Finding 12 — Fully compatible with PHP 8.3+
**Severity:** N/A  

---

## 8. Async/ReactPHP Improvements

### Finding 13 — Async design is appropriate
**Severity:** N/A  

Uses ReactPHP Loop for timers correctly.

---

## Summary

| Severity | Count | Key Issues |
|----------|-------|------------|
| HIGH | 2 | EOF doesn't drain buffer, mouse coordinate off-by-one |
| MEDIUM | 3 | Timer not reset on config change, paste throws, resize not handled |
| LOW | 2 | Event lookup O(n), paste start/end missing |

**Total: 13 findings**
