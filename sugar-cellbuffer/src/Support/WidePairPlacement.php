<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer\Support;

use SugarCraft\CellBuffer\Cell;

/**
 * The wide-pair placement law, shared verbatim by Buffer's copy-on-write
 * paths and the mutable BufferBuilder scratch (CB-34) so the degradation
 * rules exist in exactly one place — a builder that diverged from
 * withCellAt() would mint grids the immutable API can never produce.
 *
 * Expects the composing class to carry `int $width` / `int $height` grid
 * dimensions and a row-major flat `array $grid` keyed $row * $width + $col.
 */
trait WidePairPlacement
{
    /**
     * @throws \OutOfRangeException
     */
    private function assertInBounds(int $col, int $row): void
    {
        if ($col < 0 || $col >= $this->width || $row < 0 || $row >= $this->height) {
            throw new \OutOfRangeException(
                "Cell ({$col}, {$row}) is out of buffer bounds ({$this->width}x{$this->height})"
            );
        }
    }

    /**
     * Write one cell into a working grid, keeping the wide-cell pair
     * invariant of the Cell docblock structurally true afterwards:
     *
     *  - a width-2 lead gets its width-0 continuation in the right
     *    neighbour; a straddle at the last column DEGRADES to a blank —
     *    the lead would otherwise paint two columns of rune into one
     *    slot and overrun the row past the frame (auto-wrap then shifts
     *    every following row in raw mode); applyDiff() mirrors the
     *    degrade so grid, diff, encoder and text() stay within the width
     *    by construction;
     *  - replacing a lead with a real cell blanks a left-behind continuation
     *    so the cleared column repaints instead of ghosting;
     *  - writing a real cell onto a continuation blanks the stranded lead
     *    to its left instead of leaving a half-open pair;
     *  - a width-2 lead landing on a partner slot that is itself the lead of
     *    another pair blanks that pair's continuation one column further
     *    right, so overwriting a wide cell never orphans half a pair.
     *
     * Continuation-to-continuation writes pass through untouched, so a
     * well-formed source pair blits byte-identically.
     *
     * @param non-empty-array<int, Cell> $grid working grid, mutated in place
     */
    private function placeWithPair(array &$grid, int $col, int $row, Cell $cell): void
    {
        $idx = $row * $this->width + $col;
        $previous = $grid[$idx];

        if ($cell->width() === 2 && $col + 1 >= $this->width) {
            $cell = Cell::new(); // straddle degrade — see docblock
        }

        $grid[$idx] = $cell;

        if ($cell->width() === 2) {
            $displaced = $grid[$idx + 1];
            $grid[$idx + 1] = Cell::continuation();
            if ($displaced->width() === 2 && $col + 2 < $this->width) {
                $grid[$idx + 2] = Cell::new();
            }
        } elseif ($cell->width() !== 0
            && $previous->width() === 2
            && $col + 1 < $this->width
            && $grid[$idx + 1]->width() === 0
        ) {
            $grid[$idx + 1] = Cell::new();
        }

        if ($cell->width() !== 0 && $previous->width() === 0 && $col > 0) {
            $left = $grid[$idx - 1];
            if ($left->width() === 2) {
                $grid[$idx - 1] = Cell::new();
            }
        }
    }
}
