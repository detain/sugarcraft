<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Dashboard;

use SugarCraft\Charts\Chart\NiceScale;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Plot\Braille\Bresenham;
use SugarCraft\Dash\Plot\Braille\BrailleMatrix;
use SugarCraft\Query\Admin\StatusSnapshot;

/**
 * Renders a timeline widget as a multi-series braille line graph.
 *
 * Holds one ring window per named series (a scalar calc yields a single
 * series keyed by the widget caption; a tuple calc yields one series per
 * key, in insertion order) and draws every series as its own colored
 * polyline on a shared 2×4-dot-per-cell braille grid, auto-scaled to a
 * "nice ceiling" over all series so they share one Y axis — the shape of
 * MySQL Workbench's DBTimeLineGraph, which plots SELECT/INSERT/UPDATE/…
 * rates as separate colored lines in one frame.
 *
 * Why not sugar-charts LineChart: its renderChart() stamps cells through
 * Canvas::setCell() with a single shared point rune — withDataset() colors
 * never reach the strokes, and its withCanvas(BrailleCanvas) is plumbed
 * through the constructor but never consumed by any render path. Drawing
 * per-series colored polylines at sub-cell resolution needs the dot grid,
 * so this cell rasterizes with sugar-dash's Bresenham + BrailleMatrix
 * primitives (the same ones sugar-dash\BrailleCanvas is built from) into
 * one mutable bit buffer: BrailleCanvas.setPoint() clones the whole cell
 * grid per dot, which is fine for one-shot charts but would cost hundreds
 * of thousands of cell copies per frame at 7 series × 120 samples redrawn
 * every second.
 *
 * Zero IS plottable (an idle counter traces a flat line along the floor,
 * matching Workbench); only negative deltas and non-finite values are
 * rejected as counter-reset/error artifacts.
 *
 * @see Mirrors mysql-workbench/wb_admin_performance_dashboard DBTimeLineGraph
 */
final class MultiSeriesCell
{
    /**
     * Default ring capacity: at the 1 s dashboard cadence
     * (CacheTtl::DASHBOARD) this holds a 2-minute slide, wider than the
     * 80-pixel canvas at 40 cells so history survives a future resize.
     */
    public const DEFAULT_WINDOW = 120;

    /**
     * Legend is only drawn at or above this many render cells when several
     * series share the canvas. On an 80-col terminal each dashboard panel
     * gets ~24 cells; a half-width '■name ' legend would push the graph
     * itself off the panel, so narrow renders rely on per-series colors
     * plus the panel's own caption instead.
     */
    private const LEGEND_MIN_CELLS = 28;

    /** Never collapse the canvas below this many cells when clamping. */
    private const MIN_CELLS = 8;

    /**
     * Distinct series colors — eight slots so the seven SQL statement
     * series (select…drop) never collide, unlike sugar-charts'
     * DATASET_COLORS which cycles after six.
     *
     * @var list<array{r:int,g:int,b:int}>
     */
    private const PALETTE = [
        ['r' => 60, 'g' => 178, 'b' => 191],   // teal
        ['r' => 253, 'g' => 138, 'b' => 39],   // orange
        ['r' => 124, 'g' => 193, 'b' => 80],   // green
        ['r' => 255, 'g' => 215, 'b' => 0],    // gold
        ['r' => 155, 'g' => 89, 'b' => 182],   // purple
        ['r' => 243, 'g' => 139, 'b' => 168],  // red
        ['r' => 137, 'g' => 180, 'b' => 250],  // blue
        ['r' => 224, 'g' => 108, 'b' => 159],  // pink
    ];

    /** @var array<string, list<float>> One ring window per series, insertion-ordered */
    private array $series = [];

    private float $maxSeen = 0.0;

    private float $ceiling = NiceScale::FLOOR;

    public function __construct(
        private readonly Widget $widget,
        private readonly int $windowSize = self::DEFAULT_WINDOW,
        private readonly int $width = 40,
        private readonly int $height = 6,
    ) {}

    /**
     * Ingest one sample per series from the current/previous snapshots.
     *
     * Mutable by contract, like the sibling CounterCell/MeterCell/
     * TimeSeriesCell: the dashboard reuses one cell object per widget and
     * replaces state wholesale through reset(), so a per-sample clone
     * would just churn window copies for no safety.
     *
     * @param array<string, string> $current Current status variables
     * @param array<string, string> $previous Previous status variables
     * @param float $elapsed Seconds elapsed since the previous snapshot
     * @return $this
     */
    public function ingest(array $current, array $previous, float $elapsed): self
    {
        if ($elapsed <= 0) {
            return $this;
        }

        $value = $this->widget->compute($current, $previous, $elapsed);

        // Tuple calcs (MakeTuple/TupleRatePerSecond) return an assoc array
        // = one series per key. Scalar calcs (RatePerSecond/StatusVar/…)
        // return a number-or-numeric-string = a single series under the
        // widget caption.
        $samples = is_array($value)
            ? $value
            : [$this->widget->caption => (float) $value];

        foreach ($samples as $name => $raw) {
            $sample = (float) $raw;
            if (!is_finite($sample) || $sample < 0) {
                continue;
            }
            $key = (string) $name;
            $window = $this->series[$key] ?? [];
            $window[] = $sample;
            while (count($window) > $this->windowSize) {
                array_shift($window);
            }
            $this->series[$key] = $window;
            $this->maxSeen = max($this->maxSeen, $sample);
        }

        $this->ceiling = NiceScale::ceiling($this->maxSeen);

        return $this;
    }

    /**
     * Ingest from a StatusSnapshot pair (mirrors TimeSeriesCell).
     */
    public function ingestFromSnapshot(
        StatusSnapshot $current,
        StatusSnapshot $previous,
    ): self {
        if ($previous->ts <= 0) {
            return $this;
        }
        return $this->ingest(
            $current->variables,
            $previous->variables,
            $current->elapsedSince($previous),
        );
    }

    /**
     * Render the graph (plus a legend line when it fits).
     *
     * @param int|null $maxCells Hard ceiling on rendered cells per row —
     *                           pass the panel's remaining width so the
     *                           graph can never overflow its column.
     */
    public function view(?int $maxCells = null): string
    {
        $cellsW = $this->width;
        if ($maxCells !== null) {
            $cellsW = max(self::MIN_CELLS, min($cellsW, $maxCells));
        }

        $graph = $this->renderGraph($cellsW);

        if (count($this->series) < 2 || $cellsW < self::LEGEND_MIN_CELLS) {
            return $graph;
        }

        return $graph . "\n" . $this->renderLegend($cellsW);
    }

    /**
     * @return list<string> Series names in ingest order
     */
    public function seriesNames(): array
    {
        return array_keys($this->series);
    }

    /** Total samples across all series (the dashboard-reload pin). */
    public function count(): int
    {
        $total = 0;
        foreach ($this->series as $window) {
            $total += count($window);
        }
        return $total;
    }

    public function isEmpty(): bool
    {
        return $this->series === [];
    }

    /** Latest sample of one series, or null when it has none yet. */
    public function latest(string $name): ?float
    {
        $window = $this->series[$name] ?? [];
        return $window === [] ? null : $window[count($window) - 1];
    }

    /** Current auto-scale ceiling (shared by all series). */
    public function ceiling(): float
    {
        return $this->ceiling;
    }

    public function maxSeen(): float
    {
        return $this->maxSeen;
    }

    public function widget(): Widget
    {
        return $this->widget;
    }

    /** Clear every window and scale (call on [r] reset / server restart). */
    public function reset(): self
    {
        $this->series = [];
        $this->maxSeen = 0.0;
        $this->ceiling = NiceScale::FLOOR;
        return $this;
    }

    public function __toString(): string
    {
        return $this->view();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Rasterize all series into one braille bit buffer and stringify it.
     */
    private function renderGraph(int $cellsW): string
    {
        $pxW = $cellsW * 2;
        $pxH = max(1, $this->height) * 4;
        $cols = BrailleMatrix::cellWidth($pxW);
        $rows = BrailleMatrix::cellHeight($pxH);

        /** @var list<list<int>> $bits */
        $bits = array_fill(0, $rows, array_fill(0, $cols, 0));
        /** @var list<list<Color|null>> $tint */
        $tint = array_fill(0, $rows, array_fill(0, $cols, null));

        $span = $this->ceiling > 0 ? $this->ceiling : 1.0;
        $single = count($this->series) === 1;

        $index = 0;
        foreach ($this->series as $window) {
            $color = $this->seriesColor($index, $single);
            $points = $this->pixelPath($window, $pxW, $pxH, $span);

            $prevX = null;
            $prevY = null;
            foreach ($points as [$x, $y]) {
                // A lone sample draws itself; every later one connects to
                // its predecessor (the degenerate line(x,y,x,y) case covers
                // the first point uniformly).
                $fromX = $prevX ?? $x;
                $fromY = $prevY ?? $y;
                foreach (Bresenham::line($fromX, $fromY, $x, $y) as $point) {
                    $this->poke($bits, $tint, $point->x, $point->y, $pxW, $pxH, $color);
                }
                $prevX = $x;
                $prevY = $y;
            }
            $index++;
        }

        $profile = ColorProfile::TrueColor;
        $lines = [];
        for ($row = 0; $row < $rows; $row++) {
            $line = '';
            for ($col = 0; $col < $cols; $col++) {
                $cellBits = $bits[$row][$col];
                if ($cellBits === 0) {
                    $line .= ' ';
                    continue;
                }
                $color = $tint[$row][$col];
                $rune = BrailleMatrix::rune($cellBits);
                $line .= $color === null
                    ? $rune
                    : $color->toFg($profile) . $rune . Ansi::reset();
            }
            // rtrim keeps the frame narrow for the diff renderer; blank
            // lines (fully empty rows) stay empty strings.
            $lines[] = rtrim($line);
        }

        return implode("\n", $lines);
    }

    /**
     * Map samples to pixel coordinates: newest rides the right edge and
     * the trace scrolls left; a window wider than the canvas is thinned
     * across the full pixel width instead of clipping old samples.
     *
     * @param list<float> $samples
     * @return list<array{int,int}>
     */
    private function pixelPath(array $samples, int $pxW, int $pxH, float $span): array
    {
        $count = count($samples);
        if ($count === 0) {
            return [];
        }

        $points = [];
        $lastIndex = $count - 1;
        foreach ($samples as $j => $value) {
            $x = $count <= $pxW
                ? $pxW - $count + $j
                : (int) round($j * ($pxW - 1) / max(1, $lastIndex));

            $normalised = $span > 0 ? min(1.0, max(0.0, $value / $span)) : 0.0;
            $y = ($pxH - 1) - (int) round($normalised * ($pxH - 1));
            $points[] = [$x, max(0, min($pxH - 1, $y))];
        }

        return $points;
    }

    /**
     * Set one dot in the mutable raster (bounds-checked early exit).
     *
     * @param list<list<int>> $bits
     * @param list<list<Color|null>> $tint
     */
    private function poke(array &$bits, array &$tint, int $x, int $y, int $pxW, int $pxH, Color $color): void
    {
        if ($x < 0 || $y < 0 || $x >= $pxW || $y >= $pxH) {
            return;
        }
        $col = BrailleMatrix::cellX($x);
        $row = BrailleMatrix::cellY($y);
        $bits[$row][$col] |= BrailleMatrix::dotBit($x, $y);
        $tint[$row][$col] = $color;
    }

    /**
     * One series shares the widget's own color; many series cycle the
     * palette so each line (and its legend swatch) is distinct.
     */
    private function seriesColor(int $index, bool $single): Color
    {
        if ($single) {
            $color = $this->widget->color;
            return Color::rgb($color['r'], $color['g'], $color['b']);
        }
        $slot = self::PALETTE[$index % count(self::PALETTE)];
        return Color::rgb($slot['r'], $slot['g'], $slot['b']);
    }

    /**
     * Compact one-line colored legend: '■name ■name …', ellipsised when a
     * trailing entry would not fit.
     */
    private function renderLegend(int $cellsW): string
    {
        $profile = ColorProfile::TrueColor;
        $single = false;

        $line = '';
        $used = 0;
        $index = 0;
        $names = array_keys($this->series);
        foreach ($names as $name) {
            $swatch = '■';
            $width = 1 + Width::of($name) + 1; // swatch + name + gap
            if ($used + $width > $cellsW) {
                $ellipsis = '…';
                if ($used + 1 <= $cellsW) {
                    $line .= $ellipsis;
                }
                break;
            }
            $color = $this->seriesColor($index, $single);
            $line .= $color->toFg($profile) . $swatch . Ansi::reset() . $name . ' ';
            $used += $width;
            $index++;
        }

        return rtrim($line);
    }
}
