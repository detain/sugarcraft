<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Buffer;

use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Style as BufferStyle;
use SugarCraft\Sprinkles\Style as SprinklesStyle;
use SugarCraft\Core\Util\Width;

/**
 * Helpers for building Buffer-backed chart renderers.
 *
 * Mirrors charmbracelet/lipgloss Buffer rendering pipeline.
 */
final class BufferHelper
{
    /**
     * Compute the display width of a single grapheme cluster in terminal cells.
     * Wide East-Asian chars and emoji count as 2; ASCII printable counts as 1.
     */
    public static function graphemeWidth(string $cluster): int
    {
        if ($cluster === '') {
            return 0;
        }
        $cp = self::firstCodepoint($cluster);
        if ($cp === 0) {
            return 0;
        }
        if (self::isZeroWidth($cp)) {
            return 0;
        }
        if (self::isWide($cp)) {
            return 2;
        }
        return 1;
    }

    /**
     * Place a string into a Buffer row starting at column $x, returning
     * the modified grid. Handles wide characters by creating continuation
     * cells for each wide char.
     *
     * @param list<Cell> $grid
     * @return list<Cell> Modified grid with cells placed
     */
    public static function placeString(array $grid, int $width, int $height, int $x, int $y, string $s, ?BufferStyle $style = null): array
    {
        $clusters = function_exists('grapheme_str_split')
            ? (grapheme_str_split($s) ?: mb_str_split($s, 1, 'UTF-8'))
            : mb_str_split($s, 1, 'UTF-8');

        $col = $x;
        foreach ($clusters as $cluster) {
            $gw = self::graphemeWidth($cluster);
            if ($col >= $width) {
                break;
            }
            $grid[$y * $width + $col] = new Cell($cluster, $style, null, $gw);
            // For wide chars (width 2), add a continuation cell
            if ($gw === 2) {
                $nextCol = $col + 1;
                if ($nextCol < $width) {
                    $grid[$y * $width + $nextCol] = Cell::continuation();
                }
            }
            $col += $gw;
        }
        return $grid;
    }

    /**
     * Convert a Sprinkles\Style to a Buffer\Style.
     * Only fg/bg/attrs are transferred; advanced features (borders, padding, etc.) are dropped.
     */
    public static function toBufferStyle(SprinklesStyle $s): BufferStyle
    {
        $attrs = 0;
        if ($s->isBold())          { $attrs |= BufferStyle::ATTR_BOLD; }
        if ($s->isItalic())        { $attrs |= BufferStyle::ATTR_ITALIC; }
        if ($s->isUnderline())     { $attrs |= BufferStyle::ATTR_UNDERLINE; }
        if ($s->isStrikethrough()) { $attrs |= BufferStyle::ATTR_STRIKE; }
        if ($s->isFaint())         { $attrs |= BufferStyle::ATTR_FAINT; }
        if ($s->isBlink())         { $attrs |= BufferStyle::ATTR_BLINK; }
        if ($s->isReverse())       { $attrs |= BufferStyle::ATTR_REVERSE; }
        if ($s->isOverline())     { $attrs |= BufferStyle::ATTR_OVERLINE; }
        if ($s->isInvisible())    { $attrs |= BufferStyle::ATTR_INVISIBLE; }

        $fgInt = null;
        if ($s->getForeground() !== null) {
            $fgInt = self::colorToInt($s->getForeground());
        }

        $bgInt = null;
        if ($s->getBackground() !== null) {
            $bgInt = self::colorToInt($s->getBackground());
        }

        return BufferStyle::new($fgInt, $bgInt, $attrs);
    }

    private static function colorToInt(object $color): int
    {
        return ($color->r << 16) | ($color->g << 8) | $color->b;
    }

    private static function firstCodepoint(string $g): int
    {
        if (function_exists('mb_ord')) {
            /** @var int|false $cp */
            $cp = mb_ord($g, 'UTF-8');
            return $cp === false ? 0 : $cp;
        }
        $b1 = ord($g[0]);
        if ($b1 < 0x80) {
            return $b1;
        }
        if (($b1 & 0xe0) === 0xc0 && strlen($g) >= 2) {
            return (($b1 & 0x1f) << 6) | (ord($g[1]) & 0x3f);
        }
        if (($b1 & 0xf0) === 0xe0 && strlen($g) >= 3) {
            return (($b1 & 0x0f) << 12) | ((ord($g[1]) & 0x3f) << 6) | (ord($g[2]) & 0x3f);
        }
        if (($b1 & 0xf8) === 0xf0 && strlen($g) >= 4) {
            return (($b1 & 0x07) << 18) | ((ord($g[1]) & 0x3f) << 12)
                 | ((ord($g[2]) & 0x3f) << 6) | (ord($g[3]) & 0x3f);
        }
        return 0;
    }

    private static function isZeroWidth(int $cp): bool
    {
        return ($cp >= 0x0000 && $cp <= 0x001f && $cp !== 0x0009 && $cp !== 0x000a && $cp !== 0x000d)
            || in_array($cp, [
                0x200b, 0x200c, 0x200d, 0x2060, 0xfeff,
                0x0300, 0x0301, 0x0302, 0x0303, 0x0304,
                0x0305, 0x0306, 0x0307, 0x0308, 0x0309,
                0x030a, 0x030b, 0x030c, 0x030d, 0x030e,
                0x030f, 0x0310, 0x0311, 0x0312, 0x0313,
                0x0314, 0x0315, 0x0316, 0x0317, 0x0318,
                0x0319, 0x031a, 0x031b, 0x031c, 0x031d,
                0x031e, 0x031f, 0x0320, 0x0321, 0x0322,
                0x0323, 0x0324, 0x0325, 0x0326, 0x0327,
                0x0328, 0x0329, 0x032a, 0x032b, 0x032c,
                0x032d, 0x032e, 0x032f, 0x0330, 0x0331,
                0x0332, 0x0333, 0x0334, 0x0335, 0x0336,
                0x0337, 0x0338, 0x0339, 0x033a, 0x033b,
                0x033c, 0x033d, 0x033e, 0x033f, 0x0340,
                0x0341, 0x0342, 0x0343, 0x0344, 0x0345,
                0x0346, 0x0347, 0x0348, 0x0349, 0x034a,
                0x034b, 0x034c, 0x034d, 0x034e, 0x034f,
                0x0350, 0x0351, 0x0352, 0x0353, 0x0354,
                0x0355, 0x0356, 0x0357, 0x0358, 0x0359,
                0x035a, 0x035b, 0x035c, 0x035d, 0x035e,
                0x035f, 0x0360, 0x0361, 0x0362, 0x0363,
                0x0364, 0x0365, 0x0366, 0x0367, 0x0368,
                0x0369, 0x036a, 0x036b, 0x036c, 0x036d,
                0x036e, 0x036f, 0x0483, 0x0484, 0x0485,
                0x0486, 0x0487, 0x0488, 0x0489,
            ], true);
    }

    private static function isWide(int $cp): bool
    {
        return ($cp >= 0x1100 && $cp <= 0x115f)
            || $cp === 0x2329 || $cp === 0x232a
            || ($cp >= 0x2e80 && $cp <= 0x303e)
            || ($cp >= 0x3040 && $cp <= 0xa4cf && !($cp >= 0x303f && $cp <= 0x3040))
            || ($cp >= 0xac00 && $cp <= 0xd7a3)
            || ($cp >= 0xf900 && $cp <= 0xfaff)
            || ($cp >= 0xfe10 && $cp <= 0xfe1f)
            || ($cp >= 0xfe30 && $cp <= 0xfe6f)
            || ($cp >= 0xff00 && $cp <= 0xff60)
            || ($cp >= 0xffe0 && $cp <= 0xffe6)
            || ($cp >= 0x20000 && $cp <= 0x2fffd)
            || ($cp >= 0x30000 && $cp <= 0x3fffd);
    }
}
