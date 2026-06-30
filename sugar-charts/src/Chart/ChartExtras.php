<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Chart;

use SugarCraft\Charts\Legend\Legend;

/**
 * Shared legend / title / axis-label composition logic.
 *
 * Extracted from Chart (base class) and duplicated verbatim in
 * BarChart, Scatter, and OHLCChart. Each using class must
 * provide the property getters that this trait's methods reference.
 */
trait ChartExtras
{
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
            $lines[] = $this->chartExtrasGetXLabel();
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
                $result[] = str_pad($chartLine, $this->chartExtrasGetWidth(), ' ', STR_PAD_RIGHT) . ' ' . $legendLine;
            } else {
                $result[] = $legendLine . ' ' . str_pad($chartLine, $this->chartExtrasGetWidth(), ' ', STR_PAD_RIGHT);
            }
        }

        return $result;
    }

    /** @param list<string> $lines */
    protected function chartExtrasAddTitle(array $lines): array
    {
        $title = $this->chartExtrasGetTitle();
        $titleLen = mb_strlen($title, 'UTF-8');
        $centered = str_pad($title, $this->chartExtrasGetWidth(), ' ', STR_PAD_BOTH);

        return match ($this->chartExtrasGetTitlePosition()) {
            Position::Top    => [$centered, ...$lines],
            Position::Bottom => [...$lines, $centered],
            Position::Left, Position::Right => $lines,
        };
    }

    /** @param list<string> $lines */
    protected function chartExtrasAddYLabel(array $lines): array
    {
        return array_map(
            fn(string $line) => $this->chartExtrasGetYLabel() . ' ' . $line,
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
