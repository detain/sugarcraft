<?php

declare(strict_types=1);

namespace SugarCraft\Top\View;

/**
 * An immutable 0-based screen rectangle (column x, row y, width, height).
 *
 * btop positions boxes with 1-based `Mv::to(row, col)`; the {@see FrameBuilder}
 * port subtracts one from both on the way in so every rectangle here indexes
 * the {@see Surface} grid directly.
 */
final class Rect
{
    private function __construct(
        public readonly int $x,
        public readonly int $y,
        public readonly int $width,
        public readonly int $height,
    ) {
    }

    public static function new(int $x, int $y, int $width, int $height): self
    {
        return new self($x, $y, max(0, $width), max(0, $height));
    }

    /** First column past the right edge. */
    public function right(): int
    {
        return $this->x + $this->width;
    }

    /** First row past the bottom edge. */
    public function bottom(): int
    {
        return $this->y + $this->height;
    }

    public function isEmpty(): bool
    {
        return $this->width === 0 || $this->height === 0;
    }

    public function contains(int $x, int $y): bool
    {
        return $x >= $this->x && $x < $this->right() && $y >= $this->y && $y < $this->bottom();
    }

    /** The overlap of the two rectangles (empty when they do not meet). */
    public function intersect(self $other): self
    {
        $x = max($this->x, $other->x);
        $y = max($this->y, $other->y);

        return self::new($x, $y, min($this->right(), $other->right()) - $x, min($this->bottom(), $other->bottom()) - $y);
    }

    /** The same rectangle moved by ($dx, $dy). */
    public function translate(int $dx, int $dy): self
    {
        return new self($this->x + $dx, $this->y + $dy, $this->width, $this->height);
    }

    public function equals(self $other): bool
    {
        return $this->x === $other->x && $this->y === $other->y
            && $this->width === $other->width && $this->height === $other->height;
    }
}
