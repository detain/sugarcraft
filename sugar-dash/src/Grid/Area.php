<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * Data point for area charts.
 */
final readonly class AreaPoint
{
    public function __construct(
        public string $label,
        public float $value,
        public ?float $y0 = null,  // Baseline (default 0)
    ) {}
}

/**
 * An area chart component with stacked layers.
 *
 * Displays data as filled areas between lines. Supports:
 * - Single area or stacked multiple areas
 * - Custom colors for each area layer
 * - Gradient fills
 * - Y-axis baselines per layer
 *
 * Mirrors area chart patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class Area implements Sizer
{
    private ?int $width = null;
    private ?int $sizerHeight = null;

    /** @var list<AreaPoint> */
    private array $dataPoints = [];

    /** @var list<Color> */
    private array $layerColors = [];

    private int $heightConstraint = 12;
    private bool $showGrid = true;
    private bool $showLabels = true;
    private bool $showValues = false;
    private bool $stacked = false;
    private bool $showLegend = false;

    /**
     * Block characters for area fill (density levels).
     */
    private const FILL_BLOCKS = ['░', '▒', '▓', '█'];

    public function __construct(
        private readonly ?Color $color = null,
        private readonly ?Color $fillColor = null,
        private readonly ?Color $gridColor = null,
        private readonly ?Color $labelColor = null,
    ) {}

    /**
     * Create a new area chart with default styling.
     *
     * @param list<AreaPoint> $dataPoints
     */
    public static function new(array $dataPoints = []): self
    {
        return new self(
            color: Color::hex('#89B4FA'),
            fillColor: Color::hex('#45475A'),
            gridColor: Color::hex('#45475A'),
            labelColor: Color::hex('#6C7086'),
        )->withDataPoints($dataPoints);
    }

    /**
     * Create a sample area chart for demonstration.
     */
    public static function sample(int $points = 10): self
    {
        $dataPoints = [];
        $base = 0;

        for ($i = 0; $i < $points; $i++) {
            $value = $base + random_int(5, 25);
            $dataPoints[] = new AreaPoint(
                label: 'P' . ($i + 1),
                value: $value,
            );
            $base = $value;
        }

        return self::new($dataPoints);
    }

    /**
     * Set the allocated dimensions for this area chart.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->sizerHeight = $height;
        return $clone;
    }

    /**
     * Set the data points.
     *
     * @param list<AreaPoint> $dataPoints
     */
    public function withDataPoints(array $dataPoints): self
    {
        $clone = clone $this;
        $clone->dataPoints = $dataPoints;
        return $clone;
    }

    /**
     * Add a data point.
     */
    public function withPoint(AreaPoint $point): self
    {
        $clone = clone $this;
        $clone->dataPoints[] = $point;
        return $clone;
    }

    /**
     * Add a data point by parameters.
     */
    public function addPoint(string $label, float $value, ?float $y0 = null): self
    {
        return $this->withPoint(new AreaPoint($label, $value, $y0));
    }

    /**
     * Set layer colors for stacked charts.
     *
     * @param list<Color> $colors
     */
    public function withLayerColors(array $colors): self
    {
        $clone = clone $this;
        $clone->layerColors = $colors;
        return $clone;
    }

    /**
     * Show or hide grid lines.
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
     * Show or hide values.
     */
    public function withShowValues(bool $show): self
    {
        $clone = clone $this;
        $clone->showValues = $show;
        return $clone;
    }

    /**
     * Enable or disable stacked mode.
     */
    public function withStacked(bool $stacked): self
    {
        $clone = clone $this;
        $clone->stacked = $stacked;
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
     * Render the area chart as a string.
     */
    public function render(): string
    {
        $chartWidth = $this->getChartWidth();
        $chartHeight = $this->getChartHeight();

        if ($chartWidth <= 0 || $chartHeight <= 0 || empty($this->dataPoints)) {
            return '';
        }

        $values = array_map(fn(AreaPoint $p) => $p->value, $this->dataPoints);
        $minValue = 0;
        $maxValue = max($values);

        if ($this->stacked) {
            $maxValue = array_sum($values);
        }

        if ($maxValue === $minValue) {
            $maxValue = $minValue + 1;
        }

        $gridColor = $this->gridColor ?? Color::hex('#45475A');
        $labelColor = $this->labelColor ?? Color::hex('#6C7086');

        // Generate grid lines
        $gridLines = $this->generateGridLines($minValue, $maxValue, $chartHeight);

        // Calculate points
        $dataCount = count($this->dataPoints);
        $plotWidth = $chartWidth - 1;

        $output = '';

        // Build the chart line by line (top to bottom)
        for ($row = $chartHeight - 1; $row >= 0; $row--) {
            $yValue = $gridLines[$row];
            $line = '';

            // Y-axis label
            if ($this->showGrid) {
                $yLabel = $this->formatValue($yValue);
                $line .= str_pad($yLabel, 7) . ' ';
            }

            // Draw the area fill for this row
            for ($col = 0; $col < $dataCount; $col++) {
                $point = $this->dataPoints[$col];
                $baseline = $point->y0 ?? 0;
                $value = $point->value;

                if ($this->stacked && $col > 0) {
                    // Add previous values for stacked mode
                    for ($prev = 0; $prev < $col; $prev++) {
                        $value += $this->dataPoints[$prev]->value;
                    }
                }

                // Calculate row threshold
                $valueHeight = intval((($value - $baseline) / ($maxValue - $minValue)) * $chartHeight);
                $baselineHeight = intval((($baseline - $minValue) / ($maxValue - $minValue)) * $chartHeight);

                $threshold = $row + 1;

                // Determine fill density
                if ($valueHeight >= $threshold && $baselineHeight < $threshold) {
                    $density = min(3, intval(($valueHeight - $threshold + 1) / max(1, $valueHeight - $baselineHeight) * 4));
                    $fillChar = self::FILL_BLOCKS[$density];

                    $color = $this->color;
                    if (!empty($this->layerColors)) {
                        $color = $this->layerColors[$col % count($this->layerColors)];
                    }

                    if ($color !== null) {
                        $line .= $color->toFg(ColorProfile::TrueColor);
                    }
                    $line .= $fillChar;
                    if ($color !== null) {
                        $line .= Ansi::reset();
                    }
                } else {
                    $line .= ' ';
                }
            }

            // Grid line
            if ($this->showGrid) {
                if ($gridColor !== null) {
                    $line .= $gridColor->toFg(ColorProfile::TrueColor);
                }
                $line .= '│';
                if ($gridColor !== null) {
                    $line .= Ansi::reset();
                }
            }

            $output .= $line . "\n";
        }

        // X-axis line
        if ($this->showGrid) {
            $line = str_repeat(' ', 7);
            $line .= str_repeat('─', $dataCount);
            if ($gridColor !== null) {
                $line = $gridColor->toFg(ColorProfile::TrueColor) . $line . Ansi::reset();
            }
            $output .= $line . "\n";
        }

        // Labels
        if ($this->showLabels) {
            $line = str_repeat(' ', 7);
            foreach ($this->dataPoints as $point) {
                $label = mb_substr($point->label, 0, 1, 'UTF-8');
                $line .= $label;
            }
            if ($labelColor !== null) {
                $line = $labelColor->toFg(ColorProfile::TrueColor) . $line . Ansi::reset();
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
     * Get the chart width.
     */
    private function getChartWidth(): int
    {
        if ($this->width !== null && $this->width > 0) {
            return max(0, $this->width - 10);
        }
        return max(1, count($this->dataPoints));
    }

    /**
     * Get the chart height.
     */
    private function getChartHeight(): int
    {
        return $this->sizerHeight ?? $this->heightConstraint;
    }

    /**
     * Format a Y-axis label.
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
     * Calculate the natural dimensions of this area chart.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->getChartWidth() + 10;
        $height = $this->getChartHeight() + 2;

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the line color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            color: $color,
            fillColor: $this->fillColor,
            gridColor: $this->gridColor,
            labelColor: $this->labelColor,
        );
    }

    /**
     * Set the fill color.
     */
    public function withFillColor(?Color $color): self
    {
        return new self(
            color: $this->color,
            fillColor: $color,
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
            color: $this->color,
            fillColor: $this->fillColor,
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
            color: $this->color,
            fillColor: $this->fillColor,
            gridColor: $this->gridColor,
            labelColor: $color,
        );
    }

    /**
     * Set the height constraint.
     */
    public function withHeight(int $height): self
    {
        $clone = clone $this;
        $clone->heightConstraint = max(4, $height);
        return $clone;
    }
}
