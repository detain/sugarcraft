<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Picture;

/**
 * Inline-image escape protocol used by {@see Picture}. Each protocol
 * encodes pixel data into a different escape envelope:
 *
 * - **Sixel** (`ESC P q ... ESC \`) — DCS-wrapped 6-bit-stripe encoding.
 *   Broadest wire support (xterm — only the --enable-sixel build —
 *   mlterm, foot, Windows Terminal, iTerm2, WezTerm). Pure-PHP encoder
 *   ships in `Picture\Sixel`.
 * - **Kitty** (`ESC _ G ... ESC \`) — APC-wrapped base64-encoded PNG.
 *   `Picture` accepts PNG bytes already encoded by the caller
 *   (`fromPng`) — producing them typically needs `ext-gd` or similar
 *   on the caller's side; the lib itself encodes no raster.
 * - **iTerm2** (`OSC 1337 ; File=...`) — OSC-wrapped base64 PNG, same
 *   caller-supplied-PNG contract.
 *
 * Pick automatically at runtime via {@see Picture::detect()}. Its
 * auto-detected set is foot, mlterm, WezTerm, iTerm2, kitty and Windows
 * Terminal; every other terminal (xterm included) must be wired through
 * an explicit `Picture::withProtocol()` — and under a multiplexer the
 * auto arms stay silent unless CHARTS_PICTURE_PASSTHROUGH=1 opts in
 * (sugar-charts-012/-115).
 */
enum Protocol: string
{
    case Sixel  = 'sixel';
    case Kitty  = 'kitty';
    case ITerm2 = 'iterm2';
}
