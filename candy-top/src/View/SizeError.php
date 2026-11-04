<?php

declare(strict_types=1);

namespace SugarCraft\Top\View;

use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\Lang;

/**
 * The full-screen "Terminal size too small" notice btop paints instead of
 * the boxes, with the offending dimension red and a satisfied one green.
 * Drawn on the terminal's default background (btop clears with Term::clear,
 * not the theme), at btop's centre offsets.
 *
 * Given a profile that cannot carry colour at all (NO_COLOR resolves to
 * Ascii, TERM=dumb to NoTty) the notice is plain text instead: the red is the
 * whole decoration here, and the same escapes written to a terminal that
 * declares it cannot render them are an insult to the frame they replace.
 * Null profile — every caller that has no ink to consult — keeps btop's bytes
 * exactly as they were.
 *
 * Mirrors aristocratos/btop term_resize (src/btop.cpp).
 */
final class SizeError
{
    /** btop's Global::fg_white / fg_green / fg_red. */
    public const WHITE = "\x1b[1;97m";

    public const GREEN = "\x1b[1;92m";

    public const RED = "\x1b[1;91m";

    private function __construct()
    {
    }

    public static function surface(int $cols, int $rows, int $minWidth, int $minHeight, ?ColorProfile $profile = null): Surface
    {
        $mono = $profile !== null && !$profile->supportsAnsi();
        $white = $mono ? '' : self::WHITE;
        $verdict = static fn (bool $tooSmall): string => $mono ? '' : ($tooSmall ? self::RED : self::GREEN);

        $s = Surface::new($cols, $rows);
        $hw = intdiv($cols, 2);
        $hh = intdiv($rows, 2);
        $width = Lang::t('size.width');
        $height = Lang::t('size.height');

        // btop rows (h/2)-2, (h/2)-1, (h/2)+1, (h/2)+2 are 1-based.
        $s->put(max(0, $hw - 12), $hh - 3, Lang::t('size.too_small'), $white);
        $s->ansi(
            max(0, $hw - 11),
            $hh - 2,
            ' ' . $width . ' ' . $verdict($cols < $minWidth) . $cols
                . ' ' . $white . $height . ' ' . $verdict($rows < $minHeight) . $rows,
            null,
            $white,
        );
        $s->put(max(0, $hw - 13), $hh, Lang::t('size.needed'), $white);
        $s->put(max(0, $hw - 11), $hh + 1, $width . ' ' . $minWidth . ' ' . $height . ' ' . $minHeight, $white);

        return $s;
    }

    public static function render(int $cols, int $rows, int $minWidth, int $minHeight, ?ColorProfile $profile = null): string
    {
        return self::surface($cols, $rows, $minWidth, $minHeight, $profile)->render();
    }
}
