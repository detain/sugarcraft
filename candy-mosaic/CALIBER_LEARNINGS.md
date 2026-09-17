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
  ~~candy-mosaic does **not** depend on candy-flip. A future GIF→Mosaic bridge
  (decoding GIF frames into `ImageSource[]`) lives in candy-flip if needed.~~
  **SUPERSEDED by #18:** `ImageSource::fromAnimatedFile()` now pulls candy-flip
  in as a `require`-only sibling dep to bridge GIF frames into `ImageSource[]`
  (no `repositories[]` in the lib manifest). See the #18 entry below for the
  frame-layout (offset-list) reconcile / cost-bound hardening and the blocking candy-flip
  multi-frame decode bug it works around.
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

_None outstanding — #17 and #18 landed this session; the audit notes below are
kept as design rationale for what shipped._

- **#17 Streaming sixel encode (IMPLEMENTED).** `SixelRenderer::encodeBandStream(
  ImageSource $image, int $width, callable $write, ?int $height = null): void`
  emits the DCS header + full-image median-cut palette in ONE write, then one
  write per 6-row band, then the ST terminator — so a consumer starts writing to
  the TTY while later bands quantize. `render()` is now a thin sink that collects
  the same callbacks and `implode`s them, so streaming and one-shot are provably
  byte-identical (proven against the pre-existing snapshot suite and a 2304-config
  differential harness vs. the old `render()`). Design constraints the audit
  flagged and how they were honoured: (a) the palette is computed from the FULL
  image before any band ships — a private `encodeInto()` decodes the source once,
  keeps ONE `GdImage` open, and feeds a lazy `indexRows()` row generator; (b) the
  generator is a ROLLING WINDOW (reach = 1 for Floyd–Steinberg, 2 for Stucki/
  Atkinson, 0 for None) so band N's downward/forward error diffusion lands on
  band N+1's still-unyielded rows — this replaces the old `buildIndexGrid` +
  `ditheredIndexGrid` full-grid materialisation and drops peak memory ~34MB→24MB
  on the 500×400 corpus while output stays identical; (c) the graphics-newline
  `-` PREFIXES every band after the first and is folded into the band body (never
  emitted as a lone write, never touches the header); (d) tmux passthrough DOES
  have a streaming branch — `TmuxPassthroughDecorator::encodeBandStream()` opens
  `\x1bPtmux;`, ESC-doubles each chunk as it streams, and closes `\x1b\\`,
  byte-equal to `wrap(render())` because a sixel payload is one DCS whose only
  ESC bytes are the introducer and the ST (all interior bytes are printable), so
   per-chunk doubling composes exactly; it throws `LogicException`
   (`tmux.stream_not_sixel`) if the inner renderer is not Sixel — the band stream is
   a sixel-only capability, so unlike the one-shot `render()` (which wraps any
   protocol's final buffer) the streaming path has no meaning for a non-Sixel inner
   and refuses rather than silently buffering.

- **#18 GIF/APNG multi-frame decode (IMPLEMENTED).** New
  `ImageSource::fromAnimatedFile(string $path, int $maxPixels = self::MAX_PIXELS): Animation`
  sniffs the container: GIF87a/89a → `SugarCraft\Flip\Decoder` (**added
  `sugarcraft/candy-flip` as a `require` line ONLY — no `repositories[]` in the
  committed manifest per the split-repo rule; CI injects the path-repo closure**);
  animated PNG (acTL before IDAT/IEND) → the new `@internal ApngDecoder` (pure-PHP
  fcTL/fdAT walk over the PNG container, zlib-inflate + per-row unfilter, a real
  dispose-op state machine — none keeps the canvas, background clears the frame
  rect, previous restores the pre-frame canvas — plus source/over blend and
   palette+tRNS expansion); a still PNG degrades to a single-frame `Animation`
   with delay `[0]` and a single-frame GIF returns one frame at its GCE delay;
   JPEG/WebP and any other container throw `animation.unsupported_format` (this
   entry point decodes only GIF/APNG animation — still PNG is the lone
   non-animated convenience); GIF frame delays are centiseconds in the GCE so
   they are ×10 to milliseconds. Audit constraint (c) honoured and HARDENED in
   review (PR #1442 round 1): `MAX_PIXELS` is checked PER FRAME
   (`image_source.too_large`) AND in AGGREGATE (`animation.too_many_pixels`), and
   the aggregate is computed from the frames the stream ACTUALLY carries — a
   metadata-only `collectFrames()` runs first and its count is reconciled against
   the `acTL` attestation (`apng.frame_count_mismatch`), because an attacker-authored
   `acTL` under-declaring the count would otherwise shrink the budget while the
   composite loop pays a full canvas per real frame; `Animation::MAX_FRAMES` is also
   enforced before compositing, not only in the constructor after the fact. The
   per-frame inflate is bounded with `gzuncompress($data, $expected)` and must land
   on `strlen() === $expected` (PNG scanline matrices are exact) so an oversized
   stream is refused rather than materialised. The loader caps the file READ at
   `ImageSource::MAX_BYTES` (a pixel budget cannot, because bytes must be resident
   before geometry is inspectable). **candy-flip GIF gotcha (blocking sibling
   finding, NOT fixable from candy-mosaic):** `Flip\Decoder::parseHeader` skips a
   frame's image data starting at the LZW min-code-size byte rather than the byte
   AFTER it (`$j = $i + 10` at `candy-flip/src/Decoder.php:371`, should be `+ 11`),
   so it desyncs on most real multi-frame GIFs and silently returns too few frames
   with shuffled delays — this is also why GIF test fixtures are generated from GD
   `imagegif()` per frame (shared 4-slot GCT, `imagecreate` reserves index 0 as
   black) rather than hand-rolled LZW (see `tests/Support/AnimatedImageFixtures.php`).
      mosaic walks GIF image descriptors TWICE with byte-only structural passes (no
      LZW decode), each returning the ORDERED LIST of descriptor byte offsets:
      `ImageSource::gifHonestDescriptorOffsets()` — the spec-correct walk (skips the
      LCT AND the mandatory LZW min-code-size byte, throws on a truncated or unknown
      block) — and `ImageSource::gifFlipWalkDescriptorOffsets()`, a byte-exact clone of
      flip's own walk (same LZW mis-skip, same one-byte advance on an unknown block,
      same 256-frame slice). `countGifFrames`/`countGifFramesAsFlipWalks` are now thin
      `count()` wrappers kept only for tests that assert the count relationship; the
      production loader reconciles the OFFSET LISTS. The clone records every descriptor
      it reaches UNCONDITIONALLY at a `0x2C` (matching flip, which reads the packed byte
      with `?? ''` and records even a nine-byte truncated tail) — an earlier clone gated
      on `i+9` and thus under-counted (round-4 MED). **The authoritative gate is ORDERED
      OFFSET-LIST EQUALITY run BEFORE flip (round-4 CRITICAL-2)**, not a count compare:
      `$honestOffsets !== $flipOffsets` → `animation.gif_frame_layout_mismatch`. A phantom
      descriptor forged into a GCE region can preserve the frame COUNT while replacing a
      real frame's bytes (honest `{33,68,91}` vs flip `{33,56,91}`), which a count-only
      check would wave through and emit as authentic; equality refuses it. This SUPERSEDES
      the round-3 stance (count-only reconcile, clone as a loose `[real, real+1]` upper
      bound): demanding equality of the full OFFSET list, not just the count, is sound
      precisely because flip is only trustworthy when it lands on the exact positions a
      correct walk does. When the lists DO agree, `$declared` is simultaneously the honest
      count and flip's materialisation count, so the aggregate cost budget is EXACT, not a
      bound — and the ceiling itself (not a mis-estimate) is what stops the cell-array
      bomb. flip stores one PHP array per cell (~250 bytes, far heavier than a packed RGBA
      buffer), so `MAX_PIXELS` is NOT a memory bound for it; `FLIP_MAX_TOTAL_CELLS` caps
      the aggregate grid at ~100 MB and was recalibrated from 6 M down to 400 k cells in
      round-4 (CRITICAL-1: a legitimately-structured 60-frame 316×316 GIF — 5.99 M cells —
      materialised 1.5 GB / 20 s under the old ceiling). `FLIP_MAX_CELLS` (flip's 100k
      per-frame grid ceiling) is still checked up front so a ~316×316+ GIF gets a mosaic
      message, not flip's untranslated exception. **Decode runs over the ALREADY-VALIDATED
      bytes, never a second open of `$path`** (round-4 HIGH): the logical screen size comes
      from `unpack('vwidth/vheight', substr($bytes, 6, 4))` (not `getimagesize($path)`), and
      flip — whose `Decoder::decode()` still insists on a path and does its own
      `file_get_contents()` — is handed a private, `0600`, unlink-on-exit temp file of those
      bytes via `decodeGifFramesFromBytesSafely()`, so a post-read swap of the original
      path cannot be re-followed to bypass `MAX_BYTES`. That wrapper also arms a throwing
      error handler (flip emits raw GD warnings on corrupt GIFs) and maps any failure to one
      translated `animation.gif_decode_failed`. A post-decode `$declared !== count($flipFrames)`
      reconcile is retained as defence in depth (`animation.gif_frame_count_mismatch`,
      round-1 C2), though equality makes it unreachable in practice.
      **Net product consequence (blocking sibling finding):** because candy-flip silently
      mis-decodes most real multi-frame GIFs (an ffmpeg 10-frame GIF arrives as ~2), and
      mosaic now refuses whenever flip's walk diverges from the honest one, the animated-GIF
      path is **safe-but-inert** for real-world multi-frame GIFs; full support needs the
      one-byte candy-flip header-walk fix, which is out of this lib's authority. The APNG
      path is fully functional (pure-PHP decode, no sibling dependency).

    **Sixel DCS-state safety (rounds 1-2):** a sixel payload is exactly one DCS, so a
    half-written stream (a consumer `$write` throwing on a closed pipe, or a GD load
    error mid-band) strands the terminal inside DECGXL and swallows later output;
    `encodeInto()` emits the string terminator from a `finally` when the header reached
    the wire but the terminator did not. The "header delivered" flag is set BEFORE the
    `$write($head)` call, not after — a pty consumer that flushes bytes then dies on the
    FIRST write still triggers the guaranteed terminator (round-2 NEW-3; an orphan ST is
    inert, an open DCS is not). `TmuxPassthroughDecorator::encodeBandStream()` opens the
        `\x1bPtmux;` envelope LAZILY on the first real chunk (early inner failure writes
    nothing) with the same open-before-deliver ordering, and closes it in a `finally`.
    Both keep byte-identity with `render()` on the happy path. `readBoundedRegularFile()`
    stats the PATH first (stat never opens, so it cannot block) and demands `S_IFREG`
    BEFORE `fopen` — round-3 (R3-2) caught that the round-2 version opened the descriptor
    first and only then checked its type, which re-introduced the very FIFO hazard it
    closed: `fopen()` on a writer-less FIFO blocks forever, so a plain fifo path hung
    instead of refusing in microseconds (round-1's `is_file()` had rejected it without
    ever opening). It then re-checks `S_IFREG` on the SAME descriptor it reads to defeat
    the size-based swap (to `/dev/zero`/an endless special file) before a byte is slurped,
    capped at `MAX_BYTES` (round-2 TOCTOU finding); `ApngDecoder::decode()` verifies the
 8-byte signature and
    a hard `HARD_MAX_DIM` ceiling so a direct call cannot decode a non-PNG blob or reach
    `str_repeat()` with a float operand when the pixel budget is disabled.
   `DiskCache::FORMAT_VERSION` was NOT bumped: #17 is byte-identical on every existing
   render path and #18 is purely additive (a new method + a new class + fail-fast
   guards that only turn previously-silent corruption into loud errors), so no
   already-cached bytes change meaning.

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
- **[Sixel alpha perf note]** The transparency probe (`hasTransparentPixels`)
  is a full `imagecolorat` sweep of the pixel canvas on every render — cheap
  per pixel but the hot sixel path (video frames) pays one extra
  O(pixelW·pixelH) pass even for opaque sources, where it can never
  short-circuit. Deliberate: the background register must be decided before
  median-cut, so folding the probe into the sampling/grid passes cannot
  remove the dependency, and gating on container alpha is blind here because
  GD re-encodes every decoded source as colour-type-6 PNG. The streaming encode
  (#17, now implemented) did NOT eliminate it: the background register and the
  median-cut palette are both header decisions that must be computed from the
  FULL image before the first band ships, so `hasTransparentPixels` stays a
  whole-canvas pre-pass in `encodeInto()`. Band-local hole discovery would
  require deferring the header, which breaks the byte-identical-with-one-shot
  guarantee that #17 is built on.
