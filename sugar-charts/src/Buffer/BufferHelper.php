<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Buffer;

use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Style as BufferStyle;
use SugarCraft\Sprinkles\Style as SprinklesStyle;
use SugarCraft\Core\Util\Width;
use SugarCraft\Core\Util\Color;

/**
 * Helpers for building Buffer-backed chart renderers.
 *
 * Mirrors charmbracelet/lipgloss Buffer rendering pipeline.
 */
final class BufferHelper
{
    /**
     * Compute the display width of a single grapheme cluster in terminal
     * cells. Delegates to the shared candy-core width table
     * ({@see Width::of()}) so East-Asian, emoji (including plane-1), and
     * combining marks follow the same canon as every other SugarCraft
     * renderer — the old private table omitted plane-1 emoji despite its
     * docblock claiming otherwise (audit F6).
     */
    public static function graphemeWidth(string $cluster): int
    {
        if ($cluster === '') {
            return 0;
        }
        return Width::of($cluster);
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

    private static function colorToInt(Color $color): int
    {
        return ($color->r << 16) | ($color->g << 8) | $color->b;
    }
}
