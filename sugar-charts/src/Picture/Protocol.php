<?php

declare(strict_types=1);

namespace CandyCore\Charts\Picture;

/**
 * Inline-image escape protocol used by {@see Picture}. Each protocol
 * encodes pixel data into a different escape envelope:
 *
 * - **Sixel** (`ESC P q ... ESC \`) — DCS-wrapped 6-bit-stripe encoding.
 *   Most widely supported (xterm, mlterm, foot, Windows Terminal,
 *   iTerm2, WezTerm). Pure-PHP encoder ships in `Picture\Sixel`.
 * - **Kitty** (`ESC _ G ... ESC \`) — APC-wrapped base64-encoded PNG.
 *   Requires `ext-gd` to encode the PNG.
 * - **iTerm2** (`OSC 1337 ; File=...`) — OSC-wrapped base64 PNG.
 *   Requires `ext-gd`.
 *
 * Pick automatically at runtime via {@see Picture::detect()}.
 */
enum Protocol: string
{
    case Sixel  = 'sixel';
    case Kitty  = 'kitty';
    case ITerm2 = 'iterm2';
}
