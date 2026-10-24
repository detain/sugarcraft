<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Output;

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
     * @param ColorProfile|null $profile Colour tier to emit for; null follows
     *        the terminal's detected capability via `ColorProfile::detect()`
     *        — the same choke the Plot renderers use, so NO_COLOR strips the
     *        escapes instead of this helper overriding the user's setting.
     * @return string Rendered bar
     */
    public static function render(
        float $percentage,
        int $width,
        string $filled = '█',
        string $empty = '░',
        ?Color $filledColor = null,
        ?Color $emptyColor = null,
        ?ColorProfile $profile = null,
    ): string {
        if ($width <= 0) {
            return '';
        }

        $profile ??= ColorProfile::detect();
        $percentage = max(0.0, min(1.0, $percentage));
        $filledWidth = (int) round($percentage * $width);
        $emptyWidth = $width - $filledWidth;

        $result = '';

        // Add filled portion with color; when the profile carries no colour
        // (NO_COLOR ⇒ Ascii, where toFg() answers '') emit neither the open
        // nor its reset — a lone SGR 0 would contradict the user's request.
        if ($filledWidth > 0) {
            $open = $filledColor !== null ? $filledColor->toFg($profile) : '';
            if ($open !== '') {
                $result .= $open;
            }
            $result .= str_repeat($filled, $filledWidth);
            if ($open !== '') {
                $result .= "\x1b[0m";
            }
        }

        // Add empty portion with color
        if ($emptyWidth > 0) {
            $open = $emptyColor !== null ? $emptyColor->toFg($profile) : '';
            if ($open !== '') {
                $result .= $open;
            }
            $result .= str_repeat($empty, $emptyWidth);
            if ($open !== '') {
                $result .= "\x1b[0m";
            }
        }

        return $result;
    }

    /**
     * Render a progress bar shaded at eighth-block precision.
     *
     * Every cell carries the same shade from a 9-entry ramp: the light
     * shade ░, the left-flush eighths ▏▎▍▌▋▊▉, and the full block █.
     * Bars grow left→right, so partial shades are flush-left (a 1/8 fill
     * leads with ▏, U+258F — never its right-flush mirror).
     *
     * @param float $percentage 0.0 to 1.0
     * @param int $width Total width in cells
     * @param Color|null $color Color for the bar
     * @param ColorProfile|null $profile Colour tier to emit for; null follows
     *        the terminal's detected capability via `ColorProfile::detect()`.
     * @return string Rendered bar
     */
    public static function renderSegmented(
        float $percentage,
        int $width,
        ?Color $color = null,
        ?ColorProfile $profile = null,
    ): string {
        if ($width <= 0) {
            return '';
        }

        $profile ??= ColorProfile::detect();

        $percentage = max(0.0, min(1.0, $percentage));
        $blocks = ['░', '▏', '▎', '▍', '▌', '▋', '▊', '▉', '█'];

        $fullBlocks = (int) floor($percentage * $width * 8);
        $result = '';

        // Same open/reset pairing law as render(): no colour tier, no SGR bytes.
        $open = $color !== null ? $color->toFg($profile) : '';
        if ($open !== '') {
            $result .= $open;
        }

        for ($i = 0; $i < $width; $i++) {
            $blockIndex = min(8, (int) floor($fullBlocks / max(1, $width)));
            $result .= $blocks[$blockIndex];
        }

        if ($open !== '') {
            $result .= "\x1b[0m";
        }

        return $result;
    }
}
