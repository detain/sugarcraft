<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Chart;

use SugarCraft\Charts\Legend\Legend;
use SugarCraft\Charts\Lang;

/**
 * Base chart class providing common features like legend, title,
 * axis labels, and data labels.
 *
 * Chart implementations should extend this class and implement
 * the `renderChart()` method to produce the raw chart output.
 * The `view()` method will then composite the chart with legend,
 * title, and labels.
 */
abstract class Chart
{
    protected function __construct(
        protected int $width,
        protected int $height,
        public readonly bool $showLegend,
        public readonly Position $legendPosition,
        public readonly ?string $legendIndicatorChar,
        public readonly ?string $title,
        public readonly Position $titlePosition,
        public readonly ?string $xLabel,
        public readonly ?string $yLabel,
        public readonly bool $showDataLabels,
        public readonly ?\Closure $dataLabelFormatter,
        /** @var list<array{label: string, color: string}> */
        protected array $legendItems = [],
    ) {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException(Lang::t('chart.dim_nonneg'));
        }
    }

    /**
     * Render the raw chart content without legend, title, or labels.
     */
    abstract protected function renderChart(): string;

    /**
     * Compose and return the full chart output with legend, title, and labels.
     */
    public function view(): string
    {
        $chart = $this->renderChart();

        if (!$this->showLegend && $this->title === null && $this->xLabel === null && $this->yLabel === null) {
            return $chart;
        }

        $parts = $this->buildChartWithExtras($chart);
        return implode("\n", $parts);
    }

    /**
     * @return list<string>
     */
    private function buildChartWithExtras(string $chart): array
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

        return $lines;
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
            Position::Top => [...$legendLines, ...$chartLines],
            Position::Bottom => [...$chartLines, ...$legendLines],
            Position::Left => $this->mergeLegendLeftRight($chartLines, $legendLines),
            Position::Right => $this->mergeLegendLeftRight($chartLines, $legendLines, true),
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
            Position::Top => [$centered, ...$lines],
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

    // ─── Fluent Configuration ───────────────────────────────────────────

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

    /** Customize legend styling. */
    public function withLegendStyle(?string $indicatorChar = null): self
    {
        return $this->copy(legendIndicatorChar: $indicatorChar);
    }

    /** Set the X-axis label (rendered at bottom). */
    public function withXLabel(string $label): self
    {
        return $this->copy(xLabel: $label);
    }

    /** Set the Y-axis label (rendered at left). */
    public function withYLabel(string $label): self
    {
        return $this->copy(yLabel: $label);
    }

    /** Enable or disable data point labels. */
    public function withDataLabels(bool $show = true): self
    {
        return $this->copy(showDataLabels: $show);
    }

    /** Set a custom formatter for data point labels. */
    public function withDataLabelFormatter(\Closure $formatter): self
    {
        return $this->copy(dataLabelFormatter: $formatter);
    }

    /**
     * Set the chart title.
     *
     * @param Position $position Where to render the title (Top or Bottom).
     *                           Left/Right positions are accepted but
     *                           rendered at Top for simplicity.
     */
    public function withTitle(string $title, Position $position = Position::Top): self
    {
        return $this->copy(title: $title, titlePosition: $position);
    }

    // ─── Short-form Aliases ─────────────────────────────────────────────

    /** @param list<array{label: string, color: string}> $items */
    public function legendItems(array $items): self { return $this->copy(legendItems: $items); }
    public function legend(bool $on = true): self   { return $this->withLegend($on); }
    public function legendPos(Position $pos): self  { return $this->withLegendPosition($pos); }
    public function legendStyle(?string $char): self { return $this->withLegendStyle($char); }
    public function xLabel(string $label): self     { return $this->withXLabel($label); }
    public function yLabel(string $label): self     { return $this->withYLabel($label); }
    public function dataLabels(bool $on = true): self { return $this->withDataLabels($on); }
    public function dataLabelFormat(\Closure $fn): self { return $this->withDataLabelFormatter($fn); }

    /**
     * Internal copy-with-overrides helper.
     *
     * @param list<array{label: string, color: string}> $legendItems
     * @return $this
     */
    protected function copy(
        ?int $width = null,
        ?int $height = null,
        ?bool $showLegend = null,
        ?Position $legendPosition = null,
        ?string $legendIndicatorChar = null,
        ?string $title = null,
        ?Position $titlePosition = null,
        ?string $xLabel = null,
        ?string $yLabel = null,
        ?bool $showDataLabels = null,
        ?\Closure $dataLabelFormatter = null,
        ?array $legendItems = null,
    ): self {
        return new static(
            width:              $width              ?? $this->width,
            height:             $height             ?? $this->height,
            showLegend:         $showLegend         ?? $this->showLegend,
            legendPosition:     $legendPosition     ?? $this->legendPosition,
            legendIndicatorChar:$legendIndicatorChar ?? $this->legendIndicatorChar,
            title:              $title              ?? $this->title,
            titlePosition:      $titlePosition      ?? $this->titlePosition,
            xLabel:             $xLabel             ?? $this->xLabel,
            yLabel:             $yLabel             ?? $this->yLabel,
            showDataLabels:     $showDataLabels     ?? $this->showDataLabels,
            dataLabelFormatter: $dataLabelFormatter ?? $this->dataLabelFormatter,
            legendItems:        $legendItems        ?? $this->legendItems,
        );
    }
}
