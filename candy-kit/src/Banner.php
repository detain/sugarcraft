<?php

declare(strict_types=1);

namespace SugarCraft\Kit;

use SugarCraft\Kit\Internal\SafeText;
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
 */
final class Banner
{
    public static function title(string $title, string $subtitle = '', ?Theme $theme = null, ?Border $border = null): string
    {
        $theme  ??= Theme::detect();
        $border ??= Border::rounded();

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
        return Style::new()->border($border)->padding(0, 2)->render($body);
    }
}
