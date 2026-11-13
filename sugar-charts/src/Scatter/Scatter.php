<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Scatter;

use SugarCraft\Charts\Chart\ChartExtras;
use SugarCraft\Charts\Chart\ChromeAccessors;
use SugarCraft\Charts\Chart\Position;
use SugarCraft\Charts\Lang;
use SugarCraft\Charts\Support\Finite;
use SugarCraft\Charts\Support\UntrustedText;
use SugarCraft\Charts\Support\Range;
use SugarCraft\Charts\Support\Rune;
use SugarCraft\Charts\Canvas\Canvas;
use SugarCraft\Charts\Legend\Legend;
use SugarCraft\Charts\Support\NumberLabel;
use SugarCraft\Core\Util\Width;

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
final class Scatter implements ChromeAccessors
{
    use ChartExtras;
    /**
     * Point glyph as stored after the {@see \SugarCraft\Charts\Support\Rune}
     * ingress door (sugar-charts-002): declared and assigned in the
     * constructor body so only the guarded single-cell value can be stored.
     */
    public readonly string $rune;
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
        string $rune,
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
            throw new \InvalidArgumentException(Lang::t('scatter.dim_nonneg'));
        }
        if ($titlePosition === Position::Left || $titlePosition === Position::Right) {
            throw new \InvalidArgumentException(Lang::t('chart.title_side', ['side' => strtolower($titlePosition->name)]));
        }
        // single door for every copy()/with* rebuild — pinned axis
        // ranges must be finite and ordered; null ends stay legal ("auto").
        Range::pin($minX, $maxX, 'X');
        Range::pin($minY, $maxY, 'Y');
        // sugar-charts-002: one sweep point for the rune, covering
        // new(), withRune()/rune() and every copy() rebuild.
        $this->rune = Rune::cell($rune, 'scatter point rune');
    }

    /** @param list<array{0:int|float,1:int|float}> $points */
    public static function new(array $points = [], int $width = 40, int $height = 8): self
    {
        self::assertFinitePoints($points);
        return new self(array_values($points), $width, $height, null, null, null, null, '*');
    }

    /** @param list<array{0:int|float,1:int|float}> $points */
    public function withPoints(array $points): self
    {
        self::assertFinitePoints($points);
        return $this->copy(points: array_values($points));
    }

    /**
     * Ingestion guard: reject any point whose X or Y is non-finite, which
     * would otherwise defeat the range maths in {@see renderChart()}.
     *
     * @param list<array{0:int|float,1:int|float}> $points
     */
    private static function assertFinitePoints(array $points): void
    {
        foreach ($points as $p) {
            // sugar-charts-063: the (float) pre-cast made 'abc' a silent
            // 0.0 plotted at the origin — gate the shape before the cast.
            if (!is_array($p) || !isset($p[0], $p[1]) || !is_numeric($p[0]) || !is_numeric($p[1])) {
                throw new \InvalidArgumentException(Lang::t('scatter.point_numeric', [
                    'given' => get_debug_type($p),
                ]));
            }
            Finite::assert((float) $p[0]);
            Finite::assert((float) $p[1]);
        }
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
        return $this->copy(minX: $min, minXSet: true, maxX: $max, maxXSet: true);
    }

    public function withYRange(?float $min, ?float $max): self
    {
        return $this->copy(minY: $min, minYSet: true, maxY: $max, maxYSet: true);
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
        return $this->copy(legendIndicatorChar: $indicatorChar === null ? null : Rune::cell($indicatorChar, 'legend indicator'), legendIndicatorCharSet: true);
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
     * Show the value-range endpoint gutter (sugar-charts-033). ON by
     * default for Scatter — a bare rune cloud without scale is the
     * defect this opt-out guards against, not the desired posture.
     */
    public function withAxes(bool $on = true): self
    {
        return $this->copy(showAxes: $on);
    }

    // ─── Short-form Aliases ─────────────────────────────────────────────

    /** @param list<array{0:int|float,1:int|float}> $points */
    public function points(array $points): self              { return $this->withPoints($points); }
    public function size(int $w, int $h): self               { return $this->withSize($w, $h); }
    public function xRange(?float $min, ?float $max): self   { return $this->withXRange($min, $max); }
    public function yRange(?float $min, ?float $max): self   { return $this->withYRange($min, $max); }
    public function rune(string $rune): self                 { return $this->withRune($rune); }
    public function axes(bool $on = true): self              { return $this->withAxes($on); }
    public function legend(bool $on = true): self            { return $this->withLegend($on); }
    public function legendPos(Position $pos): self           { return $this->withLegendPosition($pos); }
    public function legendStyle(?string $char): self         { return $this->withLegendStyle($char); }
    public function title(?string $t = null, Position $p = Position::Top): self { return $this->withTitle($t, $p); }
    public function xLabel(?string $label = null): self              { return $this->withXLabel($label); }
    public function yLabel(?string $label = null): self              { return $this->withYLabel($label); }
    /** @param list<array{label: string, color: string}> $items */
    public function legendItems(array $items): self          { return $this->withLegendItems($items); }

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
     * no points or the dimensions collapse — see BarChart::view() for
     * the family of per-class empty shapes (pinned by
     * EmptyRenderContractTest).
     */
    public function view(): string
    {
        if ($this->points === [] || $this->width === 0 || $this->height === 0) {
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
     * Render the raw scatter plot without legend, title, or labels.
     */
    private function renderChart(): string
    {
        // sugar-charts-018: scan ONLY the null ends. A pinned bound is
        // honored even when the data sits inside it — the fold that used
        // to widen pinned seeds toward the data made pins unobservable.
        $sMinX = $sMaxX = $sMinY = $sMaxY = null;
        foreach ($this->points as $p) {
            $x = (float) $p[0];
            $y = (float) $p[1];
            $sMinX = $sMinX === null ? $x : min($sMinX, $x);
            $sMaxX = $sMaxX === null ? $x : max($sMaxX, $x);
            $sMinY = $sMinY === null ? $y : min($sMinY, $y);
            $sMaxY = $sMaxY === null ? $y : max($sMaxY, $y);
        }
        $minX = $this->minX ?? $sMinX;
        $maxX = $this->maxX ?? $sMaxX;
        $minY = $this->minY ?? $sMinY;
        $maxY = $this->maxY ?? $sMaxY;
        // A lone pinned end that excludes the whole data set yields an
        // inverted effective domain — refuse it here (sugar-charts-056
        // now reaches Scatter's live path; the ctor only sees pins).
        Range::pin($minX, $maxX, 'X');
        Range::pin($minY, $maxY, 'Y');
        if ($maxX == $minX) { $maxX = $minX + 1.0; }
        if ($maxY == $minY) { $maxY = $minY + 1.0; }

        // sugar-charts-033: quantitative context by default — the Y-range
        // endpoints ride a left gutter so a bare cloud of runes is never
        // scale-less. Opt out with withAxes(false).
        $gW = 0;
        if ($this->showAxes) {
            $gW = max(
                Width::string(NumberLabel::format((float) $minY)),
                Width::string(NumberLabel::format((float) $maxY)),
            );
        }
        $canvas = new Canvas($gW > 0 ? max(1, $this->width - $gW - 1) : $this->width, $this->height);
        foreach ($this->points as $p) {
            $x = (float) $p[0];
            $y = (float) $p[1];
            // Clamped to the plot like LineChart::rowForValue — a point
            // outside a manually pinned range lands on the edge column/row
            // instead of being silently dropped off-canvas.
            $col = (int) round(self::norm($x, $minX, $maxX) * ($canvas->width() - 1));
            // Y is inverted so larger values sit at the top.
            $row = (int) round((1.0 - self::norm($y, $minY, $maxY)) * ($this->height - 1));
            $canvas->setCell($col, $row, $this->rune);
        }
        if ($gW === 0) {
            return $canvas->view();
        }
        $rows  = explode("\n", $canvas->view());
        $top   = str_pad(NumberLabel::format((float) $maxY), $gW, ' ', STR_PAD_LEFT);
        $bot   = str_pad(NumberLabel::format((float) $minY), $gW, ' ', STR_PAD_LEFT);
        $blank = str_repeat(' ', $gW);
        $last  = count($rows) - 1;
        foreach ($rows as $i => $line) {
            $prefix   = $i === 0 ? $top : ($i === $last ? $bot : $blank);
            $rows[$i] = $prefix . '│' . $line;
        }
        return implode("\n", $rows);
    }

    /**
     * Normalized position of $value within [$min,$max], clamped to [0,1].
     */
    private static function norm(float $value, float $min, float $max): float
    {
        $t = ($value - $min) / ($max - $min);
        return max(0.0, min(1.0, $t));
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
        bool $minXSet = false,
        ?float $maxX = null,
        bool $maxXSet = false,
        ?float $minY = null,
        bool $minYSet = false,
        ?float $maxY = null,
        bool $maxYSet = false,
        ?string $rune = null,
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
            points:             $points             ?? $this->points,
            width:              $width              ?? $this->width,
            height:             $height             ?? $this->height,
            minX:               $minXSet ? $minX : $this->minX,
            maxX:               $maxXSet ? $maxX : $this->maxX,
            minY:               $minYSet ? $minY : $this->minY,
            maxY:               $maxYSet ? $maxY : $this->maxY,
            rune:               $rune               ?? $this->rune,
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
