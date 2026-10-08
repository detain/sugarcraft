<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Gfx;

use SugarCraft\Top\View\Region;

/**
 * btop `trans()`: every space becomes `Mv::r(1)`, a cursor move, so what is
 * already painted (a graph, a divider line) shows through the gaps of the
 * text written over it.
 *
 * Mirrors aristocratos/btop Tools::trans (btop_tools.cpp).
 */
final class Trans
{
    private function __construct()
    {
    }

    /** Write `$text` at ($x, $y) skipping its spaces; returns the columns spanned. */
    public static function put(Region $region, int $x, int $y, string $text, string $sgr = ''): int
    {
        $chars = mb_str_split($text);
        foreach ($chars as $i => $ch) {
            if ($ch !== ' ') {
                $region->put($x + $i, $y, $ch, $sgr);
            }
        }

        return count($chars);
    }
}
