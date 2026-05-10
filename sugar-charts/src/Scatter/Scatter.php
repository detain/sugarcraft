<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Scatter;

use SugarCraft\Charts\Chart\Position;
use SugarCraft\Charts\Lang;
use SugarCraft\Charts\Canvas\Canvas;
use SugarCraft\Charts\Legend\Legend;

/**
 * Scatter plot — like {@see \SugarCraft\Charts\LineChart\LineChart} but
 * each point is plotted independently with no connecting strokes. The
 * X and Y ranges are auto-detected from the data unless pinned via
 * {@see withXRange()} / {@see withYRange()}.
 *
 * ```php
 * echo Scatter::new([[1, 4], [2, 7], [3, 5], [4, 9]], width: 30, height: 8)->view();
 * ```
 *
 * Axis labels and legend are supported:
 *
 * ```php
 * echo Scatter::new([[1, 4], [2, 7]], width: 20, height: 6)
 *     ->withXLabel('X Value')
 *     ->withYLabel('Y Value')
 *     ->withLegend(true)
 *     ->withLegendPosition(Position::Bottom)
 *     ->view();
 * ```
 */
final class Scatter
{
    /**
     * @param list<array{0:int|float,1:int|float}>       $points
     * @param list<array{label: string, color: string}> $legendItems
     */
    private function __construct(
        public readonly array $points,
        public readonly int $width,
        public readonly int $height,
        public readonly ?float $minX,
        public readonly ?float $maxX,
        public readonly ?float $minY,
        public readonly ?float $maxY,
        public readonly string $rune,
        public readonly bool $showLegend = false,
        public readonly Position $legendPosition = Position::Right,
        public readonly ?string $legendIndicatorChar = null,
        public readonly ?string $title = null,
        public readonly Position $titlePosition = Position::Top,
        public readonly ?string $xLabel = null,
        public readonly ?string $yLabel = null,
        private readonly array $legendItems = [],
    ) {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException(Lang::t('scatter.dim_nonneg'));
        }
    }

    /** @param list<array{0:int|float,1:int|float}> $points */
    public static function new(array $points = [], int $width = 40, int $height = 8): self
    {
        return new self(array_values($points), $width, $height, null, null, null, null, '*');
    }

    /** @param list<array{0:int|float,1:int|float}> $points */
    public function withPoints(array $points): self
    {
        return $this->copy(points: array_values($points));
    }

    public function withSize(int $w, int $h): self
    {
        if ($w < 0 || $h < 0) {
            throw new \InvalidArgumentException(Lang::t('scatter.dim_nonneg'));
        }
        return $this->copy(width: $w, height: $h);
    }

    public function withXRange(?float $min, ?float $max): self
    {
        return $this->copy(minX: $min, maxX: $max);
    }

    public function withYRange(?float $min, ?float $max): self
    {
        return $this->copy(minY: $min, maxY: $max);
    }

    public function withRune(string $rune): self
    {
        return $this->copy(rune: $rune);
    }

    // ─── Legend & Label Configuration ──────────────────────────────────

    /** Enable or disable the legend. */
    public function withLegend(bool $show = true): self
    {
        return $this->copy(showLegend: $show);
    }

    /** Set the legend position. */
    public function withLegendPosition(Position $position): self
    {
        return $this->copy(legendPosition: $position);
    }

    /** Customize legend indicator character. */
    public function withLegendStyle(?string $indicatorChar = null): self
    {
        return $this->copy(legendIndicatorChar: $indicatorChar);
    }

    /** Set the chart title. */
    public function withTitle(string $title, Position $position = Position::Top): self
    {
        return $this->copy(title: $title, titlePosition: $position);
    }

    /** Set the X-axis label (rendered at bottom). */
    public function withXLabel(string $label): self
    {
        return $this->copy(xLabel: $label);
    }

    /** Set the Y-axis label (prepended to each line). */
    public function withYLabel(string $label): self
    {
        return $this->copy(yLabel: $label);
    }

    /**
     * Set legend items directly (label + color pairs).
     *
     * @param list<array{label: string, color: string}> $items
     */
    public function withLegendItems(array $items): self
    {
        return $this->copy(legendItems: $items);
    }

    // ─── Short-form Aliases ─────────────────────────────────────────────

    /** @param list<array{0:int|float,1:int|float}> $points */
    public function points(array $points): self              { return $this->withPoints($points); }
    public function size(int $w, int $h): self               { return $this->withSize($w, $h); }
    public function xRange(?float $min, ?float $max): self   { return $this->withXRange($min, $max); }
    public function yRange(?float $min, ?float $max): self   { return $this->withYRange($min, $max); }
    public function rune(string $rune): self                 { return $this->withRune($rune); }
    public function legend(bool $on = true): self            { return $this->withLegend($on); }
    public function legendPos(Position $pos): self           { return $this->withLegendPosition($pos); }
    public function legendStyle(?string $char): self         { return $this->withLegendStyle($char); }
    public function title(string $t, Position $p = Position::Top): self { return $this->withTitle($t, $p); }
    public function xLabel(string $label): self              { return $this->withXLabel($label); }
    public function yLabel(string $label): self              { return $this->withYLabel($label); }
    /** @param list<array{label: string, color: string}> $items */
    public function legendItems(array $items): self          { return $this->withLegendItems($items); }

    // ─── Rendering ──────────────────────────────────────────────────────

    public function view(): string
    {
        if ($this->points === [] || $this->width === 0 || $this->height === 0) {
            return (new Canvas($this->width, $this->height))->view();
        }

        $chart = $this->renderChart();

        if (!$this->showLegend && $this->title === null && $this->xLabel === null && $this->yLabel === null) {
            return $chart;
        }

        return $this->buildChartWithExtras($chart);
    }

    /**
     * Render the raw scatter plot without legend, title, or labels.
     */
    private function renderChart(): string
    {
        $minX = $this->minX;
        $maxX = $this->maxX;
        $minY = $this->minY;
        $maxY = $this->maxY;
        if ($minX === null || $maxX === null || $minY === null || $maxY === null) {
            foreach ($this->points as $p) {
                $x = (float) $p[0];
                $y = (float) $p[1];
                $minX = $minX === null ? $x : min($minX, $x);
                $maxX = $maxX === null ? $x : max($maxX, $x);
                $minY = $minY === null ? $y : min($minY, $y);
                $maxY = $maxY === null ? $y : max($maxY, $y);
            }
        }
        if ($maxX == $minX) { $maxX = $minX + 1.0; }
        if ($maxY == $minY) { $maxY = $minY + 1.0; }

        $canvas = new Canvas($this->width, $this->height);
        foreach ($this->points as $p) {
            $x = (float) $p[0];
            $y = (float) $p[1];
            $col = (int) round((($x - $minX) / ($maxX - $minX)) * ($this->width - 1));
            // Y is inverted so larger values sit at the top.
            $row = (int) round((1.0 - (($y - $minY) / ($maxY - $minY))) * ($this->height - 1));
            $canvas->setCell($col, $row, $this->rune);
        }
        return $canvas->view();
    }

    /**
     * Compose chart output with legend, title, and axis labels.
     */
    private function buildChartWithExtras(string $chart): string
    {
        $lines = $chart !== '' ? explode("\n", $chart) : [];

        if ($this->showLegend && $this->legendItems !== []) {
            $legend = $this->buildLegend();
            $lines = $this->mergeLegend($lines, $legend);
        }

        if ($this->title !== null) {
            $lines = $this->addTitle($lines);
        }

        if ($this->yLabel !== null) {
            $lines = $this->addYLabel($lines);
        }

        if ($this->xLabel !== null) {
            $lines[] = $this->xLabel;
        }

        return implode("\n", $lines);
    }

    private function buildLegend(): Legend
    {
        $legend = Legend::new($this->legendItems)
            ->withPosition($this->legendPosition);

        if ($this->legendIndicatorChar !== null) {
            $legend = $legend->withIndicatorChar($this->legendIndicatorChar);
        }

        return $legend;
    }

    /**
     * @param list<string> $chartLines
     * @param list<string> $legendLines
     * @return list<string>
     */
    private function mergeLegend(array $chartLines, Legend $legend): array
    {
        $legendLines = explode("\n", $legend->view());
        $chartHeight = count($chartLines);
        $legendHeight = count($legendLines);

        return match ($this->legendPosition) {
            Position::Top    => [...$legendLines, ...$chartLines],
            Position::Bottom => [...$chartLines, ...$legendLines],
            Position::Left   => $this->mergeLegendLeftRight($chartLines, $legendLines),
            Position::Right  => $this->mergeLegendLeftRight($chartLines, $legendLines, true),
        };
    }

    /**
     * @param list<string> $chartLines
     * @param list<string> $legendLines
     * @return list<string>
     */
    private function mergeLegendLeftRight(array $chartLines, array $legendLines, bool $legendOnRight = false): array
    {
        $maxHeight = max(count($chartLines), count($legendLines));
        $result = [];

        for ($i = 0; $i < $maxHeight; $i++) {
            $chartLine = $chartLines[$i] ?? '';
            $legendLine = $legendLines[$i] ?? '';

            if ($legendOnRight) {
                $result[] = str_pad($chartLine, $this->width, ' ', STR_PAD_RIGHT) . ' ' . $legendLine;
            } else {
                $result[] = $legendLine . ' ' . str_pad($chartLine, $this->width, ' ', STR_PAD_RIGHT);
            }
        }

        return $result;
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function addTitle(array $lines): array
    {
        $titleLen = mb_strlen($this->title, 'UTF-8');
        $centered = str_pad($this->title, $this->width, ' ', STR_PAD_BOTH);

        return match ($this->titlePosition) {
            Position::Top    => [$centered, ...$lines],
            Position::Bottom => [...$lines, $centered],
            Position::Left, Position::Right => $lines,
        };
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function addYLabel(array $lines): array
    {
        return array_map(
            fn(string $line) => $this->yLabel . ' ' . $line,
            $lines,
        );
    }

    public function __toString(): string
    {
        return $this->view();
    }

    /**
     * Internal copy-with-overrides helper.
     *
     * @param list<array{0:int|float,1:int|float}>       $points
     * @param list<array{label: string, color: string}> $legendItems
     */
    private function copy(
        ?array $points = null,
        ?int $width = null,
        ?int $height = null,
        ?float $minX = null,
        ?float $maxX = null,
        ?float $minY = null,
        ?float $maxY = null,
        ?string $rune = null,
        ?bool $showLegend = null,
        ?Position $legendPosition = null,
        ?string $legendIndicatorChar = null,
        ?string $title = null,
        ?Position $titlePosition = null,
        ?string $xLabel = null,
        ?string $yLabel = null,
        ?array $legendItems = null,
    ): self {
        return new self(
            points:             $points             ?? $this->points,
            width:              $width              ?? $this->width,
            height:             $height             ?? $this->height,
            minX:               $minX               ?? $this->minX,
            maxX:               $maxX               ?? $this->maxX,
            minY:               $minY               ?? $this->minY,
            maxY:               $maxY               ?? $this->maxY,
            rune:               $rune               ?? $this->rune,
            showLegend:         $showLegend         ?? $this->showLegend,
            legendPosition:     $legendPosition     ?? $this->legendPosition,
            legendIndicatorChar:$legendIndicatorChar ?? $this->legendIndicatorChar,
            title:              $title              ?? $this->title,
            titlePosition:      $titlePosition      ?? $this->titlePosition,
            xLabel:             $xLabel             ?? $this->xLabel,
            yLabel:             $yLabel             ?? $this->yLabel,
            legendItems:        $legendItems        ?? $this->legendItems,
        );
    }
}
