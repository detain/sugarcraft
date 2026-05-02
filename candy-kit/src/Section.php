<?php

declare(strict_types=1);

namespace CandyCore\Kit;

use CandyCore\Core\Util\Width;

/**
 * Render a section header — a label sandwiched between two horizontal
 * rules: `── LABEL ──────────────`. Common in CLI output where you
 * want to break long stretches of stdout into named groups.
 *
 * The label uses the theme's accent style; the rule rune defaults to
 * `─` (Unicode box-drawing horizontal). Total width defaults to 80
 * cells; pass an explicit width or `null` to disable trailing fill
 * (output ends right after the label's closing pad).
 */
final class Section
{
    /**
     * @param ?int $width  total cell width to fill; null = stop after the
     *                     leading pad + label + 1 trailing rune.
     */
    public static function header(
        string $label,
        ?Theme $theme = null,
        int $leftPad = 2,
        ?int $width = 80,
        string $rune = '─',
    ): string {
        $theme   ??= Theme::ansi();
        $left      = str_repeat($rune, max(0, $leftPad));
        $labelOut  = $label === '' ? '' : ' ' . $theme->accent->render($label) . ' ';
        $head      = $left . $labelOut;
        if ($width === null) {
            return $head . $rune;
        }
        $remaining = max(0, $width - Width::string($head));
        return $head . str_repeat($rune, $remaining);
    }

    /**
     * Render a horizontal rule — same as `header('')` but expressed
     * directly. Pass `width: null` to use a fixed two-rune dash.
     */
    public static function rule(
        ?Theme $theme = null,
        ?int $width = 80,
        string $rune = '─',
    ): string {
        return str_repeat($rune, max(1, $width ?? 2));
    }
}
