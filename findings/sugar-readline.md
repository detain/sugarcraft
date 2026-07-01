# Audit: sugar-readline

**Library:** SugarCraft/sugar-readline  
**Date:** 2026-06-30  

---

## Overview

`sugar-readline` is a readline-style line editor for PHP, providing line editing, history, completion, and Emacs/Vi keybindings. Ports `charmbracelet/bubbletea` input handling for terminal line editing.

---

## 1. Issues (Bugs, Edge Cases, Error Handling)

### Finding 1 — History search (`Ctrl+R`) doesn't handle empty history
**Severity:** MEDIUM  
**Location:** `src/History.php`

When history is empty, pressing `Ctrl+R` enters search mode and immediately exits because `currentIndex` goes negative without bounds.

**Recommendation:** Guard against empty history in search mode.

---

### Finding 2 — Vi mode cursor positioning off by one at line end
**Severity:** MEDIUM  
**Location:** `src/ViEngine.php`

After typing to end of line and entering normal mode, cursor is positioned one character past the last character. Should be on the last character.

**Recommendation:** Test with single-char line, multi-char line, empty line.

---

### Finding 3 — Tab completion doesn't handle empty prefix
**Severity:** LOW  
**Location:** `src/Completion.php`

When tab is pressed with no input, the completer may return all completions (flood of output) rather than nothing.

**Recommendation:** Return empty array for empty prefix.

---

### Finding 4 — History save race condition
**Severity:** MEDIUM  
**Location:** `src/History.php:89`

`file_put_contents()` is called on every history append. If the process crashes, the last entry could be lost. Also, concurrent writes from multiple instances could corrupt the file.

**Recommendation:** Write to temp file and rename (atomic).

---

## 2. Performance Problems

### Finding 5 — History search is O(n)
**Severity:** LOW  
**Location:** `src/History.php`

Linear scan through history on each search keystroke. For history with 10,000+ entries, this could lag.

**Recommendation:** Consider prefix indexing or Trie for common searches.

---

### Finding 6 — No N+1 issues detected
**Severity:** N/A  

---

## 3. Memory Leaks

### Finding 7 — History array grows unboundedly
**Severity:** MEDIUM  
**Location:** `src/History.php`

`$this->entries[]` appends indefinitely. No maximum size enforcement.

**Recommendation:** Enforce `maxHistory` limit by removing oldest entries.

---

### Finding 8 — No memory leaks in edit buffer
**Severity:** N/A  

---

## 4. Security

### Finding 9 — History file permissions use 0644
**Severity:** LOW  
**Location:** `src/History.php`

Default `0664` allows group read. If the history contains sensitive commands, this could leak.

**Recommendation:** Use `0600` for history files.

---

### Finding 10 — No security concerns beyond history file perms
**Severity:** N/A  

---

## 5. Complexity

### Finding 11 — Dual keybinding engine (Emacs/Vi) adds complexity
**Severity:** LOW  
**Location:** `src/`

Two separate input state machines. Finding bugs requires understanding both.

---

## 6. Missing Features / Incomplete Ports

### Finding 12 — No incremental search (Emacs mode)
**Severity:** LOW  
**Location:** `src/EmacsEngine.php`

Standard readline incremental search (`Ctrl+S`) not implemented.

---

### Finding 13 — No Vi text objects
**Severity:** MEDIUM  
**Location:** `src/ViEngine.php`

Vi text objects (`ci"`, `da{`, etc.) not implemented.

---

### Finding 14 — No bracketed paste mode
**Severity:** LOW  
**Location:** `src/InputHandler.php`

Modern terminals use bracketed paste. Not supported.

---

## 7. PHP 8.3/8.4 Compatibility

### Finding 15 — Fully compatible with PHP 8.3+
**Severity:** N/A  

Uses readonly, promoted constructors, strict types.

---

## 8. Async/ReactPHP Improvements

### Finding 16 — Synchronous history save on every enter
**Severity:** MEDIUM  
**Location:** `src/History.php`

Blocking file I/O on every line submitted. Could cause input lag.

**Recommendation:** Defer save to background tick.

---

## Summary

| Severity | Count | Key Issues |
|----------|-------|------------|
| HIGH | 0 | |
| MEDIUM | 5 | Empty history search, Vi cursor off-by-one, history race condition, unbounded history, sync history save |
| LOW | 6 | Tab completion flood, history search O(n), file perms, dual engine complexity, no incremental search, no bracketed paste |

**Total: 16 findings**
