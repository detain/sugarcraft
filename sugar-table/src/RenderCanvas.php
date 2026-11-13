<?php

declare(strict_types=1);

namespace SugarCraft\Table;

use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;

/**
 * Mutable render canvas mirroring Buffer's cell-pairing semantics.
 *
 * The table used to build each frame by chaining {@see Buffer::withCellAt()},
 * which copies the whole grid on every single write: a 40-row page of an
 * 80-column table pushed ~10k full-grid copies through the render pass, and
 * render cost grew quadratically with rows (audit M1). This canvas keeps ONE
 * flat grid, mutated in place, and freezes it into an immutable Buffer
 * exactly once via {@see toBuffer()}.
 *
 * {@see put()} is a faithful mutable twin of Buffer's private
 * placeWithPair(): width-2 leads get their width-0 continuation on the right,
 * a real cell replacing a stranded pair blanks the leftover half. Byte
 * output is therefore identical to the per-write chain — the goldens are the
 * harness that proves it.
 *
 * @internal Layout plumbing for {@see Table::view()}; not public API.
 */
final class RenderCanvas
{
    /** @var list<Cell> Flat row-major grid, index = row * width + col. */
    private array $grid;

    public function __construct(
        public readonly int $width,
        public readonly int $height,
    ) {
        $blank = Cell::new();
        $this->grid = \array_fill(0, $width * $height, $blank);
    }

    /** Read a cell for in-place reconstruction (null when out of bounds). */
    public function at(int $col, int $row): ?Cell
    {
        if ($col < 0 || $row < 0 || $col >= $this->width || $row >= $this->height) {
            return null;
        }

        return $this->grid[$row * $this->width + $col];
    }

    /**
     * Write one cell, keeping the wide-cell pair invariant exactly as
     * {@see Buffer} does per write.
     *
     * @throws \OutOfBoundsException when the position is outside the frame
     *                               (fail loud: a write beyond the budgeted
     *                               buffer is a layout bug, never a clamp).
     */
    public function put(int $col, int $row, Cell $cell): void
    {
        if ($col < 0 || $row < 0 || $col >= $this->width || $row >= $this->height) {
            throw new \OutOfBoundsException(
                "Cell($col,$row) is outside the {$this->width}x{$this->height} canvas"
            );
        }

        $idx = $row * $this->width + $col;
        $previous = $this->grid[$idx];
        $this->grid[$idx] = $cell;

        if ($cell->width() === 2) {
            if ($col + 1 < $this->width) {
                $this->grid[$idx + 1] = Cell::continuation();
            }
        } elseif ($cell->width() !== 0
            && $previous->width() === 2
            && $col + 1 < $this->width
            && $this->grid[$idx + 1]->width() === 0
        ) {
            $this->grid[$idx + 1] = Cell::new();
        }

        if ($cell->width() !== 0 && $previous->width() === 0 && $col > 0) {
            $left = $this->grid[$idx - 1];
            if ($left->width() === 2) {
                $this->grid[$idx - 1] = Cell::new();
            }
        }
    }

    /** Freeze the finished grid into the immutable render buffer. */
    public function toBuffer(): Buffer
    {
        return Buffer::fromGrid($this->width, $this->height, $this->grid);
    }
}
