<?php

declare(strict_types=1);

namespace SugarCraft\Kit;

use SugarCraft\Core\Util\Width;

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
        $runeW   = max(1, Width::string($rune));  // cell width of the fill rune
        $left    = str_repeat($rune, intdiv(max(0, $leftPad), $runeW));
        $labelOut  = $label === '' ? '' : ' ' . $theme->accent->render($label) . ' ';
        $head      = $left . $labelOut;
        if ($width === null) {
            return $head . $rune;  // one trailing rune (may exceed for multi-cell runes)
        }
        $remaining = max(0, $width - Width::string($head));
        $repeat = intdiv($remaining, $runeW);
        return $head . str_repeat($rune, $repeat);
    }

    /**
     * Render a horizontal rule — same as `header('')` but expressed
     * directly. Pass `width: null` to use a fixed two-rune dash.
     * The rule is styled with the theme's `muted` style and accepts
     * a custom fill rune (measured in cells for multi-cell glyphs).
     */
    public static function rule(
        ?Theme $theme = null,
        ?int $width = 80,
        string $rune = '─',
    ): string {
        $theme  ??= Theme::ansi();
        $runeW  = max(1, Width::string($rune));
        $repeat = intdiv(max(1, $width ?? 2), $runeW);
        $bare   = str_repeat($rune, $repeat);
        return $theme->muted->render($bare);
    }
}
