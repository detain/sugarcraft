<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * Chart types.
 */
enum ChartType: string
{
    case Bar = 'bar';
    case Line = 'line';
}

/**
 * Data point for charts.
 */
final readonly class ChartDataPoint
{
    public function __construct(
        public string $label,
        public float $value,
    ) {}
}

/**
 * A bar/line chart component with axes, labels, and grid.
 *
 * Displays data as either vertical bars or connected line points.
 * Supports custom colors, axis labels, grid lines, and value labels.
 *
 * Mirrors chart rendering from bubbletea/spinner but adapted to PHP
 * with wither-style immutable setters.
 */
final class Chart implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /** @var list<ChartDataPoint> */
    private array $dataPoints;

    /**
     * Block characters for bar chart.
     */
    private const BAR_CHARS = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];

    public function __construct(
        array $dataPoints = [],
        private readonly ChartType $type = ChartType::Bar,
        private readonly ?int $widthConstraint = null,
        private readonly int $heightConstraint = 10,
        private readonly bool $showGrid = true,
        private readonly bool $showValues = false,
        private readonly bool $showLabels = true,
        private readonly ?string $xAxisLabel = null,
        private readonly ?string $yAxisLabel = null,
        private readonly ?Color $color = null,
        private readonly ?Color $gridColor = null,
        private readonly ?Color $labelColor = null,
    ) {
        $this->dataPoints = $dataPoints;
    }

    /**
     * Create a new chart with default styling.
     *
     * @param list<ChartDataPoint> $dataPoints
     */
    public static function new(array $dataPoints = [], ChartType $type = ChartType::Bar): self
    {
        return new self(
            dataPoints: $dataPoints,
            type: $type,
            widthConstraint: null,
            heightConstraint: 10,
            showGrid: true,
            showValues: false,
            showLabels: true,
            xAxisLabel: null,
            yAxisLabel: null,
            color: Color::hex('#89B4FA'),
            gridColor: Color::hex('#45475A'),
            labelColor: Color::hex('#6C7086'),
        );
    }

    /**
     * Set the allocated dimensions for this chart.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the chart as a string.
     */
    public function render(): string
    {
        $chartWidth = $this->getChartWidth();
        $chartHeight = $this->getChartHeight();

        if ($chartWidth <= 0 || $chartHeight <= 0 || empty($this->dataPoints)) {
            return '';
        }

        $output = '';

        // Calculate chart bounds
        $values = array_map(fn(ChartDataPoint $p) => $p->value, $this->dataPoints);
        $minValue = min(0, min($values)); // Always start at 0 or lower
        $maxValue = max($values);
        if ($maxValue === $minValue) {
            $maxValue = $minValue + 1;
        }

        // Generate grid lines
        $gridLines = $this->generateGridLines($minValue, $maxValue, $chartHeight);

        // Render based on chart type
        if ($this->type === ChartType::Bar) {
            $output = $this->renderBarChart($chartWidth, $chartHeight, $minValue, $maxValue, $gridLines);
        } else {
            $output = $this->renderLineChart($chartWidth, $chartHeight, $minValue, $maxValue, $gridLines);
        }

        return $output;
    }

    /**
     * Render a bar chart.
     *
     * @return list<string>
     */
    private function renderBarChart(
        int $chartWidth,
        int $chartHeight,
        float $minValue,
        float $maxValue,
        array $gridLines
    ): string {
        $output = '';
        $dataCount = count($this->dataPoints);
        $barWidth = max(1, (int) floor($chartWidth / $dataCount) - 1);
        $range = $maxValue - $minValue;

        // Build the chart area line by line (top to bottom)
        for ($row = $chartHeight - 1; $row >= 0; $row--) {
            $line = '';
            $yValue = $gridLines[$row];

            // Y-axis label
            if ($this->showGrid && $row < count($gridLines)) {
                $yLabel = $this->formatYLabel($yValue);
                $line .= str_pad($yLabel, 8) . ' ';
            }

            // Draw each bar
            for ($i = 0; $i < $dataCount; $i++) {
                $point = $this->dataPoints[$i];
                $barHeight = (int) round((($point->value - $minValue) / $range) * $chartHeight);
                $threshold = $row + 1;

                if ($barHeight >= $threshold) {
                    // Filled bar
                    if ($this->color !== null) {
                        $line .= $this->color->toFg(ColorProfile::TrueColor);
                    }
                    $line .= str_repeat('█', $barWidth);
                    if ($this->color !== null) {
                        $line .= Ansi::reset();
                    }
                } else {
                    $line .= str_repeat(' ', $barWidth);
                }

                // Spacing between bars
                $line .= ' ';
            }

            // Grid line
            if ($this->showGrid) {
                if ($this->gridColor !== null) {
                    $line .= $this->gridColor->toFg(ColorProfile::TrueColor);
                }
                $line .= '─';
                if ($this->gridColor !== null) {
                    $line .= Ansi::reset();
                }
            }

            $output .= $line . "\n";
        }

        // X-axis line
        if ($this->showGrid) {
            $line = str_repeat(' ', 8); // Y-axis spacing
            $line .= str_repeat('─', $chartWidth);
            if ($this->gridColor !== null) {
                $line = $this->gridColor->toFg(ColorProfile::TrueColor) . $line . Ansi::reset();
            }
            $output .= $line . "\n";
        }

        // Labels
        if ($this->showLabels) {
            $line = str_repeat(' ', 8);
            foreach ($this->dataPoints as $point) {
                $label = mb_substr($point->label, 0, $barWidth, 'UTF-8');
                $label = str_pad($label, $barWidth);
                $line .= $label . ' ';
            }
            if ($this->labelColor !== null) {
                $line = $this->labelColor->toFg(ColorProfile::TrueColor) . $line . Ansi::reset();
            }
            $output .= $line;
        }

        return trim($output);
    }

    /**
     * Render a line chart.
     *
     * @return list<string>
     */
    private function renderLineChart(
        int $chartWidth,
        int $chartHeight,
        float $minValue,
        float $maxValue,
        array $gridLines
    ): string {
        $output = '';
        $dataCount = count($this->dataPoints);
        $range = $maxValue - $minValue;
        $plotWidth = $chartWidth - 1;

        // Calculate normalized points
        $points = [];
        for ($i = 0; $i < $dataCount; $i++) {
            $x = (int) (($i / max(1, $dataCount - 1)) * $plotWidth);
            $y = $chartHeight - 1 - (int) round((($this->dataPoints[$i]->value - $minValue) / $range) * ($chartHeight - 1));
            $points[] = [$x, $y];
        }

        // Build the chart area
        $grid = [];
        for ($h = 0; $h < $chartHeight; $h++) {
            $grid[] = array_fill(0, $chartWidth, ' ');
        }

        // Draw grid lines
        if ($this->showGrid) {
            for ($row = 0; $row < $chartHeight; $row++) {
                for ($col = 0; $col < $chartWidth; $col++) {
                    if ($row === 0 || $row === $chartHeight - 1 || $col === 0 || $col === $chartWidth - 1) {
                        if ($this->gridColor !== null) {
                            $grid[$row][$col] = $this->gridColor->toFg(ColorProfile::TrueColor) . '·' . Ansi::reset();
                        } else {
                            $grid[$row][$col] = '·';
                        }
                    }
                }
            }
        }

        // Draw line segments
        for ($i = 0; $i < count($points) - 1; $i++) {
            [$x1, $y1] = $points[$i];
            [$x2, $y2] = $points[$i + 1];

            // Interpolate between points
            $steps = max(1, abs($x2 - $x1));
            for ($step = 0; $step <= $steps; $step++) {
                $t = $step / $steps;
                $x = (int) ($x1 + ($x2 - $x1) * $t);
                $y = (int) ($y1 + ($y2 - $y1) * $t);

                if ($x >= 0 && $x < $chartWidth && $y >= 0 && $y < $chartHeight) {
                    if ($this->color !== null) {
                        $grid[$y][$x] = $this->color->toFg(ColorProfile::TrueColor) . '●' . Ansi::reset();
                    } else {
                        $grid[$y][$x] = '●';
                    }
                }
            }
        }

        // Convert grid to string (top row first; grid row 0 holds the max-value points)
        for ($row = 0; $row < $chartHeight; $row++) {
            $line = '';

            // Y-axis label — gridLines[0] is min, so invert the index so the
            // top row shows the maximum value.
            $labelIndex = $chartHeight - 1 - $row;
            if ($this->showGrid && isset($gridLines[$labelIndex])) {
                $yLabel = $this->formatYLabel($gridLines[$labelIndex]);
                $line .= str_pad($yLabel, 8) . ' ';
            }

            $line .= implode('', $grid[$row]);

            $output .= $line . "\n";
        }

        // X-axis labels
        if ($this->showLabels) {
            $line = str_repeat(' ', 8);
            $labelWidth = max(1, (int) floor($chartWidth / $dataCount));
            foreach ($this->dataPoints as $point) {
                $label = mb_substr($point->label, 0, $labelWidth, 'UTF-8');
                $label = str_pad($label, $labelWidth);
                $line .= $label;
            }
            if ($this->labelColor !== null) {
                $line = $this->labelColor->toFg(ColorProfile::TrueColor) . $line . Ansi::reset();
            }
            $output .= $line;
        }

        return trim($output);
    }

    /**
     * Generate grid line values.
     *
     * @return list<float>
     */
    private function generateGridLines(float $min, float $max, int $height): array
    {
        $lines = [];
        $step = ($max - $min) / max(1, $height - 1);

        for ($i = 0; $i < $height; $i++) {
            $lines[] = $min + ($step * $i);
        }

        return $lines;
    }

    /**
     * Format a Y-axis label.
     */
    private function formatYLabel(float $value): string
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
     * Get the chart width.
     */
    private function getChartWidth(): int
    {
        if ($this->width !== null && $this->width > 0) {
            return max(0, $this->width - 10); // Account for y-axis labels
        }
        return $this->widthConstraint ?? 40;
    }

    /**
     * Get the chart height.
     */
    private function getChartHeight(): int
    {
        return $this->height ?? $this->heightConstraint;
    }

    /**
     * Calculate the natural dimensions of this chart.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = ($this->width ?? $this->widthConstraint ?? 50) + 10;
        $height = ($this->height ?? $this->heightConstraint) + 2; // For labels

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the data points.
     *
     * @param list<ChartDataPoint> $dataPoints
     */
    public function withDataPoints(array $dataPoints): self
    {
        return new self(
            dataPoints: $dataPoints,
            type: $this->type,
            widthConstraint: $this->widthConstraint,
            heightConstraint: $this->heightConstraint,
            showGrid: $this->showGrid,
            showValues: $this->showValues,
            showLabels: $this->showLabels,
            xAxisLabel: $this->xAxisLabel,
            yAxisLabel: $this->yAxisLabel,
            color: $this->color,
            gridColor: $this->gridColor,
            labelColor: $this->labelColor,
        );
    }

    /**
     * Set the chart type.
     */
    public function withType(ChartType $type): self
    {
        return new self(
            dataPoints: $this->dataPoints,
            type: $type,
            widthConstraint: $this->widthConstraint,
            heightConstraint: $this->heightConstraint,
            showGrid: $this->showGrid,
            showValues: $this->showValues,
            showLabels: $this->showLabels,
            xAxisLabel: $this->xAxisLabel,
            yAxisLabel: $this->yAxisLabel,
            color: $this->color,
            gridColor: $this->gridColor,
            labelColor: $this->labelColor,
        );
    }

    /**
     * Set the width constraint.
     */
    public function withWidth(int $width): self
    {
        return new self(
            dataPoints: $this->dataPoints,
            type: $this->type,
            widthConstraint: $width,
            heightConstraint: $this->heightConstraint,
            showGrid: $this->showGrid,
            showValues: $this->showValues,
            showLabels: $this->showLabels,
            xAxisLabel: $this->xAxisLabel,
            yAxisLabel: $this->yAxisLabel,
            color: $this->color,
            gridColor: $this->gridColor,
            labelColor: $this->labelColor,
        );
    }

    /**
     * Set the height constraint.
     */
    public function withHeight(int $height): self
    {
        return new self(
            dataPoints: $this->dataPoints,
            type: $this->type,
            widthConstraint: $this->widthConstraint,
            heightConstraint: $height,
            showGrid: $this->showGrid,
            showValues: $this->showValues,
            showLabels: $this->showLabels,
            xAxisLabel: $this->xAxisLabel,
            yAxisLabel: $this->yAxisLabel,
            color: $this->color,
            gridColor: $this->gridColor,
            labelColor: $this->labelColor,
        );
    }

    /**
     * Show or hide grid lines.
     */
    public function withGrid(bool $show): self
    {
        return new self(
            dataPoints: $this->dataPoints,
            type: $this->type,
            widthConstraint: $this->widthConstraint,
            heightConstraint: $this->heightConstraint,
            showGrid: $show,
            showValues: $this->showValues,
            showLabels: $this->showLabels,
            xAxisLabel: $this->xAxisLabel,
            yAxisLabel: $this->yAxisLabel,
            color: $this->color,
            gridColor: $this->gridColor,
            labelColor: $this->labelColor,
        );
    }

    /**
     * Show or hide value labels.
     */
    public function withShowValues(bool $show): self
    {
        return new self(
            dataPoints: $this->dataPoints,
            type: $this->type,
            widthConstraint: $this->widthConstraint,
            heightConstraint: $this->heightConstraint,
            showGrid: $this->showGrid,
            showValues: $show,
            showLabels: $this->showLabels,
            xAxisLabel: $this->xAxisLabel,
            yAxisLabel: $this->yAxisLabel,
            color: $this->color,
            gridColor: $this->gridColor,
            labelColor: $this->labelColor,
        );
    }

    /**
     * Show or hide X-axis labels.
     */
    public function withShowLabels(bool $show): self
    {
        return new self(
            dataPoints: $this->dataPoints,
            type: $this->type,
            widthConstraint: $this->widthConstraint,
            heightConstraint: $this->heightConstraint,
            showGrid: $this->showGrid,
            showValues: $this->showValues,
            showLabels: $show,
            xAxisLabel: $this->xAxisLabel,
            yAxisLabel: $this->yAxisLabel,
            color: $this->color,
            gridColor: $this->gridColor,
            labelColor: $this->labelColor,
        );
    }

    /**
     * Set the X-axis label.
     */
    public function withXAxisLabel(?string $label): self
    {
        return new self(
            dataPoints: $this->dataPoints,
            type: $this->type,
            widthConstraint: $this->widthConstraint,
            heightConstraint: $this->heightConstraint,
            showGrid: $this->showGrid,
            showValues: $this->showValues,
            showLabels: $this->showLabels,
            xAxisLabel: $label,
            yAxisLabel: $this->yAxisLabel,
            color: $this->color,
            gridColor: $this->gridColor,
            labelColor: $this->labelColor,
        );
    }

    /**
     * Set the Y-axis label.
     */
    public function withYAxisLabel(?string $label): self
    {
        return new self(
            dataPoints: $this->dataPoints,
            type: $this->type,
            widthConstraint: $this->widthConstraint,
            heightConstraint: $this->heightConstraint,
            showGrid: $this->showGrid,
            showValues: $this->showValues,
            showLabels: $this->showLabels,
            xAxisLabel: $this->xAxisLabel,
            yAxisLabel: $label,
            color: $this->color,
            gridColor: $this->gridColor,
            labelColor: $this->labelColor,
        );
    }

    /**
     * Set the chart color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            dataPoints: $this->dataPoints,
            type: $this->type,
            widthConstraint: $this->widthConstraint,
            heightConstraint: $this->heightConstraint,
            showGrid: $this->showGrid,
            showValues: $this->showValues,
            showLabels: $this->showLabels,
            xAxisLabel: $this->xAxisLabel,
            yAxisLabel: $this->yAxisLabel,
            color: $color,
            gridColor: $this->gridColor,
            labelColor: $this->labelColor,
        );
    }

    /**
     * Set the grid color.
     */
    public function withGridColor(?Color $color): self
    {
        return new self(
            dataPoints: $this->dataPoints,
            type: $this->type,
            widthConstraint: $this->widthConstraint,
            heightConstraint: $this->heightConstraint,
            showGrid: $this->showGrid,
            showValues: $this->showValues,
            showLabels: $this->showLabels,
            xAxisLabel: $this->xAxisLabel,
            yAxisLabel: $this->yAxisLabel,
            color: $this->color,
            gridColor: $color,
            labelColor: $this->labelColor,
        );
    }

    /**
     * Set the label color.
     */
    public function withLabelColor(?Color $color): self
    {
        return new self(
            dataPoints: $this->dataPoints,
            type: $this->type,
            widthConstraint: $this->widthConstraint,
            heightConstraint: $this->heightConstraint,
            showGrid: $this->showGrid,
            showValues: $this->showValues,
            showLabels: $this->showLabels,
            xAxisLabel: $this->xAxisLabel,
            yAxisLabel: $this->yAxisLabel,
            color: $this->color,
            gridColor: $this->gridColor,
            labelColor: $color,
        );
    }
}
