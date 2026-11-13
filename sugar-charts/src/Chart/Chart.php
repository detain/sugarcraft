<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Chart;

use SugarCraft\Charts\Legend\Legend;
use SugarCraft\Charts\Lang;
use SugarCraft\Charts\Support\CellGrid;
use SugarCraft\Charts\Support\UntrustedText;
use SugarCraft\Charts\Support\Rune;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Theme;

/**
 * Base chart class providing common features like legend, title,
 * axis labels, and data labels.
 *
 * Chart implementations should extend this class and implement
 * the `renderChart()` method to produce the raw chart output.
 * The `view()` method will then composite the chart with legend,
 * title, and labels through the shared ChartExtras compositor —
 * the same body the BarChart/Scatter/OHLC renderers use.
 */
abstract class Chart implements ChromeAccessors
{
    use ChartExtras;

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
        /** @var float Animation progress 0.0-1.0 (1.0 = fully rendered) */
        private float $animationProgress = 1.0,
        /** @var int Animation duration in milliseconds (0 = instant) */
        private int $animationDuration = 0,
        ?CellGrid $canvas = null,
        bool $braille = false,
        ?Theme $theme = null,
    ) {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException(Lang::t('chart.dim_nonneg'));
        }
        if ($titlePosition === Position::Left || $titlePosition === Position::Right) {
            throw new \InvalidArgumentException(Lang::t('chart.title_side', ['side' => strtolower($titlePosition->name)]));
        }
        $this->canvas = $canvas;
        $this->braille = $braille;
        $this->theme = $theme;
    }

    /** @var CellGrid|null grid the render is painted into (sugar-charts-007: a real bridge, not a flag) */
    public readonly ?CellGrid $canvas;

    /** @var bool render the series as braille dot-matrix instead of character cells */
    public readonly bool $braille;

    /** @var Theme|null Named color theme for rendering */
    public readonly ?Theme $theme;

    /**
     * Render the raw chart content without legend, title, or labels.
     */
    abstract protected function renderChart(): string;

    /**
     * Compose and return the full chart output with legend, title, and labels.
     *
     * Family empty-render rule (sugar-charts-087), stated once here and
     * pinned by EmptyRenderContractTest: a declared width or height below 1
     * renders '' on EVERY renderer (zero is no longer an off-switch —
     * Canvas itself refuses <1×1 outright), empty data renders '' on the
     * grid renderers, and LineChart's declared divergence is a blank
     * width×height canvas for empty data / progress ≤ 0. Sparkline keeps
     * its stamped width×spaces divergence; TimeSeries adds the
     * '[no points in range]' notice for a filtered-empty window.
     */
    public function view(): string
    {
        $chart = $this->renderChart();

        // CH-08: zero dims render '' no matter what chrome is stacked on
        // top — an extras column over a nonexistent canvas is noise.
        if ($this->width < 1 || $this->height < 1) {
            return '';
        }

        // NOTE: the early return deliberately does NOT test showDataLabels.
        // No composer at this level draws value labels (LineChart's
        // dot-per-column geometry has no room for them — see
        // supportsDataLabels() for the honest capability surface; BarChart
        // renders labels through its own API). Gating here would only
        // re-wrap the bare chart through an empty extras pass.
        if (!$this->showLegend && $this->title === null && $this->xLabel === null && $this->yLabel === null) {
            return $chart;
        }

        $parts = $this->buildChartWithExtras($chart);
        return implode("\n", $parts);
    }

    /**
     * Legend composition for this family: recolored from the active theme
     * when one is set (the ChartExtras renderers carry no theme surface,
     * so the trait's palette-blind body serves them unchanged).
     */
    public function chartExtrasBuildLegend(): Legend
    {
        $items = $this->theme === null ? $this->legendItems : $this->themeLegendItems();

        $legend = Legend::new($items)
            ->withPosition($this->legendPosition);

        if ($this->legendIndicatorChar !== null) {
            $legend = $legend->withIndicatorChar($this->legendIndicatorChar);
        }

        return $legend;
    }

    // ─── ChromeAccessors ──────────────────────────────────────────────────

    public function chartExtrasGetWidth(): int        { return $this->width; }
    public function chartExtrasShowLegend(): bool     { return $this->showLegend; }
    /** @return list<array{label: string, color: string}> */
    public function chartExtrasGetLegendItems(): array { return $this->legendItems; }
    public function chartExtrasGetLegendPosition(): Position { return $this->legendPosition; }
    public function chartExtrasGetLegendIndicatorChar(): ?string { return $this->legendIndicatorChar; }
    public function chartExtrasGetTitle(): ?string    { return $this->title; }
    public function chartExtrasGetTitlePosition(): Position { return $this->titlePosition; }
    public function chartExtrasGetXLabel(): ?string   { return $this->xLabel; }
    public function chartExtrasGetYLabel(): ?string   { return $this->yLabel; }

    /**
     * The theme's series-color palette, in render order. Seven color roles
     * cycle across series, so a seven-dataset chart still gets a distinct
     * hue per line. WHY these roles: primary/accent carry the two hero
     * colors of every shipped theme; info/success/warning/secondary cover
     * mid-range data hues; error closes the set. Mirrors sugar-style's
     * named `Theme` color roles.
     *
     * @return non-empty-list<Color>
     */
    protected static function themePalette(Theme $theme): array
    {
        return [
            $theme->primary,
            $theme->accent,
            $theme->info,
            $theme->success,
            $theme->warning,
            $theme->secondary,
            $theme->error,
        ];
    }

    /**
     * Legend items recolored from the active theme: item `i` takes palette
     * slot `i % paletteSize`. Subclasses whose series render in a different
     * order than their legend items appear (LineChart plots an unlisted
     * primary series first) override this to keep indicators and strokes
     * the same color.
     *
     * @return list<array{label: string, color: string}>
     */
    protected function themeLegendItems(): array
    {
        $theme = $this->theme;
        if ($theme === null) {
            return $this->legendItems;
        }

        $palette = self::themePalette($theme);
        $size    = count($palette);

        $items = $this->legendItems;
        foreach ($items as $i => $item) {
            $items[$i]['color'] = $palette[$i % $size]->toHex();
        }

        return $items;
    }

    public function __toString(): string
    {
        return $this->view();
    }

    // ─── Capability Introspection ─────────────────────────────────────────

    /**
     * Whether value labels (a number printed beside each datum) render.
     * {@see withDataLabels()} refuses true on classes that answer false —
     * no knob is stored that the frame will ignore.
     */
    public function supportsDataLabels(): bool
    {
        return false;
    }

    /**
     * Whether {@see withAnimationProgress()} changes rendered bytes.
     * Duration stays a caller-side pacing hint on every renderer (no
     * renderer here owns a clock).
     */
    public function supportsAnimation(): bool
    {
        return true;
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

    /**
     * Customize the legend indicator glyph. Passing null clears it back to
     * the default bullet.
     */
    public function withLegendStyle(?string $indicatorChar = null): self
    {
        return $this->copy(
            legendIndicatorChar: $indicatorChar === null ? null : Rune::cell($indicatorChar, 'legend indicator'),
            legendIndicatorCharSet: true,
        );
    }

    /**
     * Replace the legend entries.
     *
     * @param list<array{label: string, color: string}> $items
     */
    public function withLegendItems(array $items): self
    {
        return $this->copy(legendItems: UntrustedText::guardItems($items));
    }

    /**
     * Set the X-axis label (rendered at bottom). Null clears it.
     */
    public function withXLabel(?string $label = null): self
    {
        return $this->copy(xLabel: $label === null ? null : UntrustedText::guard($label), xLabelSet: true);
    }

    /**
     * Set the Y-axis label (rendered at left). Null clears it.
     */
    public function withYLabel(?string $label = null): self
    {
        return $this->copy(yLabel: $label === null ? null : UntrustedText::guard($label), yLabelSet: true);
    }

    /**
     * Enable or disable data point labels.
     *
     * Value labels are drawn by {@see BarChart} through its own
     * `withDataLabels()` (a row of bar values above the bars, or a suffix
     * per row in horizontal mode). On this base only the flag is stored —
     * LineChart's dot-per-column geometry has no room to print a number
     * next to each sample, so nothing renders. Bar labels (the category
     * names under each bar) are the separate `showLabels` axis.
     *
     * Query {@see supportsDataLabels()} to learn whether this renderer
     * paints them; LineChart (the only class extending this base) answers
     * false and its own override throws instead of storing a dead knob.
     */
    public function withDataLabels(bool $show = true): self
    {
        return $this->copy(showDataLabels: $show);
    }

    /**
     * Set a custom formatter for data point labels. Storage-only on this
     * base — see {@see withDataLabels()} for which class actually renders.
     * (BarChart's own `withDataLabelFormatter()` is the consuming API:
     * `fn(Bar $bar, int $index): string`.)
     */
    public function withDataLabelFormatter(?\Closure $formatter = null): self
    {
        return $this->copy(dataLabelFormatter: $formatter, dataLabelFormatterSet: true);
    }

    /**
     * Set the chart title.
     *
     * @param Position $position Where to render the title. Only Top and
     *                           Bottom compose (a horizontal composition
     *                           has no title gutter), so Left/Right throw
     *                           instead of silently dropping.
     */
    public function withTitle(?string $title = null, Position $position = Position::Top): self
    {
        if ($position === Position::Left || $position === Position::Right) {
            throw new \InvalidArgumentException(Lang::t('chart.title_side', ['side' => strtolower($position->name)]));
        }

        return $this->copy(
            title: $title === null ? null : UntrustedText::guard($title),
            titlePosition: $position,
            titleSet: true,
        );
    }

    /**
     * Paint the render into the provided cell grid instead of an internal
     * canvas (sugar-charts-007: this is a bridge, not a mode flag). The
     * chart is drawn at its declared size; writes beyond the grid's own
     * bounds are dropped by the grid. Null (the default) renders into a
     * fresh internal Canvas, as before. Braille dot-matrix mode is selected
     * by {@see withBraille()}, independently of the grid choice — the two
     * compose: a braille raster can be painted into any grid.
     *
     * Honored by LineChart, the only class extending this base; the
     * ChartExtras renderers (BarChart, Scatter, OHLCChart) carry no
     * canvas surface at all.
     */
    public function withCanvas(?CellGrid $canvas = null): self
    {
        return $this->copy(canvas: $canvas, canvasSet: true);
    }

    /**
     * Select braille dot-matrix rendering (2x horizontal, 4x vertical
     * resolution) instead of standard character cells. Honored by
     * LineChart, like {@see withCanvas()}.
     */
    public function withBraille(bool $on = true): self
    {
        return $this->copy(braille: $on);
    }

    /**
     * Apply a named color theme to the chart. The theme's palette drives
     * every series color and legend indicator: series render in
     * primary/accent/info/success/warning/secondary/error order (cycling),
     * and the legend recolors to match. Without a theme, series render
     * with their default colors (cell mode: unstyled; braille mode: the
     * legend's CSS colors) — the unthemed output is byte-identical.
     * Honored by {@see LineChart}, the only class extending this base;
     * {@see BarChart} and the rest compose their own legends via the
     * ChartExtras trait and have no theme surface. Use
     * `Theme::dracula()`, `Theme::oneDark()`, etc. Mirrors sugar-style
     * `Theme` palette roles.
     */
    public function withTheme(?Theme $theme = null): self
    {
        return $this->copy(theme: $theme, themeSet: true);
    }

    // ─── Short-form Aliases ──────────────────────────────────────────────

    /**
     * Short-form alias for {@see withLegendItems()}.
     *
     * @param list<array{label: string, color: string}> $items
     */
    public function legendItems(array $items): self { return $this->withLegendItems($items); }
    public function legend(bool $on = true): self   { return $this->withLegend($on); }
    public function legendPos(Position $pos): self  { return $this->withLegendPosition($pos); }
    public function legendStyle(?string $char): self { return $this->withLegendStyle($char); }
    public function xLabel(string $label): self     { return $this->withXLabel($label); }
    public function yLabel(string $label): self     { return $this->withYLabel($label); }
    public function dataLabels(bool $on = true): self { return $this->withDataLabels($on); }
    /** Short-form alias for {@see withDataLabelFormatter()}; null clears the knob (sugar-charts-085). */
    public function dataLabelFormat(?\Closure $fn = null): self { return $this->withDataLabelFormatter($fn); }
    /** Short-form alias for {@see withTitle()}. */
    public function title(string $t, Position $p = Position::Top): self { return $this->withTitle($t, $p); }
    /** Short-form alias for {@see withCanvas()}. */
    public function canvas(?CellGrid $canvas = null): self { return $this->withCanvas($canvas); }
    /** Short-form alias for {@see withBraille()}. */
    public function braille(bool $on = true): self { return $this->withBraille($on); }
    /** Short-form alias for {@see withTheme()}; null clears the knob (sugar-charts-085 family parity with canvas()/seriesColorFn()). */
    public function theme(?Theme $theme = null): self { return $this->withTheme($theme); }

    // ─── Animation Support ──────────────────────────────────────────────

    /**
     * Set animation progress (0.0 = nothing rendered, 1.0 = fully rendered).
     * Values outside [0,1] are clamped to the nearest valid bound.
     */
    public function withAnimationProgress(float $progress): self
    {
        return $this->copy(animationProgress: max(0.0, min(1.0, $progress)));
    }

    /**
     * Set animation duration in milliseconds (0 = instant, no animation).
     *
     * Storage only: rendering is progress-driven ({@see
     * withAnimationProgress()} is what actually clamps the frame). The
     * duration is the wall-clock budget a CALLER's driver loop should pace
     * progress across — no renderer here owns a clock (chart libraries
     * must not spawn timers; the embedding program does). Read it back with
     * `getAnimationDuration()` / drive the frames with your own loop.
     *
     * @throws \InvalidArgumentException on negative milliseconds (sugar-charts-057 —
     *                                  the last numeric door in the family without a leg)
     */
    public function withAnimationDuration(int $durationMs): self
    {
        if ($durationMs < 0) {
            throw new \InvalidArgumentException(Lang::t('chart.duration_nonneg'));
        }

        return $this->copy(animationDuration: $durationMs);
    }

    /**
     * Returns current animation progress (0.0 to 1.0).
     *
     * Kept `get`-prefixed by exception: the bare short form
     * `animationProgress()` is taken by the {@see withAnimationProgress()}
     * alias (see README "Accessor conventions").
     */
    public function getAnimationProgress(): float
    {
        return $this->animationProgress;
    }

    /**
     * Returns animation duration in milliseconds. Bare name occupied by
     * the setter alias — see {@see getAnimationProgress()} for the
     * exception note.
     */
    public function getAnimationDuration(): int
    {
        return $this->animationDuration;
    }

    /** Short-form alias for {@see withAnimationProgress()}. */
    public function animationProgress(float $progress): self { return $this->withAnimationProgress($progress); }
    /** Short-form alias for {@see withAnimationDuration()}. */
    public function animationDuration(int $durationMs): self { return $this->withAnimationDuration($durationMs); }

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
        ?float $animationProgress = null,
        ?int $animationDuration = null,
        ?CellGrid $canvas = null,
        ?bool $braille = null,
        ?Theme $theme = null,
        bool $legendIndicatorCharSet = false,
        bool $titleSet = false,
        bool $xLabelSet = false,
        bool $yLabelSet = false,
        bool $dataLabelFormatterSet = false,
        bool $canvasSet = false,
        bool $themeSet = false,
    ): self {
        return new static(
            width:              $width              ?? $this->width,
            height:             $height             ?? $this->height,
            showLegend:         $showLegend         ?? $this->showLegend,
            legendPosition:     $legendPosition     ?? $this->legendPosition,
            legendIndicatorChar:($legendIndicatorCharSet || $legendIndicatorChar !== null) ? $legendIndicatorChar : $this->legendIndicatorChar,
            title:              ($titleSet || $title !== null)           ?              $title : $this->title,
            titlePosition:      $titlePosition      ?? $this->titlePosition,
            xLabel:             ($xLabelSet || $xLabel !== null)          ?             $xLabel : $this->xLabel,
            yLabel:             ($yLabelSet || $yLabel !== null)          ?             $yLabel : $this->yLabel,
            showDataLabels:     $showDataLabels     ?? $this->showDataLabels,
            dataLabelFormatter: ($dataLabelFormatterSet || $dataLabelFormatter !== null) ? $dataLabelFormatter : $this->dataLabelFormatter,
            legendItems:        $legendItems        ?? $this->legendItems,
            animationProgress:  $animationProgress  ?? $this->animationProgress,
            animationDuration:  $animationDuration  ?? $this->animationDuration,
            canvas:             ($canvasSet || $canvas !== null)  ?      $canvas : $this->canvas,
            braille:            $braille ?? $this->braille,
            theme:              ($themeSet || $theme !== null)           ?              $theme : $this->theme,
        );
    }
}
