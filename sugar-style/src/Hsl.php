<?php

declare(strict_types=1);

namespace SugarCraft\Style;

/**
 * Factory for creating Color instances via HSL values.
 *
 * Mirrors charmbracelet/lipgloss's color factory pattern: hue 0-360,
 * saturation 0-100, lightness 0-100 (the conventional CSS-style
 * notation rather than the raw 0-1 fractions that Color::hsl() accepts).
 *
 * Scale honesty (finding 055): a component WITHOUT a `%` suffix is still
 * read on the 0-100 percent scale — `Hsl::parse('hsl(120, 0.5, 0.5)')`
 * means half a percent, not fifty (same reading as CSS, where a unitless
 * saturation below the implicit scale can only mean 0.5%). Callers who
 * hold 0-1 fractions want {@see \SugarCraft\Core\Util\Color::hsl()},
 * which takes fractions directly. Out-of-range components are clamped
 * into scale by {@see color()}; hue wraps modulo 360.
 *
 * @see \SugarCraft\Core\Util\Color::hsl()
 */
final class Hsl
{
    /**
     * Create a Color from HSL values.
     *
     * Hue wraps modulo 360 (negative hues rotate the other way);
     * saturation and lightness are clamped into [0, 100]. See the class
     * note for what a unitless component means (percent scale).
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

        // Parse H value (no suffix). The raw result must be tested for
        // `false` BEFORE any float cast — casting first turns rejected
        // garbage into 0.0 and silences the guard.
        $hStr = trim($parts[0]);
        $hRaw = filter_var($hStr, FILTER_VALIDATE_FLOAT);
        if ($hRaw === false || $hStr === '') {
            return null;
        }
        $h = (float) $hRaw;

        // Parse S value (may have % suffix)
        $sStr = trim($parts[1]);
        $sHasPercent = str_ends_with($sStr, '%');
        $sStrClean = $sHasPercent === true ? rtrim($sStr, '%') : $sStr;
        $sRaw = filter_var($sStrClean, FILTER_VALIDATE_FLOAT);
        if ($sRaw === false || $sStrClean === '') {
            return null;
        }
        $s = (float) $sRaw;

        // Parse L value (may have % suffix)
        $lStr = trim($parts[2]);
        $lHasPercent = str_ends_with($lStr, '%');
        $lStrClean = $lHasPercent ? rtrim($lStr, '%') : $lStr;
        $lRaw = filter_var($lStrClean, FILTER_VALIDATE_FLOAT);
        if ($lRaw === false || $lStrClean === '') {
            return null;
        }
        $l = (float) $lRaw;

        return self::color($h, $s, $l);
    }
}
