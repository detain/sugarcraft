<?php

declare(strict_types=1);

namespace CandyCore\Sprinkles;

use CandyCore\Core\Util\Color;

/**
 * Tiny convenience for picking one of two colours based on whether the
 * terminal background is dark. Mirrors lipgloss v2's `LightDark`
 * helper.
 *
 * Typical use:
 *
 *     $isDark = $bgMsg->isDark();
 *     $accent = LightDark::pick($isDark, Color::hex('#005f87'), Color::hex('#5fafd7'));
 *
 * For multi-call ergonomics build a single picker once:
 *
 *     $pick = LightDark::picker($isDark);
 *     $fg   = $pick(Color::hex('#222'), Color::hex('#eee'));
 *     $accent = $pick(Color::hex('#005f87'), Color::hex('#5fafd7'));
 */
final class LightDark
{
    private function __construct() {}

    public static function pick(bool $isDark, Color $light, Color $dark): Color
    {
        return $isDark ? $dark : $light;
    }

    /**
     * Returns a closure that picks `(light, dark)` pairs based on a
     * single captured `isDark` flag.
     *
     * @return \Closure(Color,Color):Color
     */
    public static function picker(bool $isDark): \Closure
    {
        return static fn(Color $light, Color $dark): Color => $isDark ? $dark : $light;
    }
}
