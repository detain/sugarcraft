<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Chart;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * An area-style sparkline chart component.
 *
 * Displays a series of values as a filled area chart using Unicode
 * block characters. The area below the line is filled with a gradient
 * or solid color for visual emphasis.
 *
 * Mirrors area sparkline rendering from bubbletea/sparkline but adapted
 * to PHP with wither-style immutable setters.
 */
final class SparklineArea implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $sizerHeight = null;

    /** @var list<float> */
    private array $data;

    /**
     * Block characters for drawing the area.
     */
    private const AREA_BLOCKS = [
        '▁', // 0 - lowest
        '▂', // 1
        '▃', // 2
        '▄', // 3
        '▅', // 4
        '▆', // 5
        '▇', // 6
        '█', // 7 - highest
    ];

    /**
     * Fill characters for area fill (partial blocks).
     */
    private const FILL_BLOCKS = [
        '░', // Light shade
        '▒', // Medium shade
        '▓', // Dark shade
    ];

    public function __construct(
        array $data = [],
        private readonly ?int $widthConstraint = null,
        private readonly int $height = 3,
        private readonly bool $showLine = true,
        private readonly bool $showFill = true,
        private readonly ?Color $lineColor = null,
        private readonly ?Color $fillColor = null,
        private readonly ?Color $maxColor = null,
        private readonly ?Color $minColor = null,
    ) {
        $this->data = $data;
    }

    /**
     * Create a new area sparkline with default styling.
     *
     * @param list<float> $data Array of numeric values to display
     */
    public static function new(array $data = []): self
    {
        return new self(
            data: $data,
            widthConstraint: null,
            height: 3,
            showLine: true,
            showFill: true,
            lineColor: Color::hex('#89B4FA'),
            fillColor: Color::hex('#89B4FA')->darken(0.5),
            maxColor: Color::hex('#A6E3A1'),
            minColor: Color::hex('#F38BA8'),
        );
    }

    /**
     * Set the allocated dimensions for this sparkline.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->sizerHeight = $height;
        return $clone;
    }

    /**
     * Render the area sparkline as a string.
     */
    public function render(): string
    {
        $displayWidth = $this->getWidth();

        if ($displayWidth <= 0 || empty($this->data)) {
            return '';
        }

        // Normalize data to fit display width
        $normalizedData = $this->normalizeData($displayWidth);

        return $this->renderArea($normalizedData);
    }

    /**
     * Normalize data points to fit the display width.
     *
     * @return list<float>
     */
    private function normalizeData(int $width): array
    {
        $dataCount = count($this->data);

        if ($dataCount === 0) {
            return [];
        }

        if ($dataCount === $width) {
            return $this->data;
        }

        if ($dataCount < $width) {
            // Upscale: interpolate between points
            $result = [];
            for ($i = 0; $i < $width; $i++) {
                $pos = ($i / ($width - 1)) * ($dataCount - 1);
                $index = (int) floor($pos);
                $fraction = $pos - $index;

                if ($index >= $dataCount - 1) {
                    $result[] = $this->data[$dataCount - 1];
                } else {
                    $v1 = $this->data[$index];
                    $v2 = $this->data[$index + 1];
                    $result[] = $v1 + ($v2 - $v1) * $fraction;
                }
            }
            return $result;
        }

        // Downscale: sample at regular intervals
        $result = [];
        $step = $dataCount / $width;
        for ($i = 0; $i < $width; $i++) {
            $index = (int) floor($i * $step);
            if ($index >= $dataCount) {
                $index = $dataCount - 1;
            }
            $result[] = $this->data[$index];
        }
        return $result;
    }

    /**
     * Render area chart with multi-line output.
     *
     * @param list<float> $data Normalized data
     */
    private function renderArea(array $data): string
    {
        if (empty($data)) {
            return '';
        }

        $min = min($data);
        $max = max($data);
        $range = $max - $min;
        $range = abs($range);
        if ($range == 0.0) {
            $range = 1.0;
        }

        // Calculate the baseline (bottom of the area)
        $baseline = $min - ($range * 0.1); // Extend slightly below min

        $lines = [];

        // Build output line by line (top to bottom)
        for ($h = $this->height - 1; $h >= 0; $h--) {
            $line = '';
            $threshold = ($h + 1) / $this->height;

            foreach ($data as $i => $value) {
                $normalized = ($value - $min) / $range;
                $color = $this->getColorForValue($value, $min, $max);

                if ($normalized >= $threshold) {
                    // The area is filled at this height - draw full block
                    if ($this->showLine && $normalized >= ($threshold - (1 / $this->height)) && $normalized < ($threshold + (1 / $this->height))) {
                        // This is near the top edge - draw line color
                        if ($color !== null && $this->lineColor !== null) {
                            $line .= $this->lineColor->toFg(ColorProfile::TrueColor);
                        }
                        $line .= self::AREA_BLOCKS[(int) (min(7, $normalized * 8))];
                        if ($color !== null && $this->lineColor !== null) {
                            $line .= Ansi::reset();
                        }
                    } else {
                        // Fill area
                        if ($color !== null && $this->fillColor !== null) {
                            $line .= $this->fillColor->toFg(ColorProfile::TrueColor);
                        }
                        $line .= $this->getFillChar($normalized, $threshold);
                        if ($color !== null && $this->fillColor !== null) {
                            $line .= Ansi::reset();
                        }
                    }
                } elseif ($this->showFill && $normalized >= ($threshold - (1 / $this->height))) {
                    // Partial fill for smoother edges
                    if ($color !== null && $this->fillColor !== null) {
                        $line .= $this->fillColor->toFg(ColorProfile::TrueColor);
                    }
                    $line .= self::FILL_BLOCKS[0];
                    if ($color !== null && $this->fillColor !== null) {
                        $line .= Ansi::reset();
                    }
                } else {
                    $line .= ' ';
                }
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Get fill character based on normalized value.
     */
    private function getFillChar(float $normalized, float $threshold): string
    {
        // Use progressively darker fill blocks
        $fillLevel = $normalized * $this->height;
        if ($fillLevel >= 3) {
            return '█';
        } elseif ($fillLevel >= 2) {
            return '▓';
        } elseif ($fillLevel >= 1) {
            return '▒';
        }
        return '░';
    }

    /**
     * Get the appropriate color for a value based on min/max positions.
     */
    private function getColorForValue(float $value, float $min, float $max): ?Color
    {
        if ($min === $max) {
            return $this->lineColor;
        }

        $normalized = ($value - $min) / ($max - $min);

        if ($normalized >= 0.9 && $this->maxColor !== null) {
            return $this->maxColor;
        }

        if ($normalized <= 0.1 && $this->minColor !== null) {
            return $this->minColor;
        }

        return $this->lineColor;
    }

    /**
     * Get the width to use for the sparkline.
     */
    private function getWidth(): int
    {
        if ($this->width !== null && $this->width > 0) {
            return $this->width;
        }
        return $this->widthConstraint ?? count($this->data);
    }

    /**
     * Calculate the natural dimensions of this sparkline.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->getWidth();

        if ($width <= 0 || empty($this->data)) {
            return [0, $this->height];
        }

        return [$width, $this->height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the data points.
     *
     * @param list<float> $data
     */
    public function withData(array $data): self
    {
        return new self(
            data: $data,
            widthConstraint: $this->widthConstraint,
            height: $this->height,
            showLine: $this->showLine,
            showFill: $this->showFill,
            lineColor: $this->lineColor,
            fillColor: $this->fillColor,
            maxColor: $this->maxColor,
            minColor: $this->minColor,
        );
    }

    /**
     * Set the width constraint.
     */
    public function withWidth(int $width): self
    {
        return new self(
            data: $this->data,
            widthConstraint: $width,
            height: $this->height,
            showLine: $this->showLine,
            showFill: $this->showFill,
            lineColor: $this->lineColor,
            fillColor: $this->fillColor,
            maxColor: $this->maxColor,
            minColor: $this->minColor,
        );
    }

    /**
     * Set the height.
     */
    public function withHeight(int $height): self
    {
        return new self(
            data: $this->data,
            widthConstraint: $this->widthConstraint,
            height: max(1, $height),
            showLine: $this->showLine,
            showFill: $this->showFill,
            lineColor: $this->lineColor,
            fillColor: $this->fillColor,
            maxColor: $this->maxColor,
            minColor: $this->minColor,
        );
    }

    /**
     * Show or hide the line.
     */
    public function withShowLine(bool $show): self
    {
        return new self(
            data: $this->data,
            widthConstraint: $this->widthConstraint,
            height: $this->height,
            showLine: $show,
            showFill: $this->showFill,
            lineColor: $this->lineColor,
            fillColor: $this->fillColor,
            maxColor: $this->maxColor,
            minColor: $this->minColor,
        );
    }

    /**
     * Show or hide the fill.
     */
    public function withShowFill(bool $show): self
    {
        return new self(
            data: $this->data,
            widthConstraint: $this->widthConstraint,
            height: $this->height,
            showLine: $this->showLine,
            showFill: $show,
            lineColor: $this->lineColor,
            fillColor: $this->fillColor,
            maxColor: $this->maxColor,
            minColor: $this->minColor,
        );
    }

    /**
     * Set the line color.
     */
    public function withLineColor(?Color $color): self
    {
        return new self(
            data: $this->data,
            widthConstraint: $this->widthConstraint,
            height: $this->height,
            showLine: $this->showLine,
            showFill: $this->showFill,
            lineColor: $color,
            fillColor: $this->fillColor,
            maxColor: $this->maxColor,
            minColor: $this->minColor,
        );
    }

    /**
     * Set the fill color.
     */
    public function withFillColor(?Color $color): self
    {
        return new self(
            data: $this->data,
            widthConstraint: $this->widthConstraint,
            height: $this->height,
            showLine: $this->showLine,
            showFill: $this->showFill,
            lineColor: $this->lineColor,
            fillColor: $color,
            maxColor: $this->maxColor,
            minColor: $this->minColor,
        );
    }

    /**
     * Set the maximum value color.
     */
    public function withMaxColor(?Color $color): self
    {
        return new self(
            data: $this->data,
            widthConstraint: $this->widthConstraint,
            height: $this->height,
            showLine: $this->showLine,
            showFill: $this->showFill,
            lineColor: $this->lineColor,
            fillColor: $this->fillColor,
            maxColor: $color,
            minColor: $this->minColor,
        );
    }

    /**
     * Set the minimum value color.
     */
    public function withMinColor(?Color $color): self
    {
        return new self(
            data: $this->data,
            widthConstraint: $this->widthConstraint,
            height: $this->height,
            showLine: $this->showLine,
            showFill: $this->showFill,
            lineColor: $this->lineColor,
            fillColor: $this->fillColor,
            maxColor: $this->maxColor,
            minColor: $color,
        );
    }
}