<?php

declare(strict_types=1);

namespace SugarCraft\Charts\OHLC;

use SugarCraft\Charts\Support\ColorLadder;
use SugarCraft\Charts\Support\MemoizesRender;
use SugarCraft\Charts\Chart\ChartExtras;
use SugarCraft\Charts\Chart\ChromeAccessors;
use SugarCraft\Charts\Chart\Position;
use SugarCraft\Charts\Lang;
use SugarCraft\Charts\Support\Range;
use SugarCraft\Charts\Support\Rune;
use SugarCraft\Charts\Support\NumberLabel;
use SugarCraft\Charts\Support\UntrustedText;
use SugarCraft\Charts\Canvas\Canvas;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Style;

/**
 * OHLC / candlestick chart drawn onto a {@see Canvas}. Each bar gets
 * one column: a vertical wick spanning low → high, with a thicker
 * body covering open ↔ close. Bullish bars (close > open) and bearish
 * bars (close < open) use distinct glyphs and colors so the
 * direction of motion is obvious at a glance. Doji bars (open == close,
 * a no-change period) carry their own glyph and color arm (sugar-charts-101)
 * instead of silently bearing the bearish body.
 *
 * Mirrors ntcharts' `linechart/timeseries/candlestick` and the canvas/graph
 * `drawCandlestick` helper.
 *
 * ```php
 * $bars = [
 *     new Candle(open: 100.0, high: 110.0, low: 95.0,  close: 108.0),
 *     new Candle(open: 108.0, high: 112.0, low: 100.0, close: 102.0),
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
final class OHLCChart implements ChromeAccessors
{
    use MemoizesRender;
    use ChartExtras;
    /**
     * Candle glyphs as stored after the {@see \SugarCraft\Charts\Support\Rune}
     * ingress doors (sugar-charts-003): declared and assigned in the
     * constructor body so only guarded single-cell values can be stored.
     */
    public readonly string $bodyBullish;
    public readonly string $bodyBearish;
    public readonly string $bodyDoji;
    public readonly string $wick;
    /**
     * @param list<Candle>                            $bars
     * @param list<array{label: string, color: string}> $legendItems
     */
    private function __construct(
        public readonly array $bars,
        public readonly int $width,
        public readonly int $height,
        public readonly ?float $min,
        public readonly ?float $max,
        string $bodyBullish,
        string $bodyBearish,
        string $bodyDoji,
        string $wick,
        public readonly ?Color $bullishColor,
        public readonly ?Color $bearishColor,
        public readonly ?Color $dojiColor,
        public readonly bool $showLegend = false,
        public readonly Position $legendPosition = Position::Right,
        public readonly ?string $legendIndicatorChar = null,
        public readonly ?string $title = null,
        public readonly Position $titlePosition = Position::Top,
        public readonly ?string $xLabel = null,
        public readonly ?string $yLabel = null,
        private readonly array $legendItems = [],
        public readonly bool $showAxes = true,
    ) {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException(Lang::t('ohlc.dim_nonneg'));
        }
        if ($titlePosition === Position::Left || $titlePosition === Position::Right) {
            throw new \InvalidArgumentException(Lang::t('chart.title_side', ['side' => strtolower($titlePosition->name)]));
        }
        // pinned price range must be finite and ordered.
        Range::pin($min, $max, 'Y');
        // sugar-charts-003/-064: one sweep point for the four candle
        // runes and the bar element type, covering new(), push(),
        // withBars()/bars() and withBodyRunes()/withWickRune() rebuilds.
        foreach ($bars as $bar) {
            if (!$bar instanceof Candle) {
                throw new \InvalidArgumentException(Lang::t('ohlc.bar_type', [
                    'given' => get_debug_type($bar),
                ]));
            }
        }
        $this->bodyBullish = Rune::cell($bodyBullish, 'OHLC bullish body rune');
        $this->bodyBearish = Rune::cell($bodyBearish, 'OHLC bearish body rune');
        $this->bodyDoji    = Rune::cell($bodyDoji, 'OHLC doji body rune');
        $this->wick        = Rune::cell($wick, 'OHLC wick rune');
    }

    /** @param list<Candle> $bars */
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
            bodyDoji:      '─',
            wick:          '│',
            bullishColor:  Color::ansi(10),  // bright green
            bearishColor:  Color::ansi(9),   // bright red
            dojiColor:     Color::ansi(7),   // white — the no-change period
        );
    }

    /**
     * Replace the full bar set (the explicit replace door — it stores
     * exactly what is given, windowing applies to {@see push()} only).
     *
     * @param list<Candle> $bars
     */
    public function withBars(array $bars): self
    {
        return $this->copy(bars: array_values($bars));
    }

    /**
     * Append one bar, windowing the stored history to the chart width
     * (sugar-charts-062, mirroring LineChart::push's sliding window): the
     * renderer already paints only the last `$width` bars, so older bars
     * were retained as history the chart could never show — streamed
     * pushes grew the stored list without bound while the render slice
     * kept discarding the same prefix. Documented semantic change: after
     * the window fills, the oldest bar is dropped at push time rather
     * than at render time. Growing `$width` later cannot resurrect
     * dropped bars; {@see withBars()} is the full-replace door.
     */
    public function push(Candle $bar): self
    {
        $bars   = [...$this->bars, $bar];
        $window = $this->width > 0 ? $this->width : 1;
        if (count($bars) > $window) {
            $bars = array_slice($bars, -$window);
        }
        return $this->copy(bars: $bars);
    }

    public function withSize(int $w, int $h): self
    {
        if ($w < 0 || $h < 0) {
            throw new \InvalidArgumentException(Lang::t('ohlc.dim_nonneg'));
        }
        return $this->copy(width: $w, height: $h);
    }

    public function withMin(?float $m): self  { return $this->copy(min: $m, minSet: true); }
    public function withMax(?float $m): self  { return $this->copy(max: $m, maxSet: true); }
    /**
     * Set the candle body glyphs. `$doji` (sugar-charts-101) covers the
     * no-change arm; pass null to keep the currently stored doji rune.
     */
    public function withBodyRunes(string $bull, string $bear, ?string $doji = null): self
    {
        return $this->copy(bodyBullish: $bull, bodyBearish: $bear, bodyDoji: $doji);
    }
    public function withWickRune(string $rune): self
    {
        return $this->copy(wick: $rune);
    }
    /** Set the doji (open == close) stroke color; null paints it unstyled. */
    public function withDojiColor(?Color $doji): self
    {
        return $this->copy(dojiColor: $doji, dojiColorSet: true);
    }
    public function withColors(?Color $bullish, ?Color $bearish): self
    {
        return $this->copy(bullishColor: $bullish, bullishColorSet: true, bearishColor: $bearish, bearishColorSet: true);
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

    /**
     * Customize the legend indicator glyph (one cell-wide rune). Passing
     * null clears it back to the default bullet.
     */
    public function withLegendIndicator(?string $indicatorChar = null): self
    {
        return $this->copy(legendIndicatorChar: $indicatorChar === null ? null : Rune::cell($indicatorChar, 'legend indicator'), legendIndicatorCharSet: true);
    }

    /**
     * @deprecated use {@see self::withLegendIndicator()}: the argument is
     *             the indicator RUNE, not a style. Kept one minor as the
     *             house alias window; REMOVED-IN 2.0.0.
     */
    public function withLegendStyle(?string $indicatorChar = null): self
    {
        return $this->withLegendIndicator($indicatorChar);
    }

    /** Set the chart title. */
    public function withTitle(?string $title = null, Position $position = Position::Top): self
    {
        return $this->copy(title: $title === null ? null : UntrustedText::guard($title), titleSet: true, titlePosition: $position);
    }

    /** Set the X-axis label (rendered at bottom). */
    public function withXLabel(?string $label = null): self
    {
        return $this->copy(xLabel: $label === null ? null : UntrustedText::guard($label), xLabelSet: true);
    }

    /** Set the Y-axis label (prepended to each line). */
    public function withYLabel(?string $label = null): self
    {
        return $this->copy(yLabel: $label === null ? null : UntrustedText::guard($label), yLabelSet: true);
    }

    /**
     * Set legend items directly (label + color pairs).
     *
     * @param list<array{label: string, color: string}> $items
     */
    public function withLegendItems(array $items): self
    {
        return $this->copy(legendItems: UntrustedText::guardItems($items));
    }

    /**
     * Show the price-range endpoint gutter (sugar-charts-033). ON by
     * default; withAxes(false) restores the bare candle frame.
     */
    public function withAxes(bool $on = true): self
    {
        return $this->copy(showAxes: $on);
    }

    // ─── Short-form Aliases ─────────────────────────────────────────────

    /** @param list<Candle> $bars */
    public function bars(array $bars): self                    { return $this->withBars($bars); }
    public function size(int $w, int $h): self                 { return $this->withSize($w, $h); }
    public function axes(bool $on = true): self                 { return $this->withAxes($on); }
    public function min(?float $m): self                       { return $this->withMin($m); }
    public function max(?float $m): self                       { return $this->withMax($m); }
    public function bodyRunes(string $bull, string $bear, ?string $doji = null): self { return $this->withBodyRunes($bull, $bear, $doji); }
    public function wickRune(string $rune): self               { return $this->withWickRune($rune); }
    public function dojiColor(?Color $doji): self              { return $this->withDojiColor($doji); }
    public function colors(?Color $bullish, ?Color $bearish): self { return $this->withColors($bullish, $bearish); }
    public function legend(bool $on = true): self              { return $this->withLegend($on); }
    public function legendPos(Position $pos): self             { return $this->withLegendPosition($pos); }
    /**
     * Dual-form knob: bare `legendIndicator()` reads the rune, an argument
     * (null clears) sets it via {@see withLegendIndicator()}.
     */
    public function legendIndicator(?string $char = null): string|self|null
    {
        return func_num_args() === 0 ? $this->legendIndicatorChar : $this->withLegendIndicator($char);
    }

    /** @deprecated use {@see self::legendIndicator()}; REMOVED-IN 2.0.0. */
    public function legendStyle(?string $char): self { return $this->withLegendIndicator($char); }
    public function title(?string $t = null, Position $p = Position::Top): self { return $this->withTitle($t, $p); }
    public function xLabel(?string $label = null): self                { return $this->withXLabel($label); }
    public function yLabel(?string $label = null): self                { return $this->withYLabel($label); }
    /** @param list<array{label: string, color: string}> $items */
    public function legendItems(array $items): self            { return $this->withLegendItems($items); }

    // ─── ChartExtras Getters ────────────────────────────────────────────

    public function chartExtrasGetWidth(): int   { return $this->width; }
    public function chartExtrasShowLegend(): bool { return $this->showLegend; }
    /** @return list<array{label: string, color: string}> */
    public function chartExtrasGetLegendItems(): array { return $this->legendItems; }
    public function chartExtrasGetLegendPosition(): Position { return $this->legendPosition; }
    public function chartExtrasGetLegendIndicatorChar(): ?string { return $this->legendIndicatorChar; }
    public function chartExtrasGetTitle(): ?string { return $this->title; }
    public function chartExtrasGetTitlePosition(): Position { return $this->titlePosition; }
    public function chartExtrasGetXLabel(): ?string { return $this->xLabel; }
    public function chartExtrasGetYLabel(): ?string { return $this->yLabel; }

    // ─── Rendering ──────────────────────────────────────────────────────

    /**
     * Empty-data contract: returns '' (no canvas at all) when there are
     * no bars or the dimensions collapse — see BarChart::view() for the
     * family of per-class empty shapes (pinned by EmptyRenderContractTest).
     */
    public function view(): string
    {
        // CH-34 (sugar-charts-112): compose once, serve repeats from the
        // per-instance memo; mutate paths build new objects and start
        // empty. These renderers have no caller-owned canvas bridge.
        return ColorLadder::guard($this->memoizedRender(fn(): string => $this->composeView()));
    }

    private function composeView(): string
    {
        if ($this->bars === [] || $this->width === 0 || $this->height === 0) {
            return '';
        }

        $chart = $this->renderChart();

        if (!$this->showLegend && $this->title === null && $this->xLabel === null && $this->yLabel === null) {
            return $chart;
        }

        $parts = $this->buildChartWithExtras($chart);
        return implode("\n", $parts);
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
        [$min, $max] = Range::resolve($this->min, $this->max, min($values), max($values), 'Y');
        if ($max == $min) { $max = $min + 1.0; }

        // sugar-charts-033: price-range endpoints on a left gutter by
        // default; withAxes(false) restores the bare frame.
        $gW = 0;
        if ($this->showAxes) {
            $gW = max(Width::string(NumberLabel::format($min)), Width::string(NumberLabel::format($max)));
        }
        $plotW  = $gW > 0 ? max(1, $this->width - $gW - 1) : $this->width;
        $canvas = new Canvas($plotW, $this->height);

        $rowFor = function (float $v) use ($min, $max): int {
            $norm = ((float) $v - $min) / ($max - $min);
            $norm = max(0.0, min(1.0, $norm));
            return (int) round((1.0 - $norm) * ($this->height - 1));
        };

        foreach ($bars as $i => $bar) {
            $col = $count <= 1
                ? 0
                : (int) round($i * ($plotW - 1) / ($count - 1));
            $highRow  = $rowFor($bar->high);
            $lowRow   = $rowFor($bar->low);
            $bodyTop  = $rowFor($bar->bodyTop());
            $bodyBot  = $rowFor($bar->bodyBottom());

            // sugar-charts-101: three arms — bullish, doji (open == close,
            // satisfies neither direction predicate), bearish. Before the
            // doji arm existed, a no-change period bore the bearish ▒ in
            // red and read as a down period.
            $isDoji = !$bar->isBullish() && !$bar->isBearish();
            $color  = $isDoji ? $this->dojiColor
                : ($bar->isBullish() ? $this->bullishColor : $this->bearishColor);
            $body   = $isDoji ? $this->bodyDoji
                : ($bar->isBullish() ? $this->bodyBullish : $this->bodyBearish);
            $style = $color !== null ? Style::new()->foreground($color) : null;

            // Wick: high → low, with body cells overwritten.
            for ($r = $highRow; $r <= $lowRow; $r++) {
                $rune = ($r >= $bodyTop && $r <= $bodyBot) ? $body : $this->wick;
                $canvas->setCell($col, $r, $rune, $style);
            }
        }
        if ($gW === 0) {
            return $canvas->view();
        }
        $rows  = explode("\n", $canvas->view());
        $top   = str_pad(NumberLabel::format($max), $gW, ' ', STR_PAD_LEFT);
        $bot   = str_pad(NumberLabel::format($min), $gW, ' ', STR_PAD_LEFT);
        $blank = str_repeat(' ', $gW);
        $last  = count($rows) - 1;
        foreach ($rows as $i => $line) {
            $prefix   = $i === 0 ? $top : ($i === $last ? $bot : $blank);
            $rows[$i] = $prefix . '│' . $line;
        }
        return implode("\n", $rows);
    }

    public function __toString(): string { return $this->view(); }

    /**
     * Internal copy-with-overrides helper.
     *
     * @param list<Candle>                            $bars
     * @param list<array{label: string, color: string}> $legendItems
     */
    /**
     * House mutate(): positional copy-with-overrides feeding the constructor
     * (see CALIBER_LEARNINGS.md [convention:sugar-charts] — why not the core
     * Mutable trait). Null means "keep current"; behaviorally identical to a
     * with*-by-positional-args builder.
     */
    private function copy(
        ?array $bars = null,
        ?int $width = null,
        ?int $height = null,
        ?float $min = null,
        bool $minSet = false,
        ?float $max = null,
        bool $maxSet = false,
        ?string $bodyBullish = null,
        ?string $bodyBearish = null,
        ?string $bodyDoji = null,
        ?string $wick = null,
        ?Color $bullishColor = null,
        bool $bullishColorSet = false,
        ?Color $bearishColor = null,
        bool $bearishColorSet = false,
        ?Color $dojiColor = null,
        bool $dojiColorSet = false,
        ?bool $showLegend = null,
        ?Position $legendPosition = null,
        ?string $legendIndicatorChar = null,
        bool $legendIndicatorCharSet = false,
        ?string $title = null,
        bool $titleSet = false,
        ?Position $titlePosition = null,
        ?string $xLabel = null,
        bool $xLabelSet = false,
        ?string $yLabel = null,
        bool $yLabelSet = false,
        ?array $legendItems = null,
        ?bool $showAxes = null,
    ): self {
        return new self(
            bars:               $bars               ?? $this->bars,
            width:              $width              ?? $this->width,
            height:             $height             ?? $this->height,
            min:                $minSet ? $min : $this->min,
            max:                $maxSet ? $max : $this->max,
            bodyBullish:        $bodyBullish        ?? $this->bodyBullish,
            bodyBearish:        $bodyBearish        ?? $this->bodyBearish,
            bodyDoji:           $bodyDoji           ?? $this->bodyDoji,
            wick:               $wick               ?? $this->wick,
            bullishColor:       $bullishColorSet ? $bullishColor : $this->bullishColor,
            bearishColor:       $bearishColorSet ? $bearishColor : $this->bearishColor,
            dojiColor:          $dojiColorSet ? $dojiColor : $this->dojiColor,
            showLegend:         $showLegend         ?? $this->showLegend,
            legendPosition:     $legendPosition     ?? $this->legendPosition,
            legendIndicatorChar: ($legendIndicatorCharSet || $legendIndicatorChar !== null) ? $legendIndicatorChar : $this->legendIndicatorChar,
            title:              ($titleSet || $title !== null) ? $title : $this->title,
            titlePosition:      $titlePosition      ?? $this->titlePosition,
            xLabel:             ($xLabelSet || $xLabel !== null) ? $xLabel : $this->xLabel,
            yLabel:             ($yLabelSet || $yLabel !== null) ? $yLabel : $this->yLabel,
            legendItems:        $legendItems        ?? $this->legendItems,
            showAxes:           $showAxes           ?? $this->showAxes,
        );
    }
}
