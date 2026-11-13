<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Theme;

/**
 * Font-family sanitisation shared by the theme loaders.
 *
 * Both loaders used to carry byte-identical private copies of this door
 * (candy-codeshot-053) — the same drift-invisible duplication that
 * justified extracting {@see NormalizesHexColor}. One implementation,
 * two callers, and the admitted charset can no longer fork.
 */
trait SanitizesFontFamily
{
    /**
     * The house font stack: what every loader falls back to when a theme
     * file carries no usable fontFamily — and the one value no theme may
     * smuggle past the charset door as a custom family.
     */
    private const HOUSE_FONT_FAMILY = 'Hack, "JetBrains Mono", Menlo, Consolas, monospace';

    /**
     * Font family names are echoed into SVG attributes and CSS by the
     * renderers; keep them to a plainly-safe charset and fall back to the
     * house default instead of trusting theme files (docs-fix era).
     */
    private static function sanitizeFontFamily(string $family): string
    {
        $p = '/^[A-Za-z0-9 ,"\'\-]+$/';
        if (preg_match($p, $family) !== 1) {
            return self::HOUSE_FONT_FAMILY;
        }

        return $family;
    }
}
