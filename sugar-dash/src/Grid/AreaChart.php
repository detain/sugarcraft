<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * An area chart component for displaying time series data.
 *
 * Features:
 * - Multiple stacked areas with different colors
 * - Configurable height and width
 * - Optional grid lines
 * - Y-axis labels
 * - Gradient or solid fill options
 *
 * Mirrors area chart patterns adapted to PHP with wither-style immutable setters.
 */
final class AreaChart implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<array{label: string, values: list<float>, color: Color|null}> $series
     */
    public function __construct(
        private readonly array $series,
        private readonly int $chartHeight = 10,
        private readonly int $chartWidth = 40,
        private readonly bool $showGrid = true,
        private readonly bool $showLabels = true,
        private readonly bool $showLegend = false,
        private readonly float $maxValue = 100.0,
        private readonly bool $stacked = false,
    ) {}

    /**
     * Create a new area chart.
     *
     * @param list<array{label: string, values: list<float>, color?: string|Color|null}> $series
     */
    public static function new(array $series): self
    {
        $colors = [
            Color::hex('#89B4FA'),
            Color::hex('#A6E3A1'),
            Color::hex('#F38BA8'),
            Color::hex('#F9E2AF'),
            Color::hex('#CBA6F7'),
        ];

        $normalizedSeries = array_map(function (array $item) use (&$colorIndex, $colors): array {
            $color = $item['color'] ?? null;
            if (is_string($color)) {
                $color = Color::hex($color);
            }
            if ($color === null) {
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
            series: $normalizedSeries,
            chartHeight: 10,
            chartWidth: 40,
            showGrid: true,
            showLabels: true,
            showLegend: false,
            maxValue: 100.0,
            stacked: false,
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
     * Calculate the natural dimensions of this area chart.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $useWidth = $this->width ?? $this->chartWidth + ($this->showLabels ? 10 : 0);
        $useHeight = $this->height ?? $this->chartHeight + ($this->showLegend ? 2 : 0);
        return [$useWidth, $useHeight];
    }

    /**
     * Render the area chart.
     */
    public function render(): string
    {
        if ($this->series === [] || count($this->series[0]['values'] ?? []) === 0) {
            return $this->renderEmpty();
        }

        $useWidth = $this->width ?? $this->chartWidth;
        $useHeight = $this->height ?? $this->chartHeight;

        // Normalize values
        $numPoints = count($this->series[0]['values']);
        $normalizedSeries = [];
        foreach ($this->series as $s) {
            $normalizedSeries[] = [
                'label' => $s['label'],
                'values' => array_map(fn($v) => min(1.0, max(0.0, $v / $this->maxValue)), $s['values']),
                'color' => $s['color'],
            ];
        }

        // Build grid
        $grid = [];
        for ($y = 0; $y < $useHeight; $y++) {
            $grid[$y] = array_fill(0, $useWidth, ['char' => ' ', 'color' => null]);
        }

        // Draw grid lines
        if ($this->showGrid) {
            $gridColor = Color::hex('#6C7086');
            for ($i = 1; $i < $this->chartHeight; $i++) {
                $y = $useHeight - 1 - $i;
                for ($x = 0; $x < $useWidth; $x++) {
                    $grid[$y][$x] = ['char' => '·', 'color' => $gridColor];
                }
            }
        }

        // Draw areas
        $xStep = $useWidth / max(1, $numPoints - 1);
        foreach ($normalizedSeries as $seriesIndex => $series) {
            $color = $series['color'];
            $values = $series['values'];

            for ($pointIndex = 0; $pointIndex < $numPoints; $pointIndex++) {
                $x = (int) round($pointIndex * $xStep);
                if ($x >= $useWidth) {
                    $x = $useWidth - 1;
                }

                $value = $values[$pointIndex];
                $yHeight = (int) round($value * $useHeight);

                for ($yOffset = 0; $yOffset < $yHeight; $yOffset++) {
                    $y = $useHeight - 1 - $yOffset;
                    if ($y >= 0 && $y < $useHeight) {
                        // For stacked charts, only draw if cell is empty or same series
                        if ($this->stacked || $grid[$y][$x]['char'] === ' ') {
                            $grid[$y][$x] = ['char' => '█', 'color' => $color];
                        }
                    }
                }
            }
        }

        // Convert grid to string
        $result = '';
        for ($y = 0; $y < $useHeight; $y++) {
            for ($x = 0; $x < $useWidth; $x++) {
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

        // Add legend
        if ($this->showLegend) {
            $legendParts = [];
            foreach ($this->series as $s) {
                $legendParts[] = $s['color']->toFg(ColorProfile::TrueColor) . '█ ' . $s['label'] . Ansi::reset();
            }
            $result .= implode('  ', $legendParts);
        }

        return rtrim($result, "\n");
    }

    /**
     * Render an empty chart.
     */
    private function renderEmpty(): string
    {
        $useWidth = $this->width ?? $this->chartWidth;
        $useHeight = $this->height ?? $this->chartHeight;

        $result = '';
        for ($y = 0; $y < $useHeight; $y++) {
            $result .= str_repeat(' ', $useWidth) . "\n";
        }
        return rtrim($result, "\n");
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the chart dimensions.
     */
    public function withDimensions(int $width, int $height): self
    {
        return new self(
            series: $this->series,
            chartHeight: $height,
            chartWidth: $width,
            showGrid: $this->showGrid,
            showLabels: $this->showLabels,
            showLegend: $this->showLegend,
            maxValue: $this->maxValue,
            stacked: $this->stacked,
        );
    }

    /**
     * Show or hide grid lines.
     */
    public function withShowGrid(bool $show): self
    {
        return new self(
            series: $this->series,
            chartHeight: $this->chartHeight,
            chartWidth: $this->chartWidth,
            showGrid: $show,
            showLabels: $this->showLabels,
            showLegend: $this->showLegend,
            maxValue: $this->maxValue,
            stacked: $this->stacked,
        );
    }

    /**
     * Show or hide legend.
     */
    public function withShowLegend(bool $show): self
    {
        return new self(
            series: $this->series,
            chartHeight: $this->chartHeight,
            chartWidth: $this->chartWidth,
            showGrid: $this->showGrid,
            showLabels: $this->showLabels,
            showLegend: $show,
            maxValue: $this->maxValue,
            stacked: $this->stacked,
        );
    }

    /**
     * Set the maximum value.
     */
    public function withMaxValue(float $max): self
    {
        return new self(
            series: $this->series,
            chartHeight: $this->chartHeight,
            chartWidth: $this->chartWidth,
            showGrid: $this->showGrid,
            showLabels: $this->showLabels,
            showLegend: $this->showLegend,
            maxValue: $max,
            stacked: $this->stacked,
        );
    }

    /**
     * Enable stacked mode.
     */
    public function withStacked(bool $stacked): self
    {
        return new self(
            series: $this->series,
            chartHeight: $this->chartHeight,
            chartWidth: $this->chartWidth,
            showGrid: $this->showGrid,
            showLabels: $this->showLabels,
            showLegend: $this->showLegend,
            maxValue: $this->maxValue,
            stacked: $stacked,
        );
    }
}
