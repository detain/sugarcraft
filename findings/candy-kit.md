# Code Review: candy-kit

**Library:** `sugarcraft/candy-kit`  
**Review scope:** `src/` (8 classes) + `tests/` (9 test files)  
**Dependencies:** `candy-core`, `candy-sprinkles`, `candy-async`, `candy-layout`, `candy-ansi`, `candy-input`, `candy-buffer`

---

## Summary

candy-kit is a well-structured, clean port of charmbracelet/fang providing CLI presentation helpers. The code is generally of high quality: all classes are `final`, use `declare(strict_types=1)`, follow PSR-4, and are immutable/fluent where appropriate. The most complex component (`Frame`) has thorough documentation and careful ANSI-width handling. However, several specific issues, gaps in test coverage, and opportunities for improvement were identified.

---

## Critical Issues

### 1. Section::header — flawed multi-cell rune formula

**File:** `src/Section.php:34`

```php
$left = str_repeat($rune, intdiv(max(0, $leftPad), $runeW));
```

**Problem:** `leftPad` is expressed in display cells, but `intdiv(cells, cells_per_rune)` gives rune *count*, not cell width. When `$rune` is a multi-cell glyph like `'──'` (`runeW = 2`):

- `leftPad = 2`, `runeW = 2` → `intdiv(2, 2) = 1` → `str_repeat('──', 1)` = **2 cells** ✓ (coincidentally correct)
- `leftPad = 3`, `runeW = 2` → `intdiv(3, 2) = 1` → `str_repeat('──', 1)` = **2 cells** ✗ (should be 3)

The formula produces wrong output whenever `leftPad` is not evenly divisible by `runeW`. The correct approach would be `intdiv(leftPad, runeW)` for counting how many runes fit, but for a "fill to N cells" semantics this doesn't apply — the left padding should simply fill with spaces and the caller should control the rune count directly, OR the parameter should be rune count not cell count.

**Test `testMultiCellRuneNeverOvershoots`** at `tests/SectionTest.php:41` does not catch this because it only asserts `<= 20` (never overshoots), not equality.

**Recommendation:** Either (a) change `$leftPad` semantics to be a *rune count*, or (b) replace the formula with a proper cell-filling approach. Document clearly in the `@param` comment that `leftPad` is a cell count.

---

### 2. Section::rule — inconsistent minimum width vs header

**File:** `src/Section.php:58`

```php
$repeat = intdiv(max(1, $width ?? 2), $runeW);
```

When `$width === null`, `rule()` produces a minimum of 2 cells (`intdiv(max(1, 2), 1) = 2`). But `header()` with `$width === null` produces only 1 trailing rune (`$head . $rune`). These are inconsistent behaviors for what should be related operations.

---

## High-Priority Issues

### 3. Theme::byName — no type guard on non-string input

**File:** `src/Theme.php:115`

```php
public static function byName(string $name): self
{
    return match (strtolower($name)) { ... };
}
```

If called with a non-string (e.g., an integer), `strtolower($name)` emits a warning and may produce unexpected output. While PHP's type system will coerce primitives, an explicit check would produce a clearer error:

```php
if (!is_string($name)) {
    throw new \InvalidArgumentException('Theme name must be a string');
}
```

---

### 4. HelpText::renderRows — double iteration (O(2n))

**File:** `src/HelpText.php:59-68`

```php
// First pass: find max key length
$maxKey = 0;
foreach (array_keys($rows) as $k) {
    if (mb_strlen($k, 'UTF-8') > $maxKey) {
        $maxKey = mb_strlen($k, 'UTF-8');
    }
}
// Second pass: build output
$lines = [];
foreach ($rows as $key => $desc) {
    $padded = $key . str_repeat(' ', max(0, $maxKey - mb_strlen($key, 'UTF-8')));
    ...
}
```

**Recommendation:** Combine into a single pass, tracking both max key width and building lines simultaneously, or use a two-pass only if the first pass genuinely enables optimization. For an array of typical size (< 50 entries), this is not a performance problem, but it is unnecessary complexity.

---

### 5. Section::header — repeated Width::string() calls on same rune

**File:** `src/Section.php:33-34`

```php
$runeW   = max(1, Width::string($rune));  // line 33
$left    = str_repeat($rune, intdiv(max(0, $leftPad), $runeW)); // line 34
$head    = $left . $labelOut;
$remaining = max(0, $width - Width::string($head)); // line 40: remeasures $head
```

`Width::string($head)` at line 40 includes `$left` which was already measured at line 33. The value of `$runeW` computed at line 33 could be reused. Minor — but the pattern of calling `Width::string()` repeatedly on strings that haven't changed hints at potential for a shared helper.

---

## Medium-Priority Issues

### 6. Banner::title — creates a new Style per render

**File:** `src/Banner.php:28-31`

```php
return Style::new()
    ->border($border)
    ->padding(0, 2)
    ->render($body);
```

Every call to `Banner::title()` allocates a new `Style` object with no reuse. For a presentation helper called once per CLI invocation this is irrelevant, but if used in a hot loop (e.g., rendering many lines in a progress output), it adds GC pressure. Consider a cached static style instance if the library is used in high-frequency rendering.

---

### 7. Theme constructor — no null checks on Style properties

**File:** `src/Theme.php:20-28`

```php
public function __construct(
    public readonly Style $success,
    public readonly Style $error,
    public readonly Style $warn,
    public readonly Style $info,
    public readonly Style $prompt,
    public readonly Style $accent,
    public readonly Style $muted,
) {}
```

If any of the 7 Style properties is passed as `null` (e.g., from a custom Theme subclass or typo), the error surfaces as a TypeError on the property declaration — a reasonable error, but a descriptive message would be better. Consider validating in the constructor or adding phpdoc `@param` annotations that declare non-nullability explicitly.

---

### 8. Section::rule — inconsistent null vs 80 default with header()

**File:** `src/Section.php:29` vs `src/Section.php:53`

- `header()`: `$width = 80` (int, non-null default)
- `rule()`: `$width = 80` (int, same non-null default)

But `rule()` then does `$width ?? 2` (line 58) to handle null, while `header()` checks `$width === null` directly. This is not a bug but an inconsistency in how null is treated. Document or align.

---

### 9. Missing `Theme` getters — all 7 Style properties are write-once

**File:** `src/Theme.php:20-28`

All 7 properties are `public readonly` and set only through the constructor. They are directly accessible (e.g., `$theme->success->render('x')`) which works but bypasses any future validation or transformation logic. SugarCraft convention (per AGENTS.md: "Bare accessors (no `get`)") permits public properties as bare accessors, so this is technically compliant — but for a Theme palette object that callers might want to introspect (e.g., "what color is the accent?"), explicit getters would be more future-proof.

---

## Test Coverage Gaps

### 10. Snapshot tests missing for 6 of 8 classes

**File:** `tests/GoldenRenderTest.php`

Golden-file tests exist only for `Stage::step()` and `Stage::subStep()`. The following have **no snapshot tests**:

| Class | Risk |
|-------|------|
| `Banner` | Regression in border/padding rendering |
| `Section::header` | Rune-fill logic (see issue #1) |
| `Section::rule` | Width handling |
| `HelpText` | Multi-section rendering, row alignment |
| `Frame` | The most complex component; body normalization, ellipsis, CJK |
| `Logo` | `withColor()` ANSI wrapping |

`Theme` and `StatusLine` are well-covered by unit tests.

---

### 11. Theme::plain() — no test exercising it in isolation

**File:** `tests/ThemeTest.php`

`ThemeTest::testPlainThemePassthrough()` tests that `->render('text')` returns `'text'` for plain styles, but there is no test for the structural integrity of the Theme object itself (all 7 fields are the same `$s` instance?).

---

### 12. Logo::withColor — test comment indicates missing coverage

**File:** `tests/LogoTest.php:68`

```php
/** The $color instanceof Color branch in withColor() must be exercised too. */
public function testWithColorAcceptsColorInstance(): void
```

The test exists and covers this. However, `testSugarcraftPresetIsMultiLine` at line 39 asserts `count($lines) > 5` — a loose lower-bound that could pass with a truncated logo.

---

## Dependency Issues

### 13. Unused repository entries in composer.json

**File:** `composer.json:51-107`

The `repositories` array includes:
- `../candy-layout` — no class in candy-kit uses any `candy-layout` types
- `../candy-async` — no async patterns exist in the library
- `../candy-buffer` — no buffer usage
- `../candy-input` — no input handling

These add maintenance burden and CI closure-checking noise without providing any value to candy-kit. Unless they are transitive dependencies of `candy-sprinkles` or `candy-core`, they should be removed.

---

## Refactoring Opportunities

### 14. Duplicated ANSI-width padding logic

`Frame::padRight()` (lines 134-153) and `Frame::padCenter()` (lines 162-176) contain nearly identical ANSI-truncation logic:

```php
// padRight
$len = Width::string($s);
if ($len > $width) {
    $s = Width::truncateAnsi($s, max(0, $width - 1)) . '…' . Ansi::reset();
    $len = Width::string($s);
}
if ($len < $width) {
    return $s . str_repeat(' ', $width - $len);
}

// padCenter — identical pattern
$len = Width::string($s);
if ($len > $width) {
    $s = Width::truncateAnsi($s, max(0, $width - 1)) . '…' . Ansi::reset();
    $len = Width::string($s);
}
```

**Recommendation:** Extract common truncation-and-remeasure logic into a private static helper:

```php
private static function truncateWithEllipsis(string $s, int $width): string
{
    $len = Width::string($s);
    if ($len > $width) {
        $s = Width::truncateAnsi($s, max(0, $width - 1)) . '…' . Ansi::reset();
    }
    return $s;
}
```

Then both `padRight` and `padCenter` call this once, remeasure once, and apply padding.

---

### 15. Logo::sugarcraft — hardcoded ASCII art prevents theme-aware rendering

**File:** `src/Logo.php:45-58`

`Logo::sugarcraft()` returns a Logo with the ASCII art baked in as a raw string. `withColor()` (lines 65-70) then wraps the entire ascii string in an ANSI foreground, but the border characters (`╔`, `═`, `║`, etc.) are also colored — they can't be independently themed. 

If a caller wants the SugarCraft logo in a specific theme's accent color but with the border characters in a different color, there's no API to support that. The current API is `Logo::sugarcraft()->withColor('#ff5fd2')` which applies the same color to everything.

Consider separating the logo structure from its color: `Logo::sugarcraft()` could return a Logo with the text portion being `['S', 'u', 'g', 'a', ...]` separately colorable, or provide a `Logo::sugarcraft($fgColor, $borderColor)` variant.

---

## Compatibility Concerns

### 16. No async/ReactPHP integration

**Context:** This is a ReactPHP-based ecosystem (`candy-async` is in the monorepo). candy-kit has no async rendering methods. Since all output is string-based (pure synchronous functions), this is not a bug, but for future compatibility with streaming/async TUI output (e.g., `Frame` rendered incrementally to a terminal via a stream), consider whether `render()` methods could return `\React\Async\Awaitable<string>` or whether a parallel `renderAsync()` method would make sense.

This is **NOT** a recommendation to change — just an observation that the library is purely sync and that decision should be explicit in documentation.

---

### 17. Theme switching mid-output has no state

**File:** `src/Theme.php`

All methods accept an optional `?Theme $theme` parameter. If a caller renders with `Theme::ansi()` then switches to `Theme::dracula()` within the same output, there's no enforcement that the two are compatible in width or style. This is by design (stateless helpers), but it means a mismatch between themes in the same CLI output depends on caller discipline.

---

## Security

### 18. No user-input rendering concerns

The library renders only programmer-controlled strings (glyphs, themes, layout). There is no SQL, no file I/O, no eval, and no user-provided HTML. The `HelpText::render()` method takes structured arrays from the calling code, not raw user input. **No security issues identified.**

---

## Performance

### 19. Negligible — all operations are O(n) string building

The library deals exclusively in string manipulation. `Frame::render()` is the most expensive operation at `O(lines + cells)` which is optimal for its guarantee of exact terminal fill. No loops that scale unexpectedly, no recursion, no I/O.

---

## Missing Features

### 20. No `Theme::dark()` / `Theme::light()` factory based on terminal detection

SugarCraft Core has `BackgroundColorMsg::isDark()`. candy-kit has no `Theme::auto()` or equivalent that picks a preset based on the terminal's background color. This is a natural missing feature for a theme system.

### 21. No programmatic theme builder

Creating a custom theme requires constructing a `Theme` object directly with 7 Style dependencies. A fluent builder (`Theme::build()->success(Style::new()->bold()->foreground(...))->error(...)`) would improve DX significantly.

### 22. No `Frame` title/status styling

**File:** `src/Frame.php:64-79`

`withTitle()` and `withStatus()` accept raw strings that are pre-rendered by the host app. The `Frame` class never applies its `$borderStyle` (a `Style` object) to the title or status strings — they are placed into the frame as plain text. A caller that wants a styled title must pre-style it before passing it in. An explicit API like `Frame::new()->withTitleText('My Title', $theme->accent)` would be more discoverable.

### 23. No `Section::subHeader()` for nested sections

`Section::header()` produces a full-width rule with label. There's no `subHeader()` that renders an indented section divider (e.g., for sub-sections within a larger section). This is a common pattern in CLI output (e.g., `git log` uses indent-level rules).

### 24. No `Stage::subStep()` variant with progress indication

`Stage::step()` shows `current/total` and `Stage::subStep()` shows a tree-line. There is no variant showing a progress bar or spinner within a stage (e.g., `▸ 2/5 installing... [====----]`).

---

## Code Quality Praise

- **Immutable builders**: `Frame::withTitle()`, `Logo::withColor()` all return new instances — correct
- **Type safety**: All classes declare `strict_types`, use typed parameters, and avoid `mixed`
- **ANSI-width correctness**: `Frame::padRight()` and `Frame::padCenter()` correctly use `Width::string()` and `Width::truncateAnsi()` for ANSI-aware measurement — critical for correct terminal output
- **Extensive docblocks**: Every class and method has a docblock citing the upstream charmbracelet/fang reference
- **Builder pattern for Frame**: The `with*()` chain is a clean, fluent API
- **Tests**: Unit tests are well-structured with data providers (`FrameTest::sizes()`, `FrameTest::bodies()`); golden file tests for Stage output
- **Constants vs magic strings**: Glyph constants (`GLYPH_SUCCESS`, `GLYPH_ERROR`) defined as class constants — good practice

---

## Recommendations (Priority Order)

1. **[Critical]** Fix `Section::header()` multi-cell rune formula or clarify its semantics in the docblock (issue #1)
2. **[High]** Add snapshot tests for `Banner`, `Section`, `HelpText`, `Frame`, `Logo` (issue #10)
3. **[High]** Add `Theme::byName()` type guard for non-string input (issue #3)
4. **[Medium]** Consolidate duplicate ANSI-truncation logic in `Frame::padRight/padCenter` (issue #14)
5. **[Medium]** Remove unused repository entries from `composer.json` (issue #13)
6. **[Medium]** Combine the two-pass iteration in `HelpText::renderRows()` (issue #4)
7. **[Low]** Add `Theme` getters for the 7 Style properties (issue #9)
8. **[Low]** Add `Theme::auto()` factory using `BackgroundColorMsg::isDark()` (issue #20)
9. **[Low]** Add `Frame::withTitleStyled()` API for theme-aware title rendering (issue #22)
10. **[Info]** Consider async rendering variants for future streaming TUI integration (issue #16)
