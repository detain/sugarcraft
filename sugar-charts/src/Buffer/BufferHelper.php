<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Buffer;

use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Style as BufferStyle;
use SugarCraft\Style\Style as SugarStyle;
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
     * cells. Delegates to the shared sugar-core width table
     * ({@see Width::of()}) so East-Asian, emoji (including plane-1), and
     * combining marks follow the same canon as every other SugarCraft
     * renderer — the old private table omitted plane-1 emoji despite its
     * docblock claiming otherwise.
     */
    public static function graphemeWidth(string $cluster): int
    {
        if ($cluster === '') {
            return 0;
        }
        return Width::of($cluster);
    }

    /**
     * Split a string into display clusters — the fleet-proven ladder
     * (identical to sugar-cellbuffer's Buffer::clusters): optional
     * `grapheme_str_split()` accelerator (symfony polyfill / future
     * core), then `IntlBreakIterator` character boundaries, which is
     * what this package's hard ext-intl requirement guarantees
     * (sugar-charts-044: without it combining marks and ZW sequences
     * used to silently degrade to CODEPOINT cells — same bytes
     * diverging per platform, zero diagnostic). The mb/preg codepoint
     * arms remain only as defence for malformed UTF-8 and installs
     * that bypass composer. Centralised here so the Buffer and Canvas
     * placement paths cannot drift.
     *
     * @return list<string>
     */
    public static function clusters(string $s): array
    {
        if ($s === '') {
            return [];
        }
        if (function_exists('grapheme_str_split')) {
            $g = grapheme_str_split($s);
            if (is_array($g)) {
                return $g;
            }
        }
        if (class_exists(\IntlBreakIterator::class)) {
            $iterator = \IntlBreakIterator::createCharacterInstance();
            $iterator->setText($s);
            $out = [];
            $previous = 0;
            foreach ($iterator as $end) {
                if ($end > $previous) {
                    $out[] = substr($s, $previous, $end - $previous);
                    $previous = $end;
                }
            }
            return $out;
        }
        if (function_exists('mb_str_split')) {
            return mb_str_split($s, 1, 'UTF-8');
        }
        return preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Introspection seam for the splitter actually in force: 'grapheme'
     * when either accelerator is available (composer contract via
     * ext-intl) or 'codepoint' on a host that somehow lacks both arms.
     * Diagnostics and tests pin which canon produced a render instead
     * of guessing from platform bytes (sugar-charts-044).
     */
    public static function clusterMode(): string
    {
        return function_exists('grapheme_str_split') || class_exists(\IntlBreakIterator::class)
            ? 'grapheme'
            : 'codepoint';
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
        $clusters = self::clusters($s);

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
     * Convert a sugar-style Style (`SugarCraft\Style\Style`) to a Buffer\Style.
     * Only fg/bg/attrs are transferred; advanced features (borders, padding, etc.) are dropped.
     */
    public static function toBufferStyle(SugarStyle $s): BufferStyle
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
