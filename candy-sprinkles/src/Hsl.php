<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles;

/**
 * Factory for creating Color instances via HSL values.
 *
 * Mirrors charmbracelet/lipgloss's color factory pattern where HSL
 * values are expressed as hue 0-360, saturation 0-100, lightness 0-100
 * (the conventional CSS-style notation rather than the raw 0-1 fractions
 * that Color::hsl() accepts).
 *
 * @see \SugarCraft\Core\Util\Color::hsl()
 */
final class Hsl
{
    /**
     * Create a Color from HSL values.
     *
     * @param float $h Hue in degrees [0, 360)
     * @param float $s Saturation percentage [0, 100]
     * @param float $l Lightness percentage [0, 100]
     */
    public static function color(float $h, float $s, float $l): \SugarCraft\Core\Util\Color
    {
        // Normalize hue to [0, 360)
        $h = fmod($h, 360.0);
        if ($h < 0) {
            $h += 360.0;
        }
        // Clamp saturation and lightness to [0, 100]
        $s = max(0.0, min(100.0, $s));
        $l = max(0.0, min(100.0, $l));

        // Convert from percentage to 0-1 fraction
        return \SugarCraft\Core\Util\Color::hsl($h, $s / 100.0, $l / 100.0);
    }

    /**
     * Create a Color from HSL string like "hsl(200, 80%, 50%)".
     *
     * Supports whitespace variations. Returns null if the string
     * cannot be parsed.
     */
    public static function parse(string $hsl): ?\SugarCraft\Core\Util\Color
    {
        $hsl = trim($hsl);
        if (!str_starts_with(strtolower($hsl), 'hsl(')) {
            return null;
        }
        $inner = trim(substr($hsl, 4));
        if (!str_ends_with($inner, ')')) {
            return null;
        }
        $inner = trim(substr($inner, 0, -1));
        if ($inner === '') {
            return null;
        }

        // Split on comma, handling potential / separators for alpha
        $parts = preg_split('/\s*,\s*/', $inner);
        if ($parts === false || count($parts) < 3) {
            return null;
        }

        // Parse H value (no suffix)
        $hStr = trim($parts[0]);
        $h = (float) filter_var($hStr, FILTER_VALIDATE_FLOAT);
        if ($h === false || $hStr === '') {
            return null;
        }

        // Parse S value (may have % suffix)
        $sStr = trim($parts[1]);
        $sHasPercent = str_ends_with($sStr, '%');
        $sStrClean = $sHasPercent ? rtrim($sStr, '%') : $sStr;
        $s = (float) filter_var($sStrClean, FILTER_VALIDATE_FLOAT);
        if ($s === false || $sStrClean === '') {
            return null;
        }

        // Parse L value (may have % suffix)
        $lStr = trim($parts[2]);
        $lHasPercent = str_ends_with($lStr, '%');
        $lStrClean = $lHasPercent ? rtrim($lStr, '%') : $lStr;
        $l = (float) filter_var($lStrClean, FILTER_VALIDATE_FLOAT);
        if ($l === false || $lStrClean === '') {
            return null;
        }

        return self::color($h, $s, $l);
    }
}
