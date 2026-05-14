<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout;

use SugarCraft\Core\Util\Width;

/**
 * A spacer component that renders empty space.
 *
 * Used to add visible breathing room between components in a layout.
 * Unlike Margin which is a style, Spacer is a visible element that
 * renders configurable fill characters (often dots or lines) to
 * make the space visible during development/debugging.
 *
 * Mirrors spacer concepts adapted to PHP with wither-style immutable setters.
 */
final class Spacer implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $allocatedWidth = null;
    private ?int $allocatedHeight = null;

    public function __construct(
        private readonly int $width = 1,
        private readonly int $height = 1,
        private readonly string $fillChar = ' ',
    ) {}

    /**
     * Create a new spacer with default styling.
     */
    public static function new(int $width = 1, int $height = 1): self
    {
        return new self(
            width: max(0, $width),
            height: max(0, $height),
            fillChar: ' ',
        );
    }

    /**
     * Create a dotted spacer line (useful for visual separation).
     */
    public static function dotted(int $width = 40): self
    {
        return new self(
            width: $width,
            height: 1,
            fillChar: '·',
        );
    }

    /**
     * Create a dashed spacer line (useful for visual separation).
     */
    public static function dashed(int $width = 40): self
    {
        return new self(
            width: $width,
            height: 1,
            fillChar: '─',
        );
    }

    /**
     * Create a vertical spacer (column of fill characters).
     */
    public static function vertical(int $width = 1, int $height = 10): self
    {
        return new self(
            width: $width,
            height: $height,
            fillChar: ' ',
        );
    }

    /**
     * Set the allocated dimensions for this spacer.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->allocatedWidth = $width;
        $clone->allocatedHeight = $height;
        return $clone;
    }

    /**
     * Render the spacer as a string.
     */
    public function render(): string
    {
        $w = $this->getEffectiveWidth();
        $h = $this->getEffectiveHeight();

        if ($w <= 0 || $h <= 0) {
            return '';
        }

        $lines = [];
        for ($y = 0; $y < $h; $y++) {
            $lines[] = str_repeat($this->fillChar, $w);
        }

        return implode("\n", $lines);
    }

    /**
     * Get the effective width (allocated or default).
     */
    private function getEffectiveWidth(): int
    {
        return $this->allocatedWidth ?? $this->width;
    }

    /**
     * Get the effective height (allocated or default).
     */
    private function getEffectiveHeight(): int
    {
        return $this->allocatedHeight ?? $this->height;
    }

    /**
     * Calculate the natural dimensions of this spacer.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        return [$this->getEffectiveWidth(), $this->getEffectiveHeight()];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the width of this spacer.
     */
    public function withWidth(int $width): self
    {
        return new self(
            width: max(0, $width),
            height: $this->height,
            fillChar: $this->fillChar,
        );
    }

    /**
     * Set the height of this spacer.
     */
    public function withHeight(int $height): self
    {
        return new self(
            width: $this->width,
            height: max(0, $height),
            fillChar: $this->fillChar,
        );
    }

    /**
     * Set both width and height.
     */
    public function withSize(int $width, int $height): self
    {
        return new self(
            width: max(0, $width),
            height: max(0, $height),
            fillChar: $this->fillChar,
        );
    }

    /**
     * Set the fill character.
     */
    public function withFillChar(string $char): self
    {
        return new self(
            width: $this->width,
            height: $this->height,
            fillChar: mb_substr($char, 0, 1, 'UTF-8'),
        );
    }
}
