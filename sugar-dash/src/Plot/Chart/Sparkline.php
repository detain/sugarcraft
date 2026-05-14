<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Chart;

use SugarCraft\Dash\Plot\RingBuffer;

/**
 * An inline sparkline chart component using 8-block Unicode scaling.
 *
 * O(1) push via RingBuffer - appends value and overwrites oldest when full.
 * Supports dim-edge padding to keep right edge anchored on most recent data.
 *
 * Mirrors Homedash internal_ui_components_sparkline.go (8-block scaling).
 * Mirrors Homedash ring buffer but with O(1) push instead of O(n) slice-shift.
 */
final class Sparkline implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $sizerHeight = null;

    /**
     * Unicode block characters for 8-step vertical scaling.
     * ▁▂▃▄▅▆▇█ → indices 0..7 (lowest to highest)
     */
    private const BLOCK_CHARS = '▁▂▃▄▅▆▇█';

    /**
     * Dim block character for left padding when buffer has fewer samples than width.
     */
    private const DIM_CHAR = '░';

    private RingBuffer $buffer;

    public function __construct(
        private readonly int $widthConstraint = 40,
        private readonly int $height = 1,
        private readonly bool $showDataPoints = false,
        private readonly bool $fill = false,
        private readonly bool $dimEdge = false,
    ) {
        $this->buffer = new RingBuffer($this->widthConstraint ?? 40);
    }

    /**
     * Create a new sparkline with default styling.
     */
    public static function new(int $width = 40): self
    {
        return new self(
            widthConstraint: $width,
            height: 1,
            showDataPoints: false,
            fill: false,
            dimEdge: false,
        );
    }

    /**
     * Push a value onto the sparkline buffer.
     *
     * O(1) operation - uses RingBuffer internally.
     */
    public function push(float $value): self
    {
        $clone = $this->mutate();
        $clone->buffer->push($value);
        return $clone;
    }

    /**
     * Push multiple values onto the sparkline buffer.
     *
     * O(n) where n is the number of values.
     *
     * @param list<float> $values
     */
    public function pushAll(array $values): self
    {
        $clone = $this->mutate();
        foreach ($values as $value) {
            $clone->buffer->push($value);
        }
        return $clone;
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
     * Render the sparkline as a string.
     */
    public function render(): string
    {
        $displayWidth = $this->getWidth();

        if ($displayWidth <= 0 || $this->buffer->isEmpty()) {
            return '';
        }

        $values = $this->buffer->toArray();

        if ($this->dimEdge) {
            return $this->renderWithDimEdge($values, $displayWidth);
        }

        return $this->renderToBlocks($values, $displayWidth);
    }

    /**
     * Render with dim-edge padding.
     *
     * Left-pads with dim characters to keep right edge anchored on most recent data.
     * Example:░░░▁▂▃▄▅▆▇█ (right edge shows latest data)
     *
     * @param list<float|null> $values
     */
    private function renderWithDimEdge(array $values, int $width): string
    {
        $result = '';
        $count = count($values);

        // Count non-null values for right-alignment
        $nonNullCount = 0;
        foreach ($values as $v) {
            if ($v !== null) {
                $nonNullCount++;
            }
        }

        // Calculate padding needed
        $padding = max(0, $width - $nonNullCount);

        // Render dim padding
        for ($i = 0; $i < $padding; $i++) {
            $result .= self::DIM_CHAR;
        }

        // Render actual values
        for ($i = 0; $i < $nonNullCount && strlen($result) < $width; $i++) {
            $value = $values[$i] ?? 0;
            $idx = $this->valueToIndex($value);
            $result .= self::BLOCK_CHARS[$idx];
        }

        return $result;
    }

    /**
     * Render values to 8-block character string.
     *
     * Scales 0-100 to index 0-7 using the formula:
     *   idx = int(v / 100 * 7)  // ▁▂▃▄▅▆▇█ → 0..7
     *
     * @param list<float|null> $values
     */
    private function renderToBlocks(array $values, int $width): string
    {
        $result = '';
        $count = count($values);

        for ($i = 0; $i < $width; $i++) {
            if ($i < $count) {
                $value = $values[$i] ?? 0;
                $idx = $this->valueToIndex($value);
                $result .= self::BLOCK_CHARS[$idx];
            } else {
                $result .= ' ';
            }
        }

        return $result;
    }

    /**
     * Convert a 0-100 value to block index 0-7.
     */
    private function valueToIndex(float $value): int
    {
        $idx = intval(($value / 100) * 7);
        return max(0, min(7, $idx));
    }

    /**
     * Get the width to use for the sparkline.
     */
    private function getWidth(): int
    {
        if ($this->width !== null && $this->width > 0) {
            return $this->width;
        }
        return $this->widthConstraint ?? 40;
    }

    /**
     * Calculate the natural dimensions of this sparkline.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->getWidth();

        if ($width <= 0 || $this->buffer->isEmpty()) {
            return [0, $this->height];
        }

        return [$width, $this->height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the width constraint.
     */
    public function withWidth(int $width): self
    {
        return new self(
            widthConstraint: $width,
            height: $this->height,
            showDataPoints: $this->showDataPoints,
            fill: $this->fill,
            dimEdge: $this->dimEdge,
        );
    }

    /**
     * Set the height.
     */
    public function withHeight(int $height): self
    {
        return new self(
            widthConstraint: $this->widthConstraint,
            height: max(1, $height),
            showDataPoints: $this->showDataPoints,
            fill: $this->fill,
            dimEdge: $this->dimEdge,
        );
    }

    /**
     * Show or hide data point markers.
     */
    public function withDataPoints(bool $show): self
    {
        return new self(
            widthConstraint: $this->widthConstraint,
            height: $this->height,
            showDataPoints: $show,
            fill: $this->fill,
            dimEdge: $this->dimEdge,
        );
    }

    /**
     * Enable or disable area fill.
     */
    public function withFill(bool $fill): self
    {
        return new self(
            widthConstraint: $this->widthConstraint,
            height: $this->height,
            showDataPoints: $this->showDataPoints,
            fill: $fill,
            dimEdge: $this->dimEdge,
        );
    }

    /**
     * Enable or disable dim-edge padding.
     *
     * When enabled, pads left with dim blocks to keep right edge
     * anchored on most recent data.
     */
    public function withDimEdge(bool $dim): self
    {
        return new self(
            widthConstraint: $this->widthConstraint,
            height: $this->height,
            showDataPoints: $this->showDataPoints,
            fill: $this->fill,
            dimEdge: $dim,
        );
    }

    // ─── Internal Helpers ─────────────────────────────────────────

    /**
     * Create a mutable clone for internal modifications.
     */
    private function mutate(): self
    {
        $clone = clone $this;
        $clone->buffer = new RingBuffer($this->widthConstraint ?? 40);
        foreach ($this->buffer->toArray() as $value) {
            $clone->buffer->push($value);
        }
        return $clone;
    }
}
