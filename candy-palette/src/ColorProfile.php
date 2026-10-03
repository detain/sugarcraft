<?php

declare(strict_types=1);

namespace SugarCraft\Palette;

/**
 * Terminal color capability profile detected from environment variables.
 *
 * Ordered from richest to simplest (TrueColor → ANSI256 → ANSI → ASCII → NoTTY).
 *
 * This enum is the detection-chain vocabulary consumed by Probe, AsyncProbe,
 * DetectionChain and most sibling libraries. {@see Profile} is the renderer-
 * facing vocabulary used by Palette and ProfileWriter; the two carry the same
 * five levels under different case spellings (Ansi256 vs ANSI256). PHP enums
 * cannot alias cases, so consolidating them is a multi-library BREAKING
 * rename deliberately deferred to the findings#1 API-planning pass rather
 * than being smuggled into a fix commit.
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

    /**
     * Whether this profile permits ANSI color rendering.
     */
    public function allowsColor(): bool
    {
        return $this !== self::NoTTY;
    }
}
