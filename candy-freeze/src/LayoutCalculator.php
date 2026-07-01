<?php

declare(strict_types=1);

namespace SugarCraft\Freeze;

use SugarCraft\Core\Util\Ansi;

/**
 * Shared layout dimension calculator for SVG and PNG renderers.
 */
final class LayoutCalculator
{
    /**
     * @return array{0:int, 1:int, 2:float, 3:int, 4:int, 5:int, 6:int, 7:int}
     *   [maxCols, gutter, contentWidth, contentHeight, headerHeight, shadowMargin, totalW, totalH]
     */
    public static function calculate(
        array $lines,
        bool $lineNumbers,
        int $padding,
        bool $window,
        bool $shadow,
        float $cellW,
        float $cellH,
    ): array {
        $maxCols = 0;
        foreach ($lines as $line) {
            $cols = mb_strlen(Ansi::strip($line), 'UTF-8');
            if ($cols > $maxCols) {
                $maxCols = $cols;
            }
        }
        $gutter = $lineNumbers
            ? max(2, strlen((string) count($lines))) + 2
            : 0;

        $contentWidth  = ($maxCols + $gutter) * $cellW;
        $contentHeight = count($lines) * $cellH;

        $headerHeight = $window ? 36 : 0;
        $frameWidth    = $contentWidth + $padding * 2;
        $frameHeight   = $contentHeight + $padding * 2 + $headerHeight;

        $shadowMargin = $shadow ? 32 : 0;
        $totalW = $frameWidth + $shadowMargin * 2;
        $totalH = $frameHeight + $shadowMargin * 2;

        return [$maxCols, $gutter, $contentWidth, $contentHeight, $headerHeight, $shadowMargin, $totalW, $totalH];
    }
}
