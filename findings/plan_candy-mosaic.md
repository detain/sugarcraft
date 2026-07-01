# Implementation Plan: candy-mosaic

**Library:** SugarCraft/candy-mosaic
**Plan created:** 2026-06-30
**Source:** Code review findings in `findings/candy-mosaic.md`

---

## Goal

Address all 28 findings from the candy-mosaic code review — resource leaks, security hardening, performance optimizations, code complexity reduction, missing features, and async pattern improvements — organized into 7 phases by severity and dependency.

---

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Use `MosaicBuilder::sixel()` static factory for explicit default | SugarCraft convention favors explicit factories over hidden defaults | `candy-mosaic.md:1` (Finding 1) |
| Add `allowedSchemes` param to `ImageSource::fromUrl()` defaulting to `['http','https']` | Defense-in-depth: SSRF is a real risk with user-provided URLs; safe-by-default is the right posture | `candy-mosaic.md:12` (Finding 12) |
| Extract `dist()` and `luma()` to `SugarCraft\Core\Util\ColorUtil` | Both QuarterBlockRenderer and AsciiRenderer use identical implementations; shared utility prevents drift | `candy-mosaic.md:16` (Finding 16) |
| Add `Renderer::prepareRender()` base method | 7 renderers have nearly identical validation/height-computation boilerplate; extraction improves DRY and consistency | `candy-mosaic.md:15` (Finding 15) |
| Stripe-process SixelRenderer accum array | Error diffusion only propagates within ±2 rows, so 64-row stripes with 2-3 row overlap are sufficient while bounding memory | `candy-mosaic.md:7` (Finding 7) |
| ChafaRenderer pipe cleanup on proc_open failure path | `$pipes` array can have created resources before proc_open returns false; those must be closed to avoid fd leaks | `candy-mosaic.md:4` (Finding 4) |
| QuarterBlockRenderer refactor into 4 methods | 56-line renderCell() doing seed search, grouping, averaging, and glyph assembly — split for testability | `candy-mosaic.md:5` (Finding 5) |

---

## Phase 1: Critical/High Severity — Resource & Correctness Fixes [PENDING]

### 1.1 Fix ChafaRenderer::available() pipe resource leak on proc_open failure — FINDING 4

**What:** When `proc_open()` partially succeeds (pipes are created) but returns `false`, the `$pipes` array may contain already-opened file descriptors that are never closed.

**Why:** File descriptor leaks can exhaust system resources in loops or long-running processes.

**Severity:** high

**Conditions for success:**
- `ChafaRendererTest::testAvailableFalseCleansUpPipes()` passes — verifies no fd leaks when `chafa` binary does not exist
- Manual test: loop 1000 `proc_open` failures, verify no warnings

**Related code locations:**
- `src/Renderer/ChafaRenderer.php:44-58`

**Investigation notes:**
- `proc_open()` creates pipes before returning; if it returns `false`, pipes are already created
- Current code at L49-50 returns `false` immediately without iterating `$pipes`
- Fix: close any already-opened pipes before the early return

```php
// src/Renderer/ChafaRenderer.php:44-58 (proposed fix)
$proc = @proc_open(
    ['chafa', '--version'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
);
if (!is_resource($proc)) {
    // $pipes may have been created before proc_open failed
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    return self::$available = false;
}
```

---

### 1.2 Add `allowedSchemes` parameter to `ImageSource::fromUrl()` — FINDING 12

**What:** Add optional `?array $allowedSchemes = ['http', 'https']` parameter to `fromUrl()` and `fromUrlAsync()`. Default restricts to web schemes; opt-in `file://` or `data:` for trusted sources.

**Why:** Current implementation honors all PHP stream wrappers (`http`, `https`, `file`, `data`), exposing SSRF and local-file-read attacks when user-provided URLs are passed.

**Severity:** critical (security)

**Conditions for success:**
- Existing `ImageSourceUrlTest` tests still pass
- New test: `testFromUrlRejectsFileScheme()` — passes `file:///etc/passwd`, expects `\InvalidArgumentException`
- New test: `testFromUrlWithExplicitAllowedScheme()` — passes `file:///path`, `allowedSchemes: ['file']`, succeeds

**Related code locations:**
- `src/ImageSource.php:180-183` — `fromUrl()` signature
- `src/ImageSource.php:207-235` — `fromUrlAsync()` signature
- `src/ImageSource.php:243-272` — `fetchUrlSync()` which applies the scheme check

**Investigation notes:**
- `fromUrl()` delegates to `fromString(self::fetchUrlSync(...))` at L182
- `fetchUrlSync()` uses `file_get_contents($url, ...)` at L259, which honors all PHP wrappers
- Need a new lang key: `image_source.url_invalid_scheme`
- Also apply the same fix to `fromUrlAsync()` (see Phase 2)

---

### 1.3 Remove unreachable try/catch in `Mosaic::auto()` — FINDING 3

**What:** Remove the nested inner try/catch block (L100-114) that wraps `Detect::probe()`. The comment explicitly states it is unreachable. Use `// @phpstan-ignore-next-line` if a future-proof comment is truly needed.

**Why:** Dead code misleads readers, adds noise, and PHPStan should flag it. The outer catch already handles any throwing path.

**Severity:** medium

**Conditions for success:**
- `vendor/bin/phpunit` passes with no phpstan warnings about dead code
- `MosaicAutoTest` still passes (tests the fallback behavior)

**Related code locations:**
- `src/Mosaic.php:100-115`

**Investigation notes:**
- `Detect::probe()` (Detect.php L49-60) never throws — returns `Capability` with all protocols `false` on failure
- The outer catch at L121 catches any throw and calls `self::halfBlock()` — identical behavior
- The inner catch only falls through to `TerminalProbe::run()` path, but `Detect::probe()` does not throw

---

### 1.4 Fix MosaicBuilder clone pattern — FINDING 10

**What:** Refactor `MosaicBuilder` to use PHP 8.3 constructor promotion with `readonly` properties, replacing manual `new self()` + field-copying in every `with*()` method.

**Why:** Current implementation manually copies 5 fields in each of 4 `with*()` methods (L500-549) — error-prone when fields are added.

**Severity:** medium

**Conditions for success:**
- `MosaicBuilderTest` passes — existing tests verify `build()` output
- New test: immutability check — `withRenderer()` returns new instance, original unchanged

**Related code locations:**
- `src/Mosaic.php:492-576` — `MosaicBuilder` class

**Investigation notes:**
- Pattern to follow: `with(string $property, mixed $value)` via `__call()` that does `new self(...get_object_vars($this), $property: $value)`
- Or: named constructor that accepts all fields as promoted params and each `with*()` creates new instance via `new self(renderer: $renderer, ...)`

---

### 1.5 Add `supportedProtocols()` static method to Mosaic — FINDING 19

**What:** Add `public static function supportedProtocols(): array<string>` returning `['kitty', 'sixel', 'iterm2', 'halfblock', 'quarterblock', 'chafa']`. Add `ChafaRenderer::reset()` for long-running process re-check.

**Why:** Users may want to know what protocols the library can render regardless of terminal capabilities.

**Severity:** medium

**Conditions for success:**
- `Mosaic::supportedProtocols()` returns the full list
- `ChafaRendererTest::testAvailableResets()` verifies reset functionality

**Related code locations:**
- `src/Mosaic.php` — new static method
- `src/Renderer/ChafaRenderer.php:19` — `private static ?bool $available = null`

---

### 1.6 Fix `SyncAsyncRenderer` futureTick exception handling — FINDING 22

**What:** Wrap the `Loop::futureTick()` callback in a try/catch that rejects the deferred on exception. Currently if `doRender()` throws, the exception propagates into the event loop and the promise never settles.

**Why:** Uncaught exceptions in the futureTick callback can crash the event loop or leave promises pending forever.

**Severity:** high

**Conditions for success:**
- `SyncAsyncRendererTest::testRenderAsyncRejectsOnException()` — mocks `Mosaic::render()` to throw, verifies promise rejects
- Existing `AsyncRendererTest` tests still pass

**Related code locations:**
- `src/SyncAsyncRenderer.php:31` — `Loop::futureTick()` call
- `src/SyncAsyncRenderer.php:36-48` — `doRender()` method

**Investigation notes:**
- The `doRender()` at L42-47 already has try/catch — the issue is that `Loop::futureTick(fn() => $this->doRender(...))` wraps it, so the exception propagates OUT of the callback into the event loop

```php
// src/SyncAsyncRenderer.php:25-34 (proposed fix)
public function renderAsync(ImageSource $image, int $width, int $height): PromiseInterface
{
    $deferred = new Deferred();

    try {
        Loop::futureTick(fn() => $this->doRender($image, $width, $height, $deferred));
    } catch (\Throwable $e) {
        $deferred->reject($e);
    }

    return $deferred->promise();
}
```

---

## Phase 2: Performance Improvements [PENDING]

### 2.1 Stripe-process SixelRenderer accum array — FINDING 7

**What:** Process the image in 64-row stripes rather than the full image at once. Maintain a 2-3 row overlap buffer for error diffusion propagation.

**Why:** Full-resolution float array (e.g., 2000×1000 for a 200×100 cell render) consumes ~8 MB heap per render. Stripe processing bounds peak memory.

**Severity:** high

**Conditions for success:**
- `SixelRendererTest::testMemoryStripeProcessing()` — render large image, verify peak memory stays bounded
- Visual output matches non-striped version for same dither settings
- All existing `SixelRendererTest` tests pass (all dither modes, color counts)

**Related code locations:**
- `src/Renderer/SixelRenderer.php:388-437` — `ditheredIndexGrid()` method

**Investigation notes:**
- Floyd-Steinberg/Atkinson/Stucki diffusion only propagates ±1-2 rows from current pixel
- Atkinson (6 neighbors, max row offset 2) and Stucki (max row offset 2) both fit in a 3-row buffer
- Process in stripes of 64 rows + 2-row overlap, emit each stripe's sixel data before proceeding

---

### 2.2 Replace strided iteration with direct stride offsets in SixelRenderer::samplePixels() — FINDING 8

**What:** Replace the `continue`-based stride loop with two nested loops using direct stride offsets.

**Why:** Branch misprediction on `continue` in tight loops is suboptimal on modern CPUs. Direct strided access eliminates the branch.

**Severity:** medium

**Conditions for success:**
- `SixelRendererTest::testSamplePixelsMatchesFullScan()` — verify sampled output matches full scan for same image

**Related code locations:**
- `src/Renderer/SixelRenderer.php:178-201` — `samplePixels()` method

**Investigation notes:**
- Current: `for ($y=0;$y<$h;$y++) for ($x=0;$x<$w;$x++) if (($i++ % $step) !== 0) continue;`
- Replace with: `for ($y=0;$y<$h;$y+=$rowStep) for ($x=0;$x<$w;$x+=$colStep)`

---

### 2.3 Optimize `ImageSource::fromString()` to avoid double temp file — FINDING 9

**What:** Refactor `fromString()` to use `imagecreatefromstring()` directly instead of writing to a temp file and re-reading via `fromFile()`.

**Why:** Creates two temp file operations per call — wasteful and could fail if temp disk is full.

**Severity:** medium

**Conditions for success:**
- `ImageSourceTest::testFromStringRoundTrip()` — create from GD resource, convert to bytes, re-create via `fromString()`, verify dimensions and bytes match
- `ImageSourceTest::testFromStringWithTransparentPng()` — transparency is preserved

**Related code locations:**
- `src/ImageSource.php:96-112` — `fromString()` method

**Investigation notes:**
- `imagecreatefromstring()` at L328 already handles this path for `fromString()` bytes
- Use `imagecreatefromstring()` + `imagepng($img, 'php://temp')` + `stream_get_contents()` to get PNG bytes directly

---

### 2.4 Add `allowedSchemes` to `fromUrlAsync()` — FINDING 12 (async path)

**What:** Same as 1.2 but for the async `fromUrlAsync()` path which uses ReactPHP `Browser::get()`.

**Why:** Same SSRF vulnerability exists in the async path.

**Severity:** critical (security) — same issue as 1.2 but different code path

**Conditions for success:**
- `ImageSourceUrlTest::testFromUrlAsyncRejectsFileScheme()` — verifies async path also blocks `file://`
- Existing async URL tests pass

**Related code locations:**
- `src/ImageSource.php:207-235` — `fromUrlAsync()` method

---

## Phase 3: Code Complexity & DRY Improvements [PENDING]

### 3.1 Extract `Renderer::prepareRender()` base method — FINDING 15

**What:** Add a `protected function prepareRender(ImageSource $image, int $width, ?int &$height): int` method to an abstract base class or trait. Every renderer's identical validation and height-computation logic is extracted there.

**Why:** 7 renderers have copy-pasted 4-line validation + height-computation blocks. Extracting to one place ensures consistent error messages and reduces maintenance burden.

**Severity:** medium

**Conditions for success:**
- All renderer tests pass — behavior unchanged
- New test: `RendererContractTest::testPrepareRenderValidatesWidth()` — verify all renderers share identical validation message

**Related code locations:**
- `src/Renderer/Renderer.php` — interface (consider abstract base class or trait)
- `src/Renderer/KittyRenderer.php:34-45` — duplicated boilerplate
- `src/Renderer/SixelRenderer.php:50-67` — duplicated boilerplate
- `src/Renderer/HalfBlockRenderer.php:33-47` — duplicated boilerplate
- `src/Renderer/QuarterBlockRenderer.php:51-64` — duplicated boilerplate
- `src/Renderer/Iterm2Renderer.php:21-34` — duplicated boilerplate
- `src/Renderer/AsciiRenderer.php:35-47` — duplicated boilerplate
- `src/Renderer/ChafaRenderer.php:61-78` — duplicated boilerplate

**Investigation notes:**
- Interface can't have `protected` methods — use an abstract `AbstractRenderer` base class or a `RenderValidation` trait
- Trait approach avoids breaking `final` renderer classes; inject via `use RenderValidationTrait`
- Method signature: `protected function prepareRender(ImageSource $image, int $width, ?int &$height): int`

---

### 3.2 Extract `dist()` and `luma()` to `SugarCraft\Core\Util\ColorUtil` — FINDING 16

**What:** Add static methods `ColorUtil::squaredDistance(array $a, array $b): int` and `ColorUtil::luma(int $r, int $g, int $b): int` to a new `ColorUtil` class. Update `QuarterBlockRenderer` and `AsciiRenderer` to use the shared utility.

**Why:** Both functions are duplicated verbatim between `QuarterBlockRenderer` (L161-173) and `AsciiRenderer` (L86).

**Severity:** medium

**Conditions for success:**
- `ColorUtilTest` — new unit tests for `squaredDistance()` and `luma()`
- `QuarterBlockRendererTest::testRenderCellUsesCorrectLuma()`
- `AsciiRendererTest::testLumaMatchesSharedUtil()`

**Related code locations:**
- `src/Renderer/QuarterBlockRenderer.php:161-173` — private `dist()` and `luma()` methods
- `src/Renderer/AsciiRenderer.php:86` — inline luma calculation

**Investigation notes:**
- Create `SugarCraft\Core\Util\ColorUtil` as a standalone utility class
- BT.601 luma: `(77R + 150G + 29B) >> 8`
- Squared distance: `(dr*dr + dg*dg + db*db)`

---

### 3.3 Split `QuarterBlockRenderer::renderCell()` into 4 methods — FINDING 5

**What:** Decompose the 56-line `renderCell()` method (L100-155) into:
- `findSeedPair(array $quads): array{0:int, 1:int}` — indices of most-distant pair
- `groupQuadrantsBySeed(array $quads, int $fgSeed, int $bgSeed): array{0:int,1:int}` — mask + fg/bg group sums
- `computeCellColors(...): array{0:array,1:array}` — averaged fg/bg colors
- `renderCell()` — orchestrates and returns final string

**Why:** The method name doesn't reflect its multiple responsibilities. Splitting improves testability and readability.

**Severity:** medium

**Conditions for success:**
- All `QuarterBlockRendererTest` tests pass
- New tests for each extracted method: `testFindSeedPair()`, `testGroupQuadrantsBySeed()`, `testComputeCellColors()`
- `renderCell()` becomes ≤20 lines

**Related code locations:**
- `src/Renderer/QuarterBlockRenderer.php:100-155`

---

### 3.4 Extract RLE inner loop from `SixelRenderer::emitBand()` — FINDING 6

**What:** Extract the inner RLE encoding loop (L586-611) into a separate `emitRleForColor()` method. Optionally extract per-column bitmask building into a `buildColumnBits()` helper.

**Why:** `emitBand()` has 4 levels of nesting. Extracting RLE reduces cognitive complexity and improves testability.

**Severity:** medium

**Conditions for success:**
- All `SixelRendererTest` tests pass
- `emitBand()` reduced to ≤40 lines after extraction

**Related code locations:**
- `src/Renderer/SixelRenderer.php:549-615` — `emitBand()` method

---

## Phase 4: API & Design Improvements [PENDING]

### 4.1 Make `MosaicBuilder::build()` default explicit — FINDING 1

**What:** Add `MosaicBuilder::sixel(Dither $dither = Dither::FloydSteinberg): self` static factory for symmetry with `Mosaic::sixel()`, and update doc-comment to document the Sixel default.

**Why:** Silent Sixel default is surprising API. SugarCraft convention favors explicit factories.

**Severity:** medium

**Conditions for success:**
- `MosaicBuilderTest::testBuildWithNoConfigDefaultsToSixel()` — documents behavior
- Explicit: `MosaicBuilder::sixel()->withDither(Dither::Stucki)->build()` works

**Related code locations:**
- `src/Mosaic.php:557-575` — `MosaicBuilder::build()` method
- `src/Mosaic.php:563-564` — Sixel default instantiation

---

### 4.2 Document `Mosaic::autoFromPalette()` to use more of `ProbeReport` — FINDING 2

**What:** Expand `autoFromPalette()` (L144-166) to check `TrueColor`, `Color256`, and `BasicAscii` capabilities from the `ProbeReport` before falling back to `halfBlock()`.

**Why:** The `$report` parameter is passed in but used for only two checks. The full capability report from `candy-palette` is discarded.

**Severity:** low

**Conditions for success:**
- `MosaicAutoTest::testAutoFromPaletteUsesTrueColor()` — verifies Kitty is returned when `TrueColor` is reported alongside `KittyKeyboard`

**Related code locations:**
- `src/Mosaic.php:144-166`

**Investigation notes:**
- `PaletteCapability` enum includes: `TrueColor`, `Color256`, `BasicAscii`, `KittyKeyboard`, `NoColor`
- Current code only checks `KittyKeyboard` and `NoColor`, falling back to `halfBlock()`

---

### 4.3 Document `AnimationDriver::subscriptions()` returning null — FINDING 27

**What:** Add a doc-comment to `AnimationDriver::subscriptions()` explaining this is intentional — `AnimationDriver` is purely tick-driven and does not receive keyboard/mouse events.

**Why:** Could confuse callers who expect subscription-based events.

**Severity:** low

**Conditions for success:**
- `AnimationDriverTest::testSubscriptionsReturnsNull()` documents the contract

**Related code locations:**
- `src/AnimationDriver.php:95-98`

---

### 4.4 Document `PixelGrid` semi-transparent pixel limitation — FINDING 26

**What:** Add a clear doc-comment to `PixelGrid::fromGd()` and class doc-comment explaining that semi-transparent pixels (alpha 1-126) are treated as fully opaque.

**Why:** The limitation is in CALIBER_LEARNINGS but not in the source code itself.

**Severity:** low

**Conditions for success:**
- Reader of `PixelGrid::fromGd()` understands the alpha handling limitation

**Related code locations:**
- `src/PixelGrid.php:16-25` — class doc-comment
- `src/PixelGrid.php:39` — `fromGd()` doc-comment

---

### 4.5 Add `validateUrlScheme()` helper and lang key for invalid scheme — FINDING 12

**What:** Create `validateUrlScheme()` private static helper in `ImageSource` and add `image_source.url_invalid_scheme` lang key.

**Why:** Reused by both `fromUrl()` and `fromUrlAsync()`. DRY, centralized validation.

**Severity:** low (but required for 1.2 and 2.4)

**Conditions for success:**
- Centralized in one place, called from both `fromUrl()` and `fromUrlAsync()`

**Related code locations:**
- `src/ImageSource.php` — new private static method
- `lang/en.php` — add `'image_source.url_invalid_scheme' => 'URL scheme {scheme} is not in the allowed list: {allowed}'`

---

## Phase 5: Missing Features — New APIs [PENDING]

### 5.1 Implement GIF frame extraction in candy-flip — FINDING 17

**What:** The finding correctly notes this belongs in `candy-flip`. Add a `GifDecoder` class that decodes `ImageSource` (GIF format) into `Animation`.

**Why:** Users have `Animation` and `AnimationDriver` but no way to load a GIF file as an `Animation`.

**Severity:** medium

**Conditions for success:**
- `GifDecoder::decode(ImageSource)` returns `Animation`
- Full pipeline: `ImageSource::fromFile('animated.gif')` → `GifDecoder::decode()` → `AnimationDriver::render()` shows animation

**Related code locations:**
- `candy-mosaic/src/Animation.php` — immutable value object (exists)
- `candy-mosaic/src/AnimationDriver.php` — drives animation (exists)
- `candy-mosaic/CALIBER_LEARNINGS.md:33-37` — notes GIF belongs in candy-flip

**Investigation notes:**
- Use `imagecreatefromgif()` to extract individual frames, build `Animation::fromFrames()`
- If candy-flip doesn't exist yet, document the gap in CALIBER_LEARNINGS

---

### 5.2 Document ProcessAsyncRenderer as future consideration — FINDING 18 ⏭️

**What:** Document in `CALIBER_LEARNINGS.md` that `SyncAsyncRenderer` provides no parallelism and a `ProcessAsyncRenderer` using `react/child-process` would enable true concurrent frame computation.

**Severity:** low (future consideration)

**Conditions for success:**
- Documented in CALIBER_LEARNINGS.md

---

### 5.3 Document relationship between `Detect::probe()` and `TerminalProbe` — FINDING 20

**What:** Clarify in `Mosaic::auto()` doc-comment the precedence: `Detect::probe()` is tried first, falls back to `TerminalProbe::run()`, then to `halfBlock()`.

**Why:** The two detection systems may disagree. The fallback logic is convoluted and undocumented.

**Severity:** medium

**Conditions for success:**
- `Mosaic::auto()` doc-comment explains the detection chain
- `Mosaic::diagnose()` is mentioned as the debug tool

**Related code locations:**
- `src/Mosaic.php:94-125`
- `src/Detect.php` — `Detect::probe()` implementation

---

### 5.4 Clarify ChafaRenderer availability memoization behavior — FINDING 21

**What:** Add doc-comment to `ChafaRenderer::$available` explaining per-process memoization and the `reset()` method (from 1.5).

**Why:** Appropriate for CLI but could surprise daemon authors.

**Severity:** low

**Conditions for success:**
- Doc-comment on `private static ?bool $available = null;` explains behavior

**Related code locations:**
- `src/Renderer/ChafaRenderer.php:19`

---

### 5.5 Consider cancellation support in `AsyncRenderer` interface — FINDING 24 ⏭️

**What:** Document as a known limitation in `CALIBER_LEARNINGS.md`. The `AsyncRenderer` interface returns `PromiseInterface` but provides no cancellation mechanism.

**Severity:** low (future consideration)

**Conditions for success:**
- Documented in CALIBER_LEARNINGS.md

---

## Phase 6: Async Behavior Refinements [PENDING]

### 6.1 Fix `AdaptiveImage::renderAsync()` cache-hit to use immediate resolve — FINDING 23

**What:** When serving a cache hit in `renderAsync()`, use `React\Promise\Promise::resolve()` directly instead of deferring to `Loop::futureTick()`.

**Why:** Adds unnecessary event-loop iterations for every cached render in tight rendering loops.

**Severity:** low

**Conditions for success:**
- `AdaptiveImageTest::testRenderAsyncCacheHitIsImmediate()` — verifies cached path resolves synchronously

**Related code locations:**
- `src/AdaptiveImage.php:93-100`

**Investigation notes:**
- Current: `Loop::futureTick(fn() => $deferred->resolve($this->cache[$key]))`
- Fix: `return \React\Promise\Promise::resolve($this->cache[$key]);`

---

### 6.2 Add comment to outer catch in `Mosaic::auto()` explaining fallback conditions — FINDING 25

**What:** Add a comment in the outer `catch (\Throwable)` block (L121-124) explaining what would cause `TerminalProbe::run()` to throw and what falling back to `halfBlock()` means.

**Why:** If terminal detection silently falls back to half-block, the user has no signal. Document the fallback conditions.

**Severity:** low

**Conditions for success:**
- Comment clearly explains: (1) `TerminalProbe::run()` throws on platform detection errors, (2) `halfBlock()` means "no image protocol detected, using universal fallback"

**Related code locations:**
- `src/Mosaic.php:121-124`

---

## Phase 7: Positive Findings — No Changes Needed [COMPLETE]

The following findings are positive — the code is correct and well-designed. No action needed:

- **7.1** DiskCache FORMAT_VERSION versioning scheme — excellent design, auto-retirement on format changes
- **7.2** TmuxPassthroughDecorator properly handles DCS, APC, and OSC sequences separately
- **7.3** Deadline class in Detect.php is a clean monotonic clock abstraction using `hrtime(true)`
- **7.4** AdaptiveImage::touchLru() properly maintains both cache map and LRU ordering array
- **7.5** Test coverage is thorough — comprehensive tests for delete API, URL fetching, async rendering, DA1 probing, LRU eviction
- **7.6** Renderer interface contract is clean — each renderer independently implements all 5 methods following ISP
- **7.7** GD resource management is consistent — every code path has corresponding `imagedestroy()` in `finally` or immediate cleanup
- **7.8** ChafaRenderer static memoization is appropriate for CLI tools (single process, known environment)
- **7.9** Header injection protection is correctly implemented with `preg_match('/[\r\n]/', $line)` in `formatHeaders()`
- **7.10** DiskCache key hashing is correct — keys are SHA-1 hashed before use in file paths
- **7.11** SixelRenderer `supportsAlpha()` returning `false` is correct — CALIBER_LEARNINGS documents this limitation

---

## Implementation Notes

### Dependencies and Execution Order

- Phase 1 items (1.1, 1.2, 1.3, 1.4, 1.5, 1.6) are all independent and can be executed in parallel
- Phase 2 items (2.1, 2.2, 2.3, 2.4) are independent except 2.4 depends on 1.2/4.5
- Phase 3 items (3.1, 3.2, 3.3, 3.4) are independent
- Phase 4 items (4.1-4.5) are independent
- Phase 5 items (5.1-5.5) are independent except 5.1 is cross-library work
- Phase 6 items (6.1, 6.2) are independent

### New Lang Keys Needed

- `image_source.url_invalid_scheme` — "URL scheme {scheme} is not in the allowed list: {allowed}" (used by 1.2, 2.4, 4.5)

### New Files to Create

- `candy-core/src/Util/ColorUtil.php` — shared `luma()` and `squaredDistance()` static methods (3.2)
- `candy-flip/src/GifDecoder.php` — GIF frame extraction (5.1, if candy-flip exists)

### Cross-Library Dependencies

- Finding 17 (GIF decoder) requires `candy-flip` — depends on whether it exists in monorepo
- Finding 16 (ColorUtil) requires adding to `candy-core` which is a dependency of candy-mosaic

### Testing Requirements

All changes must pass `vendor/bin/phpunit` in the `candy-mosaic` directory before any PR.

### Risk Notes

- Phase 2.1 (memory optimizations) may affect output quality if stripe overlap is insufficient — must benchmark with Atkinson dither which propagates ¾ of error
- Phase 6.1 (removing futureTick for cache hits) changes async behavior — verify no downstream consumers expect strictly async resolution for cache hits
