<?php

declare(strict_types=1);

namespace SugarCraft\Top\View;

/**
 * The glyphs btop's chrome draws with that are not border runes (those come
 * from sugar-style {@see \SugarCraft\Style\Border}).
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

    /** The selector arrows when `force_tty` is on: no line glyph is trusted. */
    public const LEFT_TTY = '<';

    public const RIGHT_TTY = '>';

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

    /**
     * One target-selector arrow, ASCII under `force_tty` like every other
     * decoration the frame draws. `$tty` is the resolved tty mode, not the
     * colour profile: a 16-colour session still gets the line arrows.
     */
    public static function arrow(bool $right, bool $tty): string
    {
        if ($tty) {
            return $right ? self::RIGHT_TTY : self::LEFT_TTY;
        }

        return $right ? self::RIGHT : self::LEFT;
    }
}
