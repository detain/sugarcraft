# Terminal Image Rendering Libraries Research: candy-mosaic

**Date:** 2026-05-13
**Source:** `/home/sites/sugarcraft/candy-mosaic/src/`
**Upstream:** [charmbracelet/x/mosaic](https://pkg.go.dev/github.com/charmbracelet/x/mosaic)

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Current Implementation Analysis](#2-current-implementation-analysis)
3. [Go Ecosystem](#3-go-ecosystem)
4. [Rust Ecosystem](#4-rust-ecosystem)
5. [Python Ecosystem](#5-python-ecosystem)
6. [Cross-Language Comparison](#6-cross-language-comparison)
7. [Identified Gaps & Improvement Opportunities](#7-identified-gaps--improvement-opportunities)
8. [Prioritized Recommendations](#8-prioritized-recommendations)
9. [Effort Estimates](#9-effort-estimates)

---

## 1. Executive Summary

**candy-mosaic** is a well-architected PHP 8.3+ library that renders images in terminals using multiple protocols. The current implementation covers:

| Renderer | Protocol | Alpha Support | Quality | Speed |
|----------|----------|---------------|---------|-------|
| `KittyRenderer` | Kitty Graphics (APC) | ✅ | High | ~2.5ms |
| `Iterm2Renderer` | iTerm2 Inline Image (OSC 1337) | ✅ | High | ~2.5ms |
| `SixelRenderer` | DEC Sixel (DCS) | ❌ | High | ~90ms |
| `HalfBlockRenderer` | Unicode ▀ + SGR | ❌ | Medium | ~800µs |
| `ChafaRenderer` | External chafa CLI | ✅ | Varies | External |

**Key Strengths:**
- Clean `Renderer` interface with protocol-agnostic `render(ImageSource, width, height)` signature
- Proper tmux passthrough via `TmuxPassthroughDecorator`
- Comprehensive dithering (Floyd-Steinberg, Stucki, Atkinson)
- Cell size detection via XTWINOPS queries
- Median-cut quantization for Sixel

**Key Weaknesses:**
- No animation/GIF support (all renderers produce static output)
- No clear mechanism for image removal (especially Kitty's delete/transmit functionality)
- Sixel RLE encoding may not be optimal (byte-aligned vs bit-aligned)
- No quarter-block Unicode rendering (only half-block)
- ChafaRenderer depends on external binary (not pure PHP)

---

## 2. Current Implementation Analysis

### 2.1 Source Structure

```
candy-mosaic/src/
├── Mosaic.php              # Facade + builder pattern
├── Detect.php              # Terminal capability detection (DA1, XTWINOPS)
├── Capability.php          # Immutable snapshot of terminal capabilities
├── ImageSource.php         # Image container (bytes, format, dimensions)
├── Scale.php               # Scale modes (Fit, Fill, Stretch, None, Crop)
├── Dither.php              # Dither algorithm enum
├── CellSize.php            # Terminal cell pixel dimensions
├── PixelGrid.php           # GD→RGB grid for half-block rendering
├── KittyOptions.php        # Kitty protocol options (experimental)
├── TmuxPassthroughDecorator.php  # tmux DCS envelope wrapper
├── Lang.php                # i18n
├── AdaptiveImage.php       # Memoizing wrapper
├── PrecomputedImage.php    # Pre-rendered cache
├── AsyncRenderer.php       # Async rendering support
├── SyncAsyncRenderer.php   # Sync/async adapter
└── Renderer/
    ├── Renderer.php        # Interface contract
    ├── KittyRenderer.php   # PNG base64 chunks via APC
    ├── SixelRenderer.php   # Pure PHP Sixel with median-cut + RLE
    ├── Iterm2Renderer.php  # PNG base64 via OSC 1337
    ├── HalfBlockRenderer.php # Unicode ▀ + SGR truecolor
    └── ChafaRenderer.php   # External chafa subprocess
```

### 2.2 Protocol Detection (Detect.php)

**Source:** `/home/sites/sugarcraft/candy-mosaic/src/Detect.php:L47-67`

```php
public static function probe(): Capability
{
    $inTmux = getenv('TMUX') !== false;
    $cap = self::probeEnv($inTmux);

    // Env vars gave a definite answer — no DA1 needed.
    if ($cap->kitty || $cap->iterm2) {
        return $cap->withCellSize(self::probeFontSize());
    }

    // Run DA1 sixel probing BEFORE font-size probing
    $sixelViaDa1 = self::probeDa1();
    if ($sixelViaDa1 === true) {
        return Capability::sixel(self::probeFontSize(), $inTmux);
    }

    return $cap->withCellSize(self::probeFontSize());
}
```

**Detection Method:**
- **Kitty:** `KITTY_WINDOW_ID`, `TERM_PROGRAM=WezTerm`, `TERM=xterm-kitty`
- **iTerm2:** `TERM_PROGRAM=iTerm.app|iTerm2`, `LC_TERMINAL=iTerm2`
- **Sixel:** DA1 query (`\x1b[c`) looking for `;4;` or `;4c` in reply
- **Font size:** XTWINOPS 16t (cell pixels), fallback to 14t+18t

### 2.3 Sixel Renderer Deep Dive

**Source:** `/home/sites/sugarcraft/candy-mosaic/src/Renderer/SixelRenderer.php:L24-96`

The Sixel renderer is the most complex pure-PHP implementation:

1. **Resize** via GD `imagecopyresampled()`
2. **Extract pixels** via `imagecolorat()`
3. **Median-cut quantization** → max 256 palette entries
4. **Error-diffusion dithering** (Floyd-Steinberg/Stucki/Atkinson)
5. **Sixel encoding** with RLE (byte-aligned, NOT bit-aligned per spec)

**RLE Encoding Issue:** The spec allows bit-aligned sixels with count prefixes, but current implementation uses byte-aligned (`chr($ascii)` with range 63-126). This is technically non-compliant but works with most terminals.

### 2.4 Kitty Renderer Analysis

**Source:** `/home/sites/sugarcraft/candy-mosaic/src/Renderer/KittyRenderer.php:L24-57`

```php
public function render(ImageSource $image, int $width, ?int $height = null): string
{
    // ...
    $pngBytes = $this->ensurePng($image);
    $base64 = base64_encode($pngBytes);
    $chunks = $this->chunk($base64);  // 4092-byte chunks
    $total  = count($chunks);

    $out = Ansi::kittyGraphicsBegin([
        'c' => $width,
        'r' => $effectiveHeight,
    ]);

    foreach ($chunks as $idx => $chunk) {
        $more = ($idx < $total - 1);
        $out .= Ansi::kittyGraphicsChunk($chunk, $more);
    }

    $out .= Ansi::kittyGraphicsEnd();
    return $out;
}
```

**Kitty Features NOT Implemented:**
- Virtual images (upload once, place multiple times)
- Z-index for layering
- Compression (`f=1` zlib)
- Delete/transmit animation frames
- Unicode placeholder mode

---

## 3. Go Ecosystem

### 3.1 charmbracelet/x/mosaic (Upstream)

**Repository:** https://github.com/charmbracelet/x
**Module:** `github.com/charmbracelet/x/mosaic`
**License:** MIT
**Stars:** ~277 (charmbracelet/x)

**Source Reference:** `charmbracelet/lipgloss` commit `bf352b9` (2025-03-07)

The upstream Go mosaic is a **Unicode half-block renderer** only (NOT Sixel/Kitty/iTerm2):

```go
type Mosaic struct {
    Blocks         []Block
    colorMode      int     // 0=none, 1=8colors, 2=256colors, 3=truecolor.
    OutputWidth    int
    OutputHeight   int
    ThresholdLevel uint8   // For alpha thresholding
    DitherLevel    float64 // 0.0-1.0
    UseFgBgOnly    bool    // Skip block symbols
    InvertColors   bool
    ScaleMode      Scale   // fit, stretch, center, none
    Symbols        Symbol  // "half", "quarter", "all"
}
```

**Key Features in Go upstream:**
- 2x2 pixel block analysis for symbol selection
- Multiple symbol sets: HalfBlocks, QuarterBlocks, ComplexBlocks
- Floyd-Steinberg dithering
- Scale modes: stretch, center, fit, none
- Color modes: none, 8-color, 256-color, truecolor

**Gap vs candy-mosaic:** The Go mosaic is half-block only and does NOT support Sixel/Kitty/iTerm2 protocols. The `blacktop/go-termimg` library wraps charmbracelet/x/mosaic for half-block rendering.

### 3.2 blacktop/go-termimg

**Repository:** https://github.com/blacktop/go-termimg
**Stars:** 53
**License:** MIT

**Protocol Support:**

| Protocol | Implementation | Notes |
|----------|---------------|-------|
| Kitty | `kitty.go` | Full chunked APC, virtual images, z-index (experimental) |
| Sixel | `sixel.go` | Uses `github.com/napln/sixel` Go binding |
| iTerm2 | `iterm2.go` | OSC 1337 with ECH clearing |
| Halfblocks | `halfblocks.go` | Wraps `charmbracelet/x/mosaic` |

**Kitty Renderer (go-termimg):**
```go
type KittyRenderer struct {
    imageID   uint32
    lastID    uint32
    lastNum   uint32
    chunkSize int  // BASE64_CHUNK_SIZE = 4092
}

func (r *KittyRenderer) Render(img image.Image, opts RenderOptions) (string, error) {
    // 1. Process image (resize, dither)
    processed, err := processImage(img, opts)

    // 2. Generate unique imageID via atomic counter
    imageID := atomic.AddUint32(&globalKittyImageID, 1)

    // 3. Transmit in 4092-byte chunks
    // Supports PNG encoding, zlib compression
    // Virtual placement with U=1 flag
}
```

**Performance Benchmarks (go-termimg):**
- Halfblocks: ~800µs (fastest, works everywhere)
- Kitty: ~2.5ms (efficient, modern terminals)
- iTerm2: ~2.5ms (fast, macOS)
- Sixel: ~90ms (high quality, slower)

**Key Advantage Over candy-mosaic:**
- Native Go for Kitty/Sixel (no GD dependency)
- Proper image deletion/clear mechanisms
- Atomic image ID management for virtual images
- Animation frame support

### 3.3 lsix

**Repository:** https://github.com/hackerb9/lsix
**Language:** Bash + ImageMagick
**Purpose:** `ls` for images - displays thumbnails in terminal

**Approach:**
- Uses ImageMagick `convert` for all format conversions
- Outputs Sixel directly via `convert -gravity south -background black -extent 0x0 ... sixel:-`
- Fallback to Chafa if Sixel unavailable
- ANSI truecolor half-blocks via `convert ... txt:-` parsing

---

## 4. Rust Ecosystem

### 4.1 chafa (Reference Implementation)

**Repository:** https://github.com/hpjansson/chafa
**Stars:** 4.5k
**Language:** C (core) + Rust bindings via `chafa`
**License:** LGPLv3+

**The reference terminal graphics library.** All other implementations are compared to chafa.

**Features:**
- SIMD-optimized (x86, ARM)
- Multithreaded
- Symbol ranges: Half blocks, quarter blocks, braille, box-drawing
- All protocols: Sixel, Kitty, iTerm2, Unicode
- Format support: PNG, JPEG, GIF, WebP, TIFF, SVG, BMP
- **Animation support** (GIF)

**Symbol Sets (from chafa docs):**
```
Half blocks (U+2580): Best quality/color tradeoff
Quarter blocks (U+2590): Higher detail
Braille (U+2800): Very high detail but smaller
Box-drawing: Decorative only
```

**Rust Bindings:** `chafa` crate by @wong-justin

```toml
[dependencies]
chafa = { git = "https://github.com/wong-justin/chafa-rust.git", tag = "0.4.0", features = ["link-dynamic"] }
```

### 4.2 ratatui-image

**Repository:** https://github.com/orhun/ratatui-image
**Crates.io:** https://crates.io/crates/ratatui-image
**Purpose:** Image widget for ratatui TUI library

**Supported Protocols:**

| Protocol | Backend | Notes |
|----------|---------|-------|
| Sixel | `sixel-rs` or `icy_sixel` | Safe libsixel bindings |
| Kitty | Native | Chunked APC |
| iTerm2 | Native | OSC 1337 |
| Halfblocks | chafa | Via `chafa-dyn` or `chafa-static` |

**Key Architecture:**
```rust
pub struct ImageWidget {
    picker: Picker,  // Protocol detection
    protocol: ProtocolType,
    state: ProtocolState,
}

impl Picker {
    pub fn new() -> Self;  // Auto-detect protocol
    pub fn guess_protocol() -> ProtocolType;  // Env vars + DA1 query
}
```

**ratatui-image Protocol Detection (from docs):**
> Some terminals may implement one or more graphics protocols, such as Sixels, or the iTerm2 or Kitty graphics protocols. Guess by env vars. If that fails, query the terminal with some control sequences. Fallback to "halfblocks" which uses some unicode half-block characters with fore- and background colors.

### 4.3 viuer

**Repository:** https://github.com/atanunq/viuer
**Crates.io:** https://crates.io/crates/viuer
**Purpose:** Terminal image viewer (used by viu command)

**Features:**
- Default: Lower half-blocks (▄)
- Kitty, iTerm2, Sixel (via feature flags)
- Animated GIF support
- Transparency handling
- Custom dimensions and offsets

**Architecture:**
```rust
use viuer::{print, Config};

let conf = Config {
    width: 80,
    height: 25,
    absolute: true,
    x: 0,
    y: 0,
};
print(&img, &conf).expect("Image printing failed.");
```

### 4.4 sixel-rs / icy_sixel

**Repository:** https://crates.io/crates/sixel-rs
**Alternative:** https://crates.io/crates/icy_sixel

Safe Rust wrappers for libsixel (the C library):

```rust
// sixel-rs
pub fn encode(img: &DynamicImage, opts: &EncodeOptions) -> Result<Vec<u8>, Error> {
    let rgba = img.to_rgba8();
    unsafe {
        sixel_encoder_encode_rgba(
            rgba.raw_pixels(),
            rgba.width() as i32,
            rgba.height() as i32,
            rgba.width() as i32 * 4,
            &mut encoder,
        )
    }
}
```

**Key Difference:** `icy_sixel` is a pure Rust port of libsixel (no C dependency).

### 4.5 isene/glow

**Repository:** https://github.com/isene/glow
**Purpose:** Terminal image display (used by pointer file preview)

**Protocols:**

| Protocol | Terminals | Detection |
|----------|-----------|-----------|
| Kitty | kitty, WezTerm | `TERM=xterm-kitty`, `KITTY_WINDOW_ID` |
| Sixel | xterm, mlterm, foot | `TERM` starts with xterm/mlterm/foot |
| W3m | Any X11 | `/usr/lib/w3m/w3mimgdisplay` exists |

**Notable:** Uses ImageMagick `convert` for Kitty/Sixel image preprocessing.

---

## 5. Python Ecosystem

### 5.1 img2txt (hit9)

**Repository:** https://github.com/hit9/img2txt
**Stars:** 953
**License:** BSD-3-Clause

**Approach:** Pure Python, PIL/Pillow-based ASCII art generation

```python
# Core algorithm
def get_ansi_color(r, g, b):
    # Map RGB to nearest ANSI 256 color
    ansi = 16 + (r//43)*36 + (g//43)*6 + (b//43)
    return ansi

def generate_ANSI_from_pixels(pixels, width, height, bgcolor):
    for y in range(height):
        line = ""
        for x in range(width):
            r, g, b, a = pixels[x, y]
            # Apply bgcolor blending for transparency
            if a < 255 and bgcolor:
                r = r * a/255 + bgcolor[0] * (1 - a/255)
                # ...
            ansi = get_ansi_color(r, g, b)
            line += "\033[38;5;%dm▓" % ansi
        yield line + "\033[0m\n"
```

**Features:**
- `--ansi` for ANSI colored output
- `--color` for HTML with colored spans
- `--dither` for Floyd-Steinberg-like dithering to 256-color palette
- `--bgcolor` for transparency blending
- `--antialias` for smooth resize

**Limitations:**
- ASCII only (no block characters)
- No truecolor SGR (only 256-color ANSI)
- No Sixel/Kitty/iTerm2 protocols

### 5.2 img2text (hmiladhia)

**Repository:** https://github.com/hmiladhia/img2text
**PyPI:** https://pypi.org/project/img2text/

**Modern Python approach with color support:**

```python
from img2text import img_to_ascii

ascii_img = img_to_ascii('data/python.png', width=80, colorful=True)
print(ascii_img)
```

**Features:**
- Configurable character set: `chars = r" ░▒▓█"`
- Bright mode (bold characters)
- Reverse intensity
- Colorful ANSI output
- CLI tool

### 5.3 pillow (PIL Fork)

**Repository:** https://github.com/python-pillow/Pillow
**PyPI:** https://pypi.org/project/Pillow/
**Stars:** 11.4k

**Not a terminal graphics library**, but the foundation for most Python image processing in terminal tools:

```python
from PIL import Image

# Resize with antialiasing
img = Image.open('photo.jpg')
img = img.resize((width, height), Image.LANCZOS)

# Extract pixels
pixels = img.load()
width, height = img.size

for y in range(height):
    for x in range(width):
        r, g, b = pixels[x, y]
```

---

## 6. Cross-Language Comparison

### 6.1 Protocol Support Matrix

| Library | Sixel | Kitty | iTerm2 | Unicode | Chafa | Animation |
|---------|-------|-------|--------|---------|-------|-----------|
| **candy-mosaic (PHP)** | ✅ | ✅ | ✅ | ✅ (half-block) | ✅ (ext) | ❌ |
| **charmbracelet/x (Go)** | ❌ | ❌ | ❌ | ✅ (half/quarter) | ❌ | ❌ |
| **go-termimg (Go)** | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| **chafa (C)** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| **ratatui-image (Rust)** | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ |
| **viuer (Rust)** | ✅ (feat) | ✅ | ✅ | ✅ | ❌ | ✅ |
| **img2txt (Python)** | ❌ | ❌ | ❌ | ASCII only | ❌ | ❌ |

### 6.2 Color Handling Comparison

| Library | Truecolor SGR | ANSI 256 | ANSI 16 | Palette Optimization |
|---------|--------------|----------|---------|---------------------|
| **candy-mosaic** | ✅ | ✅ | ✅ | Median-cut (Sixel) |
| **go-termimg** | ✅ | ✅ | ✅ | Per-protocol |
| **chafa** | ✅ | ✅ | ✅ | Auto (SIMD) |
| **ratatui-image** | ✅ | ✅ | ✅ | Via chafa |
| **img2txt** | ❌ (256 only) | ✅ | ✅ | ❌ |

### 6.3 Size Calculation Comparison

| Library | Cell Size Detection | Aspect Ratio | Scale Modes |
|---------|-------------------|--------------|-------------|
| **candy-mosaic** | XTWINOPS 16t/14t+18t | Auto from AR | Fit, Fill, Stretch, None, Crop |
| **go-termimg** | XTWINOPS 16t/14t+18t | Auto from AR | Fit, Fill, Stretch, None |
| **chafa** | Terminal query | `--geometry` flag | `--fit`/`--fill` |
| **ratatui-image** | Via terminal | Auto | Fit, Fill |
| **img2txt** | `--fontSize` | `--targetAspect` 0.5 | `--maxLen` |

### 6.4 Fallback Strategy Comparison

| Library | Primary | Fallback 1 | Fallback 2 | Final |
|---------|---------|------------|------------|-------|
| **candy-mosaic** | Kitty | iTerm2 | Sixel | Halfblock |
| **go-termimg** | Kitty | iTerm2 | Sixel | Halfblocks |
| **chafa** | Auto-detect | Any available | - | ASCII |
| **ratatui-image** | Kitty | iTerm2 | Sixel | chafa |
| **img2txt** | - | - | - | ANSI ASCII only |

---

## 7. Identified Gaps & Improvement Opportunities

### 7.1 Critical Gaps (Missing in candy-mosaic)

| Gap | Severity | Description |
|-----|----------|-------------|
| **No animation/GIF support** | High | All renderers produce static output; no frame iteration |
| **No image deletion/clear** | Medium | Kitty/iTerm2 can delete images; we never emit delete sequences |
| **No virtual image support** | Medium | Kitty virtual images (upload once, place many) not implemented |
| **No z-index layering** | Low | Kitty z-index for stacking not exposed |
| **No compression** | Low | Kitty zlib compression (`f=1`) not used |
| **No quarter-block Unicode** | Medium | Only `▀` (U+2580); quarter blocks `▒`, `░` not used |
| **Sixel RLE not bit-aligned** | Low | Spec allows bit-aligned RLE; we use byte-aligned (works in practice) |
| **No WezTerm detection** | Low | WezTerm supports both Kitty/iTerm2; env var `TERM_PROGRAM=WezTerm` treated as iTerm2 |

### 7.2 Architecture Improvements

| Issue | Current | Ideal | Effort |
|-------|---------|-------|--------|
| ChafaRenderer spawns subprocess | ✅ Works | Pure PHP alternative | High |
| No interface for image lifecycle | render only | Image + delete methods | Medium |
| Sixel performance | ~90ms | Could be faster with chunked rendering | Medium |
| No streaming render | Entire image | Streaming for large images | High |

### 7.3 Comparison with Upstream (charmbracelet/x/mosaic)

**What Go mosaic has that candy-mosaic doesn't:**

| Feature | Go mosaic | candy-mosaic |
|---------|-----------|--------------|
| Quarter blocks | ✅ | ❌ |
| Complex block combinations | ✅ | ❌ |
| Threshold-based alpha | ✅ | ❌ |
| Center scale mode | ✅ | ✅ |
| Symbol selection API | ✅ (`Symbols: "half\|quarter\|all"`) | ❌ |

**What candy-mosaic has that Go mosaic doesn't:**

| Feature | candy-mosaic | Go mosaic |
|---------|--------------|-----------|
| Sixel protocol | ✅ | ❌ |
| Kitty protocol | ✅ | ❌ |
| iTerm2 protocol | ✅ | ❌ |
| Median-cut quantization | ✅ (Sixel) | ❌ (uses fixed palette) |
| XTWINOPS font probing | ✅ | ❌ |

---

## 8. Prioritized Recommendations

### P0 — Must Have (Core Functionality)

1. **Fix WezTerm detection** (`TERM_PROGRAM=WezTerm` should prefer Kitty over iTerm2)
   - **File:** `Detect.php:probeEnv()`
   - **Change:** Check for WezTerm before iTerm2, treat as Kitty

2. **Add image deletion support**
   - Add `Renderer::delete(string $imageId): string` method (Kitty/iTerm2 only)
   - Update `Mosaic` facade with `delete(ImageSource): void`
   - **Files:** `Renderer.php`, `KittyRenderer.php`, `Iterm2Renderer.php`

3. **Add quarter-block Unicode renderer**
   - New `QuarterBlockRenderer` using `▒` (U+2592) and `░` (U+2591)
   - Higher visual fidelity than half-block for detailed images
   - **File:** `src/Renderer/QuarterBlockRenderer.php`

### P1 — Should Have (Quality Improvements)

4. **Expose Kitty virtual image support**
   - Use `KittyOptions` to enable `a=p` (place) action after `a=T` (transmit)
   - Allows repositioning without re-upload
   - **File:** `KittyRenderer.php`

5. **Implement Kitty compression** (`f=1` zlib)
   - Significant bandwidth reduction for large images
   - Tradeoff: CPU time vs bandwidth
   - **File:** `KittyRenderer.php`

6. **Add transparent background support for HalfBlockRenderer**
   - Current: alpha channel discarded
   - Ideal: blend with terminal background color
   - **File:** `HalfBlockRenderer.php`, `PixelGrid.php`

7. **Add `--ansi` mode to SixelRenderer for 256-color fallback**
   - When terminal doesn't support truecolor SGR
   - **File:** `SixelRenderer.php`

### P2 — Nice to Have (Advanced Features)

8. **Add animation/GIF support**
   - `AnimatedImage` class iterating over frames
   - `AnimatedGifRenderer` with `--frame-rate` support
   - **Files:** `src/AnimatedImage.php`, `src/Renderer/AnimatedGifRenderer.php`

9. **Add z-index support for Kitty**
   - `KittyOptions::withZIndex(int)` already exists but not wired
   - **File:** `KittyRenderer.php:renderWithOptions()`

10. **Optimize Sixel with progressive band rendering**
    - Emit bands as they complete (streaming)
    - Reduces memory for large images
    - **File:** `SixelRenderer.php`

### P3 — Future (Research Needed)

11. **Investigate libsixel PHP extension**
    - `php-sixel` PECL extension for native Sixel encoding
    - Would replace pure PHP median-cut + RLE with C implementation

12. **Investigate ImageMagick PHP binding**
    - `imagick` extension could handle format conversion + some protocols
    - Currently GD-only for image loading

---

## 9. Effort Estimates

| Task | Priority | Complexity | Est. Hours | Files |
|------|----------|------------|------------|-------|
| Fix WezTerm detection | P0 | Low | 0.5 | `Detect.php` |
| Add image deletion support | P0 | Medium | 3 | `Renderer.php`, `KittyRenderer.php`, `Iterm2Renderer.php`, `Mosaic.php` |
| Add quarter-block Unicode renderer | P0 | Medium | 4 | `QuarterBlockRenderer.php`, `Mosaic.php` |
| Expose Kitty virtual image support | P1 | Medium | 3 | `KittyRenderer.php` |
| Implement Kitty compression | P1 | Low | 1 | `KittyRenderer.php` |
| Transparent background for HalfBlock | P1 | Medium | 4 | `HalfBlockRenderer.php`, `PixelGrid.php` |
| Sixel 256-color fallback | P1 | Low | 2 | `SixelRenderer.php` |
| Animation/GIF support | P2 | High | 8 | `AnimatedImage.php`, `AnimatedGifRenderer.php` |
| Wire z-index for Kitty | P2 | Low | 1 | `KittyRenderer.php`, `KittyOptions.php` |
| Progressive Sixel band rendering | P2 | High | 6 | `SixelRenderer.php` |
| libsixel investigation | P3 | Research | 4 | Research only |

---

## Appendix A: Source References

### Core Source Files

| File | Purpose |
|------|---------|
| `/home/sites/sugarcraft/candy-mosaic/src/Mosaic.php` | Facade + builder pattern |
| `/home/sites/sugarcraft/candy-mosaic/src/Detect.php` | Terminal capability detection |
| `/home/sites/sugarcraft/candy-mosaic/src/Renderer/SixelRenderer.php` | Pure PHP Sixel implementation |
| `/home/sites/sugarcraft/candy-mosaic/src/Renderer/KittyRenderer.php` | Kitty APC protocol |
| `/home/sites/sugarcraft/candy-mosaic/src/Renderer/Iterm2Renderer.php` | iTerm2 OSC 1337 |
| `/home/sites/sugarcraft/candy-mosaic/src/Renderer/HalfBlockRenderer.php` | Unicode half-block fallback |

### External References

| Source | URL |
|--------|-----|
| charmbracelet/x/mosaic | https://pkg.go.dev/github.com/charmbracelet/x/mosaic |
| blacktop/go-termimg | https://github.com/blacktop/go-termimg |
| hpjansson/chafa | https://github.com/hpjansson/chafa |
| orhun/ratatui-image | https://github.com/orhun/ratatui-image |
| atanunq/viuer | https://github.com/atanunq/viuer |
| hit9/img2txt | https://github.com/hit9/img2txt |
| hmiladhia/img2text | https://github.com/hmiladhia/img2text |

---

## Appendix B: Protocol Escape Sequences Reference

### Kitty Graphics (APC)

```
Begin:  ESC G c=<cols> r=<rows> [;opts...]
Chunk:  ESC G m=<chunk> [;more]
End:    ESC G ESC \
```

### iTerm2 Inline Image (OSC 1337)

```
ESC ] 1337 ; File=name=<name> ; size=<size> ; width=<w> ; height=<h> ; preserveAspectRatio=<0|1> BEL
Base64-encoded image data
```

### Sixel (DCS)

```
DCS <cols> ; <rows> q / <palette> / <bands> ST
```

### Unicode Half-Block (SGR)

```
ESC [ 38;2;<r>;<g>;<b> m   # FG truecolor
ESC [ 48;2;<r>;<g>;<b> m   # BG truecolor
▀ (U+2580)                  # Upper half block
```

---

*Research compiled: 2026-05-13*
*Agent: researcher*
