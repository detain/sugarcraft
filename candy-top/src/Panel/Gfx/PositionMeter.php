<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Gfx;

use SugarCraft\Top\View\Ink;

/**
 * btop's one-row percent bar: every `■` is coloured at ITS OWN position on
 * the gradient, the unfilled tail in `meter_bg`.
 *
 * Same law as sugar-dash's position-mode `Meter` (whose bytes the test
 * suite compares against), but resolved through {@see Ink}: the dash
 * Meter always emits TrueColor escapes, which would put 24-bit colour on
 * a TTY-mode (16-colour) frame.
 *
 * Mirrors aristocratos/btop Draw::Meter::operator() (btop_draw.cpp:403-419).
 */
final class PositionMeter
{
    public const GLYPH = '■';

    private function __construct()
    {
    }

    public static function render(Ink $ink, int $width, int|float $value, string $gradient, bool $invert = false): string
    {
        if ($width < 1) {
            return '';
        }
        $value = max(0, min(100, (int) round($value)));
        $out = '';
        for ($i = 1; $i <= $width; $i++) {
            $y = (int) round($i * 100 / $width);
            if ($value >= $y) {
                $out .= $ink->gradient($gradient, $invert ? 100 - $y : $y) . self::GLYPH;
            } else {
                $out .= $ink->fg('meter_bg') . str_repeat(self::GLYPH, $width + 1 - $i);
                break;
            }
        }

        return $out . "\x1b[0m";
    }
}
