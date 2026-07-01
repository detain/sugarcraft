# Audit: candy-mold

**Library:** `sugarcraft/candy-mold` — Skeleton repo for bootstrapping a SugarCraft TUI app  
**Audit date:** 2026-06-30  
**PHP version:** 8.3+  

---

## Overview

`candy-mold` is a **skeleton/bootstrap template** — not a library in the traditional sense. Its sole purpose is to be cloned via `composer create-project sugarcraft/candy-mold` to bootstrap a new SugarCraft TUI app. The `Counter` class is a reference Model implementation demonstrating the Elm-architecture pattern (Model/View/Update), not production code that ships downstream. Many findings below are only actionable if this skeleton were to become a real app; they are marked **INFORMATIONAL**.

---

## 1. Issues (Bugs, Edge Cases, Error Handling)

### Finding 1 — Quit does not fire for uppercase `Q` (Shift+q)
**Severity:** LOW  
**Location:** `src/Counter.php:51`  
**Code:**
```php
if (($msg->type === KeyType::Char && $msg->rune === 'q' && !$msg->ctrl)
```
**Issue:** The rune literal `'q'` only matches lowercase. If a user has caps-lock on or holds Shift, the key is `'Q'` and the condition is `false` — the quit never fires.
**Recommendation:** Normalize the rune to lowercase before comparing:
```php
if ($msg->type === KeyType::Char && strtolower($msg->rune) === 'q' && !$msg->ctrl)
```

---

### Finding 2 — Ctrl+C quit is rune-sensitive (only matches lowercase `'c'`)
**Severity:** LOW  
**Location:** `src/Counter.php:53`  
**Code:**
```php
|| ($msg->ctrl && $msg->rune === 'c')
```
**Issue:** Identical pattern to Finding 1 — `Ctrl+Shift+C` produces `rune='C'` and will not quit. The upstream Bubble Tea quit condition is rune-insensitive.
**Recommendation:** `strtolower($msg->rune) === 'c'`

---

### Finding 3 — Quit silently ignores key combos (alt, shift, alt+ctrl)
**Severity:** LOW  
**Location:** `src/Counter.php:51-55`  
**Code:**
```php
if (($msg->type === KeyType::Char && $msg->rune === 'q' && !$msg->ctrl)
    || $msg->type === KeyType::Escape
    || ($msg->ctrl && $msg->rune === 'c'))
```
**Issue:** `!$msg->alt` and `!$msg->ctrl` are explicit for the `'q'` case, but `alt+q` or `ctrl+shift+c` do not quit — with no user feedback. The comment at line 49 claims "quit with q (plain), Esc, or ctrl+c" but `alt+q` and `ctrl+shift+c` are not "plain" and would confuse a user.
**Recommendation:** Update the comment to be precise, or consider whether `alt+q` / `ctrl+shift+c` should also quit.

---

### Finding 4 — `view()` rebuilds the `Style` object on every render
**Severity:** MEDIUM  
**Location:** `src/Counter.php:68-71`  
**Code:**
```php
return Style::new()
    ->border(Border::rounded())
    ->padding(1, 2)
    ->render($body);
```
**Issue:** `Style::new()->border(...)->padding(...)->render(...)` creates three short-lived intermediate `Style` objects per render frame. For a skeleton demo this is negligible, but it is an anti-pattern for a real app running at 60fps render tick.
**Recommendation:** Cache the configured `Style` as a `private static` property:
```php
private static ?Style $cachedStyle = null;

public function view(): string
{
    self::$cachedStyle ??= Style::new()->border(Border::rounded())->padding(1, 2);
    return self::$cachedStyle->render(sprintf("  count: %d  \n  ↑ ↓ to change · q to quit  ", $this->n));
}
```

---

### Finding 5 — `bin/start` error message says `candy-template` instead of `candy-mold`
**Severity:** LOW  
**Location:** `bin/start:25`  
**Code:**
```php
fwrite(STDERR, "candy-template: cannot find composer autoload.php — did you `composer install`?\n");
```
**Issue:** The package name is `sugarcraft/candy-mold`. This is a copy-paste artifact from a different skeleton.
**Recommendation:** Change `"candy-template"` to `"candy-mold"`.

---

### Finding 6 — `bin/start` uses IIFE for autoload discovery
**Severity:** INFORMATIONAL  
**Location:** `bin/start:17-27`  
**Issue:** The double-loop over autoload candidates is defensive but could be simplified.
**Recommendation:** Use `is_file()` directly with a fallback.

---

## 2. Performance Problems

### Finding 7 — No N+1, no DB, no loops — Performance is clean
**Severity:** N/A  
**Note:** As a template, `candy-mold` has no performance concerns. The only potential issue is Finding 4 (Style recreation on every frame).

---

## 3. Memory Leaks

### Finding 8 — No memory leaks detected
**Severity:** N/A  
**Note:** `Style` chain objects are short-lived and properly GC'd. No streams, no resources, no callbacks with reference cycles.

---

## 4. Security

### Finding 9 — No user input reaches unsanitized paths
**Severity:** N/A  
**Note:** `Counter` only receives structured `KeyMsg` objects from the SugarCraft event loop. No attack surface.

---

### Finding 10 — No `CALIBER_LEARNINGS.md` exists
**Severity:** LOW  
**Location:** `candy-mold/`  
**Issue:** The skeleton lacks a `CALIBER_LEARNINGS.md` file.
**Recommendation:** Add `CALIBER_LEARNINGS.md` documenting key patterns.

---

## 5. Complexity

### Finding 11 — Complexity is appropriate for a skeleton template
**Severity:** N/A  
**Note:** The `Counter` class is exactly as complex as it needs to be.

---

### Finding 12 — Tests are comprehensive and follow the canonical SugarCraft pattern
**Severity:** N/A  
**Note:** `CounterTest.php` covers all four quit paths, up/down mutations, immutability, subscriptions, view rendering.

---

## 6. Missing Features / Incomplete Ports

### Finding 13 — `subscriptions()` returns `null` — no subscription demo
**Severity:** INFORMATIONAL  
**Location:** `src/Counter.php:74-77`  
**Recommendation:** Add a commented subscription demo showing an auto-increment tick.

---

### Finding 14 — `init()` returns `null` — no startup command demo
**Severity:** INFORMATIONAL  
**Location:** `src/Counter.php:39-42`  
**Recommendation:** Add a commented example in the docblock showing how to return a startup `Cmd`.

---

### Finding 15 — No `examples/` directory
**Severity:** INFORMATIONAL  
**Location:** `candy-mold/`  
**Recommendation:** Add `examples/counter-with-step.php` and `examples/async-counter.php`.

---

### Finding 16 — No `View` object demo — `view()` returns only `string`
**Severity:** INFORMATIONAL  
**Location:** `src/Counter.php:65`  
**Recommendation:** Add a second `CounterWithView` example.

---

## 7. PHP 8.3/8.4 Compatibility

### Finding 17 — Fully compatible with PHP 8.3+
**Severity:** N/A  
**Note:** No PHP 8.4-specific features are used but none are needed at this stage.

---

## 8. Async/ReactPHP Improvements

### Finding 18 — Async not demonstrated
**Severity:** INFORMATIONAL  
**Location:** `bin/start:41`, `src/Counter.php`  
**Recommendation:** Add `examples/async-counter.php` demonstrating `Cmd::promise()`.

---

## Summary

| # | Severity | Category | Location | Description |
|---|----------|----------|----------|-------------|
| 1 | LOW | Bug | `src/Counter.php:51` | Quit doesn't fire for uppercase `Q` |
| 2 | LOW | Bug | `src/Counter.php:53` | Ctrl+C quit is rune-sensitive |
| 3 | LOW | UX | `src/Counter.php:51-55` | Quit ignores alt/ctrl-shift combos silently |
| 4 | MEDIUM | Perf | `src/Counter.php:68-71` | `Style` chain rebuilt on every render frame |
| 5 | LOW | Bug | `bin/start:25` | Error message says `candy-template` not `candy-mold` |
| 10 | LOW | Missing | `candy-mold/` | No `CALIBER_LEARNINGS.md` |
| 13-16 | INFO | Missing | various | No subscriptions/init/examples/View demos |
| 17-18 | INFO | Compat/Async | various | PHP 8.3+ compatible, no async demo |

**Total findings:** 18 (3 low, 1 medium, 4 informational, 10 N/A)
