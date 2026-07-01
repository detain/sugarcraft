# Implementation Plan: sugar-stickers Audit Fixes

**Library:** sugar-stickers (PHP port of 76creates/stickers)
**Plan Created:** 2026-06-30
**Status:** not-started

---

## Goal

Fix all HIGH and MEDIUM severity findings in sugar-stickers library (multibyte corruption, non-existent import, cursor reset documentation), plus address LOW severity items (scroll defaults, performance documentation).

---

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Fix FlexBox::sanitize() multibyte bug first | Data corruption risk - CJK text would be permanently destroyed | `findings/sugar-stickers.md:27-32` |
| Column::sanitize() appears already fixed | Comment in code explicitly explains why 0x80-0x9F should not be removed | `src/Table/Column.php:122-126` |
| scrollLeft/right defaults should change to 1 | Consistency with other nav methods (lineUp, lineDown default to 1) | `findings/sugar-stickers.md:54-59` |
| cursorRow reset should be documented | Users may find unexpected cursor reset on sort/filter | `findings/sugar-stickers.md:44-50` |

---

## Phase 1: Critical Fixes [PENDING]

### 1.1 Fix FlexBox::sanitize() Multibyte Corruption (HIGH) ← CURRENT

**Location:** `src/Flex/FlexBox.php:301-302`

**What is expected:** Remove `\x80-\x9F` from the regex pattern that strips C1 controls. These bytes are valid UTF-8 continuation bytes and stripping them corrupts CJK characters like `東京` (bytes `e6 9d b1 e4 ba ac` where `9d` and `ba` fall in the forbidden range).

**Why the change should be done:** Data corruption - once a character is stripped, there is no way to recover it. This affects any user displaying non-ASCII text (CJK, accented characters, emoji).

**Severity:** HIGH

**Conditions for success:**
- Unit test passes verifying `東京` survives `sanitize()`
- `vendor/bin/phpunit` passes for entire sugar-stickers test suite

**Related code locations:**
- `src/Flex/FlexBox.php:301-302` (bug location)
- `src/Table/Column.php:122-126` (reference fix already in Column)

**Investigation notes:**
- Confirmed bug exists at line 302: `\x7F\x80-\x9F` pattern
- The Column.php sanitize method at lines 122-126 already has the correct fix with detailed comment explaining why
- sugar-bits Table.php sanitizeCell at line 580-584 uses simpler approach (only removes C0 controls)

**Implementation:**
```php
// BEFORE (line 301-302):
// Remove C1 controls (0x7F, 0x80-0x9F).
$s = \preg_replace('/[\x7F\x80-\x9F]/', '', $s);

// AFTER:
// Remove DEL (0x7F).  Do NOT remove 0x80-0x9F — those are valid
// UTF-8 continuation bytes (e.g. CJK `東京` = e6[9d]b1 e4[ba]ac
// where bytes in brackets fall in that range).  Stripping them
// corrupts any multi-byte character whose encoding includes them.
$s = \preg_replace('/\x7F/', '', $s);
```

---

### 1.2 Verify Column::sanitize() Fix (HIGH)

**Location:** `src/Table/Column.php:112-128`

**What is expected:** Verify that Column::sanitize() correctly handles multibyte characters and does NOT strip 0x80-0x9F.

**Why the change should be done:** The findings file reports this as HIGH severity, but investigation shows the fix may already be in place (comment at lines 122-126 explicitly explains not stripping 0x80-0x9F).

**Severity:** HIGH

**Conditions for success:**
- Code inspection confirms only `\x7F` is stripped, not `\x80-\x9F`
- Unit test with CJK input passes

**Related code locations:**
- `src/Table/Column.php:112-128` (sanitize method)
- `src/Table/Column.php:122-126` (fix already in place)

**Investigation notes:**
- Lines 122-126 in current code show the correct fix
- Comment explicitly documents the UTF-8 continuation byte rationale
- The comment explains: "Do NOT remove 0x80-0x9F — those are valid UTF-8 continuation bytes (e.g. CJK `東京` = e6[9d]b1 e4[ba]ac where bytes in brackets fall in that range)"
- If findings file was created before this fix, the finding may be outdated

---

## Phase 2: Medium Priority Fixes [PENDING]

### 2.1 Fix Non-Existent Justify Import in Example (MEDIUM)

**Location:** `examples/flexbox.php:13`

**What is expected:** Remove the unused `Justify` import from the use statement. The `Justify` enum does not exist in the SugarCraft\Stickers\Flex namespace - only `Align` and `Direction` enums exist.

**Why the change should be done:** Would cause fatal error "Class 'SugarCraft\Stickers\Flex\Justify' not found" at runtime if example is executed.

**Severity:** MEDIUM

**Conditions for success:**
- `php -l examples/flexbox.php` passes with no errors
- Example file can be executed without fatal error

**Related code locations:**
- `examples/flexbox.php:13` (erroneous import)
- `src/Flex/FlexBox.php:10-21` (actual enums: Direction, Align)

**Investigation notes:**
- `FlexBox.php` defines only `Direction` and `Align` enums (lines 10-21)
- The example doesn't actually use `Justify` anywhere in the code - it's imported but never referenced
- Finding #8 is duplicate of this issue

**Implementation:**
```php
// BEFORE (line 13):
use SugarCraft\Stickers\Flex\{Align, Direction, FlexBox, FlexItem, Justify};

// AFTER:
use SugarCraft\Stickers\Flex\{Align, Direction, FlexBox, FlexItem};
```

---

### 2.2 Document Cursor Reset Behavior (MEDIUM)

**Location:** `src/Table/Table.php:274`

**What is expected:** Document the behavior where `rebuildView()` unconditionally resets `cursorRow` to 0 whenever sort or filter changes.

**Why the change should be done:** Users may find this unexpected - if they're viewing row 5 and apply a filter that reduces the dataset, the cursor jumps back to row 0 without warning.

**Severity:** MEDIUM

**Conditions for success:**
- PHPDoc comment added to `rebuildView()` explaining cursor reset behavior
- PHPDoc comment added to `sortBy()`, `filter()`, `sortByNext()` methods noting the cursor reset side effect

**Related code locations:**
- `src/Table/Table.php:248-275` (rebuildView method)
- `src/Table/Table.php:274` (cursor reset line)
- `src/Table/Table.php:77-84` (sortBy)
- `src/Table/Table.php:96-102` (filter)

**Investigation notes:**
- `rebuildView()` called from `sortBy()` (line 82), `sortByNext()` (line 89), `filter()` (line 100), and `addRow()` (line 73)
- Cursor reset is on line 274: `$this->cursorRow = 0;`
- This behavior is intentional (filter on empty set should show from beginning) but should be documented

**Implementation:**
Add to `rebuildView()` docblock:
```php
/**
 * Rebuild the visible $rows view from $allRows by applying the current
 * sort and filter state. Called whenever rows, sort, or filter change so
 * that filter('') (clearFilter) restores the full set instead of operating
 * on already-shrunk data.
 *
 * NOTE: This method unconditionally resets cursorRow to 0. If you need
 * to preserve cursor position across rebuilds, you must track and restore
 * it manually after calling sortBy() or filter().
 */
private function rebuildView(): void
```

---

## Phase 3: Low Priority Improvements [PENDING]

### 3.1 Change scrollLeft/scrollRight Default 0→1 (LOW)

**Location:** `src/Viewport.php:352-359`

**What is expected:** Change default parameter value from 0 to 1 for consistency with other navigation methods.

**Why the change should be done:** With default 0, `scrollLeft()` with no arguments is a no-op. Other nav methods (`lineUp`, `lineDown`) default to 1, providing expected behavior.

**Severity:** LOW

**Conditions for success:**
- `vendor/bin/phpunit` tests pass
- Behavior change documented in method docblocks

**Related code locations:**
- `src/Viewport.php:352-355` (scrollLeft)
- `src/Viewport.php:357-360` (scrollRight)
- `src/Viewport.php:336-344` (lineUp/lineDown defaults for reference)

**Investigation notes:**
- `lineUp(int $n = 1)` and `lineDown(int $n = 1)` at lines 336 and 341 use default 1
- `halfPageUp()`, `halfPageDown()`, `pageUp()`, `pageDown()` don't take parameters (delegates to inner)

**Implementation:**
```php
// BEFORE (lines 352-355):
public function scrollLeft(int $n = 0): self
{
    return new self($this->inner->scrollLeft($n), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
}

// AFTER:
public function scrollLeft(int $n = 1): self
{
    return new self($this->inner->scrollLeft($n), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
}
```

And same change for `scrollRight()` at line 357.

---

### 3.2 Performance: Repeated Array Allocation in FlexBox (LOW)

**Location:** `src/Flex/FlexBox.php:114-120, 195-201`

**What is expected:** Document the `$measured` array allocation that creates closures on every render as a known GC pressure point.

**Why the change should be done:** Creates GC pressure with repeated allocation of arrays with closures.

**Severity:** LOW (performance optimization, not a bug)

**Conditions for success:**
- Documentation added to `CALIBER_LEARNINGS.md` noting this as a known pattern

**Related code locations:**
- `src/Flex/FlexBox.php:114-120` (renderRow measured array)
- `src/Flex/FlexBox.php:195-201` (renderColumn measured array)

**Investigation notes:**
- Lines 114-120 in `renderRow()` and lines 195-201 in `renderColumn()` create `$measured` arrays with closures
- Could potentially be cached if items haven't changed, but this would add complexity for marginal gain
- Recommendation: Document as a known performance consideration rather than fixing immediately

---

### 3.3 Performance: Large Per-Line Array in TableRenderer (LOW)

**Location:** `src/Table/TableRenderer.php:119-150`

**What is expected:** Document the `$strippedPosToStyle` associative array created per line as a known memory usage pattern.

**Why the change should be done:** Creates ~5000 entries for a 100×50 buffer. Could cause memory pressure in large renders.

**Severity:** LOW (performance optimization, not a bug)

**Conditions for success:**
- Documentation added to `CALIBER_LEARNINGS.md` noting this as a known pattern

**Related code locations:**
- `src/Table/TableRenderer.php:119-150` ($strippedPosToStyle array)

**Investigation notes:**
- The `$strippedPosToStyle` array at lines 119-150 maps stripped character positions to active SGR styles
- This is necessary for correct ANSI style tracking through the diff algorithm
- Recommendation: Document as a known memory usage pattern rather than fixing

---

## Phase 4: Testing [PENDING]

### 4.1 Add Multibyte Character Tests

**What is expected:** Add unit tests to verify CJK and other multibyte characters survive sanitization.

**Why the change should be done:** Prevent regression of the multibyte corruption bug.

**Conditions for success:**
- All new tests pass
- All existing tests continue to pass

**Test Cases to add:**
1. `testFlexBoxSanitizePreservesCJK()` - verifies `東京` survives sanitize
2. `testColumnSanitizePreservesCJK()` - verifies `東京` survives sanitize
3. `testFlexBoxSanitizePreservesEmoji()` - verifies emoji survive sanitize
4. `testColumnSanitizePreservesAccentedChars()` - verifies `café` survives sanitize

**Related code locations:**
- `tests/StickersTest.php` (existing tests)

---

### 4.2 Add scrollLeft/scrollRight Default Tests

**What is expected:** Add unit tests verifying new default behavior (no-arg calls scroll by 1, not 0).

**Why the change should be done:** Prevent regression of the default parameter change.

**Conditions for success:**
- All new tests pass
- All existing tests continue to pass

**Test Cases to add:**
1. `testViewportScrollLeftDefaultsTo1()` - verifies no-arg call scrolls by 1
2. `testViewportScrollRightDefaultsTo1()` - verifies no-arg call scrolls by 1

---

### 4.3 Verify All Existing Tests Pass

**Command:** `cd sugar-stickers && vendor/bin/phpunit`

**Conditions for success:**
- All tests pass with exit code 0

---

## Summary of Changes

| Finding | Severity | File | Lines | Action |
|---------|----------|------|-------|--------|
| #1 | HIGH | Column.php | 112-128 | Verify/confirm fix already in place |
| #2 | HIGH | FlexBox.php | 301-302 | FIX: Remove `\x80-\x9F` from regex |
| #3 | MEDIUM | examples/flexbox.php | 13 | FIX: Remove Justify import |
| #4 | MEDIUM | Table.php | 248-275 | DOCS: Add cursor reset documentation |
| #5 | LOW | Viewport.php | 352-359 | FIX: Change defaults 0→1 |
| #6 | LOW | FlexBox.php | 114-120, 195-201 | DOCUMENT: Known GC pressure |
| #7 | LOW | TableRenderer.php | 119-150 | DOCUMENT: Known memory pattern |
| #8 | LOW | examples/flexbox.php | 13 | Same as #3 |

---

## Notes

- **Memory:** No memory leaks detected per findings file
- **Security:** Sanitization approach is sound but multibyte bug (Finding 2) corrupts text rather than protecting it
- **PHP 8.3+:** Fully compatible according to findings file
- **Verification:** All fixes must pass `vendor/bin/phpunit` before PR
