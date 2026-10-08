<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Gfx;

use SugarCraft\Core\Util\Width;

/**
 * btop's `rjust` / `ljust` with their default `limit = true`: pad to
 * `$width` cells, and CUT a longer string to its first `$width` cells
 * (so an `8.94 GiB` in a 7-cell slot prints `8.94 Gi`, never spilling
 * into the divider). `str_pad` never truncates, which is the trap.
 *
 * A NEGATIVE width is btop's `size_t` wrap-around (`rjust(s, mem_width - 9)`
 * in a very narrow box): the limit is then astronomically large, so the
 * text prints whole and unpadded — the Region clips anything past the box.
 * Width 0 cuts to "" exactly as btop's `str.resize(0)` does.
 *
 * Mirrors aristocratos/btop Tools::rjust / Tools::ljust (btop_tools.cpp:346-378).
 */
final class Just
{
    private function __construct()
    {
    }

    public static function right(string $text, int $width): string
    {
        if ($width < 0) {
            return $text;
        }
        $text = Width::truncate($text, $width);

        return str_repeat(' ', max(0, $width - Width::string($text))) . $text;
    }

    public static function left(string $text, int $width): string
    {
        if ($width < 0) {
            return $text;
        }

        return Width::padRight(Width::truncate($text, $width), $width);
    }
}
