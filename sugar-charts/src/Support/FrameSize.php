<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Support;

use SugarCraft\Core\Util\Width;

/**
 * Honest rendered-frame measurement (sugar-charts-010/-096).
 *
 * Composed charts widen past their declared box by the gutters the
 * caller opts into (legend sidecar, y-label column), so the declared
 * width alone is a lie once extras ride. intrinsicSize() reports the
 * TRUE [w, h] of the composed output; this is that measurement for
 * any multi-row view string.
 */
final class FrameSize
{
    /**
     * @return array{0: int, 1: int} [width, height] in terminal cells
     */
    public static function of(string $view): array
    {
        if ($view === '') {
            return [0, 0];
        }

        $rows   = explode("\n", $view);
        $widest = 0;
        foreach ($rows as $row) {
            $w = Width::string($row);
            if ($w > $widest) {
                $widest = $w;
            }
        }

        return [$widest, count($rows)];
    }

    private function __construct()
    {
    }
}
