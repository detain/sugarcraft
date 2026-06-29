<?php

declare(strict_types=1);

namespace SugarCraft\Palette;

/**
 * Terminal color profile — the level of color support the terminal provides.
 *
 * Ordered from richest to simplest so that {@see Profile::degradedTo()} can walk
 * the chain downwards.
 *
 * @see https://github.com/charmbracelet/colorprofile
 */
enum Profile: string
{
    /** Full 24-bit TrueColor (16.7 million colors). */
    case TrueColor = 'truecolor';

    /** 256-color ANSI (216 cube + 24 greys + 16 standard). */
    case ANSI256 = 'ansi256';

    /** Classic 16-color ANSI. */
    case ANSI = 'ansi';

    /** Two-color (black/white or bold/bold-off). */
    case Ascii = 'ascii';

    /** No TTY — ANSI sequences should be stripped entirely. */
    case NoTTY = 'notty';

    /**
     * Bridge from the {@see ColorProfile} enum.
     */
    public static function fromColorProfile(ColorProfile $p): self
    {
        return match ($p) {
            ColorProfile::TrueColor => self::TrueColor,
            ColorProfile::Ansi256   => self::ANSI256,
            ColorProfile::Ansi     => self::ANSI,
            ColorProfile::Ascii    => self::Ascii,
            ColorProfile::NoTTY    => self::NoTTY,
        };
    }

    /**
     * Human-readable name for the profile.
     */
    public function label(): string
    {
        return match ($this) {
            self::TrueColor => 'TrueColor',
            self::ANSI256   => 'ANSI 256',
            self::ANSI      => 'ANSI',
            self::Ascii     => 'ASCII',
            self::NoTTY     => 'No TTY',
        };
    }

    /**
     * Short description for display.
     */
    public function description(): string
    {
        return match ($this) {
            self::TrueColor => '24-bit full color',
            self::ANSI256   => '256-color palette',
            self::ANSI      => '16-color standard',
            self::Ascii     => 'black & white',
            self::NoTTY     => 'colors disabled',
        };
    }

    /**
     * Maximum colors this profile supports.
     */
    public function maxColors(): int
    {
        return match ($this) {
            self::TrueColor => 16_777_216,
            self::ANSI256   => 256,
            self::ANSI      => 16,
            self::Ascii     => 2,
            self::NoTTY     => 0,
        };
    }

    /**
     * Walk the profile downgrade chain to the next simpler profile.
     * Returns null when already at the simplest (NoTTY).
     */
    public function degradedTo(): ?self
    {
        return match ($this) {
            self::TrueColor => self::ANSI256,
            self::ANSI256   => self::ANSI,
            self::ANSI      => self::Ascii,
            self::Ascii     => self::NoTTY,
            self::NoTTY     => null,
        };
    }
}
