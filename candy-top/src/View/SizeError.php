<?php

declare(strict_types=1);

namespace SugarCraft\Top\View;

use SugarCraft\Top\Lang;

/**
 * The full-screen "Terminal size too small" notice btop paints instead of
 * the boxes, with the offending dimension red and a satisfied one green.
 * Drawn on the terminal's default background (btop clears with Term::clear,
 * not the theme), at btop's centre offsets.
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

    public static function surface(int $cols, int $rows, int $minWidth, int $minHeight): Surface
    {
        $s = Surface::new($cols, $rows);
        $hw = intdiv($cols, 2);
        $hh = intdiv($rows, 2);
        $width = Lang::t('size.width');
        $height = Lang::t('size.height');

        // btop rows (h/2)-2, (h/2)-1, (h/2)+1, (h/2)+2 are 1-based.
        $s->put(max(0, $hw - 12), $hh - 3, Lang::t('size.too_small'), self::WHITE);
        $s->ansi(
            max(0, $hw - 11),
            $hh - 2,
            ' ' . $width . ' ' . ($cols < $minWidth ? self::RED : self::GREEN) . $cols
                . ' ' . self::WHITE . $height . ' ' . ($rows < $minHeight ? self::RED : self::GREEN) . $rows,
            null,
            self::WHITE,
        );
        $s->put(max(0, $hw - 13), $hh, Lang::t('size.needed'), self::WHITE);
        $s->put(max(0, $hw - 11), $hh + 1, $width . ' ' . $minWidth . ' ' . $height . ' ' . $minHeight, self::WHITE);

        return $s;
    }

    public static function render(int $cols, int $rows, int $minWidth, int $minHeight): string
    {
        return self::surface($cols, $rows, $minWidth, $minHeight)->render();
    }
}
