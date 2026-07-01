# Implementation Plan: sugar-spark.md Findings

**Library:** SugarCraft/sugar-charts (Sparkline)  
**Findings File:** `/home/sites/sugarcraft/findings/sugar-spark.md` (misnamed - describes sparkline charts, not the ANSI inspector at `sugar-spark`)  
**Date:** 2026-06-30

---

## Goal

Clarify findings file ownership and address all valid issues in `sugar-charts/src/Sparkline/Sparkline.php`.

---

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Findings file is misnamed | `findings/sugar-spark.md` describes `charmbracelet/spark` (sparkline charts) but `sugar-spark` in SugarCraft maps to `charmbracelet/sequin` (ANSI inspector) | `docs/MATCHUPS.md:L76` |
| Actual sparkline code is in sugar-charts | Sparkline class is at `sugar-charts/src/Sparkline/Sparkline.php`, not `sugar-spark` | glob findings |
| Negative values handled correctly | `glyph()` at line 221-222 uses clamping `max(0.0, min(1.0, $norm))` which handles negatives | `sugar-charts/src/Sparkline/Sparkline.php:L221-L222` |
| Empty data with auto-width is ambiguous | `Sparkline::new([])` auto-sets width=0, causing `view()` to return `''` | `sugar-charts/src/Sparkline/Sparkline.php:L55-60` |
| Single-point behavior already correct | `glyph()` returns `▄` (mid-bar) when `range <= 0` | `sugar-charts/src/Sparkline/Sparkline.php:L216-219`; `tests/Sparkline/SparklineTest.php:L37-40` |
| Examples directory already exists | `sugar-charts/examples/sparkline.php` is present | `sugar-charts/examples/sparkline.php` |

---

## Phase 1: Rename Findings File [PENDING]

- [ ] **1.1** Rename `findings/sugar-spark.md` to `findings/sugar-charts-sparkline.md` to reflect the actual library ownership

**Rationale:** The findings file describes `charmbracelet/spark` (sparkline charts) but was placed in a file named after `sugar-spark` which is actually `charmbracelet/sequin` (ANSI inspector).

---

## Phase 2: Validate Each Finding [PENDING]

### 2.1 Finding 1 — Empty data set produces invalid output

**Severity:** MEDIUM  
**Status:** NEEDS VERIFICATION + POTENTIAL FIX  
**Location:** `sugar-charts/src/Sparkline/Sparkline.php:L55-60` (auto-width logic) and `L144-149` (view early exit)

**Investigation Notes:**
- `Sparkline::new([], 3)->view()` at lines 155-157 correctly returns `'   '` (padded spaces)
- `Sparkline::new([])->view()` auto-sets width to 0 (via `count([]) = 0` at line 58), then `view()` returns `''` at line 148
- Test at line 13-15 (`testEmptyDataPaddedWithBlanks`) explicitly tests the width=3 case
- **Issue:** When empty data is passed with default width (-1), width becomes 0 and renders nothing — this may be confusing

**What is expected:** Either guard against this case (return padded spaces when width is known) or document that `Sparkline::new([])->view()` intentionally returns empty string.

**Why it matters:** Users may expect some visual placeholder rather than nothing when creating an empty sparkline.

**Conditions for success:** `Sparkline::new([])->view()` behavior is documented or changed to return a predictable result.

---

### 2.2 Finding 2 — Single data point renders as full-height bar

**Severity:** LOW  
**Status:** INVALID — Already handled correctly  
**Location:** `sugar-charts/src/Sparkline/Sparkline.php:L214-229`

**Investigation Notes:**
- `glyph()` at lines 216-219 returns `▄` (mid-bar) when `range <= 0`
- Test at lines 37-39 (`testFlatSeriesRendersMidBar`) confirms: `Sparkline::new([5, 5, 5])->view()` returns `'▄▄▄'`
- This is correct and expected behavior — a flat series renders mid-bar for visibility

**What is expected:** No change needed.

**Why it matters:** N/A — finding is incorrect based on current code.

**Conditions for success:** N/A

---

### 2.3 Finding 3 — Negative values handled inconsistently

**Severity:** MEDIUM  
**Status:** NEEDS DOC COMMENT CLARIFICATION  
**Location:** `sugar-charts/src/Sparkline/Sparkline.php:L214-229`

**Investigation Notes:**
- `glyph()` at line 221: `$norm = ($v - $min) / $range`
- At line 222: `$norm = max(0.0, min(1.0, $norm))` — this clamps correctly
- For all-negative data (e.g., `[-5, -3, -1]`): `min = -5`, `max = -1`, `range = 4`
- Glyph: `(-3 - (-5)) / 4 = 0.5` → clamped → `round(0.5 * 8) = 4` → `▄` (mid-bar)
- However, `NiceScale::ceiling()` at lines 37-39 returns `FLOOR = 100.0` when `max <= 0`
- This could cause issues if users combine explicit min/max with NiceScale for Y-axis

**What is expected:** Add doc comment to `glyph()` clarifying negative values are handled correctly, and consider whether `NiceScale` behavior is appropriate.

**Why it matters:** Without documentation, future maintainers might incorrectly assume negative values are not supported.

**Conditions for success:** Doc comment added explaining negative value normalization.

---

### 2.4 Finding 4 — No performance concerns

**Severity:** N/A  
**Status:** CONFIRMED — No issues

**No action needed.**

---

### 2.5 Finding 5 — No memory leaks detected

**Severity:** N/A  
**Status:** CONFIRMED — No issues

**No action needed.**

---

### 2.6 Finding 6 — No security concerns

**Severity:** N/A  
**Status:** CONFIRMED — No issues

**No action needed.**

---

### 2.7 Finding 7 — Complexity is minimal and appropriate

**Severity:** N/A  
**Status:** CONFIRMED

**No action needed.**

---

### 2.8 Finding 8 — No minimum bar width

**Severity:** LOW  
**Status:** NEEDS DECISION  
**Location:** `sugar-charts/src/Sparkline/Sparkline.php:L69-75`

**Investigation Notes:**
- Test at lines 18-20 (`testZeroWidthRendersEmpty`) explicitly tests width=0 returning `''`
- This suggests width=0 is intentional design for placeholder states
- However, the finding suggests 1-2 char sparklines "look broken"

**What is expected:** Either document that width=0 is valid and intentional, OR add a minimum width enforcement of 1.

**Why it matters:** A 0-width sparkline is technically valid but visually useless. Adding a minimum prevents accidental misconfiguration.

**Conditions for success:** Behavior documented or minimum width enforced.

---

### 2.9 Finding 9 — No `examples/` directory

**Severity:** LOW  
**Status:** INVALID — Already exists  
**Location:** `sugar-charts/examples/sparkline.php`

**Investigation Notes:**
- Example file exists at `sugar-charts/examples/sparkline.php` with working code
- Running `php examples/sparkline.php` produces visible output

**What is expected:** No change needed.

**Why it matters:** N/A — finding is incorrect.

**Conditions for success:** N/A

---

### 2.10 Finding 10 — PHP 8.3+ compatible

**Severity:** N/A  
**Status:** CONFIRMED

**No action needed.**

---

### 2.11 Finding 11 — No async improvements needed

**Severity:** N/A  
**Status:** CONFIRMED

**No action needed.**

---

## Phase 3: Implementation [PENDING]

### 3.1 Fix Empty Data Auto-Width Behavior (Finding 1, MEDIUM)

**File:** `sugar-charts/src/Sparkline/Sparkline.php`

**Option A (Document):** Leave as-is but add doc comment explaining that `Sparkline::new([])->view()` returns empty string by design (no data to visualize).

**Option B (Change):** Add guard in `view()` to handle empty data with explicit width differently:
```php
// At line 144 in view()
if ($this->data === [] && $this->width > 0) {
    return $this->styled(str_repeat(self::GLYPHS[0], $this->width));
}
```

**Recommendation:** Option A — document the current behavior as intentional. Users who want padded empty sparklines should use `Sparkline::new([], $desiredWidth)`.

---

### 3.2 Document Negative Value Handling (Finding 3, MEDIUM)

**File:** `sugar-charts/src/Sparkline/Sparkline.php`

**Change at line 214 (add docblock):**
```php
/**
 * Map a value to one of 8 block-bar glyphs (indices 1–8).
 *
 * Negative values and values outside [min, max] are clamped to [0, 1]
 * via max(0.0, min(1.0, $norm)) before scaling.
 *
 * @param float $v     The value to render
 * @param float $min    Minimum value in the data range
 * @param float $range  Max - min (must be > 0; callers ensure this)
 */
private static function glyph(float $v, float $min, float $range): string
```

**Rationale:** Makes it clear that negative values work correctly without requiring future maintainers to trace through the clamping logic.

---

### 3.3 Minimum Width Decision (Finding 8, LOW)

**File:** `sugar-charts/src/Sparkline/Sparkline.php`

**Decision needed:** Should width=0 remain valid (returning empty string) or should a minimum of 1 be enforced?

- If **document**: Add to docblock of `withWidth()` that width=0 is valid for placeholder states.
- If **enforce**: Change `if ($w < 0)` to `if ($w < 1)` at line 71 and add error message.

**Current test suggests keeping width=0 valid** (`testZeroWidthRendersEmpty` at line 18-20), so document approach is preferred.

---

### 3.4 Add Edge Case Tests

**File:** `sugar-charts/tests/Sparkline/SparklineTest.php`

**Add test for all-negative data:**
```php
public function testAllNegativeDataRendersCorrectly(): void
{
    $out = Sparkline::new([-5, -3, -1, -4])->view();
    $this->assertSame(4, Width::string($out));
    $this->assertStringContainsString('▁', $out);
    $this->assertStringContainsString('█', $out);
}
```

**Add test for mixed positive/negative data:**
```php
public function testMixedPositiveNegativeData(): void
{
    $out = Sparkline::new([-100, 50])->view();
    $this->assertSame(2, Width::string($out));
}
```

**Add test for empty data with explicit width:**
```php
public function testEmptyDataWithExplicitWidthRendersPadded(): void
{
    $this->assertSame('    ', Sparkline::new([], 4)->view());
}
```

---

## Phase 4: Documentation Updates [PENDING]

### 4.1 Create CALIBER_LEARNINGS.md

**File:** `sugar-charts/CALIBER_LEARNINGS.md`

Document edge case handling:
- Empty data renders as all-blank (when width explicitly set) or empty string (auto-width=0)
- Negative values normalized to [min, max] range correctly via clamping
- Flat series (range=0) renders mid-bar (▄) for visibility
- Width=0 is valid and intentional (produces empty string for placeholder states)

### 4.2 Add @see Reference

**File:** `sugar-charts/src/Sparkline/Sparkline.php`

Add `@see \SugarCraft\Charts\Chart\NiceScale` for users who need Y-axis auto-scaling.

---

## Summary Table

| Finding | Severity | Status | Action |
|---------|----------|--------|--------|
| 1 Empty data | MEDIUM | Needs fix | Document or guard |
| 2 Single point | LOW | INVALID | None |
| 3 Negative values | MEDIUM | Needs doc | Add comment |
| 4 Performance | N/A | — | None |
| 5 Memory leaks | N/A | — | None |
| 6 Security | N/A | — | None |
| 7 Complexity | N/A | — | None |
| 8 Min width | LOW | Decision | Document or enforce |
| 9 Examples | LOW | INVALID | None |
| 10 PHP compat | N/A | — | None |
| 11 Async | N/A | — | None |

**Total actionable items: 3** (Findings 1, 3, 8)

---

## Key Code Locations

| File | Lines | Purpose |
|------|-------|---------|
| `sugar-charts/src/Sparkline/Sparkline.php` | 55-60 | Auto-width from empty data |
| `sugar-charts/src/Sparkline/Sparkline.php` | 144-149 | view() early exit for width=0 |
| `sugar-charts/src/Sparkline/Sparkline.php` | 214-229 | glyph() method with clamping |
| `sugar-charts/src/Sparkline/Sparkline.php` | 69-75 | withWidth() validation |
| `sugar-charts/src/Chart/NiceScale.php` | 35-50 | ceiling() for Y-axis scaling |
| `sugar-charts/tests/Sparkline/SparklineTest.php` | 13-15 | testEmptyDataPaddedWithBlanks |
| `sugar-charts/tests/Sparkline/SparklineTest.php` | 37-40 | testFlatSeriesRendersMidBar |
| `sugar-charts/examples/sparkline.php` | 1-20 | Example usage |

---

## Notes

- **2026-06-30:** The findings file `findings/sugar-spark.md` is **misnamed** — it describes sparkline charts (`charmbracelet/spark`) but `sugar-spark` in SugarCraft is the sequin port (`charmbracelet/sequin`). Actual sparkline code is at `sugar-charts/src/Sparkline/Sparkline.php`.

- **2026-06-30:** Findings 2 and 9 are already resolved by current code.

- **2026-06-30:** Line numbers in findings (e.g., "line 42", "line 88") do not match current code — findings appear to be from an earlier version or auto-generated with stale references.

- **2026-06-30:** The actual `sugar-spark` library (sequin/ANSI inspector at `sugar-spark/src/Inspector.php`) has no `Sparkline.php` and is unrelated to these findings.
