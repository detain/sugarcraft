# Plan: image-to-cell renderer lib (`x/mosaic` → `candy-mosaic`)

## Goal

New foundation lib that renders raster images (PNG/JPEG/static GIF)
to terminal cells, picking the best available protocol:

- **Sixel** (xterm, foot, mlterm, wezterm, contour)
- **Kitty graphics protocol** (kitty, ghostty, wezterm)
- **iTerm2 inline images** (OSC 1337) (iTerm2, wezterm, mintty)
- **Half-block fallback** (Unicode `▀` + 24-bit fg/bg) — anywhere

## Scope

**In**

- Static image decode via ext-gd
- Four renderer backends with clean interface
- Capability detection from env vars + DA1 query
- Public facade returning ANSI bytes
- Programmatic resize / aspect-ratio handling

**Out**

- Animated GIFs (stays in candy-flip; candy-flip will depend on candy-mosaic for single-frame rendering)
- SVG rasterization (caller does it externally — librsvg / inkscape — and feeds PNG bytes)
- Pre-rendered terminal art (use `cat`)
- Browser-style image effects (filters, blur) — out of scope; user can pre-process

## Design influences

Three upstream image-to-terminal libraries inform this design:

| Source | Pull what | Notes |
|---|---|---|
| [`charmbracelet/x/mosaic`](https://github.com/charmbracelet/x/tree/main/mosaic) | architecture baseline (Charmbracelet-canonical) | the repo this plan ports from |
| [`blacktop/go-termimg`](https://github.com/blacktop/go-termimg) (MIT, 56★) | dithering modes (FS + Stucki), font-size CSI probe, tmux passthrough, scaling modes (fit/fill/stretch/none), Kitty z-index + virtual images | folded into v1.5 PRs (PR7-PR11 below) |
| [`ratatui/ratatui-image`](https://github.com/ratatui/ratatui-image) (MIT, 324★) | **Picker pattern** (probe once, mint renderers from a single state object), two-tier API (PrecomputedImage vs AdaptiveImage), async resize | Picker concept folded into v1 API (below); two-tier + async are v1.5 (PR12-PR13) |

`x/mosaic` is canonical; the others are best-of-class executions of the
same problem. We adopt the Picker shape from ratatui-image because it
gives a single state object to query for font-size + protocol + cell
dimensions, and the dithering/tmux/scaling work from go-termimg
because it's already debugged.

## Naming + placement

- Composer pkg: `sugarcraft/candy-mosaic`
- Subdir: `candy-mosaic/`
- Namespace: `SugarCraft\Mosaic`
- Prefix: **Candy-** (foundation/system; per `PROJECT_NAMES.md`)

## Layout

```
candy-mosaic/
  composer.json
  phpunit.xml
  README.md
  CALIBER_LEARNINGS.md
  src/
    Mosaic.php                       # facade
    ImageSource.php                  # readonly: bytes, format, width, height, mime
    PixelGrid.php                    # internal: GD resource → 2D color array
    Capability.php                   # readonly: { sixel, kitty, iterm2, halfblock }
    Detect.php                       # capability detection
    Renderer/
      Renderer.php                   # interface
      SixelRenderer.php
      KittyRenderer.php
      Iterm2Renderer.php
      HalfBlockRenderer.php
    Lang.php                         # i18n facade
  examples/
    inline-image.php                 # show ./logo.png at 40x20
    capability-probe.php             # print Detect output
    forced-halfblock.php             # demo fallback rendering
  lang/
    en.php
  tests/
    DetectTest.php
    SixelRendererTest.php
    KittyRendererTest.php
    Iterm2RendererTest.php
    HalfBlockRendererTest.php
    PixelGridTest.php
    fixtures/
      4x2.png
      checkerboard.png
      expected_sixel.txt
      expected_kitty.txt
      expected_iterm2.txt
      expected_halfblock.txt
```

## composer.json

- Deps: `sugarcraft/candy-core: @dev` (for `Util/Ansi`), `sugarcraft/candy-sprinkles: @dev` (for color types), `ext-gd`, `ext-mbstring`
- Path-repos: full transitive closure — copy from `sugar-charts/composer.json`

## Public API

`Mosaic` is the **Picker** — borrowed name from ratatui-image. Probe
the terminal once at startup (env vars + optional CSI queries), cache
protocol + font size on the instance, then mint renders from it.

```php
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Mosaic\ImageSource;

# Probe once — caches detected protocol + font-size + cell dimensions
$mosaic = Mosaic::probe();

# Inspect what was detected (useful for "supported features" UIs)
$mosaic->protocol();           # 'kitty' | 'sixel' | 'iterm2' | 'halfblock'
$mosaic->fontSize();           # ['cellWidth' => 8, 'cellHeight' => 16] in pixels (best-effort)
$mosaic->capability();         # full Capability snapshot

# Render
$image = ImageSource::fromFile('cat.png');             # or fromString($bytes)
$ansi = $mosaic->render($image, width: 40, height: 20); # returns ANSI bytes
echo $ansi;
```

Forcing a specific backend (skips probing):

```php
$mosaic = Mosaic::halfBlock();        # or ::sixel(), ::kitty(), ::iterm2()
$ansi = $mosaic->render($image, width: 40);  # height auto from aspect
```

Builder for fine-grained control:

```php
$mosaic = Mosaic::builder()
    ->withRenderer(new HalfBlockRenderer())
    ->withResize(width: 40, height: 20)
    ->withAspectMode(AspectMode::FIT)
    ->build();
```

Two-tier rendering (v1.5):

```php
# Stateless precomputed — encode once, render the same bytes many times.
# Cheap to write to the terminal each frame; the encoded ANSI never
# changes after construction.
$precomputed = $mosaic->precompute($image, width: 40, height: 20);
echo $precomputed->bytes();              # ready-to-write ANSI

# Adaptive — re-encodes when the requested size changes. Use inside a
# render loop where the image must adapt to layout.
$adaptive = $mosaic->adaptive($image);
echo $adaptive->render(width: 40, height: 20);   # cached
echo $adaptive->render(width: 60, height: 30);   # re-encoded
```

## Capability detection (`Detect.php`)

Precedence order:

1. **Kitty** — `KITTY_WINDOW_ID` env set, **or** `TERM_PROGRAM` ∈ {`ghostty`, `WezTerm`}, **or** `$TERM` matches `xterm-kitty`
2. **iTerm2** — `TERM_PROGRAM` ∈ {`iTerm.app`, `WezTerm`, `mintty`}, **or** `LC_TERMINAL` == `iTerm2`
3. **Sixel** — DA1 query (`\x1b[c`) → response contains `;4;` (sixel capability bit). Fallback: `$TERM` matches `mlterm|foot|xterm-256color` and `XTERM_VERSION` set.
4. **HalfBlock** — always available

DA1 probing requires writing the query and reading the reply with a
short timeout. If the program is non-interactive (no TTY on stdout), skip
the probe and trust env vars only.

Cache the result per process — `Detect::cached()`.

## Renderer interface

```php
interface Renderer
{
    public function render(ImageSource $image, int $width, ?int $height = null): string;
    public function name(): string;          # 'sixel' | 'kitty' | 'iterm2' | 'halfblock'
    public function supportsAlpha(): bool;
}
```

## Algorithm notes per backend

### HalfBlockRenderer

- Resize image to `$width × ($height * 2)`
- Pair `(top, bottom)` pixels per cell
- Per cell: emit `Ansi::sgr([Fg::rgb($topR,$topG,$topB), Bg::rgb($botR,$botG,$botB)]) . "▀" . Ansi::sgrReset()`
- Lines separated by `\r\n`
- Already implemented in candy-flip's `HalfBlockEncoder`; **action: extract verbatim into candy-mosaic**, then have candy-flip depend on it

### SixelRenderer

- Convert truecolor → indexed (max 256 entries) via median-cut quantizer
- Emit DCS header: `Ansi::sixelDcsHeader()`
- Emit color palette: one `Ansi::sixelColorIntroducer($i, $r, $g, $b)` per palette entry
- For each 6-pixel-tall band:
  - For each color in the palette used in this band: emit color select then sixel-encoded pixels
- Emit terminator: `Ansi::sixelTerminator()`
- Quantizer: simple median-cut (~150 lines). Avoid floyd-steinberg dither in v1.

### KittyRenderer

- Encode image as base64 PNG (no need to decode-then-reencode if input is PNG; pass through)
- Chunked APC: 4096-byte chunks per spec, `m=1` on all but last
- `Ansi::kittyGraphicsBegin(['a' => 'T', 'f' => 100, 'q' => 2, ...])` — `a=T` means transmit-and-display
- `Ansi::kittyGraphicsChunk($chunk, $more)` per chunk
- `Ansi::kittyGraphicsEnd()` — final chunk with `m=0`
- For sized output, set `c` (cell columns) and `r` (cell rows) instead of pixel dims

### Iterm2Renderer

- Single OSC 1337: `Ansi::iterm2InlineImage($base64, ['width' => 40, 'preserveAspectRatio' => 1])`
- That's it; no chunking, no palette

## Implementation slices

### PR1 — scaffold + ImageSource + half-block (~1 day)

- composer.json, phpunit.xml, README skeleton
- `ImageSource::fromFile`, `fromString`, `fromGd($resource)`
- `PixelGrid` decoder
- `HalfBlockRenderer` extracted from candy-flip
- `Mosaic::halfBlock()` facade
- Tests: 4×2 PNG fixture → expected ANSI bytes
- candy-flip composer.json: add `sugarcraft/candy-mosaic: @dev` dep
- candy-flip Renderer.php: replace local half-block code with `(new HalfBlockRenderer())->render(...)`

### PR2 — Detect.php + Iterm2Renderer (~half day)

- `Detect::probe()` env-var-based detection only (no DA1 yet)
- `Iterm2Renderer` (small — single OSC 1337 emission)
- `Mosaic::auto()` and `Mosaic::iterm2()`
- Tests: env-var matrix, base64 round-trip

### PR3 — KittyRenderer (~1 day)

- Chunked APC encoder
- Tests: assemble + parse round-trip via `Util\Ansi` helpers
- Add `Ansi::kittyGraphicsBegin/Chunk/End` (item A2 from x-ansi plan)

### PR4 — SixelRenderer (~2 days)

- ... DONE (PR #274)
  - Median-cut quantizer (corrects bucket leak: buckets grew 2× per iteration instead of 1×)
  - Sixel band encoder (DECGCI palette decl, DECGCR per-band select, RLE sixel data)
  - 16 tests: fixture smoke, dimension validation, palette count, multi-band, printable sixel bytes
  - `Ansi::sixelDcsHeader`, `sixelColorIntroducer`, `sixelColorSelect`, `sixelPixelData`, `sixelTerminator`
  - Wired into `Mosaic::bestBackend()` — Kitty > iTerm2 > Sixel > HalfBlock

### PR5 — DA1 probe + capability fallback (~half day)

- `Detect` writes `\x1b[c` to stdout (only if interactive), reads reply with 100ms timeout
- Append sixel detection (`;4;`) and kitty detection (`?62;` doesn't help for kitty — skip; rely on env)
- Tests: stub IO with canned reply bytes

### PR6 — examples + .vhs tape + matrix entry (~half day)

- `examples/inline-image.php`
- `.vhs/inline-image.tape`
- `.github/workflows/{ci,vhs}.yml` matrix entries
- `MATCHUPS.md`, `PROJECT_NAMES.md`, `CONVERSION.md`, root README, `docs/index.html`, icon

---

## v1.5 enhancements (post-v1, absorbed from go-termimg + ratatui-image)

These ship as a follow-up wave once v1 is stable. Each is independent
of the others; pick whichever the first user complaint demands.

### PR7 — font-size CSI probe (~half day)  *[from go-termimg]*

- Add `Capability::fontSize()` filled by probing:
  - **XTWINOPS 14**: `\x1b[14t` → `\x1b[4;<height>;<width>t` (window pixel size)
  - **XTWINOPS 16**: `\x1b[16t` → `\x1b[6;<cellHeight>;<cellWidth>t` (cell pixel size)
  - **XTWINOPS 18**: `\x1b[18t` → `\x1b[8;<rows>;<cols>t` (terminal cell count)
- Derive cell pixel size = window pixel size / cell count when 16t isn't supported
- Fall back to a reasonable default (`8×16`) when probing fails or stdin isn't a TTY
- Plumb font-size through to KittyRenderer + SixelRenderer for accurate cell-to-pixel mapping
- Tests: stub stdin replies with canned bytes; assert detected font size
- **Effort**: half day
- **Status**: DONE (PR [#277](https://github.com/detain/sugarcraft/pull/277))

### PR8 — Sixel dithering (~1 day)  *[from go-termimg]*

- Add `Dither` enum: `None | FloydSteinberg | Stucki | Atkinson`
- Implement error-diffusion dithering in `SixelRenderer` quantization step
- New API: `Mosaic::sixel()->withDither(Dither::FloydSteinberg)->render(...)`
- Default: `FloydSteinberg` (best quality/speed trade-off; matches what xterm itself does for sixel)
- Tests: snapshot test with deliberately-banded gradient — assert dither pattern emerges
- **Effort**: 1 day (the actual error-diffusion math is ~30 lines per algorithm; spread cost is fixture creation)
- **Status**: IN_PROGRESS (PR [#278](https://github.com/detain/sugarcraft/pull/278))

### PR9 — tmux passthrough wrapper (~half day)  *[from go-termimg]*

- Detect tmux: `getenv('TMUX')` non-empty
- When detected, wrap any DCS/APC/OSC sequence in tmux's passthrough envelope:
  - `\x1bPtmux;` + (escape inner `\x1b` as `\x1b\x1b`) + `\x1b\\`
- Apply uniformly to Sixel, Kitty, iTerm2 outputs
- New `TmuxPassthroughDecorator` wraps any `Renderer`
- `Mosaic::probe()` checks `TMUX` and applies the decorator automatically
- Caveat: tmux must be configured with `set -g allow-passthrough on` (default off in tmux 3.3+). Document.
- Tests: enable env var, render same image with/without tmux wrap, assert byte difference matches the envelope grammar
- **Effort**: half day
- **Status**: DONE (PR [#279](https://github.com/detain/sugarcraft/pull/279))

### PR10 — scaling modes (~half day)  *[from go-termimg]*

- Add `Scale` enum: `Fit | Fill | Stretch | None | Crop`
- Behavior:
  - `Fit` (default): preserve aspect ratio, image fits within bounds (letterbox)
  - `Fill`: preserve aspect ratio, image fills bounds (overflow cropped)
  - `Stretch`: ignore aspect ratio, exact dimensions
  - `None`: no resize; clip if larger than bounds
  - `Crop`: explicit center-crop to bounds with original pixel density
- Wire through `render($image, width, height, scale: Scale::Fit)` and `Mosaic::builder()->withScale(...)`
- Tests: 4×2 fixture rendered at 8×8 in each mode; assert pixel grid
- **Effort**: half day
- **Status**: DONE (PR [#280](https://github.com/detain/sugarcraft/pull/280))

### PR11 — Kitty z-index + virtual images (~1 day)  *[from go-termimg, marked experimental upstream]*

- Add `KittyOptions` value object: `imageId`, `zIndex`, `placement`, `virtual`
- New `KittyRenderer::renderWithOptions($image, $width, $height, KittyOptions $opts)`
- Z-index lets multiple images stack with controlled overlap
- Virtual images: image data sent once, then re-displayed via `a=p` (place) at different positions without re-transmitting
- Useful for: TUI dashboards with sticky logos / status images
- Document as **experimental** since upstream upstreams it as experimental
- Tests: assert APC sequences include the right `a=`, `i=`, `z=`, `q=` keys
- **Effort**: 1 day

### PR12 — two-tier rendering API (~half day)  *[from ratatui-image]*

- New `PrecomputedImage` value object: holds encoded ANSI bytes; constructed by `Mosaic::precompute($image, $width, $height)`
- New `AdaptiveImage`: holds reference to source image + memoized encodings keyed by `[width, height, scale]`
- `AdaptiveImage::render($w, $h)` returns cached bytes if size matches, otherwise re-encodes
- Cap memoization cache at N entries (LRU), default 4 — typical use case is one or two sizes alternating
- Tests: render same size twice, assert encoder called once; resize, assert re-encoded
- **Effort**: half day

### PR13 — async resize hook (~1 day)  *[from ratatui-image's ThreadProtocol]*

- `AdaptiveImage::renderAsync($w, $h): PromiseInterface` — returns ReactPHP promise resolved with bytes
- Internal worker:
  - **Default backend**: `ReactPHP\ChildProcess` — spawn a worker that does GD decode + resize + encode, write result back via stdout
  - **Alternate backend**: synchronous (drop-in for environments without ReactPHP) — runs in current process
  - **Future backend**: pcntl_fork pool (faster startup than child_process, but POSIX-only)
- API surface:
  ```php
  $img = $mosaic->adaptive($image)->withAsync(true);
  $img->renderAsync(40, 20)->then(fn($bytes) => $renderer->draw($bytes));
  ```
- Required for any TUI that wants to handle large images without blocking the main loop
- Tests: assert promise resolves with same bytes as sync `render()`; assert non-blocking via timing assertion
- **Effort**: 1 day

## Test strategy

- Pixel-perfect snapshot tests using fixed PNG fixtures (commit them; they're tiny)
- Cross-reference upstream `charmbracelet/x/mosaic/testdata/` if their fixtures are MIT-licensed (they are)
- DA1 probe tested against canned input bytes; never hits a real TTY
- HalfBlock test verifies exact-byte parity with candy-flip's current renderer (regression guard during the extraction)

## Caveats

1. **ext-gd colorspace** — GD reads PNG as truecolor by default; palette PNGs need `imagepalettetotruecolor()` first. Handle in `PixelGrid::fromGd`.
2. **GD on alpine / minimal containers** — bundled by default in php:8.x docker images but not always; CI image must `apt install php8-gd` (already does for candy-flip).
3. **Sixel quantization** — median-cut is fast but lossy; we accept ≤256 colors per image. Document the limit.
4. **Kitty chunk size** — protocol says max 4096 bytes per chunk. Easy to overshoot if base64 padding pushes us; round down to 4092 to stay safe.
5. **Terminal-side scaling** — Kitty + iTerm2 protocols let the terminal scale the image. Sixel + half-block require us to resize via GD first. Keep API uniform: both code paths take `(width, height)` in cells.
6. **Aspect ratio** — terminal cells are roughly 1:2 (wide:tall). Half-block doubles vertical resolution → near-square pixels. Sixel/Kitty operate in pixels; we set cell dims and trust the terminal. Document that half-block looks "tall" if you don't compensate.
7. **Animated GIFs are out of scope here** — but candy-flip's animation loop will call `(new HalfBlockRenderer())->render($frame, ...)` per frame. No API change.

## Effort

### v1 — Charmbracelet `x/mosaic` parity

| Slice | Effort |
|---|---|
| PR1 scaffold + half-block extract | 1 day |
| PR2 Detect + iTerm2 | half day |
| PR3 Kitty | 1 day |
| PR4 Sixel | 2 days |
| PR5 DA1 probe | half day |
| PR6 examples + matrix | half day |
| **v1 total** | **~5 days** |

### v1.5 — go-termimg + ratatui-image absorbed features

| Slice | Effort | Source |
|---|---|---|
| PR7 font-size CSI probe | half day | go-termimg |
| PR8 Sixel dithering (FS/Stucki/Atkinson) | 1 day | go-termimg |
| PR9 tmux passthrough | half day | go-termimg |
| PR10 scaling modes | half day | go-termimg |
| PR11 Kitty z-index + virtual | 1 day | go-termimg |
| PR12 two-tier API (Precomputed + Adaptive) | half day | ratatui-image |
| PR13 async resize via ReactPHP | 1 day | ratatui-image |
| **v1.5 total** | **~5 days** | |

**Combined v1 + v1.5: ~10 days** for full parity with the most polished
upstream image-to-terminal libraries.

## Dependencies

- [x-ansi](./x-ansi.md) items A1, A2, A3 — land within their PRs here
- candy-flip refactor — happens in PR1 alongside the extraction
- Optional: candy-shine could later use `Mosaic::halfBlock()` for inline image links in markdown

## Tracking

- `MATCHUPS.md` — new row: `[charmbracelet/x/mosaic] | candy-mosaic | candy-mosaic/ | sugarcraft/candy-mosaic | SugarCraft\Mosaic | 🟡 (until v1) | Image-to-cell renderer`
- `PROJECT_NAMES.md` — naming entry for CandyMosaic (Candy- foundation)
- `CONVERSION.md` — phase row
- `UPSTREAM_OPPORTUNITIES.md` — flip `x/mosaic` to 🟡 on PR1, 🟢 on PR6
- `docs/index.html` — homepage tile
- `media/candy-mosaic.png` — 256² icon
- `candy-flip/CALIBER_LEARNINGS.md` — note the half-block renderer was extracted to candy-mosaic
