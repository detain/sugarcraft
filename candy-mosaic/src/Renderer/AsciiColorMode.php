<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Renderer;

/**
 * Colour treatment for the {@see AsciiRenderer}: each pixel becomes a
 * luminance character, optionally tinted.
 */
enum AsciiColorMode: string
{
    /** Plain character ramp, no colour — classic monochrome ASCII art. */
    case Mono = 'ascii';

    /** Character ramp with a 256-colour (`38;5;N`) foreground. */
    case Ansi256 = 'ansi256';

    /** Character ramp with a 24-bit truecolour (`38;2;R;G;B`) foreground. */
    case TrueColor = 'truecolor';
}
