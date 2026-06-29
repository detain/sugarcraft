<?php

declare(strict_types=1);

namespace SugarCraft\Charts\BarChart;

use SugarCraft\Charts\Chart\Position;
use SugarCraft\Charts\Lang;
use SugarCraft\Charts\Legend\Legend;

/**
 * Vertical bar chart drawn with `█` blocks. Bars are spaced one column
 * apart and labels are written underneath, truncated to fit when too
 * long. Y-axis range is computed from the data unless explicit
 * {@see withMin()} / {@see withMax()} are provided.
 *
 * ```php
 * echo BarChart::new([['cpu', 0.7], ['mem', 0.4], ['disk', 0.9]], width: 12, height: 5)->view();
 * ```
 *
 * Axis labels and legend are supported:
 *
 * ```php
 * echo BarChart::new([['cpu', 0.7], ['mem', 0.4]], width: 20, height: 6)
 *     ->withXLabel('Resource')
 *     ->withYLabel('Usage %')
 *     ->withLegend(true)
 *     ->withLegendPosition(Position::Bottom)
 *     ->view();
 * ```
 */
final class BarChart
{
    /**
     * @param list<Bar>                        $bars
     * @param list<array{label: string, color: string}> $legendItems
     */
    private function __construct(
        public readonly array $bars,
        public readonly int $width,
        public readonly int $height,
        public readonly ?float $min,
        public readonly ?float $max,
        public readonly bool $showLabels,
        public readonly bool $horizontal = false,
        public readonly bool $showAxis   = false,
        public readonly ?int $barWidth   = null,
        public readonly ?int $barGap     = null,
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
            throw new \InvalidArgumentException(Lang::t('barchart.dim_nonneg'));
        }
        if ($barWidth !== null && $barWidth < 1) {
            throw new \InvalidArgumentException(Lang::t('barchart.bar_width_min'));
        }
        if ($barGap !== null && $barGap < 0) {
            throw new \InvalidArgumentException(Lang::t('barchart.bar_gap_nonneg'));
        }
    }

    /**
     * Construct from either an array of `[label, value]` tuples, an array
     * keyed `label => value`, or a list of {@see Bar} instances.
     *
     * @param iterable<mixed> $bars
     */
    public static function new(iterable $bars = [], int $width = 40, int $height = 8): self
    {
        return new self(self::coerceBars($bars), $width, $height, null, null, true);
    }

    /** @param iterable<mixed> $bars */
    public function withBars(iterable $bars): self
    {
        return $this->copy(bars: self::coerceBars($bars));
    }

    /**
     * Append a single bar to the chart. Accepts a {@see Bar} instance,
     * a `[label, value]` tuple, or a `label => value` pair (when called
     * via `push(['Apple', 12])` or `push(new Bar('Apple', 12))`).
     * Mirrors ntcharts' `BarChart::Push(BarData)`. Immutable.
     */
    public function push(Bar|array $bar): self
    {
        return $this->copy(bars: [...$this->bars, ...self::coerceBars([$bar])]);
    }

    /**
     * Append every bar in `$bars` to the chart, in order. Accepts the
     * same shapes as {@see new()}. Mirrors ntcharts' `BarChart::PushAll`.
     *
     * @param iterable<mixed> $bars
     */
    public function pushAll(iterable $bars): self
    {
        $appended = self::coerceBars($bars);
        if ($appended === []) {
            return $this;
        }
        return $this->copy(bars: [...$this->bars, ...$appended]);
    }

    /** Drop every bar. Mirrors ntcharts' `Clear`. */
    public function clear(): self
    {
        return $this->copy(bars: []);
    }

    public function withSize(int $w, int $h): self
    {
        if ($w < 0 || $h < 0) {
            throw new \InvalidArgumentException(Lang::t('barchart.dim_nonneg'));
        }
        return $this->copy(width: $w, height: $h);
    }

    public function withMin(?float $m): self       { return $this->copy(min: $m, minSet: true); }
    public function withMax(?float $m): self       { return $this->copy(max: $m, maxSet: true); }
    public function withShowLabels(bool $on): self { return $this->copy(showLabels: $on); }

    /**
     * Render bars left-to-right instead of bottom-to-top. Each bar
     * occupies one row and grows horizontally; the label sits in the
     * leftmost column. Mirrors ntcharts' `SetHorizontal`.
     */
    public function withHorizontal(bool $on = true): self
    {
        return $this->copy(horizontal: $on);
    }

    /**
     * Draw an axis line along the chart edge: vertical (┤) on the
     * left in vertical mode, horizontal (┴) along the top in
     * horizontal mode. Mirrors ntcharts' `SetShowAxis`.
     */
    public function withShowAxis(bool $on = true): self
    {
        return $this->copy(showAxis: $on);
    }

    /**
     * Pin every bar to a fixed cell width. Default null means
     * "distribute available width across bars" (the prior behaviour).
     * Mirrors ntcharts' `WithBarWidth`. `null` re-enables auto.
     */
    public function withBarWidth(?int $width): self
    {
        return $this->barWidthCopy(barWidth: $width, barWidthSet: true);
    }

    /**
     * Pin the gap between bars. Default null means "1-cell gap when
     * width allows". `0` packs bars edge-to-edge. Mirrors ntcharts'
     * `WithBarGap`.
     */
    public function withBarGap(?int $gap): self
    {
        return $this->copy(barGap: $gap);
    }

    /**
     * Disable auto-fit on `barWidth` — synonymous with
     * `withBarWidth($w)` once a width is pinned, but expressed as a
     * boolean for parity with ntcharts' `WithNoAutoBarWidth`. With
     * no pinned barWidth this is a no-op.
     */
    public function withNoAutoBarWidth(bool $on = true): self
    {
        if (!$on) {
            return $this->barWidthCopy(barWidth: null, barWidthSet: true);
        }
        return $this;
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

    /** Set the title position separately. */
    public function withTitlePosition(Position $position): self
    {
        return $this->copy(titlePosition: $position);
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

    /** @param iterable<Bar|array{string,float|int}> $bars */
    public function bars(iterable $bars): self        { return $this->withBars($bars); }
    public function size(int $w, int $h): self        { return $this->withSize($w, $h); }
    public function min(?float $m): self              { return $this->withMin($m); }
    public function max(?float $m): self              { return $this->withMax($m); }
    public function showLabels(bool $on = true): self { return $this->withShowLabels($on); }
    public function horizontal(bool $on = true): self { return $this->withHorizontal($on); }
    public function showAxis(bool $on = true): self   { return $this->withShowAxis($on); }
    public function barWidth(?int $width): self       { return $this->withBarWidth($width); }
    public function barGap(?int $gap): self           { return $this->withBarGap($gap); }
    public function legend(bool $on = true): self     { return $this->withLegend($on); }
    public function legendPos(Position $pos): self    { return $this->withLegendPosition($pos); }
    public function legendStyle(?string $char): self  { return $this->withLegendStyle($char); }
    public function title(string $t, Position $p = Position::Top): self { return $this->withTitle($t, $p); }
    public function xLabel(string $label): self       { return $this->withXLabel($label); }
    public function yLabel(string $label): self       { return $this->withYLabel($label); }
    /** @param list<array{label: string, color: string}> $items */
    public function legendItems(array $items): self   { return $this->withLegendItems($items); }

    // ─── Rendering ──────────────────────────────────────────────────────

    public function view(): string
    {
        if ($this->bars === [] || $this->width === 0 || $this->height === 0) {
            return '';
        }

        $chart = $this->renderChart();

        if (!$this->showLegend && $this->title === null && $this->xLabel === null && $this->yLabel === null) {
            return $chart;
        }

        return $this->buildChartWithExtras($chart);
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
     * Render the raw bar chart without legend, title, or labels.
     */
    private function renderChart(): string
    {
        if ($this->bars === [] || $this->width === 0 || $this->height === 0) {
            return '';
        }
        if ($this->horizontal) {
            return $this->renderHorizontal();
        }

        // Drop trailing bars that don't fit the width budget.
        $bars       = $this->bars;
        $count      = count($bars);
        $withGapMax = intdiv($this->width + 1, 2);
        if ($count > $withGapMax) {
            $maxCount = max(0, min($count, $this->width));
            if ($count > $maxCount) {
                $bars  = array_slice($bars, 0, $maxCount);
                $count = $maxCount;
            }
        }
        $values = array_map(static fn(Bar $b): float => $b->value, $bars);
        if ($values === []) {
            return '';
        }

        $min = $this->min ?? min(min($values), 0.0);
        $max = $this->max ?? max($values);
        if ($max === $min) {
            $max = $min + 1.0;
        }

        if ($this->barGap !== null) {
            $gap = $this->barGap;
        } else {
            $gap = $count > 1 && $this->width >= 2 * $count - 1 ? 1 : 0;
        }
        if ($this->barWidth !== null) {
            $colW = $this->barWidth;
        } else {
            $avail = $this->width - ($count - 1) * $gap;
            $colW  = max(1, intdiv($avail, max(1, $count)));
        }

        $renderLabels = $this->showLabels && $this->height >= 2;
        $bodyHeight   = $renderLabels ? $this->height - 1 : $this->height;
        $heights = [];
        foreach ($values as $v) {
            $norm = ($v - $min) / ($max - $min);
            $norm = max(0.0, min(1.0, $norm));
            $heights[] = (int) round($norm * $bodyHeight);
        }

        $rows = [];
        for ($row = $bodyHeight; $row >= 1; $row--) {
            $line = $this->showAxis ? '┤' : '';
            foreach ($heights as $i => $h) {
                $line .= str_repeat($h >= $row ? '█' : ' ', $colW);
                if ($i !== $count - 1 && $gap > 0) {
                    $line .= str_repeat(' ', $gap);
                }
            }
            $rows[] = rtrim($line);
        }
        if ($this->showAxis) {
            $axisLine = '└' . str_repeat('─', max(0, $this->width - 1));
            $rows[] = $axisLine;
        }

        if ($renderLabels) {
            $labelRow = '';
            foreach ($bars as $i => $bar) {
                $label = self::truncate($bar->label, $colW);
                $label = str_pad($label, $colW, ' ', STR_PAD_RIGHT);
                $labelRow .= $label;
                if ($i !== $count - 1 && $gap > 0) {
                    $labelRow .= str_repeat(' ', $gap);
                }
            }
            $rows[] = rtrim($labelRow);
        }
        return implode("\n", $rows);
    }

    /**
     * Bars run left-to-right; one row per bar.
     */
    private function renderHorizontal(): string
    {
        $bars   = $this->bars;
        $count  = min(count($bars), $this->height);
        if ($count === 0) {
            return '';
        }
        $bars   = array_slice($bars, 0, $count);
        $values = array_map(static fn(Bar $b): float => $b->value, $bars);

        $min = $this->min ?? min(min($values), 0.0);
        $max = $this->max ?? max($values);
        if ($max === $min) {
            $max = $min + 1.0;
        }

        $labelGutter = 0;
        if ($this->showLabels) {
            foreach ($bars as $b) {
                $labelGutter = max($labelGutter, mb_strlen($b->label, 'UTF-8'));
            }
            $labelGutter = min($labelGutter, max(1, intdiv($this->width, 3)));
        }
        $axisCol = $this->showAxis ? 1 : 0;
        $barWidth = max(0, $this->width - $labelGutter - ($this->showLabels ? 1 : 0) - $axisCol);

        $rows = [];
        foreach ($bars as $i => $bar) {
            $norm = ($bar->value - $min) / ($max - $min);
            $norm = max(0.0, min(1.0, $norm));
            $filled = (int) round($norm * $barWidth);
            $row = '';
            if ($this->showLabels) {
                $label = self::truncate($bar->label, $labelGutter);
                $row .= str_pad($label, $labelGutter, ' ', STR_PAD_RIGHT) . ' ';
            }
            if ($this->showAxis) {
                $row .= '├';
            }
            $row .= str_repeat('█', $filled);
            $rows[] = rtrim($row);
        }
        return implode("\n", $rows);
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    /**
     * @param iterable<mixed> $bars
     * @return list<Bar>
     */
    private static function coerceBars(iterable $bars): array
    {
        $out = [];
        foreach ($bars as $key => $value) {
            if ($value instanceof Bar) {
                $out[] = $value;
                continue;
            }
            if (is_array($value) && count($value) === 2 && isset($value[0], $value[1])) {
                $out[] = new Bar((string) $value[0], (float) $value[1]);
                continue;
            }
            if (is_string($key)) {
                $out[] = new Bar($key, (float) $value);
                continue;
            }
            $out[] = new Bar((string) $key, (float) $value);
        }
        return $out;
    }

    private static function truncate(string $s, int $max): string
    {
        if ($max <= 0) {
            return '';
        }
        if (mb_strlen($s, 'UTF-8') <= $max) {
            return $s;
        }
        return mb_substr($s, 0, $max, 'UTF-8');
    }

    /**
     * Internal copy-with-overrides helper.
     * Uses flag parameters to distinguish "not passed" from "passed as null".
     *
     * @param list<Bar>                        $bars
     * @param list<array{label: string, color: string}> $legendItems
     */
    private function copy(
        ?array $bars = null,
        ?int $width = null,
        ?int $height = null,
        ?float $min = null,
        bool $minSet = false,
        ?float $max = null,
        bool $maxSet = false,
        ?bool $showLabels = null,
        ?bool $horizontal = null,
        ?bool $showAxis = null,
        ?int $barWidth = null,
        bool $barWidthSet = false,
        ?int $barGap = null,
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
            bars:               $bars               ?? $this->bars,
            width:              $width              ?? $this->width,
            height:             $height             ?? $this->height,
            min:                $minSet ? $min : $this->min,
            max:                $maxSet ? $max : $this->max,
            showLabels:         $showLabels         ?? $this->showLabels,
            horizontal:         $horizontal         ?? $this->horizontal,
            showAxis:           $showAxis           ?? $this->showAxis,
            barWidth:           $barWidthSet ? $barWidth : ($barWidth ?? $this->barWidth),
            barGap:             $barGap             ?? $this->barGap,
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

    /**
     * Specialized copy for barWidth to handle explicit null.
     */
    private function barWidthCopy(?int $barWidth, bool $barWidthSet): self
    {
        return new self(
            bars:               $this->bars,
            width:              $this->width,
            height:             $this->height,
            min:                $this->min,
            max:                $this->max,
            showLabels:         $this->showLabels,
            horizontal:         $this->horizontal,
            showAxis:           $this->showAxis,
            barWidth:           $barWidthSet ? $barWidth : ($barWidth ?? $this->barWidth),
            barGap:             $this->barGap,
            showLegend:         $this->showLegend,
            legendPosition:     $this->legendPosition,
            legendIndicatorChar:$this->legendIndicatorChar,
            title:              $this->title,
            titlePosition:      $this->titlePosition,
            xLabel:             $this->xLabel,
            yLabel:             $this->yLabel,
            legendItems:        $this->legendItems,
        );
    }
}
