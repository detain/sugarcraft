<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Chart;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A bar-style sparkline chart component.
 *
 * Displays a series of values as vertical bars using Unicode block
 * characters. Unlike line sparklines, bars provide clear comparisons
 * between discrete values.
 *
 * Mirrors bar sparkline rendering from bubbletea/sparkline but adapted
 * to PHP with wither-style immutable setters.
 */
final class SparklineBar implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $sizerHeight = null;

    /** @var list<float> */
    private array $data;

    /**
     * Block characters for bar levels (8 levels).
     */
    private const BAR_BLOCKS = [
        '▁', // 0 - lowest (1/8)
        '▂', // 1 - (2/8)
        '▃', // 2 - (3/8)
        '▄', // 3 - (4/8)
        '▅', // 4 - (5/8)
        '▆', // 5 - (6/8)
        '▇', // 6 - (7/8)
        '█', // 7 - highest (8/8)
    ];

    public function __construct(
        array $data = [],
        private readonly ?int $widthConstraint = null,
        private readonly int $height = 8,
        private readonly bool $showValues = false,
        private readonly ?Color $color = null,
        private readonly ?Color $maxColor = null,
        private readonly ?Color $minColor = null,
        private readonly bool $showBarGaps = true,
        private readonly string $separator = ' ',
    ) {
        $this->data = $data;
    }

    /**
     * Create a new bar sparkline with default styling.
     *
     * @param list<float> $data Array of numeric values to display
     */
    public static function new(array $data = []): self
    {
        return new self(
            data: $data,
            widthConstraint: null,
            height: 8,
            showValues: false,
            color: Color::hex('#89B4FA'),
            maxColor: Color::hex('#A6E3A1'),
            minColor: Color::hex('#F38BA8'),
            showBarGaps: true,
            separator: ' ',
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
     * Render the bar sparkline as a string.
     */
    public function render(): string
    {
        $displayWidth = $this->getWidth();

        if ($displayWidth <= 0 || empty($this->data)) {
            return '';
        }

        // Normalize data to fit display width
        $normalizedData = $this->normalizeData($displayWidth);

        return $this->renderBars($normalizedData);
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
     * Render bars with multi-line output.
     *
     * @param list<float> $data Normalized data
     */
    private function renderBars(array $data): string
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

        $lines = [];

        // Build output line by line (top to bottom)
        for ($h = $this->height - 1; $h >= 0; $h--) {
            $line = '';
            $threshold = ($h + 1) / $this->height;

            foreach ($data as $index => $value) {
                $normalized = ($value - $min) / $range;

                if ($normalized >= $threshold) {
                    // Filled at this height
                    $color = $this->getColorForValue($value, $min, $max);
                    if ($color !== null) {
                        $line .= $color->toFg(ColorProfile::TrueColor);
                    }
                    $line .= '█';
                    if ($color !== null) {
                        $line .= Ansi::reset();
                    }
                } else {
                    $line .= ' ';
                }

                // Add separator between bars
                if ($this->showBarGaps && $index < count($data) - 1) {
                    $line .= $this->separator;
                }
            }

            $lines[] = $line;
        }

        // Add values row if enabled
        if ($this->showValues) {
            $valuesLine = '';
            foreach ($data as $index => $value) {
                $formatted = sprintf('%3d', (int) round($value));
                $color = $this->getColorForValue($value, $min, $max);
                if ($color !== null) {
                    $valuesLine .= $color->toFg(ColorProfile::TrueColor);
                }
                $valuesLine .= $formatted;
                if ($color !== null) {
                    $valuesLine .= Ansi::reset();
                }
                if ($this->showBarGaps && $index < count($data) - 1) {
                    $valuesLine .= $this->separator;
                }
            }
            $lines[] = $valuesLine;
        }

        return implode("\n", $lines);
    }

    /**
     * Get the appropriate color for a value based on min/max positions.
     */
    private function getColorForValue(float $value, float $min, float $max): ?Color
    {
        if ($min === $max) {
            return $this->color;
        }

        $normalized = ($value - $min) / ($max - $min);

        if ($normalized >= 0.9 && $this->maxColor !== null) {
            return $this->maxColor;
        }

        if ($normalized <= 0.1 && $this->minColor !== null) {
            return $this->minColor;
        }

        return $this->color;
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

        $totalWidth = $width;
        if ($this->showBarGaps && $width > 1) {
            $totalWidth = $width + ($width - 1) * mb_strlen($this->separator, 'UTF-8');
        }

        $height = $this->showValues ? $this->height + 1 : $this->height;

        return [$totalWidth, $height];
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
            showValues: $this->showValues,
            color: $this->color,
            maxColor: $this->maxColor,
            minColor: $this->minColor,
            showBarGaps: $this->showBarGaps,
            separator: $this->separator,
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
            showValues: $this->showValues,
            color: $this->color,
            maxColor: $this->maxColor,
            minColor: $this->minColor,
            showBarGaps: $this->showBarGaps,
            separator: $this->separator,
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
            showValues: $this->showValues,
            color: $this->color,
            maxColor: $this->maxColor,
            minColor: $this->minColor,
            showBarGaps: $this->showBarGaps,
            separator: $this->separator,
        );
    }

    /**
     * Show or hide numeric values below bars.
     */
    public function withShowValues(bool $show): self
    {
        return new self(
            data: $this->data,
            widthConstraint: $this->widthConstraint,
            height: $this->height,
            showValues: $show,
            color: $this->color,
            maxColor: $this->maxColor,
            minColor: $this->minColor,
            showBarGaps: $this->showBarGaps,
            separator: $this->separator,
        );
    }

    /**
     * Set the main color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            data: $this->data,
            widthConstraint: $this->widthConstraint,
            height: $this->height,
            showValues: $this->showValues,
            color: $color,
            maxColor: $this->maxColor,
            minColor: $this->minColor,
            showBarGaps: $this->showBarGaps,
            separator: $this->separator,
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
            showValues: $this->showValues,
            color: $this->color,
            maxColor: $color,
            minColor: $this->minColor,
            showBarGaps: $this->showBarGaps,
            separator: $this->separator,
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
            showValues: $this->showValues,
            color: $this->color,
            maxColor: $this->maxColor,
            minColor: $color,
            showBarGaps: $this->showBarGaps,
            separator: $this->separator,
        );
    }

    /**
     * Show or hide gaps between bars.
     */
    public function withBarGaps(bool $show): self
    {
        return new self(
            data: $this->data,
            widthConstraint: $this->widthConstraint,
            height: $this->height,
            showValues: $this->showValues,
            color: $this->color,
            maxColor: $this->maxColor,
            minColor: $this->minColor,
            showBarGaps: $show,
            separator: $this->separator,
        );
    }

    /**
     * Set the separator between bars.
     */
    public function withSeparator(string $separator): self
    {
        return new self(
            data: $this->data,
            widthConstraint: $this->widthConstraint,
            height: $this->height,
            showValues: $this->showValues,
            color: $this->color,
            maxColor: $this->maxColor,
            minColor: $this->minColor,
            showBarGaps: $this->showBarGaps,
            separator: $separator,
        );
    }
}