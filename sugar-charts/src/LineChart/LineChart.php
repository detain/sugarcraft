<?php

declare(strict_types=1);

namespace SugarCraft\Charts\LineChart;

use SugarCraft\Charts\Chart\Chart;
use SugarCraft\Charts\Chart\Position;
use SugarCraft\Charts\Lang;
use SugarCraft\Charts\MarkLine;
use SugarCraft\Charts\Support\Finite;
use SugarCraft\Charts\Support\UntrustedText;
use SugarCraft\Charts\Support\Range;
use SugarCraft\Charts\Support\Rune;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Charts\Canvas\BrailleGrid;
use SugarCraft\Charts\Canvas\Canvas;
use SugarCraft\Charts\Canvas\Graph;
use SugarCraft\Style\Style;
use SugarCraft\Style\Theme;

/**
 * Multi-series line plot drawn onto a {@see Canvas}. Each data point
 * becomes one column; the series is scaled into the canvas height and
 * a `*` (configurable) is plotted at the rounded row. Adjacent points
 * are connected with `|`, `/`, or `\` strokes when their rows differ,
 * giving an at-a-glance trend without requiring sub-cell rasterisation.
 * The unnamed `$data` series renders first; {@see withDataset()} adds
 * named series on the same Y scale with automatic legend entries and a
 * cycling per-series color. {@see withTheme()} recolors every series and
 * legend indicator from a sugar-style `Theme` palette, and
 * `withUnicodeConnectors()` swaps the connector strokes for the `│ ─ ╱ ╲`
 * runes used by {@see Waveline} (ASCII remains the default).
 *
 * Color resolution order: a `withSeriesColorFn()` resolver wins per
 * point; otherwise the theme palette slot (per series); the built-in
 * dataset palette colours only the legend in unthemed cell mode, where
 * series separate by glyph instead.
 *
 * ```php
 * $chart = LineChart::new([1, 4, 2, 8, 6, 3, 7], 30, 6)
 *     ->withDataset('trend', [2, 3, 5, 7, 6, 4, 5]);
 * echo $chart->view();
 * ```
 */
final class LineChart extends Chart
{
    /**
     * Point glyph as stored after the {@see \SugarCraft\Charts\Support\Rune}
     * ingress door (single display cell, control bytes swept). Declared
     * here and assigned in the constructor instead of promoted so the
     * guarded value — never the raw caller string — is what lands on the
     * readonly property.
     */
    public readonly string $point;

    /**
     * Auto-legend color cycle for {@see withDataset()}. Ordered for
     * color-blind safety: the old first pair (red, green) is the classic
     * deuteranopia/protanopia collision, so blue/orange open the cycle
     * and red/green are kept apart.
     */
    private const DATASET_COLORS = ['blue', 'orange', 'green', 'red', 'purple', 'cyan'];

    /**
     * Point-glyph cycle for extra series in cell mode: unthemed renders
     * carry no color bytes, so a second and later series separate by
     * RUNE (`* + o x # .`) instead of every dataset defaulting to `*`.
     */
    private const DATASET_RUNES = ['*', '+', 'o', 'x', '#', '.'];

    /**
     * Series name the unnamed primary `$data` series is plotted under —
     * the `$dataset` argument a {@see withSeriesColorFn()} resolver
     * receives for it.
     */
    public const PRIMARY_SERIES = '_primary';

    /**
     * @param list<int|float>            $data
     * @param array<string,list<int|float>> $datasets  named multi-series
     * @param array<string,string>       $datasetPoints  per-series rune override
     * @param list<string>               $xLabels
     * @param list<string>               $yLabels
     * @param ?array<string,string>      $lineStyle  axis runeset override (a Graph::LINE_* preset); null = LINE_THIN
     * @param list<MarkLine>             $markLines  horizontal reference-line annotations; [] (default) draws nothing
     * @param bool                       $unicodeConnectors  draw connectors with `│ ─ ╱ ╲` instead of ASCII `| - / \`
     * @param ?\Closure                  $seriesColorFn  per-point color resolver, see {@see withSeriesColorFn()}; null = per-series colors only
     */
    public function __construct(
        public readonly array $data,
        int $width,
        int $height,
        public readonly ?float $min,
        public readonly ?float $max,
        string $point,
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
        bool $showLegend = false,
        Position $legendPosition = Position::Right,
        ?string $legendIndicatorChar = null,
        ?string $title = null,
        Position $titlePosition = Position::Top,
        ?string $xLabel = null,
        ?string $yLabel = null,
        bool $showDataLabels = false,
        ?\Closure $dataLabelFormatter = null,
        array $legendItems = [],
        float $animationProgress = 1.0,
        int $animationDuration = 0,
        public readonly bool $fill = false,
        public readonly ?array $lineStyle = null,
        public readonly array $markLines = [],
        public readonly bool $unicodeConnectors = false,
        ?object $brailleCanvas = null,
        ?Theme $theme = null,
        public readonly ?\Closure $seriesColorFn = null,
    ) {
        parent::__construct(
            width: $width,
            height: $height,
            showLegend: $showLegend,
            legendPosition: $legendPosition,
            legendIndicatorChar: $legendIndicatorChar,
            title: $title,
            titlePosition: $titlePosition,
            xLabel: $xLabel,
            yLabel: $yLabel,
            showDataLabels: $showDataLabels,
            dataLabelFormatter: $dataLabelFormatter,
            legendItems: $legendItems,
            animationProgress: $animationProgress,
            animationDuration: $animationDuration,
            brailleCanvas: $brailleCanvas,
            theme: $theme,
        );

        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException(Lang::t('linechart.dim_nonneg'));
        }
        // every ingestion door (new / with* / lineChartCopy)
        // rebuilds through this constructor, so one check pair covers both
        // axes for NaN/INF endpoints and inverted ranges. `null` ends are
        // legal and mean "auto" — including autoAdjustRange()'s all-null.
        Range::pin($min, $max, 'Y');
        Range::pin($xMin, $xMax, 'X');

        // `view()` plots the unnamed primary series under
        // PRIMARY_SERIES and merges `$datasets` on top of it with the
        // `+` operator — a caller dataset carrying the same key would be
        // silently discarded. Fail loudly at every ingress instead (all
        // doors rebuild through this constructor).
        if (array_key_exists(self::PRIMARY_SERIES, $datasets)) {
            throw new \InvalidArgumentException(
                'dataset name ' . self::PRIMARY_SERIES . ' is reserved for the primary data series',
            );
        }
        if (array_key_exists(self::PRIMARY_SERIES, $datasetPoints)) {
            throw new \InvalidArgumentException(
                'dataset-point name ' . self::PRIMARY_SERIES . ' is reserved for the primary data series',
            );
        }

        // Ingress doors (sugar-charts-001/-013): every rune stored here is
        // painted one cell at a time, so each passes the single-cell guard
        // in the constructor — the one sweep point covering new(),
        // withPoint()/point() and every lineChartCopy() rebuild.
        $this->point = Rune::cell($point, 'line chart point rune');
        foreach ($datasetPoints as $seriesName => $seriesRune) {
            $datasetPoints[$seriesName] = Rune::cell((string) $seriesRune, 'line chart dataset point rune');
        }
    }

    /** @param list<int|float> $data */
    public static function new(array $data = [], int $width = 40, int $height = 8): self
    {
        Finite::assertAll($data);
        return new self(array_values($data), $width, $height, null, null, '*');
    }

    /** @param list<int|float> $data */
    public function withData(array $data): self
    {
        Finite::assertAll($data);
        return $this->lineChartCopy(data: array_values($data));
    }

    public function withSize(int $w, int $h): self
    {
        if ($w < 0 || $h < 0) {
            throw new \InvalidArgumentException(Lang::t('linechart.dim_nonneg'));
        }
        return $this->lineChartCopy(width: $w, height: $h);
    }

    /**
     * Pin the vertical range. Data outside the pin is CLAMPED into it —
     * the silent exclusion from the auto-range scan is deliberate, while
     * a `min > max` pair throws at construction (Range::pin). Passing
     * `null` re-enables auto-scaling.
     */
    public function withMin(?float $m): self        { return $this->lineChartCopy(min: $m, minSet: true); }

    /** @see withMin() — same clamp/throw asymmetry. */
    public function withMax(?float $m): self        { return $this->lineChartCopy(max: $m, maxSet: true); }

    public function withPoint(string $rune): self   { return $this->lineChartCopy(point: $rune); }

    /**
     * Y-axis data range as a `[min, max]` pair. Equivalent to chaining
     * `withMin($min)->withMax($max)`. Mirrors ntcharts' `SetYRange`.
     *
     * @throws \InvalidArgumentException on NaN/INF endpoints or min > max
     *         (enforced at the constructor door); `null` ends stay
     *         legal and mean "auto".
     */
    public function withYRange(?float $min, ?float $max): self
    {
        return $this->lineChartCopy(min: $min, minSet: true, max: $max, maxSet: true);
    }

    /**
     * X-axis range used by the {@see withXLabelFormatter()} when
     * generating X tick labels. The data itself remains column-indexed;
     * the range is the conceptual `[xMin, xMax]` mapped across the
     * plot width. Mirrors ntcharts' `SetXRange`.
     *
     * @throws \InvalidArgumentException on NaN/INF endpoints or min > max
     *         (enforced at the constructor door); `null` ends stay
     *         legal and mean "auto".
     */
    public function withXRange(?float $min, ?float $max): self
    {
        return $this->lineChartCopy(xMin: $min, xMinSet: true, xMax: $max, xMaxSet: true);
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
        return $this->lineChartCopy(
            min: null, minSet: true, max: null, maxSet: true,
            xMin: null, xMinSet: true, xMax: null, xMaxSet: true,
        );
    }

    /**
     * Closure invoked per X tick to format axis labels: `fn(float $v): string`.
     * Used only when {@see withXLabels()} hasn't been called explicitly.
     * `$ticks` controls the count of label slots — 0 (the default) means the
     * built-in count: five. Used only while axes are shown.
     */
    public function withXLabelFormatter(\Closure $fn, int $ticks = 0): self
    {
        return $this->lineChartCopy(xLabelFormatter: $fn, xLabelFormatterSet: true, xLabelTicks: max(0, $ticks));
    }

    /**
     * Closure invoked per Y tick to format axis labels: `fn(float $v): string`.
     * Used only when {@see withYLabels()} hasn't been called explicitly.
     * `$ticks` controls the count of label slots — 0 (the default) means the
     * built-in count: four. Used only while axes are shown.
     */
    public function withYLabelFormatter(\Closure $fn, int $ticks = 0): self
    {
        return $this->lineChartCopy(yLabelFormatter: $fn, yLabelFormatterSet: true, yLabelTicks: max(0, $ticks));
    }

    /**
     * Add or replace a named series, auto-managing its legend entry.
     *
     * REPLACE semantics: re-calling with an existing name swaps that
     * series' values and keeps its original legend slot and color, so a
     * re-call never duplicates the label or shifts the color cycle for
     * later series. A brand-new name appends its legend entry with the
     * next color in the cycle (append-only flows render identically).
     *
     * @param list<int|float> $values
     */
    public function withDataset(string $name, array $values): self
    {
        Finite::assertAll($values);
        $name = UntrustedText::guard($name);
        $sets = $this->datasets;
        $sets[$name] = array_values($values);

        // Auto-add legend item with cycling colors; on replace, update the
        // existing slot in place so label order and color assignment stay
        // stable for the series' identity.
        $legendItems = $this->legendItems;
        $slot = array_search($name, array_column($legendItems, 'label'), true);
        if ($slot === false) {
            $colorIndex = count($this->datasets) % count(self::DATASET_COLORS);
            $legendItems[] = ['label' => $name, 'color' => self::DATASET_COLORS[$colorIndex]];
        }

        return $this->lineChartCopy(datasets: $sets, legendItems: $legendItems);
    }

    /** Per-series rune override for {@see view()}. */
    public function withDatasetPoint(string $name, string $rune): self
    {
        $points = $this->datasetPoints;
        $points[UntrustedText::guard($name)] = UntrustedText::guard($rune);
        return $this->lineChartCopy(datasetPoints: $points);
    }

    /** Render an X / Y axis frame around the plot area. Default off. */
    public function withAxes(bool $on = true): self
    {
        return $this->lineChartCopy(showAxes: $on);
    }

    /**
     * Choose the line-art preset used for the axis frame drawn by
     * {@see withAxes()} — e.g. `Graph::LINE_ROUNDED` for arc corners
     * (`╰`) instead of the default sharp thin ones (`└`). Passing null
     * restores the LINE_THIN default. WHY: ntcharts has no rune-style
     * setter; this closes the org-wide rounded-box gap sugar-dashboard
     * already defaults to.
     *
     * @param ?array<string,string> $runes  a Graph::LINE_* preset
     */
    public function withLineStyle(?array $runes): self
    {
        return $this->lineChartCopy(lineStyle: $runes, lineStyleSet: true);
    }

    /**
     * Horizontal reference-line annotations drawn across the plot at fixed
     * Y values (see {@see MarkLine}). Each mark whose value falls inside the
     * resolved `[min, max]` range paints its style glyph — solid `─`, dashed
     * `╌`, dotted `┄` — over the full plot-width run of the mapped row,
     * replacing any point/connector cells it crosses (marks are annotations
     * ON TOP), with an optional label right-aligned over the run when it
     * fits. WHY: this is the "consumed by LineChart" render pass that
     * {@see MarkLine}'s class docblock documents; Mirrors ntcharts'
     * `MarkLine` overlay concept.
     *
     * @param list<MarkLine> $marks  default [] renders nothing (byte-identical)
     * @throws \InvalidArgumentException if the list contains a non-MarkLine element
     */
    public function withMarkLines(array $marks): self
    {
        // Boundary parse: trust the list internals after this point.
        foreach ($marks as $mark) {
            if (!$mark instanceof MarkLine) {
                throw new \InvalidArgumentException(sprintf(
                    'MarkLine list must contain only MarkLine instances, %s given',
                    get_debug_type($mark),
                ));
            }
        }
        return $this->lineChartCopy(markLines: array_values($marks));
    }

    /**
     * Draw inter-point connectors with the Unicode box/slope runes `│ ─ ╱ ╲`
     * — the exact slope mapping already used by {@see Waveline::connectorRune()}
     * — instead of the ASCII `| - \ /` strokes. WHY: ASCII connectors read as
     * noise next to the Unicode axis frame; auto-detecting terminal charset
     * capability is not available (T::detect is language-only), so the richer
     * palette ships opt-in. ASCII stays the default (no silent visual change
     * for existing consumers).
     */
    public function withUnicodeConnectors(bool $on = true): self
    {
        return $this->lineChartCopy(unicodeConnectors: $on);
    }

    /**
     * Per-point color resolver: `fn(string $dataset, int $x, float $value): ?Color`.
     * A non-null Color overrides the series color for that sample's dot,
     * its fill run, and the connector segment leaving it; null falls
     * through to the series default — the theme palette slot when a theme
     * is active, otherwise the legend color (braille mode) or unstyled
     * (cell mode). `$dataset` is the series name
     * ({@see PRIMARY_SERIES} for the unnamed `$data` series) and `$x` the
     * sample's index in that series. WHY: btop colors every graph point
     * by its value through the theme gradient, so one color per series is
     * too coarse for value-heat graphs. Mirrors btop's per-value
     * `Theme::g(...).at(value)` lookup (btop_draw.cpp Graph::_create).
     *
     * Passing null restores the default path, which renders
     * byte-identically to a chart that never set a resolver.
     *
     * @throws \UnexpectedValueException at render time when the resolver
     *         returns anything other than a Color or null
     */
    public function withSeriesColorFn(?\Closure $fn): self
    {
        return $this->lineChartCopy(seriesColorFn: $fn, seriesColorFnSet: true);
    }

    /**
     * X-axis labels (rendered under the axis when `withAxes(true)` is set).
     * @param list<string> $labels
     */
    public function withXLabels(array $labels): self
    {
        return $this->lineChartCopy(xLabels: array_map(static fn(string $l): string => UntrustedText::guard($l), array_values($labels)));
    }

    /**
     * Y-axis labels (rendered to the left of the axis, top-to-bottom
     * for largest-to-smallest by convention).
     * @param list<string> $labels
     */
    public function withYLabels(array $labels): self
    {
        return $this->lineChartCopy(yLabels: array_map(static fn(string $l): string => UntrustedText::guard($l), array_values($labels)));
    }

    // Short-form aliases.
    /** @param list<float|int> $data */
    public function data(array $data): self          { return $this->withData($data); }
    public function size(int $w, int $h): self       { return $this->withSize($w, $h); }
    public function min(?float $m): self             { return $this->withMin($m); }
    public function max(?float $m): self             { return $this->withMax($m); }
    public function point(string $rune): self        { return $this->withPoint($rune); }
    public function yRange(?float $min, ?float $max): self           { return $this->withYRange($min, $max); }
    public function xRange(?float $min, ?float $max): self           { return $this->withXRange($min, $max); }
    public function xyRange(?float $xMin, ?float $xMax, ?float $yMin, ?float $yMax): self {
        return $this->withXYRange($xMin, $xMax, $yMin, $yMax);
    }
    public function axes(bool $on = true): self      { return $this->withAxes($on); }
    /** Short-form alias for {@see withLineStyle()}. */
    public function lineStyle(?array $runes): self   { return $this->withLineStyle($runes); }
    /** @param list<string> $labels */
    public function xLabels(array $labels): self     { return $this->withXLabels($labels); }
    /** @param list<string> $labels */
    public function yLabels(array $labels): self     { return $this->withYLabels($labels); }
    /** Short-form alias for {@see withXLabelFormatter()}. */
    public function xLabelFormatter(\Closure $fn, int $ticks = 0): self { return $this->withXLabelFormatter($fn, $ticks); }
    /** Short-form alias for {@see withYLabelFormatter()}. */
    public function yLabelFormatter(\Closure $fn, int $ticks = 0): self { return $this->withYLabelFormatter($fn, $ticks); }
    /** Short-form alias for {@see withDataset()}. */
    public function dataset(string $name, array $values): self { return $this->withDataset($name, $values); }
    /** Short-form alias for {@see withDatasetPoint()}. */
    public function datasetPoint(string $name, string $rune): self { return $this->withDatasetPoint($name, $rune); }
    /** Short-form alias for {@see withMarkLines()}. */
    public function markLines(array $marks): self { return $this->withMarkLines($marks); }
    /** Short-form alias for {@see withUnicodeConnectors()}. */
    public function unicodeConnectors(bool $on = true): self { return $this->withUnicodeConnectors($on); }
    /** Short-form alias for {@see withSeriesColorFn()}. */
    public function seriesColorFn(?\Closure $fn): self { return $this->withSeriesColorFn($fn); }

    // ─── Chart Property Overrides ───────────────────────────────────────
    // Override Chart's methods to use lineChartCopy() so LineChart properties are preserved

    /** Enable or disable the legend. */
    public function withLegend(bool $show = true): self
    {
        return $this->lineChartCopy(showLegend: $show);
    }

    /** Set the legend position. */
    public function withLegendPosition(Position $position): self
    {
        return $this->lineChartCopy(legendPosition: $position);
    }

    /** Customize legend styling. */
    public function withLegendStyle(?string $indicatorChar = null): self
    {
        return $this->lineChartCopy(
            legendIndicatorChar: $indicatorChar === null ? null : Rune::cell($indicatorChar, 'legend indicator'),
            legendIndicatorCharSet: true,
        );
    }

    /** Set the X-axis label (rendered at bottom). */
    public function withXLabel(?string $label = null): self
    {
        return $this->lineChartCopy(xLabel: $label === null ? null : UntrustedText::guard($label), xLabelSet: true);
    }

    /** Set the Y-axis label (rendered at left). */
    public function withYLabel(?string $label = null): self
    {
        return $this->lineChartCopy(yLabel: $label === null ? null : UntrustedText::guard($label), yLabelSet: true);
    }

    /**
     * Value labels cannot render on LineChart (one column per sample
     * leaves no room beside it), so enabling them throws — the same
     * guidance {@see Chart::withDataLabels()} gives via
     * supportsDataLabels(). Use BarChart::withDataLabels() for rendered
     * value labels, or read the numbers from your own `$data` array.
     */
    public function withDataLabels(bool $show = true): self
    {
        if ($show && !$this->supportsDataLabels()) {
            throw new \InvalidArgumentException(Lang::t('chart.datalabels_unsupported'));
        }

        return $this->lineChartCopy(showDataLabels: $show);
    }

    /**
     * Set a custom formatter for data point labels. Storage-only on
     * LineChart — see {@see withDataLabels()}.
     */
    public function withDataLabelFormatter(?\Closure $formatter = null): self
    {
        return $this->lineChartCopy(dataLabelFormatter: $formatter, dataLabelFormatterSet: true);
    }

    /**
     * Set the chart title.
     *
     * @param Position $position Where to render the title (Top or Bottom).
     *                           Left/Right throw (sugar-charts-025): a
     *                           horizontal composition has no title
     *                           gutter, so nothing used to render for
     *                           those values.
     */
    public function withTitle(?string $title = null, Position $position = Position::Top): self
    {
        return $this->lineChartCopy(title: $title === null ? null : UntrustedText::guard($title), titleSet: true, titlePosition: $position);
    }

    /**
     * Render the series as braille dot-matrix (2x horizontal, 4x vertical
     * resolution) instead of one cell per sample. The canvas object is not
     * mutated — LineChart rasterizes into its own grid on every render and
     * the instance merely selects braille mode (its dimensions are ignored).
     */
    public function withCanvas(?object $canvas = null): self
    {
        return $this->lineChartCopy(brailleCanvas: $canvas, brailleCanvasSet: true);
    }

    /**
     * Apply a named color theme. The palette colors every series (ASCII
     * and braille modes) and the legend indicators; the unnamed primary
     * series takes slot 0, then {@see withDataset()} series in insertion
     * order. Unthemed rendering is byte-identical to the historical
     * default (legend CSS colors in braille mode, unstyled in cell mode).
     */
    public function withTheme(?Theme $theme = null): self
    {
        return $this->lineChartCopy(theme: $theme, themeSet: true);
    }

    /**
     * Legend indicators must match the strokes: LineChart plots an
     * implicit {@see PRIMARY_SERIES} first that usually carries no legend
     * entry, so a dataset named "temp" is legend slot 0 but render slot 1.
     * Map each label to its position in the render order before taking a
     * palette slot; unknown labels fall back to their own legend index.
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
        $order   = array_keys([self::PRIMARY_SERIES => true] + $this->datasets);

        $items = $this->legendItems;
        foreach ($items as $i => $item) {
            $slot   = array_search($item['label'], $order, true);
            $index  = $slot === false ? $i : (int) $slot;
            $items[$i]['color'] = $palette[$index % $size]->toHex();
        }

        return $items;
    }

    /**
     * Set legend items. Overrides Chart::withLegendItems() to route
     * through lineChartCopy() so LineChart-specific constructor args are
     * preserved.
     *
     * @param list<array{label: string, color: string}> $items
     */
    public function withLegendItems(array $items): self
    {
        return $this->lineChartCopy(legendItems: UntrustedText::guardItems($items));
    }

    /** Short-form alias for {@see withLegendItems()}. */
    public function legendItems(array $items): self
    {
        return $this->withLegendItems($items);
    }

    // ─── Animation Support ──────────────────────────────────────────────

    /**
     * Set animation progress (0.0 = nothing rendered, 1.0 = fully rendered).
     * Values outside [0,1] are clamped to the nearest valid bound.
     */
    public function withAnimationProgress(float $progress): self
    {
        return $this->lineChartCopy(animationProgress: max(0.0, min(1.0, $progress)));
    }

    /**
     * Set animation duration in milliseconds (0 = instant, no animation).
     * Storage-only pacing hint for the caller's frame loop — rendering
     * reads only `withAnimationProgress()`; no renderer here owns a clock.
     */
    public function withAnimationDuration(int $durationMs): self
    {
        return $this->lineChartCopy(animationDuration: $durationMs);
    }

    /**
     * Append a single value using a sliding window sized to the chart width.
     * When the window is full, the oldest point is dropped before the new
     * one is added — giving live streaming behaviour.
     */
    public function push(float $value): self
    {
        Finite::assert($value);
        $window = $this->width > 0 ? $this->width : 1;
        $data = $this->data;
        $data[] = $value;
        if (count($data) > $window) {
            $data = array_slice($data, -$window);
        }
        return $this->lineChartCopy(data: array_values($data));
    }

    /**
     * Toggle area fill below the line. When enabled the vertical space
     * under the curve is filled with the point rune to create a solid-band
     * look.
     */
    public function withFill(bool $on = true): self
    {
        return $this->lineChartCopy(fill: $on);
    }

    /** Short-form alias for {@see withFill()}. */
    public function fill(bool $on = true): self
    {
        return $this->withFill($on);
    }

    /**
     * Render the raw line chart without legend, title, or labels.
     */
    protected function renderChart(): string
    {
        if ($this->width === 0 || $this->height === 0 || ($this->data === [] && $this->datasets === [])) {
            return (new Canvas($this->width, $this->height))->view();
        }

        // Handle animation progress - return empty canvas if progress <= 0
        $progress = $this->getAnimationProgress();
        if ($progress <= 0.0) {
            return (new Canvas($this->width, $this->height))->view();
        }

        $canvas = new Canvas($this->width, $this->height);

        // Compute a global axis range covering every series so each
        // line stays comparable on the same scale.
        $dataMin = null;
        $dataMax = null;
        // Single pass over every series: accumulating into one merged
        // array re-copies all samples once per dataset (O(series × total)).
        foreach ([[$this->data], array_values($this->datasets)] as $group) {
            foreach ($group as $values) {
                foreach ($values as $v) {
                    $f = (float) $v;
                    if ($dataMin === null || $f < $dataMin) {
                        $dataMin = $f;
                    }
                    if ($dataMax === null || $f > $dataMax) {
                        $dataMax = $f;
                    }
                }
            }
        }
        if ($dataMin === null) {
            return $canvas->view();
        }
        $min = $this->min ?? $dataMin;
        $max = $this->max ?? $dataMax;
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
                // gutter sized by DISPLAY width, not codepoint count.
                $maxYLabel = max($maxYLabel, Width::string($lbl));
            }
            $gutterLeft   = max(2, $maxYLabel + 1);
            $gutterBottom = $xLabels !== [] ? 2 : 1;
        }
        // The axis line itself occupies the gutter's first column when
        // drawn; samples start one column right of it so the x-minimum
        // point never sits under the frame that paints over the series.
        $dataLeft = $this->showAxes ? $gutterLeft + 1 : $gutterLeft;
        $plotW = max(1, $this->width  - $dataLeft);
        $plotH = max(1, $this->height - $gutterBottom);

        // Plot primary + named series on the same axes.
        $allSeries = [self::PRIMARY_SERIES => $this->data] + $this->datasets;
        if ($this->brailleCanvas !== null) {
            // braille mode — series rasterize into a dot grid
            // (2×4 dots per cell) so per-dataset legend colors reach the
            // strokes. MarkLines and the axis frame still compose in cell
            // space above/below the dot region.
            $this->renderBrailleSeries($canvas, $allSeries, (float) $min, (float) $max, $dataLeft, $plotW, $plotH, $progress);
        } else {
            /** @var array<string,Style> $pointStyleMemo */
            $pointStyleMemo = [];
            // Theme palette drives series color when set; without a theme
            // cell mode stays unstyled (byte-identical default path).
            $themePalette = $this->theme === null ? [] : self::themePalette($this->theme);
            $paletteSize  = count($themePalette);
            $seriesIndex  = 0;
            foreach ($allSeries as $name => $values) {
                $seriesStyle = $paletteSize === 0
                    ? null
                    : Style::new()->fg($themePalette[$seriesIndex % $paletteSize]);
                $seriesIndex++;
                if ($values === []) {
                    continue;
                }
                $points  = count($values) > $plotW ? array_slice($values, -$plotW) : $values;
                $count   = count($points);
                // Unthemed cell mode carries no color bytes, so extra
                // series separate by glyph (themed runs already carry a
                // distinct color, so they keep the shared point rune): explicit
                // per-series runes win,
                // otherwise rotate the default table by dataset position
                // (the primary keeps its configured `point` rune).
                $rune    = $name === self::PRIMARY_SERIES
                    ? $this->point
                    : ($this->datasetPoints[$name]
                        ?? ($paletteSize === 0
                            ? self::DATASET_RUNES[($seriesIndex - 1) % count(self::DATASET_RUNES)]
                            : $this->point));

                // Compute max point index based on animation progress
                // Clamp to [0, count] to ensure we don't try to access beyond array bounds
                $maxPointIndex = min($count, max(0, (int) floor($count * $progress)));
                // Tail-slicing drops leading samples; resolver x stays the
                // sample's index in the full series.
                $xOffset = count($values) - $count;

                $coords = [];
                foreach ($points as $i => $v) {
                    // A single sample (count <= 1) sits at the first data
                    // column; two or more spread across the plot width.
                    $col = $dataLeft + ($count <= 1
                        ? 0
                        : (int) round($i * ($plotW - 1) / ($count - 1)));
                    $row = self::rowForValue((float) $v, (float) $min, (float) $max, $plotH);
                    $coords[] = [$col, $row];
                }
                // Only render up to maxPointIndex points (and connectors between them)
                for ($i = 0; $i < $count && $i < $maxPointIndex; $i++) {
                    [$x, $y] = $coords[$i];
                    // No resolver → the series default: null (unstyled)
                    // without a theme, the theme slot with one; the
                    // unthemed default stays byte-identical.
                    $style = $this->seriesColorFn === null
                        ? $seriesStyle
                        : $this->pointStyle((string) $name, $xOffset + $i, (float) $points[$i], $seriesStyle, $pointStyleMemo);
                    $canvas->setCell($x, $y, $rune, $style);
                    // Draw connector to next point if within animation bounds
                    if ($i + 1 < $maxPointIndex) {
                        [$x2, $y2] = $coords[$i + 1];
                        self::drawConnector($canvas, $x, $y, $x2, $y2, $this->unicodeConnectors, $style);
                    }
                    // Area fill: paint from baseline up to the point row.
                    if ($this->fill) {
                        $baseline = $plotH - 1;
                        $top = min($baseline, $y);
                        $bottom = max($baseline, $y);
                        for ($fy = $top; $fy <= $bottom; $fy++) {
                            $canvas->setCell($x, $fy, $rune, $style);
                        }
                    }
                }
            }
        }

        // Reference-line annotations paint ON TOP of the series (their row
        // wins over point/connector cells) but UNDER the axis frame. The
        // default [] skips the pass entirely → byte-identical output.
        if ($this->markLines !== []) {
            $this->drawMarkLines($canvas, (float) $min, (float) $max, $dataLeft, $plotW, $plotH);
        }

        if ($this->showAxes) {
            // Origin = bottom-left of the plot region. The axis column
            // keeps the gutter's last cell; samples were mapped from
            // $dataLeft, one column right of the frame, so the x-minimum
            // point is never overwritten by the axis painted after it.
            $xOrigin = $gutterLeft;
            $yOrigin = $plotH; // axis row sits one row below the topmost data
            Graph::drawXYAxis(
                $canvas, $xOrigin, $yOrigin, $plotW, $plotH,
                null, $this->lineStyle ?? Graph::LINE_THIN,
            );
            Graph::drawXYAxisLabel(
                $canvas, $xOrigin, $yOrigin, $plotW, $plotH,
                $xLabels, $yLabels,
            );
        }
        return $canvas->view();
    }

    /**
     * Map a Y value to its plot row: normalise into the resolved
     * `[min, max]` range (clamped to it), topmost row = 0. Shared by the
     * series loop and the MarkLine overlay so the two mappings can never
     * drift apart. Callers guarantee `$max > $min` (degenerate ranges are
     * widened by +1.0 before plotting).
     */
    private static function rowForValue(float $value, float $min, float $max, int $plotHeight): int
    {
        $norm = ($value - $min) / ($max - $min);
        $norm = max(0.0, min(1.0, $norm));
        return (int) round((1.0 - $norm) * ($plotHeight - 1));
    }

    /**
     * Braille-mode series pass. Rasterizes every series into a
     * dot-matrix grid (2×4 dots per terminal cell) and paints each touched
     * cell exactly once, carrying the series' legend color as the cell
     * style — so dataset colors actually reach the strokes and the render
     * is one batched walk instead of sugar-dashboard `BrailleCanvas::setPoint`'s
     * copy-on-write-per-dot cost. The `$brailleCanvas` instance only
     * selects this mode; its own buffer is never read or mutated.
     *
     * Animation cutoff and area fill mirror the ASCII loop: dots appear in
     * the same sample order, and fill paints the vertical dot run from each
     * sample down to the bottom dot row.
     *
     * @param array<string,list<int|float>> $allSeries
     */
    private function renderBrailleSeries(
        Canvas $canvas,
        array $allSeries,
        float $min,
        float $max,
        int $gutterLeft,
        int $plotW,
        int $plotH,
        float $progress,
    ): void {
        $grid = new BrailleGrid($plotW, $plotH);
        [$dotW, $dotH] = $grid->dotSize();

        /** @var array<string,string> label => color name/hex */
        $colorByLabel = [];
        foreach ($this->legendItems as $item) {
            $colorByLabel[$item['label']] = $item['color'];
        }
        /** @var array<string,Style|null> color string => style memo */
        $styleMemo = [];
        /** @var array<int, array<int, Style|null>> [cellY][cellX] last series to touch wins */
        $cellStyles = [];
        /** @var array<string,Style> $pointStyleMemo */
        $pointStyleMemo = [];

        // Theme palette wins over the legend's CSS colors when set; the
        // legend-colored default path stays byte-identical unthemed.
        $themePalette = $this->theme === null ? [] : self::themePalette($this->theme);
        $paletteSize  = count($themePalette);
        $seriesIndex  = 0;

        foreach ($allSeries as $name => $values) {
            if ($paletteSize === 0) {
                $colorName = $colorByLabel[$name] ?? null;
                if ($colorName === null) {
                    $seriesStyle = null;
                } else {
                    if (!array_key_exists($colorName, $styleMemo)) {
                        $styleMemo[$colorName] = self::styleForColor($colorName);
                    }
                    $seriesStyle = $styleMemo[$colorName];
                }
            } else {
                $seriesStyle = Style::new()->fg($themePalette[$seriesIndex % $paletteSize]);
            }
            $seriesIndex++;
            if ($values === []) {
                continue;
            }
            $points = count($values) > $dotW ? array_slice($values, -$dotW) : $values;
            $count  = count($points);
            $maxPointIndex = min($count, max(0, (int) floor($count * $progress)));

            $xOffset = count($values) - $count;

            $coords = [];
            foreach ($points as $i => $v) {
                $dx = $count <= 1
                    ? 0
                    : (int) round($i * ($dotW - 1) / ($count - 1));
                $dy = self::dotRowForValue((float) $v, $min, $max, $dotH);
                $coords[] = [$dx, $dy];
            }

            for ($i = 0; $i < $maxPointIndex; $i++) {
                [$dx, $dy] = $coords[$i];
                $style = $this->seriesColorFn === null
                    ? $seriesStyle
                    : $this->pointStyle((string) $name, $xOffset + $i, (float) $points[$i], $seriesStyle, $pointStyleMemo);
                $grid->set($dx, $dy);
                $cellStyles[intdiv($dy, 4)][intdiv($dx, 2)] = $style;
                if ($i + 1 < $maxPointIndex) {
                    [$dx2, $dy2] = $coords[$i + 1];
                    self::drawDotLine($grid, $dx, $dy, $dx2, $dy2, $style, $cellStyles);
                }
                if ($this->fill) {
                    for ($fy = $dy; $fy < $dotH; $fy++) {
                        $grid->set($dx, $fy);
                        $cellStyles[intdiv($fy, 4)][intdiv($dx, 2)] = $style;
                    }
                }
            }
        }

        // Single batched paint: only cells touched by some series are set;
        // untouched cells keep the (blank) canvas underneath, matching
        // BrailleGrid::paint's skip-empty behaviour.
        foreach ($cellStyles as $cy => $row) {
            foreach ($row as $cx => $style) {
                $canvas->setCell($gutterLeft + $cx, (int) $cy, $grid->rune($cx, (int) $cy), $style);
            }
        }
    }

    /**
     * Run the per-point resolver for one sample. A returned Color becomes
     * a foreground style memoized by its SGR identity (so a gradient
     * resolver handing back fresh Color objects per sample still shares
     * one Style per distinct color); null keeps `$fallback`, the series'
     * own style.
     *
     * @param array<string,Style> $memo
     */
    private function pointStyle(string $dataset, int $x, float $value, ?Style $fallback, array &$memo): ?Style
    {
        $color = ($this->seriesColorFn)($dataset, $x, $value);
        if ($color === null) {
            return $fallback;
        }
        if (!$color instanceof Color) {
            throw new \UnexpectedValueException(Lang::t('linechart.color_return', [
                'expected' => Color::class,
                'given'    => get_debug_type($color),
            ]));
        }
        $key = $color->r . ',' . $color->g . ',' . $color->b . ',' . ($color->ansiIndex ?? '-');
        return $memo[$key] ??= Style::new()->fg($color);
    }

    /**
     * Same mapping as {@see rowForValue()} at dot-row resolution.
     */
    private static function dotRowForValue(float $value, float $min, float $max, int $dotRows): int
    {
        $norm = ($value - $min) / ($max - $min);
        $norm = max(0.0, min(1.0, $norm));
        return (int) round((1.0 - $norm) * ($dotRows - 1));
    }

    /**
     * Integer Bresenham in dot space; both endpoints are in-bounds by
     * construction, so every intermediate dot is too. Records the owning
     * series' style per touched cell (last writer wins, matching the
     * painter's-order of the ASCII loop).
     *
     * @param array<int, array<int, Style|null>> $cellStyles
     */
    private static function drawDotLine(BrailleGrid $grid, int $x0, int $y0, int $x1, int $y1, ?Style $style, array &$cellStyles): void
    {
        $dx = abs($x1 - $x0);
        $dy = -abs($y1 - $y0);
        $sx = $x0 < $x1 ? 1 : -1;
        $sy = $y0 < $y1 ? 1 : -1;
        $err = $dx + $dy;

        while (true) {
            $grid->set($x0, $y0);
            $cellStyles[intdiv($y0, 4)][intdiv($x0, 2)] = $style;
            if ($x0 === $x1 && $y0 === $y1) {
                break;
            }
            $e2 = 2 * $err;
            if ($e2 >= $dy) {
                $err += $dy;
                $x0   += $sx;
            }
            if ($e2 <= $dx) {
                $err += $dx;
                $y0   += $sy;
            }
        }
    }

    /**
     * Resolve a legend color entry (`#hex` or CSS name) into a foreground
     * style. Unknown colors fail soft to unstyled — the same outcome as a
     * legend entry that does not exist. Names are tried first so
     * hex-lookalike words resolve to their CSS value.
     */
    private static function styleForColor(string $color): ?Style
    {
        try {
            try {
                $c = Color::parse($color);
            } catch (\InvalidArgumentException) {
                $c = Color::hex($color);
            }
        } catch (\Throwable) {
            return null;
        }
        return Style::new()->fg($c);
    }

    /**
     * Paint the {@see MarkLine} annotations. One horizontal glyph run per
     * mark spanning the full plot width at the row its value maps to;
     * marks outside the resolved range are skipped. A non-empty label
     * overwrites the rightmost label-width cells of its own row when the
     * plot is wider than the label plus a 2-cell breathing margin (mb-safe:
     * cells are codepoints, not bytes).
     */
    private function drawMarkLines(Canvas $canvas, float $min, float $max, int $gutterLeft, int $plotW, int $plotH): void
    {
        foreach ($this->markLines as $mark) {
            if ($mark->value < $min || $mark->value > $max) {
                continue;
            }
            $glyph = match ($mark->style) {
                MarkLine::STYLE_SOLID  => '─',  // U+2500
                MarkLine::STYLE_DASHED => '╌',  // U+254C
                MarkLine::STYLE_DOTTED => '┄',  // U+2504
                default => throw new \InvalidArgumentException("Invalid MarkLine style: {$mark->style}"),
            };
            $row = self::rowForValue($mark->value, $min, $max, $plotH);
            for ($col = $gutterLeft; $col < $gutterLeft + $plotW; $col++) {
                $canvas->setCell($col, $row, $glyph);
            }
            $labelWidth = Width::string($mark->label);
            // Labels vanish entirely when the plot is too narrow to hold
            // them (a truncated axis note is worse than none); the label
            // rides the canvas default text style.
            if ($mark->label !== '' && $plotW > $labelWidth + 2) {
                $start = $gutterLeft + $plotW - $labelWidth;
                // Width-aware placement: one walker for every
                // string the canvas receives, wide runes included.
                $canvas->setString($start, $row, $mark->label);
            }
        }
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
            // CH-1 (M15): formatter returns are untrusted — a closure
            // echoing host data must not smuggle control bytes past the
            // UntrustedText boundary the withXLabels() ingress enforces.
            $out[] = UntrustedText::guard((string) $fn($v));
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
            $out[] = UntrustedText::guard((string) $fn($v)); // CH-1 (M15)
        }
        return $out;
    }

    /** Internal copy-with-overrides helper. */
    private function lineChartCopy(
        ?array $data = null,
        ?int $width = null,
        ?int $height = null,
        ?float $min = null,
        bool $minSet = false,
        ?float $max = null,
        bool $maxSet = false,
        ?string $point = null,
        ?array $datasets = null,
        ?array $datasetPoints = null,
        ?bool $showAxes = null,
        ?array $xLabels = null,
        ?array $yLabels = null,
        ?float $xMin = null,
        bool $xMinSet = false,
        ?float $xMax = null,
        bool $xMaxSet = false,
        ?\Closure $xLabelFormatter = null,
        bool $xLabelFormatterSet = false,
        ?\Closure $yLabelFormatter = null,
        bool $yLabelFormatterSet = false,
        ?int $xLabelTicks = null,
        ?int $yLabelTicks = null,
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
        ?bool $showDataLabels = null,
        ?\Closure $dataLabelFormatter = null,
        bool $dataLabelFormatterSet = false,
        ?array $legendItems = null,
        ?float $animationProgress = null,
        ?int $animationDuration = null,
        ?bool $fill = null,
        ?array $lineStyle = null,
        bool $lineStyleSet = false,
        ?array $markLines = null,
        ?bool $unicodeConnectors = null,
        ?object $brailleCanvas = null,
        bool $brailleCanvasSet = false,
        ?Theme $theme = null,
        bool $themeSet = false,
        ?\Closure $seriesColorFn = null,
        bool $seriesColorFnSet = false,
    ): self {
        return new self(
            data:               $data               ?? $this->data,
            width:              $width              ?? $this->width,
            height:             $height             ?? $this->height,
            min:                $minSet ? $min : $this->min,
            max:                $maxSet ? $max : $this->max,
            point:              $point              ?? $this->point,
            datasets:           $datasets           ?? $this->datasets,
            datasetPoints:      $datasetPoints      ?? $this->datasetPoints,
            showAxes:           $showAxes           ?? $this->showAxes,
            xLabels:            $xLabels            ?? $this->xLabels,
            yLabels:            $yLabels            ?? $this->yLabels,
            xMin:               $xMinSet ? $xMin : $this->xMin,
            xMax:               $xMaxSet ? $xMax : $this->xMax,
            xLabelFormatter:    $xLabelFormatterSet ? $xLabelFormatter : $this->xLabelFormatter,
            yLabelFormatter:    $yLabelFormatterSet ? $yLabelFormatter : $this->yLabelFormatter,
            xLabelTicks:        $xLabelTicks        ?? $this->xLabelTicks,
            yLabelTicks:        $yLabelTicks        ?? $this->yLabelTicks,
            showLegend:         $showLegend         ?? $this->showLegend,
            legendPosition:     $legendPosition     ?? $this->legendPosition,
            legendIndicatorChar: ($legendIndicatorCharSet || $legendIndicatorChar !== null) ? $legendIndicatorChar : $this->legendIndicatorChar,
            title:              ($titleSet || $title !== null) ? $title : $this->title,
            titlePosition:      $titlePosition      ?? $this->titlePosition,
            xLabel:             ($xLabelSet || $xLabel !== null) ? $xLabel : $this->xLabel,
            yLabel:             ($yLabelSet || $yLabel !== null) ? $yLabel : $this->yLabel,
            showDataLabels:     $showDataLabels     ?? $this->showDataLabels,
            dataLabelFormatter: ($dataLabelFormatterSet || $dataLabelFormatter !== null) ? $dataLabelFormatter : $this->dataLabelFormatter,
            legendItems:        $legendItems        ?? $this->legendItems,
            animationProgress:  $animationProgress  ?? $this->getAnimationProgress(),
            animationDuration:  $animationDuration  ?? $this->getAnimationDuration(),
            fill:              $fill              ?? $this->fill,
            lineStyle:         $lineStyleSet ? $lineStyle : $this->lineStyle,
            markLines:         $markLines        ?? $this->markLines,
            unicodeConnectors: $unicodeConnectors ?? $this->unicodeConnectors,
            brailleCanvas:     ($brailleCanvasSet || $brailleCanvas !== null) ? $brailleCanvas : $this->brailleCanvas,
            theme:             ($themeSet || $theme !== null) ? $theme : $this->theme,
            seriesColorFn:     $seriesColorFnSet ? $seriesColorFn : $this->seriesColorFn,
        );
    }

    public function __toString(): string
    {
        return $this->view();
    }

    /**
     * Draw a coarse connector between two points using line-art glyphs —
     * ASCII `| - \ /` by default, or the Unicode `│ ─ ╱ ╲` set when the
     * `unicodeConnectors` flag is on (slope mapping identical to
     * {@see Waveline::connectorRune()}). `$style` is the per-point style
     * of the segment's starting sample (null = unstyled).
     */
    private static function drawConnector(Canvas $c, int $x1, int $y1, int $x2, int $y2, bool $unicode, ?Style $style = null): void
    {
        if ($x2 < $x1) {
            return;
        }
        // Vertical column between two adjacent samples (same x): step
        // through the rows.
        if ($x2 === $x1) {
            if ($y2 === $y1) {
                return; // identical points share a cell: no run to paint
            }
            $vertical = $unicode ? '│' : '|';
            $step = $y2 > $y1 ? 1 : -1;
            for ($y = $y1 + $step; $y !== $y2; $y += $step) {
                $c->setCell($x1, $y, $vertical, $style);
            }
            return;
        }
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        if ($dy === 0) {
            $horizontal = $unicode ? '─' : '-';
            for ($x = $x1 + 1; $x < $x2; $x++) {
                $c->setCell($x, $y1, $horizontal, $style);
            }
            return;
        }
        // Sample one row per intermediate column.
        $slope = $dy > 0 ? ($unicode ? '╲' : '\\') : ($unicode ? '╱' : '/');
        for ($x = $x1 + 1; $x < $x2; $x++) {
            $t   = ($x - $x1) / $dx;
            $row = (int) round($y1 + $t * $dy);
            $c->setCell($x, $row, $slope, $style);
        }
    }
}
