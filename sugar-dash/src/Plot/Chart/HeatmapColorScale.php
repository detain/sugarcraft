<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Chart;

use SugarCraft\Core\Util\Color;

/**
 * Value clamping and low→high color ramp for heatmap renderers.
 *
 * Literal twins extracted from HeatMapChart and Heatmap under E731
 * round-85 per-family follow-through: both copies of each method (and
 * both docblocks) were byte-identical; both consumers are final and
 * interpolateColor() reads only the identical promoted
 * `private readonly ?Color $lowColor/$highColor` pair. Precedent:
 * ChartGridGeometry (r7 `79abe8461`).
 *
 * The adjacent getHeatChar() is NOT here: HeatMapChart buckets on
 * 0.2/0.4/0.6 with literal block glyphs while Heatmap thresholds at
 * 0.25/0.5/0.75 over its own HEAT_BLOCKS constant — genuinely divergent,
 * merging would change rendering.
 */
trait HeatmapColorScale
{
    /**
     * Normalize data to ensure all values are between 0 and 1.
     *
     * @param list<list<float>> $data
     * @return list<list<float>>
     */
    private static function normalizeData(array $data): array
    {
        return array_map(function (array $row): array {
            return array_map(function (float $value): float {
                return max(0.0, min(1.0, $value));
            }, $row);
        }, $data);
    }

    /**
     * Interpolate between two colors based on a ratio.
     */
    private function interpolateColor(float $ratio): ?Color
    {
        if ($this->lowColor === null && $this->highColor === null) {
            return null;
        }

        if ($this->lowColor === null) {
            return $this->highColor;
        }

        if ($this->highColor === null) {
            return $this->lowColor;
        }

        // Simple linear interpolation between colors
        $r1 = $this->lowColor->r;
        $g1 = $this->lowColor->g;
        $b1 = $this->lowColor->b;

        $r2 = $this->highColor->r;
        $g2 = $this->highColor->g;
        $b2 = $this->highColor->b;

        $r = (int) ($r1 + ($r2 - $r1) * $ratio);
        $g = (int) ($g1 + ($g2 - $g1) * $ratio);
        $b = (int) ($b1 + ($b2 - $b1) * $ratio);

        return Color::rgb($r, $g, $b);
    }
}
