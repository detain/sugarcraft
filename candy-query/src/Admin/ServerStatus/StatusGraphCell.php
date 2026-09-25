<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\ServerStatus;

use SugarCraft\Dash\Plot\Chart\AreaChart;
use SugarCraft\Query\Admin\Calc\HostLoadSampler;

/**
 * One rolling time-series frame on the Server Status column.
 *
 * Holds per-series sample windows (never renders I/O) and draws them as a
 * sugar-dash AreaChart sized to the column. Two shapes, mirroring MySQL
 * Workbench's Management :: Server Status graphs (query_dashboard.md
 * lines 49-68):
 *
 *  - filled (stacked=false): a single series painted from the baseline up.
 *  - stacked (stacked=true): two series where the LOWER band is the first
 *    name and the UPPER band the second. AreaChart paints later series
 *    over earlier cells, so the render pre-sums cumulatively and passes the
 *    TOTAL first (top color, full height) then the BOTTOM segment (repaints
 *    the lower cells with its own color). The visible bands are exactly
 *    bottom + delta, i.e. cumulative-height semantics.
 *
 * maxValue is pinned per frame from the window's own peak on a 1/2/2.5/5/10
 * ladder so traces use the vertical space at any magnitude, from a dozen
 * connections up to megabytes-per-second of traffic.
 */
final class StatusGraphCell
{
    /** @var array<string, list<float>> series name -> samples, oldest first */
    private array $windows = [];

    /**
     * @param list<string> $seriesNames draw order: index 0 is the bottom band
     * @param list<string> $colors      hex color per series, same order
     */
    public function __construct(
        public readonly string $caption,
        private readonly array $seriesNames,
        private readonly array $colors,
        private readonly bool $stacked,
        private readonly int $window = HostLoadSampler::WINDOW,
    ) {}

    /**
     * Append one frame of samples; keys missing from $samples record 0.0 so
     * the trace keeps one point per poll instead of drifting sideways.
     *
     * @param array<string, float> $samples
     */
    public function ingest(array $samples): void
    {
        foreach ($this->seriesNames as $name) {
            $this->windows[$name][] = (float) ($samples[$name] ?? 0.0);
            if (count($this->windows[$name]) > $this->window) {
                array_shift($this->windows[$name]);
            }
        }
    }

    public function hasSamples(): bool
    {
        return ($this->windows[$this->seriesNames[0]] ?? []) !== [];
    }

    /**
     * Latest recorded value per series name.
     *
     * @return array<string, float>
     */
    public function latest(): array
    {
        $latest = [];
        foreach ($this->seriesNames as $name) {
            $series = $this->windows[$name];
            $latest[$name] = $series === [] ? 0.0 : $series[count($series) - 1];
        }

        return $latest;
    }

    /**
     * @return list<float>
     */
    public function series(string $name): array
    {
        return $this->windows[$name] ?? [];
    }

    /**
     * Paint the window as exactly $width x $height cells (plus nothing else:
     * captions and labels are the column's job so the width budget stays
     * arithmetic, not string-geometry, at the caller).
     */
    public function view(int $width, int $height): string
    {
        if (!$this->hasSamples()) {
            return str_repeat(str_repeat(' ', max(1, $width)) . "\n", max(1, $height) - 1)
                . str_repeat(' ', max(1, $width));
        }

        $chart = $this->stacked
            ? $this->stackedChart($width, $height)
            : $this->filledChart($width, $height);

        return $chart->render();
    }

    private function filledChart(int $width, int $height): AreaChart
    {
        $values = $this->windows[$this->seriesNames[0]];

        return AreaChart::new([[
            'label' => $this->caption,
            'values' => $values,
            'color' => $this->colors[0],
        ]])
            ->withDimensions($width, $height)
            ->withShowGrid(false)
            ->withMaxValue($this->ceiling($values));
    }

    /**
     * Cumulative pre-sum drawn top-first: AreaChart's stacked mode lets the
     * later series overwrite the earlier cells, so passing [total, bottom]
     * leaves the bottom band in the first color and the delta band above it
     * in the second — Workbench's stacked-area semantics.
     */
    private function stackedChart(int $width, int $height): AreaChart
    {
        $bottom = $this->windows[$this->seriesNames[0]];
        $top = $this->windows[$this->seriesNames[1]];
        $total = array_map(static fn (float $b, float $t): float => $b + $t, $bottom, $top);

        return AreaChart::new([
            ['label' => $this->seriesNames[1], 'values' => $total, 'color' => $this->colors[1]],
            ['label' => $this->seriesNames[0], 'values' => $bottom, 'color' => $this->colors[0]],
        ])
            ->withDimensions($width, $height)
            ->withShowGrid(false)
            ->withStacked(true)
            ->withMaxValue($this->ceiling($total));
    }

    /**
     * Frame the vertical axis to the window's peak on a 1/2/2.5/5/10 x 10^k
     * ladder.
     *
     * WHY not the Dashboard's NiceScale: its 100-unit floor exists to keep
     * per-second QPS traces stable, but the status column spans connections
     * (~dozens) to bytes/s (millions), and a forced axis of 100 would draw a
     * 12-connection server as a flat line on the floor. The ladder rescales
     * only when a new peak crosses a step, so traces fill their pane without
     * flickering every frame.
     *
     * @param list<float> $values
     */
    private function ceiling(array $values): float
    {
        $peak = $values === [] ? 0.0 : max($values);
        if ($peak <= 0.0) {
            return 1.0;
        }

        $magnitude = 10 ** (int) floor(log10($peak));
        foreach ([1.0, 2.0, 2.5, 5.0, 10.0] as $step) {
            if ($peak <= $step * $magnitude) {
                return $step * $magnitude;
            }
        }

        return 10.0 * $magnitude;
    }
}
