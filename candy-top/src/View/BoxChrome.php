<?php

declare(strict_types=1);

namespace SugarCraft\Top\View;

use SugarCraft\Sprinkles\Border;

/**
 * btop's box primitive: outline, optional fill, embedded titles.
 *
 * Runes come from a candy-sprinkles {@see Border} (rounded or square), the
 * title junctions from {@see Border::embedJunctions()} — `┐title┌` on the
 * top edge, `┘title└` on the bottom, square even on a rounded box, exactly
 * as btop's Symbols::title_left/right(_down).
 *
 * Positions are btop's absolute offsets (titles at x+2, buttons at fixed
 * columns) rather than Border::withEmbeddedTitle's glued anchors, because
 * btop places several embeds on one edge at independent columns.
 *
 * Mirrors aristocratos/btop Draw::createBox (src/btop_draw.cpp).
 */
final class BoxChrome
{
    private function __construct()
    {
    }

    /**
     * Draw a box outline at `$rect`. `$fill` blanks the interior (btop's
     * main boxes); without it the interior is left as painted (sub-boxes
     * such as the cpu cores box or the net stats box).
     */
    public static function paint(
        Surface $surface,
        Rect $rect,
        string $lineSgr,
        Ink $ink,
        Border $border,
        bool $fill = false,
        string $title = '',
        string $title2 = '',
        int $num = 0,
        bool $tty = false,
    ): void {
        if ($rect->width < 2 || $rect->height < 2) {
            return;
        }
        $x = $rect->x;
        $y = $rect->y;
        $right = $rect->right() - 1;
        $bottom = $rect->bottom() - 1;
        $run = str_repeat($border->top, $rect->width - 2);

        $surface->put($x, $y, $border->topLeft . $run . $border->topRight, $lineSgr, $rect);
        $surface->put($x, $bottom, $border->bottomLeft . str_repeat($border->bottom, $rect->width - 2) . $border->bottomRight, $lineSgr, $rect);
        for ($row = $y + 1; $row < $bottom; $row++) {
            $surface->put($x, $row, $border->left, $lineSgr, $rect);
            $surface->put($right, $row, $border->right, $lineSgr, $rect);
        }
        if ($fill && $rect->width > 2 && $rect->height > 2) {
            $surface->fill(Rect::new($x + 1, $y + 1, $rect->width - 2, $rect->height - 2));
        }

        $numbering = $num === 0 ? '' : $ink->fg('hi_fg') . Symbols::number($num, $tty);
        if ($title !== '') {
            self::embed($surface, $x + 2, $y, Symbols::BOLD . $numbering . $ink->fg('title') . $title, $lineSgr, $border, false, $rect);
        }
        if ($title2 !== '') {
            self::embed($surface, $x + 2, $bottom, Symbols::BOLD . $numbering . $ink->fg('title') . $title2, $lineSgr, $border, true, $rect);
        }
    }

    /**
     * Embed `$inner` (may carry SGR) into a border run at ($x, $y):
     * junction, text, junction — the `┐menu┌` idiom. Clipped to `$clip`'s
     * columns minus its corners, so an over-long embed is cut rather than
     * eating the box corner or a neighbour.
     *
     * @return int columns written including both junctions
     */
    public static function embed(
        Surface $surface,
        int $x,
        int $y,
        string $inner,
        string $lineSgr,
        Border $border,
        bool $bottom = false,
        ?Rect $clip = null,
    ): int {
        $clip ??= $surface->bounds();
        $edge = Rect::new($clip->x + 1, $y, max(0, $clip->width - 2), 1);
        [$open, $close] = $border->embedJunctions($bottom);
        $col = $x;
        $col += $surface->put($col, $y, $open, $lineSgr, $edge);
        $col += $surface->ansi($col, $y, $inner, $edge);
        $col += $surface->put($col, $y, $close, $lineSgr, $edge);

        return $col - $x;
    }
}
