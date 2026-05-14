<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Foundation;

/**
 * A 2D axis-aligned rectangle.
 *
 * Mirrors github.com/charmbracelet/rectmath rect.Rect.
 */
final readonly class Rect
{
    public function __construct(
        public int $minX,
        public int $minY,
        public int $maxX,
        public int $maxY,
    ) {}

    /**
     * Check if a point is inside the rectangle (inclusive).
     */
    public function contains(int $x, int $y): bool
    {
        return $x >= $this->minX && $x <= $this->maxX
            && $y >= $this->minY && $y <= $this->maxY;
    }

    /**
     * Compute the intersection of two rectangles, or null if no overlap.
     */
    public function intersect(self $other): ?self
    {
        $minX = max($this->minX, $other->minX);
        $minY = max($this->minY, $other->minY);
        $maxX = min($this->maxX, $other->maxX);
        $maxY = min($this->maxY, $other->maxY);

        if ($minX > $maxX || $minY > $maxY) {
            return null;
        }

        return new self($minX, $minY, $maxX, $maxY);
    }

    /**
     * Width of the rectangle (maxX - minX + 1).
     */
    public function dx(): int
    {
        return $this->maxX - $this->minX + 1;
    }

    /**
     * Height of the rectangle (maxY - minY + 1).
     */
    public function dy(): int
    {
        return $this->maxY - $this->minY + 1;
    }
}
