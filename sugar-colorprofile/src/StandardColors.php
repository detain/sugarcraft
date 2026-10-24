<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile;

use SugarCraft\ColorProfile\Lang;

/**
 * Standard 16-color ANSI palette as named {@see Color} objects.
 *
 * Mirrors the standard ANSI 16-color palette from the Go colorprofile
 * package. Use these as readable shortcuts in place of `Color::ansi(N)`.
 *
 * CAUTION: these are public MUTABLE statics — an assignment anywhere in the
 * process rewrites the value for every reader, and nothing pins them against
 * reassignment. Prefer the pure factories (Color::ansi(3), Color::true(0xff, 0, 0))
 * in new code.
 *
 * DEPRECATION WINDOW (opened 2026-10-24, owner ruling 2b): every writable
 * public static property below is @deprecated. They keep working
 * byte-identically — reads and writes are untouched — and are replaced, no
 * earlier than 2.0.0 (tagged REMOVED-IN: 2.0.0), by an immutable,
 * constant-backed equivalent. The blessed NON-MUTATING read surface is
 * already here and is NOT deprecated: all() (indexed 0–15),
 * fromIndex(int) (bounds-checked, throws OutOfBoundsException), and
 * catalog() (the ordered name list). New code that needs a named standard
 * color as a value constructs it from those (or from Color::ansi(N)); code
 * that needs a private, non-global palette table builds its own array from
 * all() — the values are immutable Color objects, so a local array gives
 * isolation without a second shared class.
 *
 * @see https://en.wikipedia.org/wiki/ANSI_escape_code#3/4-bit
 */
final class StandardColors
{
    /**
     * Process-global mutable palette slot — writing it rewrites the value for
     * every reader in the process, with nothing pinning it against
     * reassignment. Read the blessed non-mutating surface instead:
     * all()/fromIndex(0)/catalog()[0].
     *
     * @deprecated Mutable process-global static; kept byte-identical for BC,
     *             removed in favor of an immutable constant-backed equivalent.
     *             REMOVED-IN: 2.0.0
     */
    public static Color $black;
    /** @deprecated Mutable process-global static; read fromIndex(1)/all()[1] instead. REMOVED-IN: 2.0.0 */
    public static Color $red;
    /** @deprecated Mutable process-global static; read fromIndex(2)/all()[2] instead. REMOVED-IN: 2.0.0 */
    public static Color $green;
    /** @deprecated Mutable process-global static; read fromIndex(3)/all()[3] instead. REMOVED-IN: 2.0.0 */
    public static Color $yellow;
    /** @deprecated Mutable process-global static; read fromIndex(4)/all()[4] instead. REMOVED-IN: 2.0.0 */
    public static Color $blue;
    /** @deprecated Mutable process-global static; read fromIndex(5)/all()[5] instead. REMOVED-IN: 2.0.0 */
    public static Color $magenta;
    /** @deprecated Mutable process-global static; read fromIndex(6)/all()[6] instead. REMOVED-IN: 2.0.0 */
    public static Color $cyan;
    /** @deprecated Mutable process-global static; read fromIndex(7)/all()[7] instead. REMOVED-IN: 2.0.0 */
    public static Color $white;

    /** @deprecated Mutable process-global static; read fromIndex(8)/all()[8] instead. REMOVED-IN: 2.0.0 */
    public static Color $brightBlack;   // grey
    /** @deprecated Mutable process-global static; read fromIndex(9)/all()[9] instead. REMOVED-IN: 2.0.0 */
    public static Color $brightRed;
    /** @deprecated Mutable process-global static; read fromIndex(10)/all()[10] instead. REMOVED-IN: 2.0.0 */
    public static Color $brightGreen;
    /** @deprecated Mutable process-global static; read fromIndex(11)/all()[11] instead. REMOVED-IN: 2.0.0 */
    public static Color $brightYellow;
    /** @deprecated Mutable process-global static; read fromIndex(12)/all()[12] instead. REMOVED-IN: 2.0.0 */
    public static Color $brightBlue;
    /** @deprecated Mutable process-global static; read fromIndex(13)/all()[13] instead. REMOVED-IN: 2.0.0 */
    public static Color $brightMagenta;
    /** @deprecated Mutable process-global static; read fromIndex(14)/all()[14] instead. REMOVED-IN: 2.0.0 */
    public static Color $brightCyan;
    /** @deprecated Mutable process-global static; read fromIndex(15)/all()[15] instead. REMOVED-IN: 2.0.0 */
    public static Color $brightWhite;

    /** @var array<int, Color>|null Cached return value of all() */
    private static ?array $cachedAll = null;

    /**
     * All 16 colors as an indexed array.
     *
     * Returns the same array instance on repeated calls (static caching).
     *
     * @return array<int, Color>
     */
    public static function all(): array
    {
        if (self::$cachedAll === null) {
            self::$cachedAll = [
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
        return self::$cachedAll;
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
            throw new \OutOfBoundsException(Lang::t('standard.ansi16_out_of_range', ['index' => $index]));
        }
        return $all[$index];
    }

    /**
     * Returns a list of all available standard color names.
     *
     * @return list<string>
     */
    public static function catalog(): array
    {
        return ['black', 'red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'white', 'brightBlack', 'brightRed', 'brightGreen', 'brightYellow', 'brightBlue', 'brightMagenta', 'brightCyan', 'brightWhite'];
    }
}

// Static initialization — typed statics have no default, so the objects
// are seeded eagerly at file load (first possible use).
StandardColors::$black       = new Color(0x00, 0x00, 0x00);
StandardColors::$red         = new Color(0xcd, 0x00, 0x00);
StandardColors::$green       = new Color(0x00, 0xcd, 0x00);
StandardColors::$yellow      = new Color(0xcd, 0xcd, 0x00);
StandardColors::$blue        = new Color(0x00, 0x00, 0xee);
StandardColors::$magenta     = new Color(0xcd, 0x00, 0xcd);
StandardColors::$cyan        = new Color(0x00, 0xcd, 0xcd);
StandardColors::$white       = new Color(0xe5, 0xe5, 0xe5);
StandardColors::$brightBlack = new Color(0x7f, 0x7f, 0x7f);
StandardColors::$brightRed   = new Color(0xff, 0x00, 0x00);
StandardColors::$brightGreen = new Color(0x00, 0xff, 0x00);
StandardColors::$brightYellow = new Color(0xff, 0xff, 0x00);
StandardColors::$brightBlue  = new Color(0x5c, 0x5c, 0xff);
StandardColors::$brightMagenta = new Color(0xff, 0x00, 0xff);
StandardColors::$brightCyan  = new Color(0x00, 0xff, 0xff);
StandardColors::$brightWhite = new Color(0xff, 0xff, 0xff);
