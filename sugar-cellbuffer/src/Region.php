<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer;

/**
 * A rectangular sub-region of a Buffer, identified by its top-left
 * {@see Position} and dimensions.
 *
 * Dimensions must be non-negative. A zero-area region (0×N or N×0) is
 * explicitly allowed and behaves as a no-op in {@see Buffer::fill()} /
 * {@see Buffer::withRegion()} — the loops simply run zero iterations.
 * {@see Buffer::copy()} is the exception: its result is region-sized by
 * contract, so a zero-area region cannot be represented and copy()
 * rejects it with a named error.
 * A NEGATIVE width or height is rejected at construction: previously such
 * a region was silently accepted and then quietly produced no output
 * (the `$dx < $width` loop guard was false immediately), masking the
 * caller's arithmetic bug instead of surfacing it.
 *
 * Note: zero-width or zero-height regions are edge cases — a cell at
 * the origin is contained only if the region has positive dimensions.
 * See {@see self::contains()} for the zero-size boundary behaviour.
 *
 * @readonly
 */
final class Region implements \JsonSerializable
{
    /**
     * @throws \InvalidArgumentException when $width or $height is negative
     */
    public function __construct(
        public readonly Position $origin,
        public readonly int $width,
        public readonly int $height,
    ) {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException(
                "Region dimensions must not be negative: {$width}x{$height}"
            );
        }
    }

    public static function new(Position $origin, int $width, int $height): self
    {
        return new self($origin, $width, $height);
    }

    /** Top-left corner. */
    public function origin(): Position { return $this->origin; }

    /** Width in cells. */
    public function width(): int { return $this->width; }

    /** Height in cells. */
    public function height(): int { return $this->height; }

    /** Rightmost column (inclusive). */
    public function right(): int
    {
        return $this->origin->col + $this->width - 1;
    }

    /** Bottommost row (inclusive). */
    public function bottom(): int
    {
        return $this->origin->row + $this->height - 1;
    }

    /**
     * Whether a cell at ($col, $row) falls within this region. A zero-area
     * region contains nothing (see class docblock).
     */
    public function contains(int $col, int $row): bool
    {
        return $col >= $this->origin->col
            && $col <= $this->right()
            && $row >= $this->origin->row
            && $row <= $this->bottom();
    }

    /**
     * The rectangle common to this region and $other — the clip math
     * {@see Buffer::withRegion()}/{@see Buffer::fill()} use against the
     * grid, exposed for overlay compositors.
     *
     * Returns null when the intersection is EMPTY (disjoint rectangles, or
     * either side zero-area — a zero-area region contains nothing to
     * intersect): "clip yields nothing" is the caller's branch, not a
     * degenerate region it must re-test.
     */
    public function intersect(self $other): ?self
    {
        $col = max($this->origin->col, $other->origin->col);
        $row = max($this->origin->row, $other->origin->row);
        $right = min($this->right(), $other->right());
        $bottom = min($this->bottom(), $other->bottom());

        if ($right < $col || $bottom < $row) {
            return null;
        }

        return new self(Position::new($col, $row), $right - $col + 1, $bottom - $row + 1);
    }

    /**
     * Value equality of the rectangle: origin corner plus extents (the
     * inclusive right/bottom edges follow from those, so equal here means
     * equal corners and edges). Consumer idiom: "did the selection move?"
     */
    public function equals(self $other): bool
    {
        return $this->origin()->equals($other->origin())
            && $this->width() === $other->width()
            && $this->height() === $other->height();
    }

    /**
     * Serialization hook for caching/IPC use cases.
     *
     * @return array{origin: array{col: int, row: int}, width: int, height: int}
     */
    public function __serialize(): array
    {
        return [
            'origin' => $this->origin->__serialize(),
            'width' => $this->width,
            'height' => $this->height,
        ];
    }

    /**
     * Unserialization hook for caching/IPC use cases.
     *
     * Fail-closed: the constructor's non-negative dimension check is a
     * safety control, so a forged payload cannot mint an invalid region.
     *
     * @param array{origin: array{col: int, row: int}, width: int, height: int} $data
     */
    public function __unserialize(array $data): void
    {
        $width = (int) ($data['width'] ?? 0);
        $height = (int) ($data['height'] ?? 0);
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException("Region dimensions must not be negative: {$width}x{$height}");
        }

        $this->origin = new Position((int) $data['origin']['col'], (int) $data['origin']['row']);
        $this->width = $width;
        $this->height = $height;
    }

    /**
     * JSON serialization support.
     *
     * @return array{origin: array{col: int, row: int}, width: int, height: int}
     */
    public function jsonSerialize(): array
    {
        return $this->__serialize();
    }
}
