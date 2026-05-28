# SugarCraft CandyFlip — Innovation & Comparison Report

## Metadata

- **Package:** `sugarcraft/candy-flip`
- **Subdir:** `candy-flip/`
- **Namespace:** `SugarCraft\Flip`
- **Status:** 🟢 v1 ready
- **Upstream:** [namzug16/gifterm](https://github.com/namzug16/gifterm) (Go, 6 stars, 2024-06-15)
- **Active Fork:** [nalediym/gifterm](https://github.com/nalediym/gifterm) (Rust, 2026) — kitty-GPU architecture
- **Sister Ports:** `candy-mosaic` (image protocol rendering — Sixel/Kitty/iTerm2/Unicode halfblocks)
- **Summary:** ASCII GIF viewer — decodes `.gif` via `ext-gd`, downsamples to cell grid, renders as ANSI-coloured Unicode block-glyphs or luminance-ramp ASCII.

---

## Source Tree

```
candy-flip/
├── bin/candy-flip                   # CLI entry point
├── examples/play.php                # Demo player (synthesizes test GIF)
├── src/
│   ├── Decoder.php                  # Hand-rolled GIF89a parser + GD frame extract
│   ├── Frame.php                    # Pure value: 2D RGB grid + delay/disposal/transparent
│   ├── Downsampler.php              # NEAREST or AREA_AVERAGE pixel→cell
│   ├── Renderer.php                 # ANSI emitter (solid blocks / luminance ramp)
│   ├── Player.php                   # TEA model: init/update/view + tick-driven playback
│   ├── TickMsg.php                  # Frame-advance Msg
│   ├── Lang.php                     # i18n facade (extends SugarCraft\Core\I18n\Lang)
│   ├── Cache/FrameCache.php          # WeakMap<Frame,string> memoization cache
│   └── Dither/FloydSteinberg.php   # Error-diffusion dithering against fixed palette
├── lang/                            # 16 locales (en, fr, de, es, pt, pt-br, zh-cn, …)
├── tests/
│   ├── DecoderTest.php              # File validation, 1x1 decode, dimension checks
│   ├── DecoderLocalColorTest.php    # Per-frame LCT parsing and usage
│   ├── DecoderTransparencyTest.php # GCE transparent flag + disposal method
│   ├── PerFrameTimingTest.php       # Multi-frame GCE delay carry-forward + defaults
│   ├── FrameTest.php                # Dimension accessors, readonly cells
│   ├── DownsamplerTest.php         # NEAREST vs AREA_AVERAGE quality diff
│   ├── FloydSteinbergTest.php      # Immutability, palette adherence, transparency
│   ├── AdaptiveSizeTest.php        # Row/col clamping, backward static render
│   ├── FrameCacheTest.php           # WeakMap identity, separate instances
│   └── PlayerTest.php              # Tick/step/pause/quit/preset-toggle
├── composer.json                     # ext-gd, sugarcraft/{core,pty,sprinkles}
└── CALIBER_LEARNINGS.md             # 7 GIF-specific patterns documented
```

---

## GIF Decoding Pipeline

### `Decoder::decode(string $path, int $cellsW, int $cellsH): list<Frame>`

The decoder does **zero** temp-file I/O. Every frame is decoded in-memory.

#### Step 1 — Header parse (`parseHeader()`)

GIF89a/GIF87a magic checked at bytes 0–5. The Logical Screen Descriptor (bytes 6–12) yields:
- `width`, `height` — original pixel dimensions
- `hasGct` — global color table presence (bit 7 of packed byte)
- `gctSizeExp` — color count exponent (2^(exp+1) entries)
- `gctBytes` — `gctEntryCount * 3`

#### Step 2 — Frame stream walk

Byte-by-byte walk from offset `13 + gctBytes`:

| Byte | Block Type | Action |
|------|-----------|--------|
| `0x21` | Extension | Check label at `+1`. If `0xF9` (GCE): read disposal (bits 2–4), transparent flag (bit 0), transparent index (byte +6), delay LE16 (bytes +4,+5). Carry forward last-seen values. Skip fixed 8 bytes. Otherwise: walk sub-blocks until `0x00` terminator. |
| `0x2C` | Image Descriptor | Record offset + last-seen GCE values (delay, disposal, transparent). Parse LCT flag (bit 7) and size from packed byte at `+9`. Walk LZW sub-blocks to skip image data. |
| `0x3B` | Trailer | Break. |

**Frame cap:** `array_slice($frameInfos, 0, 256)` prevents OOM on pathological files.

#### Step 3 — Per-frame reassembly (`renderSingleFrame()`)

For each frame, a minimal single-frame GIF payload is assembled in memory and passed to `imagecreatefromstring()`:

```
[original header 13 bytes]
[global color table — if present]
[GCE block — with correct per-frame delay/disposal/transparent]
[local color table — if present (after Image Descriptor header)]
[Image Descriptor 10 bytes]
[LZW image data sub-blocks]
[GIF trailer 0x3B]
```

The GCE delay is reconstructed as LE16 into bytes 4–5 of the GCE block. This ensures `imagecreatefromstring()` produces a GdImage with the correct frame timing metadata intact for the sampling pass.

#### Step 4 — Area-average downsampling (`sample()`)

For each target cell `(cx, cy)` computing source pixel range `[x0..x1] × [y0..y1]`:

```php
// For each source pixel in the cell:
if ($transparent && $idx === $transparentColor) continue; // skip transparent
$sumR += ($rgb >> 16) & 0xff;
$sumG += ($rgb >>  8) & 0xff;
$sumB +=  $rgb        & 0xff;
$count++;

// Cell result: [round($sumR/$count), round($sumG/$count), round($sumB/$count)]
// or null if every pixel was transparent.
```

The `Decoder::findImageDataEnd()` method walks LZW sub-blocks to find where the LZW data terminates — it looks for `subLen === 0 || subLen >= 0x80` (a GIF control byte mid-stream signals end of image data sub-block chain).

---

## Downsampling Algorithm

### `Downsampler::downsample(\GdImage $img, int $cellsW, int $cellsH, string $mode): array`

Two modes:

**`NEAREST`** — sample the center pixel of each cell. Fast, low quality. Used for quick previews:
```php
$sx = (int)(($cx + 0.5) * $w / $cellsW);
$sy = (int)(($cy + 0.5) * $h / $cellsH);
$rgb = imagecolorat($img, min($w-1, $sx), min($h-1, $sy));
```

**`AREA_AVERAGE`** — sum all source pixels in the cell region, divide by count. Produces smooth gradients without aliasing. Used by default in `Decoder::sample()`.

The standalone `Downsampler` class also skips the GIF reassembly step — it takes a raw `\GdImage` directly and is used by the `FloydSteinberg` ditherer to downsample before error diffusion.

---

## Rendering Modes

### `Renderer::renderFrame(Frame $f, string $preset): string`

#### `PRESET_SOLID` — Full-cell Unicode blocks with 24-bit background

```
CSI 48;2;R;G;Bm <space>   # e.g. \033[48;2;255;0;0m for red cell
```

Each cell emits one space character painted with the cell's RGB via `Ansi::CSI . "48;2;%d;%d;%dm"`. The cell background is the full color; the foreground (space character) is invisible. All cells on a line share one ANSI sequence.

#### `PRESET_DENSITY` — Luminance-mapped ASCII ramp

```
CSI 38;2;R;G;Bm <glyph>
```

Luminance: `0.299r + 0.587g + 0.114b` (ITU-R BT.601 coefficients). Ramp index:
```php
$idx = (int)round($lum / 255 * (strlen(" .:-=+*#%@") - 1)); // 0–9
$glyph = " .:-=+*#%@"[$idx]; // @ for white, space for black
```

Both modes terminate each line with `Ansi::reset()` (`\033[0m`). Transparent cells emit `Ansi::sgr(49) . ' '` (reset to default background).

#### Adaptive sizing

`Renderer::withAdaptiveSize()` calls `SizeIoctl::query(STDOUT)` to get terminal rows/cols and clamps output so the grid never overflows. `Renderer::withConstraints(int $rows, int $cols)` accepts explicit limits for testing.

---

## Animation Playback

### `Player implements Model`

State: `frames: list<Frame>`, `index: int`, `paused: bool`, `interval: float`, `preset: string`.

**`init()`** — if not paused and frames exist, schedules `Cmd::tick($interval, TickMsg)` to advance once per interval (default 100ms).

**`update(Msg)`** — handles:
- `KeyMsg(Space)` — toggle pause; when unpausing, reschedules tick
- `KeyMsg(←/→)` — `step(-1)` / `step(+1)` with modulo wrap
- `KeyMsg(Char 'd')` — toggle `PRESET_SOLID` ↔ `PRESET_DENSITY`
- `KeyMsg(Escape|Char 'q'|Ctrl-C)` — `Cmd::quit()`
- `TickMsg` (while not paused) — `step(+1)`, reschedule tick

**`view(): string`** — renders current frame + status line:
```
frame 3/45  ·  solid  ·  playing   space pause   ←/→ step   d preset   q quit
```

---

## Floyd-Steinberg Dithering

### `FloydSteinberg::dither(\GdImage $src, array $palette): \GdImage`

Error diffusion matrix:
```
       *  7/16
  3/16  5/16  1/16
```

Implements as three error buffers (per-channel float arrays `$errR`, `$errG`, `$errB`) — **source image is never mutated**. For each pixel:
1. Accumulate error from buffer: `newC = oldC + round(errBuffer[y][x+1])`
2. Find nearest palette color by Euclidean distance `(dr²+dg²+db²)`
3. Quantization error diffuses into buffer positions `(y, x+1)`, `(y+1, x-1)`, `(y+1, x)`, `(y+1, x+1)` at weights 7/16, 3/16, 5/16, 1/16

Transparent pixels (`imagecolortransparent()` index) are preserved as fully transparent in the output via `imagesavealpha($dst, true)`.

**Note:** The ditherer is not yet wired into the playback pipeline — it is a standalone utility for palette-reduced output.

---

## Frame Cache

### `FrameCache` — `WeakMap<object, string>`

Keyed by `Frame` object identity. Entries are GC'd automatically when the `Frame` is garbage-collected — critical for long-running players where hundreds of frame objects could accumulate.

API: `get(Frame): ?string`, `set(Frame, string): void`, `has(Frame): bool`, `delete(Frame): void`, `clear(): void`.

Used to skip re-rendering identical frames on looped playback.

---

## Upstream Analysis

### namzug16/gifterm (Go, primary upstream)

**URL:** https://github.com/namzug16/gifterm  
**Stars:** 6 | **Language:** Go (98.2%) | **Created:** 2024-06-15 | **Last push:** 2025-07-04

The upstream is a minimal Go CLI tool (~6 stars, single-maintainer, now inactive). It reads a GIF using the standard `image` package, downscales via `resize.Resample()`, then maps each pixel to a character from a configurable density ramp (`cd` flag, default `".,:-=i|%O#@$X"`). It writes ANSI 24-bit foreground color per character (`\033[38;2;R;G;Bm`). Flags: `-fps` (default 12), `-cd` (character density string), `-randomBlank`, `-ofg` (foreground-only, no background).

**Key differences from candy-flip:**

| Aspect | namzug16/gifterm | candy-flip |
|--------|------------------|------------|
| GIF decoding | `image.Decode()` (full GIF, all frames at once) | Hand-rolled GIF89a parser; frames decoded independently via `imagecreatefromstring()` |
| Downsampling | Go `resize.Resample()` Lanczos/bilinear | Area-average or nearest-neighbor in PHP |
| Transparency | Not handled | GCE transparent-index awareness; null cells for transparent pixels |
| Disposal methods | Not handled | DISPOSAL_NONE/RESTORE/PREVIOUS tracked per frame |
| Multi-frame timing | Global FPS flag | Per-frame GCE delay (centiseconds), carried forward on zero-delay frames |
| Luminance ramp | Configurable string (default 10 chars) | Fixed 10-char ramp ` .:-=+*#%@` |
| Solid-block mode | Not available | Yes: full-cell `█` with 24-bit background |
| Player interactivity | None (fire-and-forget) | Pause/step/preset-toggle/quit |
| TUI framework | None | SugarCraft TEA model + `Cmd::tick()` |
| Frame cache | None | `WeakMap<Frame, string>` memoization |

### nalediym/gifterm (Rust, 2026 — active fork)

**URL:** https://github.com/nalediym/gifterm  
**Stars:** ~25 | **Language:** Rust | **Created:** 2025 | **License:** MIT

A complete reimagining by a different author. Instead of text rendering it uses the **kitty graphics protocol** to transmit frames to the terminal GPU directly. Uses `image` crate for decoding, Lanczos3 for scaling, SHA-256 cache of decoded frames to `~/.cache/gifterm/`. Requires a Kitty-compatible terminal (kitty, WezTerm, Konsole). Animation persists in terminal after CLI exits.

**Architecture comparison:**

| Aspect | nalediym/gifterm | candy-flip |
|--------|-----------------|------------|
| Rendering target | Kitty GPU (temporary file transfer `t=t`) | ANSI text (any terminal) |
| Frame transmission | Binary graphics protocol data | Text escape sequences |
| Scaling | Lanczos3 (high quality) | Area average (smooth, but lower quality than Lanczos3) |
| Caching | SHA-256 cache on disk | WeakMap in-memory |
| Platform | Kitty/WezTerm/Konsole only | Universal |
| Animation management | Terminal-managed (loop, persist post-exit) | PHP-driven tick loop |

---

## Competitive Landscape

### blacktop/go-termimg (Go, ~57 stars)

**Primary mapping:** `candy-mosaic` (image protocol rendering)

Renders via 4 protocols: Kitty, Sixel, iTerm2, Unicode halfblocks. Uses `charmbracelet/x/cellbuf` and `x/mosaic`. Animation partially implemented. LRU resize cache, parallel Base64 encoding, tmux passthrough. Falls back to Unicode halfblocks when protocols unavailable.

**Contrast with candy-flip:** go-termimg uses binary protocols for pixel-perfect output; candy-flip uses pure text (ANSI blocks). They are complementary: candy-flip for universal fallback/text-only environments, candy-mosaic for full-featured protocol rendering.

### Zebbeni/ansipx (Go, ~active 2026)

**Primary mapping:** None (not yet in MATCHUPS)

Renders images as ANSI art via Go library. Features: `CharacterMode` (Ascii/Unicode/Custom), multiple Unicode block variants (half/quarter/shade), 6 sampling functions (nearest/bicubic/bilinear/Lanczos2/Lanczos3/Mitchell), 3 dithering modes (matrix/Bayer/clustered dot), 14 diffusion matrices (Floyd-Steinberg/Atkinson/Burkes/Stucki/etc.). Supports animated GIFs with per-frame delay. Uses `go-colorful` for color space math and `nfnt/resize` for resampling.

**Innovation:** Far more sampling and dithering options than candy-flip. Could inform `Downsampler` improvements (add Lanczos3, Mitchell-Netravali).

### Gaurav-Gosain/jif (Go, v0.1.0, 2026)

**Primary mapping:** None (not yet in MATCHUPS)

Bubble Tea TUI GIF viewer. Half-block rendering for 2x vertical resolution, Lanczos3 scaling, proper disposal method handling (none/background/previous), HTTP remote URL support, automatic terminal resize handling.

**Innovation:** Bubble Tea integration, remote URL fetching, disposal method handling (which candy-flip tracks but doesn't fully apply in the player).

### hzeller/timg (C++, ~2566 stars)

**Primary mapping:** None

Comprehensive terminal image viewer. Supports Sixel, Kitty, iTerm2, quarter-blocks. Can play animated GIFs, scroll static images, play videos. Has a grid mode, centering, checkerboard alpha pattern, thumbnail mode, multiple scale/filter options.

**Innovation:** Video support, multiple output modes, thumbnail grid. Serves as the quality benchmark for terminal image rendering.

### moshen/gotermimg (Go, older)

Display images in 256-color terminals. Uses its own GIF decoder. 256-color palette quantization, UTF-8 mode for Unicode blocks. Supports looping, scaling, piped input.

**Relevance to candy-flip:** The earliest Go implementation of terminal GIF playback — predates Bubble Tea era libraries.

### qeesung/asciiplayer (Go, 2018)

ASCII video player. Three display modes: encode (save to file), play (stdout), server (HTTP stream). Supports PNG/JPEG/GIF/MP4/AVI. Uses `image2ascii` library internally.

---

## Innovation Gaps & Opportunities

### 1. Sampling Quality
candy-flip uses area-average (smooth but blurs edges). Upsream **Zebbeni/ansipx** demonstrates that Lanczos3 / Mitchell-Netravali give sharper results. Adding a `LanczosResampler` using `imagecopyresampled()` with a precomputed lanczos kernel would improve quality.

**Implementation hint:** GD's `imagecopyresampled()` uses bilinear interpolation. A true Lanczos3 requires 2-pass resampling or the `imagick` extension. The `Downsampler` class could gain a `LANCZOS3` constant that uses `imagecopyresampled()` with careful dimension calculations as an approximation.

### 2. Full Disposal Method Pipeline
`Frame::$disposal` is tracked and parsed but the `Player::view()` does not apply disposal between frames. Implementing DISPOSAL_RESTORE (clear to background) and DISPOSAL_PREVIOUS (restore previous content) requires maintaining a composite canvas across frames — the next frame's transparent cells would sample from this canvas rather than the raw frame.

### 3. Floyd-Steinberg Integration
`FloydSteinberg::dither()` exists but is not wired into the `Decoder` pipeline. Adding a `Decoder::decodeWithDither()` that applies FS dithering against a configurable palette before downsampling would enable palette-reduced output modes.

### 4. Half-Block Rendering
Like **jif** (Go) and **ansipx** (Go), candy-flip could support Unicode half-block rendering (`▀` upper + `▄` lower) to double effective vertical resolution with 24-bit color on each half.

**Implementation:** In `Renderer::cell()`, add `PRESET_HALFBLOCK` that emits two stacked cells (upper-half pixel in foreground, lower-half pixel in background) using `\033[38;2;R;G;Bm▀` then `\033[48;2;R;G;Bm▄`.

### 5. Sixel / iTerm2 Protocol Support
The **nalediym/gifterm** (Rust) and **hzeller/timg** (C++) and **blacktop/go-termimg** (Go) approaches transmit image data via terminal graphics protocols rather than text. While `candy-mosaic` handles this at the rendering layer, an extension to `candy-flip` that produces Sixel output for `ext-gd`-compatible frames would dramatically improve quality on supporting terminals (xterm, foot, mlterm).

### 6. WebP / APNG Input
**nalediym/gifterm** roadmap includes APNG and WebP. `candy-flip` is GIF-only. Adding APNG support via `imagecreatefromwebp()` (PHP 8.3+) or `imagecreatefrompng()` would broaden input support.

### 7. Per-Frame Adaptive Quality
For complex animated GIFs, the frame decode cost is non-trivial. A `FrameCache` at the `Decoder` level — not just at the `Renderer` level — would enable instant re-decoding of previously-seen frames (common in looping GIFs).

---

## Strengths

1. **Zero temp-file I/O** — all GIF parsing and frame extraction happens in memory via hand-rolled GIF89a parser + `imagecreatefromstring()`. This is architecturally clean and fast.

2. **Per-frame GCE timing** — unlike the upstream Go tool which uses a global FPS flag, candy-flip reads the actual GIF89a Graphic Control Extension delay for each frame and carries it forward when the GIF spec mandates it.

3. **Transparent-pixel awareness** — the area-average downsampling correctly skips transparent pixels when computing cell averages, so semi-transparent regions don't pull the average toward transparent.

4. **Immutable + fluent patterns** — `Frame` is a readonly value object, `Player` follows the TEA model contract, `Renderer` is instantiated with constraints and renders against value frames. Pure PHP architecture throughout.

5. **WeakMap frame cache** — automatic GC of stale frame renders prevents memory growth in long-running players.

6. **Adaptive TTY sizing** — `Renderer::withAdaptiveSize()` queries `SizeIoctl` (no shell-out to `tput`) to clamp output to the current viewport.

7. **Comprehensive test suite** — 10 test files covering: multi-frame timing, local color tables, transparency, disposal methods, dithering immutability, adaptive sizing, frame cache identity semantics.

---

## Weaknesses

1. **Area-average blurs edges** — no Lanczos3 / Mitchell-Netravali option for sharper downsampling (contrast with ansipx, jif, nalediym/gifterm which all support higher-quality filters).

2. **Disposal methods not applied** — `Frame::$disposal` is stored but the player renders each frame in isolation without compositing against a canvas. Complex GIFs with disposal will not animate correctly.

3. **No dither integration** — `FloydSteinberg` exists but is not called from any pipeline. Grayscale/limited-palette output is unimplemented.

4. **No half-block mode** — `PRESET_SOLID` uses full-cell `█` (1x vertical resolution). Half-blocks (`▀`/`▄`) would double vertical resolution as in jif/ansipx.

5. **GIF87a static images** — if a static GIF has no GCE block (no animation timing), the decoder falls back to delay=10 (100ms) but returns only one frame. This is correct behavior but could be configurable.

6. **No Sixel/iTerm2/Kitty protocol output** — on terminals that support these, candy-flip produces text where binary protocols would look dramatically better. `candy-mosaic` handles protocol rendering but not at the `candy-flip` integration level.

7. **Single-threaded decoding** — frames are decoded sequentially. For a 200-frame GIF this is negligible, but larger files would benefit from parallel decoding via ReactPHP `Async` queue.

---

## SugarCraft Mapping

### Primary: `candy-flip` → `namzug16/gifterm` (primary upstream)

The port faithfully extends the upstream concept with PHP idioms:
- GIF89a byte-stream parsing replaces Go's `image.Decode()` — in-memory zero-temp-file
- Area-average downsampling replaces Go's `resize.Resample()` — equivalent quality
- ANSI text rendering replaces Go's text output — same fundamental approach
- Player built on SugarCraft TEA model adds interactivity the upstream lacks

### Extension: `candy-flip` → `nalediym/gifterm` (active fork)

The Rust fork's kitty-GPU approach is architecturally different but the output use case (animated GIF in terminal) is the same. If SugarCraft were to implement protocol-aware rendering, the `Player` state machine and `TickMsg` scheduling would be the natural integration point for `candy-mosaic`.

### Sister mapping: `candy-flip` ↔ `candy-mosaic`

`candy-mosaic` provides image rendering via terminal graphics protocols (Sixel/Kitty/iTerm2/Unicode halfblocks). `candy-flip` provides text-based fallback (ANSI blocks/luminance ramp). They are complementary rendering strategies for the same input types. `FrameCache` from `candy-flip` could cache protocol-rendered frames in `candy-mosaic` to skip re-encoding identical frames.

---

## Conclusion

CandyFlip is a well-engineered 🟢 v1 port of `namzug16/gifterm` that improves on its upstream in several dimensions: proper per-frame GCE timing, transparent-pixel awareness, interactive TEA-model player, adaptive TTY sizing, and comprehensive test coverage. Its weaknesses are in downsampling quality (no Lanczos3) and disposal method handling (parsed but not applied in playback) — both tractable extensions.

The competitive landscape reveals that the text-based ANSI approach occupies a specific niche: universal terminal compatibility at the cost of visual fidelity. The cutting edge of terminal GIF playback (`nalediym/gifterm`, `hzeller/timg`, `blacktop/go-termimg`) uses binary graphics protocols (Kitty/Sixel/iTerm2) for near-lossless rendering. CandyFlip's contribution is making ASCII GIF playback accessible and dependency-free on any PHP 8.3+ system with ext-gd.
