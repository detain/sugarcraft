<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Foundation;

/**
 * Sugar-dash Rect uses the charmbracelet/rectmath bounds model
 * (minX, minY, maxX, maxY). Intentionally distinct from
 * \SugarCraft\Core\Rect, which uses the ratatui offset+size model
 * (x, y, width, height). Both are canonical for their lib — choose
 * by which upstream semantics the consumer needs.
 *
 * See sugar-dash/CALIBER_LEARNINGS.md entry [pattern:dual-rect-models].
 */
readonly class Rect
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
