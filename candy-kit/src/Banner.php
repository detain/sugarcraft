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
    private static ?Style $titleStyleCache = null;

    public static function title(string $title, string $subtitle = '', ?Theme $theme = null, ?Border $border = null): string
    {
        $theme  ??= Theme::ansi();
        $border ??= Border::rounded();

        $body  = $theme->accent->render($title);
        if ($subtitle !== '') {
            $body .= "\n" . $theme->muted->render($subtitle);
        }

        // Only use the cache when using default rounded border (the common case).
        // Custom borders always get a fresh style.
        if ($border instanceof Border\Rounded) {
            if (self::$titleStyleCache !== null) {
                return self::$titleStyleCache->render($body);
            }
            self::$titleStyleCache = Style::new()->border($border)->padding(0, 2);
            return self::$titleStyleCache->render($body);
        }

        return Style::new()->border($border)->padding(0, 2)->render($body);
    }
}
