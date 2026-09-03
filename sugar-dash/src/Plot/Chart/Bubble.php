<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Chart;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A data point for a bubble chart.
 */
/**
 * A bubble chart component.
 *
 * Displays data points as size-binned Unicode glyphs where:
 * - X position represents one dimension
 * - Y position represents another dimension
 * - Size selects the shape: a single dot, a five-cell plus, or a 5x5
 *   rounded box of quadrant arcs over a dot fill — never true circles,
 *   and never ASCII (see plotBubble's dispatch)
 * - Color can represent a category
 *
 * Mirrors bubble chart patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class Bubble implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /** @var list<BubblePoint> */
    private array $points = [];

    private bool $showGrid = true;
    private bool $showLabels = true;
    private bool $showLegend = false;
    private bool $showSizes = true;

    private float $minX = 0;
    private float $maxX = 100;
    private float $minY = 0;
    private float $maxY = 100;
    private float $minSize = 1;
    private float $maxSize = 10;

    /**
     * Bubble glyph table: quadrant arcs for the box corners plus the full
     * dot everywhere else — approximations of round shapes, never true
     * circles. Renamed from the old circle-named identifier in v5 D5 per
     * the R3 Set-3 ruling: the table drives shape-family glyphs, never
     * true circles.
     */
    private const ROUNDED_BOX_GLYPHS = [
        'top-left' => '◜',
        'top-right' => '◝',
        'bottom-left' => '◟',
        'bottom-right' => '◞',
        'full' => '●',
    ];

    public function __construct(
        private ?Color $color = null,
        private ?Color $gridColor = null,
        private ?Color $labelColor = null,
        private ?Color $bgColor = null,
    ) {}

    /**
     * Create a new bubble chart with default styling.
     */
    public static function new(array $points = []): self
    {
        return (new self(
            color: Color::hex('#89B4FA'),
            gridColor: Color::hex('#45475A'),
            labelColor: Color::hex('#CDD6F4'),
            bgColor: Color::hex('#1E1E2E'),
        ))->withPoints($points);
    }

    /**
     * Create a sample bubble chart for demonstration.
     */
    public static function sample(int $count = 6): self
    {
        $labels = ['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon', 'Zeta', 'Eta', 'Theta'];
        $categories = ['A', 'B', 'C'];
        $points = [];

        for ($i = 0; $i < $count; $i++) {
            $points[] = new BubblePoint(
                label: $labels[$i % count($labels)],
                x: random_int(10, 90),
                y: random_int(10, 90),
                size: random_int(2, 8),
                color: Color::hex(['#89B4FA', '#A6E3A1', '#F38BA8'][$i % 3]),
                category: $categories[$i % count($categories)],
            );
        }

        return self::new($points);
    }

    /**
     * Set the allocated dimensions for this bubble chart.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Set all data points at once.
     *
     * @param list<BubblePoint> $points
     */
    public function withPoints(array $points): self
    {
        $clone = clone $this;
        $clone->points = $points;

        // Auto-calculate bounds if points exist
        if (!empty($points)) {
            $clone->calculateBounds($points);
        }

        return $clone;
    }

    /**
     * Add a data point.
     */
    public function withPoint(BubblePoint $point): self
    {
        $clone = clone $this;
        $clone->points[] = $point;
        $clone->calculateBounds($clone->points);
        return $clone;
    }

    /**
     * Add a point by parameters.
     */
    public function addPoint(string $label, float $x, float $y, float $size, ?Color $color = null): self
    {
        return $this->withPoint(new BubblePoint($label, $x, $y, $size, $color));
    }

    /**
     * Calculate bounds from data points.
     *
     * @param list<BubblePoint> $points
     */
    private function calculateBounds(array $points): void
    {
        if (empty($points)) {
            return;
        }

        $xs = array_map(fn(BubblePoint $p) => $p->x, $points);
        $ys = array_map(fn(BubblePoint $p) => $p->y, $points);
        $sizes = array_map(fn(BubblePoint $p) => $p->size, $points);

        $this->minX = min($this->minX, ...$xs);
        $this->maxX = max($this->maxX, ...$xs);
        $this->minY = min($this->minY, ...$ys);
        $this->maxY = max($this->maxY, ...$ys);
        $this->minSize = min($this->minSize, ...$sizes);
        $this->maxSize = max($this->maxSize, ...$sizes);
    }

    /**
     * Set explicit bounds.
     */
    public function withXRange(float $min, float $max): self
    {
        $clone = clone $this;
        $clone->minX = $min;
        $clone->maxX = $max;
        return $clone;
    }

    /**
     * Set explicit Y range.
     */
    public function withYRange(float $min, float $max): self
    {
        $clone = clone $this;
        $clone->minY = $min;
        $clone->maxY = $max;
        return $clone;
    }

    /**
     * Set size range.
     */
    public function withSizeRange(float $min, float $max): self
    {
        $clone = clone $this;
        $clone->minSize = $min;
        $clone->maxSize = $max;
        return $clone;
    }

    /**
     * Show or hide grid.
     */
    public function withShowGrid(bool $show): self
    {
        $clone = clone $this;
        $clone->showGrid = $show;
        return $clone;
    }

    /**
     * Show or hide labels.
     */
    public function withShowLabels(bool $show): self
    {
        $clone = clone $this;
        $clone->showLabels = $show;
        return $clone;
    }

    /**
     * Show or hide legend.
     */
    public function withShowLegend(bool $show): self
    {
        $clone = clone $this;
        $clone->showLegend = $show;
        return $clone;
    }

    /**
     * Show or hide bubble sizes.
     */
    public function withShowSizes(bool $show): self
    {
        $clone = clone $this;
        $clone->showSizes = $show;
        return $clone;
    }

    /**
     * Render the bubble chart as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 50;
        $useHeight = $this->height ?? 20;

        if ($useWidth < 15 || $useHeight < 5 || empty($this->points)) {
            return '';
        }

        // Ensure ranges are valid
        if ($this->maxX <= $this->minX) {
            $this->maxX = $this->minX + 1;
        }
        if ($this->maxY <= $this->minY) {
            $this->maxY = $this->minY + 1;
        }

        $gridColor = $this->gridColor ?? Color::hex('#45475A');
        $labelColor = $this->labelColor ?? Color::hex('#CDD6F4');
        $bgColor = $this->bgColor ?? Color::hex('#1E1E2E');

        // Chart area dimensions
        $chartLeft = 8;  // Space for Y-axis labels
        $chartTop = 1;
        $chartRight = $useWidth - 1;
        $chartBottom = $useHeight - 2; // Space for X-axis labels
        $chartWidth = $chartRight - $chartLeft;
        $chartHeight = $chartBottom - $chartTop;

        // Build the grid
        $grid = [];
        for ($y = 0; $y < $chartHeight; $y++) {
            $grid[$y] = array_fill(0, $chartWidth, ' ');
        }

        // Draw grid lines
        if ($this->showGrid) {
            for ($y = 0; $y < $chartHeight; $y++) {
                for ($x = 0; $x < $chartWidth; $x++) {
                    if ($y === 0 || $y === $chartHeight - 1 || $x === 0 || $x === $chartWidth - 1) {
                        if ($gridColor !== null) {
                            $grid[$y][$x] = '·';
                        }
                    }
                }
            }
        }

        // Plot each bubble
        foreach ($this->points as $point) {
            $this->plotBubble($grid, $point, $chartWidth, $chartHeight);
        }

        // Build output
        $result = '';

        // Y-axis labels and grid
        for ($y = 0; $y < $chartHeight; $y++) {
            $yValue = $this->maxY - ($y / ($chartHeight - 1)) * ($this->maxY - $this->minY);
            $label = $this->formatValue($yValue);

            if ($gridColor !== null) {
                $result .= $gridColor->toFg(ColorProfile::TrueColor);
            }
            $result .= str_pad($label, 6) . ' ';
            if ($gridColor !== null) {
                $result .= Ansi::reset();
            }

            $result .= implode('', $grid[$y]);
            $result .= "\n";
        }

        // X-axis labels
        if ($gridColor !== null) {
            $result .= $gridColor->toFg(ColorProfile::TrueColor);
        }
        $result .= str_repeat(' ', 7);
        for ($x = 0; $x < $chartWidth; $x++) {
            $xValue = $this->minX + ($x / ($chartWidth - 1)) * ($this->maxX - $this->minX);
            if ($x % max(1, intval($chartWidth / 5)) === 0) {
                $result .= $this->formatValue($xValue)[0];
            } else {
                $result .= '─';
            }
        }
        if ($gridColor !== null) {
            $result .= Ansi::reset();
        }
        $result .= "\n";

        // Labels below chart
        if ($this->showLabels) {
            $labelLine = str_repeat(' ', 7);
            $perPoint = max(5, (int) ($chartWidth / max(1, count($this->points))));
            foreach ($this->points as $point) {
                $label = mb_substr($point->label, 0, $perPoint);
                $labelLine .= str_pad($label, $perPoint);
            }
            if ($labelColor !== null) {
                $labelLine = $labelColor->toFg(ColorProfile::TrueColor) . $labelLine . Ansi::reset();
            }
            $result .= $labelLine;
        }

        return $result;
    }

    /**
     * Plot a bubble on the grid.
     *
     * @param array<array<string>> $grid
     */
    private function plotBubble(array &$grid, BubblePoint $point, int $chartWidth, int $chartHeight): void
    {
        $x = $this->mapX($point->x, $chartWidth);
        $y = $this->mapY($point->y, $chartHeight);
        $size = $this->mapSize($point->size);

        $color = $point->color ?? $this->color;

        // Draw the size-binned glyph cluster (mapSize bins 1..3). The shapes are
        // a plus and a solid box built from Unicode arcs/dots — never true
        // circles, and never ASCII.
        if ($size <= 1) {
            // Single cell
            if ($x >= 0 && $x < $chartWidth && $y >= 0 && $y < $chartHeight) {
                if ($color !== null) {
                    $grid[$y][$x] = $color->toFg(ColorProfile::TrueColor) . '●' . Ansi::reset();
                } else {
                    $grid[$y][$x] = '●';
                }
            }
        } elseif ($size === 2) {
            // r=1: five-cell plus within a 3x3 extent — the disk test drops the
            // diagonals, so this cluster carries no corner arcs.
            $this->drawShapeCluster($grid, $x, $y, 1, $color, $chartWidth, $chartHeight);
        } else {
            // r=2: solid 5x5 box with ROUNDED_BOX_GLYPHS quadrant arcs at the corners.
            $this->drawShapeCluster($grid, $x, $y, 2, $color, $chartWidth, $chartHeight);
        }
    }

    /**
     * Draw a size-binned shape cluster on the grid.
     *
     * r >= 2 fills the whole (2r+1)-cell box so the ROUNDED_BOX_GLYPHS quadrant
     * arcs land on the diagonal extremes — a pure disk never admits
     * |dx|=|dy|=r, leaving those glyphs unreachable (chart_plan.md S1
     * amendment). The full box is trivially 4-fold symmetric and 4-connected.
     * r == 1 keeps the disk test: its diagonals fall outside dx²+dy²≤1, so it
     * renders the legacy 5-cell plus with no corner arcs, per the r >= 2
     * contract clause.
     *
     * @param array<array<string>> $grid
     */
    private function drawShapeCluster(array &$grid, int $cx, int $cy, int $radius, ?Color $color, int $chartWidth, int $chartHeight): void
    {
        for ($dy = -$radius; $dy <= $radius; $dy++) {
            for ($dx = -$radius; $dx <= $radius; $dx++) {
                $inSmallDisk = $dx * $dx + $dy * $dy <= $radius * $radius;
                if ($radius < 2 && !$inSmallDisk) {
                    continue;
                }

                $x = $cx + $dx;
                $y = $cy + $dy;

                if ($x >= 0 && $x < $chartWidth && $y >= 0 && $y < $chartHeight) {
                    // Determine which shape glyph to use based on position
                    $char = $this->glyphAtOffset($dx, $dy, $radius);
                    if ($color !== null) {
                        $grid[$y][$x] = $color->toFg(ColorProfile::TrueColor) . $char . Ansi::reset();
                    } else {
                        $grid[$y][$x] = $char;
                    }
                }
            }
        }
    }

    /**
     * Resolve the cluster glyph sitting at a (dx, dy) grid offset.
     *
     * Resolved through ROUNDED_BOX_GLYPHS so the table (not per-branch
     * literals) drives render: quadrant arcs at the diagonal extremes, the
     * full glyph everywhere else — cardinals, interior fill, and the r == 1
     * plus.
     */
    private function glyphAtOffset(int $dx, int $dy, int $radius): string
    {
        if ($dx === -$radius && $dy === -$radius) {
            return self::ROUNDED_BOX_GLYPHS['top-left'];
        }
        if ($dx === $radius && $dy === -$radius) {
            return self::ROUNDED_BOX_GLYPHS['top-right'];
        }
        if ($dx === -$radius && $dy === $radius) {
            return self::ROUNDED_BOX_GLYPHS['bottom-left'];
        }
        if ($dx === $radius && $dy === $radius) {
            return self::ROUNDED_BOX_GLYPHS['bottom-right'];
        }

        return self::ROUNDED_BOX_GLYPHS['full'];
    }

    /**
     * Map X value to grid position.
     */
    private function mapX(float $x, int $chartWidth): int
    {
        $ratio = ($x - $this->minX) / ($this->maxX - $this->minX);
        return intval($ratio * ($chartWidth - 1));
    }

    /**
     * Map Y value to grid position.
     */
    private function mapY(float $y, int $chartHeight): int
    {
        $ratio = ($y - $this->minY) / ($this->maxY - $this->minY);
        return intval((1 - $ratio) * ($chartHeight - 1));
    }

    /**
     * Map a raw size value to a glyph bin: 1 = single cell, 2 = r=1 plus,
     * 3 = r=2 rounded box — the largest band (raw 7..10 at the default
     * 1..10 size range).
     *
     * The ladder is capped at 3 because plotBubble dispatches only three
     * shapes: the former bin 4 fell in the same r=2 arm as bin 3 and
     * rendered byte-identical, so the fourth rung was dead weight.
     *
     * A degenerate size range (withSizeRange(x, x)) collapses the span to
     * zero; PHP 8 makes that division fatal, so the contract pins ratio to 1
     * and every point bins to the largest glyph instead of crashing render().
     */
    private function mapSize(float $size): int
    {
        $ratio = $this->maxSize === $this->minSize
            ? 1.0
            : ($size - $this->minSize) / ($this->maxSize - $this->minSize);

        return max(1, min(3, intval(1 + $ratio * 3)));
    }

    /**
     * Format a value for display.
     */
    private function formatValue(float $value): string
    {
        if (abs($value) >= 1000000) {
            return sprintf('%.1fM', $value / 1000000);
        }
        if (abs($value) >= 1000) {
            return sprintf('%.1fK', $value / 1000);
        }
        if ($value === floor($value)) {
            return sprintf('%.0f', $value);
        }
        return sprintf('%.1f', $value);
    }

    /**
     * Calculate the natural dimensions of this bubble chart.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->width ?? 50;
        $height = $this->height ?? 20;

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the default color.
     */
    public function withColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->color = $color;
        return $clone;
    }

    /**
     * Set the grid color.
     */
    public function withGridColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->gridColor = $color;
        return $clone;
    }

    /**
     * Set the label color.
     */
    public function withLabelColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->labelColor = $color;
        return $clone;
    }

    /**
     * Set the background color.
     */
    public function withBgColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->bgColor = $color;
        return $clone;
    }
}
