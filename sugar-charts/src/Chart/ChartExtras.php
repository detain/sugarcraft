<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Chart;

use SugarCraft\Charts\Legend\Legend;
use SugarCraft\Charts\Support\FrameSize;
use SugarCraft\Core\Util\Width;

/**
 * Shared legend / title / axis-label composition logic.
 *
 * The single compositor for every renderer: the ChartExtras renderers
 * (BarChart, Scatter, OHLCChart) and the Chart base alike carry these
 * method bodies (sugar-charts-008 converged a private fork into this
 * trait). Each using class supplies the nine ChromeAccessors getters
 * this trait's methods reference.
 */
trait ChartExtras
{
    /** yLabel gutter cap: width / this divisor, so a long label cannot multiply out the canvas. */
    private const Y_LABEL_WIDTH_FRACTION_DIVISOR = 4;

    /**
     * Honest rendered-frame size of the composed output (sugar-charts-010).
     * The declared width covers the CHART body; opted-in gutters (legend
     * sidecar, y-label column) extend the real frame — this returns the
     * true [w, h] so fixed panes can size themselves against it.
     *
     * @return array{0: int, 1: int}
     */
    public function intrinsicSize(): array
    {
        return FrameSize::of($this->view());
    }

    /**
     * Compose the chart with legend, title, and axis labels.
     *
     * Public so the parity guard can assert both families share this one
     * body (declaring-class reflection, sugar-charts-008).
     *
     * @return list<string>
     */
    public function buildChartWithExtras(string $chart): array
    {
        $lines = $chart !== '' ? explode("\n", $chart) : [];

        if ($this->chartExtrasShowLegend()) {
            if ($this->chartExtrasGetLegendItems() !== []) {
                $lines = $this->chartExtrasMergeLegend($lines, $this->chartExtrasBuildLegend());
            } else {
                // sugar-charts-096: asking for a legend with no series must
                // show that state, not silently drop the row.
                $lines[] = Width::truncate('[no series]', $this->chartExtrasGetWidth());
            }
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
        $position    = $this->chartExtrasGetLegendPosition();
        $legendLines = explode("\n", $legend->view());

        // sugar-charts-010: stacked (top/bottom) legend rows are part of
        // the frame proper — clip them to the declared width. The sidecar
        // (Left/Right) merge stays an honest opt-in gutter extension
        // measured by intrinsicSize().
        if ($position === Position::Top || $position === Position::Bottom) {
            $legendLines = array_map(
                fn(string $line): string => Width::truncate($line, $this->chartExtrasGetWidth()),
                $legendLines,
            );
        }

        return match ($position) {
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

        // Left/Right cannot arrive here — the constructor door refuses them
        // (sugar-charts-025), so match stays exhaustive-fail-fast.
        return match ($this->chartExtrasGetTitlePosition()) {
            Position::Top    => [$centered, ...$lines],
            Position::Bottom => [...$lines, $centered],
        };
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    protected function chartExtrasAddYLabel(array $lines): array
    {
        $budget = max(1, intdiv($this->chartExtrasGetWidth(), self::Y_LABEL_WIDTH_FRACTION_DIVISOR));
        $label  = Width::truncate($this->chartExtrasGetYLabel(), $budget);
        $gutter = str_repeat(' ', Width::string($label));

        // sugar-charts-030: the y-axis name is a label, not wallpaper —
        // emit it once, vertically centered, keeping the gutter column
        // aligned on every other row.
        $rows = count($lines);
        $at   = $rows > 0 ? intdiv($rows, 2) : 0;

        return array_map(
            fn(string $line, int $i): string => ($i === $at ? $label : $gutter) . ' ' . $line,
            $lines,
            array_keys($lines),
        );
    }
}

