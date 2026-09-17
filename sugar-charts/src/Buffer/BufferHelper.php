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

    private static function colorToInt(Color $color): int
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

    /**
     * Zero-width / combining codepoints outside the C0 control block.
     * Keyed set (E736/2.2 round 86): flipped lookup so the hot
     * per-grapheme width path is O(1) instead of an in_array scan.
     */
    private const ZERO_WIDTH = [
            0x200b => true, 0x200c => true, 0x200d => true, 0x2060 => true, 0xfeff => true,
            0x0300 => true, 0x0301 => true, 0x0302 => true, 0x0303 => true, 0x0304 => true,
            0x0305 => true, 0x0306 => true, 0x0307 => true, 0x0308 => true, 0x0309 => true,
            0x030a => true, 0x030b => true, 0x030c => true, 0x030d => true, 0x030e => true,
            0x030f => true, 0x0310 => true, 0x0311 => true, 0x0312 => true, 0x0313 => true,
            0x0314 => true, 0x0315 => true, 0x0316 => true, 0x0317 => true, 0x0318 => true,
            0x0319 => true, 0x031a => true, 0x031b => true, 0x031c => true, 0x031d => true,
            0x031e => true, 0x031f => true, 0x0320 => true, 0x0321 => true, 0x0322 => true,
            0x0323 => true, 0x0324 => true, 0x0325 => true, 0x0326 => true, 0x0327 => true,
            0x0328 => true, 0x0329 => true, 0x032a => true, 0x032b => true, 0x032c => true,
            0x032d => true, 0x032e => true, 0x032f => true, 0x0330 => true, 0x0331 => true,
            0x0332 => true, 0x0333 => true, 0x0334 => true, 0x0335 => true, 0x0336 => true,
            0x0337 => true, 0x0338 => true, 0x0339 => true, 0x033a => true, 0x033b => true,
            0x033c => true, 0x033d => true, 0x033e => true, 0x033f => true, 0x0340 => true,
            0x0341 => true, 0x0342 => true, 0x0343 => true, 0x0344 => true, 0x0345 => true,
            0x0346 => true, 0x0347 => true, 0x0348 => true, 0x0349 => true, 0x034a => true,
            0x034b => true, 0x034c => true, 0x034d => true, 0x034e => true, 0x034f => true,
            0x0350 => true, 0x0351 => true, 0x0352 => true, 0x0353 => true, 0x0354 => true,
            0x0355 => true, 0x0356 => true, 0x0357 => true, 0x0358 => true, 0x0359 => true,
            0x035a => true, 0x035b => true, 0x035c => true, 0x035d => true, 0x035e => true,
            0x035f => true, 0x0360 => true, 0x0361 => true, 0x0362 => true, 0x0363 => true,
            0x0364 => true, 0x0365 => true, 0x0366 => true, 0x0367 => true, 0x0368 => true,
            0x0369 => true, 0x036a => true, 0x036b => true, 0x036c => true, 0x036d => true,
            0x036e => true, 0x036f => true, 0x0483 => true, 0x0484 => true, 0x0485 => true,
            0x0486 => true, 0x0487 => true, 0x0488 => true, 0x0489 => true,
    ];

    private static function isZeroWidth(int $cp): bool
    {
        return ($cp >= 0x0000 && $cp <= 0x001f && $cp !== 0x0009 && $cp !== 0x000a && $cp !== 0x000d)
            || isset(self::ZERO_WIDTH[$cp]);
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
