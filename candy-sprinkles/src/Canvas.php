<?php

declare(strict_types=1);

namespace CandyCore\Sprinkles;

use CandyCore\Core\Util\Width as WidthUtil;

/**
 * Multi-layer compositor — port of Bubble Tea v2's `lipgloss.Canvas`.
 *
 * The Canvas takes a stack of {@see Layer} instances, each with its
 * own (x, y) position and z-index, and composes them into a single
 * rendered string. The first layer (lowest z) is the base; later
 * layers paint on top at their declared positions.
 *
 * Use this for pop-overs, modal dialogs, hover tooltips, floating
 * status indicators — anything that needs to overlay on a base
 * view without disturbing the existing rendered text. Cell-aware:
 * each layer's lines are cut into base rows at the exact column
 * (`$x`) and row (`$y`) declared, with ANSI escape sequences
 * preserved on both sides of the cut.
 *
 * Example:
 * ```php
 * $base    = Layer::new($mainView);
 * $popover = Layer::new($dialog)->withX(20)->withY(5)->withZ(1);
 *
 * echo Canvas::new()->addLayer($base)->addLayer($popover)->render();
 * ```
 *
 * Z-ties are broken by insertion order (later wins). The output is
 * a single string with `\n` separators — drop straight into a
 * {@see \CandyCore\Core\Renderer} or `echo` directly.
 */
final class Canvas
{
    /** @var list<Layer> */
    private array $layers = [];

    public static function new(): self
    {
        return new self();
    }

    public function addLayer(Layer $l): self
    {
        $copy = clone $this;
        $copy->layers[] = $l;
        return $copy;
    }

    /**
     * Compose all layers and return the rendered string.
     *
     * The compose loop:
     *
     *   1. Sort layers by z (stable; insertion order breaks ties).
     *   2. Take the bottom layer's grid as the canvas baseline,
     *      growing it as later layers extend below or beyond.
     *   3. For each subsequent layer, paste its lines into the
     *      canvas grid at (x, y), splicing each base row with
     *      {@see \CandyCore\Core\Util\Width::truncateAnsi()} +
     *      {@see \CandyCore\Core\Util\Width::dropAnsi()} so the
     *      cell counts line up regardless of ANSI / wide-character
     *      content.
     */
    public function render(): string
    {
        if ($this->layers === []) {
            return '';
        }
        $sorted = $this->layers;
        usort($sorted, static fn(Layer $a, Layer $b) => $a->z <=> $b->z);

        $canvas = [];
        foreach ($sorted as $layer) {
            $lines = $layer->lines();
            $h = count($lines);
            $w = $layer->width();
            for ($i = 0; $i < $h; $i++) {
                $row = $layer->y + $i;
                if ($row < 0) {
                    continue;
                }
                $line = $lines[$i];
                $existing = $canvas[$row] ?? null;
                $canvas[$row] = self::pasteRow($existing, $layer->x, $w, $line);
            }
        }

        ksort($canvas);
        $maxRow = empty($canvas) ? 0 : max(array_keys($canvas));
        $out = [];
        for ($r = 0; $r <= $maxRow; $r++) {
            $out[] = $canvas[$r] ?? '';
        }
        return implode("\n", $out);
    }

    /**
     * Splice an overlay run of width `$overlayW` cells into the
     * existing row at column `$x`. The row is space-padded out to
     * `$x` if it's shorter; ANSI sequences in both base and overlay
     * are preserved across the cut.
     */
    private static function pasteRow(?string $base, int $x, int $overlayW, string $overlay): string
    {
        if ($base === null || $base === '') {
            return str_repeat(' ', max(0, $x)) . $overlay;
        }
        $baseW = WidthUtil::string($base);
        if ($x >= $baseW) {
            return $base . str_repeat(' ', $x - $baseW) . $overlay;
        }
        $left  = WidthUtil::truncateAnsi($base, $x);
        $right = WidthUtil::dropAnsi($base, $x + $overlayW);
        // Reset SGR state at the boundaries so neither side bleeds
        // into the other. Cheap, consistent, and matches lipgloss/v2
        // Canvas semantics. Skip resets when there's no neighbour.
        $leftSep  = $left  === '' ? '' : "\x1b[0m";
        $rightSep = $right === '' ? '' : "\x1b[0m";
        return $left . $leftSep . $overlay . $rightSep . $right;
    }
}
