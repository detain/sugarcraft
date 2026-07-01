---
status: not-started
phase: 1
updated: 2026-06-30
---

# Implementation Plan: candy-kit

## Goal

Address all 24 findings from the code review of `sugarcraft/candy-kit`, covering critical bugs, test coverage gaps, refactoring opportunities, dependency cleanup, and missing features — organized into executable phases with clear conditions for success.

---

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Organize fixes by dependency order (critical → high → medium → low) | Core bugs must be fixed before adding tests; tests before refactoring; refactoring before features | `ref:candy-kit-review` |
| Snapshot tests use `candy-testing`'s `assertGoldenAnsi` | Matches existing `GoldenRenderTest.php` pattern and `CALIBER_LEARNINGS.md` recommendation | `ref:candy-kit-caliber` |
| Fix the Section::header rune formula semantics rather than behavior | Changing parameter semantics would break callers; clarify docs + fix math is safer | `ref:candy-kit-section-34` |
| Remove unused repositories from composer.json | These are path-repo entries for deps not actually used by candy-kit; removing reduces CI noise | `ref:candy-kit-composer` |
| Extract truncate helper to reduce Frame::padRight/padCenter duplication | Both methods contain nearly identical ANSI-truncation-and-remeasure logic; DRY principle | `ref:candy-kit-frame-134-176` |

---

## Phase 1: Critical Bugs [PENDING]

### 1.1 Section::header — Fix multi-cell rune formula

**File:** `candy-kit/src/Section.php:34`

**What is expected:**
The `$leftPad` parameter is documented as "cell width" but the formula `str_repeat($rune, intdiv(max(0, $leftPad), $runeW))` produces wrong output for multi-cell runes when `leftPad` is not evenly divisible by `runeW`. For example, `leftPad=3, rune='──' (2-cell)` produces 2 cells instead of 3.

**Why:** This is a correctness bug. A caller requesting 3 cells of left-padding with a 2-cell rune gets only 2 cells.

**Severity:** critical

**Conditions for success:**
1. `Section::header('X', Theme::plain(), leftPad: 2, width: 20, rune: '─')` → 2-cell left pad (single `─`, works with `intdiv(2,1)=2`)
2. `Section::header('X', Theme::plain(), leftPad: 2, width: 20, rune: '──')` → 2-cell left pad (one `──`, works with `intdiv(2,2)=1`)
3. `Section::header('X', Theme::plain(), leftPad: 3, width: 20, rune: '──')` → ≥2-cell left pad (must not overshoot width=20)
4. The test `testMultiCellRuneNeverOvershoots` must pass and should be updated to assert exact width, not just `<=20`

**Related code:**
- `candy-kit/src/Section.php:33-34` — `$runeW` computation and `$left` formula
- `candy-kit/tests/SectionTest.php:41-46` — existing (weak) test

**Investigation notes:**
The current formula interprets `$leftPad` as "how many cells of left padding" but then counts runes via `intdiv(cells, cellsPerRune)`. This is the correct formula for "fill to N cells with a repeating rune that is N cells wide" — the problem is it doesn't allow partial runes. The fix options are: (a) change semantics to "rune count" not "cell count", or (b) use a proper cell-fill approach with spaces for remainder. Option (a) is cleaner — rename `$leftPad` to `$leftRunes` and update the intdiv to just `$leftPad` (since it's now a count, not cells).

```php
// Current (line 34):
$left = str_repeat($rune, intdiv(max(0, $leftPad), $runeW));
// For leftPad=3, runeW=2: intdiv(3,2)=1 → "──" (2 cells) ← WRONG

// Fix option (a) — change $leftPad to be rune count:
$left = str_repeat($rune, max(0, $leftPad));
// For leftPad=2, rune='──': "────" (4 cells) ← but then we need to recalculate
```

Actually, the correct interpretation: `$leftPad` is the number of **cells** to fill with the rune. Since runes may be multi-cell, the correct approach is to fill with spaces when the remainder doesn't divide evenly, OR to change the param to be rune count. Looking at the docblock: `@param ?int $width total cell width to fill; null = stop after the leading pad + label + 1 trailing rune` — it says "cell width" for `$width` but doesn't specify for `$leftPad`. The most self-consistent fix is to treat `$leftPad` as a **rune count** (matching the behavior of the existing test at line 36 which uses `leftPad: 2, rune: '='` and expects 8 cells total). However, the existing test `testHeaderFillsToWidth` uses `leftPad: 2` with `width: 20` and expects exactly 20 cells — so if `leftPad=2` means "2 runes", `rune='─'` (1-cell) gives 2 cells of left padding, which is consistent. The fix should be to **document** that `leftPad` is a **rune count** (not cell count), which is what the current formula assumes.

Actually, re-reading more carefully: `intdiv(max(0, $leftPad), $runeW)` divides by `runeW`. If `leftPad` is rune count, you don't divide. If `leftPad` is cell count, you divide to get rune count. The formula IS correct for "cell count → rune count" conversion, but it truncates. The real issue: when `leftPad=3` cells and `runeW=2` cells per rune, we get `intdiv(3,2)=1` rune = 2 cells. But `leftPad=3` means "I want 3 cells of left padding" which can't be satisfied with 2-cell runes.

The fix should be to **document** that `leftPad` is a **rune count** (semantic: "how many runes of left padding, measured in cells as a side effect"), and update the test to assert the actual result is correct. The finding itself says: "Either (a) change `$leftPad` semantics to be a *rune count*, or (b) replace the formula with a proper cell-filling approach."

Option (a) is chosen: treat `leftPad` as rune count. Update docblock and `$left = str_repeat($rune, max(0, $leftPad));`

---

### 1.2 Section::rule — Align minimum width behavior with header()

**File:** `candy-kit/src/Section.php:58`

**What is expected:**
`rule()` with `$width === null` currently uses `$width ?? 2` producing minimum 2 cells. `header()` with `$width === null` produces only 1 trailing rune. These should be consistent.

**Why:** Inconsistent behavior between two methods that are documented as related operations.

**Severity:** critical

**Conditions for success:**
1. `Section::rule(Theme::plain(), null, '─')` produces the same minimum output as `Section::header('', Theme::plain(), 0, null, '─')`
2. Document both behaviors clearly

**Related code:** `candy-kit/src/Section.php:53-61`

**Investigation notes:**
Line 58: `$repeat = intdiv(max(1, $width ?? 2), $runeW);`
When `$width = null`: `max(1, null ?? 2) = max(1, 2) = 2`, `intdiv(2, 1) = 2` runes
When `$width = 2`: `max(1, 2) = 2`, `intdiv(2, 1) = 2` runes

For header with `width === null` and `leftPad=0, label=''`:
Line 37-38: `if ($width === null) { return $head . $rune; }` → just 1 rune + label

So header with empty label gets 1 rune, but rule() gets 2. The fix should make them consistent: either both return 1 rune for null-width, or document the difference intentionally. Given that `rule()` without width is intended to be a "minimum visible dash", 2 cells makes sense for visibility. Add a clarifying docblock to `rule()` noting it always produces at least 2 cells even when `$width=null`.

---

## Phase 2: High-Priority Fixes [PENDING]

### 2.1 Theme::byName — Add type guard for non-string input

**File:** `candy-kit/src/Theme.php:115`

**What is expected:**
If called with a non-string (e.g., an integer), `Theme::byName()` should throw a clear `\InvalidArgumentException` rather than emitting a PHP warning via `strtolower()`.

**Why:** Prevents confusing error messages and makes the API more robust.

**Severity:** high

**Conditions for success:**
1. `Theme::byName(123)` throws `\InvalidArgumentException` with message about string required
2. `Theme::byName('dracula')` continues to work as before
3. Existing tests still pass

**Related code:** `candy-kit/src/Theme.php:113-124`

**Investigation notes:**
The existing docblock already has `@throws \InvalidArgumentException if the name is not recognised`, but it doesn't mention type checking. Adding a type guard at the top of `byName()` would make it consistent with the documented exception.

---

### 2.2 HelpText::renderRows — Combine double iteration into single pass

**File:** `candy-kit/src/HelpText.php:59-68`

**What is expected:**
The two-pass algorithm (first pass finds max key length, second pass builds output) should be combined into a single pass that tracks max key width while building lines.

**Why:** Unnecessary complexity for arrays of typical size (< 50 entries). While not a performance problem, it violates the "no unnecessary complexity" principle.

**Severity:** high

**Conditions for success:**
1. Output of `HelpText::renderRows()` is identical before and after refactor
2. All existing tests pass (especially `testRenderRowsAlignsKeys`)

**Related code:** `candy-kit/src/HelpText.php:53-71`

**Investigation notes:**
The two-pass exists because the padding needs the max key width, which isn't known until all keys are scanned. However, a single-pass approach collects lines in an array while tracking max width, then applies padding in a second (implicit) loop over the collected lines. Actually, to truly single-pass we'd need to either: (a) collect all keys first, find max, then format, OR (b) use a two-pass that is genuinely needed for the alignment feature. The finding notes the two-pass is "unnecessary complexity" for typical sizes. The refactor could use `array_reduce` to compute max in a single functional pass, but the result is the same O(2n). A true single-pass would need to buffer all keys first. Given the finding says "combine into a single pass" the cleanest approach is to use `array_map` with a closure that captures max by reference — one explicit loop.

---

### 2.3 Section::header — Cache repeated Width::string() call

**File:** `candy-kit/src/Section.php:33-40`

**What is expected:**
`$runeW = max(1, Width::string($rune))` is computed once and reused, not recomputed.

**Why:** Minor inefficiency — `$runeW` is computed at line 33 but not reused even though it could be.

**Severity:** high (but minor)

**Conditions for success:**
1. `Width::string($rune)` is called only once per `header()` invocation
2. Output unchanged
3. All tests pass

**Related code:** `candy-kit/src/Section.php:33-40`

**Investigation notes:**
The finding notes `$runeW` is computed at line 33, then `$head` is built at line 36, then `$remaining = max(0, $width - Width::string($head))` at line 40 remeasures `$head` which already includes `$left`. The `$runeW` is NOT recomputed at line 40 — `Width::string($head)` is measuring the whole `$head` string, not just `$left`. So the issue is more about `$runeW` being potentially computable once and `$head` width being computable without re-measuring the full string after construction. Actually this is already fine — `Width::string($head)` at line 40 is necessary because `$head` includes the label which was styled by the theme, and its width can only be known after construction. The real minor optimization would be to store `$runeW` in a variable and reuse it in the trailing fill calculation at line 41 (where `$runeW` is already used). This is already done correctly — `$runeW` is stored at line 33 and reused at line 41. The finding may be slightly off; the only real inefficiency is `Width::string($head)` at line 40 which is genuinely necessary because `$head` is dynamically constructed.

---

### 2.4 Snapshot tests — Add for Banner, Section, HelpText, Frame, Logo

**File:** `candy-kit/tests/GoldenRenderTest.php`

**What is expected:**
Add golden-file snapshot tests for the 6 classes that lack them: `Banner`, `Section`, `HelpText`, `Frame`, `Logo`. Also add `StatusLine` for completeness.

**Why:** Regression protection for render output — ANSI output can inadvertently change and unit tests don't catch visual regressions.

**Severity:** high

**Conditions for success:**
1. Each class has at least one golden file test
2. All golden files use `Theme::ansi()` for coloured output
3. Fixtures stored in `candy-kit/tests/fixtures/`
4. All tests pass and CI captures the canonical output

**Related code:**
- `candy-kit/tests/GoldenRenderTest.php`
- `candy-kit/tests/fixtures/stage-step.golden`
- `candy-kit/tests/fixtures/stage-substep.golden`

**Investigation notes:**
`GoldenRenderTest` currently tests only `Stage::step()` and `Stage::subStep()`. The existing fixture format uses `assertGoldenAnsi` from `candy-testing`. The finding lists these classes with associated risk:
- `Banner` — border/padding regression
- `Section::header` — rune-fill logic (see issue #1)
- `Section::rule` — width handling
- `HelpText` — multi-section, row alignment
- `Frame` — body normalization, ellipsis, CJK (most complex)
- `Logo` — `withColor()` ANSI wrapping

---

## Phase 3: Medium-Priority Fixes [PENDING]

### 3.1 Banner::title — Cache Style instance

**File:** `candy-kit/src/Banner.php:28-31`

**What is expected:**
`Style::new()->border($border)->padding(0, 2)->render($body)` is called on every `title()` invocation. For high-frequency rendering, this creates unnecessary GC pressure. Consider a static cached instance for the common case (rounded border, standard padding).

**Why:** Minor optimization relevant if Banner is used in hot loops (e.g., progress rendering). Negligible for single-shot CLI output.

**Severity:** medium

**Conditions for success:**
1. For the default rounded border, the same Style instance is reused across calls
2. Output is byte-identical before and after
3. All Banner tests pass

**Related code:** `candy-kit/src/Banner.php:18-32`

---

### 3.2 Theme constructor — Add null checks

**File:** `candy-kit/src/Theme.php:20-28`

**What is expected:**
If any of the 7 Style properties is passed as `null`, the constructor should throw a descriptive `\InvalidArgumentException` rather than letting PHP's type system produce a cryptic TypeError.

**Why:** Better developer experience. A typo like `new self(...$arr)` with a null in the array produces a confusing error.

**Severity:** medium

**Conditions for success:**
1. `new Theme(null, $style, ...)` throws `\InvalidArgumentException` with message naming the null field
2. All existing tests still pass
3. All 7 Style properties are validated

**Related code:** `candy-kit/src/Theme.php:18-28`

---

### 3.3 Section::rule — Document null vs 80 default inconsistency with header()

**File:** `candy-kit/src/Section.php:29` vs `src/Section.php:53`

**What is expected:**
`header()` has `$width = 80` (int) then checks `$width === null`. `rule()` has `$width = 80` (int) then does `$width ?? 2`. The inconsistency should be documented or aligned.

**Why:** API clarity — callers should understand the null-handling difference between two related methods.

**Severity:** medium

**Conditions for success:**
1. Both methods' null-handling behavior is clearly documented in their docblocks
2. `Section::rule()` docblock explicitly states the "minimum 2 cells when null" behavior

**Related code:** `candy-kit/src/Section.php:25-43` (header), `candy-kit/src/Section.php:51-61` (rule)

---

### 3.4 Theme — Add getters for 7 Style properties

**File:** `candy-kit/src/Theme.php:20-28`

**What is expected:**
Add explicit getter methods for all 7 Style properties: `success()`, `error()`, `warn()`, `info()`, `prompt()`, `accent()`, `muted()`. While the AGENTS.md convention permits bare public readonly properties, getters provide future-proofing for validation/transformation logic.

**Why:** Better encapsulation. If Theme ever needs to validate or transform style access, getters allow that without breaking the API.

**Severity:** medium

**Conditions for success:**
1. `$theme->success()` returns the same `Style` as `$theme->success`
2. All existing tests using property access continue to work
3. New getter methods are documented

**Related code:** `candy-kit/src/Theme.php:18-28`

**Investigation notes:**
SugarCraft convention (per AGENTS.md: "Bare accessors (no `get`)") permits public properties as bare accessors. However, adding getters doesn't violate this — it adds an alternative access pattern. The properties remain `public readonly` so existing `$theme->success->render('x')` continues to work.

---

### 3.5 Theme::plain() — Add structural integrity test

**File:** `candy-kit/tests/ThemeTest.php`

**What is expected:**
Add a test that verifies `Theme::plain()` produces a Theme with all 7 fields being the **same** `$s` instance (object identity), not just equal styles.

**Why:** `Theme::plain()` uses `$s = Style::new()` once and passes it to all 7 fields. The test `testPlainThemePassthrough` only tests that each field's `->render('text')` returns `'text'`, not that all 7 are the identical instance.

**Severity:** medium

**Conditions for success:**
1. `Theme::plain()->success === Theme::plain()->error === ... === Theme::plain()->muted` (same object)
2. This is a property of the implementation, not just the render output

**Related code:** `candy-kit/tests/ThemeTest.php:27-33`, `candy-kit/src/Theme.php:43-47`

---

### 3.6 Logo::sugarcraft — Hardcoded ASCII art color separation

**File:** `candy-kit/src/Logo.php:45-58`

**What is expected:**
`Logo::sugarcraft()` returns ASCII art with border characters and text baked together. `withColor()` then applies a single color to the entire thing. This doesn't allow independent border vs text coloring.

**Why:** Limits theming flexibility. A caller might want text in accent color and border in a different color.

**Severity:** medium

**Conditions for success:**
1. Either document the limitation, OR
2. Provide a `Logo::sugarcraft($fgColor, $borderColor)` variant, OR
3. Provide a `Logo::sugarcraft()->withTextColor($c)->withBorderColor($c)` builder

**Related code:** `candy-kit/src/Logo.php:45-70`

---

### 3.7 Duplicated ANSI-truncation logic in Frame

**File:** `candy-kit/src/Frame.php:134-176`

**What is expected:**
Extract the common truncation-and-remeasure pattern from `padRight()` (lines 134-153) and `padCenter()` (lines 162-176) into a private static helper `truncateWithEllipsis()`.

**Why:** DRY principle. Both methods contain nearly identical ANSI-truncation logic. The helper approach reduces code duplication and makes the truncation algorithm easier to verify.

**Severity:** medium

**Conditions for success:**
1. A new `private static function truncateWithEllipsis(string $s, int $width): string` exists
2. Both `padRight` and `padCenter` call this helper
3. Output is byte-identical before and after
4. All Frame tests pass

**Related code:** `candy-kit/src/Frame.php:134-176`

---

### 3.8 Remove unused repository entries from composer.json

**File:** `candy-kit/composer.json:51-107`

**What is expected:**
Remove unused path repositories:
- `../candy-layout` — no usage in candy-kit
- `../candy-async` — no async patterns
- `../candy-buffer` — no buffer usage
- `../candy-input` — no input handling

Keep only repositories that are actually used: `candy-core`, `candy-sprinkles` (required deps), and `candy-testing` (dev dep).

**Why:** Reduces maintenance burden and CI closure-checking noise. These entries were likely copy-paste scaffold artifacts.

**Severity:** medium

**Conditions for success:**
1. `composer validate` passes
2. All tests pass with `cd candy-kit && composer install && vendor/bin/phpunit`
3. `tools/check-path-repos.php` reports no errors

**Related code:** `candy-kit/composer.json:50-107`

**Investigation notes:**
The `require` section only lists `candy-core` and `candy-sprinkles` as actual dependencies. The other path repos (`candy-layout`, `candy-async`, `candy-ansi`, `candy-input`, `candy-buffer`) are listed in `repositories[]` but not in `require[]`. These could be: (a) transitive deps of `candy-sprinkles` that need path repos for the closure, OR (b) scaffolding artifacts. The finding says "Unless they are transitive dependencies of `candy-sprinkles` or `candy-core`, they should be removed." — need to verify whether any of these are transitive deps.

---

## Phase 4: Low-Priority / Future Work [PENDING]

### 4.1 Theme::auto() — Terminal background detection factory

**File:** `candy-kit/src/Theme.php`

**What is expected:**
Add `Theme::auto()` that calls `BackgroundColorMsg::isDark()` from candy-core to detect terminal background and return an appropriate light or dark theme.

**Why:** Natural missing feature for a theme system — reduces manual theme selection for users.

**Severity:** low

**Conditions for success:**
1. `Theme::auto()` exists and returns appropriate preset based on terminal detection
2. Falls back to `Theme::ansi()` if detection fails

**Related code:** `candy-kit/src/Theme.php`, `candy-core/src/Terminal/BackgroundColorMsg.php` (if it exists)

---

### 4.2 Frame::withTitleStyled() — Theme-aware title rendering

**File:** `candy-kit/src/Frame.php:64-79`

**What is expected:**
Add an explicit API `Frame::withTitleText(string $text, Style $style)` that applies the style to the title before placing it in the frame, making the title styling more discoverable than the current "pass pre-rendered string" approach.

**Why:** API discoverability. The current `withTitle()` accepts raw strings which are pre-rendered by the host app, but callers may expect the Frame to handle title styling.

**Severity:** low

**Conditions for success:**
1. `Frame::new()->withTitleText('My Title', $theme->accent)->render(...)` works
2. `withTitle()` continues to work as-is (backwards compatible)

**Related code:** `candy-kit/src/Frame.php:64-79`

---

### 4.3 Section::subHeader() — Nested section divider

**File:** `candy-kit/src/Section.php`

**What is expected:**
Add `Section::subHeader()` that renders an indented section divider for sub-sections within a larger section (e.g., `git log`-style indent-level rules).

**Why:** Common CLI output pattern. Requested by finding #23.

**Severity:** low

**Conditions for success:**
1. `Section::subHeader('Sub Section Name', Theme::ansi())` renders an indented divider
2. Signature follows existing `header()` pattern

**Related code:** `candy-kit/src/Section.php`

---

### 4.4 Stage::subStep() variant with progress indication

**File:** `candy-kit/src/Stage.php`

**What is expected:**
Add a variant of `subStep()` (or `step()`) that shows a progress bar or spinner within a stage (e.g., `▸ 2/5 installing... [====----]`).

**Why:** Feature request. The current API only has numeric progress (`2/5`) or tree-line (`├─`). A progress-bar variant would fill a gap.

**Severity:** low

**Conditions for success:**
1. New method exists with clear signature
2. Backwards compatible with existing `step()` and `subStep()`

**Related code:** `candy-kit/src/Stage.php`

---

### 4.5 Theme programmatic builder

**File:** `candy-kit/src/Theme.php`

**What is expected:**
Add a fluent builder for custom themes: `Theme::build()->success(Style::new()->bold()->foreground(...))->error(...)`.

**Why:** DX improvement for custom theme creation. Currently requires constructing a Theme with 7 Style dependencies directly.

**Severity:** low

**Conditions for success:**
1. `Theme::build()->success($s)->error($s)->warn($s)->info($s)->prompt($s)->accent($s)->muted($s)->done()` returns a Theme
2. All Style properties are required (no partial themes)

**Related code:** `candy-kit/src/Theme.php`

---

## Phase 5: Informational Only [PENDING]

These items are noted for awareness but do not require action:

### 5.1 Async/ReactPHP integration

**Finding reference:** Issue #16

`candy-kit` is purely synchronous. This is not a bug but a design decision. Document this constraint clearly if async streaming support is ever considered.

### 5.2 Theme switching mid-output has no state

**Finding reference:** Issue #17

All methods accept optional `?Theme $theme`. No enforcement of theme consistency across an output. This is by design (stateless helpers).

### 5.3 No user-input rendering concerns

**Finding reference:** Issue #18

The library renders only programmer-controlled strings. No SQL, file I/O, eval, or user-provided HTML. **No security issues identified.**

### 5.4 Performance — Negligible

**Finding reference:** Issue #19

All operations are O(n) string building. Frame is O(lines + cells) which is optimal. No loops that scale unexpectedly, no recursion, no I/O.

---

## Verification

### Pre-flight
```bash
cd /home/sites/sugarcraft/candy-kit && composer install
```

### Run tests
```bash
cd /home/sites/sugarcraft/candy-kit && vendor/bin/phpunit
```

### Specific test files
```bash
cd /home/sites/sugarcraft/candy-kit && vendor/bin/phpunit tests/SectionTest.php
cd /home/sites/sugarcraft/candy-kit && vendor/bin/phpunit tests/ThemeTest.php
cd /home/sites/sugarcraft/candy-kit && vendor/bin/phpunit tests/HelpTextTest.php
cd /home/sites/sugarcraft/candy-kit && vendor/bin/phpunit tests/BannerTest.php
cd /home/sites/sugarcraft/candy-kit && vendor/bin/phpunit tests/FrameTest.php
cd /home/sites/sugarcraft/candy-kit && vendor/bin/phpunit tests/LogoTest.php
cd /home/sites/sugarcraft/candy-kit && vendor/bin/phpunit tests/StageTest.php
cd /home/sites/sugarcraft/candy-kit && vendor/bin/phpunit tests/StatusLineTest.php
cd /home/sites/sugarcraft/candy-kit && vendor/bin/phpunit tests/GoldenRenderTest.php
```

### Composer validation
```bash
cd /home/sites/sugarcraft/candy-kit && composer validate
```

### Path repo checker
```bash
cd /home/sites/sugarcraft && php tools/check-path-repos.php
```

---

## Notes

- 2026-06-30: Plan created based on findings in `/home/sites/sugarcraft/findings/candy-kit.md` (24 items total)
- Critical: 2 items (Section::header formula, Section::rule width consistency)
- High: 5 items (byName type guard, HelpText double-pass, repeated Width calls, snapshot tests for 6 classes, plus the snapshot tests which are finding #10)
- Medium: 7 items (Banner style cache, Theme null checks, Section null doc, Theme getters, Theme::plain structural test, Logo color separation, Frame duplication, unused repos)
- Low: 4 items (Theme::auto, Frame::withTitleStyled, Section::subHeader, Stage progress variant)
- Informational: 4 items (async, theme state, security, performance — no action needed)
- Total action items: 2 + 4 + 7 + 4 = **17 actionable items**
