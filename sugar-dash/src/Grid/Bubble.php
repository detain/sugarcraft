<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A data point for a bubble chart.
 */
final class BubblePoint
{
    public function __construct(
        public readonly string $label,
        public readonly float $x,
        public readonly float $y,
        public readonly float $size,       // Radius factor
        public readonly ?Color $color = null,
        public readonly ?string $category = null,
    ) {}

    /**
     * Create a copy with a different color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            $this->label,
            $this->x,
            $this->y,
            $this->size,
            $color,
            $this->category,
        );
    }
}

/**
 * A bubble chart component.
 *
 * Displays data points as circles where:
 * - X position represents one dimension
 * - Y position represents another dimension
 * - Size represents a third dimension
 * - Color can represent a category
 *
 * Mirrors bubble chart patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class Bubble implements Sizer
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
     * Circle drawing characters (partial arcs).
     */
    private const CIRCLE_CHARS = [
        'top-left' => '◜',
        'top-right' => '◝',
        'bottom-left' => '◟',
        'bottom-right' => '◠',
        'full' => '●',
    ];

    public function __construct(
        private readonly ?Color $color = null,
        private readonly ?Color $gridColor = null,
        private readonly ?Color $labelColor = null,
        private readonly ?Color $bgColor = null,
    ) {}

    /**
     * Create a new bubble chart with default styling.
     */
    public static function new(array $points = []): self
    {
        return new self(
            color: Color::hex('#89B4FA'),
            gridColor: Color::hex('#45475A'),
            labelColor: Color::hex('#CDD6F4'),
            bgColor: Color::hex('#1E1E2E'),
        )->withPoints($points);
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
    public function setSize(int $width, int $height): Sizer
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
            foreach ($this->points as $point) {
                $x = $this->mapX($point->x, $chartWidth);
                $label = mb_substr($point->label, 0, 3);
                $labelLine .= str_pad($label, $chartWidth / count($this->points));
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

        // Draw filled circle using ASCII characters
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
            // 3x3 bubble
            $this->drawBubbleOnGrid($grid, $x, $y, 1, $color, $chartWidth, $chartHeight);
        } else {
            // 5x5 bubble
            $this->drawBubbleOnGrid($grid, $x, $y, 2, $color, $chartWidth, $chartHeight);
        }
    }

    /**
     * Draw a bubble on the grid.
     *
     * @param array<array<string>> $grid
     */
    private function drawBubbleOnGrid(array &$grid, int $cx, int $cy, int $radius, ?Color $color, int $chartWidth, int $chartHeight): void
    {
        for ($dy = -$radius; $dy <= $radius; $dy++) {
            for ($dx = -$radius; $dx <= $radius; $dx++) {
                if ($dx * $dx + $dy * $dy <= $radius * $radius) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;

                    if ($x >= 0 && $x < $chartWidth && $y >= 0 && $y < $chartHeight) {
                        // Determine which circle character to use based on position
                        $char = $this->getCircleChar($dx, $dy, $radius);
                        if ($color !== null) {
                            $grid[$y][$x] = $color->toFg(ColorProfile::TrueColor) . $char . Ansi::reset();
                        } else {
                            $grid[$y][$x] = $char;
                        }
                    }
                }
            }
        }
    }

    /**
     * Get circle character for position.
     */
    private function getCircleChar(int $dx, int $dy, int $radius): string
    {
        $isEdge = abs($dx) === $radius || abs($dy) === $radius;

        if (!$isEdge) {
            return '●';
        }

        // Edge characters
        if ($dx === -$radius && $dy === -$radius) {
            return '◜';
        }
        if ($dx === $radius && $dy === -$radius) {
            return '◝';
        }
        if ($dx === -$radius && $dy === $radius) {
            return '◟';
        }
        if ($dx === $radius && $dy === $radius) {
            return '◠';
        }

        return '●';
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
     * Map size value to pixel radius.
     */
    private function mapSize(float $size): int
    {
        $ratio = ($size - $this->minSize) / ($this->maxSize - $this->minSize);
        return max(1, intval(1 + $ratio * 3));
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
        return new self(
            color: $color,
            gridColor: $this->gridColor,
            labelColor: $this->labelColor,
            bgColor: $this->bgColor,
        );
    }

    /**
     * Set the grid color.
     */
    public function withGridColor(?Color $color): self
    {
        return new self(
            color: $this->color,
            gridColor: $color,
            labelColor: $this->labelColor,
            bgColor: $this->bgColor,
        );
    }

    /**
     * Set the label color.
     */
    public function withLabelColor(?Color $color): self
    {
        return new self(
            color: $this->color,
            gridColor: $this->gridColor,
            labelColor: $color,
            bgColor: $this->bgColor,
        );
    }

    /**
     * Set the background color.
     */
    public function withBgColor(?Color $color): self
    {
        return new self(
            color: $this->color,
            gridColor: $this->gridColor,
            labelColor: $this->labelColor,
            bgColor: $color,
        );
    }
}
