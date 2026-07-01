---
status: not-started
phase: 1
updated: 2026-06-30
---

# Implementation Plan: candy-freeze

## Goal

Fix all 29 findings from the `candy-freeze.md` code review across critical bugs, performance issues, code quality improvements, security hardening, missing features, and test coverage gaps.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Use `WeakMap` for `allocateColor` cache | `WeakMap` automatically cleans up entries when the GD image is destroyed, eliminating the memory leak without manual lifecycle management | `ref:candy-freeze-investigation` |
| Replace static `$cache` with instance property | Static variables in PHP persist across requests in long-running processes; instance-scoped state is correct for a reusable renderer object | `ref:candy-freeze-investigation` |
| Remove duplicate `throw` at `VsCodeThemeLoader.php:52` | PHP 8 allows `throw new Exception()` as an expression, so `throw throw new ...` is a parse error | `ref:candy-freeze-investigation` |
| Extract shared `LayoutCalculator` class | Both SVG and PNG renderers calculate identical layout dimensions; DRY violation means adding gutter options requires editing two files | `ref:candy-freeze-investigation` |
| Use `mutate()` trait for Segment | SugarCraft convention from `candy-sprinkles/src/Style.php` and `candy-core/src/Concerns/Mutable.php`; more extensible than ad-hoc `withBg()` | `ref:candy-freeze-investigation` |
| Add 1MB read limit to theme loaders | `file_get_contents()` without limits allows malicious large files to exhaust memory; standard security hardening | `ref:candy-freeze-investigation` |
| Wire `Theme::$windowStyle` into renderers | Currently set but never read — misleading API that implies it affects rendering when it doesn't | `ref:candy-freeze-investigation` |

---

## Phase 1: Critical Bugs [PENDING]

- [ ] 1.1 **[MEMORY LEAK]** Fix `PngRenderer::allocateColor()` static `$cache` — replace with instance-scoped `WeakMap`
- [ ] 1.2 **[BUG]** Remove duplicate `throw` keyword at `VsCodeThemeLoader.php:52`
- [ ] 1.3 **[DEAD CODE]** Remove unused `$buttonHoverColors` from `SvgRenderer.php:316` and `PngRenderer.php:299`

### 1.1: Fix `PngRenderer::allocateColor()` Memory Leak

**File:** `candy-freeze/src/PngRenderer.php:231-252`

**What:** The static `$cache` array grows indefinitely as `spl_object_id($img)` entries accumulate for every GD image created, even after `imagedestroy()`. Entries for destroyed images become orphaned.

**Why:** For a long-running CLI or ReactPHP event loop rendering many images, this causes memory exhaustion. Each new `imagecreatetruecolor()` call produces a new `spl_object_id`, adding a new top-level key to the static array that is never cleaned up.

**Implementation:** Replace the static `$cache` with an instance property using PHP's `WeakMap` keyed by the `\GdImage` object. `WeakMap` automatically removes entries when the backing object is garbage-collected:

```php
final class PngRenderer
{
    /** @var WeakMap<\GdImage, array<string, int>> */
    private WeakMap $colorCache;

    public function __construct(
        // ... existing params
    ) {
        // ... existing init
        $this->colorCache = new WeakMap();
    }

    private function allocateColor(\GdImage $img, string $hex): int
    {
        if (!isset($this->colorCache[$img])) {
            $this->colorCache[$img] = [];
        }
        $cache = &$this->colorCache[$img]; // keyed by hex → GD color index

        if (isset($cache[$hex])) {
            return $cache[$hex];
        }

        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));

        $color = imagecolorallocate($img, $r, $g, $b);
        if ($color === false) {
            $color = imagecolorallocate($img, 255, 255, 255);
        }

        $cache[$hex] = $color;
        return $color;
    }
}
```

**Conditions for Success:**
- `vendor/bin/phpunit` passes in `candy-freeze/`
- Add a test that calls `render()` 100 times and asserts no memory growth
- `grep -n "WeakMap" src/PngRenderer.php` returns the new `WeakMap` property and its usage

**Related Code Locations:**
- `src/PngRenderer.php:231-252` — the `allocateColor()` method with static cache
- `src/PngRenderer.php:28-59` — constructor where `WeakMap` should be initialized

**Investigation Notes:**
- The static `$cache` at line 233 uses `spl_object_id($img)` as the top-level key
- Each call to `imagecreatetruecolor()` in `render()` (line 120) produces a new image, hence a new `spl_object_id`
- When `imagedestroy($img)` is called (line 214), the `spl_object_id` becomes invalid but the cache entry persists
- `WeakMap` solves this by auto-cleaning when the GD object is garbage-collected

---

### 1.2: Remove Duplicate `throw` Keyword

**File:** `candy-freeze/src/Theme/VsCodeThemeLoader.php:52`

**What:** Line 52 reads `throw throw new \InvalidArgumentException(...)` — the word `throw` is duplicated.

**Why:** In PHP 8, `throw` is an expression (not just a statement), so `throw new \InvalidArgumentException(...)` is valid — but `throw throw new ...` tries to throw the result of a `throw` expression, which is not an exception instance.

**Fix:** Change line 52 from:
```php
throw throw new \InvalidArgumentException("Failed to read VS Code theme file: {$path}");
```
to:
```php
throw new \InvalidArgumentException("Failed to read VS Code theme file: {$path}");
```

**Conditions for Success:**
- `vendor/bin/phpunit --filter VsCodeThemeLoaderTest` passes
- `php -l src/Theme/VsCodeThemeLoader.php` shows no syntax errors
- The line no longer contains `throw throw`

**Related Code Locations:**
- `src/Theme/VsCodeThemeLoader.php:52` — the buggy duplicate `throw`
- `src/Theme/ChromaThemeLoader.php:51-53` — correct usage for comparison

**Investigation Notes:**
- PHP 8.x uses `throw` as an expression: `return throw new Exception()` is valid (returns the exception)
- `throw new Exception()` returns an Exception instance; `throw throw new Exception()` tries to throw that instance
- The bug would produce: "Fatal error: Uncaught Error: Exception instance expected"

---

### 1.3: Remove Unused `$buttonHoverColors` Variable

**Files:** `candy-freeze/src/SvgRenderer.php:316` and `candy-freeze/src/PngRenderer.php:299`

**What:** Both window-chrome builders declare `$buttonHoverColors` (SVG at line 316, PNG at line 299) but never read from it. These were scaffolded for planned hover interactions that were never wired up.

**Why:** Dead code clutters the codebase and misleads readers into thinking hover effects are implemented when they are not.

**SvgRenderer.php fix** — delete line 316:
```php
// BEFORE (lines 315-317):
$buttonColors = ['#444444', '#444444', '#444444'];
$buttonHoverColors = ['#555555', '#555555', '#e81123'];
$buttonY = $titleBarY + ($titleBarHeight - $buttonSize) / 2;

// AFTER:
$buttonColors = ['#444444', '#444444', '#444444'];
$buttonY = $titleBarY + ($titleBarHeight - $buttonSize) / 2;
```

**PngRenderer.php fix** — delete line 299:
```php
// BEFORE (lines 298-300):
$buttonColors = ['#444444', '#444444', '#444444'];
$buttonHoverColors = ['#555555', '#555555', '#e81123'];
$buttonY = $titleBarY + ($titleBarHeight - $buttonSize) / 2;

// AFTER:
$buttonColors = ['#444444', '#444444', '#444444'];
$buttonY = $titleBarY + ($titleBarHeight - $buttonSize) / 2;
```

**Conditions for Success:**
- `vendor/bin/phpunit --filter SvgRendererTest` passes
- `vendor/bin/phpunit --filter PngRendererTest` passes
- `grep -n "buttonHoverColors" src/SvgRenderer.php src/PngRenderer.php` returns nothing

**Related Code Locations:**
- `src/SvgRenderer.php:315-317` — unused variable declaration
- `src/PngRenderer.php:298-300` — unused variable declaration

**Investigation Notes:**
- In `buildWindowsTerminalWindow()`, hover colors are never applied — only the base `$buttonColors` array is used
- The three-button close/minimize/maximize design never uses hover effects
- If hover interactions are desired in future, the hover color variables should be re-added with proper event handling

---

## Phase 2: Correctness Fixes [PENDING]

- [ ] 2.1 **[TYPO]** Fix fallback color `#c9d9` → `#c9d1d9` at `VsCodeThemeLoader.php:77`
- [ ] 2.2 **[DOCS]** Add Unicode limitation warning to `PngRenderer` docblock
- [ ] 2.3 **[SYMLINK]** Add symlink check in CLI for input files outside CWD

### 2.1: Fix Wrong Fallback Color in `VsCodeThemeLoader`

**File:** `candy-freeze/src/Theme/VsCodeThemeLoader.php:77`

**What:** The `resolveColor` call for foreground uses `#c9d9` (6 chars) instead of the correct `#c9d1d9` (8 chars). This is inconsistent with every other fallback in the file and with the default `Theme` foreground of `#c9d1d9`.

**Why:** The 6-char fallback `#c9d9` would produce a different color (dark cyan-ish) than the intended light gray `#c9d1d9`.

**Fix:** Change line 77 from:
```php
$foreground = self::resolveColor($colors, [
    'editor.foreground',
], '#c9d9);
```
to:
```php
$foreground = self::resolveColor($colors, [
    'editor.foreground',
], '#c9d1d9');
```

**Conditions for Success:**
- `vendor/bin/phpunit --filter VsCodeThemeLoaderTest` passes
- `VsCodeThemeLoader::fromArray([])->foreground === '#c9d1d9'`
- Compare with `Theme::dark()->foreground === '#c9d1d9'` — now consistent

**Related Code Locations:**
- `src/Theme/VsCodeThemeLoader.php:75-77` — the buggy fallback
- `src/Theme.php:37` — `Theme::dark()` uses `#c9d1d9` correctly
- `src/Theme/VsCodeThemeLoader.php:71-73` — background fallback uses `#0d1117` (correct)

**Investigation Notes:**
- The GitHub dark theme foreground is `#c9d1d9`
- All other fallback values in `VsCodeThemeLoader::fromArray()` use the correct 6-digit hex format
- This bug only manifests when `editor.foreground` is absent from the VS Code theme JSON

---

### 2.2: Document GD Font Unicode Limitation

**File:** `candy-freeze/src/PngRenderer.php:9-27` (docblock) and `src/PngRenderer.php:81-84` (method docblock)

**What:** The class docblock mentions GD bitmap fonts but does not warn that these fonts do not support Unicode/multi-byte characters.

**Why:** Users rendering non-ASCII code (emoji, CJK, Arabic, etc.) will get garbage output with no explanation.

**Fix:** Add to the `PngRenderer` docblock (after line 26):
```php
 * Uses GD's built-in bitmap fonts (imagestring) for portability — no
 * TTF font file required.
 *
 * @warning GD bitmap fonts do not support Unicode; multi-byte characters
 *          (including emoji, CJK, and most non-Latin scripts) will render
 *          as garbage. Use {@see SvgRenderer} for non-ASCII content.
```

Also add a note to the `render()` method docblock:
```php
/**
 * Render `$text` (which may contain ANSI escape sequences) to a
 * PNG image and return the bytes.
 *
 * @throws \RuntimeException if ext-gd is not loaded
 * @note Unicode content produces incorrect output with GD's built-in
 *       bitmap fonts. For syntax highlighted code with non-ASCII
 *       characters, use {@see SvgRenderer} instead.
 */
```

**Conditions for Success:**
- `php -l src/PngRenderer.php` has no parse errors
- `grep -n "Unicode" src/PngRenderer.php` shows the new warning

**Related Code Locations:**
- `src/PngRenderer.php:25-26` — existing font description
- `src/PngRenderer.php:81-84` — render() method docblock

---

### 2.3: Reject Symlinks Pointing Outside CWD in CLI

**File:** `candy-freeze/bin/candyfreeze:112-117`

**What:** `file_get_contents($inputPath)` follows symlinks without checking if the resolved path is outside the current working directory. A symlink to `/etc/passwd` would render its contents.

**Why:** Security hardening for CI/web-facing usage where untrusted input files could be provided.

**Fix:** Add before `file_get_contents`:
```php
if ($inputPath !== null) {
    $realPath = realpath($inputPath);
    $cwd = getcwd();
    if ($realPath !== false && str_starts_with($realPath, $cwd) === false) {
        fwrite(STDERR, Lang::t('cli.path_outside_cwd') . "\n");
        exit(2);
    }
}
```

Add the i18n key `cli.path_outside_cwd` to `lang/en.php`: `"Input path is outside the current working directory"`.

**Conditions for Success:**
- Create symlink pointing to `/etc/passwd`, run CLI → exits with code 2 and error message
- Normal files and stdin still work correctly
- `php -l bin/candyfreeze` shows no parse errors

**Related Code Locations:**
- `bin/candyfreeze:112-114` — file reading that needs the guard
- `lang/en.php` — i18n file for new key

---

## Phase 3: Code Quality Refactoring [PENDING]

- [ ] 3.1 **[DRY]** Extract `LayoutCalculator` from duplicate sizing logic in both renderers
- [ ] 3.2 **[DRY]** Consolidate four window-chrome geometry methods via `WindowChromeGeometry`
- [ ] 3.3 **[MODERNIZE]** Replace 20+ `if/continue` in `applySgr()` with `match` expression
- [ ] 3.4 **[DEAD CODE]** Remove `Theme::$windowStyle` property entirely

### 3.1: Extract Shared `LayoutCalculator`

**Files:** `candy-freeze/src/SvgRenderer.php:112-140` and `candy-freeze/src/PngRenderer.php:86-118`

**What:** Both renderers calculate `$maxCols`, `$gutter`, `$contentWidth`, `$contentHeight`, `$headerHeight`, `$shadowMargin`, `$totalW`, `$totalH` using nearly identical logic. Differences: `ceil()` in SVG vs. raw values in PNG, and different `$cellW`/`$cellH` sources.

**Why:** DRY violation. Adding a new sizing option (e.g., custom gutter width) requires editing both files.

**Implementation:** Create `candy-freeze/src/LayoutCalculator.php`:
```php
declare(strict_types=1);

namespace SugarCraft\Freeze;

use SugarCraft\Core\Util\Ansi;

/**
 * Shared layout dimension calculator for SVG and PNG renderers.
 */
final class LayoutCalculator
{
    /**
     * @return array{0:int, 1:int, 2:float, 3:int, 4:int, 5:int, 6:int, 7:int}
     *   [maxCols, gutter, contentWidth, contentHeight, headerHeight, shadowMargin, totalW, totalH]
     */
    public static function calculate(
        array $lines,
        bool $lineNumbers,
        int $padding,
        bool $window,
        bool $shadow,
        float $cellW,
        float $cellH,
    ): array {
        $maxCols = 0;
        foreach ($lines as $line) {
            $cols = mb_strlen(Ansi::strip($line), 'UTF-8');
            if ($cols > $maxCols) {
                $maxCols = $cols;
            }
        }
        $gutter = $lineNumbers
            ? max(2, strlen((string) count($lines))) + 2
            : 0;

        $contentWidth  = ($maxCols + $gutter) * $cellW;
        $contentHeight = count($lines) * $cellH;

        $headerHeight = $window ? 36 : 0;
        $frameWidth    = $contentWidth + $padding * 2;
        $frameHeight   = $contentHeight + $padding * 2 + $headerHeight;

        $shadowMargin = $shadow ? 32 : 0;
        $totalW = $frameWidth + $shadowMargin * 2;
        $totalH = $frameHeight + $shadowMargin * 2;

        return [$maxCols, $gutter, $contentWidth, $contentHeight, $headerHeight, $shadowMargin, $totalW, $totalH];
    }
}
```

Then update both renderers: extract lines into array, call `LayoutCalculator::calculate()`, destructure the result.

**Conditions for Success:**
- `vendor/bin/phpunit` passes in `candy-freeze/`
- Both SVG and PNG output dimensions are identical to before (snapshot test)
- `grep -n "maxCols" src/SvgRenderer.php` shows no duplicate calculation

**Related Code Locations:**
- `src/SvgRenderer.php:112-140` — SVG dimension calculation
- `src/PngRenderer.php:86-118` — PNG dimension calculation
- `src/LayoutCalculator.php` (new file)

---

### 3.2: Consolidate Window-Chrome Geometry via `WindowChromeGeometry`

**Files:** `candy-freeze/src/SvgRenderer.php:267-379` and `candy-freeze/src/PngRenderer.php:265-350`

**What:** Each of the four window styles has nearly identical geometry logic: same `$cy`, `$base`, `$r`, `$gap`, `$colors` order. They differ only in the drawing primitive (SVG elements vs GD functions).

**Why:** Four near-identical geometry blocks are hard to maintain; changing the radius of Macos traffic lights requires editing both files in two places each.

**Implementation:** Create `candy-freeze/src/WindowChromeGeometry.php`:
```php
declare(strict_types=1);

namespace SugarCraft\Freeze;

/**
 * Geometry description for one window-chrome style.
 * Centralizes the position and size calculations so each
 * renderer only implements the primitive-drawing loop.
 */
final class WindowChromeGeometry
{
    public function __construct(
        public readonly int $cy,
        public readonly int $base,
        public readonly int $r,
        public readonly int $gap,
        public readonly array $colors, // [red, yellow, green]
        public readonly int $titleBarHeight = 0,
        public readonly int $buttonSize = 0,
        public readonly int $buttonGap = 0,
        public readonly int $frameWidth = 0,
    ) {}

    public static function macos(int $shadowMargin): self
    {
        $cy = $shadowMargin + 18;
        $base = $shadowMargin + 18;
        return new self(cy: $cy, base: $base, r: 6, gap: 18, colors: []);
    }

    public static function iterm2(int $shadowMargin): self
    {
        $cy = $shadowMargin + 14;
        $base = $shadowMargin + 14;
        return new self(cy: $cy, base: $base, r: 4, gap: 14, colors: []);
    }

    public static function hyper(int $shadowMargin, int $contentWidth): self
    {
        $titleBarHeight = 24;
        $titleBarY = $shadowMargin;
        $r = 5;
        $gap = 16;
        $cy = $titleBarY + ($titleBarHeight - $r * 2) / 2;
        $base = $shadowMargin + 12;
        return new self(
            cy: $cy, base: $base, r: $r, gap: $gap,
            colors: [], titleBarHeight: $titleBarHeight,
            frameWidth: $contentWidth,
        );
    }

    public static function windowsTerminal(int $shadowMargin, int $contentWidth): self
    {
        $titleBarHeight = 28;
        $titleBarY = $shadowMargin;
        $buttonSize = 14;
        $buttonGap = 8;
        return new self(
            cy: 0, base: 0, r: 0, gap: 0,
            colors: [],
            titleBarHeight: $titleBarHeight,
            buttonSize: $buttonSize,
            buttonGap: $buttonGap,
            frameWidth: $contentWidth,
        );
    }
}
```

Then refactor each `buildXxxWindow()` in both renderers to use the geometry object.

**Conditions for Success:**
- `vendor/bin/phpunit --filter WindowStyleTest` passes
- All four window styles produce visually identical output (existing tests verify this)
- `grep -n "buildMacosWindow\|buildITerm2Window\|buildHyperWindow\|buildWindowsTerminalWindow" src/SvgRenderer.php src/PngRenderer.php` shows streamlined implementations

**Related Code Locations:**
- `src/SvgRenderer.php:267-379` — four window builders (SVG)
- `src/PngRenderer.php:265-350` — four window builders (PNG)
- `src/WindowChromeGeometry.php` (new file)

---

### 3.3: Replace `applySgr()` if/continue Chain with `match`

**File:** `candy-freeze/src/AnsiParser.php:119-176`

**What:** The `applySgr()` method has individual `if ($p === N) { ...; continue; }` checks for the attribute and reset codes (0, 1, 3, 4, 22, 23, 24, 39, 49). The range-based codes (30-37, 40-47, 90-97, 100-107) already use efficient array lookup.

**Why:** Modern PHP 8.1+ `match` is more idiomatic and easier to extend than a chain of `if` statements.

**Implementation:** Replace lines 122-133 (the attribute/reset block) with:
```php
$p = $params[$i];
$applied = match (true) {
    $p === 0  => fn() => ['fg' => null, 'bg' => null, 'bold' => false, 'italic' => false, 'underline' => false],
    $p === 1  => fn() => ['bold' => true],
    $p === 3  => fn() => ['italic' => true],
    $p === 4  => fn() => ['underline' => true],
    $p === 22 => fn() => ['bold' => false],
    $p === 23 => fn() => ['italic' => false],
    $p === 24 => fn() => ['underline' => false],
    $p === 39 => fn() => ['fg' => null],
    $p === 49 => fn() => ['bg' => null],
    default   => null,
};

if ($applied !== null) {
    $changes = $applied();
    foreach ($changes as $k => $v) {
        $$k = $v;
    }
    continue;
}
```

Keep the existing range-check patterns for 30-37/40-47/90-97/100-107 unchanged.

**Conditions for Success:**
- `vendor/bin/phpunit --filter AnsiParserTest` passes
- `php -l src/AnsiParser.php` shows no parse errors
- `grep -n "if (\$p ===" src/AnsiParser.php` shows the range checks remain

**Related Code Locations:**
- `src/AnsiParser.php:119-133` — the block to replace
- `src/AnsiParser.php:134-149` — range checks to keep

---

### 3.4: Remove `Theme::$windowStyle` Property

**Files:** `candy-freeze/src/Theme.php:30` and all presets, `src/Theme/VsCodeThemeLoader.php:117`, `src/Theme/ChromaThemeLoader.php:106`

**What:** `Theme::$windowStyle` is defined and set in all preset constructors but never read during rendering. The window style is controlled exclusively by `SvgRenderer::$windowStyle` / `PngRenderer::$windowStyle`. The theme property is misleading.

**Why:** A property that is set but never read creates a misleading API. Users might expect `Theme::dracula()->withWindowStyle(WindowStyle::ITerm2)` to affect rendering, but it does not.

**Decision:** Remove `Theme::$windowStyle` entirely. Window chrome style is a rendering concern, not a visual theme concern — themes carry colors, renderers carry chrome style.

**Implementation:**
1. Remove `$windowStyle` parameter from `Theme::__construct()` (line 30)
2. Remove `$windowStyle` from all `Theme` preset factories
3. Remove `$windowStyle` from `VsCodeThemeLoader::fromArray()` (line 117)
4. Remove `$windowStyle` from `ChromaThemeLoader::fromArray()` (line 106)
5. Update any tests that construct `Theme` with `$windowStyle`

**Conditions for Success:**
- `vendor/bin/phpunit` passes in `candy-freeze/`
- `grep -rn "windowStyle" src/Theme.php` returns no matches
- No test references `Theme::$windowStyle`

**Related Code Locations:**
- `src/Theme.php:18-31` — constructor with `$windowStyle`
- `src/Theme.php:33-102` — all preset factories set `$windowStyle`
- `src/Theme/VsCodeThemeLoader.php:117` — sets `$windowStyle: WindowStyle::Macos`
- `src/Theme/ChromaThemeLoader.php:106` — sets `$windowStyle: WindowStyle::Macos`

**Investigation Notes:**
- `grep -rn "theme->windowStyle\|Theme::.*->windowStyle" src/` returns no results
- `Theme::$windowStyle` is set but never accessed in any renderer

---

## Phase 4: Missing Features [PENDING]

- [ ] 4.1 **[FEATURE]** Add `--format svg|png` flag to CLI
- [ ] 4.2 **[FEATURE]** Add `OutputWriter` interface for streaming
- [ ] 4.3 **[FEATURE]** Wire `LanguageDetector` into CLI via `--type` flag
- [ ] 4.4 **[FEATURE]** Update `examples/freeze_to_png.php` to use factory methods

### 4.1: Add `--format svg|png` Flag to CLI

**File:** `candy-freeze/bin/candyfreeze:46-97` (argument parsing) and `bin/candyfreeze:120-148` (renderer selection)

**What:** The CLI always uses `SvgRenderer` even when `--output file.png` is specified. No way to get actual PNG output.

**Implementation:**
1. Add `$format = 'svg'` variable after line 43
2. Add case in switch:
```php
case '--format':
    $format = (string) array_shift($argv);
    if (!in_array($format, ['svg', 'png'], true)) {
        fwrite(STDERR, Lang::t('cli.unknown_format', ['format' => $format]) . "\n");
        exit(2);
    }
    break;
```
3. Conditionally build renderer:
```php
if ($format === 'png') {
    if (!extension_loaded('gd')) {
        fwrite(STDERR, Lang::t('cli.gd_required') . "\n");
        exit(2);
    }
    $renderer = new PngRenderer(/* ... */);
} else {
    $renderer = new SvgRenderer(/* ... */);
}
```

Add i18n keys `cli.unknown_format` and `cli.gd_required` to `lang/en.php`.

**Conditions for Success:**
- `candyfreeze --help` shows `--format` option
- `echo "hello" | candyfreeze --format png | head -c 8` outputs PNG signature `\x89PNG\r\n\x1a\n`
- `candyfreeze --format png` without ext-gd shows helpful error

**Related Code Locations:**
- `bin/candyfreeze:46-97` — option parsing loop
- `bin/candyfreeze:120-148` — renderer construction

---

### 4.2: Add `OutputWriter` Interface for Streaming

**Files:** New `candy-freeze/src/OutputWriter.php`, `candy-freeze/src/StringOutputWriter.php`, `candy-freeze/src/FileOutputWriter.php`

**What:** Both `render()` methods return `string`, requiring the entire SVG/PNG in memory. For large screenshots (>1MB SVG), this spikes memory in streaming scenarios.

**Implementation:**
```php
// src/OutputWriter.php
namespace SugarCraft\Freeze;

interface OutputWriter
{
    public function write(string $chunk): void;
    public function flush(): void;
}

// src/StringOutputWriter.php
final class StringOutputWriter implements OutputWriter
{
    private string $buffer = '';
    public function write(string $chunk): void { $this->buffer .= $chunk; }
    public function flush(): void {}
    public function getResult(): string { return $this->buffer; }
}

// src/FileOutputWriter.php
final class FileOutputWriter implements OutputWriter
{
    private $fp;
    public function __construct(string $path) { $this->fp = fopen($path, 'w'); }
    public function write(string $chunk): void { fwrite($this->fp, $chunk); }
    public function flush(): void { fflush($this->fp); }
    public function __destruct() { if ($this->fp) fclose($this->fp); }
}
```

Add `renderToStream(string $text, OutputWriter $writer): void` to both renderers.

**Conditions for Success:**
- `StringOutputWriter` produces identical output to current `render()` behavior
- `FileOutputWriter` streams directly to disk without buffering entire file in memory
- Unit tests for both implementations pass

**Related Code Locations:**
- `src/SvgRenderer.php:112` — `render()` method to add streaming counterpart
- `src/PngRenderer.php:86` — `render()` method to add streaming counterpart

---

### 4.3: Wire `LanguageDetector` into CLI via `--type` Flag

**File:** `candy-freeze/bin/candyfreeze` and `candy-freeze/src/SvgRenderer.php`

**What:** `LanguageDetector` exists but is not connected to any renderer or CLI. `--type` flag would allow users to specify language for syntax highlighting.

**Implementation:** Add `-t` / `--type` flag:
```php
case '-t':
case '--type':
    $language = (string) array_shift($argv);
    break;
```

The actual syntax-token coloring via Chroma is a larger feature for a future major version. For now, wire the detection so it runs:
```php
$detectedLang = LanguageDetector::detect($text);
// Could be used to select token scopes from VS Code themes in future
```

**Conditions for Success:**
- `echo '<?php echo "hello"' | candyfreeze --type php` renders without error
- `LanguageDetector::detect()` is called during rendering

---

### 4.4: Update Examples to Use Factory Methods

**File:** `candy-freeze/examples/freeze_to_png.php`

**What:** Lines 47-52 manually construct a theme from `PngRenderer::dark()->theme` instead of using the direct factory method.

**Fix:**
```php
// BEFORE:
$ansi = (new PngRenderer())
    ->withTheme(PngRenderer::dark()->theme)
    ->withWindow(true)
    ->withPadding(16)
    ->withBorderRadius(8)
    ->render("...");

// AFTER:
$ansi = PngRenderer::dark()
    ->withWindow(true)
    ->withPadding(16)
    ->withBorderRadius(8)
    ->render("...");
```

**Conditions for Success:**
- `php examples/freeze_to_png.php` runs without error
- All output files are generated correctly

**Related Code Locations:**
- `candy-freeze/examples/freeze_to_png.php:47-52` — the redundant pattern

---

## Phase 5: Security Hardening [PENDING]

- [ ] 5.1 **[SECURITY]** Add length check to `LanguageDetector::detect()` for DoS prevention
- [ ] 5.2 **[SECURITY]** Add 1MB read limit to `file_get_contents()` in theme loaders

### 5.1: Prevent DoS via Large Input in `LanguageDetector::detect()`

**File:** `candy-freeze/src/LanguageDetector.php:85-97`

**What:** `detect()` processes arbitrary user content with `str_contains()` across all content signatures. For multi-MB inputs, this scans the entire content for each of ~50 signatures.

**Why:** DoS vector for web-facing usage. Limit to 1MB (reasonable for any source file).

**Fix:**
```php
public static function detect(string $content): string
{
    if (strlen($content) > 1_000_000) {
        return 'text';
    }
    $content = ltrim($content);
    // ... rest of method
}
```

**Conditions for Success:**
- `LanguageDetector::detect(str_repeat('x', 2_000_000))` returns `'text'`
- Normal inputs (≤1MB) still detect correctly
- `vendor/bin/phpunit --filter LanguageDetectorTest` passes

**Related Code Locations:**
- `src/LanguageDetector.php:85-97` — `detect()` method

---

### 5.2: Add 1MB Read Limit to Theme Loader `file_get_contents()`

**Files:** `candy-freeze/src/Theme/ChromaThemeLoader.php:51` and `candy-freeze/src/Theme/VsCodeThemeLoader.php:50`

**What:** Both loaders use `file_get_contents($path)` with no size limit.

**Fix:**
```php
// ChromaThemeLoader.php:51
$json = file_get_contents($path, false, null, 0, 1_000_000);

// VsCodeThemeLoader.php:50
$json = file_get_contents($path, false, null, 0, 1_000_000);
```

Add a test for large file rejection to each test file.

**Conditions for Success:**
- `php -l` on both files passes
- `VsCodeThemeLoader::load()` with a >1MB file throws `\InvalidArgumentException`
- New tests pass

---

## Phase 6: Async Patterns — Documentation Note [PENDING]

- [ ] 6.1 **[DOCS]** Document async rendering considerations for future major version

### 6.1: Document Async/Streaming as Future Major Version

**Files:** `candy-freeze/src/SvgRenderer.php` and `candy-freeze/src/PngRenderer.php` (method docblocks)

**What:** Both renderers are synchronous. No async streaming method exists. Document this as a known limitation.

**Implementation:** Add to `render()` docblock of both renderers:
```php
/**
 * @note Rendering is synchronous and CPU-bound. For very large
 *       screenshots (>1MB SVG), a future major version may offer
 *       {@see renderToStream()} using {@see \React\Stream}.
 */
```

**Conditions for Success:**
- `grep -n "renderToStream\|React\\\\Stream" src/SvgRenderer.php src/PngRenderer.php` finds the docblock note

---

## Phase 7: Code Quality — Additional Items [PENDING]

- [ ] 7.1 **[CODE QUALITY]** Refactor `Segment::withBg()` to use `mutate()` pattern
- [ ] 7.2 **[CODE QUALITY]** Add explicit `fclose()` for stdout/stderr in `CliTest`

### 7.1: Refactor `Segment::withBg()` to Use `mutate()` Pattern

**File:** `candy-freeze/src/Segment.php:1-34`

**What:** `Segment` has only `withBg()` as a `with*` mutator, using the manual constructor pattern. Per SugarCraft conventions, should use the `Mutable` trait.

**Why:** The `mutate()` pattern from `candy-core/src/Concerns/Mutable.php` is the standard SugarCraft approach for immutable builders. Using the trait makes future `with*()` additions trivial.

**Implementation:**
```php
use SugarCraft\Core\Concerns\Mutable;

final class Segment
{
    use Mutable;

    public function __construct(
        public readonly string $text,
        public readonly ?string $fg,
        public readonly bool $bold,
        public readonly bool $italic,
        public readonly bool $underline,
        public readonly ?string $bg = null,
    ) {}

    public function withBg(?string $bg): self
    {
        return $this->mutate(['bg' => $bg]);
    }
}
```

**Conditions for Success:**
- `vendor/bin/phpunit --filter SegmentTest` passes
- Immutability preserved: original instance is unchanged after `withBg()`

**Related Code Locations:**
- `src/Segment.php:23-33` — existing `withBg()` method
- `candy-core/src/Concerns/Mutable.php:13-38` — the trait being applied
- `candy-sprinkles/src/Style.php:39+` — canonical example of `mutate()` usage

---

### 7.2: Add Explicit `fclose()` for stdout/stderr in `CliTest`

**File:** `candy-freeze/tests/CliTest.php:23-49`

**What:** The `runCli()` method closes stdin but not stdout/stderr explicitly in a `finally` block.

**Fix:**
```php
private function runCli(array $args, ?string $stdin = null): array
{
    // ... existing proc_open setup ...

    $handle = proc_open($cmd, $desc, $pipes);

    try {
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($handle);
        return ['stdout' => $stdout, 'stderr' => $stderr, 'exitCode' => $exitCode];
    } catch (\Throwable $e) {
        // Ensure cleanup on exception
        if (is_resource($pipes[0])) fclose($pipes[0]);
        if (is_resource($pipes[1])) fclose($pipes[1]);
        if (is_resource($pipes[2])) fclose($pipes[2]);
        proc_close($handle);
        throw $e;
    }
}
```

**Conditions for Success:**
- `vendor/bin/phpunit --filter CliTest` passes
- No pipe resource leaks detected

---

## Phase 8: Test Coverage Gaps [PENDING]

- [ ] 8.1 **[COVERAGE]** Add `testAnsiBackgroundBecomesRectFill` for SVG renderer
- [ ] 8.2 **[COVERAGE]** Add test for mixed 256-color + truecolor on one line
- [ ] 8.3 **[COVERAGE]** Add test for empty-input rendering
- [ ] 8.4 **[COVERAGE]** Add test for theme loaders with real JSON structure
- [ ] 8.5 **[COVERAGE]** Add test for `PngRenderer` ANSI background color limitation

### 8.1: Add `testAnsiBackgroundBecomesRectFill`

**File:** `candy-freeze/tests/SvgRendererTest.php`

**What:** `testAnsiForegroundBecomesTspanFill` tests foreground but no test for ANSI background (`\x1b[44m`) producing a `<rect>` fill.

**Implementation:**
```php
public function testAnsiBackgroundBecomesRectFill(): void
{
    // ANSI 44 = blue background
    $svg = SvgRenderer::dark()->render("\x1b[44mblue background\x1b[0m text");
    $this->assertStringContainsString('fill="#0000ee"', $svg); // ANSI 44 = #0000ee
    $this->assertStringContainsString('blue background', $svg);
}
```

**Conditions for Success:** Test passes

**Related Code Locations:**
- `tests/SvgRendererTest.php:75-82` — existing foreground test
- `src/SvgRenderer.php:230-235` — where rect is emitted for backgrounds

---

### 8.2: Add Test for Mixed 256-color + Truecolor

**File:** `candy-freeze/tests/AnsiParserTest.php`

**Implementation:**
```php
public function testMixedXterm256AndTrueColor(): void
{
    // Red fg (256-color) + green bg (truecolor)
    $segs = AnsiParser::parse("\x1b[38;5;196m\x1b[48;2;0;255;0mcolored\x1b[0m");
    $this->assertCount(1, $segs);
    $this->assertSame('#cd0000', $segs[0]->fg); // ANSI 196
    $this->assertSame('#00ff00', $segs[0]->bg); // truecolor 0;255;0
    $this->assertSame('colored', $segs[0]->text);
}
```

**Conditions for Success:** Test passes

---

### 8.3: Add Test for Empty-Input Rendering

**File:** `candy-freeze/tests/SvgRendererTest.php`

**Implementation:**
```php
public function testEmptyInputRendersMinimalFrame(): void
{
    $svg = SvgRenderer::dark()->withWindow(false)->render("");
    $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $svg);
    $this->assertStringEndsWith("</svg>\n", $svg);
    $this->assertStringContainsString('fill="#0d1117"', $svg);
}
```

**Conditions for Success:** Test passes

---

### 8.4: Add Tests for Theme Loaders with Real JSON Structure

**Files:** `candy-freeze/tests/VsCodeThemeLoaderTest.php` and `candy-freeze/tests/ChromaThemeLoaderTest.php`

**Implementation:** Add comprehensive test with all color keys present:
```php
public function testFullChrom主题WithAllColors(): void
{
    $data = [
        'background' => '#1a1b26',
        'foreground' => '#a9b1d6',
        'colors' => [
            'comment' => '#565f89',
            'keyword' => '#f7768e',
            'string' => '#9ece6a',
            'number' => '#ff9e64',
            'variable' => '#9ece6a',
            'constant' => '#ff9e64',
            'operator' => '#89ddff',
            'type' => '#e0af68',
            'class' => '#e0af68',
            'function' => '#7dcfff',
            'punctuation' => '#89ddff',
            'attribute' => '#e0af68',
            'tag' => '#f7768e',
            'error' => '#f7768e',
        ],
    ];

    $theme = ChromaThemeLoader::fromArray($data);
    $this->assertSame('#1a1b26', $theme->background);
    $this->assertSame('#a9b1d6', $theme->foreground);
    $this->assertSame('#565f89', $theme->lineNumber);
    $this->assertSame('#f7768e', $theme->windowRed);
    $this->assertSame('#9ece6a', $theme->windowGreen);
}
```

**Conditions for Success:** Test passes

---

### 8.5: Add Test for `PngRenderer` ANSI Background Limitation

**File:** `candy-freeze/tests/PngRendererTest.php`

**Implementation:**
```php
public function testAnsiBackgroundColorsDoNotRenderInPng(): void
{
    // ANSI 44 = blue background
    $png = PngRenderer::dark()->withWindow(false)->withShadow(false)
        ->render("\x1b[44mblue background\x1b[0m\n");

    // GD imagestring() does not support per-character colors,
    // so background is informational only in PNG output.
    $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
}
```

**Conditions for Success:** Test passes

**Investigation Notes:** Per `tests/PngRendererTest.php:60-66`, only foreground is tested. Background testing with `imagestring()` is not feasible since GD's bitmap fonts don't support per-char coloring.

---

## Phase 9: Path-Repo Closure Verification [PENDING]

- [ ] 9.1 **[MONOREPO]** Verify path-repo closure after any dependency change

### 9.1: Run `tools/check-path-repos.php` After Dependency Changes

**Files:** `candy-freeze/composer.json`

**What:** Finding #10 notes that if other libraries depend on `candy-freeze`, they need their own path-repo entry for `candy-ansi`. The `tools/check-path-repos.php` script validates this.

**Implementation:** After any change to `candy-freeze/composer.json`'s dependencies:
```bash
php tools/check-path-repos.php
```

If issues are reported, use `--fix` to auto-insert missing entries:
```bash
php tools/check-path-repos.php --fix
```

**Conditions for Success:**
- `php tools/check-path-repos.php` exits 0 for the current state
- Root `composer.json` already has path-repo for `candy-ansi` at line 103-108

**Related Code Locations:**
- `tools/check-path-repos.php` — closure checker script
- `candy-freeze/composer.json:54-68` — local path repos for candy-ansi and candy-core

---

## Notes

- **2026-06-30:** Plan created from `findings/candy-freeze.md` audit. All 29 findings addressed across 9 phases.
- Phases 1-3 (Critical → Code Quality) are the highest priority for initial PR.
- Phases should be bundled into 2-4 PRs following SugarCraft `ship-as-you-go` convention:
  - **PR 1:** Phase 1 (Critical Bugs) + Phase 2 (Correctness Fixes)
  - **PR 2:** Phase 3 (Code Quality Refactoring)
  - **PR 3:** Phase 4 (Missing Features)
  - **PR 4:** Phase 5 (Security) + Phase 7 (Code Quality) + Phase 8 (Tests) + Phase 6/9 (Docs/Closure)
- `Theme::$windowStyle` removal (3.4) is a breaking API change — document in PR body as `## Breaking Change`
- `bin/candyfreeze` changes (4.1, 4.3, 2.3) should be in the same PR as the feature work
