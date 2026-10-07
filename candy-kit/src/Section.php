<?php

declare(strict_types=1);

namespace SugarCraft\Kit;

use SugarCraft\Core\Util\Width;
use SugarCraft\Kit\Internal\SafeText;
use SugarCraft\Kit\Internal\WidthGuard;
use SugarCraft\Kit\Internal\WidthProbe;

/**
 * Render a section header — a label sandwiched between two horizontal
 * rules: `── LABEL ──────────────`. Common in CLI output where you
 * want to break long stretches of stdout into named groups.
 *
 * The label uses the theme's accent style; the rule rune defaults to
 * `─` (Unicode box-drawing horizontal). Total width defaults to the
 * terminal width — the exported `COLUMNS` where the environment
 * advertises one, else 80 cells (see {@see WidthProbe}); pass an
 * explicit width or `null` to disable trailing fill (output ends right
 * after the label's closing pad).
 */
final class Section
{
    /**
     * @param int|AutoWidth|null $width  total cell width of the line, and a
     *                     hard cap: the output never exceeds it. A label too
     *                     long for the room left after the lead runes and its
     *                     two pad spaces is cut with a `…`; if not even the
     *                     `…` fits, the label is dropped, and lead runes that
     *                     alone exceed the width are cut too. A width below 1
     *                     is an authoring error and throws (see
     *                     {@see self::assertWidth()}); null = no cap and
     *                     no fill: stop after the leading pad + label + 1
     *                     trailing rune. Omitted (or `AutoWidth::Auto`)
     *                     resolves to the terminal width, falling back to 80
     *                     cells — see {@see WidthProbe}.
     *
     * @throws \InvalidArgumentException when `$width` is less than 1
     */
    public static function header(
        string $label,
        ?Theme $theme = null,
        int $leftPad = 2,
        int|AutoWidth|null $width = AutoWidth::Auto,
        string $rune = '─',
    ): string {
        $width = WidthProbe::resolve($width);
        self::assertWidth($width);
        $theme   ??= Theme::detect();
        $label   = SafeText::line($label);  // neutralize escape/control injection
        $runeW   = max(1, Width::string($rune));  // cell width of the fill rune
        // $leftPad is a rune count (not cell count) — each rune repeats once
        $leadRunes = max(0, $leftPad);
        if ($width !== null) {
            // A lead wider than the whole line keeps only the runes that fit.
            // Inside this guard $width is >= 1 — assertWidth() refused anything
            // smaller — so no floor is needed here.
            $leadRunes = min($leadRunes, intdiv($width, $runeW));
        }
        $left = str_repeat($rune, $leadRunes);
        if ($width === null) {
            return $left . self::labelOut($label, $theme) . $rune;  // one trailing rune (may exceed for multi-cell runes)
        }
        return self::fill($left, $label, $theme, $width, $rune, $runeW);
    }

    /**
     * Render a horizontal rule — the fill of `header('')` without its
     * lead/label logic. Pass `width: null` for a fixed 2-cell dash.
     * The rule is styled with the theme's `muted` style and accepts
     * a custom fill rune (measured in cells for multi-cell glyphs).
     *
     * Length: with an explicit `$width` the rule is as many whole runes as
     * fit in `$width` cells — so a multi-cell rune wider than the width
     * yields an empty string. With `$width` null the budget is 2 cells: two
     * single-cell runes (one 2-cell rune), unlike header() with null width,
     * which ends after a single trailing rune.
     *
     * @throws \InvalidArgumentException when `$width` is less than 1
     */
    public static function rule(
        ?Theme $theme = null,
        int|AutoWidth|null $width = AutoWidth::Auto,
        string $rune = '─',
    ): string {
        $width = WidthProbe::resolve($width);
        self::assertWidth($width);
        $theme  ??= Theme::detect();
        $runeW  = max(1, Width::string($rune));
        $repeat = intdiv($width ?? 2, $runeW);
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
     * @param int|AutoWidth|null $width   total display width, and a hard cap
     *                          — same truncation rules as {@see header()},
     *                          with the indent cut to the width when it alone
     *                          exceeds it; null emits no fill run — just the
     *                          indent, label and one trailing rune (the
     *                          terminal-width probe is skipped). Omitted (or
     *                          `AutoWidth::Auto`) resolves to the terminal
     *                          width, falling back to 80 cells — see
     *                          {@see WidthProbe}.
     * @param string $rune      divider rune between label and end fill
     *
     * @throws \InvalidArgumentException when `$width` is less than 1
     */
    public static function subHeader(
        string $label,
        ?Theme $theme = null,
        int $indent = 4,
        int|AutoWidth|null $width = AutoWidth::Auto,
        string $rune = '·',
    ): string {
        $width = WidthProbe::resolve($width);
        self::assertWidth($width);
        $theme  ??= Theme::detect();
        $label  = SafeText::line($label);  // neutralize escape/control injection
        $runeW  = max(1, Width::string($rune));
        $indent = max(0, $indent);
        if ($width === null) {
            return str_repeat(' ', $indent) . self::labelOut($label, $theme) . $rune;
        }
        // An indent wider than the whole line keeps only the spaces that fit.
        $pad = str_repeat(' ', min($indent, $width));
        return self::fill($pad, $label, $theme, $width, $rune, $runeW);
    }

    /** ` LABEL ` in the accent style, or '' for an empty label. */
    private static function labelOut(string $label, Theme $theme): string
    {
        return $label === '' ? '' : ' ' . $theme->accent->render($label) . ' ';
    }

    /**
     * Lay `$lead` + ` LABEL ` + rune fill into exactly the room `$width`
     * allows, never past it. `$lead` must already fit in `$width`. The label
     * is measured and cut while still plain text — before styling — so the
     * cut can never split an escape sequence.
     */
    private static function fill(
        string $lead,
        string $label,
        Theme $theme,
        int $width,
        string $rune,
        int $runeW,
    ): string {
        // Room for the label itself once the lead and its two pad spaces are placed.
        $room = $width - Width::string($lead) - 2;
        if ($label !== '' && Width::string($label) > $room) {
            $label = $room >= 1 ? Width::truncate($label, $room - 1) . '…' : '';
        }
        $head = $lead . self::labelOut($label, $theme);
        $repeat = intdiv(max(0, $width - Width::string($head)), $runeW);
        return $head . str_repeat($rune, $repeat);
    }

    /**
     * Refuse a width below one cell, harmonized with HelpText via
     * {@see WidthGuard} — see that class for why clamping was the wrong answer.
     */
    private static function assertWidth(?int $width): void
    {
        WidthGuard::assert('Section', $width, 'disable trailing fill');
    }
}
