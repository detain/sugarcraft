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
