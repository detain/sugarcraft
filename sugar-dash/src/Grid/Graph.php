<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * Graph types.
 */
enum GraphType: string
{
    case Line = 'line';
    case Bar = 'bar';
    case Area = 'area';
    case Scatter = 'scatter';
}

/**
 * Graph axis labels position.
 */
enum AxisPosition: string
{
    case Left = 'left';
    case Right = 'right';
    case Both = 'both';
}

/**
 * A graph/chart component for displaying data visualizations.
 *
 * Features:
 * - Multiple graph types (line, bar, area, scatter)
 * - Customizable colors
 * - Axis labels and grid
 * - Legend support
 * - Data point highlighting
 *
 * Mirrors chart visualization patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class Graph implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /** @var list<float> */
    private array $data = [];

    /** @var list<string> */
    private array $labels = [];

    /** @var list<array{label:string,data:list<float>,color:?Color}> */
    private array $series = [];

    private ?float $minValue = null;
    private ?float $maxValue = null;
    private bool $showGrid = true;
    private bool $showLegend = true;
    private bool $showAxes = true;

    public function __construct(
        private readonly ?int $maxDataPoints = null,
        private readonly GraphType $graphType = GraphType::Line,
        private readonly ?Color $lineColor = null,
        private readonly ?Color $gridColor = null,
        private readonly ?Color $textColor = null,
        private readonly ?Color $backgroundColor = null,
        private readonly string $style = 'rounded',
    ) {}

    /**
     * Create a new graph with default styling.
     */
    public static function new(): self
    {
        return new self(
            maxDataPoints: null,
            graphType: GraphType::Line,
            lineColor: Color::hex('#89B4FA'),
            gridColor: Color::hex('#45475A'),
            textColor: Color::hex('#CDD6F4'),
            backgroundColor: Color::hex('#1E1E2E'),
            style: 'rounded',
        );
    }

    /**
     * Set the allocated dimensions for this graph.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Set the data for a single series.
     *
     * @param list<float> $data
     */
    public function withData(array $data): self
    {
        $clone = clone $this;
        $clone->data = $data;
        $clone->recalculateMinMax();
        return $clone;
    }

    /**
     * Add a data series.
     *
     * @param list<float> $data
     */
    public function withSeries(string $label, array $data, ?Color $color = null): self
    {
        $clone = clone $this;
        $clone->series[] = [
            'label' => $label,
            'data' => $data,
            'color' => $color,
        ];
        $clone->recalculateMinMax();
        return $clone;
    }

    /**
     * Set axis labels.
     *
     * @param list<string> $labels
     */
    public function withLabels(array $labels): self
    {
        $clone = clone $this;
        $clone->labels = $labels;
        return $clone;
    }

    /**
     * Set fixed min/max values.
     */
    public function withValueRange(float $min, float $max): self
    {
        $clone = clone $this;
        $clone->minValue = $min;
        $clone->maxValue = $max;
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
     * Show or hide legend.
     */
    public function withShowLegend(bool $show): self
    {
        $clone = clone $this;
        $clone->showLegend = $show;
        return $clone;
    }

    /**
     * Show or hide axes.
     */
    public function withShowAxes(bool $show): self
    {
        $clone = clone $this;
        $clone->showAxes = $show;
        return $clone;
    }

    /**
     * Recalculate min/max values from data.
     */
    private function recalculateMinMax(): void
    {
        $allData = $this->data;
        foreach ($this->series as $s) {
            $allData = array_merge($allData, $s['data']);
        }

        if ($allData === []) {
            return;
        }

        $this->minValue = min($allData);
        $this->maxValue = max($allData);

        // Add padding to max
        $range = $this->maxValue - $this->minValue;
        if ($range > 0) {
            $this->maxValue = $this->maxValue + $range * 0.1;
        }
    }

    /**
     * Render the graph as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 60;
        $useHeight = $this->height ?? 20;

        if ($useWidth < 20 || $useHeight < 5) {
            return '';
        }

        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        $gridColor = $this->gridColor ?? Color::hex('#45475A');
        $textColor = $this->textColor ?? Color::hex('#CDD6F4');
        $lineColor = $this->lineColor ?? Color::hex('#89B4FA');

        $result = '';

        // Legend (if enabled)
        if ($this->showLegend && $this->series !== []) {
            $result .= $this->renderLegend($useWidth);
        }

        // Chart area
        $chartHeight = $useHeight - 2; // Account for borders
        $chartWidth = $useWidth - 2;

        $result .= $tl . str_repeat($h, $chartWidth) . $tr . "\n";

        // Build chart content
        $chartContent = $this->buildChartContent($chartWidth, $chartHeight);
        foreach ($chartContent as $line) {
            $result .= $v . $line . $v . "\n";
        }

        // Bottom border
        $result .= $bl . str_repeat($h, $chartWidth) . $br;

        // X-axis labels
        if ($this->labels !== []) {
            $result .= $this->renderXLabels($chartWidth);
        }

        return $result;
    }

    /**
     * Render the legend.
     */
    private function renderLegend(int $width): string
    {
        $result = '';
        foreach ($this->series as $s) {
            $color = $s['color'] ?? $this->lineColor ?? Color::hex('#89B4FA');
            $label = $s['label'];
            $entry = "◆ {$label} ";
            if (strlen($result) + strlen($entry) <= $width) {
                $result .= $entry;
            }
        }
        return mb_substr($result, 0, $width) . "\n";
    }

    /**
     * Build the chart content lines.
     *
     * @return list<string>
     */
    private function buildChartContent(int $width, int $height): array
    {
        $data = $this->data !== [] ? $this->data : [0];
        $minVal = $this->minValue ?? 0;
        $maxVal = $this->maxValue ?? 100;
        $range = $maxVal - $minVal;

        $lines = [];

        // Grid lines
        $gridCount = min($height - 1, 5);
        for ($i = 0; $i < $gridCount; $i++) {
            $y = intval($i * ($height - 1) / max(1, $gridCount - 1));
            $lineIndex = $height - 2 - $y;

            if ($lineIndex >= 0 && $lineIndex < count($lines)) {
                continue;
            }

            while (count($lines) <= $lineIndex) {
                array_unshift($lines, str_repeat(' ', $width));
            }

            if ($this->showGrid) {
                $gridLine = str_repeat('·', $width);
                $lines[$lineIndex] = $gridLine;
            }
        }

        // Ensure we have enough lines
        while (count($lines) < $height - 1) {
            array_unshift($lines, str_repeat(' ', $width));
        }

        // Plot data
        $plotWidth = $width - 10; // Leave space for Y-axis labels
        $plotArea = mb_substr($lines[count($lines) - 1] ?? '', $plotWidth) ?? '';

        foreach ($data as $index => $value) {
            if ($range == 0) {
                $normalizedY = 0.5;
            } else {
                $normalizedY = ($value - $minVal) / $range;
            }

            $yPos = intval(($height - 2) * (1 - $normalizedY));
            $yPos = max(0, min($height - 2, $yPos));

            $xPos = intval($index * $plotWidth / max(1, count($data) - 1));
            $xPos = max(0, min($plotWidth - 1, $xPos));

            $lineIndex = $height - 2 - $yPos;
            if ($lineIndex >= 0 && $lineIndex < count($lines)) {
                $line = $lines[$lineIndex];
                $before = mb_substr($line, 0, $xPos);
                $after = mb_substr($line, $xPos + 1);
                $lines[$lineIndex] = $before . '●' . $after;
            }
        }

        // Add Y-axis labels
        foreach ($lines as $index => $line) {
            $yValue = $maxVal - (($maxVal - $minVal) * $index / max(1, count($lines) - 1));
            $label = sprintf('%5.1f', $yValue);
            $lines[$index] = $label . ' ' . mb_substr($line, 6);
        }

        return $lines;
    }

    /**
     * Render X-axis labels.
     */
    private function renderXLabels(int $width): string
    {
        $result = '';
        $labelWidth = intval($width / max(1, count($this->labels)));

        foreach ($this->labels as $index => $label) {
            $shortLabel = mb_substr($label, 0, $labelWidth - 1);
            $result .= str_pad($shortLabel, $labelWidth);
        }

        return mb_substr($result, 0, $width);
    }

    /**
     * Get the style characters for the border.
     *
     * @return array{0:string, 1:string, 2:string, 3:string, 4:string, 5:string}
     */
    private function getStyleChars(): array
    {
        return match ($this->style) {
            'double' => ['╔', '╗', '╚', '╝', '═', '║'],
            'rounded' => ['╭', '╮', '╰', '╯', '─', '│'],
            'single' => ['┌', '┐', '└', '┘', '─', '│'],
            'bold' => ['┏', '┓', '┗', '┛', '━', '┃'],
            'empty' => [' ', ' ', ' ', ' ', ' ', ' '],
            default => ['╭', '╮', '╰', '╯', '─', '│'],
        };
    }

    /**
     * Calculate the natural dimensions of this graph.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->width ?? 60;
        $height = $this->height ?? 20;

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the graph type.
     */
    public function withGraphType(GraphType $type): self
    {
        return new self(
            maxDataPoints: $this->maxDataPoints,
            graphType: $type,
            lineColor: $this->lineColor,
            gridColor: $this->gridColor,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
            style: $this->style,
        );
    }

    /**
     * Set the line color.
     */
    public function withLineColor(?Color $color): self
    {
        return new self(
            maxDataPoints: $this->maxDataPoints,
            graphType: $this->graphType,
            lineColor: $color,
            gridColor: $this->gridColor,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
            style: $this->style,
        );
    }

    /**
     * Set the grid color.
     */
    public function withGridColor(?Color $color): self
    {
        return new self(
            maxDataPoints: $this->maxDataPoints,
            graphType: $this->graphType,
            lineColor: $this->lineColor,
            gridColor: $color,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
            style: $this->style,
        );
    }

    /**
     * Set the text color.
     */
    public function withTextColor(?Color $color): self
    {
        return new self(
            maxDataPoints: $this->maxDataPoints,
            graphType: $this->graphType,
            lineColor: $this->lineColor,
            gridColor: $this->gridColor,
            textColor: $color,
            backgroundColor: $this->backgroundColor,
            style: $this->style,
        );
    }

    /**
     * Set the background color.
     */
    public function withBackgroundColor(?Color $color): self
    {
        return new self(
            maxDataPoints: $this->maxDataPoints,
            graphType: $this->graphType,
            lineColor: $this->lineColor,
            gridColor: $this->gridColor,
            textColor: $this->textColor,
            backgroundColor: $color,
            style: $this->style,
        );
    }

    /**
     * Set the border style.
     */
    public function withStyle(string $style): self
    {
        return new self(
            maxDataPoints: $this->maxDataPoints,
            graphType: $this->graphType,
            lineColor: $this->lineColor,
            gridColor: $this->gridColor,
            textColor: $this->textColor,
            backgroundColor: $this->backgroundColor,
            style: $style,
        );
    }
}
