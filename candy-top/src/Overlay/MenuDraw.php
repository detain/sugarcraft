<?php

declare(strict_types=1);

namespace SugarCraft\Top\Overlay;

use SugarCraft\Core\Util\Width;
use SugarCraft\Top\Panel\Gfx\Just;
use SugarCraft\Top\View\BoxChrome;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Surface;

/**
 * btop menu drawing primitives over a {@see Surface}, taking btop's
 * 1-BASED `Mv::to(line, col)` coordinates so each menu ports its offsets
 * verbatim (the conversion to 0-based cells happens only here).
 *
 * Mirrors aristocratos/btop Draw::createBox and Tools::rjust / ljust /
 * cjust (`limit = true`) as the menus call them.
 */
final class MenuDraw
{
    public const BOLD = "\x1b[1m";

    public const UNBOLD = "\x1b[22m";

    public const BLINK = "\x1b[5m";

    public const UNBLINK = "\x1b[25m";

    public const RESET = "\x1b[0m";

    private function __construct()
    {
    }

    /**
     * btop `Draw::createBox(x, y, width, height, Theme::c("hi_fg"), true,
     * title)`: a filled outline in hi_fg with a bold title at x + 2.
     */
    public static function box(Surface $s, OverlayContext $c, int $x, int $y, int $width, int $height, string $title): void
    {
        BoxChrome::paint(
            $s,
            Rect::new($x - 1, $y - 1, $width, $height),
            $c->ink->fg('hi_fg'),
            $c->ink,
            $c->border(),
            fill: true,
            title: $title,
            tty: $c->tty(),
        );
    }

    /**
     * Write SGR-carrying `$text` at btop's 1-based (`$line`, `$col`);
     * returns the columns advanced.
     */
    public static function at(Surface $s, int $line, int $col, string $text, string $sgr = '', ?Rect $clip = null): int
    {
        return $s->ansi($col - 1, $line - 1, $text, $clip, $sgr);
    }

    /** The 0-based interior of a btop box at 1-based (`$x`, `$y`) — the clip for text a translation could lengthen. */
    public static function inside(int $x, int $y, int $width, int $height): Rect
    {
        return Rect::new($x, $y, max(0, $width - 2), max(0, $height - 2));
    }

    /** btop rjust(str, x) with `limit`: padded left, an overlong value cut. */
    public static function rjust(string $text, int $width): string
    {
        return Just::right($text, $width);
    }

    /** btop ljust(str, x) with `limit`. */
    public static function ljust(string $text, int $width): string
    {
        return Just::left($text, $width);
    }

    /** btop cjust(str, x) with `limit`: centred, the odd cell on the LEFT. */
    public static function cjust(string $text, int $width): string
    {
        $len = Width::string($text);
        if ($len > $width) {
            return Width::truncate($text, max(0, $width));
        }

        return str_repeat(' ', (int) ceil(($width - $len) / 2)) . $text . str_repeat(' ', intdiv($width - $len, 2));
    }

    /** btop uresize(str, len): cut to `$width` cells ('' for a width below 1). */
    public static function cut(string $text, int $width): string
    {
        return $width < 1 ? '' : Width::truncate($text, $width);
    }

    /** Control characters stripped from untrusted text (process names) before it is drawn. */
    public static function clean(string $text): string
    {
        return (string) preg_replace('/[\x00-\x1f\x7f]|\xc2[\x80-\x9f]/', ' ', mb_scrub($text, 'UTF-8'));
    }
}
