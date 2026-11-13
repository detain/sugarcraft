<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer;

use SugarCraft\CellBuffer\Support\WidePairPlacement;

/**
 * Mutable scratch pad for BULK cell painting (CB-34).
 *
 * Buffer's fluent API is O(grid) per write — building a populated frame
 * through 5k chained withCellAt() calls is the O(n²) churn the finding
 * measured. The builder writes into one flat grid in place and freezes it
 * into the immutable VO with a single fromGrid() wrap. Every placement
 * rides the SAME WidePairPlacement law as Buffer's mutation paths —
 * degrade, partner sweep, stranded-lead clear — so builder output is
 * byte-identical to the withCellAt chain, not a second opinion on the
 * pair rules.
 *
 * Not a value object: setCellAt()/fillRect() mutate $this and return it
 * for chaining; the immutable snapshot arrives only at toBuffer(), which
 * can be called repeatedly (each call wraps the CURRENT grid — the
 * builder stays live for further painting).
 *
 * Get one via {@see Buffer::builder()}.
 */
final class BufferBuilder
{
    use WidePairPlacement;

    /** @var non-empty-array<int, Cell> */
    private array $grid;

    /**
     * @throws \InvalidArgumentException on non-positive dimensions (the
     *         same floor fromGrid() enforces at the wrap — failing here
     *         means a giant build never discovers its canvas was void).
     */
    public function __construct(
        private readonly int $width,
        private readonly int $height,
    ) {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException(
                "BufferBuilder dimensions must be positive (got {$width}x{$height})"
            );
        }

        $this->grid = array_fill(0, $width * $height, Cell::blank());
    }

    /**
     * Paint one cell with the full pair law applied in place. Bounds are
     * fatal (Out Of Range) exactly as withCellAt(), not silently clipped —
     * a bulk loop hitting the edge is a caller bug the builder refuses to
     * hide.
     *
     * @throws \OutOfRangeException when coordinates are outside the grid
     */
    public function setCellAt(int $col, int $row, Cell $cell): self
    {
        $this->assertInBounds($col, $row);
        $this->placeWithPair($this->grid, $col, $row, $cell);

        return $this;
    }

    /**
     * Sweep a region with one cell, clipped to the builder extent (the
     * same intersect-then-sweep shape as Buffer::fill(), which likewise
     * never throws for out-of-frame regions).
     */
    public function fillRect(Region $region, Cell $cell): self
    {
        $clip = $region->intersect($this->region());

        if ($clip !== null) {
            $colEnd = $clip->right();
            $rowEnd = $clip->bottom();
            for ($row = $clip->origin()->row(); $row <= $rowEnd; $row++) {
                for ($col = $clip->origin()->col(); $col <= $colEnd; $col++) {
                    $this->placeWithPair($this->grid, $col, $row, $cell);
                }
            }
        }

        return $this;
    }

    /** Bounding region of the entire canvas. */
    public function region(): Region
    {
        return new Region(Position::new(0, 0), $this->width, $this->height);
    }

    /**
     * Freeze the CURRENT grid into an immutable Buffer via the validated
     * fromGrid wrap. Repeat calls after further painting each snapshot the
     * grid as it stands.
     */
    public function toBuffer(): Buffer
    {
        return Buffer::fromGrid($this->width, $this->height, $this->grid);
    }
}
