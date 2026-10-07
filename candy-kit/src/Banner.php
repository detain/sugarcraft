<?php

declare(strict_types=1);

namespace SugarCraft\Kit;

use SugarCraft\Kit\Internal\SafeText;
use SugarCraft\Kit\Internal\WidthGuard;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;

/**
 * Render a bordered title banner. The title is rendered with the
 * theme's accent style; the optional subtitle picks up the muted
 * style. Borders default to {@see Border::rounded()} but can be
 * overridden by passing a custom Border instance.
 *
 * Title and subtitle are single display lines: like every other presenter's
 * caller text they go through {@see SafeText::line()}, so an embedded escape
 * sequence, newline or other control byte is stripped rather than allowed to
 * inject into the terminal or knock a row out of the border box.
 *
 * The box sizes itself to its content unless `$width` is given; see
 * {@see self::title()} for the cap's exact semantics, which are
 * {@see Style::width()}'s.
 */
final class Banner
{
    /**
     * @param ?int $width  fixed INNER content width in cells (the room inside
     *                     the padding and the border), i.e.
     *                     {@see Style::width()}'s semantics: each display line
     *                     is cut to it before the box is laid — a hard cut with
     *                     no ellipsis, unlike a Section label — so the bordered
     *                     block never exceeds `$width` plus the padding and
     *                     border. null — the default, and the shape every
     *                     existing caller uses — lets the box size itself to
     *                     its content.
     *
     * @throws \InvalidArgumentException when `$width` is less than 1
     */
    public static function title(string $title, string $subtitle = '', ?Theme $theme = null, ?Border $border = null, ?int $width = null): string
    {
        $theme  ??= Theme::detect();
        $border ??= Border::rounded();

        // Style::width() would accept 0 and render a box with no room for the
        // title; the presenters agree that a width below 1 is a mistake.
        WidthGuard::assert('Banner', $width, 'size the box to its content');

        $title    = SafeText::line($title);
        $subtitle = SafeText::line($subtitle);

        $body  = $theme->accent->render($title);
        if ($subtitle !== '') {
            $body .= "\n" . $theme->muted->render($subtitle);
        }

        // The border+padding Style is recomputed per call. It was previously
        // memoized in a mutable static keyed on the (unreachable) type-check
        // `$border instanceof Border\Rounded` — a class that does not exist, so
        // the branch never fired — leaving process-lifetime static state that
        // could not be invalidated when the border changed. Building the Style
        // fresh is cheap and removes that stale-state hazard.
        $style = Style::new()->border($border)->padding(0, 2);

        if ($width !== null) {
            $style = $style->width($width);
        }

        return $style->render($body);
    }
}
