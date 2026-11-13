<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;

/**
 * Shared layout dimension calculator for SVG and PNG renderers.
 */
final class LayoutCalculator
{
    /**
     * Cumulative count of painted-column walks ever run (cs-25, -045).
     * Layout vs paint share ONE width walk: every line is measured here,
     * once, and the segment paint loop only advances x by per-segment
     * widths it already needs. The counter is the pass-count pin
     * {@see \SugarCraft\CodeShot\Tests\LayoutCalculatorTest} asserts
     * against — restoring a second maxCols walk anywhere in the render
     * path shifts the delta, even though the bytes stay identical.
     */
    private static int $paintedColumnScans = 0;

    public static function paintedColumnScanCount(): int
    {
        return self::$paintedColumnScans;
    }

    /**
     * @param bool $chrome When false, the 36px window-title band is collapsed:
     *                     `--window-style none` (WindowStyle::None) keeps the
     *                     framed look but paints no title bar, and must not
     *                     reserve empty space for one that never arrives.
     *
     * @return array{0:int, 1:int, 2:float, 3:float, 4:int, 5:int, 6:float, 7:float}
     *   [maxCols, gutter, contentWidth, contentHeight, headerHeight, shadowMargin, totalW, totalH]
     *   — 2/3/6/7 are float-measured (cell sizes are float; every derived
     *   extent keeps the type), pinned in LayoutCalculatorTest.
     */
    public static function calculate(
        array $lines,
        bool $lineNumbers,
        int $padding,
        bool $window,
        bool $shadow,
        float $cellW,
        float $cellH,
        bool $chrome = true,
    ): array {
        $maxCols = 0;
        foreach ($lines as $line) {
            $cols = self::paintedColumns(Ansi::strip($line));
            if ($cols > $maxCols) {
                $maxCols = $cols;
            }
        }
        $gutter = $lineNumbers
            ? max(2, strlen((string) count($lines))) + 2
            : 0;

        $contentWidth  = ($maxCols + $gutter) * $cellW;
        $contentHeight = count($lines) * $cellH;

        $headerHeight = ($window && $chrome) ? 36 : 0;
        $frameWidth    = $contentWidth + $padding * 2;
        $frameHeight   = $contentHeight + $padding * 2 + $headerHeight;

        $shadowMargin = $shadow ? 32 : 0;
        $totalW = $frameWidth + $shadowMargin * 2;
        $totalH = $frameHeight + $shadowMargin * 2;

        return [$maxCols, $gutter, $contentWidth, $contentHeight, $headerHeight, $shadowMargin, $totalW, $totalH];
    }

    /**
     * Cells a tab advances when painting, mirroring xterm. The budget side
     * and the paint side ({@see SgrStateHandler::execute()}) now charge the
     * same stop from the same helper — {@see TabStops} — so frame budget and
     * painted columns cannot drift apart on tabbed lines. sugar-core
     * Width::TAB_WIDTH is 4 and is deliberately not used here: the paint side
     * already pads to 8-cell stops.
     */
    private static function paintedColumns(string $stripped): int
    {
        self::$paintedColumnScans++;
        $column = 0;
        foreach (explode("\t", $stripped) as $index => $chunk) {
            if ($index > 0) {
                $column += TabStops::pad($column);
            }
            $column += Width::of($chunk);
        }

        return $column;
    }
}
