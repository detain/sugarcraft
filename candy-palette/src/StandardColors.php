<?php

declare(strict_types=1);

namespace SugarCraft\Palette;

/**
 * Standard 16-color ANSI palette as named {@see Color} objects.
 *
 * Mirrors the standard ANSI 16-color palette from the Go colorprofile
 * package. Use these as readable shortcuts in place of `Color::ansi(N)`.
 *
 * @see https://en.wikipedia.org/wiki/ANSI_escape_code#3/4-bit
 */
final class StandardColors
{
    // --- Basic colors (0–7) ----------------------------------------------------

    public static Color $black;
    public static Color $red;
    public static Color $green;
    public static Color $yellow;
    public static Color $blue;
    public static Color $magenta;
    public static Color $cyan;
    public static Color $white;

    // --- Bright colors (8–15) --------------------------------------------------

    public static Color $brightBlack;   // grey
    public static Color $brightRed;
    public static Color $brightGreen;
    public static Color $brightYellow;
    public static Color $brightBlue;
    public static Color $brightMagenta;
    public static Color $brightCyan;
    public static Color $brightWhite;

    /**
     * All 16 colors as an indexed array.
     *
     * @return array<int, Color>
     */
    public static function all(): array
    {
        return [
            self::$black,
            self::$red,
            self::$green,
            self::$yellow,
            self::$blue,
            self::$magenta,
            self::$cyan,
            self::$white,
            self::$brightBlack,
            self::$brightRed,
            self::$brightGreen,
            self::$brightYellow,
            self::$brightBlue,
            self::$brightMagenta,
            self::$brightCyan,
            self::$brightWhite,
        ];
    }

    /**
     * Get a color by ANSI 16-color index (0–15).
     *
     * @throws \OutOfBoundsException if $index is outside 0..15
     */
    public static function fromIndex(int $index): Color
    {
        $all = self::all();
        if (!isset($all[$index])) {
            throw new \OutOfBoundsException("ANSI 16-color index must be 0–15, got {$index}");
        }
        return $all[$index];
    }
}

// Static initialization (PHP < 8.3 lazy enum support)
StandardColors::$black       = new Color(0x00, 0x00, 0x00);
StandardColors::$red         = new Color(0xcd, 0x00, 0x00);
StandardColors::$green       = new Color(0x00, 0xcd, 0x00);
StandardColors::$yellow      = new Color(0xcd, 0xcd, 0x00);
StandardColors::$blue        = new Color(0x00, 0x00, 0xcd);
StandardColors::$magenta     = new Color(0xcd, 0x00, 0xcd);
StandardColors::$cyan        = new Color(0x00, 0xcd, 0xcd);
StandardColors::$white       = new Color(0xe5, 0xe5, 0xe5);
StandardColors::$brightBlack = new Color(0x7f, 0x7f, 0x7f);
StandardColors::$brightRed   = new Color(0xff, 0x00, 0x00);
StandardColors::$brightGreen = new Color(0x00, 0xff, 0x00);
StandardColors::$brightYellow= new Color(0xff, 0xff, 0x00);
StandardColors::$brightBlue  = new Color(0x00, 0x00, 0xff);
StandardColors::$brightMagenta=new Color(0xff, 0x00, 0xff);
StandardColors::$brightCyan  = new Color(0x00, 0xff, 0xff);
StandardColors::$brightWhite = new Color(0xff, 0xff, 0xff);
