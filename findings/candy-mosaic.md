# Code Review: candy-mosaic

**Library**: SugarCraft/candy-mosaic  
**Date**: 2026-06-29  
**Reviewer**: Claude Code  
**Files reviewed**: 29 source files under `src/`, 29 test files under `tests/`

---

## Summary

candy-mosaic is a well-architected, mature port of charmbracelet/x/mosaic — a terminal image renderer supporting Sixel, Kitty, iTerm2, half-block Unicode, quarter-block Unicode, ASCII, and Chafa CLI backends. The code is generally clean, well-documented, and follows SugarCraft conventions. The following findings are moderate/minor severity; no critical bugs or security vulnerabilities were found.

---

## Issues & Problems

### 1. `MosaicBuilder::build()` defaults to Sixel when no renderer set — surprising API

**File**: `src/Mosaic.php:563-564`

```php
$renderer = new SixelRenderer($this->dither ?? Dither::FloydSteinberg);
```

The `MosaicBuilder` constructor initializes `$renderer = null`. A builder that is never configured with `->withRenderer()` silently defaults to Sixel. This is undocumented — callers who expect the builder to be pass-through (e.g., planning to call `->withDither()` or `->withScale()` only) will get Sixel unexpectedly. The SugarCraft convention for builders is typically identity/default behavior when nothing is set.

**Recommendation**: Either document this default loudly in the builder doc-comment, or create a `MosaicBuilder::sixel()` static factory (parallel to `Mosaic::sixel()`) so the default is explicit. Alternatively, consider throwing if `->build()` is called with no renderer and no dither — forcing the caller to be explicit.

---

### 2. `autoFromPalette()` squanders the `TerminalProbe` report

**File**: `src/Mosaic.php:144-166`

```php
private static function autoFromPalette(ProbeReport $report): self
{
    if ($report->has(PaletteCapability::KittyKeyboard)) {
        // ...
        return new self($renderer, $cap, null, null, null);
    }

    if ($report->has(PaletteCapability::NoColor)) {
        return self::halfBlock();
    }

    // For everything else, HalfBlock is the safe fallback
    return self::halfBlock();
}
```

The `$report` parameter (a `ProbeReport` from `candy-palette`) is passed in but used only for two checks before falling back to `halfBlock()` for everything else. The full capability information from `TerminalProbe::run()` is discarded. The method should refine the renderer choice using more of the probe report (e.g., `TrueColor`, `Color256`, `BasicAscii`).

**Recommendation**: Use more of the `ProbeReport` to make a more informed renderer choice before falling back to half-block.

---

### 3. Unreachable try/catch in `Mosaic::auto()`

**File**: `src/Mosaic.php:100-115`

```php
try {
    $cap = Detect::probe();
    $renderer = self::bestBackend($cap);
    // ...
    return new self($renderer, $cap, null, null, null);
} catch (\Throwable) {
    // @codeCoverageIgnoreStart
    // Detect::probe() never throws; this block is here for future-proofing.
    // @codeCoverageIgnoreEnd
}
```

The comment explicitly states this block is unreachable. The comment says it's "for future-proofing" but it adds noise and misleads readers. PHPStan should flag this as dead code.

**Recommendation**: Remove the try/catch entirely, or guard it with a `// @phpstan-ignore-next-line` if there's a genuine desire to keep it for hypothetical future refactoring.

---

### 4. `ChafaRenderer::available()` does not clean up process handle on failure

**File**: `src/Renderer/ChafaRenderer.php:44-58`

```php
$proc = @proc_open(
    ['chafa', '--version'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
);
if (!is_resource($proc)) {
    return self::$available = false;
}
foreach ($pipes as $pipe) {
    if (is_resource($pipe)) {
        fclose($pipe);
    }
}
return self::$available = (proc_close($proc) === 0);
```

When `proc_open` succeeds but the command fails (non-zero exit), `proc_close($proc)` is called. However, if `proc_open` itself fails (returns `false`), the function returns early but the `$pipes` array may contain already-created pipe resources that are never closed. This is a minor resource leak on the failure path.

**Recommendation**: Close any already-opened pipes in the early-return path:

```php
if (!is_resource($proc)) {
    // $pipes may have been created before proc_open failed
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) fclose($pipe);
    }
    return self::$available = false;
}
```

---

### 5. `QuarterBlockRenderer::renderCell()` complexity

**File**: `src/Renderer/QuarterBlockRenderer.php:100-155`

The `renderCell()` method is 56 lines and performs: most-distant-pair seed search (O(6) comparisons), luma comparison, 4-iteration grouping loop, integer division for averages, and ANSI SGR assembly. The method name does not reflect that it's doing both color grouping and glyph selection.

**Recommendation**: Split into smaller methods:
- `findSeedPair()` — returns indices of most-distant pair
- `groupQuadrantsBySeed()` — assigns quadrants to fg/bg groups
- `computeCellColors()` — computes averaged fg/bg colors
- `renderCell()` — orchestrates and produces the final string

---

### 6. `SixelRenderer::emitBand()` is deeply nested

**File**: `src/Renderer/SixelRenderer.php:549-615`

The `emitBand` method has three levels of nesting (color iteration → column iteration → band processing), with an inner RLE loop at four levels. The method is ~67 lines and handles active-color discovery, per-color Sixel emission, and RLE encoding.

**Recommendation**: Extract the inner RLE encoding loop into a separate `emitRleForColor()` method. Extract the per-column bitmask building into a helper. This would reduce nesting and improve testability.

---

## Performance

### 7. `SixelRenderer` accum array is heap-allocated at full image resolution

**File**: `src/Renderer/SixelRenderer.php:388-401`

```php
$accum = [];
for ($y = 0; $y < $h; $y++) {
    $accum[$y] = [];
    for ($x = 0; $x < $w; $x++) {
        $accum[$y][$x] = [
            (float) (($rgb >> 16) & 0xFF),
            (float) (($rgb >> 8) & 0xFF),
            (float) ($rgb & 0xFF),
        ];
    }
}
```

For a 200×100 cell render with default 10×20 pixel cell size, this creates a 2000×1000 float array (~8 MB of heap for the accum alone, plus the grid). For very large renders this can be significant.

**Recommendation**: Consider processing scanlines in stripes (e.g., 64 rows at a time) rather than the full image at once, to bound peak memory usage. The Floyd–Steinberg/Atkinson/Stucki diffusions only propagate within a small neighborhood, so line-stripe processing is feasible with a buffer of 2-3 rows.

---

### 8. `SixelRenderer::samplePixels()` iterates with striding rather than direct indexing

**File**: `src/Renderer/SixelRenderer.php:178-201`

```php
for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < $w; $x++) {
        if (($i++ % $step) !== 0) {
            continue;
        }
        // ...
    }
}
```

This is a stride loop that iterates through every pixel but only processes every `$step`-th one. On modern CPUs, the branch misprediction on `continue` in a tight loop is suboptimal vs. a direct strided loop.

**Recommendation**: Consider restructuring as two nested loops with direct stride offsets:
```php
for ($y = 0; $y < $h; $y += $rowStep) {
    for ($x = 0; $x < $w; $x += $colStep) {
        // process ($x, $y) directly
    }
}
```

---

### 9. `ImageSource::fromString()` creates two temp files per call

**File**: `src/ImageSource.php:96-112`

```php
public static function fromString(string $bytes): self
{
    $tmp = tempnam(sys_get_temp_dir(), 'mosaic-');
    // ...
    file_put_contents($tmp, $bytes);
    return self::fromFile($tmp);  // which calls getimagesize() + imagecreatefromX()
}
```

This creates two temp files: one for the raw bytes, and `fromFile()` may create additional GD temp resources. The double temp file is wasteful.

**Recommendation**: Refactor `fromString()` to use `imagecreatefromstring()` directly (which already handles in-memory PNG/JPEG/GIF data) rather than writing to a temp file and re-reading:

```php
public static function fromString(string $bytes): self
{
    if (!extension_loaded('gd')) {
        throw new \RuntimeException(Lang::t('image_source.no_gd'));
    }
    $img = imagecreatefromstring($bytes);
    if ($img === false) {
        throw new \RuntimeException(Lang::t('image_source.gd_load_failed_from_string'));
    }
    if (!imageistruecolor($img)) {
        imagepalettetotruecolor($img);
    }
    $width = imagesx($img);
    $height = imagesy($img);
    // Use php://temp to encode back to bytes
    $tmp = fopen('php://temp', 'w+b');
    imagepng($img, $tmp, 9);
    imagedestroy($img);
    rewind($tmp);
    $pngBytes = stream_get_contents($tmp);
    fclose($tmp);
    return new self($pngBytes, 'image/png', $width, $height);
}
```

This would also fix the bug where if `fromFile()` throws after creating the temp file, `fromString()`'s `finally` block only cleans up its own temp file, not any temp files created inside `fromFile()`.

---

### 10. `MosaicBuilder` clones itself via `new self()` in every `with*()` method

**File**: `src/Mosaic.php:500-549`

Every `with*()` method creates a new `MosaicBuilder` instance by constructing a fresh object and manually copying fields. This is repetitive and error-prone when adding new fields.

**Recommendation**: Use the `with*()` pattern from `Mosaic` (pass named params to constructor) or implement a single `with()` method that handles all fields generically. Also consider using `readonly` properties with constructor promotion on the builder itself.

---

## Memory Leaks

### 11. No issues found

All renderers properly call `imagedestroy()` in `finally` blocks or immediately after use. `AdaptiveImage` properly destroys its LRU entries on eviction. No cyclic references that would prevent GC were found. GD resources are always released.

---

## Security

### 12. SSRF protection is documented but relies on caller discipline

**File**: `src/ImageSource.php:163-169`

```php
// Security: like ImageSource::fromFile(), the trust decision for
// the source is the caller's. Because every PHP scheme is honoured and
// redirects are followed, passing an untrusted/user-influenced URL exposes
// local-file reads (file:///etc/passwd) and SSRF...
```

The documentation is excellent and explicit. However, `fromUrl()` allows all PHP stream schemes (`http`, `https`, `file`, `data`) with no opt-in allow-list. Applications using `ImageSource::fromUrl()` with user-provided URLs (e.g., avatar URLs from form input) are vulnerable to SSRF and local file inclusion.

**Recommendation**: Add an optional allow-list parameter to `fromUrl()` and `fromUrlAsync()`:

```php
public static function fromUrl(string $url, ?array $headers = null, ?array $allowedSchemes = null): self
```

Default to `['http', 'https']` to match typical web usage. This makes the library safe by default for the common case.

---

### 13. Header injection protection is correctly implemented

**File**: `src/ImageSource.php:285-297`

The `formatHeaders()` method validates against CR/LF injection with `preg_match('/[\r\n]/', $line)` before constructing the header array. This is correct.

---

### 14. `DiskCache` key hashing prevents directory escape

**File**: `src/DiskCache.php:270-273`

```php
private function path(string $key): string
{
    return $this->dir . '/' . sha1($key) . '.cache';
}
```

Keys are SHA-1 hashed before use in file paths, preventing arbitrary key injection from escaping the cache directory. This is correct.

---

## Complexity Issues

### 15. Repeated `render()` boilerplate in every renderer

**Pattern found in**: `KittyRenderer::render()`, `SixelRenderer::render()`, `HalfBlockRenderer::render()`, `QuarterBlockRenderer::render()`, `Iterm2Renderer::render()`, `AsciiRenderer::render()`, `ChafaRenderer::render()`

Every `render()` method contains nearly identical validation and height-computation logic:

```php
if ($width <= 0) { throw new \InvalidArgumentException(Lang::t('renderer.invalid_width', ...)); }
if ($height !== null && $height <= 0) { throw new \InvalidArgumentException(Lang::t('renderer.invalid_height', ...)); }
$effectiveHeight = $height ?? (int) round($width / $image->aspectRatio());
if ($effectiveHeight <= 0) { $effectiveHeight = 1; }
```

**Recommendation**: Extract this into `Renderer::prepareRender()`:

```php
public function prepareRender(ImageSource $image, int $width, ?int &$height): int
{
    if ($width <= 0) { throw new \InvalidArgumentException(Lang::t('renderer.invalid_width', ...)); }
    if ($height !== null && $height <= 0) { throw new \InvalidArgumentException(Lang::t('renderer.invalid_height', ...)); }
    $effectiveHeight = $height ?? (int) round($width / $image->aspectRatio());
    if ($effectiveHeight <= 0) { $effectiveHeight = 1; }
    $height = $effectiveHeight;
    return $effectiveHeight;
}
```

This also ensures consistent error messages across all renderers (currently, some use `Lang::t('renderer.invalid_width', ...)` and some use `Lang::t('renderer.invalid_height', ...)` without the `renderer.` namespace prefix inconsistency check).

---

### 16. Duplicated `dist()` and `luma()` logic

**File**: `src/Renderer/QuarterBlockRenderer.php:161-173`

The `dist()` function (squared Euclidean distance) and `luma()` function (BT.601 luma) are also found in `AsciiRenderer::render()`:

```php
// QuarterBlockRenderer:
return (($rgb[0] * 77) + ($rgb[1] * 150) + ($rgb[2] * 29)) >> 8;  // luma
$dr * $dr + $dg * $dg + $db * $db;  // squared distance

// AsciiRenderer:
$luma = (($r * 77) + ($g * 150) + ($b * 29)) >> 8;  // identical luma
```

**Recommendation**: Move these to `SugarCraft\Core\Util\Color` or a shared utility class:

```php
final class ColorUtil
{
    public static function luma(int $r, int $g, int $b): int { ... }
    public static function squaredDistance(array $a, array $b): int { ... }
}
```

---

## Missing Features

### 17. No GIF animation frame extraction

**File**: N/A

The library has `Animation` and `AnimationDriver` for driving animated sequences, but there is no way to decode a GIF into an `Animation` — the user must manually extract frames and build the `Animation`. The CALIBER_LEARNINGS correctly notes this belongs in `candy-flip` (a separate library), but the capability is missing entirely from the monorepo.

**Recommendation**: Add a `GifDecoder` class or function in `candy-flip` that decodes `ImageSource` (for GIF format) into `Animation`, making the full GIF→terminal animation pipeline work out of the box.

---

### 18. No streaming/animation render for async context

**File**: `src/SyncAsyncRenderer.php`

`SyncAsyncRenderer` defers synchronous work to the next event loop tick but provides no true parallelism. For video/animation frames, each frame must still complete before the next starts.

**Recommendation**: Consider a `ProcessAsyncRenderer` that uses `react/child-process` to run renders in parallel worker processes, allowing true concurrent frame computation for animation playback.

---

### 19. No way to query supported protocols at runtime

**File**: N/A

`Capability` tells what the *current terminal* supports, but there is no API to query what protocols the library can render (regardless of terminal). A user might want to know "can I use Sixel at all?" before deciding whether to implement a Sixel-specific feature.

**Recommendation**: Add a static method:

```php
public static function supportedProtocols(): array<string>
// e.g., ['kitty', 'sixel', 'iterm2', 'halfblock', 'quarterblock', 'chafa']
```

---

## Compatibility with Other SugarCraft Libs

### 20. `Mosaic::auto()` conflates two different detection systems

**File**: `src/Mosaic.php:94-125`

`Mosaic::auto()` uses `Detect::probe()` (custom DA1/XTWINOPS probing specific to candy-mosaic) and falls back to `TerminalProbe` from `candy-palette`. These two systems may disagree on terminal capabilities. The `Detect` system has sixel detection via DA1, while `TerminalProbe` has its own capability enumeration. The precedence is undocumented and the fallback logic is convoluted.

**Recommendation**: Clarify the relationship between `Detect::probe()` (candy-mosaic) and `TerminalProbe::run()` (candy-palette). Ideally, one system should be the source of truth with the other as fallback.

---

### 21. `ChafaRenderer` availability memoization is per-process

**File**: `src/Renderer/ChafaRenderer.php:19`

```php
private static ?bool $available = null;
```

If `chafa` is installed mid-process, the library won't detect it. Conversely, if `chafa` is uninstalled, the cached `true` persists. This is fine for CLI tools (single process, known environment) but could surprise long-running daemons.

**Recommendation**: Add a `reset()` method or consider TTL-based re-validation for the `available()` check in long-running processes.

---

## Async Patterns (ReactPHP)

### 22. `SyncAsyncRenderer` does not handle `Loop::futureTick` failure

**File**: `src/SyncAsyncRenderer.php:31`

```php
Loop::futureTick(fn() => $this->doRender(...));
```

If the callback throws an uncaught exception, ReactPHP's event loop will emit an error but the promise will never settle, hanging the caller indefinitely. This can happen if `Mosaic::render()` throws (e.g., corrupted image bytes, GD failure).

**Recommendation**: Wrap the future tick in a try/catch with fallback:

```php
try {
    Loop::futureTick(fn() => $this->doRender($image, $width, $height, $deferred));
} catch (\Throwable $e) {
    $deferred->reject($e);
}
```

---

### 23. `AdaptiveImage::renderAsync()` resolves in next tick for cached hits

**File**: `src/AdaptiveImage.php:96-100`

```php
$deferred = new \React\Promise\Deferred();
Loop::futureTick(fn() => $deferred->resolve($this->cache[$key]));
return $deferred->promise();
```

This is intentional ("resolve in the next tick so the behaviour is consistently async") but means every cached async render adds at least one event loop iteration of latency. For tight rendering loops this could cause unnecessary scheduling overhead.

**Recommendation**: If the cache hit path should be truly synchronous, use `React\Promise\Promise::resolve()` directly without deferring to the loop:

```php
return \React\Promise\Promise::resolve($this->cache[$key]);
```

---

### 24. `AsyncRenderer` interface has no cancellation support

**File**: `src/AsyncRenderer.php`

The `AsyncRenderer` interface returns a `PromiseInterface` but provides no way to cancel an in-flight render. For animations where the user navigates away before a frame completes, the promise may resolve and trigger a state update on a stale component.

**Recommendation**: Consider adding a cancellation token or returning a `CancellablePromiseInterface` (from `react/promise`).

---

## Additional Observations

### 25. `Mosaic::auto()` nested try/catch swallows `Detect::probe()` failure silently

**File**: `src/Mosaic.php:100-114`

The outer try/catch catches any exception from `Detect::probe()` but the inner comment explicitly says it never throws. If `Detect::probe()` is ever refactored to throw, the fallback silently kicks in with no logging or indication that the primary detection path failed. This could lead to subtle bugs where terminal capability detection silently falls back to half-block without any visible signal.

**Recommendation**: At minimum, add a comment explaining what would cause the fallback to activate, or log the failure in the catch block.

---

### 26. `PixelGrid::fromGd()` creates a double-height canvas without alpha blending

**File**: `src/PixelGrid.php:44-56`

```php
$scaled = imagecreatetruecolor($cellW, $cellH * 2);
$opaqueBlack = imagecolorallocatealpha($scaled, 0, 0, 0, 0);
imagefill($scaled, 0, 0, $opaqueBlack);
imagealphablending($scaled, false);
imagesavealpha($scaled, false);
```

The PixelGrid creates an opaque black background then disables alpha blending entirely. For the half-block renderer this is correct (it handles transparency explicitly via `null` alpha). However, this means if a source image has genuine transparency information (alpha < 127 but > 0), `PixelGrid` discards it and maps to binary opaque/transparent only. The comment at line 37 says "alpha is null for fully-transparent pixels, 0 for fully-opaque" — there is no intermediate support.

**Recommendation**: Document this limitation clearly: "Semi-transparent pixels (alpha 1-126) are treated as fully opaque in the PixelGrid. For true alpha compositing, use a different pixel format."

---

### 27. `AnimationDriver::subscriptions()` always returns null

**File**: `src/AnimationDriver.php:95-98`

```php
public function subscriptions(): ?\SugarCraft\Core\Subscriptions
{
    return null;
}
```

This means `AnimationDriver` cannot receive subscription-based events (keyboard, mouse). For animations triggered by user input or running alongside other TUI components, this is correct. However, it could be confusing if someone tries to compose `AnimationDriver` with a parent that expects subscriptions.

**Recommendation**: Document in the class doc-comment that `subscriptions()` returning null is intentional — `AnimationDriver` is purely tick-driven.

---

### 28. `SixelRenderer` supports alpha but `supportsAlpha()` returns false

**File**: `src/Renderer/SixelRenderer.php:133-136`

```php
public function supportsAlpha(): bool
{
    return false;
}
```

The Sixel protocol can represent transparency via a transparent color index, but the current implementation does not support it. The `supportsAlpha()` return matches the actual capability, so this is correct. The CALIBER_LEARNINGS correctly documents this.

---

## Positive Findings

The following are genuinely good patterns worth highlighting:

1. **`DiskCache::FORMAT_VERSION`** versioning scheme is excellent — old cache entries are automatically retired when the format changes, with no manual cache clearing needed.

2. **`TmuxPassthroughDecorator`** properly handles DCS, APC, and OSC sequences separately, with correct ST/BEL terminator detection.

3. **`Deadline` class** in `Detect.php` is a clean monotonic clock abstraction for timeout management, using `hrtime(true)` rather than `microtime(true)`.

4. **`AdaptiveImage::touchLru()`** properly maintains both the cache map and the LRU ordering array, with correct eviction of oldest entries when `maxCache` is exceeded.

5. **Test coverage is thorough** — there are comprehensive tests for the delete API (`DeleteApiTest`), URL fetching (`ImageSourceUrlTest`), async rendering (`AsyncRendererTest`), DA1 probing (`DetectTest`), and LRU eviction (`AdaptiveImageTest`).

6. **The `Renderer` interface contract is clean** — each renderer (Kitty, iTerm2, Sixel, HalfBlock, QuarterBlock, Chafa, Ascii) independently implements `render()`, `name()`, `supportsAlpha()`, `isInline()`, and `delete()`, following the Interface Segregation Principle well.

7. **GD resource management is consistent** — every code path that creates GD resources has a corresponding `imagedestroy()` in a `finally` block or immediate cleanup.

8. **`ChafaRenderer::$available` static memoization** is appropriate here since the presence of an external binary won't change during a process lifetime.
