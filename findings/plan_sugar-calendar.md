# Implementation Plan: sugar-calendar Audit Fixes

**Status:** not-started  
**Phase:** 1  
**Updated:** 2026-06-30

---

## Goal

Address all valid findings from the sugar-calendar audit: fix the hardcoded viewport width, add month-grid caching for performance, add boundary navigation tests, and implement ISO week numbers. Dismiss stale findings that reference non-existent files.

---

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Findings reference `View.php` and `Day.php` which do not exist | Codebase evolved; main rendering is in `DatePicker::View()` | Investigation ref:sugar-calendar-001 |
| `placeStringAt()` already has width bounds checking at line 544 | Buffer bounds are respected; width is hardcoded at 21 chars | `DatePicker.php:536-558` |
| Today highlight IS implemented at line 687 in `buildCells()` | Uses `$todayStyle` (bold green) for current day cells | `DatePicker.php:687` |
| Examples directory EXISTS with `basic.php` and `constraints.php` | Finding 11 is stale | Investigation ref:sugar-calendar-004 |
| `buildCells()` called on every `View()` - valid performance concern | 42 cells recomputed each frame at 60fps | `DatePicker.php:448` |

---

## Phase 1: Investigation & Validation [PENDING]

- [ ] 1.1 Validate Finding 1: Investigate viewport width handling in `DatePicker::View()` → `ref:sugar-calendar-001`
- [ ] 1.2 Validate Finding 2: Analyze boundary navigation in `clampedCursor()` and cursor methods → `ref:sugar-calendar-006`
- [ ] 1.3 Validate Finding 3: `Day.php` does not exist - mark stale → `ref:sugar-calendar-001`
- [ ] 1.4 Validate Finding 4: Confirm `buildCells()` called every `View()` → `ref:sugar-calendar-005`
- [ ] 1.5 Validate Finding 9: Confirm today highlight implemented → `ref:sugar-calendar-003`
- [ ] 1.6 Validate Finding 10: Confirm week numbers not implemented → `ref:sugar-calendar-007`
- [ ] 1.7 Validate Finding 11: Confirm examples directory exists → `ref:sugar-calendar-004`

---

## Phase 2: High Severity Fixes [PENDING]

### Task 2.1 — Make viewport width configurable

**Severity:** HIGH  
**Location:** `src/DatePicker.php:425-464`  
**What is expected:** The `DatePicker::View()` method hardcodes `$width = 21` at line 427. Allow callers to specify a custom width so the component can adapt to different terminal sizes.

**Why the change should be done:** A TUI component should be responsive to terminal size. Hardcoding 21 chars prevents use in narrower contexts or with different day-name lengths (e.g., full "Monday" vs "Mo").

**Conditions for success:**
- `DatePicker` accepts optional width parameter in constructor or `View()`
- Day cells properly truncate/pad when width differs from default
- Existing tests pass with default width
- New tests verify rendering at narrower widths

**Related code locations:**
- `src/DatePicker.php:427` - `$width = 21` hardcoded
- `src/DatePicker.php:536-558` - `placeStringAt()` with bounds checking
- `src/DatePicker.php:657-716` - `buildCells()` generates 42 cells

**Investigation notes:** The `placeStringAt()` at line 544 already checks `if ($colCursor >= $buf->width()) { break; }`. The rendering uses 3-char cells (2 for day number + 1 space). With configurable width, the cell count per row would be `$width / 3` (rounded down). Default 21 gives 7 cells (full week). A narrower width would show fewer columns.

---

### Task 2.2 — Add month grid caching

**Severity:** MEDIUM  
**Location:** `src/DatePicker.php`  
**What is expected:** Cache the computed 42-cell month grid and only recompute when state changes (view month, view year, cursor index, selection, today reference).

**Why the change should be done:** At 60fps (cursor blinking), calling `buildCells()` 60 times per second for a static calendar is wasteful. The grid only changes when navigation or selection occurs.

**Conditions for success:**
- `View()` returns cached string when no state change occurred
- Cache is invalidated on: `GoToPreviousMonth()`, `GoToNextMonth()`, `GoToPreviousYear()`, `GoToNextYear()`, `SetTime()`, `SelectDate()`, `ClearDate()`, `MoveCursor*()`, `handleKey()`, `withToday()`
- Benchmark shows < 1ms for cached `View()` vs current ~0.1ms (still acceptable but improvement for 60fps scenarios)

**Related code locations:**
- `src/DatePicker.php:448` - `$cells = $this->buildCells();` called every View()
- `src/DatePicker.php:657-716` - `buildCells()` full grid computation

**Investigation notes:** The `buildCells()` method computes day numbers, checks today/selected/range status, and applies styles. It iterates 42 times doing date arithmetic and style lookups. A simple `?string $cachedView` property invalidated on state mutation would solve this. Note: Buffer::toAnsi() is the expensive operation, not buildCells() itself - but both can be skipped when nothing changed.

---

## Phase 3: Medium Severity [PENDING]

### Task 3.1 — Clarify week-boundary cursor behavior

**Severity:** MEDIUM  
**Location:** `src/DatePicker.php:195-221`, `src/Navigation.php:18-28`  
**What is expected:** Document the current boundary behavior when navigating left from the first day of a month, or implement month-boundary wrap if decided to match upstream.

**Why the change should be done:** Finding 2 reports "navigating left from the first day of a month wraps to the previous month's last day." Currently `MoveCursorLeft()` clamps at 0. This is an architectural difference from upstream (grid-index based vs date-based cursor). A decision is needed on whether to change this.

**Conditions for success:**
- Document the current behavior: Left from first day (index 0) stays at index 0 (not month-wrap)
- OR implement month-boundary wrap if decided to match upstream
- Add test coverage for all 7 days of the first week and last week of each month (as recommended by Finding 2)

**Related code locations:**
- `src/DatePicker.php:195-200` - `MoveCursorLeft()` uses `\max(0, ...)` clamp
- `src/DatePicker.php:767-777` - `clampedCursor()` handles index clamping
- `src/Navigation.php:18-28` - `Navigation::move()` also clamps

**Investigation notes:** The upstream bubbles/calendar uses date-based cursor (cursor is a DateTimeImmutable), not grid-index based. When cursor is on the 1st and user presses left, it wraps to previous month's last day. Our implementation uses grid indices 0-41. When index 0 contains an "empty" cell (day < 1), pressing left stays at 0. This is a fundamental architectural difference.

---

## Phase 4: Low Severity Enhancements [PENDING]

### Task 4.1 — Add ISO week number column

**Severity:** LOW  
**Location:** `src/DatePicker.php`  
**What is expected:** Upstream bubbles/calendar shows ISO week numbers on the left. Implement as an optional feature.

**Why the change should be done:** Feature parity with upstream. ISO week numbers are useful in calendar applications.

**Conditions for success:**
- New optional parameter `bool $showWeekNumbers = false` on `View()`
- When enabled, renders a week number column (1-53) using `idate('W')`
- Uses new `$weekStyle` for week number cells
- Existing tests pass with default `$showWeekNumbers = false`

**Related code locations:**
- `src/DatePicker.php:422-464` - `View()` method
- `src/DatePicker.php:447-462` - week row rendering loop

**Investigation notes:** PHP's `idate('W')` returns ISO week number. Week numbers apply to entire week rows, not individual days. Would need to compute which week each row represents and display once per row.

---

### Task 4.2 — Dismiss stale findings

**What is expected:** The following findings reference non-existent files and should be marked resolved/stale:

**Finding 3 (LOW) — `Day.php:55` whitespace trimming:** `src/Day.php` does not exist in the current codebase. Day cell rendering is in `DatePicker::buildCells()` at lines 677-713. Whitespace handling uses `\sprintf('%2d', $dayNum)` which produces consistent 2-char padded output (no trim inconsistency).

**Finding 9 (LOW) — No today highlight:** Today highlighting IS implemented at `DatePicker::buildCells()` line 687:
```php
$isToday   = $dayNum === $todayDay && $this->viewMonth === $todayMonth && $this->viewYear === $todayYear;
```
And line 702-703:
```php
} elseif ($isToday) {
    $style = $this->sgrToBufferStyle($this->todayStyle);
```

**Finding 11 (LOW) — No examples/ directory:** The `examples/` directory EXISTS with `basic.php` and `constraints.php`.

---

## Phase 5: Testing [PENDING]

### Task 5.1 — Add boundary navigation tests

**Severity:** MEDIUM  
**Location:** `tests/DatePickerTest.php`  
**What is expected:** Per Finding 2 recommendation, add comprehensive test coverage for cursor navigation at month boundaries: all 7 days of the first week and last week of each month.

**Why the change should be done:** Ensures cursor navigation works correctly at month boundaries. This is especially important given the architectural difference (grid-index based) from upstream (date-based).

**Conditions for success:**
- Test cursor position on each of the 7 days in week 1 of the month
- Test cursor position on each of the 7 days in the last week of the month
- Test left navigation from day 1 of month (when day 1 is at various weekday positions: Sun=0 through Sat=6)
- Test right navigation from last day of month
- Tests cover multiple months with different first-day-of-week offsets

**Related code locations:**
- `tests/DatePickerTest.php:96-116` - existing boundary tests
- `src/DatePicker.php:195-221` - cursor movement methods

---

## Summary Table

| # | Finding | Severity | Action Required | Status |
|---|---------|----------|-----------------|--------|
| 1 | Viewport width hardcoded | HIGH | Make width configurable | PENDING |
| 2 | Week boundary navigation | MEDIUM | Clarify/fix + add tests | PENDING |
| 3 | Day.php whitespace (stale) | LOW | Dismiss - file doesn't exist | STALE |
| 4 | Month re-rendered every view() | MEDIUM | Add caching | PENDING |
| 5 | N+1 issues | N/A | None | N/A |
| 6 | Memory leaks | N/A | None | N/A |
| 7 | Security concerns | N/A | None | N/A |
| 8 | Complexity appropriate | N/A | None | N/A |
| 9 | No today highlight (stale) | LOW | Dismiss - already implemented | STALE |
| 10 | No ISO week numbers | LOW | Implement | PENDING |
| 11 | No examples/ (stale) | LOW | Dismiss - directory exists | STALE |
| 12 | PHP 8.3+ compatible | N/A | None | N/A |
| 13 | No async improvements needed | N/A | None | N/A |

**Total actionable findings:** 4 (Tasks 2.1, 2.2, 3.1, 4.1, 5.1)  
**Stale findings to dismiss:** 3 (Findings 3, 9, 11)

---

## Notes

- **2026-06-30:** Initial plan created. The findings file references `View.php` and `Day.php` which do not exist in the current codebase. The actual structure uses `DatePicker.php` as the main component with rendering at lines 425-464, cell building at 657-716. `ref:sugar-calendar-001`

- **2026-06-30:** `placeStringAt()` at line 544 already has `if ($colCursor >= $buf->width()) { break; }` - width IS bounded within the fixed buffer. The issue is the fixed 21-char buffer width not being configurable by callers. `ref:sugar-calendar-002`

- **2026-06-30:** Today highlight IS implemented: `$isToday` check at line 687, `$todayStyle` applied at line 702-703. Finding 9 is stale. `ref:sugar-calendar-003`

- **2026-06-30:** Examples exist at `examples/basic.php` and `examples/constraints.php`. Finding 11 is stale. `ref:sugar-calendar-004`

- **2026-06-30:** `buildCells()` at line 448 is called every time `View()` is invoked - valid Finding 4 for 60fps TUIs. `ref:sugar-calendar-005`

- **2026-06-30:** Cursor navigation is grid-index based (0-41), not date-based like upstream. Left from index 0 stays at 0 (doesn't wrap to prev month). This is an architectural difference that requires a decision to either change or document. `ref:sugar-calendar-006`

- **2026-06-30:** ISO week numbers are not implemented - valid Finding 10 for feature parity. `ref:sugar-calendar-007`
