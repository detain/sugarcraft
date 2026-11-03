<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Chart;

use SugarCraft\Charts\Legend\Legend;
use SugarCraft\Core\Util\Width;

/**
 * Shared legend / title / axis-label composition logic.
 *
 * Shared composition logic for the renderers that do not extend Chart
 * (BarChart, Scatter, OHLCChart); Chart carries a private fork of the same
 * layout. Each using class supplies the property getters this trait's
 * methods reference.
 */
trait ChartExtras
{
    /** yLabel rides every row; cap it to width / this divisor so a long label cannot multiply out the canvas. */
    private const Y_LABEL_WIDTH_FRACTION_DIVISOR = 4;

    /** @param list<string> $chart */
    protected function buildChartWithExtras(string $chart): array
    {
        $lines = $chart !== '' ? explode("\n", $chart) : [];

        if ($this->chartExtrasShowLegend() && $this->chartExtrasGetLegendItems() !== []) {
            $legend = $this->chartExtrasBuildLegend();
            $lines = $this->chartExtrasMergeLegend($lines, $legend);
        }

        if ($this->chartExtrasGetTitle() !== null) {
            $lines = $this->chartExtrasAddTitle($lines);
        }

        if ($this->chartExtrasGetYLabel() !== null) {
            $lines = $this->chartExtrasAddYLabel($lines);
        }

        if ($this->chartExtrasGetXLabel() !== null) {
            $lines[] = Width::truncate($this->chartExtrasGetXLabel(), $this->chartExtrasGetWidth());
        }

        return $lines;
    }

    protected function chartExtrasBuildLegend(): Legend
    {
        $legend = Legend::new($this->chartExtrasGetLegendItems())
            ->withPosition($this->chartExtrasGetLegendPosition());

        if ($this->chartExtrasGetLegendIndicatorChar() !== null) {
            $legend = $legend->withIndicatorChar($this->chartExtrasGetLegendIndicatorChar());
        }

        return $legend;
    }

    /** @param list<string> $chartLines @param list<string> $legendLines */
    protected function chartExtrasMergeLegend(array $chartLines, Legend $legend): array
    {
        $legendLines = explode("\n", $legend->view());

        return match ($this->chartExtrasGetLegendPosition()) {
            Position::Top    => [...$legendLines, ...$chartLines],
            Position::Bottom => [...$chartLines, ...$legendLines],
            Position::Left   => $this->chartExtrasMergeLegendLeftRight($chartLines, $legendLines),
            Position::Right  => $this->chartExtrasMergeLegendLeftRight($chartLines, $legendLines, true),
        };
    }

    /**
     * @param list<string> $chartLines
     * @param list<string> $legendLines
     * @return list<string>
     */
    protected function chartExtrasMergeLegendLeftRight(array $chartLines, array $legendLines, bool $legendOnRight = false): array
    {
        $maxHeight = max(count($chartLines), count($legendLines));
        $result = [];

        for ($i = 0; $i < $maxHeight; $i++) {
            $chartLine = $chartLines[$i] ?? '';
            $legendLine = $legendLines[$i] ?? '';

            if ($legendOnRight) {
                $result[] = Width::padRight($chartLine, $this->chartExtrasGetWidth()) . ' ' . $legendLine;
            } else {
                $result[] = $legendLine . ' ' . Width::padRight($chartLine, $this->chartExtrasGetWidth());
            }
        }

        return $result;
    }

    /** @param list<string> $lines */
    protected function chartExtrasAddTitle(array $lines): array
    {
        $title = $this->chartExtrasGetTitle();
        $centered = Width::padCenter(Width::truncate($title, $this->chartExtrasGetWidth()), $this->chartExtrasGetWidth());

        return match ($this->chartExtrasGetTitlePosition()) {
            Position::Top    => [$centered, ...$lines],
            Position::Bottom => [...$lines, $centered],
            Position::Left, Position::Right => $lines,
        };
    }

    /** @param list<string> $lines */
    protected function chartExtrasAddYLabel(array $lines): array
    {
        $budget = max(1, intdiv($this->chartExtrasGetWidth(), self::Y_LABEL_WIDTH_FRACTION_DIVISOR));
        $label = Width::truncate($this->chartExtrasGetYLabel(), $budget);

        return array_map(
            fn(string $line) => $label . ' ' . $line,
            $lines,
        );
    }

    // ─── Property Getters (must be implemented by using class) ────────────

    abstract protected function chartExtrasGetWidth(): int;
    abstract protected function chartExtrasShowLegend(): bool;
    /** @return list<array{label: string, color: string}> */
    abstract protected function chartExtrasGetLegendItems(): array;
    abstract protected function chartExtrasGetLegendPosition(): Position;
    abstract protected function chartExtrasGetLegendIndicatorChar(): ?string;
    abstract protected function chartExtrasGetTitle(): ?string;
    abstract protected function chartExtrasGetTitlePosition(): Position;
    abstract protected function chartExtrasGetXLabel(): ?string;
    abstract protected function chartExtrasGetYLabel(): ?string;
}
