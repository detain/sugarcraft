<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Output;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * Helper for rendering progress bars.
 *
 * Provides consistent progress bar rendering across different
 * components like Gauge, Progress, and Meter.
 */
final class RenderBar
{
    /**
     * Render a horizontal progress bar.
     *
     * @param float $percentage 0.0 to 1.0
     * @param int $width Total width in cells
     * @param string $filled Character for filled portion
     * @param string $empty Character for empty portion
     * @param Color|null $filledColor Color for filled portion
     * @param Color|null $emptyColor Color for empty portion
     * @return string Rendered bar
     */
    public static function render(
        float $percentage,
        int $width,
        string $filled = '█',
        string $empty = '░',
        ?Color $filledColor = null,
        ?Color $emptyColor = null,
    ): string {
        if ($width <= 0) {
            return '';
        }

        $percentage = max(0.0, min(1.0, $percentage));
        $filledWidth = (int) round($percentage * $width);
        $emptyWidth = $width - $filledWidth;

        $result = '';

        // Add filled portion with color
        if ($filledWidth > 0) {
            if ($filledColor !== null) {
                $result .= $filledColor->toFg(ColorProfile::TrueColor);
            }
            $result .= str_repeat($filled, $filledWidth);
            if ($filledColor !== null) {
                $result .= "\x1b[0m";
            }
        }

        // Add empty portion with color
        if ($emptyWidth > 0) {
            if ($emptyColor !== null) {
                $result .= $emptyColor->toFg(ColorProfile::TrueColor);
            }
            $result .= str_repeat($empty, $emptyWidth);
            if ($emptyColor !== null) {
                $result .= "\x1b[0m";
            }
        }

        return $result;
    }

    /**
     * Render a segmented progress bar with 8 block characters.
     *
     * Uses the 8 block characters for smoother visual progress:
     * ▏▎▍▌▋▊▉█
     *
     * @param float $percentage 0.0 to 1.0
     * @param int $width Total width in cells
     * @param Color|null $color Color for the bar
     * @return string Rendered bar
     */
    public static function renderSegmented(
        float $percentage,
        int $width,
        ?Color $color = null,
    ): string {
        if ($width <= 0) {
            return '';
        }

        $percentage = max(0.0, min(1.0, $percentage));
        $blocks = ['░', '▕', '▎', '▍', '▌', '▋', '▊', '▉', '█'];

        $fullBlocks = (int) floor($percentage * $width * 8);
        $result = '';

        if ($color !== null) {
            $result .= $color->toFg(ColorProfile::TrueColor);
        }

        for ($i = 0; $i < $width; $i++) {
            $blockIndex = min(8, (int) floor($fullBlocks / max(1, $width)));
            $result .= $blocks[$blockIndex];
        }

        if ($color !== null) {
            $result .= "\x1b[0m";
        }

        return $result;
    }
}
