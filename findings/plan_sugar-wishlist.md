---
status: in-progress
phase: 1
updated: 2026-06-30
---

# Implementation Plan: sugar-wishlist

## Goal
Address all valid findings from the audit of sugar-wishlist, clarifying which findings apply to the actual SSH endpoint picker codebase vs. which are based on a non-existent `Wishlist.php`.

## Context & Decisions
| Decision | Rationale | Source |
|----------|-----------|--------|
| sugar-wishlist is an SSH endpoint launcher (port of charmbracelet/wishlist), NOT a generic wishlist/item-selector | Verified via README.md, composer.json description, and source code examination | `sugar-wishlist/README.md:L16` |
| No `Wishlist.php` exists in `src/` — the library has `Endpoint.php`, `Picker.php`, `Launcher.php`, `Config.php`, `SshConfigParser.php` | Glob confirmed: `src/` contains Picker, Endpoint, Launcher, Config, SshConfigParser, Lang | `glob:sugar-wishlist/src/*.php` |
| Findings file references `selectItem()`/`toggleItem()` methods that do not exist | Codebase uses `Picker::pick()` for single selection; there is no multi-item wishlist with select/toggle | `sugar-wishlist/src/Picker.php:L49` |
| examples/ directory DOES exist with `sample-wishlist.yml` | Found sample-wishlist.yml in examples/ directory | `ls:sugar-wishlist/examples/` |
| All 109 tests pass with 302 assertions | PHPUnit run successful | `vendor/bin/phpunit` output |

## Phase 1: Audit Findings Analysis [IN PROGRESS]

### 1.1 — Analyze Finding 1 (Index bounds validation)

**Finding Claim:** `selectItem()` and `toggleItem()` in `src/Wishlist.php` accept any integer index without validation.

**Investigation Notes:**
- `src/Wishlist.php` does NOT exist in this codebase
- The actual selection mechanism is `Picker::pick(array $endpoints): ?Endpoint` at `Picker.php:L49`
- Picker uses a cursor (`$this->cursor`) that is bounds-checked at `Picker.php:L58-60`:
  ```php
  if ($this->cursor >= count($matches)) {
      $this->cursor = max(0, count($matches) - 1);
  }
  ```
- Navigation keys (j/k) also check bounds at `Picker.php:L76-84`

**Verdict:** INVALID — Finding references non-existent file and methods. The actual Picker correctly bounds its cursor.

**Source:** `sugar-wishlist/src/Picker.php:L58-60,L76-84`

---

### 1.2 — Analyze Finding 2 (Duplicate item prevention)

**Finding Claim:** Adding the same item twice results in duplicate entries.

**Investigation Notes:**
- This is an SSH endpoint launcher, not a generic collection
- `Config::load()` and `Config::parse()` at `Config.php:L42-65` do not enforce uniqueness
- Endpoints are identified by name+host combination
- Duplicates in config files would create duplicate Endpoint objects
- No unique constraint is enforced at load time

**Verdict:** N/A (not applicable to this library's design) — SSH configs can legitimately have multiple entries for the same host with different options. However, if deduplication is desired, it would be a feature request.

**Source:** `sugar-wishlist/src/Config.php:L42-65`

---

### 1.3 — Analyze Finding 3 (Empty wishlist state)

**Finding Claim:** Empty wishlist shows blank area rather than a placeholder message.

**Investigation Notes:**
- Picker already handles empty state at `Picker.php:L175-177`:
  ```php
  if ($matches === []) {
      fwrite($this->out, "  (no matches)\r\n");
  }
  ```
- When no endpoints match the filter, "(no matches)" is displayed

**Verdict:** INVALID — Empty state is already rendered with "(no matches)" message.

**Source:** `sugar-wishlist/src/Picker.php:L175-177`

---

### 1.4 — Analyze Finding 4 (Selection state recalculated on access)

**Finding Claim:** `selectedItems()` recomputes selection array on every call.

**Investigation Notes:**
- No `selectedItems()` method exists in this codebase
- Picker uses single selection via cursor, not a selection array of bools
- `Picker::pick()` returns a single `?Endpoint`, not a list of selected items

**Verdict:** INVALID — Finding describes a pattern (multi-select with bool array) that doesn't apply to this single-select TUI picker.

---

### 1.5 — Analyze Findings 5-8 (Performance, Memory, Security, Complexity)

**Finding 5:** No performance concerns beyond above
**Finding 6:** No memory leaks detected
**Finding 7:** No security concerns
**Finding 8:** Complexity is appropriate

**Investigation Notes:**
- All four findings state N/A — no issues found
- Tests pass cleanly (109 tests, 302 assertions)
- Code is well-structured with clear separation of concerns

**Verdict:** VALID — No action needed. These findings confirm the codebase is clean.

---

### 1.6 — Analyze Finding 9 (No item removal from wishlist)

**Finding Claim:** Items can be added and selected but not removed (except through UI). No API to remove an item directly.

**Investigation Notes:**
- This is an SSH config reader/launcher, not a mutable wishlist
- Endpoints are loaded from config files (YAML/JSON/SSH config)
- The Picker provides a UI for selection, not for editing
- `Config` is a read-only loader, not a CRUD repository

**Verdict:** N/A — This finding applies to a mutable item collection which this library is not. Removing endpoints is done by editing the config file.

**Source:** `sugar-wishlist/src/Config.php:L42-65`

---

### 1.7 — Analyze Finding 10 (No quantity/support level per item)

**Finding Claim:** Wishlist items only have selected/unselected state. No quantity, priority, or notes per item.

**Investigation Notes:**
- `Endpoint` already has a `description` field at `Endpoint.php:L29`
- Endpoints are SSH connection targets, not items with quantities
- Priority/ranking is handled by fuzzy match scoring in Picker

**Verdict:** N/A — This finding assumes a generic wishlist model (quantity, priority) that doesn't apply to SSH endpoints. Endpoint already supports a description field.

**Source:** `sugar-wishlist/src/Endpoint.php:L29`

---

### 1.8 — Analyze Finding 11 (No examples/ directory)

**Finding Claim:** No `examples/` directory exists.

**Investigation Notes:**
- `examples/sample-wishlist.yml` EXISTS at `sugar-wishlist/examples/sample-wishlist.yml`
- Contains sample YAML configuration demonstrating the format

**Verdict:** INVALID — The examples directory exists with a sample wishlist file.

**Source:** `ls:sugar-wishlist/examples/`

---

### 1.9 — Analyze Findings 12-13 (PHP 8.3+ compatibility, Async)

**Finding 12:** Fully compatible with PHP 8.3+
**Finding 13:** No async improvements needed

**Investigation Notes:**
- composer.json requires `"php": ">=8.3"`
- Uses pcntl_exec for synchronous process replacement (inherently synchronous)
- Async would not make sense for a process-replacing SSH launcher

**Verdict:** VALID — No action needed.

**Source:** `sugar-wishlist/composer.json:L33`

---

## Phase 2: Recommended Improvements [PENDING]

Based on the investigation, the following improvements could be made to the library (not from the findings, but from actual code review):

### 2.1 — Endpoint deduplication option (LOW)
Add optional deduplication in `Config::parse()` to warn or remove endpoints with duplicate name+host combinations.

**Severity:** LOW  
**Location:** `src/Config.php:L64`

---

### 2.2 — Add IPv6 handling verification (LOW)
The Picker accepts IPv6 addresses (tested in `PickerTest.php:L237-249`), but endpoint validation for IPv6 host format is minimal.

**Severity:** LOW  
**Location:** `src/Endpoint.php`

---

## Phase 3: Findings Disposition Summary [PENDING]

| Finding | Claimed Severity | Actual Status | Action Required |
|---------|-----------------|--------------|-----------------|
| 1. Index bounds validation | MEDIUM | INVALID — no Wishlist.php exists | None |
| 2. Duplicate item prevention | LOW | N/A | None (feature request if desired) |
| 3. Empty state rendering | LOW | INVALID — already handled | None |
| 4. Selection state caching | LOW | INVALID — not applicable | None |
| 5. Performance | N/A | VALID — no issues | None |
| 6. Memory leaks | N/A | VALID — no issues | None |
| 7. Security | N/A | VALID — no issues | None |
| 8. Complexity | N/A | VALID — appropriate | None |
| 9. No item removal API | MEDIUM | N/A | None |
| 10. No quantity/support level | LOW | N/A | None |
| 11. No examples/ | LOW | INVALID — exists | None |
| 12. PHP 8.3+ compatible | N/A | VALID | None |
| 13. No async needed | N/A | VALID | None |

## Notes
- 2026-06-30: Investigation reveals the findings file was likely generated for a different/misunderstood codebase. The `sugar-wishlist` library is a well-structured SSH endpoint picker with 109 passing tests and no significant issues found.
- The findings reference `src/Wishlist.php` which does not exist. The closest equivalent is `src/Picker.php` which correctly handles all the concerns mentioned (bounds checking, empty state, etc.)
