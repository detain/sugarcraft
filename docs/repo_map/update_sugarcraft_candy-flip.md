# Overview

**candy-flip** is SugarCraft's ASCII GIF viewer — a PHP port of `namzug16/gifterm` (Go) that decodes `.gif` files via `ext-gd`, downsamples frames to a cell grid, and renders animations as ANSI-colored Unicode block-glyphs or luminance-ramp ASCII. It sits at the intersection of image processing, terminal rendering, and TUI interactivity.

**Biggest opportunity areas:**
- Downsampling quality (Lanczos3/Mitchell-Netravali vs current area-average)
- Full disposal method pipeline (DISPOSAL_RESTORE / DISPOSAL_PREVIOUS not applied)
- Floyd-Steinberg dithering integration (exists but unwired)
- Half-block rendering for 2× vertical resolution
- Terminal graphics protocol integration (Sixel/Kitty/iTerm2 via candy-mosaic)

**Biggest missing capabilities:**
- APNG / WebP input support (GIF-only)
- Parallel frame decoding
- Half-block preset (`PRESET_HALFBLOCK`)
- Remote URL input
- Video file support (`.mp4`, `.webm`)

---

# Internal Capability Summary

## Current Architecture

```
candy-flip/src/
├── Decoder.php           # Hand-rolled GIF89a byte-stream parser → list<Frame>
├── Frame.php             # Pure value: 2D RGB grid + delay/disposal/transparent
├── Downsampler.php       # NEAREST or AREA_AVERAGE pixel→cell downsampling
├── Renderer.php          # ANSI emitter: PRESET_SOLID (█) / PRESET_DENSITY (ASCII ramp)
├── Player.php            # TEA Model: init/update/view + tick-driven playback
├── TickMsg.php           # Frame-advance Msg
├── Lang.php              # i18n facade
├── Cache/FrameCache.php  # WeakMap<Frame,string> memoization
└── Dither/FloydSteinberg.php  # Error-diffusion dithering (unwired)
```

## Current Features

| Feature | Status |
|---------|--------|
| Hand-rolled GIF89a parsing (zero temp-file I/O) | ✅ |
| Per-frame GCE delay (centiseconds) | ✅ |
| Transparent-pixel awareness in downsampling | ✅ |
| Per-frame Local Color Table support | ✅ |
| DISPOSAL_NONE / DISPOSAL_RESTORE / DISPOSAL_PREVIOUS tracking | ✅ (tracked, not applied) |
| Adaptive TTY sizing via `SizeIoctl` | ✅ |
| WeakMap frame render cache | ✅ |
| Floyd-Steinberg error-diffusion dithering | ✅ (unwired) |
| Multiple downsampling modes (NEAREST, AREA_AVERAGE) | ✅ |
| Interactive player (pause/step/preset-toggle/quit) | ✅ |
| i18n with 16 locales | ✅ |
| 256-frame cap to prevent OOM | ✅ |

## Rendering Systems

| Preset | Mechanism | Quality |
|--------|-----------|---------|
| `PRESET_SOLID` | `\033[48;2;R;G;Bm <space>` — full-cell block, 24-bit bg | High (24-bit color, 1:1 cell) |
| `PRESET_DENSITY` | `\033[38;2;R;G;Bm <glyph>` — luminance-mapped ASCII ramp | Medium (10-char ramp) |

## Extension Systems

- `FrameCache` uses `WeakMap<object, string>` — automatic GC when frame is garbage-collected
- `FloydSteinberg::dither()` is a standalone utility not wired into the `Decoder` pipeline
- `Renderer` supports `withAdaptiveSize()` and `withConstraints()` for viewport-aware rendering

## Strengths

1. **Zero temp-file I/O** — all GIF parsing in-memory via `imagecreatefromstring()`
2. **Per-frame GCE timing** — carries forward zero-delay frames per GIF spec
3. **Transparent-pixel handling** — skips transparent pixels in area-average
4. **Immutable + fluent patterns** — `Frame` is readonly value, `Player` follows TEA model
5. **WeakMap cache** — automatic memory management in long-running players
6. **Comprehensive test suite** — 10 test files covering all major subsystems

## Weaknesses

1. **Area-average blurs edges** — no Lanczos3/Mitchell option
2. **Disposal methods not applied** — renders each frame in isolation
3. **FS dithering unwired** — exists but not in pipeline
4. **No half-block mode** — 1× vertical resolution only
5. **GIF-only input** — no APNG, WebP, video
6. **No protocol output** — text-only, no Sixel/Kitty/iTerm2
7. **Sequential decoding** — no parallel frame decoding

---

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|------|-----------|---------------------------|----------|
| `namzug16/gifterm` | Primary upstream | Minimal Go GIF→ANSI viewer, basic architecture reference | Critical |
| `nalediym/gifterm` (Rust) | Active fork | kitty-GPU rendering, Lanczos3, SHA-256 disk cache | High |
| `Zebbeni/ansipx` (Go) | Sampling comparison | 6 sampling modes, 3 dithering modes, 14 diffusion matrices | High |
| `Gaurav-Gosain/jif` (Go) | Bubble Tea integration | Half-block rendering, Bubble Tea TUI, disposal handling, remote URLs | High |
| `hzeller/timg` (C++) | Quality benchmark | Sixel/Kitty/iTerm2/quarter-blocks, video support, thumbnail mode | Medium |
| `blacktop/go-termimg` (Go) | Protocol rendering | Kitty/Sixel/iTerm2/Unicode halfblocks, LRU cache, parallel base64 | High |
| `charmbracelet/bubbletea` (Go) | TEA framework | Elm architecture, Cmd/Tick pattern, mouse/keyboard, subscriptions | High |
| `charmbracelet/x/cellbuf` (Go) | Cell buffer | Damage tracking, cell-based rendering, wide char handling | Medium |
| `textualize/textual` (Python) | Reactive patterns | Reactive state, message pump, CSS layout, compositor | Medium |
| `ratatui/ratatui` (Rust) | Buffer diffing | Immediate-mode with buffer diffing, Cassowary layout, widget traits | Medium |
| `sugarcraft/candy-mosaic` | Sister library | Protocol rendering (Kitty/Sixel/iTerm2/HalfBlock), LRU cache, animation driver | Critical |
| `charmbracelet/vhs` (Go) | Tape/recording | .tape DSL, frame capture, FFmpeg encoding, theme system | Low |
| `charmbracelet/glow` (Go) | TUI patterns | Lipgloss styling, viewport scrolling, status bar patterns | Low |

---

# Feature Gap Analysis

## Critical

### 1. Full Disposal Method Pipeline
**Description:** `Frame::$disposal` is tracked and parsed but `Player::view()` renders each frame in isolation without compositing against a canvas. DISPOSAL_RESTORE (clear to background) and DISPOSAL_PREVIOUS (restore previous content) are not applied.
**Why it matters:** Complex animated GIFs (especially those with transparency and disposal) animate incorrectly — ghosting, incorrect background restoration, missing previous-frame restoration.
**Source:** `docs/repo_map/sugarcraft_candy-flip.md` (self-analysis); `Gaurav-Gosain/jif` handles disposal properly
**Implementation ideas:**
- Maintain a composite canvas (`\GdImage`) across frames
- For DISPOSAL_RESTORE: clear canvas to background color before next frame
- For DISPOSAL_PREVIOUS: snapshot canvas before compositing new frame, restore from snapshot
- Transparent cells in new frame sample from composite canvas
**Estimated complexity:** High — requires re-architecting Player to hold canvas state
**Expected impact:** Fixes animation quality for 20-30% of real-world GIFs

### 2. Floyd-Steinberg Integration
**Description:** `FloydSteinberg::dither()` exists but is not wired into the `Decoder` pipeline. Grayscale/limited-palette output is unimplemented.
**Why it matters:** Dithering against a fixed palette enables candy-flip to render in 16-color or 256-color terminals where 24-bit output isn't available.
**Source:** `docs/repo_map/sugarcraft_candy-flip.md`; `Zebbeni/ansipx` has 14 diffusion matrices
**Implementation ideas:**
- Add `Decoder::decodeWithDither(Dither $dither, array $palette)` method
- Wire `FloydSteinberg::dither()` before downsampling
- Add `Dither` enum: `None`, `FloydSteinberg`, `Stucki`, `Atkinson` (candy-mosaic has Stucki/Atkinson)
- Add `PRESET_PALETTE` render mode that outputs palette-indexed glyphs
**Estimated complexity:** Medium
**Expected impact:** Enables 256-color terminal support

## High Value

### 3. Half-Block Rendering Preset
**Description:** `PRESET_SOLID` uses full-cell `█` (1× vertical resolution). Half-blocks (`▀` upper + `▄` lower) would double effective vertical resolution with 24-bit color per half.
**Why it matters:** jif and ansipx both use half-blocks. Doubling vertical resolution significantly improves visual quality for the same terminal width.
**Source:** `Gaurav-Gosain/jif`, `Zebbeni/ansipx`, `docs/repo_map/sugarcraft_candy-mosaic.md` (HalfBlockRenderer)
**Implementation ideas:**
- In `Renderer`, add `PRESET_HALFBLOCK` constant
- Scale source to `cellW × cellH*2` pixels before sampling
- For each cell: top pixel → foreground SGR `▀`, bottom pixel → background SGR `▄`
- Handle transparent halves (skip SGR if half is transparent, emit opposite half-block)
**Estimated complexity:** Low — standalone Renderer mode, no decoder changes
**Expected impact:** 2× visual quality vertically for same width

### 4. Higher-Quality Downsampling (Lanczos3)
**Description:** candy-flip uses area-average (smooth but blurs edges). `nalediym/gifterm` and `Zebbeni/ansipx` use Lanczos3 for sharper results.
**Why it matters:** Area averaging blurs edges and text in GIFs. Lanczos3 preserves sharp transitions.
**Source:** `nalediym/gifterm` (Rust fork), `Zebbeni/ansipx`, `docs/repo_map/sugarcraft_candy-flip.md`
**Implementation ideas:**
- GD's `imagecopyresampled()` uses bilinear interpolation — a true Lanczos3 requires 2-pass resampling or `ext-imagick`
- Add `Downsampler::LANCZOS3` mode that uses `imagecopyresampled()` with careful dimension calculations as approximation
- Alternatively, implement a precomputed Lanczos3 kernel (8-tap filter) convolved per-cell
**Estimated complexity:** Medium
**Expected impact:** Sharper text and edge rendering

### 5. Terminal Graphics Protocol Output
**Description:** On terminals supporting Kitty/Sixel/iTerm2, candy-flip produces text where binary protocols would look dramatically better.
**Why it matters:** `candy-mosaic` handles protocol rendering but is not integrated with `candy-flip`'s `Player`/`TickMsg` animation loop.
**Source:** `blacktop/go-termimg`, `docs/repo_map/sugarcraft_candy-mosaic.md`
**Implementation ideas:**
- Add `KittyRenderer::renderFrame()` + `delete()` to `candy-mosaic`'s animation driver
- Create `PlayerProtocol` that wraps `Player` but outputs via `candy-mosaic` protocol renderers instead of ANSI
- Detect terminal capability via `Detect::cached()` and auto-select best protocol
**Estimated complexity:** High — requires architectural integration between two libs
**Expected impact:** Near-lossless rendering on supporting terminals (kitty, WezTerm, foot)

### 6. APNG Input Support
**Description:** candy-flip is GIF-only. APNG is animated PNG with wider color support.
**Why it matters:** APNG is increasingly common for animated images on the web. Ext-gd has `imagecreatefrompng()`.
**Source:** `nalediym/gifterm` roadmap mentions APNG
**Implementation ideas:**
- Extend `Decoder` to detect PNG vs GIF via magic bytes
- If PNG with ACTL chunk (animation control), extract frames via `imagecreatefromstring()` sequentially
- Add `Decoder::decodeApng()` path
**Estimated complexity:** Medium
**Expected impact:** Broader input format support

## Medium Priority

### 7. Remote URL Input
**Description:** `jif` supports HTTP remote URLs for GIF sources.
**Why it matters:** Enables fetching GIFs from the web without manual download.
**Source:** `Gaurav-Gosain/jif`
**Implementation ideas:**
- Add `--url` flag to CLI
- Use `file_get_contents()` with stream context for HTTP/HTTPS
- Validate Content-Type is `image/gif` or `image/png` before decoding
**Estimated complexity:** Low
**Expected impact:** Convenience for web-sourced GIFs

### 8. WebP Input Support
**Description:** Both candy-flip and go-termimg lack WebP due to ext-gd limitations.
**Why it matters:** WebP offers better compression than GIF with full alpha.
**Source:** `blacktop/go-termimg` planning WebP
**Implementation ideas:**
- PHP 8.4+ FFI to `libwebp` direct binding
- Or: use `symfony/process` to call `cwebp` as external command
**Estimated complexity:** High (FFI) or Medium (external process)
**Expected impact:** Better compression for animated web images

### 9. Parallel Frame Decoding
**Description:** Frames are decoded sequentially. ReactPHP async could parallelize.
**Why it matters:** For 100+ frame GIFs, decoding time can exceed frame display time.
**Source:** `blacktop/go-termimg` uses goroutine parallelism
**Implementation ideas:**
- Use `React\Promise\PromiseInterface` with `Promise::all()` for concurrent frame decoding
- Apply `pcntl_fork()` for true parallelism (single-threaded PHP limitation)
- Queue frames in `React\EventLoop\StreamIterator` for sequential async
**Estimated complexity:** High — significant architectural change
**Expected impact:** Faster decode for large GIFs

---

# Algorithm / Performance Opportunities

## Current Approach vs External

| Aspect | candy-flip | External (nalediym/gifterm, jif, ansipx) |
|--------|-----------|------------------------------------------|
| Downsampling | Area-average (blurs edges) | Lanczos3 / Mitchell-Netravali (sharp edges) |
| Disposal | Tracked, not applied | Fully applied with canvas compositing |
| Dithering | Floyd-Steinberg (unwired) | Multiple algorithms (FS, Stucki, Atkinson, Bayer) |
| Caching | WeakMap in-memory | SHA-256 disk cache (nalediym), LRU (go-termimg) |
| Protocol output | ANSI text only | Kitty/Sixel/iTerm2 binary protocols |
| Half-blocks | Not implemented | Standard in jif, ansipx |
| Parallel decode | Sequential | Goroutine parallelism |

## Why External Is Better

1. **Lanczos3** preserves sharp edges better than area-average. Area-average is O(1) per cell but produces blurry results on text/line art GIFs.
2. **Disposal canvas** enables correct animation replay for complex GIFs. Frame-in-isolation rendering causes ghosting on disposal-aware GIFs.
3. **Disk cache** (nalediym/gifterm's SHA-256 approach) persists decoded frames across invocations — identical GIF played twice only decodes once.
4. **Protocol output** delivers near-lossless quality on modern terminals — a 1000×1000 image at 96 dpi looks photographic vs pixelated ASCII.

## Tradeoffs

- **Lanczos3** is 3-5× slower than area-average; acceptable for pre-decode step
- **Disposal canvas** requires mutable `\GdImage` state in Player — challenges immutable design
- **Disk cache** adds filesystem I/O; needs cache directory management
- **Protocol output** requires `candy-mosaic` as dependency; increases coupling

## Applicability to candy-flip

- **Lanczos3**: Implementable via `imagecopyresampled()` approximation or `ext-imagick`
- **Disposal canvas**: Implementable as private `$canvas` property on `Player` with `applyDisposal()` method
- **Disk cache**: Add `Cache/DiskCache` using `sha1_file()` + `~/.cache/candy-flip/`
- **FS dithering**: Wire existing `FloydSteinberg` into `Decoder` pipeline

---

# Architecture Improvements

## 1. Disposal-Aware Player Canvas

Currently `Player` holds `frames: list<Frame>` and renders each frame independently. To support disposal:

```php
// Player.php additions
private ?\GdImage $canvas = null;
private ?\GdImage $prevCanvas = null;  // for DISPOSAL_PREVIOUS

private function ensureCanvas(Frame $frame): void { ... }
private function applyDisposal(int $method): void { ... }
```

Each `view()` call composites the current frame onto `$canvas` before rendering to ANSI. This preserves immutability of `Frame` while allowing mutable canvas state in `Player`.

## 2. Dither-Aware Decoder Pipeline

Add a `DecoderOptions` value object:

```php
final class DecoderOptions {
    public function __construct(
        public readonly int $cellsW,
        public readonly int $cellsH,
        public readonly DownsamplerMode $downsampleMode = DownsamplerMode::AREA_AVERAGE,
        public readonly ?FloydSteinberg $ditherer = null,  // null = no dither
        public readonly ?array $palette = null,  // null = 24-bit direct
    ) {}
}
```

Wire `FloydSteinberg::dither()` into the pipeline when `$ditherer` is set.

## 3. Protocol-Aware Renderer Bridge

Create `RendererBridge` that wraps both ANSI and protocol renderers:

```php
interface RenderTarget {
    public function renderFrame(Frame $frame, int $imageId): string;
    public function delete(int $imageId): string;
}
```

`AnsiRenderTarget` and `KittyRenderTarget` both implement this. `Player` accepts a `RenderTarget` in its constructor, defaulting to ANSI.

---

# API / Developer Experience Improvements

## 1. Progressive Frame Decoding

```php
// Generator-based decoding for memory efficiency
/** @return \Generator<int, Frame> */
public static function decodeGenerator(string $path, int $cellsW, int $cellsH): \Generator { ... }
```

## 2. Decoder Progress Callback

```php
public static function decode(
    string $path,
    int $cellsW,
    int $cellsH,
    ?callable(int $current, int $total): void $onProgress = null,
): list<Frame> { ... }
```

## 3. Fluent Decoder Builder

```php
$frames = Decoder::forPath('animation.gif')
    ->targetSize(80, 40)
    ->downsampleMode(DownsamplerMode::LANCZOS3_APPROX)
    ->withDithering(FloydSteinberg::create(), Palette::webSafe())
    ->decode();
```

## 4. Player Configuration Options

```php
$player = Player::new($frames)
    ->withPreset(Preset::HALFBLOCK)      // NEW: half-block mode
    ->withMaxFPS(30)                      // NEW: FPS cap
    ->withScaleMode(ScaleMode::FIT)       // NEW: scale mode
    ->withProtocolOutput(true);           // NEW: auto-detect best protocol
```

---

# Documentation / Cookbook Opportunities

## 1. GIF Anatomy Guide
Explain GIF89a structure: header, LSD, GCT, GCE, LCT, LZW sub-blocks, trailer — with hex dump examples.

## 2. Disposal Methods Illustrated
Visual guide showing how each disposal method (0-3) affects frame compositing, with before/after hex dumps.

## 3. Terminal Protocol Comparison
Table comparing ANSI text vs Kitty vs Sixel vs iTerm2 — quality, terminal support, performance tradeoffs.

## 4. Custom Palette Dithering Recipe
How to use `FloydSteinberg` with a custom palette (e.g., IBM VGA 16-color, WebSafe 216-color).

## 5. Integration with candy-mosaic
How to use `candy-mosaic`'s protocol renderers for near-lossless output within `candy-flip`'s player loop.

---

# UX / TUI Improvements

## 1. Status Bar Enhancement

Current: `frame 3/45  ·  solid  ·  playing   space pause   ←/→ step   d preset   q quit`

Enhanced with:
- Current GIF dimensions and frame size
- Estimated playback time
- FPS counter
- Protocol indicator (ANSI/Sixel/Kitty)

## 2. Visual Frame Step Indicator

When stepping through frames manually, show a progress bar or seek slider:

```
frame 12/45  ████████████░░░░░░░░░░░░  26%  ←/→ step   d preset   q quit
```

## 3. Luminance Ramp Configuration

Like `namzug16/gifterm`'s `-cd` flag, allow custom density strings:

```bash
candy-flip my.gif --density " .:-=+*#%@"    # default
candy-flip my.gif --density " .,:;+*#%@"     # lighter
candy-flip my.gif --density " ░▒▒▓▓██"    # block-focused
```

## 4. Theme Integration

Support TokyoNight, Dracula, Nord themes via `candy-palette` for the status bar and any text overlays.

---

# Testing / Reliability Improvements

## 1. Snapshot Tests for Disposal Behavior

Add golden file tests for GIFs with disposal methods:
- `disposal_none.gif` → no special handling
- `disposal_restore.gif` → background restored between frames
- `disposal_previous.gif` → previous frame content restored

## 2. Protocol Output Tests

Add tests for each protocol renderer:
- `KittyRenderer` chunking, alpha, dimensions
- `SixelRenderer` palette, dithering, band encoding
- `Iterm2Renderer` inline image format

## 3. Performance Regression Tests

```php
public function testDecodePerformance(): void {
    $start = hrtime(true);
    $frames = Decoder::decode('fixtures/large_animation.gif', 120, 60);
    $elapsed = (hrtime(true) - $start) / 1e6; // ms
    $this->assertLessThan(500, $elapsed, 'Decode took too long');
}
```

## 4. Fuzz Testing

Add a fuzzer that generates random GIF byte streams to test decoder robustness.

## 5. Cross-Terminal Render Verification

Golden files for render output in both light/dark themes across different character encodings.

---

# Ecosystem / Integration Opportunities

## 1. candy-flip × candy-mosaic Integration

`candy-mosaic` provides:
- `KittyRenderer`, `SixelRenderer`, `Iterm2Renderer` 
- `Detect::cached()` for capability detection
- `AnimationDriver` as candy-core Model

**Integration:** Create `candy-flip/src/ProtocolPlayer.php` that wraps `candy-mosaic`'s renderers in the `Player` loop.

## 2. VHS Demo Tapes

Add `.tape` files under `candy-flip/.vhs/`:
- `play.tape` — basic playback
- `density.tape` — luminance ramp mode
- `step.tape` — frame stepping
- `halfblock.tape` — half-block mode (when implemented)

## 3. sugar-charts Integration

Animated sparklines or bar charts could use `candy-flip`'s frame rendering for real-time data visualization.

## 4. candy-vcr Session Replay

`candy-vcr` could record `candy-flip` playback sessions for regression testing.

---

# Notable PRs / Issues / Discussions

## nalediym/gifterm (Rust Fork)

**Relevance:** kitty-GPU rendering approach, SHA-256 disk cache, Lanczos3 scaling
**Key insight:** Transmitting via Kitty graphics protocol eliminates client-side rendering entirely — terminal handles compositing
**Lessons:** The disk cache approach (hash-based) is more robust than in-memory WeakMap which doesn't persist across invocations

## Gaurav-Gosain/jif (Bubble Tea GIF Viewer)

**Relevance:** Bubble Tea TUI integration, half-block rendering, disposal handling, remote URL support
**Key insight:** Bubble Tea's `tea.Model` is the natural integration point for animated GIF playback
**Lessons:** Disposal methods are non-negotiable for correct animation; upstream gifterm ignores them

## Zebbeni/ansipx (Comprehensive ANSI Art)

**Relevance:** Multiple sampling algorithms (nearest/bicubic/bilinear/Lanczos2/Lanczos3/Mitchell), multiple dithering modes, multiple Unicode block variants
**Key insight:** `jif` uses half-blocks for 2× vertical resolution; `ansipx` adds quarter-blocks for even finer detail
**Lessons:** Sampling and dithering options are the primary quality differentiators in ASCII rendering

## blacktop/go-termimg (Go Terminal Image)

**Relevance:** Protocol rendering (Kitty/Sixel/iTerm2/halfblocks), LRU resize cache, parallel Base64 encoding, animation support
**Key insight:** Protocol rendering is 30-40× faster than Sixel client-side rendering but requires terminal support
**Lessons:** LRU cache size of 4 in candy-mosaic vs 100 in go-termimg is a trade-off between memory and cache hit rate

## charmbracelet/bubbletea (Tea Model)

**Relevance:** Cmd/Tick subscription pattern used by candy-flip's Player
**Key insight:** `Cmd::tick($interval, TickMsg::class)` schedules recurring ticks; `Batch` allows parallel commands
**Lessons:** The Elm architecture's clean separation of update/view makes testing straightforward — `Player` update is a pure function

---

# Recommended Roadmap

## Immediate Wins (1-2 weeks)

1. **Wire Floyd-Steinberg into Decoder** — add `Decoder::decodeWithDither()` path
2. **Add PRESET_HALFBLOCK** — double vertical resolution in Renderer
3. **Add custom density ramp CLI flag** — `candy-flip my.gif --density "..."`
4. **Improve status bar** — add dimensions, FPS, playback time

## Medium-Term Improvements (1-2 months)

5. **Full disposal pipeline** — canvas compositing in Player
6. **Lanczos3 downsampling mode** — `imagecopyresampled()` approximation or imagick extension
7. **candy-mosaic integration** — `ProtocolPlayer` using Kitty/Sixel/iTerm2 renderers
8. **APNG input support** — detect and decode animated PNG
9. **Disk cache** — SHA-256 hashed frame cache to `~/.cache/candy-flip/`

## Major Architectural Upgrades (3-6 months)

10. **Protocol auto-detection** — use `Detect::cached()` to select best available protocol
11. **Parallel frame decoding** — ReactPHP async queue for multi-frame decode
12. **WebP via FFI** — PHP 8.4+ libwebp binding for next-gen format support
13. **Video input** — ffmpeg-based decoding for MP4/WebM

## Experimental Ideas

14. **AI upscaling** — integrate `sugarcraft/honey-` math libs for AI-based upscaling before downsampling
15. **Gif-to-ASCII streaming** — real-time webcam/gstreamer feed rendered as ASCII
16. **Distributed decode** — Redis-based parallel decode of massive GIF collections

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Recommended Priority |
|-------------|--------|------------|------|---------------------|
| Wire Floyd-Steinberg | High | Low | Low | **P0 — Immediate** |
| PRESET_HALFBLOCK | High | Low | Low | **P0 — Immediate** |
| Full disposal pipeline | Critical | High | Medium | **P1 — High** |
| Custom density ramp | Medium | Low | Low | **P1 — High** |
| Status bar enhancement | Low | Low | Low | **P1 — High** |
| Lanczos3 downsampling | High | Medium | Medium | **P1 — High** |
| candy-mosaic integration | Critical | High | Medium | **P2 — Medium** |
| APNG input support | Medium | Medium | Low | **P2 — Medium** |
| Disk cache (SHA-256) | Medium | Medium | Low | **P2 — Medium** |
| Protocol auto-detection | High | Medium | Low | **P2 — Medium** |
| Parallel frame decode | Medium | High | Medium | **P3 — Low** |
| WebP via FFI | Medium | High | High | **P3 — Low** |
| Video input (ffmpeg) | Low | High | Medium | **P3 — Low** |

---

# Final Strategic Assessment

**candy-flip** is a well-engineered v1 port that faithfully extends its upstream (`namzug16/gifterm`) with superior per-frame GCE timing, transparent-pixel awareness, interactive TEA-model player, adaptive TTY sizing, and comprehensive test coverage. Its architecture is sound — zero temp-file I/O via hand-rolled GIF89a parsing, immutable value objects, and WeakMap caching are all principled choices.

The package's primary strategic gap is **visual quality** — area-average downsampling blurs edges, disposal methods are tracked but not applied (causing animation errors on complex GIFs), and half-block rendering is absent. The second gap is **output fidelity** — text-only ANSI output occupies a specific niche (universal terminal compatibility) but sacrifices dramatic visual quality available via terminal graphics protocols.

**Immediate priorities:**
1. Wire the existing `FloydSteinberg` ditherer into the pipeline (low-hanging fruit with high impact for 256-color terminals)
2. Implement `PRESET_HALFBLOCK` for 2× vertical resolution on truecolor terminals
3. Implement full disposal canvas in `Player` — this is critical for correct animation replay

**Medium-term priorities:**
4. Integrate with `candy-mosaic` for protocol-aware rendering — this transforms candy-flip from a text-only viewer into a quality-conscious multi-protocol viewer
5. Add Lanczos3 downsampling via `imagecopyresampled()` approximation or `ext-imagick`

**Long-term positioning:**
candy-flip should occupy the role of the **universal ASCII GIF viewer** — works everywhere, requires only PHP 8.3+ and ext-gd, with optional protocol enhancements via candy-mosaic. This is a valuable niche: not the highest quality (protocol rendering wins) but the most portable and dependency-free.

The competitive landscape reveals that text-based ANSI rendering is a deliberate tradeoff: universal compatibility at the cost of visual fidelity. The cutting edge (nalediym/gifterm, hzeller/timg, blacktop/go-termimg) uses binary graphics protocols for near-lossless rendering. candy-flip's contribution is making ASCII GIF playback accessible and dependency-free on any PHP 8.3+ system with ext-gd.

The most significant architectural insight from the competitive analysis is that **disposal methods are non-negotiable** — any serious GIF player must implement the full disposal pipeline. The second insight is that **Lanczos3 is the minimum acceptable quality** for downsampling in 2026 — area-average was acceptable in 2010 but modern implementations expect sharper results.
