<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot;

use SugarCraft\Core\Util\Color;
use SugarCraft\Dash\Foundation\Buffer;
use SugarCraft\Dash\Foundation\Rect;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Dash\Foundation\Drawable;
use SugarCraft\Dash\Plot\Braille\BrailleCanvas;
use SugarCraft\Dash\Plot\Braille\BrailleMatrix;

/**
 * A line/scatter chart rendered using braille characters for 2x4 resolution.
 *
 * Supports two modes:
 * - LineChart: connected points with lines
 * - ScatterPlot: unconnected points only
 *
 * Default uses MarkerBraille for smooth curves.
 *
 * Mirrors termui widgets/plot.go
 */
final class Plot implements Sizer, Drawable
{
    public const MARKER_BRAILLE = 'braille';
    public const MARKER_DOT = 'dot';

    public const MODE_LINE = 'line';
    public const MODE_SCATTER = 'scatter';

    private string $mode = self::MODE_LINE;
    private string $marker = self::MARKER_BRAILLE;
    private bool $showAxes = true;
    private int $horizontalScale = 1;

    /** @var (float|null)[] */
    private array $data = [];
    private float $minValue = 0;
    private float $maxValue = 100;
    private int $width = 80;
    private int $height = 24;
    private ?Rect $rect = null;
    private ?Color $color = null;

    public function __construct(array $data = [], int $width = 80, int $height = 24)
    {
        $this->data = $data;
        $this->width = $width;
        $this->height = $height;
        $this->computeValueRange();
    }

    public static function new(array $data = [], int $width = 80, int $height = 24): self
    {
        return new self($data, $width, $height);
    }

    // ─── Mode & Marker ────────────────────────────────────────────

    public function withMode(string $mode): self
    {
        $clone = clone $this;
        $clone->mode = $mode;
        return $clone;
    }

    public function withMarker(string $marker): self
    {
        $clone = clone $this;
        $clone->marker = $marker;
        return $clone;
    }

    public function withShowAxes(bool $show): self
    {
        $clone = clone $this;
        $clone->showAxes = $show;
        return $clone;
    }

    public function withHorizontalScale(int $scale): self
    {
        $clone = clone $this;
        $clone->horizontalScale = max(1, $scale);
        return $clone;
    }

    public function withColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->color = $color;
        return $clone;
    }

    public function withData(array $data): self
    {
        $clone = clone $this;
        $clone->data = $data;
        $clone->computeValueRange();
        return $clone;
    }

    public function withMinValue(float $min): self
    {
        $clone = clone $this;
        $clone->minValue = $min;
        return $clone;
    }

    public function withMaxValue(float $max): self
    {
        $clone = clone $this;
        $clone->maxValue = $max;
        return $clone;
    }

    // ─── Sizer / Drawable ─────────────────────────────────────────

    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    public function getInnerSize(): array
    {
        return [$this->width, $this->height];
    }

    public function getRect(): Rect
    {
        return $this->rect ?? new Rect(0, 0, $this->width - 1, $this->height - 1);
    }

    public function setRect(Rect $rect): self
    {
        $clone = clone $this;
        $clone->rect = $rect;
        $clone->width = $rect->dx();
        $clone->height = $rect->dy();
        return $clone;
    }

    public function draw(Buffer $buffer): void
    {
        [$bufWidth, $bufHeight] = $buffer->getInnerSize();

        $innerWidth = $this->width - ($this->showAxes ? 2 : 0);
        $innerHeight = $this->height - ($this->showAxes ? 2 : 0);

        if ($innerWidth <= 0 || $innerHeight <= 0) {
            return;
        }

        $rect = $this->getRect();
        $globalStyle = $this->color !== null
            ? new \SugarCraft\Dash\Foundation\Style(foreground: $this->color)
            : new \SugarCraft\Dash\Foundation\Style();

        // Canvas origin offset within the buffer
        $originX = $rect->minX + ($this->showAxes ? 2 : 0);
        $originY = $rect->minY + ($this->showAxes ? 1 : 0);

        // Build canvas with same math as render()
        $dotWidth = $innerWidth * 2;
        $dotHeight = $innerHeight * 4;
        $canvas = BrailleCanvas::new($dotWidth, $dotHeight);

        $prevX = null;
        $prevY = null;

        foreach ($this->data as $i => $value) {
            if ($value === null) {
                $prevX = null;
                $prevY = null;
                continue;
            }

            $x = $i * $this->horizontalScale * 2;
            $rawY = intdiv((int)(($value - $this->minValue) / max(0.001, ($this->maxValue - $this->minValue) / max(1, $innerHeight - 1))), 1) * 4;
            $y = ($dotHeight - 1) - $rawY;
            $x = max(0, min($x, $dotWidth - 1));
            $y = max(0, min($y, $dotHeight - 1));

            if ($this->mode === self::MODE_LINE && $prevX !== null && $prevY !== null) {
                $canvas = $canvas->setLine($prevX, $prevY, $x, $y, $this->color);
            } else {
                $canvas = $canvas->setPoint($x, $y, $this->color);
            }

            $prevX = $x;
            $prevY = $y;
        }

        // Write braille cells directly into the buffer grid (in-place mutation, matching Buffer::draw() pattern)
        foreach ($canvas->cells() as [$cellX, $cellY, $bits, $cellColor]) {
            $targetX = $originX + $cellX;
            $targetY = $originY + $cellY;

            if ($targetX < 0 || $targetY < 0 || $targetX >= $bufWidth || $targetY >= $bufHeight) {
                continue;
            }

            $rune = BrailleMatrix::rune($bits);

            // Per-cell color overrides global color only when no global color is set
            $style = ($cellColor !== null && $this->color === null)
                ? $globalStyle->withForeground($cellColor)
                : $globalStyle;

            $buffer->grid[$targetY][$targetX] = new \SugarCraft\Dash\Foundation\Cell($rune, $style);
        }

        // Axes labels via setString (faster for text than per-cell setCell)
        // Only write axes when there is data (matching render() behavior)
        $dataCount = count(array_filter($this->data, fn($v) => $v !== null));
        if ($this->showAxes && $dataCount > 0) {
            $yLabels = $this->generateYLabels($innerHeight);

            // Y-axis labels (left column, 2 chars wide)
            for ($y = 0; $y < $innerHeight && $y < count($yLabels); $y++) {
                $posY = $rect->minY + 1 + $y;
                if ($posY > $rect->maxY) {
                    break;
                }
                $this->writeString($buffer, $rect->minX, $posY, str_pad($yLabels[$y], 2), $globalStyle, $bufWidth, $bufHeight);
            }

            // X-axis labels (bottom two rows)
            $xLabels = $this->generateXLabels($dataCount, $innerWidth);
            $xLabelLine1 = '  ' . ($xLabels[0] ?? '');
            $xLabelLine2 = '  ' . ($xLabels[1] ?? '');
            $this->writeString($buffer, $rect->minX, $rect->minY + 1 + $innerHeight, $xLabelLine1, $globalStyle, $bufWidth, $bufHeight);
            $this->writeString($buffer, $rect->minX, $rect->minY + 2 + $innerHeight, $xLabelLine2, $globalStyle, $bufWidth, $bufHeight);
        }
    }

    /**
     * Write a string directly into the buffer grid (in-place, matching Buffer::draw() pattern).
     */
    private function writeString(Buffer $buffer, int $x, int $y, string $str, \SugarCraft\Dash\Foundation\Style $style, int $bufWidth, int $bufHeight): void
    {
        if ($x < 0 || $y < 0 || $x >= $bufWidth || $y >= $bufHeight) {
            return;
        }

        foreach (mb_str_split($str) as $i => $char) {
            $posX = $x + $i;
            if ($posX >= $bufWidth) {
                break;
            }
            $buffer->grid[$y][$posX] = new \SugarCraft\Dash\Foundation\Cell($char, $style);
        }
    }

    // ─── Rendering ────────────────────────────────────────────────

    /**
     * Compute min/max value range from data.
     */
    private function computeValueRange(): void
    {
        $filtered = array_filter($this->data, fn($v) => $v !== null);
        if (empty($filtered)) {
            $this->minValue = 0;
            $this->maxValue = 100;
            return;
        }

        $this->minValue = min($filtered);
        $this->maxValue = max($filtered);

        // Ensure some range even if all values are identical
        if ($this->maxValue === $this->minValue) {
            $this->maxValue = $this->minValue + 1;
        }
    }

    /**
     * Format a label value.
     */
    private function formatLabel(float $value): string
    {
        if (abs($value) >= 1_000_000) {
            return sprintf('%.1fM', $value / 1_000_000);
        }
        if (abs($value) >= 1_000) {
            return sprintf('%.1fK', $value / 1_000);
        }
        if ($value === floor($value)) {
            return sprintf('%.0f', $value);
        }
        return sprintf('%.1f', $value);
    }

    /**
     * Render the plot as a string.
     */
    public function render(): string
    {
        $innerWidth = $this->width - ($this->showAxes ? 2 : 0);
        $innerHeight = $this->height - ($this->showAxes ? 2 : 0);

        if ($innerWidth <= 0 || $innerHeight <= 0) {
            return '';
        }

        $dataCount = count($this->data);
        if ($dataCount === 0) {
            return str_repeat("\n", $this->height - 1);
        }

        // verticalScale = maxVal / (innerH - 1)
        $verticalScale = ($this->maxValue - $this->minValue) / max(1, $innerHeight - 1);

        // Create BrailleCanvas with high-resolution pixel dimensions
        $dotWidth = $innerWidth * 2;
        $dotHeight = $innerHeight * 4;
        $canvas = BrailleCanvas::new($dotWidth, $dotHeight);

        $prevX = null;
        $prevY = null;

        // Plot each data point
        foreach ($this->data as $i => $value) {
            if ($value === null) {
                $prevX = null;
                $prevY = null;
                continue;
            }

            // x = i * horizontalScale * 2 (pixel coords)
            $x = $i * $this->horizontalScale * 2;

            // y = intval((value - minValue) / verticalScale) * 4 (scaled to 4 pixels per cell row)
            $rawY = intdiv((int) (($value - $this->minValue) / max(0.001, $verticalScale)), 1) * 4;

            // Invert: flip so max is at top
            $y = ($dotHeight - 1) - $rawY;

            // Clamp to canvas bounds
            $x = max(0, min($x, $dotWidth - 1));
            $y = max(0, min($y, $dotHeight - 1));

            if ($this->mode === self::MODE_LINE && $prevX !== null && $prevY !== null) {
                // Draw line from previous point
                $canvas = $canvas->setLine($prevX, $prevY, $x, $y, $this->color);
            } else {
                // Draw single dot
                $canvas = $canvas->setPoint($x, $y, $this->color);
            }

            $prevX = $x;
            $prevY = $y;
        }

        $output = $canvas->render();

        // If not showing axes, return just the canvas output
        if (!$this->showAxes) {
            return $output;
        }

        // Build axes labels
        $yLabels = $this->generateYLabels($innerHeight);
        $xLabels = $this->generateXLabels($dataCount, $innerWidth);

        // Combine canvas output with axes
        $canvasLines = explode("\n", $output);
        $resultLines = [];

        for ($y = 0; $y < $innerHeight; $y++) {
            $yLabel = $yLabels[$y] ?? '';
            $canvasLine = $canvasLines[$y] ?? '';
            $resultLines[] = str_pad($yLabel, 2) . $canvasLine;
        }

        // X-axis labels (bottom row + padding row)
        $xLabelLine = '  ' . ($xLabels[0] ?? '');
        $resultLines[] = $xLabelLine;
        $resultLines[] = '  ' . ($xLabels[1] ?? '');

        $output = implode("\n", $resultLines);

        // If color was applied, ensure reset code at the very end
        if ($this->color !== null) {
            $output .= "\x1b[0m";
        }

        return $output;
    }

    /**
     * Generate Y-axis labels.
     *
     * @return list<string>
     */
    private function generateYLabels(int $height): array
    {
        $labels = [];
        $range = $this->maxValue - $this->minValue;

        for ($i = 0; $i < $height; $i++) {
            // Labels from top to bottom (max to min)
            $value = $this->maxValue - (($i / max(1, $height - 1)) * $range);
            $labels[] = $this->formatLabel($value);
        }

        return $labels;
    }

    /**
     * Generate X-axis labels.
     *
     * @return list<string>
     */
    private function generateXLabels(int $dataCount, int $width): array
    {
        if ($dataCount === 0) {
            return ['', ''];
        }

        // Start and end indices
        $startLabel = '0';
        $endLabel = (string) ($dataCount - 1);

        return [$startLabel, $endLabel];
    }
}
