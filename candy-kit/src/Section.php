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
        // $leftPad is a rune count (not cell count) — each rune repeats once
        $left    = str_repeat($rune, max(0, $leftPad));
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
     *
     * Note: when `$width` is null, rule() always produces at least 2 cells
     * (unlike header() with null width which produces only 1 trailing rune).
     * This minimum-2 behavior ensures the rule remains visible even at
     * minimal terminal widths.
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

    /**
     * Render an indented section divider for sub-sections.
     *
     * Unlike {@see header()} which uses a left-pad rune count, subHeader()
     * uses a fixed left-margin of spaces (default 4 cells) followed by
     * a lighter divider rune (`·` by default). This visually nests the
     * section under a parent {@see header()} or {@see rule()}.
     *
     * @param string $label     sub-section label; empty = divider line only
     * @param Theme|null $theme
     * @param int $indent       left margin in cells (default 4)
     * @param int|null $width   total width; null = fill to terminal
     * @param string $rune      divider rune between label and end fill
     */
    public static function subHeader(
        string $label,
        ?Theme $theme = null,
        int $indent = 4,
        ?int $width = 80,
        string $rune = '·',
    ): string {
        $theme  ??= Theme::ansi();
        $runeW  = max(1, Width::string($rune));
        $pad    = str_repeat(' ', max(0, $indent));
        $labelOut = $label === '' ? '' : ' ' . $theme->accent->render($label) . ' ';
        $head    = $pad . $labelOut;
        if ($width === null) {
            return $head . $rune;
        }
        $remaining = max(0, $width - Width::string($head));
        $repeat = intdiv($remaining, $runeW);
        return $head . str_repeat($rune, $repeat);
    }
}
