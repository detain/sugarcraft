<?php

declare(strict_types=1);

namespace SugarCraft\Palette;

/**
 * Terminal color capability profile detected from environment variables.
 *
 * Mirrors the research-driven detection hierarchy from H1-H5 + M1-M3.
 * Ordered from richest to simplest — walking degradedTo() walks downward.
 */
enum ColorProfile: string
{
    /** No TTY connected — all ANSI sequences must be stripped. */
    case NoTTY = 'notty';

    /** Two-color black/white mode. */
    case Ascii = 'ascii';

    /** Classic 16-color ANSI. */
    case Ansi = 'ansi';

    /** 256-color ANSI (216 cube + 24 greyscale + 16 standard). */
    case Ansi256 = 'ansi256';

    /** Full 24-bit TrueColor (16.7 million colors). */
    case TrueColor = 'truecolor';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::NoTTY     => 'No TTY',
            self::Ascii     => 'ASCII',
            self::Ansi      => 'ANSI',
            self::Ansi256  => 'ANSI 256',
            self::TrueColor => 'TrueColor',
        };
    }
}
