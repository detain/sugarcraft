# CandyMosaic — Caliber Learnings

Accumulated patterns and gotchas specific to this library.

- **[ext-gd colorspace]** GD reads PNG as truecolor by default; palette
  PNGs need `imagepalettetotruecolor()` first. Handle in `PixelGrid::fromGd`.
- **[GD alpha and imagecopyresampled]** `imagecopyresampled` applies
  alpha-weighted interpolation during resize — it averages alpha channels
  of neighboring pixels, corrupting transparency information. Even with
  `imagealphablending($dst, false)` set, the interpolation step blends alphas.
  Workaround: ensure source image dimensions match cell dimensions
  (1:1 pixel mapping) so `imagecopyresampled` performs no interpolation.
  Alternatively, use manual nearest-neighbor sampling with `imagecolorat` +
  `imagecolorsforindex` to preserve exact alpha.
- **[HalfBlock transparent pixels]** Alpha=127 (GD fully transparent)
  maps to `null` in the PixelGrid cell tuple; alpha=0 (opaque) maps to `0`
  (non-null). `HalfBlockRenderer` skips SGR codes for `null`-alpha pixels,
  letting the terminal default show through. For a cell where only one
  half is transparent, the renderer emits the opposite half-block glyph
  (`▀` for bottom-transparent, `▄` for top-transparent) with only the
  visible half's SGR codes.
- **[Sixel quantization]** Median-cut produces ≤256 colors per image.
  The protocol limit is 256; if the image has more colors the renderer
  still works but quality may be reduced. `SixelRenderer` accepts a
  `maxColors` constructor parameter (1-256, default 256) for palette
  limiting in terminals with limited color support.
- **[Terminal cell aspect]** Terminal cells are roughly 1:2 (wide:tall).
  The half-block renderer doubles vertical resolution → near-square
  pixels. Sixel/Kitty operate in pixel space; we set cell dimensions
  and the terminal handles scaling.
- **[Kitty chunk size]** Protocol specifies max 4096 bytes per chunk.
  Round down to 4092 to account for base64 padding overhead.
- **[Animated GIFs]** (step 11.04 / PR#660) Animation lives in
  `candy-mosaic` — not `candy-flip`. `Animation` is frame-source-agnostic:
  ctor takes `list<ImageSource>` (any grid-backed image, not just GIFs).
  candy-mosaic does **not** depend on candy-flip. A future GIF→Mosaic bridge
  (decoding GIF frames into `ImageSource[]`) lives in candy-flip if needed.
- **[pattern:animation-value-object]** `Animation` is an immutable
  readonly value object: `list<ImageSource> $frames` + `list<int> $delaysMs`.
  Construction validates via `Lang::t()` that frames is non-empty and
  `$delaysMs` count matches frame count (mismatch → validation error).
  All state is `readonly`. Mutations return new instances via a private
  `mutate()` helper; public `withFrame()`/`withDelay()` methods are fluent.
  `AnimationDriver` is a `final` class implementing `Model`: it composes
  `Animation` (read-only) + current-frame index + tick counter; uses
  `Cmd::tick()` for per-frame timing; drives a delete+render cycle per
  `View` call (delete prior frame via `Renderer::delete()`, render current
  frame via `Renderer::renderFrame()`, matching the step 07.12 API contract).
  Do not subclass `Animation` — extend via composition instead.
- **[QuarterBlockRenderer 2×2 sub-pixel]** Uses `PixelGrid::fromGdQuarter`
  to scale the GD image to `cellW*2 × cellH*2` pixels, then samples four
  quadrants (ul/ur/ll/lr) per cell. A 4-bit mask (1 bit per quadrant; 1 =
  bright if any RGB channel > 10) indexes a 16-glyph map (░▒▓█ shades).
  All four quadrants share the same source pixel colour — bright quadrants
  render as foreground, dim as background, both via 24-bit ANSI SGR.
  `supportsAlpha()` returns `false` — no transparency blending.

- **[Renderer::delete()]** Each renderer implements
  `Renderer::delete(string $imageId): string` for removing a previously
  rendered image. Kitty uses APC `a=d` (specific id); iTerm2 uses OSC
  1337 Pop (top-of-stack, ignores id); Sixel/HalfBlock/QuarterBlock/Chafa
  return `''` (no delete mechanism). When adding a new renderer, implement
  `delete()` even if it only returns `''` — the interface contract
  requires it.

- **[Kitty virtual-image placement (a=p)]** The Kitty protocol supports
  two-phase rendering: transmit once with a specific image ID and action
  `a=p` to store the PNG data in the terminal, then reference the stored
  copy at arbitrary cell offsets with `a=p` + `i=<id>` + `x=`/`y=` (see
  `KittyOptions::transmit()` then `KittyOptions::place()`). This avoids
  re-transmitting the full image data for multi-instance display. The
  terminal manages the stored image lifetime — no explicit cleanup unless
  `Renderer::delete(id)` is needed.

- **[Kitty zlib compression (f=1)]** Pass `KittyOptions::withCompression(1)`
  to compress the PNG payload with `gzcompress()` before base64-encoding.
  The `f=1`传输 field signals zlib decompression to the terminal. Worthwhile
  for large images on slow links; adds modest CPU overhead on both sides.
  Compression level 1 (fastest) is the Kitty spec minimum and sufficient.

 - Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.

 - **[ProcessAsyncRenderer (future)]** A process-based AsyncRenderer that forks
   a child PHP process per render could bypass PHP's single-threaded GIL
   constraint and parallelize encoding across CPU cores. This is a known
   future consideration but not yet implemented. The current SyncAsyncRenderer
   runs on the event loop thread and does not parallelize work.
 - **[AsyncRenderer cancellation (known limitation)]** The current AsyncRenderer
   interface does not support cancellation. If a render is in-flight and the
   caller abandons the promise (e.g., user navigated away), the render
   continues to completion internally. Implementing cancellation would require
   cooperative cancellation tokens passed through the render chain. This is
   a known limitation for long-running renders (large GIFs, high-res images).
 - **[ImageLayer lives here now]** `SugarCraft\Mosaic\ImageLayer` (the turnkey per-frame image-registry helper) was extracted from candy-core into candy-mosaic. It reaches back into candy-core for the runtime overlay-contract via `use SugarCraft\Core\ImageOverlay;` + `use SugarCraft\Core\ImagePlacement;` — those two classes INTENTIONALLY stay in candy-core (driven by `Program::renderFrame()` / `View::$images`); moving them here would circular-dep against candy-core, which candy-mosaic already requires. This one-way `candy-mosaic → candy-core` edge is the intended direction.
 - **[placeTracked vs place]** `ImageLayer` has TWO placement accessors on purpose. `place(): string` returns only the marker block and deliberately KEEPS that `string` return — frame-composition callers just concatenate it, and changing its signature would break every one of them. `placeTracked(): PlacedImage` is the additive sibling that also reports the assigned overlay id; it exists for `sugar-gallery`'s `PosterCard::withImage(bytes, id)`, which needs the id the layer allocated (PR #1121 assumed the app owned id allocation, PR #1122 moved allocation into `ImageLayer` and hid it — this closes that gap). `place()` is now a one-line delegate to `placeTracked()`, so the two can never drift. Do NOT tell callers to recover the id via `array_key_last(placements())`: on a dedup hit `??=` returns the existing id and re-assigning an existing key preserves its original insertion position, so `array_key_last` reports the HIGHEST id instead of the one just placed; and the overflow branch returns early without writing a placement, so it reports a stale unrelated id. `count()` pre-flight breaks both ways too. Return type `PlacedImage` lives in candy-mosaic (NOT candy-core) to preserve the one-way edge above — same reasoning as `ImagePlacement` staying in candy-core. It follows the house value-object pattern set by `ImagePlacement` (PR #1130, `4fb6502a`): a `final readonly class`, not an `array{marker, imageId}` shape, and like all 10 sibling readonly VOs in candy-core/candy-mosaic it has no `::new()` factory (required-arg VOs are exempt from `[pattern:new-factory-required]`).

## Deferred (audited, specified, deliberately NOT implemented)

- **#17 Streaming sixel encode (deferred).** `SixelRenderer::render()` currently
  materialises the whole pixel canvas (`imagecreatetruecolor(pixelW×pixelH)`),
  a full per-row index grid, and the complete output string in memory before
  returning bytes. For video playback the plan was an incremental encoder:
  `SixelRenderer::encodeBandStream(ImageSource $image, int $width, callable $write): void`
  (or a `Generator<string>` variant) that quantizes ONE 6-row band at a time —
  header once, palette once, then per-band `yield`/callback emission — so peak
  memory is O(pixelW) instead of O(pixelW × pixelH) and a consumer can start
  writing to the TTY while later bands are still quantizing.
  Constraints found while auditing: (a) the median-cut palette must be computed
  from a sample of the FULL image before any band is emitted (a per-band
  palette would break colour consistency across bands) — so the source decode
  pass stays whole-image, only the index grid + encoding stream; (b) dithering
  error diffuses only downward/forward, so band N can finish diffusing into
  band N+1's first row before N is emitted — the streaming loop must carry the
  one-row lookahead, not slice accum blindly; (c) `ImageSource::bytes` must be
  re-decodable per band (keep one `GdImage` open; do not re-run
  `imagecreatefromstring` per band); (d) tmux passthrough wrapping decorates
  the FINAL string, so `TmuxPassthroughDecorator` needs a streaming branch
  (`\x1bPtm;...ST` envelope around the DCS) or streaming must be refused under
  tmux — decide before building. API sketch above is the contract a later
  phase should keep so callers can migrate mechanically.

- **#18 GIF/APNG multi-frame decode (deferred).** `ImageSource::fromFile()` /
  `fromString()` collapse animated inputs to their FIRST frame (GD's
  `imagecreatefromgif` does this implicitly; APNG decodes as the base PNG).
  The ported `Animation` value object accepts caller-supplied frames + delays,
  so nothing is broken — but a turnkey `ImageSource::fromAnimatedFile()` that
  yields `list<ImageSource>` + per-frame delays was specced and NOT built.
  Constraints: (a) GD 2.1 exposes no frame API — GIF frame extraction needs
  ext-imagick (not a dependency of this lib and not installable in every CI
  runner), a pure-PHP LZW/giflib bridge, or shelling out (`gifsicle`/ffmpeg);
  any of these changes the lib's dependency posture, which is an
  orchestrator-level decision, not an implementation detail. (b) APNG needs
  fcTL/fdAT chunk walking over the PNG container (pure PHP is feasible: the
  chunks are length-prefixed and zlib-inflatable) but frame compositing is
  dispose-op stateful (none/background/previous) — budget a real state-machine,
  not a decoder loop. (c) Whatever lands MUST thread `MAX_PIXELS` per frame
  and across total decoded frames (an animation bomb can be small per-frame
  and huge in aggregate) and respect the `DiskCache::FORMAT_VERSION` bump rule
  if renderer-visible behaviour changes.

## This session's API additions (2026-09, upstream-track PR)

- **[kitty() force factory]** `Mosaic::kitty()` mirrors `iterm2()`/`sixel()` —
  callers previously had to reach through `Mosaic::builder()->withRenderer(new
  KittyRenderer())` for a Kitty pin. `supportedProtocols()` also lists `ascii`
  now (the `ascii()` factory predated the roster; the list had drifted).
- **[fromRgb()]** `ImageSource::fromRgb(string $bytes, int $w, int $h, bool
  $hasAlpha = false)` ingests a raw RGB24/RGBA scanline buffer with no
  container: exact-length + `MAX_PIXELS` guards first, one O(n)
  imagesetpixel pass into a truecolor GD image, then the existing `fromGd()`
  encode. sugar-reel's `GraphicsRenderer` switched its raw-frame path from a
  per-frame `toGd()+imagepng` round-trip to this (short/malformed buffers keep
  the old clamping path). RGBA byte alpha 255=opaque maps to GD 0=opaque via
  `intdiv((255-$a)*127, 255)`.
- **[ImageLayer windowing]** The phlix-console-client PosterLoader's
  hand-rolled policy is now first-class: `ImageLayer::digestFor($bytes,$w,$h)`
  (content+cell-footprint window key — same bytes at another size is a
  different terminal allocation), placements register their window digest, and
  `release(int $id): string` / `releaseAllExcept(array $keepIds):
  array<int,string>` return the protocol delete sequences to emit when a
  viewport window moves. The layer takes an OPTIONAL `?Renderer` at
  construction for exactly that; without one the releases still un-register
  and report `''`. Deliberate: releasing KEEPS the content→id dedup (ids are
  content identity; a re-placed image reuses its id and stale scrollback
  markers repaint correctly), matching the documented `removeById()`
  never-reuse semantics. The two indexes split on purpose: WINDOW digests
  (`imageIdForDigest`/`trackedDigests`) describe what is placed NOW, so
  `release()`, `releaseAllExcept()` and `removeById()` forget the freed id's
  digests — otherwise a check-then-place viewport would trust a stale entry
  and skip the re-placement the terminal needs after a scroll-back.
- **[poster conveniences]** `Mosaic::poster()` / `posterAsync()` /
  `posterFile()` fetch-or-load → render at cell size → consult/populate a
  `DiskCache` keyed by `DiskCache::key($url,$w,$h,$protocol)` in ONE call,
  with the SSRF guards (`allowedHosts` seam) passed straight through to
  `ImageSource::fromUrl[Async]()`. Null `$cellH` keys on a `-1` sentinel
  (documented): the aspect-derived height is not stable across image swaps.
  Default scale for posters is `Scale::Fill` when the mosaic has no explicit
  scale — cover-cropping against CELL_ASPECT is what keeps portrait posters
  unsquashed (see the v6 FORMAT_VERSION note).
- **[fromModeString()]** `Mosaic::fromModeString(string $mode): ?self` parses
  `auto|sixel|kitty|iterm2|halfblock|half|ansi|quarterblock|quarter|ascii|
  ansi256|truecolor|chafa` (case-insensitive, trimmed; `ansi256`/`truecolor`
  select the AsciiRenderer tinted accordingly — the client config's mapping).
  Unknown → `null`, never throw, so callers phrase their own error.
- **[Builder no longer defaults to Sixel]** `MosaicBuilder::build()` with no
  renderer auto-detects via the same `Mosaic::auto()` path (and threads a
  builder dither into the auto-resolved SixelRenderer when detection lands
  there). The old silent-Sixel default emitted DCS payloads a non-Sixel
  terminal cannot display. Tests that depend on the detection outcome MUST
  clear the terminal env keys (see MosaicBuilderTest::setUp) — `Detect::probe()`
  reads live env and is NOT cached (`cached()` is the caching wrapper).
- **[auto() restructured]** `Mosaic::auto()` is now one path with two stages:
  `Detect::probe()` is authoritative when it identifies any graphics protocol;
  candy-palette's `TerminalProbe` runs ONLY as the explicit fallback when
  Detect found none, and its Kitty/ITerm2 hints feed the same
  `fromCapability()` selection (tmux wrap included) instead of a parallel
  hand-rolled construction. Precedence everywhere: kitty → iterm2 → sixel →
  chafa → halfblock. The old palette branches had a latent bug (passed
  `has(Color256)` as `Capability::kitty()`'s `$inTmux` arg) — gone with the
  unification.
- **[Sixel alpha is real]** `SixelRenderer::supportsAlpha()` is now `true` and
  the encoder emits DEC's `#0;2;P` transparent-background register when (and
  only when) the resized canvas contains fully-transparent (GD alpha 127)
  pixels — palette indices then shift to 1..N and hole pixels are simply never
  painted (and diffuse no dither error). Opaque images encode byte-for-byte
  exactly as before, so every pre-existing sixel snapshot stays valid. GD
  averages alpha during `imagecopyresampled`, so only exact-127 counts as a
  hole; blended edge pixels keep their colour deliberately.
