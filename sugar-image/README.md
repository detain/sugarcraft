# SugarImage

Image-to-cell renderer for the terminal — PNG, JPEG, GIF (still and
animated), APNG, WebP and BMP decoded via ext-gd plus pure-PHP
animated-container decoders and rendered via the best
available protocol:

- **Sixel** — xterm built with sixel support (detection gates the xterm row on `XTERM_VERSION`), foot, mlterm, contour, rio
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
pure-PHP tier; without it each entry point answers with a `Lang::t` refusal —
the shipped `lang/en.php` catalog is English-only today — rather than an
undefined-function error, and `src/Support/GdAvailability.php` owns that
probe.
The chafa CLI is never required: the chafa tier is opt-in, selected only by
`Mosaic::chafa()` / `fromModeString('chafa')`, and chafa is the only external
binary the lib ever asks for image bytes.

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

- `examples/inline-image.php <image-file> [width-in-cells]` (default width 80)
  prints one image on the terminal's best tier and exits 0/1/2/3 — the
  shortest read of the detection path. The script's own header says what each
  code means; **Detection inputs** below maps the alphabet to the legs.
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

// Probe the terminal — auto()/probe() re-resolve on every call;
// wrap Detect::cached() to memoise one answer per process.
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
// Explicitly passing width or height <= 0 (including a withResize(0) box)
// throws InvalidArgumentException — a zero-column pane fails loudly, it is
// never silently clamped to a 1-column sliver; null still means "use the
// default box / derive from the aspect ratio".

// The builder holds every Sixel dial — dither, bias intensity, palette
// budget — and applies them at build() to any Sixel backend (explicit or
// auto-detected, bare or tmux-wrapped), in any call order. A raw
// SixelRenderer constructor is never needed for full sixel tuning:
$mosaic = Mosaic::builder()
    ->sixel(Dither::Stucki)        // Sixel + Stucki dithering
    ->withSixelIntensity(0.8)      // ordered/noise bias at 80%
    ->withSixelMaxColors(64)       // 64-color palette budget
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

Beyond `fromFile()`/`fromString()` the intake doors are: `fromGd()` — adopt
a GD resource some other code already decoded (`fromGd($gd, 'image/png')`;
the internal storage contract is PNG, so a non-PNG resource is re-encoded
through it and budget-checked on the way); `fromRgb()` — raw RGB24/RGBA wire
bytes plus an explicit box (`fromRgb($bytes, $w, $h, hasAlpha: true)`), the
kitty/pipe-ingress shape, under the same `MAX_PIXELS` ceiling as every other
door; `fromAnimatedFile()` — a container's frame list into an `Animation`
(see **Supported image formats**); and `fromUrl()`/`fromUrlAsync()` — see
**Remote images**.

Every backend implements `Renderer`: `render()` for the bytes, `name()` for
the stable identifier (`sixel`, `kitty`, `iterm2`, `chafa`, `halfblock`,
`quarterblock`, `ascii`, `symbols`), `supportsAlpha()` — true for the
graphics tiers, false for the text tiers, and informational for now (no
auto-ladder steers on it yet), `isInline()` — true when `render()` emits
newline-separated cell text that composes directly into a frame, false when
it emits one opaque escape blob that must ride an out-of-band overlay (see
**ImageLayer**) — and `delete()` (**Deleting images**). `Mosaic::renderer()`
hands back the live instance, tmux envelope included, and `Mosaic` answers
`isInline()` itself for callers that should not know the protocol.

## Supported image formats

Codec matrix (sugar-image native paths only):

| Container | sugar-image native | Notes |
|---|---|---|
| PNG (still + APNG) | ✅ decode | APNG frames via `fromAnimatedFile()` → an `Animation` |
| JPEG, static GIF | ✅ decode | GIF animates via `fromAnimatedFile()` → an `Animation` |
| WebP — static (VP8/VP8L/VP8X) | ✅ decode | via `fromString()` only — `fromFile()`'s declared-MIME allowlist is png/jpeg/gif; needs an ext-gd WebP build |
| BMP | ✅ decode | via `fromString()` only — `fromFile()` refuses it: its declared-MIME allowlist is png/jpeg/gif |
| WebP — animated (VP8X ANIM bit / ANMF) | ❌ refuse | refused by name: `UnsupportedFormatException` before any decode — needs chafa CLI or sugar-video's pure-PHP `AnimatedWebpDecoder` (no released ffmpeg decodes animated WebP frames — its demuxer skips ANMF); the refusal is structural (pure-PHP RIFF walk), so the silent first-frame degrade class is closed on every host |
| AVIF / HEIF / HEIC (`ftyp` brands) | ❌ refuse | refused by name: no PHP-side decoder; chafa CLI renders a still |
| JPEG XL (`FF 0A` codestream, `jxl ` brand) | ❌ refuse | refused by name: same |
| TIFF (`II*\0` / `MM\0*`) | ❌ refuse | refused as input; output-only — the GD-free iTerm2 payload encoder (`Codec\TiffUncompressed`) |

The matrix states what decodes where. Three facts it cannot carry:

- `format` on a `fromString()` payload keeps the real MIME GD sniffed (or
  `application/octet-stream`), and the Kitty/iTerm2 renderers re-encode any
  payload that is not PNG by signature.
- Palette PNGs are automatically converted to truecolor before
  processing, through one checked door (`Concerns\ForceTrueColor`): if ext-gd
  ever refuses the conversion, the read fails with a `RuntimeException`
  instead of sampling palette-indexed pixels as RGB. Every `imagecreatefromstring()`
  call in the lib runs `@`-suppressed with an explicit `false` check, so a
  corrupt payload produces exactly one thrown refusal — never warning spam
  ahead of it. `tests/GdCallIdiomCensusTest.php` greps the source tree and
  keeps both idioms from drifting back.
- Animation has exactly one entry: `ImageSource::fromAnimatedFile()` → an
  `Animation` (frames + per-frame delays, the input to `AnimationDriver`).
  The still paths above decode a single frame of an animated container —
  `fromFile()`/`fromString()` on a GIF or APNG yield the first frame, silently.

## Limits

Decoding untrusted images is budgeted — the caps are constants on
`ImageSource`, and the sequence-length cap is one on `Animation`:

| Budget | Value | What it stops |
|---|---|---|
| `ImageSource::MAX_BYTES` | 64 MiB | the file, animated-file, URL and `fromString()` reads — oversized payloads are refused before GD ever sees them (the in-memory route measures its buffer up front, so every byte-intake path shares one ceiling; the format-signature and pixel budgets below still guard what fits) |
| `ImageSource::MAX_PIXELS` | 50,000,000 px | per-decode pixel budget — `fromFile()`, `fromAnimatedFile()` and `fromString()` check it against declared dimensions, every renderer re-checks it on the decoded frame, and `withMaxPixels()` tunes it per source |
| `FLIP_FRAME_CAP` (private) | 256 frames | animated-GIF frame list: walks beyond the cap are sliced away rather than decoded (a silent truncation, not a refusal) |
| `FLIP_MAX_CELLS` (private) | 100,000 cells | per-frame ceiling for animated GIFs: a frame whose `width × height` cell footprint exceeds it is refused with a mosaic-owned message before sugar-animation's decoder sees it (mirrors the sibling's own `Decoder::MAX_CELLS`) |
| `FLIP_MAX_TOTAL_CELLS` (private) | 400,000 cells | animated-GIF aggregate budget: frames × per-frame pixels — stops a bomb whose every single frame is individually in-budget |
| `MAX_RIFF_CHUNKS` (private) | 64 | the pure-PHP WebP RIFF walk bails out of a chunk flood instead of iterating forever |
| `Animation::MAX_FRAMES` | 10,000 frames | the frame-list length itself: `new Animation(...)` and `withFrame()` refuse a longer sequence — a driver walking an unbounded list could pre-materialise unbounded memory, so the cap belongs to the value object, not the walk |

What the budgets are and what proves them, honestly: `ImageSource::MAX_BYTES`,
`MAX_PIXELS` and `Animation::MAX_FRAMES` refuse and are pinned by boundary
tests; `FLIP_MAX_TOTAL_CELLS` refuses and is pinned by the recalibrated
cell-budget bomb leg in `tests/ImageSourceGifRound4SecurityTest.php`;
`MAX_RIFF_CHUNKS` is not a refusal at all — exhausting it gives up the
pure-PHP walk fail-soft (GD's judgement takes over), pinned as that give-up.
`FLIP_FRAME_CAP` truncates silently (walks beyond it are sliced away) and is
verified by the flip-walk parity pins around it, not by its own boundary test;
and the `FLIP_MAX_CELLS` per-frame refusal's boundary itself is not pinned by
a dedicated test — it shares its guard helper and refusal grammar with the
pinned pixel ceilings, and its gap is on the record here rather than
papered over by "every cap above is pinned".

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

The grammar is POSIX-shaped by construction: absolute means leading `/`,
and the roster is three fixed POSIX paths. **The chafa tier is therefore
POSIX-only.** On Windows nothing in the roster can exist, a `C:/…`
override is refused as a slash-bearing relative name, a backslash
`C:\…` override reads as one unresolvable "bare name" the PATH
composition can never materialise, and the PATH leg itself only accepts
absolute POSIX-style entries. `SUGAR_IMAGE_CHAFA` is a dead knob there — say it plainly so an
embedder learns it from this page instead of from an unexplained
`chafa.not_found`: use one of the pure-PHP tiers on Windows hosts.

Two terminal *consults* also spawn a program, and since SI-01 they resolve
through the same doctrine — `src/Support/TmuxBinary.php` and
`src/Support/SttyBinary.php` apply this section's grammar to `tmux`
(new `SUGAR_IMAGE_TMUX` override) and `stty`: `Detect` asks a live tmux for its
client features (`tmux display-message`, `src/Detect.php`) and
`TmuxPassthrough` drives tmux subcommands to wrap a frame for the mux
(`src/TmuxPassthrough.php`). Both are argv-array `proc_open` calls with no shell
in between, no bare `tmux` argv[0] survives this gate (an unresolvable
binary means no transaction, never a PATH gamble), both are reached only
when the environment already says we are
inside that tmux session, and neither one feeds the bytes that are rendered —
they report capabilities and manage the passthrough transaction. The `stty`
calls that park and restore the terminal state around a probe run their
resolved absolute binary through `/bin/sh` as fixed literal words, with the
saved state quoted by `escapeshellarg`, and the restore is now verified —
re-read after write, one retry, then `stty sane` plus a one-line stderr
notice rather than a silent strand in raw mode. No pure-PHP
tier ever shells out for pixel data.

`ChafaRenderer::reset()` clears the whole discovery cache — availability,
version, the per-config render memo, and the sink and reap probes — for
long-running processes that install or override the binary mid-session.

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
same 30-second per-request timeout as the sync path
(`ImageSource::FETCH_TIMEOUT_SECONDS`); pass your own
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

## Async and precomputed renders

Encoding a poster is the expensive part, and a whole-buffer encode monopolises
the event loop for its duration. The async seam fixes the wait, the precompute
seam fixes the repetition:

```php
use SugarCraft\Image\SyncAsyncRenderer;

$adaptive = Mosaic::probe()->adaptive($image);   // in-memory LRU over one source

// renderAsync() returns a promise resolved with the encoded bytes. The
// default strategy pumps the mosaic's row-lazy seam in budgeted slices and
// yields the loop between them (PacedAsyncRenderer), so the encode never
// blocks the TUI that scheduled it; pass a SyncAsyncRenderer to opt into
// "schedule, then block the loop once" for tests and single-shot scripts.
$adaptive->renderAsync(24, 36)
    ->then(fn (string $ansi) => print($ansi));

// withAsync() returns a fresh twin — entries render() memoised on the
// original do not carry over, and null means "take the default strategy",
// never "disable async". render() itself stays synchronous on every twin.
$still = $adaptive->withAsync(new SyncAsyncRenderer($mosaic));

// precompute(): pay the encode once, blockingly, and replay bytes forever.
$memo = $adaptive->precompute(24, 36);           // PrecomputedImage
echo $memo->bytes();                              // redraw: zero encode
echo $memo->cellWidth(), $memo->cellHeight();     // the box it was cut for
```

The library-wide capability roster (what sugar-image can speak at all, in
preference order — not a probe of this terminal) is the static
`Mosaic::supportedProtocols()`; asking what "this" terminal answers is
`Mosaic::probe()`'s job. A doctor panel prints one beside the other to show
what the terminal refused.

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
// real key Mosaic uses for its own posters is wider still: protocol, mode
// detail, the Scale enum name, the renderer's config fingerprint and the
// optimisation mask — each part appended only when non-empty (private
// `Mosaic::posterCacheKey()`), so posters that differ in any of them can
// never share a cache entry.
$key = DiskCache::key($url, width: 24, height: 36, protocol: $mosaic->protocol() . ($mosaic->modeDetail() ? ':' . $mosaic->modeDetail() : ''));

$ansi = $cache->getOrCompute($key, fn (): string =>
    $mosaic->render(ImageSource::fromUrl($url), 24, 36));
echo $ansi;
```

`get()`/`getOrCompute()` touch an entry on a hit — coarsely: an entry younger
than a minute skips the touch, because a redraw loop used to pay one write
syscall per frame for an mtime change the 1-second filesystem resolution cannot
even observe. `put()` evicts under two budgets: the entry count
(`maxEntries`, trimmed immediately — a cap that lags a burst is no cap) and the
directory bytes (`maxBytes`, defaulting to `ImageSource::MAX_BYTES` — the
store never holds what the fetch pipeline refuses to source), whose sweep
wakes only past 125 % of the budget and then trims back to it (a cap-sized
overshoot is churn, not eviction). `get()` also refuses to read any single
entry larger than `maxBytes` — such an entry is never touched, so it carries
the oldest mtime and ages out first. (mtime is 1-second-resolution, so the caps
are always honoured but same-second writes are not strictly least-recently-used
among themselves; the victim order is at least deterministic — oldest, then
lowest hashed path.) Writes are atomic (temp file + rename)
and keys are hashed to derive the filename, so an arbitrary key can never escape
the cache directory. When a poster changes upstream, invalidation is public
API: `has()` to ask, `delete()` for one key, `clear()` for the store, `count()`
to census it — and `DiskCache::key()` is static precisely so an invalidator
can re-derive the same key without the original writer in hand.

`Mosaic` carries the fetch-and-render pair directly, so a poster needs no
hand-rolled cache plumbing:

| Method | Transport | Notes |
|---|---|---|
| `poster($url, $cellW, $cellH, ?DiskCache, ?array $allowedHosts)` | sync | SSRF ladder + cache key above |
| `posterAsync(...)` | react/promise | same doors, resolves to the bytes |
| `posterFile($path, ...)` | local file | same key, no network |

## KittyOptions — virtual-image placement and compression

The Kitty renderer exposes three options via `KittyOptions`: two that
control placement and one that controls the payload encoding:

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
  The `s=`/`v=` pair is set by `KittyOptions::withSourceDimensions($w, $h)`;
  the older `withCellDimensions($w, $h)` carries the identical pixel contract
  under a misleading name (kept for wire compatibility — neither takes cell
  counts, and `c=`/`r=` stay renderer-computed either way).
- **Zlib compression** (`o=z`): pass `withCompression(1)` to compress
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
renderer. The symbol tier rides the same text-availability slot, so a terminal
that lands on it still reports `HalfBlock` in the summary.

### Detection inputs

The probe is a function of documented inputs — no hidden state. Four
families:

**Identity variables.** `TermDb::ENV_KEYS` is the whole census: the
table-driven identity stage reads exactly these fifteen variables and
nothing else.

| Variable | What it contributes |
|----------|-------------------|
| `TERM` | terminfo class; absent or `dumb` floors the probe to unknown |
| `TERM_PROGRAM`, `TERM_PROGRAM_VERSION` | launching emulator and version |
| `LC_TERMINAL`, `TERMINAL_NAME` | alternative emulator branding exports |
| `TMUX` | presence gates the tmux passthrough transaction (see Inside tmux) |
| `KITTY_WINDOW_ID`, `KITTY_PID` | kitty identity markers |
| `XTERM_VERSION`, `KONSOLE_VERSION`, `VTE_VERSION`, `MLTERM`, `GHOSTTY_BIN_DIR`, `WEZTERM_EXECUTABLE`, `ComSpec` | per-terminal identity markers (the live-DA1 round trip on the TTY arbitrates what the table cannot settle) |

**Colour-related variables.** `NO_COLOR` (and `CLICOLOR=0`) are consulted
only by the terminfo sixel-detection ladder: they can change which
protocol is detected, never which colours are emitted — no renderer
honours them (see Limited-colour and no-colour terminals below).

**Binary overrides.** `SUGAR_IMAGE_CHAFA`, `SUGAR_IMAGE_TMUX` and
`SUGAR_IMAGE_STTY` each name the external binary their stage consults,
through the same absolute-path gate described in Chafa binary discovery.
Configuration is trusted input; `PATH` is consulted only for the one
explicitly named bare value, absolute entries only.

**Passthrough lease.** `SUGAR_IMAGE_TMUX_PASSTHROUGH_TTL` sets the
re-validation interval (seconds, default 30; `0` re-verifies before
every frame, negative or unparsable falls back to the default), so a
mid-session `set-option allow-passthrough off` elsewhere on the server
is noticed instead of being asserted forever — see Inside tmux.

`examples/inline-image.php` is the shortest read of this path; its
documented exit alphabet is the script header's, and these are the legs
that produce it (the GD check at the top of `main()` precedes argument
parsing, so a GD-less box with no arguments exits 2, not 1):

| Exit | Leg |
|------|-----|
| 0 | the image rendered and its bytes were written to stdout |
| 1 | usage: no image path given, or a width that is not a positive integer of cells, or no file at the given path |
| 2 | ext-gd is not loaded |
| 3 | the path exists but is not a readable regular file; the bytes did not load as a supported image; or the render refused at the requested size (e.g. the canvas-ceiling guard) |



## Deleting images

Every renderer implements `Renderer::delete(string $imageId): string`,
which returns the escape sequence that removes a previously rendered
image (or `''` when the protocol has nothing to remove). The interface
types `$imageId` as a string — `delete('1')` — even where the protocol
addresses images numerically: the numeric id Kitty's wire uses is
assigned at render time and handed to `KittyOptions::place()` or
`KittyOptions::transmit()` there, and `delete()` simply formats it into
the APC `a=d;I=<id>` control sequence (the id alone does nothing
without a number qualifier). iTerm2's Pop carries no id at all — it
removes the most recent image from the cursor, so its `delete()` returns
a constant Pop and ignores the argument — and every text-protocol tier
(Sixel, HalfBlock, QuarterBlock, Chafa, Ascii, SymbolMap) has no stored
image to address: its `delete()` returns `''`, clears nothing, and the
argument is a placeholder for interface uniformity.

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
line. `TmuxPassthrough` automates chafa's workaround as a scoped
transaction: `ensure()` reads the current value exit-aware (a `no such
option` error is not an unset — on servers predating the pane-scoped
option the answer falls back once to the global scope, and the restore
matches whichever scope carried the truth: `-gu` clears a global the
transaction found unset, `-g` re-sets one it found off), flips
`allow-passthrough on` only when needed, and `retire()`
restores the prior value — or unsets with `-u` exactly when it was unset
before. A process-lifetime restore is registered on first change, and the
whole thing is **fail-soft**: outside tmux, without the binary, or against
an unparseable answer it stays quiet — nothing is changed and nothing is
thrown, leaving passthrough to manual configuration. The applied answer rides a re-validation lease
(`SUGAR_IMAGE_TMUX_PASSTHROUGH_TTL`, default 30 s): at expiry the next
frame re-queries the option and, if something else flipped it off, takes
the transaction over — restoring the flipper's value on shutdown — rather
than keep asserting an envelope that no longer reaches the outer
terminal; a re-query that comes back dead or uninformative fails soft and
keeps the ledger. Embedders who own their tmux configuration opt out with
`Detect::setAutoPassthrough(false)`. GNU screen has its own envelope
instead: `ScreenPacketizer` and `ScreenPassthrough`, which live under
`Passthrough/` behind the shared `PassthroughDecorator` interface. The
tmux half of the family — `TmuxPassthrough` + `TmuxPassthroughDecorator`
— sits in the root namespace, not in `Passthrough/`.

## Animation

Drive a sequence of frames through `AnimationDriver`, a `Model`-implementing
class that uses `Cmd::tick()` for per-frame timing. On Kitty terminals a frame
change is a `Renderer::delete()` plus `KittyRenderer::renderFrame()` — a clean
per-frame redraw; iTerm2 gets a delete and a full re-render instead, because
its payload carries no stable per-frame id.

Two timing facts belong to this contract. Scheduling is floored, not literal:
`AnimationDriver::MIN_TICK_MS` (10 ms) silently clamps a zero or negative
frame delay, so a sloppy delay cannot livelock the host loop with
zero-second ticks. And the encode is paced, not non-blocking: `view()` renders
the current frame inline on the calling thread, so on a slow backend the
encode cost adds to every frame's wall time regardless of the tick floor.
Loop-sensitive hosts want the seam above rather than a faster tick —
`AdaptiveImage::precompute()` encodes once and replays bytes, and
`PacedAsyncRenderer` budgets the encode into loop-yielding slices.

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
  `Mosaic::symbols(CanvasMode $canvas = CanvasMode::TrueColor, float $workFactor = SymbolMapRenderer::DEFAULT_WORK_FACTOR, ?string $symbols = null)` picks the colour
  canvas, the candidate-budget work factor, and a chafa
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
reinterpret and no search window to widen. The silence is consultable, not
guesswork: `acceptsDither()`, `acceptsCanvasMode()`, `acceptsWorkFactor()` and
`acceptsSymbolSelectors()` answer for the live renderer (through a passthrough
envelope too), and `ignoredDials()` returns the fixed-order list of dial names
that backend cannot hear — a doctor panel or pane host asks before offering a
knob instead of letting someone tune a dead dial.

## Limited-colour and no-colour terminals

The coloured text tiers default to 24-bit foreground/background SGR and
nothing negotiates with the terminal — a terminal that does not honour
truecolour discards or misinterprets those sequences unless you steer them
with the explicit escape hatches below. The half-block tier offers none: it is
truecolour or nothing.

- `Mosaic::ascii(AsciiColorMode::Ansi16)` maps every pixel onto the 16-colour
  ANSI palette; `Mosaic::ascii()` defaults to `AsciiColorMode::Mono`, the one
  deliberate no-colour mode in the lib (luminance ramp, no colour bytes).
- The quarter-block and symbol-map tiers implement `withCanvasMode()`
  (`CanvasMode::Ansi256`, `CanvasMode::Ansi16`, `CanvasMode::Fgbg`); the
  symbol engine quantises its candidate scoring to the selected canvas, so
  the glyph choice adapts to the palette rather than inheriting the
  truecolour optimum.
- Sixel keeps its own palette budget: `maxColors` (1–256, default 256) caps
  the palette the encoder may build, and by default that palette comes from
  **median-cut over a strided sample of at most 4,096 pixels** — a
  byte-frozen shape, so tuning `maxColors` never changes which quantiser
  answers, only how many colours survive it. The chafa `-w` ladder is the
  opt-in alternative: `withQuality(1–9)` routes the census to the
  pairwise-nearest-neighbour `Quantizer` (same 4,096-sample budget; the dial
  moves the histogram depth, and a frame with fewer samples than colours
  falls back to median-cut because PNN cannot merge below one-per-bin).
  `intensity` scales the `Dither::Ordered` / `Dither::Noise` bias —
  null means chafa's config default of 1.0; values above 1.0 clamp to 1.0
  silently on every path, while the lower door only exists on the builder
  dial `MosaicBuilder::withSixelIntensity()`, which refuses NaN and negatives
  — `Mosaic::sixel(dither, intensity)` and a raw `SixelRenderer` hand a
  negative straight to the bias arithmetic, so the 0.0–1.0 range is a
  builder-enforced contract and a documented courtesy elsewhere. `intensity`
  arrives through `Mosaic::sixel(dither, intensity)` or the builder dial,
  and `maxColors` through the builder dial
  `MosaicBuilder::withSixelMaxColors()` (or a raw renderer
  passed to `withRenderer()`).

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
| `ShrinkToFit` | like `Fit` but never upscales: native pixels are centred on a blank-padded box (the quarter-block tier cannot represent the blank — its grid reads RGB only, so a transparent pad would paint black cells — and keeps `Fit`-shaped output until nullable quads land, a disclosed follow-up) |

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
$view = new View($frame, images: $layer->placements());
// $view is the composed frame: hand it to the program's draw/output path.
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
│   ├── SixelRenderer      # DEC sixel; median-cut default, opt-in PNN via withQuality
│   └── SymbolMapRenderer  # chafa coverage-class symbol engine
├── Symbol                 # Glyph corpora: tables, tags, kana + wide defs
├── Codec                  # GD-free payload codecs (TIFF encoder, raw RGBA)
├── CellGeometry / CellSize  # Pixel aspect ↔ cell arithmetic + cell pixel dimensions
├── Deadline               # Monotonic millisecond budget tracker for I/O waits
├── Dither                 # Shared dithering arithmetic (Floyd-Steinberg, ordered…)
├── Levels                 # Luminance stretching (Rec.709 weights)
├── Optimization           # chafa-style output-optimization bitmask (mosaic tiers)
├── Quantizer              # Pairwise-nearest-neighbour palette quantiser (opt-in Sixel quality rung)
├── Render                 # Band splitting
├── Scale                  # Scaling / cropping modes for a fixed cell box
├── Passthrough            # GNU screen envelope decorators (interface + packetizer)
├── Concerns               # Shared traits (optimization carrier, render validation, raw pixels)
└── Support                # OutputOptimizer, GdAvailability, PushToPull
```

## PNG text metadata

`PngMetadata::stamp($png, $text)` writes an A1111 infotext block into the PNG `parameters` chunk (tEXt for ASCII, compressed iTXt for UTF-8; an existing `prompt` chunk is re-synced to the first line). `PngMetadata::extract($png)` returns `['parameters' => ?string, 'prompt' => ?string]` and never throws on damaged input. Lower-level `readText`/`writeText`/`isPng`/`strategyFor` are public. Pure byte transform, chunk framing per ISO/IEC 15948.
