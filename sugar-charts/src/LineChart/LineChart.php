<?php

declare(strict_types=1);

namespace CandyCore\Charts\LineChart;

use CandyCore\Charts\Canvas\Canvas;
use CandyCore\Charts\Canvas\Graph;

/**
 * Single-series line plot drawn onto a {@see Canvas}. Each data point
 * becomes one column; the series is scaled into the canvas height and
 * a `*` (configurable) is plotted at the rounded row. Adjacent points
 * are connected with `|`, `/`, or `\` strokes when their rows differ,
 * giving an at-a-glance trend without requiring sub-cell rasterisation.
 *
 * ```php
 * echo LineChart::new([1, 4, 2, 8, 6, 3, 7], 30, 6)->view();
 * ```
 */
final class LineChart
{
    /**
     * @param list<int|float>            $data
     * @param array<string,list<int|float>> $datasets  named multi-series
     * @param array<string,string>       $datasetPoints  per-series rune override
     * @param list<string>               $xLabels
     * @param list<string>               $yLabels
     */
    private function __construct(
        public readonly array $data,
        public readonly int $width,
        public readonly int $height,
        public readonly ?float $min,
        public readonly ?float $max,
        public readonly string $point,
        public readonly array $datasets    = [],
        public readonly array $datasetPoints = [],
        public readonly bool $showAxes     = false,
        public readonly array $xLabels     = [],
        public readonly array $yLabels     = [],
        public readonly ?float $xMin       = null,
        public readonly ?float $xMax       = null,
        public readonly ?\Closure $xLabelFormatter = null,
        public readonly ?\Closure $yLabelFormatter = null,
        public readonly int $xLabelTicks   = 0,
        public readonly int $yLabelTicks   = 0,
    ) {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException('line chart width/height must be >= 0');
        }
    }

    /** @param list<int|float> $data */
    public static function new(array $data = [], int $width = 40, int $height = 8): self
    {
        return new self(array_values($data), $width, $height, null, null, '*');
    }

    /** @param list<int|float> $data */
    public function withData(array $data): self
    {
        return $this->copy(data: array_values($data));
    }

    public function withSize(int $w, int $h): self
    {
        if ($w < 0 || $h < 0) {
            throw new \InvalidArgumentException('line chart width/height must be >= 0');
        }
        return $this->copy(width: $w, height: $h);
    }

    public function withMin(?float $m): self        { return $this->copy(min: $m, minSet: true); }
    public function withMax(?float $m): self        { return $this->copy(max: $m, maxSet: true); }
    public function withPoint(string $rune): self   { return $this->copy(point: $rune); }

    /**
     * Y-axis data range as a `[min, max]` pair. Equivalent to chaining
     * `withMin($min)->withMax($max)`. Mirrors ntcharts' `SetYRange`.
     */
    public function withYRange(?float $min, ?float $max): self
    {
        return $this->copy(min: $min, minSet: true, max: $max, maxSet: true);
    }

    /**
     * X-axis range used by the {@see withXLabelFormatter()} when
     * generating X tick labels. The data itself remains column-indexed;
     * the range is the conceptual `[xMin, xMax]` mapped across the
     * plot width. Mirrors ntcharts' `SetXRange`.
     */
    public function withXRange(?float $min, ?float $max): self
    {
        return $this->copy(xMin: $min, xMinSet: true, xMax: $max, xMaxSet: true);
    }

    /**
     * Combined `setXYRange` shortcut.
     */
    public function withXYRange(?float $xMin, ?float $xMax, ?float $yMin, ?float $yMax): self
    {
        return $this->withXRange($xMin, $xMax)->withYRange($yMin, $yMax);
    }

    /**
     * Reset both axes to auto-detect from the dataset. Equivalent to
     * `withYRange(null, null)->withXRange(null, null)`. Mirrors
     * ntcharts' `AutoAdjustRange`.
     */
    public function autoAdjustRange(): self
    {
        return $this->copy(
            min: null, minSet: true, max: null, maxSet: true,
            xMin: null, xMinSet: true, xMax: null, xMaxSet: true,
        );
    }

    /**
     * Closure invoked per X tick to format axis labels: `fn(float $v): string`.
     * Used only when {@see withXLabels()} hasn't been called explicitly.
     * `$ticks` controls the count of label slots — defaults to a sensible
     * value based on plot width.
     */
    public function withXLabelFormatter(\Closure $fn, int $ticks = 0): self
    {
        return $this->copy(xLabelFormatter: $fn, xLabelFormatterSet: true, xLabelTicks: max(0, $ticks));
    }

    /**
     * Closure invoked per Y tick to format axis labels: `fn(float $v): string`.
     * Used only when {@see withYLabels()} hasn't been called explicitly.
     */
    public function withYLabelFormatter(\Closure $fn, int $ticks = 0): self
    {
        return $this->copy(yLabelFormatter: $fn, yLabelFormatterSet: true, yLabelTicks: max(0, $ticks));
    }

    /**
     * Add or replace a named series. Series share the same axes as the
     * primary `data`. Use {@see withDatasetPoint()} to give each series
     * a distinct rune.
     *
     * @param list<int|float> $values
     */
    public function withDataset(string $name, array $values): self
    {
        $sets = $this->datasets;
        $sets[$name] = array_values($values);
        return $this->copy(datasets: $sets);
    }

    /** Per-series rune override for {@see view()}. */
    public function withDatasetPoint(string $name, string $rune): self
    {
        $points = $this->datasetPoints;
        $points[$name] = $rune;
        return $this->copy(datasetPoints: $points);
    }

    /** Render an X / Y axis frame around the plot area. Default off. */
    public function withAxes(bool $on = true): self
    {
        return $this->copy(showAxes: $on);
    }

    /**
     * X-axis labels (rendered under the axis when `withAxes(true)` is set).
     * @param list<string> $labels
     */
    public function withXLabels(array $labels): self
    {
        return $this->copy(xLabels: array_values($labels));
    }

    /**
     * Y-axis labels (rendered to the left of the axis, top-to-bottom
     * for largest-to-smallest by convention).
     * @param list<string> $labels
     */
    public function withYLabels(array $labels): self
    {
        return $this->copy(yLabels: array_values($labels));
    }

    public function view(): string
    {
        if ($this->width === 0 || $this->height === 0 || ($this->data === [] && $this->datasets === [])) {
            return (new Canvas($this->width, $this->height))->view();
        }

        $canvas = new Canvas($this->width, $this->height);

        // Compute a global axis range covering every series so each
        // line stays comparable on the same scale.
        $allValues = $this->data;
        foreach ($this->datasets as $values) {
            $allValues = array_merge($allValues, $values);
        }
        if ($allValues === []) {
            return $canvas->view();
        }
        $min = $this->min ?? min($allValues);
        $max = $this->max ?? max($allValues);
        if ($max == $min) {
            $max = $min + 1.0;
        }

        // Resolve labels — explicit lists win, formatters fill in
        // when no list was supplied.
        $xLabels = $this->resolveXLabels(count($this->data));
        $yLabels = $this->resolveYLabels((float) $min, (float) $max);

        // When axes are on, reserve a 2-cell left gutter (Y labels +
        // axis line) and a 1-row bottom gutter (X axis + labels).
        $gutterLeft = 0;
        $gutterBottom = 0;
        if ($this->showAxes) {
            $maxYLabel = 0;
            foreach ($yLabels as $lbl) {
                $maxYLabel = max($maxYLabel, mb_strlen($lbl, 'UTF-8'));
            }
            $gutterLeft   = max(2, $maxYLabel + 1);
            $gutterBottom = $xLabels !== [] ? 2 : 1;
        }
        $plotW = max(1, $this->width  - $gutterLeft);
        $plotH = max(1, $this->height - $gutterBottom);

        // Plot primary + named series on the same axes.
        $allSeries = ['_primary' => $this->data] + $this->datasets;
        foreach ($allSeries as $name => $values) {
            if ($values === []) {
                continue;
            }
            $points  = count($values) > $plotW ? array_slice($values, -$plotW) : $values;
            $count   = count($points);
            $rune    = $name === '_primary'
                ? $this->point
                : ($this->datasetPoints[$name] ?? $this->point);

            $coords = [];
            foreach ($points as $i => $v) {
                $col = $gutterLeft + ($count <= 1
                    ? 0
                    : (int) round($i * ($plotW - 1) / ($count - 1)));
                $norm = ((float) $v - $min) / ($max - $min);
                $norm = max(0.0, min(1.0, $norm));
                $row = (int) round((1.0 - $norm) * ($plotH - 1));
                $coords[] = [$col, $row];
            }
            for ($i = 0; $i < $count; $i++) {
                [$x, $y] = $coords[$i];
                $canvas->setCell($x, $y, $rune);
                if ($i + 1 < $count) {
                    [$x2, $y2] = $coords[$i + 1];
                    self::drawConnector($canvas, $x, $y, $x2, $y2, $rune);
                }
            }
        }

        if ($this->showAxes) {
            // Origin = bottom-left of the plot region.
            $xOrigin = $gutterLeft;
            $yOrigin = $plotH; // axis row sits one row below the topmost data
            Graph::drawXYAxis($canvas, $xOrigin, $yOrigin, $plotW - 1, $plotH - 1);
            Graph::drawXYAxisLabel(
                $canvas, $xOrigin, $yOrigin, $plotW - 1, $plotH - 1,
                $xLabels, $yLabels,
            );
        }
        return $canvas->view();
    }

    /**
     * Resolve the X-axis label list: explicit `xLabels` win, formatter
     * + range fills in when none supplied. Default tick count = 5 when
     * neither labels nor formatter are present (returns empty).
     *
     * @return list<string>
     */
    private function resolveXLabels(int $sampleCount): array
    {
        if ($this->xLabels !== []) {
            return $this->xLabels;
        }
        if ($this->xLabelFormatter === null) {
            return [];
        }
        $ticks = $this->xLabelTicks > 0 ? $this->xLabelTicks : 5;
        $xMin = $this->xMin ?? 0.0;
        $xMax = $this->xMax ?? (float) max(0, $sampleCount - 1);
        if ($xMax === $xMin) {
            $xMax = $xMin + 1.0;
        }
        $out = [];
        $fn = $this->xLabelFormatter;
        for ($i = 0; $i < $ticks; $i++) {
            $t = $ticks > 1 ? $i / ($ticks - 1) : 0.0;
            $v = $xMin + $t * ($xMax - $xMin);
            $out[] = (string) $fn($v);
        }
        return $out;
    }

    /**
     * Resolve the Y-axis label list. Mirrors {@see resolveXLabels()}
     * for the Y axis using the data range (`min`..`max`) as the source.
     *
     * @return list<string>
     */
    private function resolveYLabels(float $min, float $max): array
    {
        if ($this->yLabels !== []) {
            return $this->yLabels;
        }
        if ($this->yLabelFormatter === null) {
            return [];
        }
        $ticks = $this->yLabelTicks > 0 ? $this->yLabelTicks : 4;
        $out = [];
        $fn = $this->yLabelFormatter;
        // Top-to-bottom: largest to smallest (axis convention).
        for ($i = 0; $i < $ticks; $i++) {
            $t = $ticks > 1 ? $i / ($ticks - 1) : 0.0;
            $v = $max - $t * ($max - $min);
            $out[] = (string) $fn($v);
        }
        return $out;
    }

    /** Internal copy-with-overrides helper. */
    private function copy(
        ?array $data = null,
        ?int $width = null,
        ?int $height = null,
        ?float $min = null, bool $minSet = false,
        ?float $max = null, bool $maxSet = false,
        ?string $point = null,
        ?array $datasets = null,
        ?array $datasetPoints = null,
        ?bool $showAxes = null,
        ?array $xLabels = null,
        ?array $yLabels = null,
        ?float $xMin = null, bool $xMinSet = false,
        ?float $xMax = null, bool $xMaxSet = false,
        ?\Closure $xLabelFormatter = null, bool $xLabelFormatterSet = false,
        ?\Closure $yLabelFormatter = null, bool $yLabelFormatterSet = false,
        ?int $xLabelTicks = null,
        ?int $yLabelTicks = null,
    ): self {
        return new self(
            data:            $data         ?? $this->data,
            width:           $width        ?? $this->width,
            height:          $height       ?? $this->height,
            min:             $minSet ? $min : $this->min,
            max:             $maxSet ? $max : $this->max,
            point:           $point        ?? $this->point,
            datasets:        $datasets     ?? $this->datasets,
            datasetPoints:   $datasetPoints?? $this->datasetPoints,
            showAxes:        $showAxes     ?? $this->showAxes,
            xLabels:         $xLabels      ?? $this->xLabels,
            yLabels:         $yLabels      ?? $this->yLabels,
            xMin:            $xMinSet ? $xMin : $this->xMin,
            xMax:            $xMaxSet ? $xMax : $this->xMax,
            xLabelFormatter: $xLabelFormatterSet ? $xLabelFormatter : $this->xLabelFormatter,
            yLabelFormatter: $yLabelFormatterSet ? $yLabelFormatter : $this->yLabelFormatter,
            xLabelTicks:     $xLabelTicks  ?? $this->xLabelTicks,
            yLabelTicks:     $yLabelTicks  ?? $this->yLabelTicks,
        );
    }

    public function __toString(): string
    {
        return $this->view();
    }

    /** Draw a coarse connector between two points using line-art glyphs. */
    private static function drawConnector(Canvas $c, int $x1, int $y1, int $x2, int $y2, string $point): void
    {
        if ($x2 <= $x1) {
            return;
        }
        // Vertical column between two adjacent samples (same x): step
        // through the rows.
        if ($x2 === $x1) {
            $step = $y2 > $y1 ? 1 : -1;
            for ($y = $y1 + $step; $y !== $y2; $y += $step) {
                $c->setCell($x1, $y, '|');
            }
            return;
        }
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        if ($dy === 0) {
            for ($x = $x1 + 1; $x < $x2; $x++) {
                $c->setCell($x, $y1, '-');
            }
            return;
        }
        // Sample one row per intermediate column.
        for ($x = $x1 + 1; $x < $x2; $x++) {
            $t   = ($x - $x1) / $dx;
            $row = (int) round($y1 + $t * $dy);
            $rune = $dy > 0 ? '\\' : '/';
            $c->setCell($x, $row, $rune);
        }
    }
}
