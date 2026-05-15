# candy-flip Research: ASCII GIF Viewer Libraries

## Context

- **Library**: candy-flip (SugarCraft port of namzug16/gifterm)
- **Upstream**: https://github.com/namzug16/gifterm
- **Location**: `/home/sites/sugarcraft/candy-flip/src/`
- **Status**: 🟢 v1 ready (per MATCHUPS.md)
- **PHP version**: >=8.3, requires ext-gd
- **Stack**: Uses SugarCraft's Elm-architecture TUI runtime (candy-core)

---

## 1. Source Tree Analysis

### Current Architecture (candy-flip)

| File | Role |
|------|------|
| `Decoder.php` | Parses GIF bytes, extracts frames via hand-rolled GIF89a parser + GD |
| `Frame.php` | Immutable 2D grid of RGB triples in cell coordinates |
| `Renderer.php` | ANSI escape emitter: two presets (solid block / density ramp) |
| `Player.php` | SugarCraft Model — tick-driven frame advance, keyboard input handling |
| `TickMsg.php` | Per-frame animation tick message |
| `Lang.php` | i18n wrapper |

### Current Limitations

1. **Decoder uses temp files** — `renderSingleFrame()` writes full GIF to temp file for each frame, then GD reads it. This is slow for multi-frame GIFs.

2. **Naive downsampling** — Point-sampling at cell centers (`$sx = (int)(($cx + 0.5) * $w / $cellsW)`). No averaging or anti-aliasing.

3. **No dithering** — Renderer uses either solid blocks (24-bit color) or simple luminance-to-ASCII ramp. No error-diffusion or ordered dithering.

4. **GD-only frame extraction** — `imagecreatefromgif` only reads the first frame. The hand-rolled parser only finds `,` (image descriptor) byte offsets — it does NOT handle:
   - Graphic Control Extensions (disposal methods, transparency)
   - Local color tables
   - Frame timing/duration

5. **No color palette optimization** — No quantization or palette reduction.

6. **Fixed cell size** — Cannot adapt to terminal dimensions; default is 60×24.

---

## 2. Library Comparison

### 2.1 Frame Extraction Approaches

#### ext-gd (PHP) — Current candy-flip approach
- `imagecreatefromgif()` returns only first frame
- Requires workarounds: temp-file slicing or hand-rolled byte parsing
- Native PHP, no external dependencies beyond ext-gd

#### libvips (C) — Reference implementation
- **GIF loading**: `VipsImage.gifload()` with `[n=-1]` for all frames
- **Frame access**: Vertical strip model — `n-pages * page-height == height`
- **Per-frame delay**: `vips_image_get_array_int(image, "delay")` returns millisecond array
- **Performance**: 7x faster than ImageMagick for GIF thumbnailing (libvips 8.13 benchmarks)
- **PHP binding**: `php-vips扩展` (deprecated) or shell out to `vips` CLI
- **Key advantage**: Proper animation metadata handling without byte-level parsing

#### aa-lib (C) — Classic ASCII art library
- `aa_render()` converts image buffer to ASCII art
- `aa_renderpalette()` for palette mode
- Supports brightness, gamma, contrast, dithering parameters
- Requires font/terminal context initialization

#### CImg (C++) — Header-only image processing
- `CImg::load_gif_external()` uses ImageMagick/GraphicsMagick externally
- `CImgList` for animation frames
- Template-based, no external library dependency if using internal loaders

#### image crate (Rust) — Used by gifterm, viu, rascii
- `image::open()` with `gif` decoder
- `gif::Decoder` for frame-by-frame access
- Supports frame disposal, transparency, timing
- Async frame decoding for large files

### 2.2 Dithering Algorithms

#### Floyd-Steinberg (Error Diffusion)
```
 X 7/16
 3/16 5/16 1/16
```
- **Best overall quality** for ASCII art (per `makeworld-the-better-one/dither` Go library analysis)
- Serpentine (alternating scan direction) eliminates "worm" artifacts
- **Limitation**: Sequential — cannot be parallelized efficiently
- **Reference**: Floyd & Steinberg 1976

#### Ordered Dithering (Bayer Matrix)
- Pre-computed threshold map tiled across image
- **Fast** — no forward error array, no sequential dependency
- Parallelizable — each pixel independent
- **Good for animation**: patterns don't jitter
- Crosshatch artifacts visible at low color depth
- Bayer 4×4 and 8×8 most common

#### Jarvis-Judice-Ninke (Error Diffusion)
```
 X 7/48 5/48
 3/48 5/48 7/48 5/48 3/48
 1/48 3/48 5/48 3/48 1/48
```
- Smoother output than Floyd-Steinberg
- **Slower** — 3x more pixels affected, divisor 48 not power-of-2
- Good for static images

#### Atkinson Dithering
- Only propagates 75% of error (vs 100% in Floyd-Steinberg)
- Creates brighter output with more contrast in midtones
- Used in original Macintosh HyperScan

#### libvips/cgif (C) — Palette Optimization
- **libimagequant** for high-quality palette quantization
- **Quantizr** (BSD-2 fork) — faster than libimagequant with slightly better quality
- Per-frame palette optimization with caching
- Background thread for encoding to overlap with loading

### 2.3 Playback Control

#### kitty Graphics Protocol (gifterm Rust, viu, gifterm)
- Frames transmitted via `OC=0` (sixel) or `T=t` (temp file transfer)
- GPU-side animation management — process exits immediately
- **No terminal CPU cost after launch**
- Requires kitty-compatible terminal (WezTerm, Konsole partial)
- tmux blocks protocol by default (`allow-passthrough on`)

#### SugarCraft TUI (candy-flip PHP)
- `Cmd::tick($interval)` schedules frame advance
- All rendering in-process via ANSI escapes
- Process runs for duration of playback
- Full keyboard control (space=pause, arrows=step, d=preset toggle)

#### asciicast2gif/agg (Node.js/Rust)
- Renders asciicast recordings to GIF files
- Not real-time playback — file generation
- Uses gifsicle/giflossy for optimization

### 2.4 Color Handling

#### Truecolor (24-bit) — Most terminals
```php
// Foreground
"\033[38;2;R;G;Bm"
// Background (used by candy-flip)
"\033[48;2;R;G;Bm"
```
- Full 16.7M colors
- All modern terminals support (except some tmux configs)

#### ANSI 256-color palette
```php
"\033[38;5;N"  // N = 0-255
```
- 216 color cube + 24 grayscale
- Useful for legacy terminal support
- Color fidelity loss vs truecolor

#### ANSI 16-color
```php
"\033[3N"  // foreground
"\033[4N"  // background
```
- Limited to 8 foreground + 8 background
- Only as a fallback

#### Unicode Block Characters (candy-flip approach)
- `█` (U+2588) — full block, background color fill
- `▄` (U+2584) — lower half block (used by viu for half-block mode)
- `▀` (U+2580) — upper half block
- Better aspect ratio preservation than ASCII

---

## 3. Specific Improvements for candy-flip

### 3.1 High-Priority Improvements

#### P1: Replace Temp-File Frame Extraction with in-memory GD via StringIO
**Current**: `renderSingleFrame()` writes entire GIF to temp file, then GD reads it for each frame.
**Better**: Use `imagecreatefromstring()` with reconstructed single-frame GIF bytes.
**Effort**: Low (1-2 hours)
**Impact**: Significant speedup for multi-frame GIFs

#### P2: Proper Frame Timing from GIF Metadata
**Current**: All frames displayed at fixed interval (default 0.1s or 0.08s).
**Better**: Parse Graphic Control Extension for per-frame delay (in centiseconds).
**Effort**: Medium (3-4 hours)
**Impact**: Correct playback speed for animations with variable timing

#### P3: Area-Averaged Downsampling
**Current**: Point-sample at cell center.
**Better**: Average all source pixels in each cell region.
**Effort**: Low (2-3 hours)
**Impact**: Reduced aliasing, smoother visuals

### 3.2 Medium-Priority Improvements

#### P4: Error-Diffusion Dithering for Density Preset
**Current**: Simple luminance ramp with no dithering.
**Better**: Floyd-Steinberg serpentine dithering to 2-3 color ramp levels.
**Reference**: `makeworld-the-better-one/dither` Go library, `libcaca` img2txt
**Effort**: Medium (4-6 hours)
**Impact**: Much better grayscale gradient representation

#### P5: Local Color Table Support
**Current**: Only global color table handled.
**Better**: Parse Local Color Table in image descriptors when present.
**Effort**: Medium (4-5 hours)
**Impact**: Correct colors for GIFs using local palettes

#### P6: Transparency Handling
**Current**: Transparent pixels not explicitly handled.
**Better**: Parse transparency index from Graphic Control Extension, composite against previous frame per disposal method.
**Effort**: Medium-High (6-8 hours)
**Impact**: Correct rendering of transparent GIFs

#### P7: Adaptive Cell Size Based on Terminal Dimensions
**Current**: Fixed 60×24 cells.
**Better**: Query terminal size via `exec('tput cols lines')` and adjust.
**Effort**: Low (1-2 hours)
**Impact**: Better fit to user's terminal

### 3.3 Low-Priority / Future Work

#### P8: Floyd-Steinberg for Full-Color Mode
Implement serpentine error diffusion for truecolor-to-palette reduction when using a reduced palette.

#### P9: Ordered (Bayer) Dithering
- Faster than Floyd-Steinberg
- Better for animation (no temporal jitter)
- Could replace current density ramp entirely

#### P10: kitty/WezTerm Graphics Protocol Support
Add fallback output path using terminal graphics protocol for zero-CPU playback.
**Note**: This would make candy-flip a terminal-dependent feature rather than universal ANSI.

#### P11: GIF Optimization on Write
Add ability to save downsampled/dithered frames as new GIF.
Uses libvips or cgif for high-quality palette optimization.

#### P12: Frame Cache
Cache decoded/downsampled frames to disk (like gifterm's `~/.cache/gifterm/`).
SHA-256 key based on source file hash + cell dimensions.

---

## 4. Reference Implementations

### 4.1 Go Libraries

#### Zebbeni/ansipx (Go) — Most Comprehensive
- Frame extraction: `image` crate + custom GIF decoder
- Dithering: Floyd-Steinberg, Bayer, ClusteredDot with full options
- Color handling: Truecolor ANSI, 256-color fallback
- Sampling: NearestNeighbor, Bicubic, Bilinear, Lanczos2, Lanczos3, MitchellNetravali
- GIF frame rendering with proper timing
- **URL**: https://github.com/Zebbeni/ansipx
- **License**: MIT
- **Stars**: Active

#### makeworld-the-better-one/dither (Go)
- Dedicated dithering library
- Algorithms: Floyd-Steinberg, Jarvis-Judice-Ninke, Atkinson, Stucki, Burkes, Sierra, Bayer, ClusteredDot
- Serpentine scanning option
- Parallelized ordered dithering
- **URL**: https://github.com/makeworld-the-better-one/dither
- **License**: MIT

#### esimov/gifter (Go) — Old
- termbox-go based
- **Note**: Has flickering issues with non-transparent backgrounds
- Uses `-rb` flag to remove dominant background color
- **URL**: https://github.com/esimov/gifter

### 4.2 Rust Libraries

#### katharostech/cast2gif → asciinema/agg
- asciicast-to-GIF rendering
- Uses Kornel Lesiński's gifski for quality
- **URL**: https://github.com/asciinema/agg

#### nalediym/gifterm (Rust)
- kitty graphics protocol
- Frame caching to `~/.cache/gifterm/`
- WASM-compatible library
- **URL**: https://github.com/nalediym/gifterm

#### viu (Rust)
- Also supports kitty protocol as fallback
- Half-block Unicode mode
- **URL**: https://crates.io/crates/viu

#### rascii (Rust)
- ASCII + color modes
- Lower performance with colored GIFs
- **URL**: https://github.com/mightykho/rascii

### 4.3 C/C++ Libraries

#### libcaca — img2txt
- **Dithering algorithms**: none, ordered2, ordered4, ordered8, random, fstein (Floyd-Steinberg)
- **Output formats**: ansi, caca, utf8, html, html3, irc, bbfr, ps, svg, tga
- **Parameters**: brightness, contrast, gamma
- **URL**: https://github.com/cacalabs/libcaca

#### aa-lib
- **Dithering**: floyd_steinberg, error_distribution, random
- **Parameters**: brightness (0-255), contrast (0-255), gamma (0-1)
- **Rendering modes**: inverse, fast, palette
- **URL**: http://aa-project.sourceforge.net/aalib

#### CImg
- Header-only C++
- `load_gif_external()` via ImageMagick/GraphicsMagick
- `save_gif_external()` for animation export
- **URL**: https://cimg.eu/

### 4.4 PHP Libraries

#### Sybio/GifFrameExtractor
- Pure PHP animated GIF frame extractor
- Extracts frames + durations
- Does NOT require ext-gd
- Uses raw byte parsing (similar to candy-flip's approach but more complete)
- **URL**: https://github.com/Sybio/GifFrameExtractor
- **Note**: Could replace hand-rolled decoder with this library

---

## 5. Prioritized Recommendations

### Immediate (Next Sprint)

| # | Recommendation | Effort | Impact | Approach |
|---|---------------|--------|--------|----------|
| R1 | **Replace temp-file I/O with imagecreatefromstring()** | Low (1-2h) | High | Reconstruct single-frame GIF bytes in memory, pass to GD |
| R2 | **Add per-frame timing from Graphic Control Extension** | Medium (3-4h) | High | Parse GCE for delay-centiseconds, pass to Player |
| R3 | **Area-averaged downsampling** | Low (2-3h) | Medium | Sum RGB in cell region, divide by pixel count |

### Short-term (1-2 Sprints)

| # | Recommendation | Effort | Impact | Approach |
|---|---------------|--------|--------|----------|
| R4 | **Add Floyd-Steinberg serpentine dithering** | Medium (4-6h) | High | Implement or adapt from dither.go |
| R5 | **Local Color Table support** | Medium (4-5h) | Medium | Parse LCT from image descriptors, use per-frame |
| R6 | **Terminal-size adaptive cells** | Low (1-2h) | Medium | `tput cols lines` on init |

### Medium-term (Following Sprints)

| # | Recommendation | Effort | Impact | Approach |
|---|---------------||--------|--------|----------|
| R7 | **Transparency + disposal method handling** | Medium-High (6-8h) | High | Composite frames per disposal method |
| R8 | **Bayer ordered dithering for animation** | Medium (4-5h) | Medium | Pre-computed 8×8 matrix, parallelizable |
| R9 | **Frame caching to disk** | Medium (4-5h) | Medium | SHA-256(file+dimensions) → cache file |

### Long-term / exploratory

| # | Recommendation | Effort | Impact | Approach |
|---|---------------|--------|--------|----------|
| R10 | **kitty graphics protocol fallback** | High (8-10h) | Medium | Use sixel or temp-file transfer |
| R11 | **GIF write/export** | Medium (5-6h) | Low | Use libvips CLI or cgif via FFI |
| R12 | **Sybio/GifFrameExtractor integration** | Low (2-3h) | Medium | Replace hand-rolled decoder with tested library |

---

## 6. Effort Estimates Summary

| Phase | Total Effort | Deliverables |
|-------|-------------|--------------|
| Immediate | ~6-9h | Faster decoder, frame timing, better downsampling |
| Short-term | ~9-13h | Dithering, local palettes, adaptive sizing |
| Medium-term | ~14-18h | Transparency, ordered dither, frame cache |
| Long-term | ~13-16h | kitty protocol, GIF export, library swap |

**Grand Total**: ~42-56 hours

---

## 7. Technical Notes

### GIF89a Byte Stream Reference

```
GIF89a
  [Header: 6 bytes]
  [Logical Screen Descriptor: 7 bytes]
  [Global Color Table: 0-768 bytes]
  [Blocks...]
    [Graphic Control Extension: 8 bytes]
      - disposal method (bits 2-4)
      - user input flag (bit 1)
      - transparency flag (bit 0)
      - delay in centiseconds (2 bytes)
      - transparency index (1 byte)
    [Image Descriptor: 10 bytes]
      - left/top position
      - width/height
      - local color table flag (bit 7)
      - interlace flag (bit 6)
    [Local Color Table: 0-768 bytes]
    [Image Data: LZW compressed]
  [Trailer: 1 byte (0x3B)]
```

### Color Ramp Reference (Density Mode)

Current candy-flip uses:
```php
private const RAMP = ' .:-=+*#%@';
```

Reference ramp from aa-lib (14 chars):
```
 .',`:;Il!i><~+_-?][}{1)(|\/tfjrxnuvczXYUJCLQ0OZmwqpdbkhao*#MW&8%B@$
```

Reference img2txt (70 chars, full ASCII):
```
 .',^:;Il!i><~+_-?][}{1)(|\/tfjrxnuvczXYUJCLQ0OZmwqpdbkhao*#MW&8%B@$
```

### ANSI Escape Sequences

```php
// Truecolor foreground
"\033[38;2;R;G;Bm"

// Truecolor background
"\033[48;2;R;G;Bm"

// 256-color foreground
"\033[38;5;Nm"

// Reset
"\033[0m"

// Move cursor home (used by ansipx for animation)
"\033[H"

// Clear screen
"\033[2J"
```

---

## 8. Citations

- Floyd-Steinberg dithering: R.W. Floyd, L. Steinberg, "An adaptive algorithm for spatial grey scale," Proceedings of the Society of Information Display 17, 75-77 (1976)
- libvips GIF performance: https://github.com/libvips/libvips/issues/2531
- aa-lib documentation: http://aa-project.sourceforge.net/aalib
- libcaca img2txt: https://github.com/cacalabs/libcaca
- ansipx Go library: https://github.com/Zebbeni/ansipx
- dither Go library: https://github.com/makeworld-the-better-one/dither
- GifFrameExtractor PHP: https://github.com/Sybio/GifFrameExtractor
- kitty graphics protocol: https://sw.kovidgoyal.net/kitty/graphics-protocol/
