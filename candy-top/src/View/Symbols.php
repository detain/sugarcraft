<?php

declare(strict_types=1);

namespace SugarCraft\Top\View;

/**
 * The glyphs btop's chrome draws with that are not border runes (those come
 * from candy-sprinkles {@see \SugarCraft\Sprinkles\Border}).
 *
 * Mirrors aristocratos/btop Symbols (src/btop_draw.cpp).
 */
final class Symbols
{
    /** Box-numbering superscripts; TTY mode prints the plain digit instead. */
    public const SUPERSCRIPT = ['⁰', '¹', '²', '³', '⁴', '⁵', '⁶', '⁷', '⁸', '⁹'];

    public const H_LINE = '─';

    public const V_LINE = '│';

    /** btop Symbols::left / right — the gpu box target selector arrows (PR #1730). */
    public const LEFT = '←';

    public const RIGHT = '→';

    /** Bold on / off, btop Fx::b / Fx::ub. */
    public const BOLD = "\x1b[1m";

    public const UNBOLD = "\x1b[22m";

    private function __construct()
    {
    }

    /** The box number as btop's createBox prints it. */
    public static function number(int $num, bool $tty): string
    {
        $num = max(0, min(9, $num));

        return $tty ? (string) $num : self::SUPERSCRIPT[$num];
    }
}
