<?php

declare(strict_types=1);

namespace SugarCraft\Top\View;

use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
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
        $tooSmall = Lang::t('size.too_small');
        $needed = Lang::t('size.needed');

        // btop rows (h/2)-2, (h/2)-1, (h/2)+1, (h/2)+2 are 1-based.
        $notice = [
            [$hw - 12, $tooSmall],
            [$hw - 11, ' ' . $width . ' ' . $cols . ' ' . $height . ' ' . $rows],
            [$hw - 13, $needed],
            [$hw - 11, $width . ' ' . $minWidth . ' ' . $height . ' ' . $minHeight],
        ];
        foreach ($notice as [$x, $text]) {
            if ($x >= 0 && $x + Width::string($text) <= $cols) {
                continue;
            }

            // The notice is wider than the terminal that must carry it —
            // btop's offsets would clamp to column 0 and clip the words into
            // meaningless fragments (#101). Fall back to the compact core:
            // the two verdict numbers and what they must reach, stacked so
            // the hint stays actionable down to five columns.
            return self::compact($s, $cols, $rows, $hw, $hh, $width, $height, $minWidth, $minHeight, $verdict, $white);
        }

        $s->put($hw - 12, $hh - 3, $tooSmall, $white);
        $s->ansi(
            $hw - 11,
            $hh - 2,
            ' ' . $width . ' ' . $verdict($cols < $minWidth) . $cols
                . ' ' . $white . $height . ' ' . $verdict($rows < $minHeight) . $rows,
            null,
            $white,
        );
        $s->put($hw - 13, $hh, $needed, $white);
        $s->put($hw - 11, $hh + 1, $width . ' ' . $minWidth . ' ' . $height . ' ' . $minHeight, $white);

        return $s;
    }

    /**
     * The actionable core of the notice, centred: each dimension's first
     * label letter, its current value in the verdict colour, and the minimum
     * it must reach. Two paired lines when they fit, one stacked quartet when
     * the terminal is narrower still.
     */
    private static function compact(
        Surface $s,
        int $cols,
        int $rows,
        int $hw,
        int $hh,
        string $width,
        string $height,
        int $minWidth,
        int $minHeight,
        callable $verdict,
        string $white,
    ): Surface {
        $centre = static fn (string $text): int => max(0, intdiv($cols - Width::string($text), 2));

        $curW = mb_substr(trim($width), 0, 1) . ' ' . $verdict($cols < $minWidth) . $cols;
        $curH = mb_substr(trim($height), 0, 1) . ' ' . $verdict($rows < $minHeight) . $rows;
        $needW = '>=' . $minWidth;
        $needH = '>=' . $minHeight;

        if (Width::string($curW . ' ' . $needW) <= $cols && Width::string($curH . ' ' . $needH) <= $cols) {
            $s->ansi($centre($curW . ' ' . $needW), max(0, $hh - 1), $curW . ' ' . $needW, null, $white);
            $s->ansi($centre($curH . ' ' . $needH), max(0, $hh), $curH . ' ' . $needH, null, $white);

            return $s;
        }

        $y = max(0, min($hh - 2, $rows - 4));
        $s->ansi($centre($curW), $y, $curW, null, $white);
        $s->put($centre($needW), $y + 1, $needW, $white);
        $s->ansi($centre($curH), $y + 2, $curH, null, $white);
        $s->put($centre($needH), $y + 3, $needH, $white);

        return $s;
    }

    public static function render(int $cols, int $rows, int $minWidth, int $minHeight, ?ColorProfile $profile = null): string
    {
        return self::surface($cols, $rows, $minWidth, $minHeight, $profile)->render();
    }
}
