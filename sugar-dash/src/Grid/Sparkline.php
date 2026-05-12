<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * An inline sparkline chart component.
 *
 * Displays a compact trend visualization using Unicode block characters
 * to represent data points. Supports custom height, data point markers,
 * minimum/maximum colors, and area fill under the line.
 *
 * Mirrors sparkline rendering from bubbletea/sparkline but adapted
 * to PHP with wither-style immutable setters.
 */
final class Sparkline implements Sizer
{
    private ?int $width = null;
    private ?int $sizerHeight = null;

    /** @var list<float> */
    private array $data;

    /**
     * Unicode block characters for drawing sparklines.
     * Each represents a different vertical position (height).
     */
    private const BLOCK_CHARS = [
        '▁', // 0 - lowest
        '▂', // 1
        '▃', // 2
        '▄', // 3
        '▅', // 4
        '▆', // 5
        '▇', // 6
        '█', // 7 - highest
    ];

    public function __construct(
        array $data = [],
        private readonly ?int $widthConstraint = null,
        private readonly int $height = 1,
        private readonly bool $showDataPoints = false,
        private readonly bool $fill = false,
        private readonly ?Color $color = null,
        private readonly ?Color $maxColor = null,
        private readonly ?Color $minColor = null,
        private readonly ?Color $fillColor = null,
    ) {
        $this->data = $data;
    }

    /**
     * Create a new sparkline with default styling.
     *
     * @param list<float> $data Array of numeric values to display
     */
    public static function new(array $data = []): self
    {
        return new self(
            data: $data,
            widthConstraint: null,
            height: 1,
            showDataPoints: false,
            fill: false,
            color: Color::hex('#89B4FA'),
            maxColor: Color::hex('#A6E3A1'),
            minColor: Color::hex('#F38BA8'),
            fillColor: null,
        );
    }

    /**
     * Set the allocated dimensions for this sparkline.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->sizerHeight = $height;
        return $clone;
    }

    /**
     * Render the sparkline as a string.
     */
    public function render(): string
    {
        $displayWidth = $this->getWidth();

        if ($displayWidth <= 0 || empty($this->data)) {
            return '';
        }

        // Normalize data to fit display width
        $normalizedData = $this->normalizeData($displayWidth);

        if ($this->height === 1) {
            return $this->renderSingleLine($normalizedData);
        }

        return $this->renderMultiLine($normalizedData);
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
     * Render a single-line sparkline (height = 1).
     *
     * @param list<float> $data Normalized data
     */
    private function renderSingleLine(array $data): string
    {
        if (empty($data)) {
            return '';
        }

        $min = min($data);
        $max = max($data);
        $range = $max - $min;
        if ($range === 0.0) {
            $range = 1.0;
        }

        $output = '';
        $lastBlockIndex = -1;

        foreach ($data as $i => $value) {
            // Normalize value to 0-7 range
            $normalized = (($value - $min) / $range) * 7;
            $blockIndex = (int) round(max(0, min(7, $normalized)));

            $blockChar = self::BLOCK_CHARS[$blockIndex];

            // Determine color based on position
            $color = $this->getColorForValue($value, $min, $max);

            if ($color !== null) {
                $output .= $color->toFg(ColorProfile::TrueColor);
            }

            // Mark data points with a dot
            if ($this->showDataPoints && ($i === 0 || $i === count($data) - 1 || $blockIndex !== $lastBlockIndex)) {
                $output .= '•';
                if ($color !== null) {
                    $output .= Ansi::reset();
                }
                $output .= $blockChar;
            } else {
                $output .= $blockChar;
            }

            if ($color !== null) {
                $output .= Ansi::reset();
            }

            $lastBlockIndex = $blockIndex;
        }

        return $output;
    }

    /**
     * Render a multi-line sparkline (height > 1).
     *
     * @param list<float> $data Normalized data
     */
    private function renderMultiLine(array $data): string
    {
        if (empty($data)) {
            return '';
        }

        $min = min($data);
        $max = max($data);
        $range = $max - $min;
        if ($range === 0.0) {
            $range = 1.0;
        }

        // Build output line by line (top to bottom)
        $lines = [];
        for ($h = $this->height - 1; $h >= 0; $h--) {
            $line = '';
            $threshold = ($h + 1) / $this->height;

            foreach ($data as $value) {
                $normalized = ($value - $min) / $range;
                $color = $this->getColorForValue($value, $min, $max);

                if ($normalized >= $threshold) {
                    // Filled at this height
                    if ($color !== null) {
                        $line .= $color->toFg(ColorProfile::TrueColor);
                    }
                    $line .= '█';
                    if ($color !== null) {
                        $line .= Ansi::reset();
                    }
                } elseif ($this->fill && $normalized >= ($threshold - (1 / $this->height))) {
                    // Partial fill (for area fill effect)
                    if ($color !== null && $this->fillColor !== null) {
                        $line .= $this->fillColor->toFg(ColorProfile::TrueColor);
                    }
                    $line .= '░';
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
            showDataPoints: $this->showDataPoints,
            fill: $this->fill,
            color: $this->color,
            maxColor: $this->maxColor,
            minColor: $this->minColor,
            fillColor: $this->fillColor,
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
            showDataPoints: $this->showDataPoints,
            fill: $this->fill,
            color: $this->color,
            maxColor: $this->maxColor,
            minColor: $this->minColor,
            fillColor: $this->fillColor,
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
            showDataPoints: $this->showDataPoints,
            fill: $this->fill,
            color: $this->color,
            maxColor: $this->maxColor,
            minColor: $this->minColor,
            fillColor: $this->fillColor,
        );
    }

    /**
     * Show or hide data point markers.
     */
    public function withDataPoints(bool $show): self
    {
        return new self(
            data: $this->data,
            widthConstraint: $this->widthConstraint,
            height: $this->height,
            showDataPoints: $show,
            fill: $this->fill,
            color: $this->color,
            maxColor: $this->maxColor,
            minColor: $this->minColor,
            fillColor: $this->fillColor,
        );
    }

    /**
     * Enable or disable area fill.
     */
    public function withFill(bool $fill): self
    {
        return new self(
            data: $this->data,
            widthConstraint: $this->widthConstraint,
            height: $this->height,
            showDataPoints: $this->showDataPoints,
            fill: $fill,
            color: $this->color,
            maxColor: $this->maxColor,
            minColor: $this->minColor,
            fillColor: $this->fillColor,
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
            showDataPoints: $this->showDataPoints,
            fill: $this->fill,
            color: $color,
            maxColor: $this->maxColor,
            minColor: $this->minColor,
            fillColor: $this->fillColor,
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
            showDataPoints: $this->showDataPoints,
            fill: $this->fill,
            color: $this->color,
            maxColor: $color,
            minColor: $this->minColor,
            fillColor: $this->fillColor,
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
            showDataPoints: $this->showDataPoints,
            fill: $this->fill,
            color: $this->color,
            maxColor: $this->maxColor,
            minColor: $color,
            fillColor: $this->fillColor,
        );
    }

    /**
     * Set the fill color for area fill.
     */
    public function withFillColor(?Color $color): self
    {
        return new self(
            data: $this->data,
            widthConstraint: $this->widthConstraint,
            height: $this->height,
            showDataPoints: $this->showDataPoints,
            fill: $this->fill,
            color: $this->color,
            maxColor: $this->maxColor,
            minColor: $this->minColor,
            fillColor: $color,
        );
    }
}
