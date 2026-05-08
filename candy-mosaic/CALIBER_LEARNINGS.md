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
