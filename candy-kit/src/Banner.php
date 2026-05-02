<?php

declare(strict_types=1);

namespace CandyCore\Kit;

use CandyCore\Sprinkles\Border;
use CandyCore\Sprinkles\Style;

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

        return Style::new()
            ->border($border)
            ->padding(0, 2)
            ->render($body);
    }
}
