# SugarImage

Image-to-cell renderer for the terminal — PNG, JPEG, GIF (still and
animated), APNG, WebP and BMP decoded via ext-gd and rendered via the best
available protocol:

- **Sixel** — xterm, foot, mlterm, wezterm, contour
- **Kitty graphics protocol** — kitty, ghostty, wezterm
- **iTerm2 inline images** (OSC 1337) — iTerm2, wezterm, mintty
- **Half-block Unicode** (▀ + 24-bit fg/bg) — universal fallback
- **Quarter-block Unicode** (░▒▓█ 2×2) — higher fidelity than half-block
- **Symbols** (braille/sextant/octant/block glyph cells) — coverage-class rendering chosen by Hamming-distance candidate search (the chafa symbol engine). `SymbolMap::withSelectors()` applies chafa's `--symbols=` tag grammar over the narrow glyph corpus (ASCII/Latin/misc/legacy tables, the U+1FB80–U+1FB8B eighth-block tier, braille, sextants, octants) — **1,251 glyphs effective** under `all`: the corpus parses to 1,260 entries, but nine misc-narrow glyphs carry chafa's AMBIGUOUS tag (they have emoji variants) and are BAD-vetoed by every selector that does not name ambiguous/ugly/bad, exactly as the C build does (derivation: `Symbol/SymbolSetTables.php`). The default 676-glyph corpus is byte-frozen.

```sh
composer require sugarcraft/sugar-image
```

## Quickstart

```php
use SugarCraft\Image\Mosaic;
use SugarCraft\Image\ImageSource;

$mosaic = Mosaic::halfBlock();
$image  = ImageSource::fromFile('cat.png');
$ansi   = $mosaic->render($image, width: 40, height: 20);
echo $ansi;
```

## API

```php
use SugarCraft\Image\KittyOptions;
use SugarCraft\Image\Renderer\AsciiColorMode;
use SugarCraft\Image\Renderer\QuarterBlockRenderer;

// Probe terminal once, pick best protocol
$mosaic = Mosaic::probe();

// Force a specific backend
$mosaic = Mosaic::sixel();
$mosaic = Mosaic::kitty();
$mosaic = Mosaic::iterm2();
$mosaic = Mosaic::halfBlock();
$mosaic = Mosaic::quarterBlock();
$mosaic = Mosaic::ascii();                          // character ramp, monochrome
$mosaic = Mosaic::ascii(AsciiColorMode::Ansi256);   // ...or Ansi16 / TrueColor chars
$mosaic = Mosaic::symbols();                        // chafa symbol engine, default corpus
$mosaic = Mosaic::chafa('--colors=256');            // external chafa CLI

// Render — returns ANSI bytes
$ansi = $mosaic->render($image, width: 40, height: 20);

// Builder for fine-grained control; withResize() sets the default cell box
// render() uses when called without a width
$mosaic = Mosaic::builder()
    ->withRenderer(new QuarterBlockRenderer())
    ->withResize(width: 40, height: 20)
    ->build();
$ansi = $mosaic->render($image);          // 40×20 cells
$ansi = $mosaic->render($image, 60);      // explicit width wins; height from aspect

// Kitty: virtual-image placement — store once, place at many offsets.
// Step 1 — a STORING transmit: withUseVirtual(true) sends a=T,U=1 with the
// payload and closes with a control-only a=p frame, so the image is both
// loaded into terminal memory and drawn.
$renderer = (Mosaic::kitty())->renderer();
$opts = KittyOptions::transmit(1)->withUseVirtual(true);
$transmitted = $renderer->renderWithOptions($image, 40, null, $opts);
// Step 2 — place the stored image at a cell offset: place() emits a one-shot
// control-only a=p frame (same id) and never carries payload.
$opts = KittyOptions::place(1, x: 5, y: 10)->withZIndex(5);
$placed = $renderer->renderWithOptions($image, 40, null, $opts);

// Kitty: zlib compression (o=z) for large images
$opts = KittyOptions::transmit()->withCompression(1);
$compressed = $renderer->renderWithOptions($image, 40, null, $opts);
```

## Supported image formats

Codec matrix (sugar-image native paths only):

| Container | sugar-image native | Notes |
|---|---|---|
| PNG (still + APNG) | ✅ decode | APNG frames via `fromAnimatedFile()` |
| JPEG, static GIF | ✅ decode | GIF animates via `fromAnimatedFile()` |
| WebP — static (VP8/VP8L/VP8X) | ✅ decode via `fromString()` | ext-gd WebP build |
| WebP — animated (VP8X ANIM bit / ANMF) | ❌ refused by name | `UnsupportedFormatException` — needs chafa CLI or sugar-video's pure-PHP `AnimatedWebpDecoder` (no released ffmpeg decodes animated WebP frames — its demuxer skips ANMF); the refusal is structural (pure-PHP RIFF walk), so the silent first-frame degrade class is closed on every host |
| AVIF / HEIF / HEIC (`ftyp` brands) | ❌ refused by name | no PHP-side decoder; chafa CLI renders a still |
| JPEG XL (`FF 0A` codestream, `jxl ` brand) | ❌ refused by name | same |
| TIFF (`II*\0` / `MM\0*`) | ❌ refused as input | output-only: the GD-free iTerm2 payload encoder (`Codec\TiffUncompressed`) |

- PNG, JPEG, static GIF — via ext-gd (`imagecreatefrompng`,
  `imagecreatefromjpeg`, `imagecreatefromgif`)
- `ImageSource::fromString()` additionally accepts anything GD's
  `imagecreatefromstring()` decodes (WebP, BMP, …); `format` carries the real
  MIME (or `application/octet-stream`), and the Kitty/iTerm2 renderers
  re-encode any payload that is not PNG by signature.
- Palette PNGs are automatically converted to truecolor before
  processing.
- Animated GIF and APNG via `ImageSource::fromAnimatedFile()`.

## Limits

Decoding untrusted images is budgeted — the caps are live constants on
`ImageSource`, each pinned by tests:

| Budget | Value | What it stops |
|---|---|---|
| `ImageSource::MAX_BYTES` | 64 MiB | the file/payload read itself — oversized files are refused before GD ever sees them |
| `ImageSource::MAX_PIXELS` | 50,000,000 px | per-decode pixel budget (constructor and every factory take `maxPixels` to tune it) |
| `FLIP_MAX_TOTAL_CELLS` (private) | 400,000 cells | animated-GIF aggregate budget: frames × per-frame pixels — stops a bomb whose every single frame is individually in-budget |
| `MAX_RIFF_CHUNKS` (private) | 64 | the pure-PHP WebP RIFF walk bails out of a chunk flood instead of iterating forever |

## Remote images

Load images straight from a URL. `fromUrl()` is synchronous (PHP stream
wrappers — `http`/`https`/`file`/`data`, redirects followed); `fromUrlAsync()`
is non-blocking on the ReactPHP event loop and resolves with a decoded
`ImageSource`, ideal for fetching many posters concurrently without stalling
the render loop.

```php
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Mosaic;

// Synchronous (blocks) — handy for scripts/CLIs.
$image = ImageSource::fromUrl('https://example.com/poster.png', [
    'Authorization' => 'Bearer ' . $token,   // optional request headers
]);
echo Mosaic::halfBlock()->render($image, width: 24, height: 36);

// Asynchronous — resolves with an ImageSource on the loop.
ImageSource::fromUrlAsync('https://example.com/poster.png')
    ->then(fn (ImageSource $img) => Mosaic::probe()->render($img, 24, 36))
    ->then(fn (string $ansi) => print($ansi));
```

`fromUrlAsync()` needs the suggested `react/http` package
(`composer require react/http`); without it the returned promise rejects with
an install hint rather than fataling. The default Browser it builds carries the
same 30-second per-request timeout as the sync path; pass your own
pre-configured `React\Http\Browser` as the third argument to control the
connector and timeout yourself.

> **Security:** as with `fromFile()`, the source-trust decision is yours. By
> default both methods allow only `http`/`https`, re-validate the scheme on
> every redirect hop, and reject any host whose DNS records include a private,
> loopback, link-local or reserved address (including the `169.254.169.254`
> cloud-metadata IP) — a user-influenced URL cannot reach `file://` or an
> internal host unless you explicitly opt out (`allowedSchemes: null` to
> honour any scheme, `allowedHosts` to name a trusted internal host). The host
> check runs at guard time, not connect time: a zone answering publicly when
> checked and privately moments later (TTL-0 rebinding TOCTOU) is a disclosed
> residual — proxy the fetch if you need a connect-time guarantee. Header
> values containing CR/LF are rejected to prevent request splitting.

## Persistent render cache

Encoding a poster (fetch → GD-decode → scale → protocol-encode) is expensive.
`DiskCache` stores the finished ANSI/sixel/kitty bytes on disk keyed by the
poster's identity, so a redraw — even across process restarts — is an O(1) file
read. It pairs with the in-memory `AdaptiveImage` LRU (which avoids re-encoding
*within* a session).

```php
use SugarCraft\Image\DiskCache;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Mosaic;

$mosaic = Mosaic::probe();
$cache  = new DiskCache($_SERVER['HOME'] . '/.cache/posters', maxEntries: 512);

// Key includes the protocol — the same image at the same size encodes
// differently for sixel vs kitty vs half-block. The ASCII renderer's colour
// arms share one protocol name, so append modeDetail() to protocol() (this
// protocol/modeDetail split is how Mosaic keys its own posters internally)
// to keep them cache-distinct.
$key = DiskCache::key($url, width: 24, height: 36, protocol: $mosaic->protocol() . ($mosaic->modeDetail() ? ':' . $mosaic->modeDetail() : ''));

$ansi = $cache->getOrCompute($key, fn (): string =>
    $mosaic->render(ImageSource::fromUrl($url), 24, 36));
echo $ansi;
```

`get()`/`getOrCompute()` touch an entry on a hit, and `put()` evicts the
approximately least-recently-used entries once the directory exceeds
`maxEntries` (mtime is 1-second-resolution, so the cap is always honoured but
same-second writes order arbitrarily). Writes are atomic (temp file + rename)
and keys are hashed to derive the filename, so an arbitrary key can never escape
the cache directory.

## KittyOptions — virtual-image placement and compression

The Kitty renderer supports two advanced options via `KittyOptions`:

- **Virtual-image placement**: a plain transmit draws the image with `a=T`
  (the default) and is *not* kept around for referencing. Transmit combined
  with `withUseVirtual(true)` **stores** it: the transmit frame carries
  `a=T,U=1` with the payload, and the renderer closes the chunk sequence
  with a control-only `a=p` frame that places it. Afterwards,
  `KittyOptions::place($imageId, x:, y:)` emits a one-shot control-only
  `a=p` frame at a cell offset — never the payload again — so one
  transmission can be placed at many locations, reducing bandwidth.
  Under/over text follows `withPlacementU()`: `1` renders the image under
  text (the default), `0` over it.

- **Zlib compression** (`o=z`): Pass `withCompression(1)` to compress
  the PNG payload with zlib before base64-encoding; the transmit is
  emitted as `f=100,o=z` (compression rides the spec's `o` key, `f`
  stays the data format). Useful for large images on slow links; adds
  modest CPU overhead.

```php
use SugarCraft\Image\KittyOptions;

// Storing transmit: a=T,U=1 payload frames + a trailing control-only a=p
$opts = KittyOptions::transmit(imageId: 1)->withUseVirtual(true);
$stored = $renderer->renderWithOptions($image, 40, null, $opts);

// Place the stored image at another offset — control-only a=p, no payload
$opts = KittyOptions::place(imageId: 1, x: 5, y: 10)
    ->withZIndex(5);           // z-index for stacking order
$placed = $renderer->renderWithOptions($image, 40, null, $opts);

// Zlib-compressed transmit
$opts = KittyOptions::transmit()->withCompression(1);
$compressed = $renderer->renderWithOptions($image, 40, null, $opts);
```

## Protocol detection

Every renderer implements `Renderer::delete(string $imageId): string`
which emits the protocol-specific sequence to remove a previously
rendered image. The `$imageId` is the numeric image identifier passed
during rendering (Kitty) or a placeholder for interface compatibility
(iTerm2 Pop ignores it).

| Renderer | Sequence | Notes |
|---------|----------|-------|
| Kitty | APC `a=d` | Deletes specific image by id |
| iTerm2 | OSC 1337 Pop | Removes topmost image from stack; `$imageId` ignored |
| Sixel | _(none)_ | DECSIXEL has no delete command; returns `''` |
| HalfBlock | _(none)_ | Plain text SGR; no stored image identity; returns `''` |
| QuarterBlock | _(none)_ | Plain text SGR; no stored image identity; returns `''` |
| Chafa | _(none)_ | External command; no persistent image identity; returns `''` |
| Ascii | _(none)_ | Plain text SGR; character ramp; returns `''` |
| SymbolMap | _(none)_ | Plain text SGR; glyph-coverage engine; returns `''` |

## Inside tmux

tmux panes **swallow** DCS, APC and OSC strings unless the window's
`allow-passthrough` option is `on` (or `all`), so Sixel, Kitty (APC) and
iTerm2 (OSC 1337) output would otherwise vanish inside tmux — the decorated
renderer's bytes never reach the outer terminal and the user sees a blank
line. `TmuxPassthrough` automates chafa's workaround as a pane-scoped
transaction: `ensure()` reads the current value (`show-options -pqv`), flips
`set-option -p allow-passthrough on` only when needed, and `retire()`
restores the prior value — or unsets with `-u` exactly when it was unset
before. A process-lifetime restore is registered on first change, and the
whole thing is **fail-soft**: outside tmux, without the binary, or against
an unparseable answer it degrades to the manual-configuration silence,
never a throw. Embedders who own their tmux configuration opt out with
`Detect::setAutoPassthrough(false)`. GNU screen has its own envelope
instead: the `Passthrough/` decorators (`TmuxPassthroughDecorator`,
`ScreenPacketizer` / `ScreenPassthrough`).

## Animation

Drive a sequence of frames through `AnimationDriver` — a `Model`-implementing class that uses `Cmd::tick()` for per-frame timing and `Renderer::delete()` / `Renderer::renderFrame()` for clean per-frame redraws on Kitty/iTerm2 terminals.

```php
use SugarCraft\Image\Animation;
use SugarCraft\Image\AnimationDriver;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Mosaic;

$frames = [
    ImageSource::fromFile('frame1.png'),
    ImageSource::fromFile('frame2.png'),
    ImageSource::fromFile('frame3.png'),
];

$animation = Animation::fixed($frames, delayMs: 100);
$driver    = new AnimationDriver(
    animation:  $animation,
    renderer:   (Mosaic::kitty())->renderer(),
    cellWidth:  40,
    cellHeight: 20,
    imageId:    1,
);

// Use in a Program:
// new Program($driver, ...);
echo $driver->view(); // renders first frame immediately
```

`Animation` is an immutable value object (`list<ImageSource>` + `list<int> $delaysMs`). Use `Animation::fixed($frames, $delayMs)` for uniform delays, or `new Animation($frames, $delaysMs)` for per-frame control. `withFrame($index, $frame, $delayMs)` returns a new instance with one replaced frame.

`AnimationDriver` composes `Animation` + current frame index + paused flag. Call `withIndex()` / `withPaused()` / `withImageId()` for fluent state changes. On Kitty-capable terminals, `KittyRenderer::renderFrame($image, $width, $height, $imageId)` renders a single frame with a stable id that `delete($imageId)` can later target.

## Text-tier renderers

Three backends need no graphics protocol at all — they paint the image as
text, so they work everywhere:

- **Half-block** (`▀`, 24-bit fg/bg over two pixel rows per cell) and
  **quarter-block** (`░▒▓█`, 2×2 sub-pixel canvas per cell) shade pixels
  directly.
- **ASCII ramp** — one luminance sample per cell mapped onto a character
  ramp: `Mosaic::ascii()` emits monochrome characters;
  `Mosaic::ascii(AsciiColorMode::Ansi16 | Ansi256 | TrueColor)` carries the
  pixel colours on the characters themselves.
- **Symbol map** — chafa's coverage-class engine: each cell's bit coverage
  picks the closest Unicode braille/sextant/octant/block glyph by
  Hamming-distance candidate search over a symbol corpus.
  `Mosaic::symbols(canvas:, workFactor:, symbols:)` picks the colour
  canvas (`CanvasMode`), the candidate-budget work factor, and a chafa
  `--symbols=` selector expression over the wider corpus (1,251 effective
  glyphs under `all`; default corpus byte-frozen at 676). The double-width
  wide-glyph engine plus the opt-in kana tier ride
  `SymbolMap::withSelectors('wide')` and the renderer's lookback pass.

`Mosaic::auto()` seats the symbol engine on the fallback ladder below the
chafa CLI rung and above half-block; the ASCII ramp is explicit-selection
only. Every text-tier renderer also honours `withCanvasMode()` and
`withOptimization()` like the protocol backends.

## ImageLayer — image boxes in a text frame

`ImageLayer` is the turnkey way to put real images inside a text UI. Instead of
emitting pixel bytes into the frame (which would wreck the layout), you reserve a
cell-sized **marker block** where the image belongs and hand the collected bytes
to the `View`. The runtime paints each marker it finds.

```php
use SugarCraft\Core\View;
use SugarCraft\Image\ImageLayer;

$layer = new ImageLayer();

// Reserve a 20x10 box and stash the rendered bytes.
$cell = $layer->place($ansiBytes, 20, 10);

// ...compose $cell into the frame like any other text...
return new View($frame, images: $layer->placements());
```

Identical bytes register once — dedup is by content hash (`xxh3`), so the same
image shown in several places shares one id and paints at every marker. The id
space is the Private-Use-Area window (`ImageOverlay::MAX_IMAGES`, 6398); once it
is exhausted, further placements get a blank block of the requested size rather
than a wrong image.

### Getting the assigned id: `placeTracked()`

Some widgets need the overlay id as well as the marker — for example a poster
card that takes `withImage($bytes, $id)`, or code that wants to surgically clear
just the cells one image occupied. `placeTracked()` returns both as a
`PlacedImage`:

```php
$placed = $layer->placeTracked($ansiBytes, 20, 10);

if ($placed->imageId !== null) {
    $card = $card->withImage($ansiBytes, $placed->imageId);
}

$frame = $placed->marker; // same string place() would have returned
```

`place()` keeps its plain `string` return and is simply a delegate to
`placeTracked()`, so frame-composition callers are unaffected.

Two things about `PlacedImage::$imageId` are worth knowing:

- It is `null` **exactly when** the id space is exhausted, and then `$marker` is
  a blank block. `null` never means "unknown" — it means "this will not paint".
- It identifies the **content**, not the call. Because dedup is by byte hash, the
  same bytes always get the same id regardless of size, order, or repetition. If
  you previously keyed ids by something call-shaped (a URL, a requested cell
  size, a list index), that mapping does not survive.

Do **not** try to recover the id from `array_key_last($layer->placements())`. A
dedup hit re-assigns an existing key without moving it, so that reports the
highest id rather than the one just placed; and the exhaustion path writes no
placement at all, so it reports a stale unrelated id.

## Architecture

```
SugarCraft\Image
├── Animation              # Immutable frame sequence value object
├── AnimationDriver        # Model; drives Animation onto a Renderer via tick()
├── FrameTickMsg           # Internal Msg for frame-advance ticks
├── ImageLayer             # Per-frame image registry: marker blocks + placements
├── ImageSource            # Image bytes + metadata + decode budgets (Limits)
├── KittyOptions           # Kitty protocol options (transmit / place / compress)
├── Lang                   # i18n facade
├── Mosaic                 # Facade: probe / auto / builder / render
├── MosaicBuilder          # Fluent Mosaic assembly
├── Detect / TermDb / TermInfo  # Terminal capability detection + quirks tables
├── DiskCache              # On-disk render cache (key includes protocol + mode detail)
├── AdaptiveImage          # In-memory LRU poster cache (within a session)
├── AsyncRenderer / PacedAsyncRenderer / SyncAsyncRenderer  # Async render paths
├── PixelGrid              # 2-D cell grid (foreground, background, alpha, char)
├── PlacedImage            # placeTracked() result: marker block + assigned id
├── Renderer
│   ├── AsciiRenderer      # Character ramp (Mono/Ansi16/Ansi256/TrueColor)
│   ├── ChafaRenderer      # External command renderer
│   ├── HalfBlockRenderer  # Unicode ▀ with 24-bit fg/bg
│   ├── Iterm2Renderer     # iTerm2 OSC 1337
│   ├── KittyRenderer      # Kitty APC graphics (chunked PNG, virtual placement)
│   ├── QuarterBlockRenderer # Unicode ░▒▓█ 2×2 sub-pixel
│   ├── SixelRenderer      # DEC sixel with median-cut quantizer
│   └── SymbolMapRenderer  # chafa coverage-class symbol engine
├── Symbol                 # Glyph corpora: tables, tags, kana + wide defs
├── Codec                  # GD-free payload codecs (TIFF encoder, raw RGBA)
├── Dither                 # Shared dithering arithmetic (Floyd-Steinberg, ordered…)
├── Levels                 # Luminance stretching (Rec.709 weights)
├── Render                 # Band splitting
├── Passthrough            # tmux/screen envelope decorators
└── Support                # OutputOptimizer, GdAvailability, PushToPull
```
