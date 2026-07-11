# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:gce-delay-centiseconds]** GIF89a Graphic Control Extension (GCE) delay is stored as a 16-bit little-endian centisecond value (1/100 s). A delay of `0` means "no delay specified" — decoder should carry forward the last-seen non-zero delay rather than treating it as zero. Default fallback is `10` (100 ms) per GIF spec. When reassembling a single-frame GIF payload for `imagecreatefromstring()`, include a GCE block with the correct delay so timing metadata survives the round-trip.

- **[pattern:gif-reassembly-in-memory]** `imagecreatefromstring()` accepts any GIF87a/89a single-frame payload — including one assembled by concatenating slices of the original file. The canonical pattern: take the original header (13 bytes) + GCT (if present), append a fresh GCE block for the per-frame delay, then copy the frame's image descriptor + LZW sub-block chain, then the trailer (`0x3B`). No temporary files needed. Sub-block parsing walks `subLen` bytes + 1 for the length prefix until a `0x00` terminator is found — the terminator itself is not included in the slice.

- **[pattern:lzw-sub-block-walk]** GIF LZW image data is stored as a series of sub-blocks: each sub-block starts with a length byte (0x00–0xFF) followed by that many data bytes. A `0x00` length byte terminates the image data. When skipping over image data during parsing, advance past each sub-block's length byte and then past its payload; stop when the length byte is `0x00`. Do NOT treat the length byte as data — it is a prefix that must be consumed before the next sub-block or terminator.

- **[pattern:area-average-downsample]** When downsampling a source image to a target cell grid, use area averaging: for each target cell with integer bounds `(x..x+cw, y..y+ch)`, sum all source pixels in that region (skip transparent pixels via alpha < 128) and divide by the sum of non-transparent weights. Clamp bounds to source image dimensions to handle edge cells cleanly. Prefer area average over nearest-neighbor to avoid aliasing artifacts.

- **[pattern:local-color-table-per-frame]** GIF89a per-frame local color table (LCT) is stored in the Image Descriptor block that precedes each frame's LZW data. The `hasLct` flag in the Image Descriptor indicates whether a local color table follows; if set, the LCT size (3 × 2^bits) bytes follows immediately. When reassembling single-frame payloads for `imagecreatefromstring()`, copy the frame's LCT and restore it in the same position.

- **[pattern:gce-transparency-disposal]** GIF89a GCE block carries a transparent-color index flag (bit 7 of disposal method byte). The disposal method constants are: 0=none, 1=keep, 2=restore-bg, 3=restore-prev. When rendering a cell whose pixel is the transparent index, emit null rather than a Cell with the transparent color — the disposal pass (rendered after each frame) handles restoring the background or previous content.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.

- **[pattern:snapshot-testing-assert-golden]** Use `candy-testing`'s `assertGolden*` for any renderable/serializable output — ANSI cell renders via `assertGoldenAnsi`, numeric data via `assertGolden` file equality. This pins canonical output so refactors are intentional rather than accidental regressions.
