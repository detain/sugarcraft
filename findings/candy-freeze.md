# Code Review: candy-freeze

## Overview

candy-freeze is a PHP port of charmbracelet/freeze that renders code or terminal output to SVG (and optional PNG via ext-gd) screenshots. It features macOS-style window chrome ("traffic lights"), drop shadows, line numbers, inline ANSI SGR color parsing, theme loaders for VS Code and Chroma JSON formats, and a CLI tool. The library is built for PHP 8.3+, uses immutable fluent builders, and delegates ANSI parsing to the sugarcraft/candy-ansi dependency.

## Files Reviewed

| File | Lines | Purpose |
|------|-------|---------|
| `src/AnsiParser.php` | 209 | ANSI SGR-to-Segment converter |
| `src/LanguageDetector.php` | 226 | Heuristic code language detection |
| `src/Lang.php` | 22 | i18n facade |
| `src/PngRenderer.php` | 373 | GD-based PNG renderer |
| `src/Segment.php` | 34 | Immutable styled-text value object |
| `src/SgrState.php` | 21 | Mutable SGR parse state |
| `src/SvgRenderer.php` | 413 | SVG renderer (primary output) |
| `src/Theme.php` | 103 | Theme value object with presets |
| `src/Theme/ChromaThemeLoader.php` | 141 | Chroma JSON → Theme |
| `src/Theme/VsCodeThemeLoader.php` | 214 | VS Code JSON → Theme |
| `src/WindowStyle.php` | 19 | Window chrome enum |
| `bin/candyfreeze` | 157 | CLI executable |
| **Total** | **~1,992** | |

---

## Findings

### Critical Issues

#### 1. `$cache` in `PngRenderer::allocateColor()` is never cleared — memory leak for long-running processes

**File:** `src/PngRenderer.php:233-251`

```php
private function allocateColor(\GdImage $img, string $hex): int
{
    static $cache = [];
    $oid = spl_object_id($img);
    $key = $hex;
    if (isset($cache[$oid][$key])) {
        return $cache[$oid][$key];
    }
    // ...
    $cache[$oid][$key] = $color;
    return $color;
}
```

The `$cache` is a static variable that grows without bound across:
- Multiple `render()` calls on the same `PngRenderer` instance
- Multiple images created via `imagecreatetruecolor()` (each gets a new `spl_object_id`)
- The cache entries for old images that have since been `imagedestroy()`'d are orphaned

For a long-running CLI tool or ReactPHP event loop rendering many images, this is a definite memory leak.

**Recommendation:** Either (a) make the cache non-static and store it as an instance property on `PngRenderer`, (b) implement an `imagedestroy()` callback, or (c) at minimum use `WeakMap` keyed by the `\GdImage` object ID so entries are automatically cleaned up when the image is destroyed.

---

#### 2. `VsCodeThemeLoader::load()` has a duplicate `throw` keyword

**File:** `src/Theme/VsCodeThemeLoader.php:52`

```php
$json = file_get_contents($path);
if ($json === false) {
    throw throw new \InvalidArgumentException("Failed to read VS Code theme file: {$path}");
    //    ^^^^^ BUG: duplicate `throw`
}
```

This will cause a fatal error: "Cannot throw exception that was not previously caught" (in PHP 8, `throw` as an expression requires an exception instance). Actually, in PHP 8.x `throw` is an expression and `throw new \InvalidArgumentException` works fine — but the duplicate `throw` is still a bug. It should be:

```php
throw new \InvalidArgumentException("Failed to read VS Code theme file: {$path}");
```

**Recommendation:** Remove the redundant `throw`.

---

#### 3. `$buttonHoverColors` in `SvgRenderer` is declared but never used

**File:** `src/SvgRenderer.php:316`

```php
$buttonColors = ['#444444', '#444444', '#444444'];
$buttonHoverColors = ['#555555', '#555555', '#e81123'];
// ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^ assigned but never read
$buttonY = $titleBarY + ($titleBarHeight - $buttonSize) / 2;
```

Same issue in `PngRenderer::buildWindowsTerminalWindow()` at line 299 — `$buttonColorsHover` is declared but never used. These appear to be scaffolding from when hover interactions were planned but were never wired up. Dead code should be removed.

**Recommendation:** Delete the unused `$buttonHoverColors` variable from both `SvgRenderer.php:316` and `PngRenderer.php:299`.

---

### Performance Issues

#### 4. `AnsiParser::parse()` recreates the anonymous Handler class on every call

**File:** `src/AnsiParser.php:59-179`

Every call to `AnsiParser::parse($line)` creates a brand new anonymous class instance plus new closures for `$flush` and the `$handler`. For batch rendering (e.g., a pipeline processing hundreds of lines), this is unnecessary allocation overhead.

The handler state (`$state`, `$textBuf`, `$segments`) is passed by reference, so the handler doesn't need to be stateful across calls. However, the allocation itself could be avoided by making `parse()` reusable: accept an optional mutable parse-state object that carries the handler, and reuse the parser+handler pair across multiple lines.

**Recommendation:** Consider a `ParserContext` object that holds reusable state for batch processing, allowing the same parser+handler to process multiple lines without re-allocation.

---

#### 5. `AnsiParser::parse()` iterates the `$params` array twice when handling xterm-256 and true-color SGR codes

**File:** `src/AnsiParser.php:150-175`

When handling `\x1b[38;5;Nm` (256-color foreground), the code does `$i += 2` inside the loop. But the outer `for` loop also increments `$i` each iteration, effectively consuming 3 params for the price of 1 loop iteration. This works correctly but is subtle. More importantly, when the loop encounters a bare `38` or `48` without enough subsequent params, the `if (!isset($params[$i+1]))` guard at lines 150 and 163 silently treats it as a no-op — which is correct behavior but slightly confusing.

**Minor:** The loop structure is correct but could be clearer with a labeled break or extracted into `applySgrParam()` for each param type.

---

#### 6. `SvgRenderer::render()` and `PngRenderer::render()` share duplicated dimension-calculation logic

**File:** `src/SvgRenderer.php:112-140` and `src/PngRenderer.php:86-118`

Both renderers calculate:
- `$maxCols` — longest line length (ANSI-stripped)
- `$gutter` — width for line-number column
- `$contentWidth` / `$contentHeight`
- `$headerHeight`
- `$shadowMargin`
- `$totalW` / `$totalH`

These are nearly identical blocks of code with slightly different variable names and `ceil()` in SVG vs. raw values in PNG. This violates DRY and means adding a new sizing option (e.g., custom gutter width) requires editing both files.

**Recommendation:** Extract to a shared `LayoutCalculator` class or at minimum a shared static method `LayoutCalculator::calculate(string $text, bool $lineNumbers, int $padding, bool $window, float $cellW, float $cellH): Layout`. The `Layout` result object is then passed to both renderers.

---

### Code Complexity / Refactoring Opportunities

#### 7. Four near-identical window-chrome builders should be consolidated

**Files:** `src/SvgRenderer.php:267-379` (buildMacosWindow, buildITerm2Window, buildHyperWindow, buildWindowsTerminalWindow) and `src/PngRenderer.php:265-350` (same four for PNG)

The SVG and PNG versions of each window style are structurally identical — same geometry (cx, cy, r, gap), same color array order, same primitive types (circle vs rect). They differ only in the output primitive (`<circle>`/`<rect>` in SVG vs `imagefilledellipse`/`imagefilledrectangle` in PNG).

Extract a `WindowChromeGeometry` value object:

```php
final class WindowChromeGeometry
{
    public function __construct(
        public readonly int $cy,
        public readonly int $base,
        public readonly int $r,
        public readonly int $gap,
        public readonly array $colors,
    ) {}
}
```

Then each renderer implements only the primitive-drawing loop, not the geometry calculation. Alternatively, a `Theme::windowControlGeometry(WindowStyle): WindowChromeGeometry` method would centralize the geometry decision.

---

#### 8. `AnsiParser::applySgr()` has 20 consecutive `if/continue` branches — should use `match`

**File:** `src/AnsiParser.php:119-176`

The `applySgr` method handles 20+ distinct SGR parameter codes with individual `if ($p === N) { ...; continue; }` checks. For the standard 3-digit codes (30-37, 40-47, 90-97), there is already a range check pattern. But the attribute codes (1, 3, 4, 22, 23, 24, 39, 49) and reset code (0) are all individual `if` statements.

**Recommendation:** Replace the attribute/reset block with a `match` expression:

```php
$p = $params[$i];
$applied = match (true) {
    $p === 0 => fn() => [$fg = null, $bg = null, $bold = false, $italic = false, $underline = false],
    $p === 1 => fn() => [$bold = true],
    // ...
    default => null,
};
```

Or at minimum group the range-based codes into lookup arrays and use a small `match` for the remainder.

---

#### 9. `Theme::windowStyle` property is stored but never used in rendering

**File:** `src/Theme.php:30` (property defined) vs `src/SvgRenderer.php` / `src/PngRenderer.php` (window style comes from `$this->windowStyle` on the renderer)

`Theme::$windowStyle` is defined and set in all preset constructors but it is never consulted during rendering. The window style is controlled exclusively by `SvgRenderer::$windowStyle` / `PngRenderer::$windowStyle`. This makes the `Theme::$windowStyle` property misleading — it implies it affects rendering when it doesn't.

**Recommendation:** Either remove `Theme::$windowStyle` entirely (if it shouldn't exist), or wire it into the renderers' defaults so `Theme::dracula()->withWindowStyle(WindowStyle::ITerm2)` actually changes the rendered chrome.

---

### Compatibility Issues

#### 10. Missing `composer.json` path-repo for `candy-ansi` in root `composer.json` of the monorepo

**File:** `candy-freeze/composer.json:54-68`

The library declares `sugarcraft/candy-ansi: dev-master` as a dependency and has a local path repo:

```json
{
    "type": "path",
    "url": "../candy-ansi",
    "options": { "symlink": true }
}
```

However, if other libraries in the monorepo depend on `candy-freeze`, they need their own path-repo entry for `candy-ansi`. This is a monorepo path-repo closure issue that `tools/check-path-repos.php` should catch, but it's worth documenting.

**Recommendation:** After any change to `candy-freeze/composer.json`'s dependencies, run `php tools/check-path-repos.php` to verify the full transitive closure is wired.

---

#### 11. `PngRenderer` uses GD built-in fonts — limitation not prominently documented

**File:** `src/PngRenderer.php:25-26` (docblock)

The PNG renderer uses `imagestring()` with GD's built-in bitmap fonts (font numbers 1-5). These fonts:
- Are not monospaced at the same aspect ratio as code
- Don't support Unicode (only Latin-1 characters)
- Have fixed sizes (5x8, 6x13, 7x13, 8x16)

This means multi-byte Unicode (including emoji in code comments) will render as garbage in PNG output. The SVG renderer handles this correctly via `mb_strlen` and TTF font embedding.

**Recommendation:** Add a note in `PngRenderer::$padding` docblock or `render()` docblock: "GD bitmap fonts do not support Unicode; use SvgRenderer for non-ASCII content."

---

### Missing Features

#### 12. No PNG output from the CLI

**File:** `bin/candyfreeze:120`

The CLI hardcodes `SvgRenderer` and produces only SVG output:

```php
$renderer = (new SvgRenderer(...));
$svg = $renderer->render($text);
```

The `--output` flag can write to a `.png` path but it will still be SVG content. There's no way to get PNG output from the CLI.

**Recommendation:** Add a `--format svg|png` flag to the CLI. When PNG is selected, use `PngRenderer` instead and check `extension_loaded('gd')` with a helpful error message. Also add a `withFormat(string $format): self` fluent method to the renderers' interface.

---

#### 13. No `OutputInterface` abstraction — hard to test or swap output backends

**Files:** `src/SvgRenderer.php`, `src/PngRenderer.php`

Both renderers return a `string` from `render()`. For streaming scenarios (writing directly to a file, or piping in ReactPHP), the entire SVG/PNG must be held in memory before being written. Large screenshots (>1MB SVG) will spike memory.

**Recommendation:** Add an `OutputWriter` interface:

```php
interface OutputWriter
{
    public function write(string $chunk): void;
    public function flush(): void;
}
```

With implementations: `StringOutputWriter` (current behavior), `FileOutputWriter`, and optionally `React\Stream\WritableStreamInterface` adapter for async pipelines.

---

#### 14. No support for the `--type` flag (charmbracelet/freeze feature parity)

**File:** `bin/candyfreeze`

charmbracelet/freeze supports `--type` to specify the language for syntax highlighting. The `LanguageDetector` class exists but is not connected to any renderer or the CLI. If a VS Code theme with `tokenColors` is loaded via `VsCodeThemeLoader`, those token colors could be used to colorize syntax — but currently the renderers only handle ANSI SGR, not syntax-token-based coloring.

**Recommendation:** Wire `LanguageDetector::detect($code)` into a syntax-highlightable `ChromaThemeLoader` pipeline. Even without full Chroma integration, passing the detected language as an argument to theme loaders could improve the output.

---

#### 15. No PNG theme-preset static factories on `PngRenderer`

**File:** `src/PngRenderer.php:61-65`

`PngRenderer` has static factory methods (`dark()`, `light()`, `dracula()`, `tokyoNight()`, `nord()`) but these are **not documented in the README** and are missing from the example scripts (`examples/freeze_to_png.php` manually constructs themes instead of using the factories).

**Recommendation:** Document the static factories in README and use them in examples for consistency with `SvgRenderer`.

---

### Security Issues

#### 16. No input validation on `$content` in `LanguageDetector::detect()`

**File:** `src/LanguageDetector.php:85-97`

The `detect()` method receives arbitrary user content and processes it with `str_contains()` across all content signatures. For extremely large inputs (e.g., multi-MB files), this scans the entire content for each signature — a potential DoS vector if used in a web-facing context.

**Recommendation:** Add an early length check:

```php
if (strlen($content) > 1_000_000) {
    return 'text'; // bail on extremely large inputs
}
```

---

#### 17. `file_get_contents()` used unsafely in theme loaders

**Files:** `src/Theme/ChromaThemeLoader.php:51`, `src/Theme/VsCodeThemeLoader.php:50`

Both loaders use `file_get_contents($path)` with no size limit. A maliciously large theme file could cause memory exhaustion. The `json_decode()` depth limit (512) is set, but there's no read-size limit.

**Recommendation:** Use `file_get_contents($path, false, null, 0, 1_000_000)` to cap at 1MB, or stream-parse with `json_decode()` on a limited read.

---

#### 18. CLI doesn't validate file input path for symlink attacks

**File:** `bin/candyfreeze:112-117`

```php
$text = $inputPath !== null
    ? @file_get_contents($inputPath)
    : stream_get_contents(STDIN);
```

If the input file is a symlink pointing to `/etc/passwd` or another sensitive file, the content is rendered (potentially exposing file contents in the output). In a CI context this is low-severity, but worth noting.

**Recommendation:** If `$inputPath` is a symlink and the real path is outside the current working directory, reject it with a clear error.

---

### Async Patterns (ReactPHP Ecosystem)

#### 19. Entire library is synchronous — no async rendering support

**Files:** `src/SvgRenderer.php`, `src/PngRenderer.php`

Both `render()` methods are CPU-bound synchronous operations (string concatenation, array iteration). For the ReactPHP ecosystem this is fine for batch workloads where the event loop yields between tasks. However, there is no `renderAsync()` method that could leverage `React\Stream` for streaming output.

**Recommendation:** For a future major version, consider adding:

```php
public function renderToStream(string $text): \React\Stream\ReadableStreamInterface
{
    // yields chunks of the SVG as they're built
}
```

This would allow memory-efficient rendering of very large screenshots in async pipelines without blocking the event loop for the entire render time.

---

#### 20. `PngRenderer::allocateColor()` static cache is not thread-safe

**File:** `src/PngRenderer.php:233`

In a ReactPHP worker pool (or任何 async context where multiple render calls happen concurrently on the same object or class), the static `$cache` array will have race conditions on read-write. PHP's runtime model (synchronous by default) makes this lower-risk today, but it would become an issue if `renderAsync()` (issue #19) is added.

**Recommendation:** Design the cache to be instance-scoped (not static) so each renderer instance has isolated state. If static is truly needed for performance, document the thread-safety assumption.

---

### Code Quality

#### 21. `VsCodeThemeLoader::fromArray()` uses wrong fallback for foreground color

**File:** `src/Theme/VsCodeThemeLoader.php:77`

```php
$foreground = self::resolveColor($colors, [
    'editor.foreground',
], '#c9d9);
//               ^^^^^ should be '#c9d1d9'
```

The fallback is `#c9d9` (6 chars) instead of `#c9d1d9` (8 chars). This is inconsistent with every other fallback in the file (which use the correct `#c9d1d9`) and with the default Theme foreground. This won't crash but will produce incorrect colors when `editor.foreground` is absent.

**Recommendation:** Change to `#c9d1d9`.

---

#### 22. `SEGMENT_BG_RECORD_INtegRation` test in `SvgRenderer` doesn't test background on segments

**File:** `tests/SvgRendererTest.php:75-82`

The test `testAnsiForegroundBecomesTspanFill` only verifies that a red foreground is in the output. There is no test confirming that ANSI background colors (`\x1b[44m`) produce `<rect>` fills behind text as documented in `CALIBER_LEARNINGS.md:pattern:sgr-bg-48`.

**Recommendation:** Add `testAnsiBackgroundBecomesRectFill` to `SvgRendererTest`.

---

#### 23. `Segment::withBg()` is the only `with*` mutator — inconsistent with the `mutate()` pattern

**File:** `src/Segment.php:23-33`

SugarCraft conventions (per `candy-sprinkles/src/Style.php`) use a private `mutate()` method that takes an array of changed fields and returns a new instance. `Segment` only has `withBg()` — if the API grows to support `withFg()`, `withBold()`, etc., each would need a separate method. The `mutate()` pattern is more extensible.

**Recommendation:** Either replace `withBg()` with `with(array $changes)` using the standard `mutate()` trait, or document why `withBg()` is the only needed mutation for the current use case.

---

#### 24. `CliTest::runCli()` doesn't close stdin pipe before reading

**File:** `tests/CliTest.php:36-38`

```php
if ($stdin !== null) {
    fwrite($pipes[0], $stdin);
}
fclose($pipes[0]);

$stdout = stream_get_contents($pipes[1]);
```

The stdin pipe is closed, but the test doesn't explicitly close stdout/stderr pipes in the `finally` block — it relies on `proc_close()` to close them. While `proc_close()` does close remaining pipes, it's clearer to explicitly `fclose($pipes[1])` and `fclose($pipes[2])` in a `finally` block.

**Recommendation:** Add explicit `fclose()` for `$pipes[1]` and `$pipes[2]` in a `finally` block.

---

### Test Coverage Gaps

#### 25. No test for `AnsiParser` with mixed 256-color + truecolor on same line

`tests/AnsiParserTest.php` tests 256-color (`\x1b[38;5;232m`) and truecolor (`\x1b[38;2;255;128;0m`) separately, but not a line containing both, or a sequence like `\x1b[38;5;196m\x1b[48;2;0;255;0m` (red on green). The `applySgr` method handles foreground and background independently, so this should work — but it's untested.

#### 26. No test for `PngRenderer::render()` with ANSI background colors

`tests/PngRendererTest.php:60-66` (`testAnsiColorsParsed`) only verifies the PNG is valid. It doesn't verify that background colors actually appear in the output. Given that GD's built-in fonts don't support per-character coloring (only per-string via `imagestring()`), background colors are likely not rendered in PNG output — but this should be documented or tested.

#### 27. No test for `LanguageDetector` with inputs containing multiple shebangs or conflicting signatures

`tests/LanguageDetectorTest.php` tests individual detection scenarios but not edge cases like:
- Content with both a PHP shebang AND Python signatures
- Content that is just whitespace
- Very long lines that might overflow

#### 28. No test for SVG output with zero lines (empty input after rtrim)

`tests/SvgRendererTest.php` tests `"hello\nworld"` and `"x\n"` but not the empty-string case. The `rtrim($text, "\n")` followed by `explode("\n", ...)` will produce `['']` for empty input, which should render a minimal frame — but this is untested.

#### 29. No test for `ChromaThemeLoader::load()` with a real chroma file

`tests/ChromaThemeLoaderTest.php:109-126` creates a temp file with minimal JSON and tests round-trip. But it never loads an actual chroma-format file (e.g., from the charmbracelet/chroma library's built-in themes) to verify the `TOKEN_MAP` integration end-to-end.

---

## Recommendations Summary

### Must Fix (Critical)
1. **[Memory leak]** `PngRenderer::allocateColor()` static `$cache` grows indefinitely — make it instance-scoped or use `WeakMap`
2. **[Bug]** `VsCodeThemeLoader.php:52` has `throw throw` — duplicate keyword
3. **[Dead code]** `$buttonHoverColors` unused in both `SvgRenderer` and `PngRenderer`

### Should Fix (Correctness)
4. **[Typo]** `VsCodeThemeLoader.php:77` fallback `#c9d9` should be `#c9d1d9`
5. **[Docs]** Add note that `PngRenderer` GD fonts don't support Unicode
6. **[Symlink]** CLI should reject symlinks pointing outside CWD for input files

### Should Refactor (Code Quality)
7. **[DRY]** Extract `LayoutCalculator` from duplicate sizing logic in both renderers
8. **[DRY]** Consolidate four window-chrome geometry methods via `WindowChromeGeometry`
9. **[Modernize]** Replace 20+ `if/continue` in `applySgr()` with `match` expression
10. **[Dead code]** `Theme::$windowStyle` property is set but never read — remove or wire it up

### Could Add (Features)
11. **[Missing feature]** Add `--format svg|png` to CLI
12. **[Missing feature]** `OutputWriter` interface for streaming / async rendering
13. **[Missing feature]** Wire `LanguageDetector` into the rendering pipeline for language-aware output
14. **[Missing feature]** Document and use `PngRenderer::dark()` etc. factories in examples

### Testing
15. **[Coverage]** Add `testAnsiBackgroundBecomesRectFill` for SVG renderer
16. **[Coverage]** Add tests for mixed 256-color + truecolor on one line
17. **[Coverage]** Add test for empty-input rendering
18. **[Coverage]** Test theme loaders with real VS Code / Chroma theme files

---

*Audit conducted: June 2026. Files reviewed at commit context of sugar-freeze master.*