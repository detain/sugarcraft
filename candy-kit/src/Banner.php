<?php

declare(strict_types=1);

namespace SugarCraft\Kit;

use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;

/**
 * Render a bordered title banner. The title is rendered with the
 * theme's accent style; the optional subtitle picks up the muted
 * style. Borders default to {@see Border::rounded()} but can be
 * overridden by passing a custom Border instance.
 */
final class Banner
{
    public static function title(string $title, string $subtitle = '', ?Theme $theme = null, ?Border $border = null): string
    {
        $theme  ??= Theme::ansi();
        $border ??= Border::rounded();

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
