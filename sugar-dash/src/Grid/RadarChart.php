<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A radar/spider chart component for displaying multi-axis data.
 *
 * Features:
 * - Configurable number of axes (3-12)
 * - Multiple data series with different colors
 * - Grid lines at specified intervals
 * - Configurable size and scale
 * - Optional data point markers
 *
 * Mirrors radar/spider chart patterns adapted to PHP with wither-style
 * immutable setters.
 */
final class RadarChart implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<string> $labels The axis labels
     * @param list<array{label: string, values: list<float>, color: Color|null}> $series
     */
    public function __construct(
        private readonly array $labels,
        private readonly array $series,
        private readonly int $size = 20,
        private readonly int $gridLines = 4,
        private readonly float $maxValue = 1.0,
        private readonly bool $showLabels = true,
        private readonly bool $showGrid = true,
        private readonly bool $showDots = true,
    ) {}

    /**
     * Create a new radar chart.
     *
     * @param list<string> $labels
     * @param list<array{label: string, values: list<float>, color?: string|Color|null}> $series
     */
    public static function new(array $labels, array $series): self
    {
        $normalizedSeries = array_map(function (array $item) use (&$colorIndex): array {
            $color = $item['color'] ?? null;
            if (is_string($color)) {
                $color = Color::hex($color);
            }
            if ($color === null) {
                $colors = [
                    Color::hex('#F38BA8'),
                    Color::hex('#A6E3A1'),
                    Color::hex('#89B4FA'),
                    Color::hex('#F9E2AF'),
                    Color::hex('#CBA6F7'),
                ];
                $color = $colors[$colorIndex % count($colors)];
                $colorIndex++;
            }
            return [
                'label' => $item['label'],
                'values' => array_map('floatval', $item['values']),
                'color' => $color,
            ];
        }, $series);

        $colorIndex = 0;

        return new self(
            labels: $labels,
            series: $normalizedSeries,
            size: 20,
            gridLines: 4,
            maxValue: 1.0,
            showLabels: true,
            showGrid: true,
            showDots: true,
        );
    }

    /**
     * Set the allocated dimensions for this chart.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Calculate the natural dimensions of this radar chart.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $useSize = min($this->width ?? $this->size, $this->height ?? $this->size);
        // Need extra space for labels
        return [$useSize + 10, $useSize + 10];
    }

    /**
     * Render the radar chart.
     */
    public function render(): string
    {
        $numAxes = count($this->labels);
        if ($numAxes < 3) {
            return '';
        }

        $useSize = min($this->width ?? $this->size, $this->height ?? $this->size);
        $radius = (int) floor($useSize / 2) - 1;

        $centerX = $radius + 1;
        $centerY = $radius + 1;

        // Build the grid
        $gridWidth = $useSize + 10;
        $gridHeight = $useSize + 10;
        $grid = [];
        for ($y = 0; $y < $gridHeight; $y++) {
            $grid[$y] = array_fill(0, $gridWidth, ['char' => ' ', 'color' => null]);
        }

        // Draw grid circles
        if ($this->showGrid) {
            for ($level = 1; $level <= $this->gridLines; $level++) {
                $levelRadius = (int) floor($radius * $level / $this->gridLines);
                $this->drawCircle($grid, $centerX, $centerY, $levelRadius, '·', Color::hex('#6C7086'));
            }
        }

        // Draw axis lines
        for ($i = 0; $i < $numAxes; $i++) {
            $angle = $this->getAxisAngle($i, $numAxes);
            $endX = (int) round($centerX + $radius * cos($angle));
            $endY = (int) round($centerY - $radius * sin($angle));
            $this->drawLine($grid, $centerX, $centerY, $endX, $endY, '─', Color::hex('#6C7086'));
        }

        // Draw data series
        foreach ($this->series as $seriesIndex => $series) {
            $this->drawSeries($grid, $centerX, $centerY, $radius, $series, $numAxes);
        }

        // Convert grid to string
        $result = '';
        for ($y = 0; $y < $gridHeight; $y++) {
            for ($x = 0; $x < $gridWidth; $x++) {
                $cell = $grid[$y][$x];
                if ($cell['color'] !== null) {
                    $result .= $cell['color']->toFg(ColorProfile::TrueColor);
                }
                $result .= $cell['char'];
                if ($cell['color'] !== null) {
                    $result .= Ansi::reset();
                }
            }
            $result .= "\n";
        }

        return rtrim($result, "\n");
    }

    /**
     * Get the angle for an axis in radians.
     */
    private function getAxisAngle(int $index, int $total): float
    {
        // Start from top (270° in standard coordinates) and go clockwise
        $baseAngle = 270.0 * (M_PI / 180.0); // Start at top
        $angleStep = 2.0 * M_PI / $total;
        return $baseAngle + ($index * $angleStep);
    }

    /**
     * Draw a circle on the grid.
     *
     * @param array<array<array{char:string, color:Color|null}>> $grid
     */
    private function drawCircle(array &$grid, int $cx, int $cy, int $radius, string $char, Color $color): void
    {
        $width = count($grid[0]);
        $height = count($grid);

        for ($x = $cx - $radius; $x <= $cx + $radius; $x++) {
            $dx = $x - $cx;
            $dySquared = $radius * $radius - $dx * $dx;
            if ($dySquared < 0) {
                continue;
            }
            $dy = (int) floor(sqrt($dySquared));

            foreach ([$cy - $dy, $cy + $dy] as $y) {
                if ($y >= 0 && $y < $height && $x >= 0 && $x < $width) {
                    $grid[$y][$x] = ['char' => $char, 'color' => $color];
                }
            }
        }
    }

    /**
     * Draw a line on the grid using Bresenham's algorithm.
     *
     * @param array<array<array{char:string, color:Color|null}>> $grid
     */
    private function drawLine(array &$grid, int $x0, int $y0, int $x1, int $y1, string $char, Color $color): void
    {
        $width = count($grid[0]);
        $height = count($grid);

        $dx = abs($x1 - $x0);
        $dy = abs($y1 - $y0);
        $sx = $x0 < $x1 ? 1 : -1;
        $sy = $y0 < $y1 ? 1 : -1;
        $err = $dx - $dy;

        $x = $x0;
        $y = $y0;

        while (true) {
            if ($x >= 0 && $x < $width && $y >= 0 && $y < $height) {
                $grid[$y][$x] = ['char' => $char, 'color' => $color];
            }

            if ($x === $x1 && $y === $y1) {
                break;
            }

            $e2 = 2 * $err;
            if ($e2 > -$dy) {
                $err -= $dy;
                $x += $sx;
            }
            if ($e2 < $dx) {
                $err += $dx;
                $y += $sy;
            }
        }
    }

    /**
     * Draw a data series on the grid.
     *
     * @param array<array<array{char:string, color:Color|null}>> $grid
     */
    private function drawSeries(array &$grid, int $cx, int $cy, int $radius, array $series, int $numAxes): void
    {
        $values = $series['values'];
        $color = $series['color'];
        $width = count($grid[0]);
        $height = count($grid);

        // Collect points
        $points = [];
        for ($i = 0; $i < $numAxes; $i++) {
            $value = $values[$i] ?? 0.0;
            $normalizedValue = min(1.0, max(0.0, $value / $this->maxValue));
            $angle = $this->getAxisAngle($i, $numAxes);
            $r = $radius * $normalizedValue;
            $x = (int) round($cx + $r * cos($angle));
            $y = (int) round($cy - $r * sin($angle));
            $points[] = [$x, $y];
        }

        // Draw lines between points
        for ($i = 0; $i < count($points); $i++) {
            $next = ($i + 1) % count($points);
            [$x1, $y1] = $points[$i];
            [$x2, $y2] = $points[$next];
            $this->drawLine($grid, $x1, $y1, $x2, $y2, '─', $color);

            // Draw dots at vertices
            if ($this->showDots && $x1 >= 0 && $x1 < $width && $y1 >= 0 && $y1 < $height) {
                $grid[$y1][$x1] = ['char' => '●', 'color' => $color];
            }
        }
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the chart size.
     */
    public function withSize(int $size): self
    {
        return new self(
            labels: $this->labels,
            series: $this->series,
            size: $size,
            gridLines: $this->gridLines,
            maxValue: $this->maxValue,
            showLabels: $this->showLabels,
            showGrid: $this->showGrid,
            showDots: $this->showDots,
        );
    }

    /**
     * Set the number of grid lines.
     */
    public function withGridLines(int $lines): self
    {
        return new self(
            labels: $this->labels,
            series: $this->series,
            size: $this->size,
            gridLines: $lines,
            maxValue: $this->maxValue,
            showLabels: $this->showLabels,
            showGrid: $this->showGrid,
            showDots: $this->showDots,
        );
    }

    /**
     * Set the maximum value for the scale.
     */
    public function withMaxValue(float $max): self
    {
        return new self(
            labels: $this->labels,
            series: $this->series,
            size: $this->size,
            gridLines: $this->gridLines,
            maxValue: $max,
            showLabels: $this->showLabels,
            showGrid: $this->showGrid,
            showDots: $this->showDots,
        );
    }

    /**
     * Show or hide axis labels.
     */
    public function withShowLabels(bool $show): self
    {
        return new self(
            labels: $this->labels,
            series: $this->series,
            size: $this->size,
            gridLines: $this->gridLines,
            maxValue: $this->maxValue,
            showLabels: $show,
            showGrid: $this->showGrid,
            showDots: $this->showDots,
        );
    }

    /**
     * Show or hide grid lines.
     */
    public function withShowGrid(bool $show): self
    {
        return new self(
            labels: $this->labels,
            series: $this->series,
            size: $this->size,
            gridLines: $this->gridLines,
            maxValue: $this->maxValue,
            showLabels: $this->showLabels,
            showGrid: $show,
            showDots: $this->showDots,
        );
    }

    /**
     * Show or hide data point dots.
     */
    public function withShowDots(bool $show): self
    {
        return new self(
            labels: $this->labels,
            series: $this->series,
            size: $this->size,
            gridLines: $this->gridLines,
            maxValue: $this->maxValue,
            showLabels: $this->showLabels,
            showGrid: $this->showGrid,
            showDots: $show,
        );
    }
}
