<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Chart;

/**
 * Human-readable axis-label formatting (M / K / integer / one-decimal).
 *
 * Moved out of ChartGridGeometry under E731 round-85 per-family
 * follow-through: the r7 extraction paired only Chart and Area, but
 * Bubble and Funnel carry a third and fourth copy verbatim under the
 * private name formatValue. The canonical name stays formatYLabel.
 * ChartGridGeometry now composes this trait, so Chart and Area keep
 * resolving formatYLabel transitively and are untouched; Bubble and
 * Funnel use it directly with their call sites repointed.
 *
 * A trait rather than a base class: every consumer is final and the
 * helper is pure — no shared state (AGENTS.md finality rule; precedent
 * ChartGridGeometry r7 `79abe8461`).
 *
 * GaugeChart's formatValue() is NOT a twin and stays put: it takes no
 * argument at all and formats via a match on the chart's own $format
 * template — only the name collides.
 */
trait AxisLabelFormatter
{
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
