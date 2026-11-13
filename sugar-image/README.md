# SugarImage

Image-to-cell renderer for the terminal — PNG, JPEG, GIF (still and
animated), APNG, WebP and BMP decoded via ext-gd plus pure-PHP
animated-container decoders and rendered via the best
available protocol:

- **Sixel** — xterm built with sixel support (detection gates the xterm row on <code>XTERM_VERSION</code>), foot, mlterm, contour, rio
- **Kitty graphics protocol** — kitty, ghostty, wezterm
- **iTerm2 inline images** (OSC 1337) — iTerm2, mintty, rio (rio and mintty claim sixel too; detection precedence seats them on iTerm2)
- **Half-block Unicode** (▀ + 24-bit fg/bg) — text fallback where cell
  geometry is honored (the `auto()` floor is the symbol tier)
- **Quarter-block Unicode** (░▒▓█ 2×2) — higher fidelity than half-block
- **Symbols** (braille/sextant/octant/block glyph cells) — each cell takes the Unicode glyph that looks closest to the image, the lib's floor tier for sighted readers on any Unicode-capable terminal within its font's glyph coverage; the corpus arithmetic lives in the Text-tier renderers section below.

```sh
composer require sugarcraft/sugar-image
```

Sibling `sugarcraft/*` requirements track `dev-master`, so the plain
command above only resolves when dev stability is allowed: pin the
package with `composer require sugarcraft/sugar-image:@dev`, or set
`minimum-stability: "dev"` with `prefer-stable: true` in your
consuming manifest.

## Requirements

PHP `^8.3`, `ext-gd`, `ext-mbstring` and `react/promise` — all four are
`require`d in `composer.json`, not advisory. GD is the decode path for every
pure-PHP tier; without it each entry point answers with a catalogued refusal
routed through `Lang::t` (the shipped `lang/en.php` catalog is English-only
today) rather than an undefined-function error, and
`src/Support/GdAvailability.php` owns that probe.
The chafa CLI is never required: the chafa tier is opt-in, selected only by
`Mosaic::chafa()` / `fromModeString('chafa')`, and it is the only place the lib
runs an external program to produce image output.

Why a library rather than the `chafa` viewer: every tier here is a byte string
your program owns. Graphic tiers emit the terminal's own escape sequences and
text tiers emit glyphs, so the same render works over ssh, inside tmux (with
`allow-passthrough`) and down a CI pipe — and it can be placed by id, animated,
cached on disk and layered into a text frame instead of printed and forgotten.

## Quickstart

```php
use SugarCraft\Image\Mosaic;
use SugarCraft\Image\ImageSource;

$mosaic = Mosaic::halfBlock();
$image  = ImageSource::fromFile('examples/fixture-gradient.png');
$ansi   = $mosaic->render($image, width: 40, height: 20);
echo $ansi;
```

## Examples

- `examples/inline-image.php <path> [width]` prints one image on the terminal's
  best tier and exits 0/1/2/3 — the shortest read of the detection path.
- `examples/fixture-gradient.png` is a committed image, so both the quickstart
  above and that script run against the checkout without hunting for a file.

## API

```php
use SugarCraft\Image\Dither;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\KittyOptions;
use SugarCraft\Image\Mosaic;
use SugarCraft\Image\Renderer\AsciiColorMode;
use SugarCraft\Image\Renderer\QuarterBlockRenderer;
use SugarCraft\Image\Renderer\SixelRenderer;

$image  = ImageSource::fromFile('examples/fixture-gradient.png');

// Probe terminal once, pick best protocol
$mosaic = Mosaic::probe();

// Force a specific backend
$mosaic = Mosaic::sixel();                                        // sixel, Floyd-Steinberg dither
$mosaic = Mosaic::sixel(Dither::Ordered, intensity: 0.5);         // opt-in dither + 0.0-1.0 bias
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
// At renderer level the contract is stricter: width is a mandatory argument,
// and renderWithOptions() additionally takes the nullable height positionally
// (pass null); the null-width fallback to the builder box is Mosaic-only.

// Sixel palette budget: maxColors (1–256) is a SixelRenderer constructor
// knob, so it rides the builder, not Mosaic::sixel().
$mosaic = Mosaic::builder()
    ->withRenderer(new SixelRenderer(maxColors: 64))
    ->build();

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
| PNG (still + APNG) | ✅ decode | APNG frames via `fromAnimatedFile()` → an `Animation` |
| JPEG, static GIF | ✅ decode | GIF animates via `fromAnimatedFile()` → an `Animation` |
| WebP — static (VP8/VP8L/VP8X) | ✅ decode via `fromString()` | ext-gd WebP build |
| BMP | ✅ decode via `fromString()` | ext-gd decode; `fromFile()` refuses it — its declared-MIME allowlist is png/jpeg/gif only |
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
- Animated GIF and APNG via `ImageSource::fromAnimatedFile()`, which returns an
  `Animation` (frames + per-frame delays) — the input to `AnimationDriver`.

## Limits

Decoding untrusted images is budgeted — the caps are constants on
`ImageSource`:

| Budget | Value | What it stops |
|---|---|---|
| `ImageSource::MAX_BYTES` | 64 MiB | the file, animated-file, URL and `fromString()` reads — oversized payloads are refused before GD ever sees them (the in-memory route measures its buffer up front, so every byte-intake path shares one ceiling; the format-signature and pixel budgets below still guard what fits) |
| `ImageSource::MAX_PIXELS` | 50,000,000 px | per-decode pixel budget — `fromFile()`, `fromAnimatedFile()` and `fromString()` check it against declared dimensions, every renderer re-checks it on the decoded frame, and `withMaxPixels()` tunes it per source |
| `FLIP_FRAME_CAP` (private) | 256 frames | animated-GIF frame list: walks beyond the cap are sliced away rather than decoded (a silent truncation, not a refusal) |
| `FLIP_MAX_CELLS` (private) | 100,000 cells | per-frame ceiling for animated GIFs: one frame wider than `width × height` allows is refused with a mosaic-owned message before sugar-animation's decoder sees it (the sibling's own `Decoder::MAX_CELLS` mirror) |
| `FLIP_MAX_TOTAL_CELLS` (private) | 400,000 cells | animated-GIF aggregate budget: frames × per-frame pixels — stops a bomb whose every single frame is individually in-budget |
| `MAX_RIFF_CHUNKS` (private) | 64 | the pure-PHP WebP RIFF walk bails out of a chunk flood instead of iterating forever |

Every refusal cap above is pinned by tests; `FLIP_FRAME_CAP` is the
documented slice-out and is verified by the flip-walk parity pins around it,
not by its own boundary test.

## Chafa binary discovery

The chafa tier is the only place sugar-image executes an external
program to produce image output, and that binary is chosen through a
strict gate — the `sugar-colorprofile` `InfocmpBinary` precedent applied
to chafa (`src/Renderer/ChafaBinary.php`). A bare `chafa` argv[0] is
never spawned, so a rogue `chafa` sitting earlier on PATH is never
executed:

1. **Operator override** `SUGAR_IMAGE_CHAFA` (configuration is trusted
   input — naming a binary is consent to run it): an absolute path is
   used only when it is a file and executable, and a broken absolute
   override is REFUSED rather than silently substituted; a bare name
   authorises the PATH lookup for that one explicit value, scanning
   absolute PATH entries only (relative entries are the classic hijack
   door and are skipped).
2. Otherwise a **fixed roster** of absolute candidates —
   `/usr/bin/chafa`, `/usr/local/bin/chafa`, `/opt/homebrew/bin/chafa` —
   each gated on `is_file() && is_executable()`.
3. When nothing resolves, the tier degrades **exactly** as the missing
   binary always did: `ChafaRenderer::available()` answers `false` and
   `render()` throws the same `chafa.not_found` failure.

Two terminal *consults* do spawn a program by name, and they are stated here
rather than folded into the sentence above: `Detect` asks a live tmux for its
client features (`tmux display-message`, `src/Detect.php`) and
`TmuxPassthrough` drives tmux subcommands to wrap a frame for the mux
(`src/TmuxPassthrough.php`). Both are argv-array `proc_open` calls with no shell
in between, both are reached only when the environment already says we are
inside that tmux session, and neither one feeds the bytes that are rendered —
they report capabilities and manage the passthrough transaction. The three
`stty` calls that park and restore the terminal state around a probe do run
through `/bin/sh`, with the saved state quoted by `escapeshellarg`. No pure-PHP
tier ever shells out for pixel data.

`ChafaRenderer::reset()` re-arms both memos for long-running processes
that install or override the binary mid-session.

When a chafa run fails, the last 8 KiB of the child's stderr rides the
exception message **sanitized** — escape machinery forged into that stream
(curtains, hyperlinks, palette changes) is swept at the single read choke, so
the sentence can describe the failure but can never repaint the terminal that
logs it. Ordinary prose from the child, including text chafa echoed from the
image, still appears.

## Remote images

Load images straight from a URL. `fromUrl()` is synchronous (PHP stream
wrappers — only `http`/`https` permitted by default, see **Security** below;
redirects followed); `fromUrlAsync()`
is non-blocking on the ReactPHP event loop and resolves with a decoded
`ImageSource`, ideal for fetching many posters concurrently without stalling
the render loop.

```php
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Mosaic;

// Synchronous (blocks) — handy for scripts/CLIs.
$token = getenv('POSTER_TOKEN'); // from wherever your credentials live
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
an install hint rather than a fatal. The default Browser it builds carries the
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
> `ImageSource::overrideHostResolver()` swaps the guard's address resolution
> process-wide — it is a test seam for exercising these checks without real
> DNS, not a configuration knob; leave it alone outside tests.

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

$url = 'https://example.com/poster.png';
$mosaic = Mosaic::probe();
$cache  = new DiskCache($_SERVER['HOME'] . '/.cache/posters', maxEntries: 512);

// Key includes the protocol — the same image at the same size encodes
// differently for sixel vs kitty vs half-block. The ASCII renderer's colour
// arms share one protocol name, so append modeDetail() to protocol(). The
// real key Mosaic uses for its own posters is wider still: protocol + mode
// detail + the Scale NAME + the renderer's config fingerprint + the
// optimisation mask, each part appended only when non-empty (private
// Mosaic::posterCacheKey()) to keep them cache-distinct.
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

`Mosaic` carries the fetch-and-render pair directly, so a poster needs no
hand-rolled cache plumbing:

| Method | Transport | Notes |
|---|---|---|
| `poster($url, $cellW, $cellH, ?DiskCache, ?array $allowedHosts)` | sync | SSRF ladder + cache key above |
| `posterAsync(...)` | react/promise | same doors, resolves to the bytes |
| `posterFile($path, ...)` | local file | same key, no network |

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
- **Key units**: `c=`/`r=` are the cell grid the renderer computes, `s=`/`v=`
  are source display extents in *pixels*, and a place frame's cell destination
  travels on the uppercase `X=`/`Y=` pair — the lowercase `x=`/`y=` are the
  spec's source-rectangle pixel offsets, so they crop rather than move.

- **Zlib compression** (`o=z`): Pass `withCompression(1)` to compress
  the PNG payload with zlib before base64-encoding; the transmit is
  emitted as `f=100,o=z` (compression rides the spec's `o` key, `f`
  stays the data format). Useful for large images on slow links; adds
  modest CPU overhead.

```php
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\KittyOptions;
use SugarCraft\Image\Mosaic;

$renderer = (Mosaic::kitty())->renderer();
$image    = ImageSource::fromFile('examples/fixture-gradient.png');

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

`Mosaic::auto()` asks `Detect::probe()` — environment variables first, then a
DA1/XTWINOPS round trip on the TTY — and `bestBackend()` seats a renderer on
the answer: kitty → iterm2 → sixel → the symbol-map tier. Both `Mosaic::auto()`
and `Mosaic::probe()` resolve capabilities on every call; wrap
`Detect::cached()` at the application boundary to memoise the probe per-process
(`Detect::reset()` drops the memo). Chafa is not on that
ladder (its capability slot is written only by `Capability::universal()`), and
plain half-block appears only when the text-availability slot is itself off, so
the practical floor is the glyph-coverage engine. `Mosaic::diagnose()` returns
the structured `ProbeReport` behind the decision — sugar-colorprofile's
`SugarCraft\ColorProfile\Probe\ProbeReport`, straight off
`TerminalProbe::run()` — and
`Detect::probe()->detectSummary()` (instance method on the probed
`Capability`) names the slots rather than the chosen
renderer — a terminal that lands on the symbol tier still reports `HalfBlock`,
because that tier rides the same text slot.



Every renderer implements `Renderer::delete(string $imageId): string`
which emits the protocol-specific sequence to remove a previously
rendered image. The `$imageId` is the numeric image identifier passed
during rendering — the interface takes it as a string (`delete('1')`)
— (Kitty) or a placeholder for interface compatibility
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
instead: `ScreenPacketizer` and `ScreenPassthrough`, which live under
`Passthrough/` behind the shared `PassthroughDecorator` interface. The
tmux half of the family — `TmuxPassthrough` + `TmuxPassthroughDecorator`
— sits in the root namespace, not in `Passthrough/`.

## Animation

Drive a sequence of frames through `AnimationDriver` — a `Model`-implementing class that uses `Cmd::tick()` for per-frame timing and `Renderer::delete()` + `KittyRenderer::renderFrame()` for clean per-frame redraws on Kitty terminals (iTerm2 gets delete + a full re-render, as its payload has no stable per-frame id).

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
text, so they reach any Unicode-capable terminal for sighted readers:

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
  `--symbols=` selector expression over the wider corpus. That `symbols:`
  argument and `Mosaic::withSymbolSelectors()` are the same knob at two
  facades; both feed `Symbol\SymbolMap::withSelectors()`, where the tag
  grammar lives. The corpus
  arithmetic: the narrow tables (ASCII/Latin/misc/legacy, the
  U+1FB80–U+1FB8B eighth-block tier, braille, sextants, octants) parse to
  1,260 entries, but nine misc-narrow glyphs carry chafa's AMBIGUOUS tag
  (they have emoji variants) and are BAD-vetoed by every selector that
  does not name ambiguous/ugly/bad, exactly as the C build does —
  **1,251 glyphs effective** under `all` (derivation:
  `Symbol/SymbolSetTables.php`). The default 676-glyph corpus is
  byte-frozen. The double-width
  wide-glyph engine plus the opt-in kana tier ride
  `SymbolMap::withSelectors('wide')` and the renderer's lookback pass.

Assistive technology reads none of this as picture. A screen reader
pronounces braille/sextant glyphs by dot-pattern name, the block glyphs as
their Unicode labels, and there is no alt-text channel anywhere in the text
tiers; `ImageLayer` placements ride a paint layer, so an AT consumer traversing
the frame text sees the marker block and the surrounding lines, never the
image. The tiers are for sighted readers on Unicode-capable terminals — put
content that must survive assistive parsing in your own caption text.

`Mosaic::auto()` seats the symbol engine as the floor of the fallback ladder —
plain half-block is reached only when text output itself is refused — while the
ASCII ramp and the chafa CLI tier are explicit-selection only. Canvas mode is
not universal: `QuarterBlockRenderer` and `SymbolMapRenderer` implement
`withCanvasMode()`, `SymbolMapRenderer` alone implements `withWorkFactor()`, and
every other backend hands back the same instance, because it has no canvas to
reinterpret and no search window to widen.

## Limited-colour and no-colour terminals

The half-block, quarter-block and chafa-adjacent text paths emit 24-bit
foreground/background SGR unconditionally — there is no automatic palette
negotiation, so a terminal that does not honour truecolour discards or
misinterprets those sequences. The explicit escape hatches:

- `Mosaic::ascii(AsciiColorMode::Ansi16)` maps every pixel onto the 16-colour
  ANSI palette; `Mosaic::ascii()` defaults to `AsciiColorMode::Mono`, the one
  deliberate no-colour mode in the lib (luminance ramp, no colour bytes).
- The quarter-block and symbol-map tiers implement `withCanvasMode()`
  (`CanvasMode::Ansi256`, `CanvasMode::Ansi16`, `CanvasMode::Fgbg`); the
  symbol engine quantises its candidate scoring to the selected canvas, so
  the glyph choice adapts to the palette rather than inheriting the
  truecolour optimum.
- Sixel keeps its own palette budget: `maxColors` (1–256, default 256) caps
  the median-cut quantiser for limited-truecolour terminals, and `intensity`
  (0.0–1.0; null leaves the chafa config default 1.0) scales the
  `Dither::Ordered` / `Dither::Noise` bias — `intensity` arrives through
  `Mosaic::sixel(dither, intensity)`, `maxColors` only through the builder's
  renderer constructor.

`NO_COLOR` is **not** honoured by any renderer: the variable influences only
`Detect`'s terminfo sixel-detection ladder, changing which protocol is
detected, never which colours are emitted. On a limited-palette terminal,
select the ramp or canvas explicitly.

## Scaling

`Mosaic::withScale(Scale::…)` decides how a source fills the requested cell box;
the default is `Fit`, except `poster()`, which defaults to `Fill`.

| Scale | Behaviour |
|---|---|
| `None` | never resamples — the source keeps its pixel size |
| `Fit` | shrink or grow to the box, aspect preserved |
| `Fill` | cover the box, cropping the overflow |
| `Crop` | crop from the source to the box aspect, then fit |
| `Stretch` | ignore aspect and pull to the exact box |
| `ShrinkToFit` | like `Fit` but never upscales: native pixels are centred on a blank-padded box (the quarter-block tier cannot pad and falls back to filling) |

Cell boxes are not square, so the cover modes crop against a cell-height
multiple of the box (`CellGeometry`), and an auto height divides the source
aspect by that same cell ratio.

## ImageLayer — image boxes in a text frame

`ImageLayer` is the turnkey way to put real images inside a text UI. Instead of
emitting pixel bytes into the frame (which would wreck the layout), you reserve a
cell-sized **marker block** where the image belongs and hand the collected bytes
to the `View`. The runtime — sugar-core's `ImageOverlay` — paints each
marker it finds.

```php
use SugarCraft\Core\View;
use SugarCraft\Image\ImageLayer;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Mosaic;

$layer = new ImageLayer();

// Reserve a 20x10 box and stash the rendered bytes.
$ansiBytes = Mosaic::sixel()->render(ImageSource::fromFile('examples/fixture-gradient.png'), 20, 10);
$cell = $layer->place($ansiBytes, 20, 10);

// Compose $cell into the frame like any other text; $frame is the result —
// the text rows with $cell's marker in place.
$frame = "top row\n" . $cell . "\nbottom row";
return new View($frame, images: $layer->placements());
```

Identical bytes register once — dedup is by content hash (`xxh3`), so the same
image shown in several places shares one id and paints at every marker. The id
space is the Private-Use-Area window (sugar-core's
`SugarCraft\Core\ImageOverlay::MAX_IMAGES`, 6398); once it
is exhausted, further placements get a blank block of the requested size rather
than a wrong image.

### Getting the assigned id: `placeTracked()`

Some widgets need the overlay id as well as the marker — for example a poster
card that takes `withImage($bytes, $id)`, or code that wants to surgically clear
just the cells one image occupied. `placeTracked()` returns both as a
`PlacedImage`:

```php
// continued from the snippet above; $card is any widget with a withImage() setter
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
├── ApngDecoder            # Pure-PHP APNG frame walk (GD collapses APNG to its base frame)
├── FrameTickMsg           # Internal Msg for frame-advance ticks
├── ImageLayer             # Per-frame image registry: marker blocks + placements
├── ImageSource            # Image bytes + metadata + decode budgets (Limits)
├── KittyOptions           # Kitty protocol options (transmit / place / compress)
├── Lang                   # i18n facade
├── Mosaic                 # Facade: probe / auto / builder / render
├── MosaicBuilder          # Fluent Mosaic assembly
├── Detect / TermDb / TermInfo / Capability  # Capability detection, quirks tables + probe result
├── DiskCache              # On-disk render cache (key includes protocol + mode detail)
├── AdaptiveImage          # In-memory LRU poster cache (within a session)
├── AsyncRenderer / PacedAsyncRenderer / SyncAsyncRenderer  # Async render paths
├── PacedRenderSession     # Resumable slice pump over one incremental encode
├── PixelGrid              # 2-D cell grid (foreground, background, alpha, char)
├── PlacedImage            # placeTracked() result: marker block + assigned id
├── PrecomputedImage       # A finished render: encoded ANSI bytes + geometry metadata
├── TmuxPassthrough / TmuxPassthroughDecorator  # tmux allow-passthrough envelope (root namespace)
├── UnsupportedFormatException  # Recognised-but-refused container feature (vs corrupt input)
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
├── CellGeometry / CellSize  # Pixel aspect ↔ cell arithmetic + cell pixel dimensions
├── Deadline               # Monotonic millisecond budget tracker for I/O waits
├── Dither                 # Shared dithering arithmetic (Floyd-Steinberg, ordered…)
├── Levels                 # Luminance stretching (Rec.709 weights)
├── Optimization           # chafa-style output-optimization bitmask (mosaic tiers)
├── Quantizer              # Pairwise-nearest-neighbour palette quantiser (Sixel budget)
├── Render                 # Band splitting
├── Scale                  # Scaling / cropping modes for a fixed cell box
├── Passthrough            # GNU screen envelope decorators (interface + packetizer)
├── Concerns               # Shared traits (optimization carrier, render validation, raw pixels)
└── Support                # OutputOptimizer, GdAvailability, PushToPull
```

## PNG text metadata

`PngMetadata::stamp($png, $text)` writes an A1111 infotext block into the PNG `parameters` chunk (tEXt for ASCII, compressed iTXt for UTF-8; an existing `prompt` chunk is re-synced to the first line). `PngMetadata::extract($png)` returns `['parameters' => ?string, 'prompt' => ?string]` and never throws on damaged input. Lower-level `readText`/`writeText`/`isPng`/`strategyFor` are public. Pure byte transform, chunk framing per ISO/IEC 15948.
