<?php

declare(strict_types=1);

namespace SugarCraft\Top\View;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;

/**
 * The block-letter logo the main and help menus draw above themselves —
 * btop's `Draw::banner_gen`, with candy-top's name set in btop's own
 * "ANSI Shadow" face and btop's six row colours (Global::Banner_src).
 *
 * Per row: the solid `█` cells take the row colour, the shadow strokes a
 * grey that darkens row by row (`120 - row * 12`), and spaces are skipped
 * (btop's `Mv::r(1)`), so the dimmed frame shows through between letters.
 * In tty mode the colours are btop's fixed 16-colour pairs.
 *
 * Deviation: btop prints `v<version>` after the last row; candy-top has no
 * release version of its own (and btop's would mislabel it), so the
 * version line is omitted.
 *
 * Mirrors aristocratos/btop Draw::banner_gen (src/btop_draw.cpp:138-177).
 */
final class Banner
{
    /** btop Global::Banner_src row colours. */
    public const COLORS = ['#E62525', '#CD2121', '#B31D1D', '#9A1919', '#801414', '#000000'];

    public const LINES = [
        ' ██████╗ █████╗ ███╗   ██╗██████╗ ██╗   ██╗ ████████╗ ██████╗ ██████╗ ',
        '██╔════╝██╔══██╗████╗  ██║██╔══██╗╚██╗ ██╔╝ ╚══██╔══╝██╔═══██╗██╔══██╗',
        '██║     ███████║██╔██╗ ██║██║  ██║ ╚████╔╝     ██║   ██║   ██║██████╔╝',
        '██║     ██╔══██║██║╚██╗██║██║  ██║  ╚██╔╝      ██║   ██║   ██║██╔═══╝ ',
        '╚██████╗██║  ██║██║ ╚████║██████╔╝   ██║       ██║   ╚██████╔╝██║     ',
        ' ╚═════╝╚═╝  ╚═╝╚═╝  ╚═══╝╚═════╝    ╚═╝       ╚═╝    ╚═════╝ ╚═╝     ',
    ];

    private function __construct()
    {
    }

    public static function width(): int
    {
        return max(array_map(static fn (string $l): int => Width::string($l), self::LINES));
    }

    public static function height(): int
    {
        return count(self::LINES);
    }

    /**
     * Paint the banner with its top row at 0-based `$y`, centred on the
     * surface (btop `centered`: `Term::width / 2 - width / 2`, 1-based).
     */
    public static function paint(Surface $surface, int $y, Ink $ink, bool $tty): void
    {
        $x = intdiv($surface->width, 2) - intdiv(self::width(), 2) - 1;
        $profile = $ink->profile();
        foreach (self::LINES as $z => $line) {
            if ($tty) {
                $fg = $z > 2 ? "\x1b[31m" : "\x1b[91m";
                $shade = $z > 2 ? "\x1b[90m" : "\x1b[37m";
            } else {
                $fg = Color::hex(self::COLORS[$z])->toFg($profile);
                $grey = 120 - $z * 12;
                $shade = Color::rgb($grey, $grey, $grey)->toFg($profile);
            }
            $col = $x;
            foreach (mb_str_split($line) as $glyph) {
                if ($glyph !== ' ') {
                    $surface->put($col, $y + $z, $glyph, $glyph === '█' ? $fg : $shade);
                }
                $col++;
            }
        }
    }
}
