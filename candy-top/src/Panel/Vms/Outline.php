<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Vms;

use SugarCraft\Sprinkles\Border;
use SugarCraft\Top\View\BorderFlow;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Region;

/**
 * Card outlines and `┐text┌` embeds drawn into a Region in the VM
 * dashboard's flat line colour (`<family>_box`, {@see FrameBuilder::VMS_FAMILY}).
 * {@see BorderFlow} maps the `vms` box to that family, so its post-pass
 * sweeps the theme's outline flow over the whole dashboard — the outer
 * box and every card as one gradient — exactly as for the other boxes. A
 * selected card is drawn in an override colour (hi_fg), which the sweep
 * leaves alone.
 */
final class Outline
{
    private function __construct(
        private readonly string $line,
    ) {
    }

    public static function new(string $lineSgr): self
    {
        return new self($lineSgr);
    }

    /** Outline `$rect` (box-local) in the line colour, or all in `$override`. */
    public function box(Region $r, Rect $rect, Border $border, ?string $override = null): void
    {
        if ($rect->width < 2 || $rect->height < 2) {
            return;
        }
        $sgr = $override ?? $this->line;
        $right = $rect->right() - 1;
        $bottom = $rect->bottom() - 1;
        $run = $rect->width - 2;
        $r->put($rect->x, $rect->y, $border->topLeft . str_repeat($border->top, $run) . $border->topRight, $sgr);
        $r->put($rect->x, $bottom, $border->bottomLeft . str_repeat($border->bottom, $run) . $border->bottomRight, $sgr);
        for ($y = $rect->y + 1; $y < $bottom; $y++) {
            $r->put($rect->x, $y, $border->left, $sgr);
            $r->put($right, $y, $border->right, $sgr);
        }
    }

    /**
     * `┐inner┌` on the top (or bottom) edge of `$clip` at box-local ($x,
     * $y), junctions in the line colour, cut to the edge between the
     * corners like BoxChrome::embed. Returns the cells it spans.
     */
    public function embed(Region $r, Rect $clip, int $x, int $y, string $inner, Border $border, bool $bottom = false, ?string $override = null): int
    {
        $edgeX = $clip->x + 1;
        $edge = $r->sub(Rect::new($edgeX, $y, max(0, $clip->width - 2), 1));
        [$open, $close] = $border->embedJunctions($bottom);
        $sgr = $override ?? $this->line;
        $col = $x;
        $col += $edge->put($col - $edgeX, 0, $open, $sgr);
        $col += $edge->ansi($col - $edgeX, 0, $inner);
        $col += $edge->put($col - $edgeX, 0, $close, $sgr);

        return $col - $x;
    }
}
