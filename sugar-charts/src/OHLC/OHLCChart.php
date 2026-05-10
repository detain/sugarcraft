<?php

declare(strict_types=1);

namespace SugarCraft\Charts\OHLC;

use SugarCraft\Charts\Chart\Position;
use SugarCraft\Charts\Lang;
use SugarCraft\Charts\Canvas\Canvas;
use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Charts\Legend\Legend;

/**
 * OHLC / candlestick chart drawn onto a {@see Canvas}. Each bar gets
 * one column: a vertical wick spanning low → high, with a thicker
 * body covering open ↔ close. Bullish bars (close > open) and bearish
 * bars (close < open) use distinct glyphs and colours so the
 * direction of motion is obvious at a glance.
 *
 * Mirrors ntcharts' `linechart/timeseries/candlestick` and the canvas/graph
 * `drawCandlestick` helper.
 *
 * ```php
 * $bars = [
 *     new Bar(open: 100.0, high: 110.0, low: 95.0,  close: 108.0),
 *     new Bar(open: 108.0, high: 112.0, low: 100.0, close: 102.0),
 *     // ...
 * ];
 * echo OHLCChart::new($bars, 30, 10)->view();
 * ```
 *
 * Axis labels and legend are supported:
 *
 * ```php
 * echo OHLCChart::new($bars, 30, 10)
 *     ->withXLabel('Trading Day')
 *     ->withYLabel('Price $')
 *     ->withLegend(true)
 *     ->withLegendPosition(Position::Right)
 *     ->view();
 * ```
 */
final class OHLCChart
{
    /**
     * @param list<Bar>                            $bars
     * @param list<array{label: string, color: string}> $legendItems
     */
    private function __construct(
        public readonly array $bars,
        public readonly int $width,
        public readonly int $height,
        public readonly ?float $min,
        public readonly ?float $max,
        public readonly string $bodyBullish,
        public readonly string $bodyBearish,
        public readonly string $wick,
        public readonly ?Color $bullishColor,
        public readonly ?Color $bearishColor,
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
            throw new \InvalidArgumentException(Lang::t('ohlc.dim_nonneg'));
        }
    }

    /** @param list<Bar> $bars */
    public static function new(array $bars = [], int $width = 40, int $height = 12): self
    {
        return new self(
            bars:          array_values($bars),
            width:         $width,
            height:        $height,
            min:           null,
            max:           null,
            bodyBullish:   '█',
            bodyBearish:   '▒',
            wick:          '│',
            bullishColor:  Color::ansi(10),  // bright green
            bearishColor:  Color::ansi(9),   // bright red
        );
    }

    /** @param list<Bar> $bars */
    public function withBars(array $bars): self
    {
        return $this->copy(bars: array_values($bars));
    }

    public function push(Bar $bar): self
    {
        return $this->copy(bars: [...$this->bars, $bar]);
    }

    public function withSize(int $w, int $h): self
    {
        if ($w < 0 || $h < 0) {
            throw new \InvalidArgumentException(Lang::t('ohlc.dim_nonneg'));
        }
        return $this->copy(width: $w, height: $h);
    }

    public function withMin(?float $m): self  { return $this->copy(min: $m); }
    public function withMax(?float $m): self  { return $this->copy(max: $m); }
    public function withBodyRunes(string $bull, string $bear): self
    {
        return $this->copy(bodyBullish: $bull, bodyBearish: $bear);
    }
    public function withWickRune(string $rune): self
    {
        return $this->copy(wick: $rune);
    }
    public function withColors(?Color $bullish, ?Color $bearish): self
    {
        return $this->copy(bullishColor: $bullish, bearishColor: $bearish);
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

    /** @param list<Bar> $bars */
    public function bars(array $bars): self                    { return $this->withBars($bars); }
    public function size(int $w, int $h): self                 { return $this->withSize($w, $h); }
    public function min(?float $m): self                       { return $this->withMin($m); }
    public function max(?float $m): self                       { return $this->withMax($m); }
    public function bodyRunes(string $bull, string $bear): self { return $this->withBodyRunes($bull, $bear); }
    public function wickRune(string $rune): self               { return $this->withWickRune($rune); }
    public function colors(?Color $bullish, ?Color $bearish): self { return $this->withColors($bullish, $bearish); }
    public function legend(bool $on = true): self              { return $this->withLegend($on); }
    public function legendPos(Position $pos): self             { return $this->withLegendPosition($pos); }
    public function legendStyle(?string $char): self           { return $this->withLegendStyle($char); }
    public function title(string $t, Position $p = Position::Top): self { return $this->withTitle($t, $p); }
    public function xLabel(string $label): self                { return $this->withXLabel($label); }
    public function yLabel(string $label): self                { return $this->withYLabel($label); }
    /** @param list<array{label: string, color: string}> $items */
    public function legendItems(array $items): self            { return $this->withLegendItems($items); }

    // ─── Rendering ──────────────────────────────────────────────────────

    public function view(): string
    {
        if ($this->bars === [] || $this->width === 0 || $this->height === 0) {
            return (new Canvas($this->width, $this->height))->view();
        }

        $chart = $this->renderChart();

        if (!$this->showLegend && $this->title === null && $this->xLabel === null && $this->yLabel === null) {
            return $chart;
        }

        return $this->buildChartWithExtras($chart);
    }

    /**
     * Render the raw OHLC chart without legend, title, or labels.
     */
    private function renderChart(): string
    {
        $bars = $this->bars;
        if (count($bars) > $this->width) {
            $bars = array_slice($bars, -$this->width);
        }
        $count = count($bars);

        // Compute global range across all OHLC values.
        $values = [];
        foreach ($bars as $b) {
            $values[] = $b->high;
            $values[] = $b->low;
        }
        $min = $this->min ?? min($values);
        $max = $this->max ?? max($values);
        if ($max == $min) { $max = $min + 1.0; }

        $canvas = new Canvas($this->width, $this->height);

        $rowFor = function (float $v) use ($min, $max): int {
            $norm = ((float) $v - $min) / ($max - $min);
            $norm = max(0.0, min(1.0, $norm));
            return (int) round((1.0 - $norm) * ($this->height - 1));
        };

        foreach ($bars as $i => $bar) {
            $col = $count <= 1
                ? 0
                : (int) round($i * ($this->width - 1) / ($count - 1));
            $highRow  = $rowFor($bar->high);
            $lowRow   = $rowFor($bar->low);
            $bodyTop  = $rowFor($bar->bodyTop());
            $bodyBot  = $rowFor($bar->bodyBottom());

            $bull = $bar->isBullish();
            $color = $bull ? $this->bullishColor : $this->bearishColor;
            $body  = $bull ? $this->bodyBullish  : $this->bodyBearish;
            $style = $color !== null ? Style::new()->foreground($color) : null;

            // Wick: high → low, with body cells overwritten.
            for ($r = $highRow; $r <= $lowRow; $r++) {
                $rune = ($r >= $bodyTop && $r <= $bodyBot) ? $body : $this->wick;
                $canvas->setCell($col, $r, $rune, $style);
            }
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

    public function __toString(): string { return $this->view(); }

    /**
     * Internal copy-with-overrides helper.
     *
     * @param list<Bar>                            $bars
     * @param list<array{label: string, color: string}> $legendItems
     */
    private function copy(
        ?array $bars = null,
        ?int $width = null,
        ?int $height = null,
        ?float $min = null,
        ?float $max = null,
        ?string $bodyBullish = null,
        ?string $bodyBearish = null,
        ?string $wick = null,
        ?Color $bullishColor = null,
        ?Color $bearishColor = null,
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
            min:                $min                ?? $this->min,
            max:                $max                ?? $this->max,
            bodyBullish:        $bodyBullish        ?? $this->bodyBullish,
            bodyBearish:        $bodyBearish        ?? $this->bodyBearish,
            wick:               $wick               ?? $this->wick,
            bullishColor:       $bullishColor       ?? $this->bullishColor,
            bearishColor:       $bearishColor       ?? $this->bearishColor,
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
