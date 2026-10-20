<?php

declare(strict_types=1);

namespace SugarCraft\Image\Symbol;

/**
 * Coverage-class symbol tags, bit-for-bit mirrors of chafa's
 * ChafaSymbolTag enum. Tags classify a glyph's visual family; the
 * selection engine itself never filters on them (v1 parity — chafa
 * filters at the CONFIG level, see the auto-ladder seam), they ride
 * the SymbolDef for callers that want to restrict a pick to, say,
 * BRAILLE or STIPPLE families.
 *
 * Mirrors chafa.ChafaSymbolTag (chafa/chafa-symbol-map.h:33-68).
 */
final class SymbolTags
{
    public const NONE = 0;
    public const SPACE = 1 << 0;
    public const SOLID = 1 << 1;
    public const STIPPLE = 1 << 2;
    public const BLOCK = 1 << 3;
    public const BORDER = 1 << 4;
    public const DIAGONAL = 1 << 5;
    public const DOT = 1 << 6;
    public const QUAD = 1 << 7;
    public const HHALF = 1 << 8;
    public const VHALF = 1 << 9;
    public const HALF = self::HHALF | self::VHALF;
    public const INVERTED = 1 << 10;
    public const BRAILLE = 1 << 11;
    public const TECHNICAL = 1 << 12;
    public const GEOMETRIC = 1 << 13;
    public const ASCII = 1 << 14;
    public const ALPHA = 1 << 15;
    public const DIGIT = 1 << 16;
    public const ALNUM = self::ALPHA | self::DIGIT;
    public const NARROW = 1 << 17;
    public const WIDE = 1 << 18;
    public const AMBIGUOUS = 1 << 19;
    public const UGLY = 1 << 20;
    public const LEGACY = 1 << 21;
    public const SEXTANT = 1 << 22;
    public const WEDGE = 1 << 23;
    public const LATIN = 1 << 24;
    public const IMPORTED = 1 << 25;
    public const OCTANT = 1 << 26;
    public const EXTRA = 1 << 30;
    public const BAD = self::AMBIGUOUS | self::UGLY;

    /**
     * chafa's `--symbols=` class names (chafa-symbol-map.c parse_symbol_tag).
     * `all` is every tag except EXTRA and BAD; `none` selects nothing.
     *
     * @var array<string, int>
     */
    private const SELECTOR_NAMES = [
        'all' => ~(self::EXTRA | self::BAD),
        'none' => self::NONE,
        'space' => self::SPACE,
        'solid' => self::SOLID,
        'stipple' => self::STIPPLE,
        'block' => self::BLOCK,
        'border' => self::BORDER,
        'diagonal' => self::DIAGONAL,
        'dot' => self::DOT,
        'quad' => self::QUAD,
        'half' => self::HALF,
        'hhalf' => self::HHALF,
        'vhalf' => self::VHALF,
        'inverted' => self::INVERTED,
        'braille' => self::BRAILLE,
        'sextant' => self::SEXTANT,
        'wedge' => self::WEDGE,
        'technical' => self::TECHNICAL,
        'geometric' => self::GEOMETRIC,
        'ascii' => self::ASCII,
        'alpha' => self::ALPHA,
        'digit' => self::DIGIT,
        'narrow' => self::NARROW,
        'wide' => self::WIDE,
        'ambiguous' => self::AMBIGUOUS,
        'ugly' => self::UGLY,
        'extra' => self::EXTRA,
        'alnum' => self::ALNUM,
        'bad' => self::BAD,
        'legacy' => self::LEGACY,
        'latin' => self::LATIN,
        'import' => self::IMPORTED,
        'imported' => self::IMPORTED,
        'octant' => self::OCTANT,
    ];

    /** Tag mask for a `--symbols=` class name, or null when unknown. */
    public static function selectorMask(string $name): ?int
    {
        return self::SELECTOR_NAMES[strtolower($name)] ?? null;
    }

    private function __construct()
    {
    }
}
