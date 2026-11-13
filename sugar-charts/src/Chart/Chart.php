<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Chart;

use SugarCraft\Charts\Legend\Legend;
use SugarCraft\Charts\Lang;
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
 * title, and labels.
 */
abstract class Chart
{
    /** yLabel rides every row; cap it to width / this divisor so a long label cannot multiply out the canvas. */
    private const Y_LABEL_WIDTH_FRACTION_DIVISOR = 4;

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
        ?object $brailleCanvas = null,
        ?Theme $theme = null,
    ) {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException(Lang::t('chart.dim_nonneg'));
        }
        $this->brailleCanvas = $brailleCanvas;
        $this->theme = $theme;
    }

    /** @var object|null Alternative braille-rendered canvas (a sugar-dashboard BrailleCanvas in practice) */
    public readonly ?object $brailleCanvas;

    /** @var Theme|null Named color theme for rendering */
    public readonly ?Theme $theme;

    /**
     * Render the raw chart content without legend, title, or labels.
     */
    abstract protected function renderChart(): string;

    /**
     * Compose and return the full chart output with legend, title, and labels.
     *
     * Empty-data contract (LineChart): with no data the composed output is
     * a blank width×height canvas (right-trimmed empty rows), not '' — see
     * BarChart::view() for the '' family and EmptyRenderContractTest for
     * the pinned shapes.
     */
    public function view(): string
    {
        $chart = $this->renderChart();

        // NOTE: the early return deliberately does NOT test showDataLabels.
        // No composer at this level draws value labels (LineChart's
        // dot-per-column geometry has no room for them — see
        // withDataLabels() for the honest surface; BarChart renders labels
        // through its own API). Gating here would only re-wrap the bare
        // chart through an empty extras pass.
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
            $lines[] = Width::truncate($this->xLabel, $this->width);
        }

        return $lines;
    }

    private function buildLegend(): Legend
    {
        $items = $this->theme === null ? $this->legendItems : $this->themeLegendItems();

        $legend = Legend::new($items)
            ->withPosition($this->legendPosition);

        if ($this->legendIndicatorChar !== null) {
            $legend = $legend->withIndicatorChar($this->legendIndicatorChar);
        }

        return $legend;
    }

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

    /**
     * @param list<string> $chartLines
     * @param list<string> $legendLines
     * @return list<string>
     */
    private function mergeLegend(array $chartLines, Legend $legend): array
    {
        $legendLines = explode("\n", $legend->view());

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
                $result[] = Width::padRight($chartLine, $this->width) . ' ' . $legendLine;
            } else {
                $result[] = $legendLine . ' ' . Width::padRight($chartLine, $this->width);
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
        $centered = Width::padCenter(Width::truncate((string) $this->title, $this->width), $this->width);

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
        $budget = max(1, intdiv($this->width, self::Y_LABEL_WIDTH_FRACTION_DIVISOR));
        $label = Width::truncate((string) $this->yLabel, $budget);

        return array_map(
            fn(string $line) => $label . ' ' . $line,
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
     * @param Position $position Where to render the title (Top or Bottom).
     *                           Left/Right positions are accepted but not
     *                           rendered — a horizontal composition has no
     *                           title gutter, so the title is silently
     *                           dropped for those values.
     */
    public function withTitle(?string $title = null, Position $position = Position::Top): self
    {
        return $this->copy(
            title: $title === null ? null : UntrustedText::guard($title),
            titlePosition: $position,
            titleSet: true,
        );
    }

    /**
     * Select braille dot-matrix rendering (2x horizontal, 4x vertical
     * resolution) instead of standard character cells.
     *
     * Honored by LineChart, the only class extending this base; the
     * ChartExtras renderers (BarChart, Scatter, OHLCChart) carry no
     * canvas surface at all. Null clears the mode
     * back to cell rendering. The canvas object is not mutated — the
     * chart rasterizes into its own internal grid on every render and the
     * instance merely selects the braille mode (its dimensions are not
     * consulted). Typed as object because the null-check is the whole
     * contract; a sugar-dashboard Plot/Braille canvas is what callers
     * pass in practice.
     */
    public function withCanvas(?object $canvas = null): self
    {
        return $this->copy(brailleCanvas: $canvas, brailleCanvasSet: true);
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

    /** @param list<array{label: string, color: string}> $items */
    public function legendItems(array $items): self { return $this->copy(legendItems: UntrustedText::guardItems($items)); }
    public function legend(bool $on = true): self   { return $this->withLegend($on); }
    public function legendPos(Position $pos): self  { return $this->withLegendPosition($pos); }
    public function legendStyle(?string $char): self { return $this->withLegendStyle($char); }
    public function xLabel(string $label): self     { return $this->withXLabel($label); }
    public function yLabel(string $label): self     { return $this->withYLabel($label); }
    public function dataLabels(bool $on = true): self { return $this->withDataLabels($on); }
    public function dataLabelFormat(\Closure $fn): self { return $this->withDataLabelFormatter($fn); }
    /** Short-form alias for {@see withTitle()}. */
    public function title(string $t, Position $p = Position::Top): self { return $this->withTitle($t, $p); }
    /** Short-form alias for {@see withCanvas()}. */
    public function canvas(?object $canvas = null): self { return $this->withCanvas($canvas); }
    /** Short-form alias for {@see withTheme()}. */
    public function theme(Theme $theme): self { return $this->withTheme($theme); }

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
     */
    public function withAnimationDuration(int $durationMs): self
    {
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
        ?object $brailleCanvas = null,
        ?Theme $theme = null,
        bool $legendIndicatorCharSet = false,
        bool $titleSet = false,
        bool $xLabelSet = false,
        bool $yLabelSet = false,
        bool $dataLabelFormatterSet = false,
        bool $brailleCanvasSet = false,
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
            brailleCanvas:      ($brailleCanvasSet || $brailleCanvas !== null)   ?      $brailleCanvas : $this->brailleCanvas,
            theme:              ($themeSet || $theme !== null)           ?              $theme : $this->theme,
        );
    }
}
