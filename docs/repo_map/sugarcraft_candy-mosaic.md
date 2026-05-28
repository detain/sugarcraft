# SugarCraft CandyMosaic — Innovation & Comparison Report

## Metadata

- **Library:** `candy-mosaic` (`sugarcraft/candy-mosaic`)
- **Namespace:** `SugarCraft\Mosaic`
- **Upstream:** `charmbracelet/x/mosaic` (Go)
- **Status:** 🟢 v1 ready (public API + tests + docs + demo)
- **PHP:** ^8.3
- **ext-gd:** Required
- **Key Dependencies:** `sugarcraft/candy-core`, `sugarcraft/candy-sprinkles`, `react/promise`

---

## 1. Architecture Overview

CandyMosaic is a PHP port of `charmbracelet/x/mosaic` that renders images (PNG/JPEG/static GIF) into terminal emulators via multiple graphics protocols with automatic capability detection.

### Source Tree

```
candy-mosaic/src/
├── AdaptiveImage.php              # LRU memoizing wrapper (key: "WxH")
├── Animation.php                  # Immutable frame sequence value object
├── AnimationDriver.php             # Model implementing candy-core tick() API
├── AsyncRenderer.php               # Interface for async rendering
├── Capability.php                  # Immutable terminal capability snapshot
├── CellSize.php                   # Font-size value object (cellW × cellH)
├── Detect.php                      # Terminal probing (env + DA1 + XTWINOPS)
├── Dither.php                      # Error-diffusion enum (None/FS/Stucki/Atkinson)
├── FrameTickMsg.php                # Internal Msg for frame-advance ticks
├── ImageSource.php                # Image bytes + format + dimensions
├── KittyOptions.php                # Kitty protocol options (transmit/place/compress)
├── Lang.php                        # i18n facade extending SugarCraft\Core\I18n\Lang
├── Mosaic.php                      # Facade (Picker pattern) + MosaicBuilder
├── PixelGrid.php                   # 2-D grid of RGBA cells at cell resolution
├── PrecomputedImage.php           # Frozen render result with dimensions
├── Scale.php                       # Enum: Fit/Fill/Stretch/None/Crop
├── SyncAsyncRenderer.php           # ReactPHP drop-in (futureTick defer)
├── TmuxPassthroughDecorator.php   # Renderer decorator for tmux DCS envelope
└── Renderer/
    ├── ChafaRenderer.php           # External command (libchafa) fallback
    ├── HalfBlockRenderer.php       # Unicode ▀ with 24-bit fg/bg SGR
    ├── Iterm2Renderer.php          # iTerm2 OSC 1337 inline images
    ├── KittyRenderer.php           # Kitty APC graphics (chunked PNG)
    ├── QuarterBlockRenderer.php    # Unicode ░▒▓█ 2×2 sub-pixel
    ├── QuarterBlockTransparentTest.php
    ├── Renderer.php                # Interface contract
    ├── SixelRenderer.php           # DEC Sixel with median-cut quantizer
    └── ... (KittyZlibTest.php, etc.)
```

---

## 2. Protocol Analysis

### 2.1 Kitty Graphics Protocol (`KittyRenderer`)

**Mechanism:** APC (Application Program Command) sequences carrying chunked base64-encoded PNG data.

**Implementation details (`src/Renderer/KittyRenderer.php`):**
- PNG bytes are base64-encoded and split into 4092-byte chunks (CHUNK_SIZE, accounting for base64 padding overhead)
- Each chunk emitted via `Ansi::kittyGraphicsChunk($chunk, $more)` where `$more=true` sets `m=1` (more data follows); final chunk sets `m=0`
- Transmission: `Ansi::kittyGraphicsBegin(['c' => $width, 'r' => $effectiveHeight])` → chunk stream → `Ansi::kittyGraphicsEnd()`
- Supports **alpha/transparency** (full RGBA PNG passed through)
- Supports **virtual image placement** via `KittyOptions` — transmit once with `a=p` storing in terminal, then reference by ID+offset for bandwidth savings
- Supports **zlib compression** (`f=1` with `gzcompress()`) for large images on slow links
- Supports **animation** via `renderFrame($image, $width, $height, $imageId)` which uses a stable image ID for targeted delete+redraw
- **Delete support:** APC `a=d` action via `Ansi::kittyGraphicsClear((int) $imageId)`

**Protocol strengths:**
- Best quality/speed tradeoff among modern protocols
- Virtual images enable efficient multi-placement without re-transmission
- Terminal handles aspect-ratio and scaling — no pixel manipulation needed
- Universal deletion API via image ID

**Protocol weaknesses:**
- Kitty-only (kitty, ghostty, WezTerm)
- Large images produce long base64 strings (~33% larger than raw)

**SugarCraft differentiators vs upstream:**
- `renderFrame()` + `delete()` form a clean animation API matched to candy-core's `Model::update()/view()` cycle
- `KittyOptions` value object with fluent `withZIndex()`, `withCompression()`, `withUseVirtual()` mirrors the builder pattern but as immutable value
- Chunking is explicit in source (not abstracted behind a library call)

---

### 2.2 iTerm2 Inline Images (`Iterm2Renderer`)

**Mechanism:** OSC (Operating System Command) 1337 carrying base64-encoded PNG or JPEG.

**Implementation details (`src/Renderer/Iterm2Renderer.php`):**
- Uses `imagepng()` to encode source; passes PNG bytes directly if already PNG format
- Base64-encoded in a single OSC 1337 sequence: `Ansi::iterm2InlineImage($base64, ['width' => $width, 'height' => $effectiveHeight, 'preserveAspectRatio' => true])`
- Terminal handles aspect-ratio and scaling
- **Supports alpha** (PNG preserves transparency)
- **No targeted delete by ID** — `delete()` emits OSC 1337 "Pop" which removes the topmost image from the stack only

**Protocol strengths:**
- Simple, well-supported in iTerm2, WezTerm, mintty
- JPEG encoding option for photographs (smaller than PNG)
- Good quality with zero client-side pixel manipulation

**Protocol weaknesses:**
- No per-image deletion — "Pop" removes topmost, not by ID
- No virtual placement or compression
- OSC sequences can be interrupted by terminal noise

---

### 2.3 Sixel (`SixelRenderer`)

**Mechanism:** DEC Sixel — a 6-pixel-tall band encoding scheme using a palette of up to 256 colors.

**Implementation details (`src/Renderer/SixelRenderer.php`):**
- **Median-cut quantizer** (`medianCut()`) — recursively splits RGB color space to produce ≤256 palette entries
- **Error-diffusion dithering** (`Dither` enum: None, FloydSteinberg, Stucki, Atkinson) applied per-pixel with floating-point accumulation
- Sixel band encoding: each 6-pixel-tall band encodes one bitmask per color
- **RLE encoding** (`emitRle()`) — consecutive identical sixel bytes compressed as `!count<char>`
- Palette emitted as color introducers: `Ansi::sixelColorIntroducer($i, $r, $g, $b)` → `DCS p SixelColor p;p;p;pb`
- DECSIXEL header: `Ansi::sixelDcsHeader($width, $effectiveHeight)` → `DCS q P1;Px;Py`
- Terminator: `Ansi::sixelTerminator()` → BEL (`\x07`)
- **Does NOT support alpha** — no transparency in Sixel protocol itself
- **No delete** — DECSIXEL has no remove command

**Dither algorithm details (`diffuseError()`):**

| Algorithm | Neighbors | Error propagated | Characteristics |
|-----------|-----------|-----------------|----------------|
| Floyd–Steinberg | 4 (right, bottom-left, bottom, bottom-right) | 75% (7/16+3/16+5/16+1/16) | Classic, smooth gradients |
| Stucki | 12 (wider spread) | ~95% | Sharper, less artifact |
| Atkinson | 6 (right, bottom-right, bottom, bottom-left) | 75% | Lighter result, Apple original |

**Protocol strengths:**
- Widest terminal support (xterm, foot, mlterm, wezterm, contour)
- High quality at 256 colors with dithering
- Band structure enables partial rendering

**Protocol weaknesses:**
- No alpha/transparency (1-bit background at best)
- No delete mechanism — cannot remove a rendered sixel image
- 256-color limit per image
- ~90ms render time (vs ~2.5ms for Kitty/iTerm2 per go-termimg benchmarks)
- Client-side pixel manipulation required (quantization + dithering)

**Caliber learnings (`CALIBER_LEARNINGS.md`):**
> **[GD alpha and imagecopyresampled]** `imagecopyresampled` applies alpha-weighted interpolation during resize — it averages alpha channels of neighboring pixels, corrupting transparency information. Workaround: ensure source dimensions match cell dimensions (1:1 pixel mapping) so `imagecopyresampled` performs no interpolation.

---

### 2.4 Unicode Half-Block (`HalfBlockRenderer`)

**Mechanism:** Renders image as Unicode ▀ (U+2580 Upper Half Block) characters with 24-bit foreground + background SGR codes. Each cell shows two vertically-stacked pixel rows.

**Implementation details (`src/Renderer/HalfBlockRenderer.php`):**
- `PixelGrid::fromGd($img, $cellW, $cellH)` resizes source to `cellW × cellH*2` pixels (double height)
- For each cell: top pixel color → foreground SGR, bottom pixel color → background SGR
- **Transparent pixel handling:** GD alpha=127 maps to `null` in PixelGrid cell tuple; null-alpha halves skip SGR codes entirely
- When only one half is transparent: emits the **opposite** half-block glyph (▄ for bottom-transparent, ▀ for top-transparent) with only the visible half's SGR codes
- Output: `implode("\r\n", $lines)` for cross-platform line endings

**Protocol strengths:**
- Universal — works in any truecolor terminal
- No external dependency (self-contained, pure GD + ANSI SGR)
- Supports per-pixel transparency via null-alpha handling

**Protocol weaknesses:**
- Half the vertical resolution of the source image
- 256-color limitation per cell (24-bit fg + 24-bit bg, but terminal may quantize)
- No stored image identity for deletion
- `supportsAlpha()` returns `false` (no alpha blending, just binary transparency)

---

### 2.5 Unicode Quarter-Block (`QuarterBlockRenderer`)

**Mechanism:** Each terminal cell renders a 2×2 group of source pixels. A 4-bit mask (1 bit per quadrant, 1=bright if any RGB > 10) indexes a 16-glyph lookup table using shade characters (░▒▓█).

**Implementation details (`src/Renderer/QuarterBlockRenderer.php`):**
- `PixelGrid::fromGdQuarter($img, $cellW, $cellH)` scales source to `cellW*2 × cellH*2` pixels
- Samples four quadrants (ul, ur, ll, lr) per cell
- 16-entry GLYPH_MAP: 0=space, 1,2,4,8=░, 3,5,6,9,10,12=▒, 7,11,13,14=▓, 15=█
- Same color for fg+bg SGR; bright quads=fg, dim quads=bg
- **No alpha support**

**Protocol strengths:**
- 4× the detail of half-block (4 sub-pixels per cell vs 2)
- Still universal — works in any truecolor terminal
- No external dependency

**Protocol weaknesses:**
- Coarser quantization (4 brightness levels vs continuous color)
- No transparency
- No stored image identity

---

## 3. Terminal Detection (`Detect.php`)

**Precedence chain:** Environment variables (fast path, no TTY I/O) → DA1 query → XTWINOPS font-size probing

**File:** `src/Detect.php` + `src/Deadline.php` (monotonic deadline tracker)

### 3.1 Environment-Based Detection

```php
// KITTY: KITTY_WINDOW_ID, TERM_PROGRAM=WezTerm|ghostty, TERM =~ /xterm-kitty/i
// iTerm2: TERM_PROGRAM=iTerm.app|iTerm2|mintty, LC_TERMINAL=iTerm2
// Sixel: XTERM_VERSION present + TERM ∈ {mlterm, foot, xterm(-256color)?}
```

### 3.2 DA1 Capability Query

```php
private const DA1_QUERY = "\x1b[c";  // DA1 "who are you?"

// Parsed: look for ";4;" or ";4c" or "?4c" in the reply
// Sixel bit (bit 4 in secondary attributes field)
```

**Timeout:** 100ms via `Deadline::in($ms)` using `hrtime(true)` for monotonic timing.

**Caching:** `Detect::cached()` memoizes per-process via `private static ?Capability $cached`.

**Testability:** `Detect::setProbeStdin($fd)` injects a mock stdin for socket-pair testing.

### 3.3 XTWINOPS Font-Size Probing

```php
private const XTWINOP_14 = "\x1b[14t";  // window pixel size
private const XTWINOP_16 = "\x1b[16t";  // cell pixel size
private const XTWINOP_18 = "\x1b[18t";  // terminal cell count
```

Fallback chain: 16t (direct cell size) → 14t + 18t (window pixels ÷ cell count).

### 3.4 tmux Detection

```php
$inTmux = getenv('TMUX') !== false;
```

When inside tmux, all output is wrapped by `TmuxPassthroughDecorator` in the `\x1bPtmux; ... \x1b\\` envelope.

---

## 4. LRU Resize Cache (`AdaptiveImage.php`)

**Architecture:** Memoizing wrapper for `Mosaic` render results.

```php
private array $cache = [];       // key: "WxH", value: encoded bytes
private array $lru = [];         // front=most-recent
private readonly int $maxCache = 4;  // default LRU size

public function render(int $cellWidth, int $cellHeight): string {
    $key = "{$cellWidth}x{$cellHeight}";
    if (isset($this->cache[$key])) {
        $this->touchLru($key);
        return $this->cache[$key];
    }
    $bytes = $this->mosaic->render($this->image, $cellWidth, $cellHeight);
    $this->cache[$key] = $bytes;
    $this->touchLru($key);
    return $bytes;
}
```

**LRU eviction:** `touchLru()` unshift new key, then `while (count($this->lru) > $this->maxCache)` pop and unset.

**Async variant:** `withAsync(?AsyncRenderer $renderer)` configures `SyncAsyncRenderer` (futureTick defer) or a custom `AsyncRenderer` implementation.

**Key comparison with go-termimg:**
- go-termimg uses `sync.RWMutex` for thread-safe LRU with `ResizeCache` struct keyed as `widthxheight_path_srcWidthxsrcHeight` (includes source dimensions in key)
- candy-mosaic uses simpler "cell dimensions only" key — less precise but faster for repeated renders at same size

---

## 5. Parallel Base64 Encoding

CandyMosaic does **not** currently implement parallel base64 encoding. However, `go-termimg` implements this pattern:
- `sync.Pool` for buffer reuse (~33% faster thanstdlib)
- `ParallelBase64Encode` with worker pool threshold: 2× chunk size
- Channel-based job distribution to goroutine pool

**PHP equivalent opportunity:** Using `React\Promise\PromiseInterface` with `React\Promise\Deferred` in a worker pool via `pcntl_fork()` or `clue/reactphp-promise-stream` for parallel chunk encoding.

---

## 6. tmux Passthrough (`TmuxPassthroughDecorator.php`)

**Problem:** Terminal multiplexers (tmux, screen) intercept DCS (`\x1bP`), APC (`\x1b_`), and OSC (`\x1b]`) sequences. They must be forwarded through the multiplexer's passthrough envelope.

**Solution:** Wraps each escape sequence in `\x1bPtmux; <escaped-inner> \x1b\\` where lone `\x1b` inside the payload is doubled to `\x1b\x1b`.

```php
// DCS: \x1bP … \x1b\\ → \x1bPtmux; \x1b\x1bP … \x1b\x1b\\ \x1b\\
// APC: \x1b_ … \x1b\\  → \x1bPtmux; \x1b\x1b_ … \x1b\x1b\\ \x1b\\
// OSC: \x1b] … \x07/ST   → \x1bPtmux; \x1b\x1b] … \x1b\x1b\\ \x1b\\
```

**Implementation:** `wrap()` scans the input byte-by-byte, draining plain text between escape sequences, then re-encodes each sequence with the tmux envelope.

**Note from CALIBER_LEARNINGS:** tmux 3.3+ requires `set -g allow-passthrough on` (default: off).

---

## 7. Animation (`Animation.php` + `AnimationDriver.php`)

**`Animation`** — immutable value object:
```php
public readonly array $frames;     // list<ImageSource>
public readonly array $delaysMs;   // list<int> (milliseconds per frame)

public static function fixed(array $frames, int $delayMs): self;  // uniform delay
public function withFrame(int $index, ImageSource $frame, int $delayMs): self;  // replace one frame
```

**`AnimationDriver`** — candy-core `Model` implementation:
- `init()`: Returns `Cmd::tick($delaySec, FrameTickMsg::class)` when not paused
- `update(FrameTickMsg)` — advances `$index = ($index + 1) % $animation->frameCount()`, schedules next tick
- `view()` — emits `$renderer->delete($imageId) . $renderer->render($frame, $cellWidth, $cellHeight)` for clean per-frame redraw

**SugarCraft innovation vs upstream:** No equivalent animation driver in `charmbracelet/x/mosaic` — this is a pure SugarCraft addition.

---

## 8. Scale Modes (`Scale.php`)

```php
enum Scale {
    case Fit;     // preserve AR, fit within bounds, centered
    case Fill;     // preserve AR, fill bounds, crop overflow from center
    case Stretch;  // ignore AR, stretch to exact bounds
    case None;    // no scaling, clip if larger, use native if smaller
    case Crop;    // center-crop at native pixel density, scale to exact bounds
}
```

`computeDimensions()` returns `['srcX', 'srcY', 'srcW', 'srcH', 'dstW', 'dstH']` describing the crop rect and target size to pass to `ImageSource::crop()` + `resize()`.

---

## 9. Performance Considerations

### 9.1 Rendering Time (relative)

| Protocol | Relative Speed | Notes |
|---------|---------------|-------|
| Kitty | ~1× (fastest) | Base64 encoding + chunking; terminal handles scaling |
| iTerm2 | ~1× | Single OSC sequence; terminal handles scaling |
| HalfBlock | ~2-3× | GD resize + pixel grid extraction |
| QuarterBlock | ~2-3× | GD double-resolution resize + quadrant sampling |
| Chafa | Variable | External process spawn + IPC overhead |
| Sixel | ~30-40× | Median-cut quantizer + error-diffusion + band encoding |

*Based on go-termimg benchmarks (~2.5ms for Kitty/iTerm2 vs ~90ms for Sixel)*

### 9.2 Memory Usage

- **Kitty/iTerm2:** PNG bytes in memory, base64-encoded string, chunk strings
- **Sixel:** Full pixel grid (w×h floats for error accumulation) + quantized palette + index grid
- **HalfBlock/QuarterBlock:** GD image + 2× or 4× intermediate surface + cell grid arrays
- **Chafa:** External process + temp file on disk

### 9.3 Cache Efficiency

- **AdaptiveImage LRU:** Only 4 entries by default — small but sufficient for viewport re-renders
- **Font-size detection:** Cached per-process via `Detect::$cached`
- **go-termimg comparison:** Uses 100-entry LRU keyed on `widthxheight_path_srcWidthxsrcHeight`; includes source dimensions for more precise caching

---

## 10. Terminal Compatibility Matrix

| Terminal | Kitty | iTerm2 | Sixel | Half-Block | Quarter-Block |
|----------|-------|--------|-------|------------|----------------|
| kitty | ✅ | ❌ | ❌ | ✅ | ✅ |
| ghostty | ✅ | ❌ | ❌ | ✅ | ✅ |
| WezTerm | ✅ | ✅ | ✅ | ✅ | ✅ |
| iTerm2 | ❌ | ✅ | ❌ | ✅ | ✅ |
| foot | ❌ | ❌ | ✅ | ✅ | ✅ |
| xterm | ❌ | ❌ | ✅* | ✅ | ✅ |
| mlterm | ❌ | ❌ | ✅ | ✅ | ✅ |
| mintty | ❌ | ✅ | ❌ | ✅ | ✅ |
| contour | ❌ | ❌ | ✅ | ✅ | ✅ |
| Apple Terminal | ❌ | ❌ | ❌ | ✅ | ✅ |
| VS Code (embedded) | ❌ | ❌ | ❌ | ✅ | ✅ |

*Sixel requires XTERM_VERSION env var to be set

---

## 11. Comparison with Third-Party Repos

### 11.1 `charmbracelet/x/mosaic` (Primary Upstream)

| Feature | charmbracelet/x | SugarCraft candy-mosaic |
|---------|-----------------|-------------------------|
| Protocols | Kitty, Sixel | Kitty, iTerm2, Sixel, HalfBlock, QuarterBlock, Chafa |
| Terminal detection | Basic env-var only | Full DA1 + XTWINOPS probing with caching |
| tmux passthrough | Not mentioned | Full decorator implementation |
| Animation driver | None | AnimationDriver as candy-core Model |
| LRU cache | None | AdaptiveImage with configurable maxCache |
| Scale modes | None | Full Scale enum (Fit/Fill/Stretch/None/Crop) |
| Dithering | None (external) | Floyd-Steinberg, Stucki, Atkinson via Dither enum |
| Kitty compression | Unknown | gzcompress via withCompression() |
| Kitty virtual placement | Mentioned | Full transmit/place API via KittyOptions |
| i18n | None | Lang facade via SugarCraft\Core\I18n\T |

**Assessment:** CandyMosaic is a **superset** of the upstream implementation in terms of protocol coverage, terminal detection, and TUI integration (animation driver). The upstream is a reference for the core Kitty/Sixel encoding; candy-mosaic adds the PHP-idiomatic facade and polish.

---

### 11.2 `blacktop/go-termimg` (Image Rendering, Go)

| Feature | go-termimg | SugarCraft candy-mosaic |
|---------|-------------|-------------------------|
| Protocols | Kitty, Sixel, iTerm2, Halfblocks | Same set |
| Terminal detection | Full DA1 + XTWINOPS + font-size cache | Same (nearly identical algorithm) |
| LRU resize cache | 100-entry RWMutex LRU | 4-entry LRU (AdaptiveImage) |
| Parallel base64 | sync.Pool + worker pool | Not implemented |
| Kitty virtual placement | Unicode placeholders (U+10EEEE + diacritics) | Direct a=p + a=T via KittyOptions |
| Kitty compression | Yes | Yes (gzcompress) |
| Animation | Partial (TODO comments) | Full AnimationDriver |
| Async rendering | AsyncRenderWorker + request coalescing | SyncAsyncRenderer (futureTick) |
| Protocol precedence | Kitty > iTerm2 > Sixel > Halfblocks | Same |
| tmux passthrough | Yes (DCS envelope) | Yes (TmuxPassthroughDecorator) |
| TUI integration | ImageWidget for Bubbletea | AnimationDriver for candy-core |
| WebP support | Planned (not implemented) | Not supported (ext-gd limitation) |
| Image formats | PNG, JPEG, GIF, WebP (planned) | PNG, JPEG, GIF only |

**Assessment:** Go-termimg is the more mature implementation with parallel encoding, larger LRU, and Bubbletea widget integration. CandyMosaic matches it on protocol coverage and terminal detection, exceeds it on animation support, but lacks parallel encoding. WebP support is missing in both (ext-gd limitation).

---

### 11.3 `ratatui/ratatui-image` (Image Widget, Rust)

| Feature | ratatui-image | SugarCraft candy-mosaic |
|---------|--------------|------------------------|
| Protocols | Sixel, Kitty, iTerm2, Halfblocks | Same |
| Terminal detection | Picker via cap_parser | Same algorithm (DA1 + XTWINOPS) |
| Font-size detection | Yes (pixel→cell mapping) | Yes (CellSize + Detect) |
| Widget model | Stateless Image, StatefulImage, SlicedImage | AdaptiveImage, PrecomputedImage |
| Async rendering | ThreadProtocol (mpsc channel) | SyncAsyncRenderer (ReactPHP) |
| Sliced rendering | Band-sliced Sixel for scrolling | Not implemented |
| Chafa integration | Yes (optional libchafa) | ChafaRenderer (external command) |
| tmux passthrough | Yes | Yes |
| Protocol enum | Protocol<T> monomorphic | Renderer interface + Mosaic facade |
| Resize modes | Fit, Crop, Scale | Fit, Fill, Stretch, None, Crop |
| Animation | None mentioned | AnimationDriver |

**Assessment:** Ratatui-image's architecture is closest to candy-mosaic — both use a capability-detection + renderer-interface pattern. Ratatui's ThreadProtocol (channel-based worker thread) is more sophisticated than candy-mosaic's SyncAsyncRenderer (futureTick only), but both target the same goal of non-blocking image rendering.

---

### 11.4 `charmbracelet/vhs` (Terminal GIF Recorder)

VHS uses `go-termimg` internally via its `tui` package to render images in the xterm.js canvas during frame capture. The relevant connection is:
- VHS renders images during tape playback for demo recording
- CandyMosaic's `AnimationDriver` + `KittyRenderer::renderFrame()` provides equivalent per-frame delete+redraw semantics that would enable VHS-style animation capture

**Assessment:** VHS is a consumer of image rendering technology, not a competing implementation. CandyMosaic's animation support positions it to potentially power future SugarCraft demo tools.

---

## 12. Extension Opportunities

### 12.1 WebP Support

Both candy-mosaic and go-termimg lack WebP support due to ext-gd limitations. PHP 8.4+ with FFI could enable WebP via `libwebp` direct binding.

### 12.2 Parallel Base64 Encoding

Using `React\Promise\PromiseInterface` with a worker pool:
```php
// Chunk base64 string into N parts
// Process chunks in parallel via pcntl_fork or thread pool
// Reassemble results
```

### 12.3 Sliced/Partial Rendering

Ratui-image implements band-sliced Sixel rendering for smooth scrolling. This would require:
- `SixelRenderer::renderSliced($image, $cellW, $cellH, $skipLines, $dropLines)` 
- Band indexing math from `ratatui-image/src/sliced.rs:L310-331`

### 12.4 Unicode Placeholder Mode (Kitty)

Go-termimg implements Kitty Unicode placeholder mode using U+10EEEE + 297 combining diacritics for scrolling support. This is marked experimental in go-termimg and not implemented in candy-mosaic.

### 12.5 JPEG Encoding Option (iTerm2)

Currently `Iterm2Renderer` always uses PNG. Adding JPEG option would improve performance for photographs:
```php
// Add to Iterm2Renderer:
$jpegBytes = imagejpeg($img, null, 85);  // 85% quality
```

---

## 13. File Index

### Core Implementation Files

| File | Lines | Purpose |
|------|-------|---------|
| `src/Mosaic.php` | 407 | Facade + MosaicBuilder |
| `src/Detect.php` | 457 | Terminal capability detection |
| `src/Renderer/SixelRenderer.php` | 563 | DEC Sixel with median-cut + dithering |
| `src/Renderer/KittyRenderer.php` | 234 | Kitty APC graphics |
| `src/Renderer/Iterm2Renderer.php` | 85 | iTerm2 OSC 1337 |
| `src/Renderer/HalfBlockRenderer.php` | 120 | Unicode half-block |
| `src/Renderer/QuarterBlockRenderer.php` | 128 | Unicode quarter-block |
| `src/Renderer/ChafaRenderer.php` | 115 | External libchafa command |
| `src/Renderer/Renderer.php` | 48 | Interface contract |
| `src/ImageSource.php` | 243 | Image bytes + metadata + crop/resize |
| `src/PixelGrid.php` | 171 | GD→RGB cell grid conversion |
| `src/AdaptiveImage.php` | 154 | LRU memoizing wrapper |
| `src/Animation.php` | 101 | Immutable frame sequence |
| `src/AnimationDriver.php` | 146 | candy-core Model for animation |
| `src/TmuxPassthroughDecorator.php` | 164 | tmux DCS envelope wrapper |
| `src/Scale.php` | 137 | Scale mode enum + computeDimensions |
| `src/Dither.php` | 36 | Error-diffusion enum |
| `src/KittyOptions.php` | 161 | Kitty protocol options value object |
| `src/Capability.php` | 77 | Terminal capability snapshot |
| `src/SyncAsyncRenderer.php` | 49 | ReactPHP futureTick defer |

### Test Files

| File | Tests | Coverage |
|------|-------|----------|
| `tests/KittyRendererTest.php` | 11 | Chunking, alpha, dimensions, error cases |
| `tests/SixelRendererTest.php` | 20+ | Palette, dithering, band encoding, terminator |
| `tests/HalfBlockRendererTest.php` | 11 | Snapshot fixture, transparency, aspect ratio |
| `tests/DetectTest.php` | 30+ | Env detection, DA1 probing, XTWINOPS parsing |
| `tests/AnimationTest.php` | — | — |
| `tests/AnimationDriverTest.php` | — | — |
| `tests/AsyncRendererTest.php` | — | — |
| `tests/Iterm2RendererTest.php` | — | — |

### Fixture Files

| File | Dimensions | Purpose |
|------|-----------|---------|
| `tests/fixtures/8x4_red.png` | 8×4 | Unit test fixture (all red) |
| `tests/fixtures/4x2.png` | 4×2 | Half-block unit test |
| `tests/fixtures/checkerboard.png` | varies | Checkerboard pattern |
| `tests/fixtures/gradient_64x64.png` | 64×64 | Gradient for dither comparison |
| `tests/fixtures/500x400_noise.png` | 500×400 | Large image for chunking tests |
| `tests/fixtures/expected_halfblock.txt` | — | Snapshot for half-block output |

---

## 14. Key Takeaways

1. **Most complete PHP terminal image rendering library** — candy-mosaic covers 6 protocols (Kitty, iTerm2, Sixel, HalfBlock, QuarterBlock, Chafa) with full capability detection, LRU caching, tmux passthrough, and animation support.

2. **Clean Renderer interface** — each protocol implements `render()`, `name()`, `supportsAlpha()`, `delete()` providing a consistent contract for the `Mosaic` facade.

3. **Superior to upstream in TUI integration** — `AnimationDriver` as a candy-core `Model` enables direct use in Bubble Tea-style programs, something the Go upstream lacks entirely.

4. **Performance gap vs go-termimg** — missing parallel base64 encoding, smaller LRU (4 vs 100 entries), no request coalescing in async pipeline. PHP's single-threaded nature makes parallel encoding harder but not impossible with ReactPHP.

5. **Sixel is the workhorse** — highest client-side CPU cost (~30-40× vs Kitty) but widest terminal support. The median-cut + error-diffusion implementation is complete and correct.

6. **HalfBlock is the universal fallback** — works everywhere with 24-bit color but half vertical resolution and no alpha blending. Proper null-alpha handling for transparent pixels is a detail many implementations miss.

7. **WebP is the missing format** — ext-gd limitation shared with go-termimg; would require PHP 8.4+ FFI to `libwebp` for next-generation format support.
