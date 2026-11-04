<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Gfx;

use SugarCraft\Top\View\Ink;

/**
 * btop's one-row percent bar: every `■` is coloured at ITS OWN position on
 * the gradient, the unfilled tail in `meter_bg`.
 *
 * Same law as sugar-dashboard's position-mode `Meter` (whose bytes the test
 * suite compares against), but resolved through {@see Ink}: the dash
 * Meter always emits TrueColor escapes, which would put 24-bit colour on
 * a TTY-mode (16-colour) frame.
 *
 * Where the profile cannot carry colour at all (NO_COLOR → Ascii,
 * TERM=dumb → NoTty) every {@see Ink} escape resolves to the empty string,
 * and a bar of ten plain `■` reads as 100 % whatever the value is. So the
 * fill/empty channel that colour was carrying moves into the glyphs:
 * full-block filled, light-shade empty. The two tiers render the same
 * shape, just through different contrast.
 *
 * Mirrors aristocratos/btop Draw::Meter::operator() (btop_draw.cpp:403-419).
 */
final class PositionMeter
{
    public const GLYPH = '■';

    /** Filled cell when the profile has no colour to distinguish it with. */
    public const MONO_FILLED = '█';

    /** Unfilled cell of the same, for the same reason. */
    public const MONO_EMPTY = '░';

    private function __construct()
    {
    }

    public static function render(Ink $ink, int $width, int|float $value, string $gradient, bool $invert = false): string
    {
        if ($width < 1) {
            return '';
        }
        $value = max(0, min(100, (int) round($value)));
        // Under a colourless profile every escape below resolves to '', so a
        // run of bare GLYPH would read as 100% at any value; the glyph itself
        // carries the fill there.
        $mono = !$ink->profile()->supportsAnsi();
        $out = '';
        for ($i = 1; $i <= $width; $i++) {
            $y = (int) round($i * 100 / $width);
            if ($value >= $y) {
                $out .= $mono
                    ? self::MONO_FILLED
                    : $ink->gradient($gradient, $invert ? 100 - $y : $y) . self::GLYPH;
            } else {
                $out .= $mono
                    ? str_repeat(self::MONO_EMPTY, $width + 1 - $i)
                    : $ink->fg('meter_bg') . str_repeat(self::GLYPH, $width + 1 - $i);
                break;
            }
        }

        return $out . "\x1b[0m";
    }
}
