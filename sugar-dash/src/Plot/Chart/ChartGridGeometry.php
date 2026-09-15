<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Chart;

/**
 * Shared Y-axis grid math for chart components.
 *
 * Literal-twin pair extracted from Chart.php and Area.php (both copies
 * were byte-identical; the Area copy was named formatValue) under E731
 * as the one dedup the round-83 design probe justified. A trait rather
 * than a base class because both consumers are final (AGENTS.md: public
 * classes final unless extension is contract) and share NO state — both
 * helpers are pure functions of their arguments.
 *
 * The sibling helpers named in the E731 survey are NOT here on purpose:
 * getChartWidth() diverges in its unallocated fallback (explicit width
 * constraint vs a data-count heuristic) and getChartHeight() is a
 * one-line ?? over differently named private props — merging those
 * would change behavior or rename private state to feed the trait.
 */
trait ChartGridGeometry
{
    /**
     * Generate grid line values.
     *
     * @return list<float>
     */
    private function generateGridLines(float $min, float $max, int $height): array
    {
        $lines = [];
        $step = ($max - $min) / max(1, $height - 1);

        for ($i = 0; $i < $height; $i++) {
            $lines[] = $min + ($step * $i);
        }

        return $lines;
    }

    /**
     * Format a Y-axis label.
     */
    private function formatYLabel(float $value): string
    {
        if (abs($value) >= 1000000) {
            return sprintf('%.1fM', $value / 1000000);
        }
        if (abs($value) >= 1000) {
            return sprintf('%.1fK', $value / 1000);
        }
        if ($value === floor($value)) {
            return sprintf('%.0f', $value);
        }
        return sprintf('%.1f', $value);
    }
}
