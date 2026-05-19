# CandyMosaic — Caliber Learnings

Accumulated patterns and gotchas specific to this library.

- **[ext-gd colorspace]** GD reads PNG as truecolor by default; palette
  PNGs need `imagepalettetotruecolor()` first. Handle in `PixelGrid::fromGd`.
- **[Sixel quantization]** Median-cut produces ≤256 colors per image.
  The protocol limit is 256; if the image has more colors the renderer
  still works but quality may be reduced.
- **[Terminal cell aspect]** Terminal cells are roughly 1:2 (wide:tall).
  The half-block renderer doubles vertical resolution → near-square
  pixels. Sixel/Kitty operate in pixel space; we set cell dimensions
  and the terminal handles scaling.
- **[Kitty chunk size]** Protocol specifies max 4096 bytes per chunk.
  Round down to 4092 to account for base64 padding overhead.
- **[Animated GIFs]** This lib handles static frames only. Animated
  GIF support lives in `candy-flip`, which calls `HalfBlockRenderer`
  per frame.
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
