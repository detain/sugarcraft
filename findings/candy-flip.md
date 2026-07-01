# Code Review: candy-flip

**Library**: sugarcraft/candy-flip (ASCII GIF viewer)  
**Date**: 2026-06-29  
**Reviewer**: Claude Code  
**Files reviewed**: src/Decoder.php, src/Frame.php, src/Renderer.php, src/Player.php, src/TickMsg.php, src/Lang.php, bin/candy-flip, examples/play.php, tests/*

---

## Summary

candy-flip is a well-structured, idiomatic SugarCraft library that decodes GIF files via ext-gd and renders them as ANSI-colored Unicode block-glyphs in the terminal. The implementation is broadly sound — memory management is correct, the hand-rolled GIF89a parser handles edge cases (truncated sub-blocks, large LZW blocks ≥128 bytes, disposal methods), and the test suite covers the critical paths. The following findings are medium-to-low severity and none represent correctness bugs in the happy path.

---

## Critical / High Severity

*(None — no correctness bugs or security issues found in the hot path.)*

---

## Medium Severity

### 1. Per-frame delay is parsed but ignored at play time

**Files**: `src/Player.php:34`, `examples/play.php:41`, `bin/candy-flip:47`

The `Decoder::decode()` correctly extracts per-frame GCE delays and stores them in `Frame::$delay` (centiseconds). However, `Player` is constructed with a single fixed `$interval` (default `0.1` seconds / 100 ms) that is used for every frame tick:

```php
// Player.php:34
public function __construct(
    public readonly array $frames,
    public readonly int $index = 0,
    public readonly bool $paused = false,
    public readonly float $interval = 0.1,  // ← single interval for all frames
    ...
)
```

```php
// Player.php:43-44 — tick always uses the same interval
return Cmd::tick($this->interval, static fn(): Msg => new TickMsg());
```

**Impact**: Animated GIFs with variable frame timings (e.g., a 50ms frame followed by a 500ms frame) will play back at a uniform rate, losing the intended rhythm of the original animation.

**Recommendation**: Use per-frame delay when scheduling the next tick. One approach: have `Player` read `$this->frames[$this->index]->delay` and pass that to `scheduleTick()`. The delay should be converted from centiseconds to seconds (`$delay / 100.0`). Edge case: if `delay === 0`, fall back to the default interval or the GIF spec default of 100ms (`Frame::$delay` defaults to `10` centiseconds = 100ms, which is the GIF spec default, so non-zero delay is always available).

```php
// In scheduleTick(), read the current frame's delay:
private function scheduleTick(): \Closure
{
    $delay = $this->frames[$this->index]->delay;
    $interval = $delay > 0 ? $delay / 100.0 : $this->interval;
    return Cmd::tick($interval, static fn(): Msg => new TickMsg());
}
```

---

### 2. Dead `$allTransparent` branches in both `sampleCanvas()` and `sample()`

**File**: `src/Decoder.php:229–261` (sampleCanvas), `src/Decoder.php:511–541` (sample)

Both downsampling methods have identical logic anomalies. In `sampleCanvas()` (lines 229–261):

```php
$allTransparent = true;
for ($sy = $y0; $sy <= $y1; $sy++) {
    for ($sx = $x0; $sx <= $x1; $sx++) {
        $pixel = imagecolorat($canvas, $sx, $sy);
        $a = ($pixel >> 24) & 0xFF;
        if ($a !== 0) {
            $allTransparent = false;
            continue;
        }
        $allTransparent = false;   // ← sets false for ANY opaque pixel
        ...
        $count++;
    }
}
if ($count > 0) {
    $row[] = [...];               // normal RGB average
} elseif ($allTransparent) {
    $row[] = null;                // ← unreachable: count==0 && allTransparent
} else {
    $row[] = null;                // ← count==0 && !allTransparent
}
```

**Problem**: The `elseif ($allTransparent)` branch is unreachable. If `count === 0`, the only way `$allTransparent` could still be `true` is if the loop never executed (which cannot happen with non-negative loop bounds), OR if every pixel iteration hit the `continue` early. But even transparent pixels set `$allTransparent = false` at line 242. So when `count === 0`, `$allTransparent` is always `false`, making the `elseif` dead code.

**Impact**: No correctness impact (both branches return `null`), but dead code indicates logic was intended differently.

**Recommendation**: Clarify intent. If the intent is "all transparent → null, none opaque → null", the code is correct and the `elseif` should be removed or documented as a belt-and-suspenders check. If intent was something else (e.g., a different value for "fully transparent vs partially transparent"), the logic needs correction.

---

## Low Severity

### 3. `sampleCanvas()` and `sample()` are ~80-line code duplicates

**File**: `src/Decoder.php:209–267` vs `src/Decoder.php:473–548`

Both methods implement area-average downsampling with transparent-pixel skipping. The only difference is:
- `sampleCanvas()` (truecolor canvas): pixel is unpacked from packed ARGB via `($pixel >> 24) & 0xFF`
- `sample()` (palette image): pixel is a palette index resolved via `imagecolorsforindex()`

The duplicated logic is ~80 lines each. This violates DRY and creates a maintenance hazard — any change to the downsampling algorithm must be applied twice.

**Recommendation**: Extract the pixel-iteration logic into a private helper:

```php
private static function downsampleRegion(
    \GdImage $img,
    bool $isPalette,
    int $x0, int $x1, int $y0, int $y1,
): ?array{0:int, 1:int, 2:int} {
    $sumR = $sumG = $sumB = 0;
    $count = 0;
    for ($sy = $y0; $sy <= $y1; $sy++) {
        for ($sx = $x0; $sx <= $x1; $sx++) {
            $pixel = imagecolorat($img, $sx, $sy);
            if ($isPalette) {
                $rgb = imagecolorsforindex($img, $pixel);
                if ($rgb === false) continue;
                $sumR += $rgb['red'];
                $sumG += $rgb['green'];
                $sumB += $rgb['blue'];
                $count++;
            } else {
                $a = ($pixel >> 24) & 0xFF;
                if ($a !== 0) continue;  // transparent
                $sumR += ($pixel >> 16) & 0xFF;
                $sumG += ($pixel >> 8) & 0xFF;
                $sumB += $pixel & 0xFF;
                $count++;
            }
        }
    }
    return $count > 0 ? [
        (int) round($sumR / $count),
        (int) round($sumG / $count),
        (int) round($sumB / $count),
    ] : null;
}
```

### 4. Snapshot image allocated every frame regardless of need

**File**: `src/Decoder.php:107–111`

```php
// Snapshot before painting this frame (for DISPOSAL_PREVIOUS of next frame).
$snapshot = imagecreatetruecolor($screenW, $screenH);
imagesavealpha($snapshot, true);
imagefill($snapshot, 0, 0, $transparentBg);
imagecopy($snapshot, $canvas, 0, 0, 0, 0, $screenW, $screenH);
```

A full canvas snapshot is created on **every iteration**, but `DISPOSAL_PREVIOUS` (method 3) is rare in practice. For typical animated GIFs using disposal methods 0, 1, or 2, this allocation is wasted.

**Recommendation**: Move snapshot creation inside the `DISPOSAL_PREVIOUS` branch:

```php
if ($prevDisposal === Frame::DISPOSAL_PREVIOUS) {
    if ($snapshot === null) {
        $snapshot = imagecreatetruecolor($screenW, $screenH);
        imagesavealpha($snapshot, true);
        imagefill($snapshot, 0, 0, $transparentBg);
    }
    imagecopy($snapshot, $canvas, 0, 0, 0, 0, $screenW, $screenH);
}
```

Note: Current logic always copies canvas→snapshot before painting each frame, and snapshot→canvas on DISPOSAL_PREVIOUS. To preserve behavior, the snapshot must be taken **before** painting the current frame (which the current code does correctly). The fix above is slightly different — it would only snapshot when the **previous** frame had DISPOSAL_PREVIOUS. The correct approach is to snapshot **unconditionally** but defer allocation:

```php
// Before painting frame N, ensure snapshot exists (for frame N+1's DISPOSAL_PREVIOUS).
if ($snapshot === null) {
    $snapshot = imagecreatetruecolor($screenW, $screenH);
    imagesavealpha($snapshot, true);
    imagefill($snapshot, 0, 0, $transparentBg);
}
imagecopy($snapshot, $canvas, 0, 0, 0, 0, $screenW, $screenH);
```

This still allocates every frame but only creates the GD image once (lazy allocation).

### 5. `renderSingleFrame()` path — inconsistency with multi-frame path

**File**: `src/Decoder.php:403–468` (renderSingleFrame) vs `src/Decoder.php:78–152` (multi-frame decode)

Single-frame GIFs (those with no Image Descriptors) go through `renderSingleFrame()` → `sample()` (palette-mode downsampling). Multi-frame GIFs use the compositing canvas → `sampleCanvas()` (truecolor-mode downsampling).

The two paths handle local color tables slightly differently:
- Multi-frame path: Local color table is embedded in the reassembled GIF at `offset + 10` (line 190-191), which is **after** the Image Descriptor.
- `renderSingleFrame()`: Same — local color table is embedded at `offset + 10` (line 446-448).

Both appear consistent in practice, but the comment at line 444 (`"Local color table when present (after the Image Descriptor header)"`) is misleading because the LCT actually precedes the LZW data in the reassembled payload — the comment could confuse future maintainers.

### 6. Transparent pixel detection in `sampleCanvas()` uses `alpha !== 0`

**File**: `src/Decoder.php:237`

```php
$a = ($pixel >> 24) & 0xFF;
if ($a !== 0) {
    // Transparent or semi-transparent pixel — skip in average.
    $allTransparent = false;
    continue;
}
```

The condition `a !== 0` treats any non-zero alpha as fully transparent. In PHP's GD with `imagesavealpha()`, alpha ranges 0 (opaque) to 127 (fully transparent). So this correctly identifies opaque pixels as those with `alpha === 0`. Semi-transparent pixels (0 < alpha < 127) are skipped in the average, which is a reasonable choice for downsampling.

**Minor note**: If the intent is "alpha 127 = transparent, alpha 0 = opaque", the condition `if ($a !== 0)` is correct. However, `if ($a >= 127)` or `if ($a > 0)` would be more precise if any semi-transparent pixels should be treated as transparent. Current behavior skips partial transparency in the average, which may cause slight color shifts when transparent overlays are downsampled.

### 7. `Renderer::render()` static method delegates to zero-sized instance

**File**: `src/Renderer.php:81–84`

```php
public static function render(Frame $f, string $preset = self::PRESET_SOLID): string
{
    return (new self())->renderFrame($f, $preset);
}
```

`withAdaptiveSize()` requires a TTY; the static helper creates an unconstrained renderer. This is documented as "preserved for backward compatibility". If backward compatibility is not a concern, this method could be removed to simplify the class.

---

## Code Quality Observations

### Well-designed aspects

1. **`Frame` is a clean value object** — `readonly` properties, `width()`/`height()` accessors, proper docblock with `@phpstan-type`. Immutable and self-contained.

2. **GIF89a parser is thorough** — Handles GCE (delay, disposal, transparency), Image Descriptor (left/top/width/height, LCT flag), sub-block traversal with bounds checking, and correctly walks the byte stream block-by-block.

3. **Disposal method compositing is correct** — DISPOSAL_NONE/KEEP (0/1) leave canvas, DISPOSAL_BACKGROUND (2) clears the prior rect with `imagefilledrectangle()`, DISPOSAL_PREVIOUS (3) restores from snapshot.

4. **256-frame cap** at line 392 prevents OOM from pathological GIFs: `'frameInfos' => array_slice($frameInfos, 0, 256)`.

5. **MAX_CELLS = 100,000** bound at line 32 prevents excessive memory allocation from large cell grid requests.

6. **Run coalescing** in `Renderer::renderFrame()` (lines 104–122) — adjacent same-color cells share a single SGR sequence, reducing output size by ~50% for typical frames. Well-tested in `testCoalescesAdjacentRuns()`.

7. **i18n via `Lang` facade** properly extends `SugarCraft\Core\I18n\Lang` with a namespace and DIR constant. All user-facing strings are externalized.

8. **Golden-file snapshot testing** via `candy-testing`'s `assertGoldenAnsi` pins rendering output.

---

## Performance Considerations

### Hot path: `sampleCanvas()` and `sample()` pixel loops

The nested loops in both methods (4 levels: cell rows × cell cols × pixel rows × pixel cols) are the primary bottleneck for large GIFs. For a 60×18 cell grid from a 120×60 source image, that's 60×18×4 = 4,320 `imagecolorat()` calls per frame. This is acceptable for typical animation sizes.

**Optimization opportunities** (low priority):
- Use `imagecopyresampled()` to downsample the full frame to 1/n size first, then average within each cell (fewer `imagecolorat()` calls).
- Process in scanline batches and use `imagegrabscreen()` or `imagegetpixel()` alternatives.

### Async/ReactPHP patterns

The library is synchronous and follows the SugarCraft `Model`/`Cmd`/`Msg` pattern correctly. For a GIF decoder:

- **Current design**: All frames decoded upfront via `Decoder::decode()` → `Player` receives a `list<Frame>`. Playback is synchronous timing via `Cmd::tick()`.
- **Async-compatible**: For very large GIFs, a streaming decoder could yield frames one-at-a-time using a generator (`Generator<Frame>`) or an async stream. This would require `Player` to handle partial frame lists and dynamic extension. **Not recommended as a near-term change** — the current up-front decode is simpler and works well for typical GIF sizes (≤256 frames).

---

## Compatibility with SugarCraft Ecosystem

### Dependency tree

```
candy-flip
├── sugarcraft/candy-core      (Model, Cmd, Msg, Program, ProgramOptions, Ansi)
├── sugarcraft/candy-pty       (SizeIoctl)
├── sugarcraft/candy-sprinkles (Style — not directly imported, but in ecosystem)
├── sugarcraft/candy-testing   (test only, assertGoldenAnsi)
└── ext-gd                     (PHP extension)
```

All imports are from public, stable interfaces:
- `SugarCraft\Core\Model` — `Player` correctly implements `init()/update()/view()/subscriptions()`
- `SugarCraft\Core\Cmd::tick()` — used for frame scheduling
- `SugarCraft\Core\Msg\KeyMsg` / `WindowSizeMsg` — keyboard and resize handling
- `SugarCraft\Pty\SizeIoctl` — TTY dimension query
- `SugarCraft\Core\Util\Ansi` — ANSI escape sequence constants
- `SugarCraft\Testing\Snapshot\Assertions::assertGoldenAnsi` — golden file testing

**No compatibility concerns detected.** The library follows ecosystem conventions correctly.

---

## Test Coverage Assessment

Tests are comprehensive and well-structured:

| Test File | Coverage |
|-----------|----------|
| `DecoderTest.php` | File validation, palette→RGB resolution, oversized grid rejection, large LZW sub-blocks, truncated sub-block handling |
| `FrameTest.php` | Value object accessors, empty-cell edge case |
| `RendererTest.php` | SGR emissions, run coalescing, transparent cell handling, line reset |
| `PlayerTest.php` | Tick advance, wrapping, pause/step/quit/preset keyboard handling, WindowSizeMsg |
| `AdaptiveSizeTest.php` | Row/col clamping with `withConstraints()` |
| `DecoderCompositingTest.php` | Multi-frame compositing, disposal methods, offset parsing |
| `DecoderTransparencyTest.php` | GCE transparency flag, disposal parsing, null cells |
| `DecoderLocalColorTest.php` | Per-frame local color table usage |
| `PerFrameTimingTest.php` | Per-frame delay parsing, default delay fallback |
| `GoldenRenderTest.php` | Byte-exact ANSI snapshot (3×3 density render) |

**Gaps**:
- No test for `DISPOSAL_PREVIOUS` (method 3) compositing correctness — it is recognized and stored but the resulting rendered output is not verified.
- `Lang` class is not directly tested (but its parent is tested elsewhere in the ecosystem).

---

## Recommendations Summary

| Priority | Item | File | Lines |
|----------|------|------|-------|
| **High** | Use per-frame delay in Player tick scheduling | `Player.php` | 43–44, 119–122 |
| **Medium** | Remove or document unreachable `$allTransparent` branches | `Decoder.php` | 258–261, 536–538 |
| **Low** | Consolidate `sampleCanvas()` and `sample()` via helper | `Decoder.php` | 209–267, 473–548 |
| **Low** | Lazy-allocate `$snapshot` GD image | `Decoder.php` | 107–111 |
| **Low** | Clarify LCT comment in `renderSingleFrame()` | `Decoder.php` | 444 |

---

*End of review.*
